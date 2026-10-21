<?php

namespace App\Services\Work;

use App\Ai\Agents\Site\ContentReviewAgent;
use App\Jobs\Site\TopUpContentPoolJob;
use App\Models\Brand;
use App\Models\ContentSiteState;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Brand\BrandFacts;
use App\Services\Compliance\ForbiddenTerms;
use App\Services\Epicrisis\Epicrisis;
use App\Services\Epicrisis\EpicrisisClaims;
use App\Services\Site\ContentFormat;
use App\Services\Site\ContentMap;
use App\Services\Site\ContentPlanner;
use App\Services\Site\SiteAi;
use App\Services\Site\SiteOperations;
use App\Services\Site\SiteScope;
use App\Services\Site\SiteSuggestions;
use App\Services\Site\SiteSuggestionTypes;
use App\Support\Roles;
use App\Support\SchemaState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * İçerik otopilotu (yakup, 2026-10-14; narrowed 2026-10-16).
 *
 *  - Geriye dönük doldurma: only for the sites that existed on 2026-10-16 without an own post in the last 60 days
 *    (ContentSiteState backfill "eligible"), one-off: one back-dated article every two days up to today, then the rule
 *    is off for the site ("done"). Every other site never gets back-dated articles.
 *  - Haftalık döngü (planWeek, Monday 05:00): the week's new articles (written backlog first, then this week's
 *    Wednesday ideas) are dated Monday / Wednesday / Friday, written with their translations and AI-checked; the
 *    Monday click sends them, scheduled for their days. Updates never go with the click.
 *
 * Earlier rule (2026-10-14): every site gets one article every two days, dated from its last published post.
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

    /** The writing round of the cluster-map rules (2026-10-15): dated articles written before it are written again once. */
    public const int REWRITE_ROUND = 1;

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

    /** @return list<int> WordPress post ids MoxDOP wrote on the site (article drafts, page copies) */
    public static function moxdopPostIds(DigitalAsset $site): array
    {
        return ExternalWriteAction::query()->where('digital_asset_id', $site->id)->where('channel', ExternalWriteAction::CHANNEL_WORDPRESS)
            ->whereIn('action', [ExternalWriteAction::ACTION_ARTICLE_DRAFTS, ExternalWriteAction::ACTION_CONTENT_DRAFT, ExternalWriteAction::ACTION_DRAFT_CREATE])
            ->whereIn('status', ['succeeded', 'partial'])->get(['id', 'result'])
            ->flatMap(fn (ExternalWriteAction $w): array => [(int) data_get($w->result, 'post_id'), ...array_map(fn (mixed $p): int => (int) data_get($p, 'post_id'), (array) data_get($w->result, 'posts', []))])
            ->filter()->unique()->values()->all();
    }

    /** The day of the site's newest published post that MoxDOP did not write, null when none is known. */
    public static function lastOwnPost(DigitalAsset $site): ?CarbonImmutable
    {
        if (! SchemaState::hasTable('website_cms_object_snapshot')) {
            return null;
        }
        $date = DB::table('website_cms_object_snapshot')->where('digital_asset_id', $site->id)->where('status', 'publish')->where('object_type', 'post')
            ->whereNotIn('object_id', self::moxdopPostIds($site) ?: [0])->max('published_at');

        return $date !== null ? CarbonImmutable::parse((string) $date)->setTimezone('Europe/Istanbul')->startOfDay() : null;
    }

    /**
     * yakup, 2026-10-16: "2 günde 1 kuralı son 60 gündür içerik paylaşılmamış markalar için geçerli olsun; sadece mevcut
     * markaları kapsasın". Once for the sites of today: a site whose own last post is older than BACKFILL_DAYS (or that
     * has none) keeps the back-dated fill; every other site has it off and its unsent dated articles lose their dates
     * (they wait for the weekly cycle with their check). A site scoped before is never scoped again.
     *
     * @return array{eligible: int, off: int, freed: int}
     */
    public function scopeBackfill(?CarbonImmutable $today = null): array
    {
        $today ??= self::today();
        $out = ['eligible' => 0, 'off' => 0, 'freed' => 0];
        foreach (self::sites() as $site) {
            $state = ContentSiteState::for($site);
            if ($state->backfill !== null) {
                continue;
            }
            $own = self::lastOwnPost($site);
            $eligible = $own === null || $own->lt($today->subDays(self::BACKFILL_DAYS));
            $state->forceFill(['backfill' => $eligible ? ContentSiteState::BACKFILL_ELIGIBLE : ContentSiteState::BACKFILL_OFF])->save();
            $out[$eligible ? 'eligible' : 'off']++;
            if (! $eligible) {
                $out['freed'] += $this->freeDates($site);
            }
        }

        return $out;
    }

    /** Unsent back-dated articles of the site lose their dates (the article and its check stay). */
    private function freeDates(DigitalAsset $site): int
    {
        $freed = 0;
        foreach (self::planned($site) as $suggestion) {
            $action = (array) $suggestion->action;
            if (isset($action['article_write_id']) || isset($action['autopilot']['week'])) {
                continue;
            }
            $action['autopilot'] = array_diff_key((array) $action['autopilot'], array_flip(['date', 'planned_at'])) + ['freed_at' => now()->toIso8601String()];
            $suggestion->forceFill(['action' => $action])->save();
            $freed++;
        }

        return $freed;
    }

    /** The day of the site's newest published post (WordPress inventory), null when none is known. */
    public static function lastPublished(DigitalAsset $site): ?CarbonImmutable
    {
        if (! SchemaState::hasTable('website_cms_object_snapshot')) {
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
     * The back-dated dates still without an article (only while the site's one-off fill is on): from two days after
     * the last post or plan (never before the last 60 days) up to today. Once no date is left the fill is done.
     *
     * @return list<string>
     */
    public function openDates(DigitalAsset $site, ?CarbonImmutable $today = null): array
    {
        $today ??= self::today();
        $state = ContentSiteState::for($site);
        if (! $state->inBackfill()) {
            return [];
        }
        $lastPlanned = self::planned($site)->map(fn (Suggestion $s): string => (string) data_get($s->action, 'autopilot.date'))->max();
        $anchor = collect([self::lastPublished($site), $lastPlanned !== null ? CarbonImmutable::parse($lastPlanned, 'Europe/Istanbul') : null])->filter()->max();
        $first = $today->subDays(self::BACKFILL_DAYS);
        $next = $anchor !== null ? $anchor->addDays(self::EVERY_DAYS) : $first;
        $next = $next->lt($first) ? $first : $next;
        $dates = [];
        for ($day = $next; $day->lte($today); $day = $day->addDays(self::EVERY_DAYS)) {
            $dates[] = $day->toDateString();
        }
        if ($dates === []) {
            $state->forceFill(['backfill' => ContentSiteState::BACKFILL_DONE, 'backfill_done_at' => now()])->save();
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
        $map = app(ContentMap::class);
        $next = 0;
        foreach ($dates as $date) {
            // Küme haritası (2026-10-15): an idea a page of the site already answers becomes that page's update, a second
            // idea of the same subject closes; neither takes a date.
            do {
                $suggestion = $ordered->get($next++);
            } while ($suggestion !== null && $map->resolve($suggestion) !== null);
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

    /** Weekday offsets of the week's articles: Monday, Wednesday, Friday first. */
    public const array WEEK_DAYS = [0, 2, 4, 1, 3, 5, 6];

    /**
     * Haftalık döngü, Monday (yakup, 2026-10-16: "pazartesi tekrar otomatik yazdırıp siteye göndersin; güncellemeler
     * hariç"): the site's new articles of the week get their dates (Mon / Wed / Fri at POST_HOUR) and are written with
     * their translations, then AI-checked. Taken in this order: written and approved new articles not sent yet, approved
     * ones still being written, then this cycle's ideas (Wednesday's). New articles the operator sent himself since
     * Wednesday count against the week's number. Sites still in their back-dated fill wait.
     *
     * @return array{planned: int, quota: int, reason?: string}
     */
    public function planWeek(DigitalAsset $site, User $by, ?CarbonImmutable $today = null): array
    {
        $today ??= self::today();
        $monday = $today->startOfWeek(CarbonImmutable::MONDAY);
        $state = ContentSiteState::for($site);
        if ($state->inBackfill()) {
            return ['planned' => 0, 'quota' => 0, 'reason' => 'backfill'];
        }
        if (! SiteScope::aiAllowed(Brand::query()->find($site->brand_id))) {
            return ['planned' => 0, 'quota' => 0, 'reason' => 'not_operational'];
        }
        $quota = $state->new_per_week ?? app(ContentRhythm::class)->store($site)['new'];
        $cycleStart = $monday->subDays(5);
        $ideas = self::ideas($site)->get()->filter(fn (Suggestion $s): bool => ($s->action['kind'] ?? 'new') !== 'update');
        $thisWeek = $ideas->filter(fn (Suggestion $s): bool => data_get($s->action, 'autopilot.week') === $monday->toDateString());
        $sentByHand = $ideas->filter(fn (Suggestion $s): bool => isset($s->action['article_write_id']) && data_get($s->action, 'autopilot') === null
            && $s->resolved_at !== null && $s->resolved_at->gte($cycleStart))->count();
        $left = $quota - $thisWeek->count() - $sentByHand;
        if ($left <= 0) {
            return ['planned' => 0, 'quota' => $quota];
        }
        $free = fn (Suggestion $s): bool => ! isset($s->action['article_write_id']) && ! isset($s->action['article_blocked']) && data_get($s->action, 'autopilot.date') === null;
        $written = $ideas->filter(fn (Suggestion $s): bool => $free($s) && $s->status === Suggestion::APPROVED && is_array($s->action['article'] ?? null)
            && data_get($s->action, 'autopilot.review.verdict') !== 'wrong');
        $writing = $ideas->filter(fn (Suggestion $s): bool => $free($s) && $s->status === Suggestion::APPROVED && ! is_array($s->action['article'] ?? null));
        $fresh = $ideas->filter(fn (Suggestion $s): bool => $free($s) && $s->status === Suggestion::OPEN && (string) data_get($s->action, 'cycle', '') >= $cycleStart->toDateString())
            ->sortBy('id');
        $map = app(ContentMap::class);
        $used = $thisWeek->count();
        $planned = 0;
        foreach ([...$written->sortBy('id')->values(), ...$writing->sortBy('id')->values(), ...$fresh->values()] as $suggestion) {
            if ($planned >= $left || $used >= count(self::WEEK_DAYS)) {
                break;
            }
            if ($map->resolve($suggestion) !== null) {
                continue;
            }
            if ($suggestion->status !== Suggestion::APPROVED) {
                $this->suggestions->approve($suggestion, $by);
                $suggestion->refresh();
            }
            $date = $monday->addDays(self::WEEK_DAYS[$used++])->toDateString();
            $autopilot = array_merge((array) data_get($suggestion->action, 'autopilot', []), ['date' => $date, 'planned_at' => now()->toIso8601String(), 'week' => $monday->toDateString()]);
            $suggestion->forceFill(['action' => array_merge((array) $suggestion->action, ['autopilot' => $autopilot])])->save();
            if (! is_array($suggestion->action['article'] ?? null)) {
                SiteOperations::dispatch((int) $site->id, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => (int) $suggestion->id]);
            }
            $planned++;
        }
        $state->forceFill(['week_planned' => $monday])->save();

        return ['planned' => $planned, 'quota' => $quota];
    }

    /**
     * Every written new article of the site that has not gone to WordPress yet, dated by the otopilot or written from
     * the operator's own "Yaz" (yakup 2026-10-20: every article is checked, so the one click can send all of them).
     * Page updates stay out: they replace a live page and keep their own approval.
     *
     * @return Collection<int, Suggestion>
     */
    public static function written(DigitalAsset $site): Collection
    {
        return self::ideas($site)->where('status', Suggestion::APPROVED)->whereNull('action->article_write_id')->get()
            ->filter(fn (Suggestion $s): bool => self::sendable($s))->values();
    }

    /** A written new article that has not been sent (no page update, no rule block). */
    public static function sendable(Suggestion $suggestion): bool
    {
        $action = (array) $suggestion->action;

        return is_array($action['article'] ?? null) && ! isset($action['article_write_id']) && ! isset($action['article_blocked'])
            && ($action['kind'] ?? null) !== 'update' && empty($action['page_update']);
    }

    /**
     * Reads the site's written articles once more (`content.review`), one call per article. Runs in the site's
     * content job, so Claude (MCP) can answer when the credit is used up.
     *
     * @return array{status: string, reviewed: int, waiting: int}
     */
    public function review(DigitalAsset $site): array
    {
        $brand = Brand::query()->with('sectorCategory')->find($site->brand_id);
        $reviewed = 0;
        $waiting = 0;
        foreach (self::written($site) as $suggestion) {
            $action = (array) $suggestion->action;
            if (data_get($action, 'autopilot.review') !== null) {
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
        $format = (array) ($action['article_format'] ?? []);
        $words = ContentFormat::words((string) ($article['html'] ?? ''));
        // Too thin for its subject even after the second write: never sent by the one click (yakup, 2026-10-15).
        if (isset($format['words_min'], $format['words_max']) && ContentFormat::tooShort($words, ['words' => [(int) $format['words_min'], (int) $format['words_max']]])) {
            return ['verdict' => 'wrong', 'reason' => 'Yazı kısa: '.$words.' kelime; bu konu için '.$format['words_min'].'–'.$format['words_max'].' kelime gerekiyor.', 'at' => $at];
        }
        $facts = array_intersect_key(app(BrandFacts::class)->forPrompt($brand), array_flip(['experts', 'regions', 'questions', 'objections', 'praise']));
        // Bilgi doğrulama (marka epikrizi): a phone, price, warranty, years or patient count the brand's record and the
        // article's own plan do not hold is invented; the article stays on the desk with the claim named.
        $claims = app(EpicrisisClaims::class)->unsupported($brand, implode(' ', [(string) ($article['title'] ?? ''), (string) ($article['meta_description'] ?? ''), (string) ($article['html'] ?? '')]),
            [(string) $suggestion->title, (string) $suggestion->reason, (string) json_encode([$action['outline'] ?? [], $action['questions'] ?? [], $action['brief'] ?? [], $facts], JSON_UNESCAPED_UNICODE)]);
        if ($claims !== []) {
            return ['verdict' => 'wrong', 'reason' => EpicrisisClaims::reason($claims), 'at' => $at];
        }
        $items = [['id' => 0, 'title' => (string) ($article['title'] ?? ''), 'html' => mb_substr((string) ($article['html'] ?? ''), 0, 30000),
            'language' => (string) ($article['language'] ?? ContentPlanner::articleLanguage($suggestion, $site)),
            'source' => array_filter(['title' => $suggestion->title, 'reason' => $suggestion->reason, 'outline' => $action['outline'] ?? null, 'questions' => $action['questions'] ?? null,
                'brand_facts' => $facts ?: null, 'format' => $format !== [] ? $format + ['words' => $words] : null,
                'brief' => is_array($action['brief'] ?? null) ? array_intersect_key((array) $action['brief'], array_flip(['must_cover', 'gaps', 'people_also_ask', 'brand_element'])) : null])]];
        foreach ((array) ($action['translations'] ?? []) as $language => $translation) {
            $items[] = ['id' => count($items), 'title' => (string) data_get($translation, 'title', ''), 'html' => mb_substr((string) data_get($translation, 'html', ''), 0, 30000),
                'language' => (string) $language, 'source' => ['translation_of' => (string) ($article['title'] ?? '')]];
        }
        $result = app(SiteAi::class)->run(new ContentReviewAgent, [
            'kind' => 'article',
            'brand' => ['name' => $brand->name, 'sector' => $brand->sectorCategory?->name],
            'forbidden' => ForbiddenTerms::forBrand($brand)->phrases(),
            'items' => $items,
        ] + Epicrisis::input($brand, 'content'), 240, 'autopilot-review-'.$suggestion->id);
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

    /**
     * Checked articles waiting for the send: back-dated ones whose date has come, this week's ones (sent scheduled
     * for their day, so the Monday click covers the week) and the undated ones the operator had written (sent as
     * drafts; the site's "Akıllı tarihle yayınla" dates them when the operator publishes).
     *
     * @return Collection<int, Suggestion>
     */
    public function ready(?int $brandId = null): Collection
    {
        $today = self::today()->toDateString();
        $weekEnd = self::today()->endOfWeek(CarbonImmutable::SUNDAY)->toDateString();

        return Suggestion::query()->where('channel', 'search')->where('action_type', SiteSuggestionTypes::CONTENT)->where('status', Suggestion::APPROVED)
            ->whereNotNull('action->autopilot')->when($brandId !== null, fn (Builder $q): Builder => $q->where('brand_id', $brandId))
            ->whereHas('brand', fn (Builder $brand): Builder => $brand->operational())->get()
            ->filter(fn (Suggestion $s): bool => data_get($s->action, 'autopilot.review.verdict') === 'ok' && self::sendable($s)
                && (! is_string(data_get($s->action, 'autopilot.date'))
                    || (string) data_get($s->action, 'autopilot.date') <= (data_get($s->action, 'autopilot.week') !== null ? $weekEnd : $today)))
            ->sortBy(fn (Suggestion $s): string => (string) (data_get($s->action, 'autopilot.date') ?? '9999'))->values();
    }

    /**
     * Websites with a paired WordPress Connector. A checked article for any other site is not tried by the daily send:
     * it waits, and the strip says once per site that the connector is missing (yakup 2026-10-19, Onarım masası).
     *
     * @return list<int>
     */
    public static function pairedSiteIds(): array
    {
        return CoreConnection::query()->where('type', 'wordpress_connector')->where('enabled', true)->get(['digital_asset_id', 'config'])
            ->filter(fn (CoreConnection $c): bool => data_get($c->config, 'pairing_state') === 'paired')
            ->map(fn (CoreConnection $c): int => (int) $c->digital_asset_id)->unique()->values()->all();
    }

    /** This week's articles still being written, translated or checked, plus written ones still waiting for the check (shown next to the send button). */
    public function preparing(): int
    {
        $monday = self::today()->startOfWeek(CarbonImmutable::MONDAY)->toDateString();

        return Suggestion::query()->where('channel', 'search')->where('action_type', SiteSuggestionTypes::CONTENT)->where('status', Suggestion::APPROVED)
            ->whereNull('action->article_write_id')->get(['id', 'action'])
            ->filter(fn (Suggestion $s): bool => data_get($s->action, 'autopilot.review') === null && ! isset($s->action['article_blocked'])
                && (data_get($s->action, 'autopilot.week') === $monday || self::sendable($s)))->count();
    }

    /** @return Collection<int, Suggestion> dated articles the check held back (shown with their reason) */
    public function held(?int $brandId = null): Collection
    {
        return Suggestion::query()->where('channel', 'search')->where('action_type', SiteSuggestionTypes::CONTENT)->where('status', Suggestion::APPROVED)
            ->whereNotNull('action->autopilot')->when($brandId !== null, fn (Builder $q): Builder => $q->where('brand_id', $brandId))->get()
            ->filter(fn (Suggestion $s): bool => in_array(data_get($s->action, 'autopilot.review.verdict'), ['wrong', 'unsure'], true) && self::sendable($s))->values();
    }

    /**
     * The plan date as the post's date and time (10:00 Istanbul); null for an undated article (sent as a plain draft). A week article whose time has already passed when
     * the operator clicks goes out a few minutes later (scheduled, not left as a draft); a back-dated one keeps its date.
     */
    public static function postDate(Suggestion $suggestion): ?CarbonImmutable
    {
        if (! is_string(data_get($suggestion->action, 'autopilot.date'))) {
            return null;
        }
        $date = CarbonImmutable::parse((string) data_get($suggestion->action, 'autopilot.date'), 'Europe/Istanbul')->setTime(self::POST_HOUR, 0);
        if (data_get($suggestion->action, 'autopilot.week') !== null && $date->lte(now()->addMinutes(5))) {
            return CarbonImmutable::now('Europe/Istanbul')->addMinutes(15)->startOfMinute();
        }

        return $date;
    }

    /**
     * The operator's daily click: every checked article whose date has come goes to WordPress as a draft dated to its
     * plan date (scheduled where the date is still ahead and the site allows it), with its translations.
     *
     * `$onProgress(done, total)` is called before the first article and after each one (the daily send's live progress).
     *
     * @param  (callable(int, int): mixed)|null  $onProgress
     * @return array{sent: int, failed: list<string>}
     */
    public function sendReady(User $user, ?int $brandId = null, ?callable $onProgress = null, ?int $siteId = null): array
    {
        $planner = app(ContentPlanner::class);
        $sent = 0;
        $failed = [];
        $paired = self::pairedSiteIds();
        $ready = $this->ready($brandId)->filter(fn (Suggestion $s): bool => in_array((int) data_get($s->action, 'site_id'), $paired, true)
            && ($siteId === null || (int) data_get($s->action, 'site_id') === $siteId))->values();
        $total = $ready->count();
        if ($onProgress !== null) {
            $onProgress(0, $total);
        }
        foreach ($ready as $suggestion) {
            try {
                $planner->sendDraft($suggestion, $user, self::postDate($suggestion));
                $sent++;
            } catch (Throwable $exception) {
                $failed[] = $suggestion->title.': '.mb_substr($exception->getMessage(), 0, 160);
            }
            if ($onProgress !== null) {
                $onProgress($sent + count($failed), $total);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Yeni yazım kuralı (yakup, 2026-10-15 "sırayla hepsini yap"): every article not sent yet that was written before the
     * new rules (no `article_format`), dated or still waiting in the pool, is planned again by the cluster map (an idea a
     * page of the site already answers becomes that page's update, a second one of the same subject closes; their dates
     * are freed) and the rest are written again with the new rules, translations included. Once per round. The first
     * round only took dated ones and left the 159 pool articles of 14 Oct in the old template (2026-10-16 check).
     * Articles already written by the new rules get their empty headings removed and, once, a fresh otopilot check
     * (content-review-v3 no longer holds an article for a few words under its range).
     *
     * @return array{rewrite: int, update: int, closed: int, recheck: int}
     */
    public function rework(): array
    {
        $map = app(ContentMap::class);
        $out = ['rewrite' => 0, 'update' => 0, 'closed' => 0, 'recheck' => 0];
        Suggestion::query()->where('channel', 'search')->where('action_type', SiteSuggestionTypes::CONTENT)->where('status', Suggestion::APPROVED)
            ->lazyById(100)->each(function (Suggestion $suggestion) use ($map, &$out): void {
                $action = (array) $suggestion->action;
                if (! is_array($action['article'] ?? null) || isset($action['article_write_id'])) {
                    return;
                }
                if (isset($action['article_format'])) {
                    if ($this->recheck($suggestion, $action)) {
                        $out['recheck']++;
                    }

                    return;
                }
                if ((int) ($action['rewrite'] ?? 0) >= self::REWRITE_ROUND) {
                    return;
                }
                $resolved = $map->resolve($suggestion);
                if ($resolved !== null) {
                    $out[$resolved['outcome'] === 'update' ? 'update' : 'closed']++;

                    return;
                }
                unset($action['article'], $action['translations'], $action['translations_blocked'], $action['article_blocked'], $action['article_blocked_draft'],
                    $action['article_seo'], $action['article_warnings'], $action['article_note'], $action['article_format']);
                if (isset($action['autopilot'])) {
                    $action['autopilot'] = array_diff_key((array) $action['autopilot'], array_flip(['review', 'written_seen_at']));
                }
                $action['rewrite'] = self::REWRITE_ROUND;
                $suggestion->forceFill(['action' => $action])->save();
                SiteOperations::dispatch((int) $action['site_id'], SiteOperations::WRITE_ARTICLE, ['suggestion_id' => (int) $suggestion->id]);
                $out['rewrite']++;
            });

        return $out;
    }

    /**
     * A new-rule article: headings left empty are removed (text and translations) and a held otopilot check is done
     * again once with the current review rules.
     *
     * @param  array<string, mixed>  $action
     */
    private function recheck(Suggestion $suggestion, array $action): bool
    {
        if (data_get($action, 'autopilot.rechecked') !== null) {
            return false;
        }
        $changed = false;
        $tidy = function (array $text) use (&$changed): array {
            $html = ContentFormat::dropEmptySections((string) ($text['html'] ?? ''));
            if ($html !== trim((string) ($text['html'] ?? ''))) {
                $changed = true;
                $text['html'] = $html;
            }

            return $text;
        };
        $action['article'] = $tidy((array) $action['article']);
        foreach ((array) ($action['translations'] ?? []) as $language => $translation) {
            if (is_array($translation)) {
                $action['translations'][$language] = $tidy($translation);
            }
        }
        $held = isset($action['autopilot']) && data_get($action, 'autopilot.review') !== null && data_get($action, 'autopilot.review.verdict') !== 'ok';
        if (! $changed && ! $held) {
            return false;
        }
        if (isset($action['autopilot'])) {
            $action['autopilot'] = array_diff_key((array) $action['autopilot'], array_flip(['review'])) + ['rechecked' => now()->toIso8601String()];
        }
        $suggestion->forceFill(['action' => $action])->save();

        return true;
    }

    /** The user the otopilot approves titles as (the first active Admin). */
    public static function actor(): ?User
    {
        return User::query()->role(Roles::ADMIN)->where('is_active', true)->orderBy('id')->first();
    }
}
