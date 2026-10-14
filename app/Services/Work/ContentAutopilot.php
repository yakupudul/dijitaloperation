<?php

namespace App\Services\Work;

use App\Ai\Agents\Site\ContentReviewAgent;
use App\Jobs\Site\TopUpContentPoolJob;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Compliance\ForbiddenTerms;
use App\Services\Site\ContentPlanner;
use App\Services\Site\SiteAi;
use App\Services\Site\SiteOperations;
use App\Services\Site\SiteScope;
use App\Services\Site\SiteSuggestions;
use App\Services\Site\SiteSuggestionTypes;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * İçerik otopilotu (yakup, 2026-10-14): every site gets one article every two days, dated from its last published post.
 *
 *  - Geçmiş: the days since the site's last post (at most the last 60 days) get one date every two days; ongoing, the
 *    next date comes two days after the last planned one. Each date takes a "new" idea of the pool (an already written
 *    one first, then the best scored); the title is approved by the system and the article is written with its
 *    translations. The pool tops itself up after each approval.
 *  - Kontrol: once the article and its translations are written, `content.review` reads it once more; only "ok" waits
 *    for the send. Anything else stays on the content board with the reason (the operator can still send it by hand).
 *  - Gönderim: the operator's one daily click ("Günde 1 tık", 2026-10-14) sends every checked article whose date has
 *    come to WordPress as a draft dated to its plan date, with its translations. Nothing goes out by itself.
 */
final class ContentAutopilot
{
    public const int BACKFILL_DAYS = 60;

    public const int EVERY_DAYS = 2;

    /** Local hour of a planned post date. */
    public const int POST_HOUR = 10;

    /** A translation that has not come back after this long does not hold the article back. */
    public const int TRANSLATION_WAIT_HOURS = 6;

    /** Articles one review job reads at most (the scheduler comes back for the rest). */
    public const int REVIEWS_PER_RUN = 4;

    public function __construct(private readonly SiteSuggestions $suggestions, private readonly ContentScore $scorer) {}

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now('Europe/Istanbul')->startOfDay();
    }

    /** @return Collection<int, DigitalAsset> websites of operational brands */
    public static function sites(): Collection
    {
        return DigitalAsset::query()->operational()->where('digital_assets.type', 'website')->whereNotNull('digital_assets.brand_id')->get();
    }

    /** The day of the site's newest published post (WordPress inventory), null when none is known. */
    public static function lastPublished(DigitalAsset $site): ?CarbonImmutable
    {
        if (! Schema::hasTable('website_cms_object_snapshot')) {
            return null;
        }
        $date = DB::table('website_cms_object_snapshot')->where('digital_asset_id', $site->id)
            ->where('status', 'publish')->where('object_type', 'post')->max('published_at');

        return $date !== null ? CarbonImmutable::parse((string) $date)->setTimezone('Europe/Istanbul')->startOfDay() : null;
    }

    /** @return Builder<Suggestion> content ideas of the site */
    private static function ideas(DigitalAsset $site): Builder
    {
        return Suggestion::query()->where('channel', 'search')->where('action_type', SiteSuggestionTypes::CONTENT)
            ->where('brand_id', $site->brand_id)->where('action->site_id', (int) $site->id);
    }

    /** @return Collection<int, Suggestion> the site's ideas the otopilot dated */
    public static function planned(DigitalAsset $site): Collection
    {
        return self::ideas($site)->whereNotNull('action->autopilot')->get()
            ->filter(fn (Suggestion $s): bool => is_string(data_get($s->action, 'autopilot.date')))->values();
    }

    /**
     * The dates still without an article: from two days after the last post or plan (never before the last 60 days)
     * up to tomorrow (written a day ahead, sent on its day).
     *
     * @return list<string>
     */
    public function openDates(DigitalAsset $site, ?CarbonImmutable $today = null): array
    {
        $today ??= self::today();
        $lastPlanned = self::planned($site)->map(fn (Suggestion $s): string => (string) data_get($s->action, 'autopilot.date'))->max();
        $anchor = collect([self::lastPublished($site), $lastPlanned !== null ? CarbonImmutable::parse($lastPlanned, 'Europe/Istanbul') : null])->filter()->max();
        $first = $today->subDays(self::BACKFILL_DAYS);
        $next = $anchor !== null ? $anchor->addDays(self::EVERY_DAYS) : $first;
        $next = $next->lt($first) ? $first : $next;
        $dates = [];
        for ($day = $next; $day->lte($today->addDay()); $day = $day->addDays(self::EVERY_DAYS)) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    /**
     * Gives the open dates of the site an idea each and starts the writing. Ideas: a written, unsent new article first,
     * then the best scored open new title; titles a sector rule blocked and "update" ideas never take a date.
     *
     * @return array{planned: int, open_dates: int}
     */
    public function plan(DigitalAsset $site, User $by): array
    {
        $dates = $this->openDates($site);
        if ($dates === [] || ! SiteScope::aiAllowed(Brand::query()->find($site->brand_id))) {
            return ['planned' => 0, 'open_dates' => count($dates)];
        }
        $candidates = self::ideas($site)->where(fn (Builder $q): Builder => $q->where('status', Suggestion::APPROVED)->orWhere(fn (Builder $open): Builder => $open->actionable()))->get()
            ->filter(fn (Suggestion $s): bool => ($s->action['kind'] ?? 'new') !== 'update' && ! isset($s->action['article_blocked']) && ! isset($s->action['article_write_id'])
                && data_get($s->action, 'autopilot') === null && ($s->status !== Suggestion::APPROVED || is_array($s->action['article'] ?? null)));
        $scores = $this->scorer->forSuggestions($candidates);
        $ordered = $candidates->sortByDesc(fn (Suggestion $s): int => (is_array($s->action['article'] ?? null) ? 1000 : 0) + (int) ($scores[(int) $s->id]['score'] ?? 0))->values();
        $planned = 0;
        foreach ($dates as $i => $date) {
            $suggestion = $ordered->get($i);
            if ($suggestion === null) {
                break; // the pool tops itself up; the next run continues from this date
            }
            if ($suggestion->status !== Suggestion::APPROVED) {
                $this->suggestions->approve($suggestion, $by);
            }
            $suggestion->forceFill(['action' => array_merge((array) $suggestion->action, ['autopilot' => ['date' => $date, 'planned_at' => now()->toIso8601String()]])])->save();
            if (! is_array($suggestion->action['article'] ?? null)) {
                SiteOperations::dispatch((int) $site->id, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => (int) $suggestion->id]);
            }
            $planned++;
        }
        if ($planned > 0) {
            TopUpContentPoolJob::dispatch((int) $site->id)->delay(now()->addMinute());
        }

        return ['planned' => $planned, 'open_dates' => count($dates)];
    }

    /**
     * Reads the site's written, dated articles once more (`content.review`), one call per article. Runs in the site's
     * content job, so Claude (MCP) can answer when the credit is used up.
     *
     * @return array{status: string, reviewed: int, waiting: int}
     */
    public function review(DigitalAsset $site): array
    {
        $brand = Brand::query()->with('sectorCategory')->find($site->brand_id);
        $reviewed = 0;
        $waiting = 0;
        foreach (self::planned($site) as $suggestion) {
            $action = (array) $suggestion->action;
            if (! is_array($action['article'] ?? null) || isset($action['article_write_id']) || data_get($action, 'autopilot.review') !== null) {
                continue;
            }
            if ($reviewed >= self::REVIEWS_PER_RUN) {
                $waiting++;

                continue;
            }
            if (! $this->translationsSettled($suggestion, $site)) {
                $waiting++;

                continue;
            }
            $verdict = $this->verdict($suggestion, $site, $brand);
            if ($verdict === null) {
                return ['status' => 'queued', 'reviewed' => $reviewed, 'waiting' => $waiting + 1];
            }
            $fresh = $suggestion->fresh();
            $fresh->forceFill(['action' => array_replace_recursive((array) $fresh->action, ['autopilot' => ['review' => $verdict]])])->save();
            $reviewed++;
        }

        return ['status' => 'ready', 'reviewed' => $reviewed, 'waiting' => $waiting];
    }

    /** Every other language of the site is written (or blocked), or the wait is over. */
    private function translationsSettled(Suggestion $suggestion, DigitalAsset $site): bool
    {
        $action = (array) $suggestion->action;
        $others = array_diff(ContentPlanner::siteLanguages($site), [ContentPlanner::articleLanguage($suggestion, $site)]);
        $done = array_keys((array) ($action['translations'] ?? []) + (array) ($action['translations_blocked'] ?? []));
        if (array_diff($others, $done) === []) {
            return true;
        }
        $seen = data_get($action, 'autopilot.written_seen_at');
        if ($seen === null) {
            $suggestion->forceFill(['action' => array_replace_recursive($action, ['autopilot' => ['written_seen_at' => now()->toIso8601String()]])])->save();

            return false;
        }

        return CarbonImmutable::parse((string) $seen)->lt(now()->subHours(self::TRANSLATION_WAIT_HOURS));
    }

    /** @return array{verdict: string, reason: string, at: string}|null null while Claude (MCP) has not answered */
    private function verdict(Suggestion $suggestion, DigitalAsset $site, ?Brand $brand): ?array
    {
        $action = (array) $suggestion->action;
        $at = now()->toIso8601String();
        // A sector-rule warning or a missing brand never passes on its own.
        if ($brand === null) {
            return ['verdict' => 'unsure', 'reason' => 'Marka bulunamadı.', 'at' => $at];
        }
        if (is_string($action['article_warnings'] ?? null) && $action['article_warnings'] !== '') {
            return ['verdict' => 'wrong', 'reason' => mb_substr((string) $action['article_warnings'], 0, 200), 'at' => $at];
        }
        $article = (array) $action['article'];
        $items = [['id' => 0, 'title' => (string) ($article['title'] ?? ''), 'html' => mb_substr((string) ($article['html'] ?? ''), 0, 30000),
            'language' => (string) ($article['language'] ?? ContentPlanner::articleLanguage($suggestion, $site)),
            'source' => array_filter(['title' => $suggestion->title, 'reason' => $suggestion->reason, 'outline' => $action['outline'] ?? null, 'questions' => $action['questions'] ?? null])]];
        foreach ((array) ($action['translations'] ?? []) as $language => $translation) {
            $items[] = ['id' => count($items), 'title' => (string) data_get($translation, 'title', ''), 'html' => mb_substr((string) data_get($translation, 'html', ''), 0, 30000),
                'language' => (string) $language, 'source' => ['translation_of' => (string) ($article['title'] ?? '')]];
        }
        $result = app(SiteAi::class)->run(new ContentReviewAgent, [
            'kind' => 'article',
            'brand' => ['name' => $brand->name, 'sector' => $brand->sectorCategory?->name],
            'forbidden' => ForbiddenTerms::forBrand($brand)->phrases(),
            'items' => $items,
        ], 240, 'autopilot-review-'.$suggestion->id);
        if ($result['status'] === 'queued') {
            return null;
        }
        if ($result['status'] !== 'ready') {
            return ['verdict' => 'unsure', 'reason' => mb_substr((string) ($result['message'] ?? 'AI kontrolü yapılamadı.'), 0, 200), 'at' => $at];
        }
        $answers = collect((array) ($result['data']['items'] ?? []))->filter(fn ($i): bool => is_array($i) && is_int($i['id'] ?? null))->keyBy('id');
        foreach (array_keys($items) as $id) {
            $answer = (array) $answers->get($id, []);
            if (($answer['verdict'] ?? null) !== 'ok') {
                $label = $id === 0 ? 'Yazı' : 'Çeviri ('.strtoupper((string) $items[$id]['language']).')';

                return ['verdict' => in_array($answer['verdict'] ?? null, ['wrong', 'unsure'], true) ? (string) $answer['verdict'] : 'unsure',
                    'reason' => $label.': '.mb_substr(trim((string) ($answer['reason'] ?? 'AI yanıt vermedi.')), 0, 180), 'at' => $at];
            }
        }

        return ['verdict' => 'ok', 'reason' => mb_substr(trim((string) data_get($answers->get(0), 'reason', '')), 0, 180), 'at' => $at];
    }

    /** @return Collection<int, Suggestion> checked articles whose date has come, waiting for the daily send */
    public function ready(?int $brandId = null): Collection
    {
        $today = self::today()->toDateString();

        return Suggestion::query()->where('channel', 'search')->where('action_type', SiteSuggestionTypes::CONTENT)->where('status', Suggestion::APPROVED)
            ->whereNotNull('action->autopilot')->when($brandId !== null, fn (Builder $q): Builder => $q->where('brand_id', $brandId))
            ->whereHas('brand', fn (Builder $brand): Builder => $brand->operational())->get()
            ->filter(fn (Suggestion $s): bool => data_get($s->action, 'autopilot.review.verdict') === 'ok' && ! isset($s->action['article_write_id'])
                && is_array($s->action['article'] ?? null) && (string) data_get($s->action, 'autopilot.date') <= $today)
            ->sortBy(fn (Suggestion $s): string => (string) data_get($s->action, 'autopilot.date'))->values();
    }

    /** @return Collection<int, Suggestion> dated articles the check held back (shown with their reason) */
    public function held(?int $brandId = null): Collection
    {
        return Suggestion::query()->where('channel', 'search')->where('action_type', SiteSuggestionTypes::CONTENT)->where('status', Suggestion::APPROVED)
            ->whereNotNull('action->autopilot')->when($brandId !== null, fn (Builder $q): Builder => $q->where('brand_id', $brandId))->get()
            ->filter(fn (Suggestion $s): bool => in_array(data_get($s->action, 'autopilot.review.verdict'), ['wrong', 'unsure'], true) && ! isset($s->action['article_write_id']))->values();
    }

    /** The plan date as the post's date and time (10:00 Istanbul). */
    public static function postDate(Suggestion $suggestion): CarbonImmutable
    {
        return CarbonImmutable::parse((string) data_get($suggestion->action, 'autopilot.date'), 'Europe/Istanbul')->setTime(self::POST_HOUR, 0);
    }

    /**
     * The operator's daily click: every checked article whose date has come goes to WordPress as a draft dated to its
     * plan date (scheduled where the date is still ahead and the site allows it), with its translations.
     *
     * @return array{sent: int, failed: list<string>}
     */
    public function sendReady(User $user, ?int $brandId = null): array
    {
        $planner = app(ContentPlanner::class);
        $sent = 0;
        $failed = [];
        foreach ($this->ready($brandId) as $suggestion) {
            try {
                $planner->sendDraft($suggestion, $user, self::postDate($suggestion));
                $sent++;
            } catch (Throwable $exception) {
                $failed[] = $suggestion->title.': '.mb_substr($exception->getMessage(), 0, 160);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /** The user the otopilot approves titles as (the first active Admin). */
    public static function actor(): ?User
    {
        return User::query()->role(Roles::ADMIN)->where('is_active', true)->orderBy('id')->first();
    }
}
