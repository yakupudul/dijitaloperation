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

    /**
     * An article the check holds back is corrected (its own text, revised with the check's reason) this many times before
     * it waits for the operator with a plain reason (yakup 2026-10-27: "kontrole takılanı Claude düzeltsin").
     */
    public const int CHECK_FIXES = 3;

    /** An article a sector rule blocked is written again (with the rule's phrases and a final rule clean-up) this many times. */
    public const int BLOCK_FIXES = 2;

    /** An AI check that could not run (no answer, error) is asked again after this long. */
    public const int RECHECK_MINUTES = 60;

    /** A write still "running" after this long is taken as lost. */
    public const int BUSY_HOURS = 3;

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
     * Aşama 8 (2026-10-22): this cycle's ideas are no longer approved here. They are proposed for their day (action.plan)
     * with the week's updates (thin ★ / service pages first) and wait for the operator's "Tümünü onayla" or "At"
     * (ContentWeekPlan). Already approved articles are dated as before. Last week's unanswered proposals go back to the pool.
     *
     * @return array{planned: int, quota: int, proposed: int, reason?: string}
     */
    public function planWeek(DigitalAsset $site, User $by, ?CarbonImmutable $today = null): array
    {
        $today ??= self::today();
        $monday = $today->startOfWeek(CarbonImmutable::MONDAY);
        $state = ContentSiteState::for($site);
        if ($state->inBackfill()) {
            return ['planned' => 0, 'quota' => 0, 'proposed' => 0, 'reason' => 'backfill'];
        }
        if (! SiteScope::aiAllowed(Brand::query()->find($site->brand_id))) {
            return ['planned' => 0, 'quota' => 0, 'proposed' => 0, 'reason' => 'not_operational'];
        }
        $quota = $state->new_per_week ?? app(ContentRhythm::class)->store($site)['new'];
        $cycleStart = $monday->subDays(5);
        $all = self::ideas($site)->get();
        $week = $monday->toDateString();
        // Last week's proposals nobody answered go back to the pool.
        foreach ($all as $idea) {
            $planWeek = data_get($idea->action, 'plan.week');
            if ($idea->status === Suggestion::OPEN && is_string($planWeek) && $planWeek < $week && data_get($idea->action, 'plan.approved_at') === null) {
                $action = (array) $idea->action;
                unset($action['plan']);
                $idea->forceFill(['action' => $action])->save();
            }
        }
        $proposedNow = fn (Suggestion $s): bool => $s->status === Suggestion::OPEN && data_get($s->action, 'plan.week') === $week && data_get($s->action, 'plan.approved_at') === null;
        $proposed = $this->proposeUpdates($all, (int) ($state->updates_per_week ?? 0), $week, $proposedNow);
        $ideas = $all->filter(fn (Suggestion $s): bool => ($s->action['kind'] ?? 'new') !== 'update');
        $thisWeek = $ideas->filter(fn (Suggestion $s): bool => data_get($s->action, 'autopilot.week') === $week || $proposedNow($s));
        $sentByHand = $ideas->filter(fn (Suggestion $s): bool => isset($s->action['article_write_id']) && data_get($s->action, 'autopilot') === null
            && data_get($s->action, 'plan') === null && $s->resolved_at !== null && $s->resolved_at->gte($cycleStart))->count();
        $left = $quota - $thisWeek->count() - $sentByHand;
        if ($left <= 0) {
            $state->forceFill(['week_planned' => $monday])->save();

            return ['planned' => 0, 'quota' => $quota, 'proposed' => $proposed];
        }
        $free = fn (Suggestion $s): bool => ! isset($s->action['article_write_id']) && ! isset($s->action['article_blocked']) && data_get($s->action, 'autopilot.date') === null
            && data_get($s->action, 'plan') === null;
        $written = $ideas->filter(fn (Suggestion $s): bool => $free($s) && $s->status === Suggestion::APPROVED && is_array($s->action['article'] ?? null)
            && data_get($s->action, 'autopilot.review.verdict') !== 'wrong');
        $writing = $ideas->filter(fn (Suggestion $s): bool => $free($s) && $s->status === Suggestion::APPROVED && ! is_array($s->action['article'] ?? null));
        $fresh = $ideas->filter(fn (Suggestion $s): bool => $free($s) && $s->status === Suggestion::OPEN && (string) data_get($s->action, 'cycle', '') >= $cycleStart->toDateString())
            ->sortBy('id');
        $map = app(ContentMap::class);
        $used = $thisWeek->count();
        $planned = 0;
        $proposedNew = 0;
        foreach ([...$written->sortBy('id')->values(), ...$writing->sortBy('id')->values(), ...$fresh->values()] as $suggestion) {
            if ($planned >= $left || $used >= count(self::WEEK_DAYS)) {
                break;
            }
            if ($map->resolve($suggestion) !== null) {
                continue;
            }
            $date = $monday->addDays(self::WEEK_DAYS[$used++])->toDateString();
            if ($suggestion->status !== Suggestion::APPROVED) {
                // Proposed for the day; the operator's "Tümünü onayla" writes it (ContentWeekPlan).
                $suggestion->forceFill(['action' => array_merge((array) $suggestion->action, ['plan' => ['week' => $week, 'date' => $date, 'proposed_at' => now()->toIso8601String()]])])->save();
                $proposed++;
                $proposedNew++;
                $planned++;

                continue;
            }
            $autopilot = array_merge((array) data_get($suggestion->action, 'autopilot', []), ['date' => $date, 'planned_at' => now()->toIso8601String(), 'week' => $monday->toDateString()]);
            $suggestion->forceFill(['action' => array_merge((array) $suggestion->action, ['autopilot' => $autopilot])])->save();
            if (! is_array($suggestion->action['article'] ?? null)) {
                SiteOperations::dispatch((int) $site->id, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => (int) $suggestion->id]);
            }
            $planned++;
        }
        $state->forceFill(['week_planned' => $monday])->save();

        return ['planned' => $planned - $proposedNew, 'quota' => $quota, 'proposed' => $proposed];
    }

    /**
     * The week's update proposals: up to the site's updates per week, thin service pages ("ana sayfa güçlendirilecek")
     * first, then the most urgent update ideas.
     *
     * @param  Collection<int, Suggestion>  $all
     * @param  callable(Suggestion): bool  $proposedNow
     */
    private function proposeUpdates(Collection $all, int $perWeek, string $week, callable $proposedNow): int
    {
        $updates = $all->filter(fn (Suggestion $s): bool => ($s->action['kind'] ?? 'new') === 'update');
        $left = $perWeek - $updates->filter($proposedNow)->count()
            - $updates->filter(fn (Suggestion $s): bool => data_get($s->action, 'plan.week') === $week && data_get($s->action, 'plan.approved_at') !== null)->count();
        if ($left <= 0) {
            return 0;
        }
        $map = app(ContentMap::class);
        $candidates = $updates->filter(fn (Suggestion $s): bool => $s->status === Suggestion::OPEN && data_get($s->action, 'plan') === null
            && ! is_array($s->action['article'] ?? null) && ! isset($s->action['article_blocked']))
            ->sortBy(fn (Suggestion $s): string => (ContentWeekPlan::kind($s) === 'strengthen' ? '0' : '1').str_pad((string) $s->priority, 3, '0', STR_PAD_LEFT).str_pad((string) $s->id, 10, '0', STR_PAD_LEFT));
        $proposed = 0;
        foreach ($candidates as $suggestion) {
            if ($proposed >= $left) {
                break;
            }
            if ($map->resolve($suggestion) !== null) {
                continue;
            }
            $suggestion->forceFill(['action' => array_merge((array) $suggestion->action, ['plan' => ['week' => $week, 'proposed_at' => now()->toIso8601String()]])])->save();
            $proposed++;
        }

        return $proposed;
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
        $this->heal($site);
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
            $this->fixAfterCheck($fresh->fresh(), $verdict);
        }

        return ['status' => 'ready', 'reviewed' => $reviewed, 'waiting' => $waiting];
    }

    /**
     * Otomatik düzeltme (yakup 2026-10-27: "kontrole ve kurala takılanlar otomatik düzeltilsin, neden yeniden yaz butonu
     * çıkıyor?"). Every round of the site's content job first heals what is stuck:
     *  - a check that could not run (no AI answer) is asked again after RECHECK_MINUTES;
     *  - an article the check held back is revised with the check's reason (fixAfterCheck), CHECK_FIXES times at most;
     *  - an article a sector rule blocked is written again from its blocked draft with the offending phrases (the writer
     *    then removes what is still left by rule), BLOCK_FIXES times at most;
     *  - a revision whose write was lost (no article, nothing running) is started again, or the last text comes back.
     * Only what is still wrong after that waits for the operator, with the reason in plain words.
     *
     * @return array{fixing: int, rechecked: int}
     */
    public function heal(DigitalAsset $site): array
    {
        $out = ['fixing' => 0, 'rechecked' => 0];
        $ideas = self::ideas($site)->where('status', Suggestion::APPROVED)->whereNull('action->article_write_id')->get();
        foreach ($ideas as $suggestion) {
            $action = (array) $suggestion->action;
            if (! self::fixing($suggestion) || self::busy($suggestion, $site)) {
                continue;
            }
            $review = data_get($action, 'autopilot.review');
            if (is_array($action['revise_from'] ?? null) && ! is_array($action['article'] ?? null) && ! isset($action['article_blocked'])) {
                $out['fixing'] += $this->resumeRevision($suggestion);

                continue;
            }
            if (is_array($review) && ! empty($review['retry']) && self::sendable($suggestion)
                && CarbonImmutable::parse((string) ($review['at'] ?? now()->toIso8601String()))->lt(now()->subMinutes(self::RECHECK_MINUTES))) {
                $action['autopilot'] = array_diff_key((array) $action['autopilot'], ['review' => true]);
                $suggestion->forceFill(['action' => $action])->save();
                $out['rechecked']++;

                continue;
            }
            if (is_array($review) && self::sendable($suggestion) && $this->fixAfterCheck($suggestion, $review)) {
                $out['fixing']++;

                continue;
            }
            if (is_string($action['article_blocked'] ?? null) && (int) ($action['block_fixes'] ?? 0) < self::BLOCK_FIXES) {
                $draft = $action['article_blocked_draft'] ?? null;
                $action['block_fixes'] = (int) ($action['block_fixes'] ?? 0) + 1;
                $action['check_feedback'] = mb_substr((string) $action['article_blocked'], 0, 400);
                if (is_array($draft)) {
                    $action['revise_from'] = self::revisable($draft);
                }
                $suggestion->forceFill(['action' => $action])->save();
                SiteOperations::dispatch((int) $site->id, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => (int) $suggestion->id]);
                $out['fixing']++;
            }
        }

        return $out;
    }

    /** The article (or one of its translations) is being written right now. */
    private static function busy(Suggestion $suggestion, DigitalAsset $site): bool
    {
        foreach ([null, ...ContentPlanner::siteLanguages($site)] as $language) {
            $status = SiteOperations::status((int) $site->id, SiteOperations::WRITE_ARTICLE, array_filter(['suggestion_id' => (int) $suggestion->id, 'language' => $language]));
            // A write that has said nothing for BUSY_HOURS was lost (a worker restart): it no longer holds the article.
            if (in_array($status['status'] ?? null, ['running', 'queued'], true)
                && CarbonImmutable::parse((string) ($status['at'] ?? now()->toIso8601String()))->gt(now()->subHours(self::BUSY_HOURS))) {
                return true;
            }
        }

        return false;
    }

    /** A revision whose write ended without an article: tried again while rounds are left, else the last text comes back held. */
    private function resumeRevision(Suggestion $suggestion): int
    {
        $action = (array) $suggestion->action;
        $tries = (int) ($action['revise_tries'] ?? 0);
        if ($tries < 2) {
            $action['revise_tries'] = $tries + 1;
            $suggestion->forceFill(['action' => $action])->save();
            SiteOperations::dispatch((int) $action['site_id'], SiteOperations::WRITE_ARTICLE, ['suggestion_id' => (int) $suggestion->id]);

            return 1;
        }
        $action['article'] = $action['revise_from'];
        unset($action['revise_from'], $action['revise_tries']);
        $action['check_fixes'] = self::CHECK_FIXES;
        $action['autopilot'] = array_merge((array) ($action['autopilot'] ?? []), ['review' => ['verdict' => 'unsure', 'at' => now()->toIso8601String(),
            'reason' => 'Claude düzeltmeyi yazamadı: '.mb_substr((string) ($action['check_feedback'] ?? ''), 0, 160)]]);
        $suggestion->forceFill(['action' => $action])->save();

        return 0;
    }

    /** @param  array<string, mixed>  $article  @return array<string, mixed> the fields the writer revises */
    public static function revisable(array $article): array
    {
        return array_intersect_key($article, array_flip(['title', 'slug', 'meta_title', 'meta_description', 'excerpt', 'html', 'language']));
    }

    /**
     * An article the check holds back ("wrong", or "unsure" from the AI's own reading) is corrected with the check's
     * reason, CHECK_FIXES times at most: the writer gets its own text back (`revise_from`) and changes only what the
     * reason names (removes the unsupported claim, adds the missing part). A held translation is translated again from
     * the source. A check that could not run is not a reason to rewrite (it is asked again).
     *
     * @param  array<string, mixed>  $verdict
     */
    private function fixAfterCheck(Suggestion $suggestion, array $verdict): bool
    {
        $action = (array) $suggestion->action;
        $fixes = (int) ($action['check_fixes'] ?? 0);
        if (! in_array($verdict['verdict'] ?? null, ['wrong', 'unsure'], true) || ! empty($verdict['retry']) || $fixes >= self::CHECK_FIXES
            || isset($action['article_write_id']) || ! is_array($action['article'] ?? null)) {
            return false;
        }
        $reason = (string) ($verdict['reason'] ?? '');
        $action['check_fixes'] = $fixes + 1;
        $action['check_feedback'] = mb_substr($reason, 0, 400);
        $action['autopilot'] = array_diff_key((array) ($action['autopilot'] ?? []), array_flip(['review', 'written_seen_at']));
        if ($action['autopilot'] === []) {
            unset($action['autopilot']);
        }
        if (preg_match('/^Çeviri \(([A-Z]{2,3})\)/u', $reason, $m) === 1 && is_array($action['translations'][strtolower($m[1])] ?? null)) {
            $language = strtolower($m[1]);
            unset($action['translations'][$language]);
            $suggestion->forceFill(['action' => $action])->save();
            SiteOperations::dispatch((int) $action['site_id'], SiteOperations::WRITE_ARTICLE, ['suggestion_id' => (int) $suggestion->id, 'language' => $language]);

            return true;
        }
        $action['revise_from'] = self::revisable((array) $action['article']);
        unset($action['article'], $action['translations'], $action['translations_blocked'], $action['article_seo'], $action['article_warnings'], $action['article_note'],
            $action['article_format'], $action['article_score'], $action['revise_tries']);
        $action['check_rewrites'] = (int) ($action['check_rewrites'] ?? 0) + 1;
        $suggestion->forceFill(['action' => $action])->save();
        SiteOperations::dispatch((int) $action['site_id'], SiteOperations::WRITE_ARTICLE, ['suggestion_id' => (int) $suggestion->id]);

        return true;
    }

    /** Still being corrected by Claude (shown instead of a "Yeniden yaz" button). */
    public static function fixing(Suggestion $suggestion): bool
    {
        $action = (array) $suggestion->action;
        if (isset($action['article_write_id'])) {
            return false;
        }
        if (is_array($action['revise_from'] ?? null) && ! is_array($action['article'] ?? null)) {
            return true;
        }
        if (is_string($action['article_blocked'] ?? null)) {
            return (int) ($action['block_fixes'] ?? 0) < self::BLOCK_FIXES;
        }
        $review = data_get($action, 'autopilot.review');

        return is_array($review) && in_array($review['verdict'] ?? null, ['wrong', 'unsure'], true)
            && (! empty($review['retry']) || (int) ($action['check_fixes'] ?? 0) < self::CHECK_FIXES);
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

    /** @return array{verdict: string, reason: string, at: string, retry?: bool}|null null while Claude (MCP) has not answered; retry: the check itself did not run */
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
        // İçerik puanı (Aşama 8): what Google's first results cover, the article must cover too.
        $coverage = is_array($action['brief'] ?? null) ? ArticleCoverage::score((string) ($article['html'] ?? ''), (array) $action['brief']) : null;
        if ($coverage !== null) {
            $suggestion->forceFill(['action' => array_merge((array) $suggestion->fresh()->action, ['article_score' => $coverage['score']])])->save();
            if ($coverage['score'] < ArticleCoverage::MIN) {
                return ['verdict' => 'wrong', 'reason' => ArticleCoverage::reason($coverage), 'at' => $at];
            }
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
            return ['verdict' => 'unsure', 'reason' => mb_substr((string) ($result['message'] ?? 'AI kontrolü yapılamadı.'), 0, 200), 'at' => $at, 'retry' => true];
        }
        $answers = collect((array) ($result['data']['items'] ?? []))->filter(fn ($i): bool => is_array($i) && is_int($i['id'] ?? null))->keyBy('id');
        foreach (array_keys($items) as $id) {
            $answer = (array) $answers->get($id, []);
            if (($answer['verdict'] ?? null) !== 'ok') {
                $label = $id === 0 ? 'Yazı' : 'Çeviri ('.strtoupper((string) $items[$id]['language']).')';

                return ['verdict' => in_array($answer['verdict'] ?? null, ['wrong', 'unsure'], true) ? (string) $answer['verdict'] : 'unsure',
                    'reason' => $label.': '.mb_substr(trim((string) ($answer['reason'] ?? 'AI yanıt vermedi.')), 0, 180), 'at' => $at]
                    + ($answers->has($id) ? [] : ['retry' => true]);
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

    /** @return Collection<int, Suggestion> dated articles the check held back and Claude could not correct (shown with their reason) */
    public function held(?int $brandId = null): Collection
    {
        return Suggestion::query()->where('channel', 'search')->where('action_type', SiteSuggestionTypes::CONTENT)->where('status', Suggestion::APPROVED)
            ->whereNotNull('action->autopilot')->when($brandId !== null, fn (Builder $q): Builder => $q->where('brand_id', $brandId))->get()
            ->filter(fn (Suggestion $s): bool => in_array(data_get($s->action, 'autopilot.review.verdict'), ['wrong', 'unsure'], true) && self::sendable($s) && ! self::fixing($s))->values();
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
    /** Articles sendReady() would take now (paired sites, of the brand / site when given). */
    public function readyFor(?int $brandId = null, ?int $siteId = null): int
    {
        $paired = self::pairedSiteIds();

        return $this->ready($brandId)->filter(fn (Suggestion $s): bool => in_array((int) data_get($s->action, 'site_id'), $paired, true)
            && ($siteId === null || (int) data_get($s->action, 'site_id') === $siteId))->count();
    }

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
