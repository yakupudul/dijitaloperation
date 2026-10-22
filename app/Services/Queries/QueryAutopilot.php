<?php

namespace App\Services\Queries;

use App\Ai\Agents\QueryTriageAgent;
use App\Jobs\Queries\QueryAutopilotJob;
use App\Jobs\Queries\TriageQueriesJob;
use App\Models\FilterTerm;
use App\Models\PendingQuery;
use App\Models\Query;
use App\Models\QueryReviewItem;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Services\Ai\AiCancellation;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Compliance\ForbiddenTerms;
use App\Services\SeoTasks\SeoText;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sorgu otomatik pilotu (operator decision 2026-10-01): the library moves to "assigned and clustered" without the
 * operator's approval, in this order, again and again:
 *
 *  1. Triage: every unassigned library query no matching keyword placed goes to AI once (`queries.triage`, 200 per
 *     call, sector by sector): a service of its sector (assigned, `ai`, locked), a filter term (person name, brand or
 *     product brand, place, irrelevant, forbidden phrase → added to the filter basket, source ai; the library is
 *     brand-neutral) or none; new matching keywords are added.
 *     `ai_checked_at` marks the query so it is never sent again.
 *  2. When no query is left: Bekleyenler is imported (filter terms already applied; matching keywords assign), and
 *     the next round triages what the keywords did not place.
 *  3. At most once a day, whatever triage still has to do: services with new, never clustered queries are clustered
 *     one by one ("Hepsini kümele" queue: place into the existing clusters, or a full run for a service without
 *     clusters). A finished run approves its sound clusters itself (QueryClusterer::autoApprove).
 *  4. Hourly clean-up: a full rescan with the grown filter basket and keywords, then every open Silinecekler line is
 *     applied (filter deletions, keyword service changes) except conflicts and lines the operator kept; unlocked
 *     clusters left without a collected query are deleted. Operator decision 2026-10-01: when 50 new filter terms
 *     have come in since the last clean-up, it runs at once (fewer queries for triage); the next forced one waits for
 *     the next 50, the hourly one goes on.
 *
 * When the API route cannot pay for triage (Claude API credit used up, the day's ceiling or the month's budget reached;
 * 2026-10-14 the library stood still for hours this way) or its model waits for Claude, triage goes to Claude over
 * MCP instead: up to CLAUDE_BATCHES batches wait there at a time (TriageQueriesJob applies each answer), and
 * Bekleyenler is imported meanwhile, since importing needs no AI.
 */
final class QueryAutopilot
{
    public const string KEY = 'queries:autopilot';

    public const string PAUSED_KEY = 'queries:autopilot:paused';

    public const int BATCH = 200;

    /** Stops starting new AI calls before the job's timeout; the job continues in the next tick. */
    private const int TIME_BUDGET_SECONDS = 600;

    private const int CLUSTER_EVERY_HOURS = 20;

    /** Failed triage calls in a row before the pilot waits ERROR_PAUSE_HOURS (the same queries are not paid for again and again). */
    private const int MAX_ERRORS = 3;

    private const int ERROR_PAUSE_HOURS = 2;

    /** New filter terms since the last clean-up that start one at once. */
    public const int CLEAN_AFTER_TERMS = 50;

    /** Triage batches (of BATCH queries) waiting for Claude over MCP at a time; each tick tops the list up. */
    public const int CLAUDE_BATCHES = 25;

    /** The batches waiting for Claude: batch key => sector, query ids, queued at. */
    public const string CLAUDE_KEY = 'queries:autopilot:claude';

    /** A batch Claude has not answered for this long is asked again (lost task). */
    private const int CLAUDE_STALE_DAYS = 3;

    /** @var array<int, array{sector: ServiceCategory, services: Collection<int, ServiceCatalogItem>, forbidden: list<string>}|null> */
    private array $sectors = [];

    /** @var list<string>|null folded names and matching keywords of every active service (all sectors) */
    private ?array $guards = null;

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly ServiceKeywordService $keywords,
        private readonly PendingQueries $pending,
        private readonly QueryClusterQueue $clusters,
        private readonly QueryRescanner $rescanner,
        private readonly AiTaskQueue $tasks,
    ) {}

    /** @return array<string, mixed> */
    public static function state(): array
    {
        $state = Cache::get(self::KEY);

        return is_array($state) ? $state : [];
    }

    public static function paused(): bool
    {
        return (bool) Cache::get(self::PAUSED_KEY, false);
    }

    public static function setPaused(bool $paused): void
    {
        $paused ? Cache::forever(self::PAUSED_KEY, true) : Cache::forget(self::PAUSED_KEY);
    }

    /** Runs only after the first import ("AI ile planla") and while not paused. */
    public static function enabled(): bool
    {
        return (bool) config('moxdop-queries.autopilot', true) && ! self::paused() && QueryPipeline::importedAt() !== null;
    }

    /** @return Builder<Query> unassigned, visible, collected queries of a sector with services that AI has not seen */
    public static function queue(): Builder
    {
        return self::inServicedSectors(Query::query()->whereNull('service_id')->where('hidden', false)->where('is_suggested', false)
            ->whereNotNull('sector_id')->whereNull('ai_checked_at'));
    }

    /**
     * Only sectors that have a catalog service: a sector without services ("Diğer", 10,656 queries, panel audit 16) has
     * nothing to assign its queries to; they wait for the sector ("sektör bekliyor") and stay out of the queue and its counts.
     *
     * @template T of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function inServicedSectors($query)
    {
        return $query->whereIn('sector_id', DB::table('service_categories')->whereIn('code', DB::table('service_catalog_items')->whereNotNull('sector')->select('sector'))->select('id'));
    }

    /**
     * One tick: triage until the queue or the time budget ends, then Bekleyenler, then (daily) clustering.
     *
     * @return string more (call again) | imported | clustering | claude (batches wait for Claude) | idle | off | no_provider
     */
    public function tick(): string
    {
        if (! self::enabled()) {
            return 'off';
        }
        // Clustering never waits for an empty triage queue (a steady query flow would starve it): once a day the services
        // with new queries are clustered on the heavy queue while triage goes on.
        $clustering = $this->clusterIfDue();
        $this->guards = null; // services may have changed since the last tick
        $started = microtime(true);
        $skipSectors = [];
        $waitUntil = self::state()['error_wait_until'] ?? null;
        if (is_string($waitUntil) && CarbonImmutable::parse($waitUntil)->isFuture()) {
            $skipSectors = self::queue()->distinct()->pluck('sector_id')->map(fn ($id): int => (int) $id)->all(); // triage waits; the rest goes on
        }
        $viaClaude = $this->viaClaude();
        $noProvider = false;
        $termsAdded = false;
        if ($viaClaude) {
            $this->queueForClaude();
        }
        while (! $viaClaude && microtime(true) - $started < self::TIME_BUDGET_SECONDS) {
            $first = self::queue()->whereNotIn('sector_id', $skipSectors ?: [0])->orderBy('sector_id')->orderBy('id')->first(['id', 'sector_id']);
            if ($first === null) {
                break;
            }
            $sectorId = (int) $first->sector_id;
            $context = $this->sectorContext($sectorId);
            if ($context === null) {
                $skipSectors[] = $sectorId; // no active services yet: asked once the sector has some

                continue;
            }
            $rows = self::queue()->where('sector_id', $sectorId)->orderByDesc('impressions')->orderBy('id')->limit(self::BATCH)->get(['id', 'text', 'impressions']);
            $structured = $this->call($context, $rows);
            if ($structured === 'no_provider') {
                // No AI for triage now: Bekleyenler is still imported and clustering still starts.
                $this->record(['status' => 'no_provider']);
                $noProvider = true;

                break;
            }
            if (! is_array($structured)) {
                $errors = (int) (self::state()['errors_in_row'] ?? 0) + 1;
                $this->record(['status' => 'error', 'errors_in_row' => $errors]
                    + ($errors >= self::MAX_ERRORS ? ['error_wait_until' => now()->addHours(self::ERROR_PAUSE_HOURS)->toIso8601String(), 'errors_in_row' => 0] : []));

                return 'more';
            }
            $this->record(['errors_in_row' => 0]);
            $result = $this->apply($context, $rows, $structured);
            $termsAdded = $termsAdded || $result['filters'] > 0;
            $this->record(['status' => 'running'], $result);
            if ($result['filters'] > 0) {
                $this->cleanIfDue();
            }
        }
        if ($termsAdded) {
            $this->pending->prune();
        }
        if (! $viaClaude && ! $noProvider && self::queue()->whereNotIn('sector_id', $skipSectors ?: [0])->exists()) {
            return 'more';
        }

        $pendingIds = PendingQuery::query()->where('status', PendingQuery::PENDING)->orderBy('id')->limit(5000)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($pendingIds !== []) {
            $imported = $this->pending->import($pendingIds);
            $this->record(['status' => $viaClaude ? 'claude' : ($noProvider ? 'no_provider' : 'running')], ['imported' => $imported]);

            return 'imported';
        }

        if ($clustering || $this->clusterIfDue()) {
            return 'clustering';
        }
        if ($noProvider) {
            return 'no_provider';
        }
        if ($viaClaude && self::claudeBatches() !== []) {
            $this->record(['status' => 'claude']);

            return 'claude';
        }
        $this->record(['status' => 'idle']);

        return 'idle';
    }

    /**
     * Whether triage goes to Claude over MCP: its model waits there, or the API route cannot pay for it (credit used up,
     * the day's ceiling or the month's budget reached), so the library goes on being sorted instead of standing still.
     */
    public function viaClaude(): bool
    {
        return $this->tasks->delegated(QueryTriageAgent::OPERATION);
    }

    /**
     * The triage batches waiting for Claude (older than CLAUDE_STALE_DAYS dropped: asked again).
     *
     * @return array<string, array{sector: int, ids: list<int>, at: string}>
     */
    public static function claudeBatches(): array
    {
        $batches = Cache::get(self::CLAUDE_KEY);

        return array_filter(is_array($batches) ? $batches : [], fn ($batch): bool => is_array($batch) && is_array($batch['ids'] ?? null)
            && CarbonImmutable::parse((string) ($batch['at'] ?? '2000-01-01'))->addDays(self::CLAUDE_STALE_DAYS)->isFuture());
    }

    /** @param callable(array<string, array{sector: int, ids: list<int>, at: string}>): array<string, array{sector: int, ids: list<int>, at: string}> $change */
    private static function changeClaudeBatches(callable $change): void
    {
        Cache::lock(self::CLAUDE_KEY.':lock', 10)->block(10, fn () => Cache::forever(self::CLAUDE_KEY, $change(self::claudeBatches())));
    }

    /**
     * Tops the batches waiting for Claude up to CLAUDE_BATCHES: unchecked queries not already waiting, sector by sector,
     * most seen first; each batch is a TriageQueriesJob that waits for Claude's answer and applies it.
     */
    private function queueForClaude(): int
    {
        $open = self::claudeBatches();
        $new = [];
        $skip = [];
        while (count($open) + count($new) < self::CLAUDE_BATCHES) {
            $waiting = collect([...$open, ...$new])->flatMap(fn (array $batch): array => $batch['ids'])->all();
            $base = fn (): Builder => self::queue()->when($waiting !== [], fn (Builder $q) => $q->whereNotIn('id', $waiting));
            $first = $base()->whereNotIn('sector_id', $skip ?: [0])->orderBy('sector_id')->orderBy('id')->first(['id', 'sector_id']);
            if ($first === null) {
                break;
            }
            $sectorId = (int) $first->sector_id;
            if ($this->sectorContext($sectorId) === null) {
                $skip[] = $sectorId; // no active services yet: asked once the sector has some

                continue;
            }
            $ids = $base()->where('sector_id', $sectorId)->orderByDesc('impressions')->orderBy('id')->limit(self::BATCH)
                ->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $new[substr(sha1(implode(',', $ids)), 0, 16)] = ['sector' => $sectorId, 'ids' => $ids, 'at' => now()->toIso8601String()];
        }
        if ($new === []) {
            return 0;
        }
        self::changeClaudeBatches(fn (array $batches): array => $batches + $new);
        foreach ($new as $key => $batch) {
            TriageQueriesJob::dispatch($key, $batch['sector'], $batch['ids']);
        }
        $this->record(['status' => 'claude'], ['claude_batches' => count($new)]);

        return count($new);
    }

    /**
     * One batch waiting for Claude (TriageQueriesJob, inside its resumable run): asks Claude once, and applies the answer
     * when it comes, exactly like an API answer.
     *
     * @param  list<int>  $ids
     * @return string queued | applied | released (asked again by a later tick)
     */
    public function triageBatch(string $key, int $sectorId, array $ids): string
    {
        $context = $this->sectorContext($sectorId);
        $rows = Query::query()->whereIn('id', $ids)->whereNull('service_id')->whereNull('ai_checked_at')
            ->orderByDesc('impressions')->orderBy('id')->get(['id', 'text', 'impressions']);
        if ($context === null || $rows->isEmpty()) {
            self::changeClaudeBatches(fn (array $batches): array => array_diff_key($batches, [$key => true]));

            return 'released';
        }
        // The API route pays again (credit topped up, a new day): the batch is released and the next tick asks there.
        $answer = $this->tasks->delegated(QueryTriageAgent::OPERATION) ? $this->tasks->answer(new QueryTriageAgent, $this->pack($context, $rows), 'triage-'.$key) : null;
        if (($answer['status'] ?? null) === 'queued') {
            return 'queued';
        }
        self::changeClaudeBatches(fn (array $batches): array => array_diff_key($batches, [$key => true]));
        if (($answer['status'] ?? null) === 'error') {
            // Claude said it cannot do this batch: its queries count as seen (none), so the same batch is not sent forever.
            Query::query()->whereIn('id', $rows->pluck('id'))->update(['ai_checked_at' => now()]);

            return 'released';
        }
        if ($answer === null) {
            return 'released';
        }
        $result = $this->apply($context, $rows, $answer['data']);
        $this->record(['status' => 'claude'], $result);
        if ($result['filters'] > 0) {
            $this->pending->prune();
            $this->cleanIfDue();
        }

        return 'applied';
    }

    /**
     * Starts the "Hepsini kümele" queue at most every CLUSTER_EVERY_HOURS when some service has new queries. A running
     * queue that has not moved for QueryClusterQueue::STALE_AFTER_HOURS (lost job) is stopped and started again at
     * once; `$ignoreGate` (moxdop:queries:finish) skips the daily gate.
     */
    public function clusterIfDue(bool $ignoreGate = false): bool
    {
        $stale = QueryClusterQueue::stale();
        if ($stale) {
            $this->clusters->stop();
            Log::warning('queries.autopilot.cluster_queue_stale', ['state' => QueryClusterQueue::state()]);
        }
        $last = self::state()['clustered_at'] ?? null;
        if (QueryClusterQueue::running()
            || (! $stale && ! $ignoreGate && $last !== null && now()->diffInHours(CarbonImmutable::parse((string) $last), true) < self::CLUSTER_EVERY_HOURS)
            || $this->clusters->servicesToCluster() === []) {
            return false;
        }
        $count = $this->clusters->start();
        $this->record(['clustered_at' => now()->toIso8601String()], ['clustered_services' => $count]);

        return true;
    }

    /**
     * Hourly clean-up: full rescan, then the open pool lines are applied (filter deletions and keyword service changes);
     * conflicts and lines the operator kept stay for the operator.
     *
     * @return array{deleted: int, changed: int, clusters?: int}
     */
    public function clean(): array
    {
        if (! self::enabled()) {
            return ['deleted' => 0, 'changed' => 0];
        }
        $this->record(['clean_filter_id' => self::lastFilterId()]);
        $this->rescanner->scan(null);
        $ids = QueryReviewItem::query()->whereNull('kept_at')
            ->where(fn ($q) => $q->where('kind', QueryReviewItem::DELETE)
                ->orWhere(fn ($s) => $s->where('kind', QueryReviewItem::SERVICE)->where(fn ($r) => $r->whereNull('reason')->orWhere('reason', '!=', QueryReviewItem::REASON_CONFLICT))))
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $result = $this->rescanner->apply($ids);
        $result['clusters'] = QueryPipeline::dropEmptyClusters();
        $this->record(['cleaned_at' => now()->toIso8601String(), 'last_clean' => $result],
            ['deleted' => $result['deleted'], 'changed' => $result['changed'], 'dropped_clusters' => $result['clusters']]);

        return $result;
    }

    /** Filter terms added since the last clean-up (or since the last forced one was queued). */
    public static function newFilterTerms(): int
    {
        return FilterTerm::query()->where('id', '>', (int) (self::state()['clean_filter_id'] ?? 0))->count();
    }

    /** Queues the clean-up at once when 50 new filter terms came in; the mark moves so the next one waits for 50 more. */
    public function cleanIfDue(): bool
    {
        if (self::newFilterTerms() < self::CLEAN_AFTER_TERMS) {
            return false;
        }
        $this->record(['clean_filter_id' => self::lastFilterId(), 'clean_queued_at' => now()->toIso8601String()]);
        QueryAutopilotJob::dispatch(true);

        return true;
    }

    private static function lastFilterId(): int
    {
        return (int) FilterTerm::query()->max('id');
    }

    /**
     * @param  array{sector: ServiceCategory, services: Collection<int, ServiceCatalogItem>, forbidden: list<string>}  $context
     * @param  Collection<int, Query>  $rows
     * @param  array<string, mixed>  $structured
     * @return array{assigned: int, filters: int, keywords: int, none: int}
     */
    private function apply(array $context, Collection $rows, array $structured): array
    {
        $texts = $rows->pluck('text', 'id')->map(fn ($t): string => (string) $t)->all();
        $guards = $this->guardWords($context);
        $byService = [];
        $filters = 0;
        $seen = [];
        foreach ((array) ($structured['decisions'] ?? []) as $row) {
            $queryId = is_array($row) && is_int($row['query_id'] ?? null) ? $row['query_id'] : null;
            if ($queryId === null || ! isset($texts[$queryId]) || isset($seen[$queryId])) {
                continue;
            }
            $seen[$queryId] = true;
            $service = is_int($row['service_id'] ?? null) ? $context['services']->get($row['service_id']) : null;
            if ($service !== null) {
                $byService[(int) $service->id][] = $queryId;

                continue;
            }
            $term = $this->filterTerm((string) ($row['filter_term'] ?? ''), $texts[$queryId], $guards);
            if ($term !== null && in_array($row['filter_reason'] ?? null, QueryTriageAgent::FILTER_REASONS, true)
                && ! FilterTerm::query()->where('term', $term)->where(fn ($q) => $q->whereNull('sector_id')->orWhere('sector_id', $context['sector']->id))->exists()) {
                FilterTerm::query()->create(['sector_id' => (int) $context['sector']->id, 'term' => $term, 'source' => 'ai', 'created_by' => null]);
                $filters++;
            }
        }
        $assigned = 0;
        foreach ($byService as $serviceId => $ids) {
            $assigned += Query::query()->whereIn('id', $ids)->whereNull('service_id')
                ->update(['service_id' => $serviceId, 'assignment' => 'ai', 'locked' => true, 'updated_at' => now()]);
        }
        $added = 0;
        foreach ((array) ($structured['keywords'] ?? []) as $row) {
            $service = is_array($row) && is_int($row['service_id'] ?? null) ? $context['services']->get($row['service_id']) : null;
            $keyword = is_array($row) ? trim(preg_replace('/\s+/u', ' ', QueryNormalizer::lower((string) ($row['keyword'] ?? ''))) ?? '') : '';
            if ($service === null || mb_strlen(SeoText::fold($keyword)) < 3 || mb_strlen($keyword) > 100 || ServiceKeywordService::isGeneric($keyword)
                || QueryNormalizer::placeIn($keyword) !== null || ! collect($texts)->contains(fn (string $text): bool => SeoText::matchesPhrase($text, $keyword))) {
                continue;
            }
            try {
                $this->keywords->add($service, $keyword);
                $added++;
            } catch (ValidationException) {
                // already there / taken by another service of the sector
            }
        }
        Query::query()->whereIn('id', $rows->pluck('id'))->update(['ai_checked_at' => now()]);

        return ['assigned' => $assigned, 'filters' => $filters, 'keywords' => $added, 'none' => $rows->count() - $assigned];
    }

    /**
     * A filter term must come from the query and be short; it never touches a service name, a matching keyword, a
     * question or a generic word. Places and product brands ARE filter terms: the library is brand-neutral (a brand's
     * area is added to its target queries later), so "ankara", "straumann" leave it.
     *
     * @param  list<string>  $guards  folded service names and matching keywords of every sector's active services
     */
    private function filterTerm(string $term, string $text, array $guards): ?string
    {
        $term = trim(preg_replace('/\s+/u', ' ', QueryNormalizer::lower($term)) ?? '');
        $folded = SeoText::fold($term);
        if (mb_strlen($folded) < 3 || mb_strlen($term) > 60 || count(explode(' ', $folded)) > 4 || ! SeoText::matchesPhrase($text, $term)
            || QueryNormalizer::isQuestionTerm($term) || ServiceKeywordService::isGeneric($term)) {
            return null;
        }
        foreach ($guards as $guard) {
            if ($guard !== '' && (str_contains(' '.$folded.' ', ' '.$guard.' ') || str_contains(' '.$guard.' ', ' '.$folded.' '))) {
                return null;
            }
        }

        return $term;
    }

    /**
     * Folded names and matching keywords of the active services of EVERY sector: a term is filed under one sector but
     * the basket is read by all of them, so a word that is a service ("hurda" of Geri dönüşüm) is never filed as
     * irrelevant by another sector's triage.
     *
     * @param  array{sector: ServiceCategory, services: Collection<int, ServiceCatalogItem>, forbidden: list<string>}  $context
     * @return list<string>
     */
    private function guardWords(array $context): array
    {
        $this->guards ??= ServiceCatalogItem::query()->with(['primaryName', 'matchingKeywords'])->where('status', 'active')->get()
            ->flatMap(fn (ServiceCatalogItem $s): array => [SeoText::fold((string) $s->primaryName?->raw_label),
                ...$s->matchingKeywords->map(fn ($k): string => SeoText::fold((string) $k->label))->all()])
            ->filter()->unique()->values()->all();

        return collect($this->guards)->merge($context['services']->flatMap(fn (ServiceCatalogItem $s): array => [SeoText::fold((string) $s->primaryName?->raw_label),
            ...$s->matchingKeywords->map(fn ($k): string => SeoText::fold((string) $k->label))->all()]))
            ->filter()->unique()->values()->all();
    }

    /** @return array{sector: ServiceCategory, services: Collection<int, ServiceCatalogItem>, forbidden: list<string>}|null */
    private function sectorContext(int $sectorId): ?array
    {
        if (array_key_exists($sectorId, $this->sectors)) {
            return $this->sectors[$sectorId];
        }
        $sector = ServiceCategory::query()->find($sectorId);
        $services = $sector !== null ? QueryPlanner::sectorServices($sector)->keyBy('id') : collect();

        return $this->sectors[$sectorId] = $services->isEmpty() ? null
            : ['sector' => $sector, 'services' => $services, 'forbidden' => ForbiddenTerms::forSector($sectorId)->phrases()];
    }

    /**
     * @param  array{sector: ServiceCategory, services: Collection<int, ServiceCatalogItem>, forbidden: list<string>}  $context
     * @param  Collection<int, Query>  $rows
     * @return array<string, mixed>|string structured output, or 'no_provider' / 'error'
     */
    private function call(array $context, Collection $rows): array|string
    {
        AiCancellation::throwIfRequested();
        try {
            $route = $this->routes->resolve(QueryTriageAgent::OPERATION);
            if ($route->isEmpty()) {
                return 'no_provider';
            }
            $this->runtime->prepare(array_keys($route->providerModels));

            return (new QueryTriageAgent)->prompt(
                "DATA_JSON\n".json_encode($this->pack($context, $rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels,
                timeout: 180,
            )->toArray();
        } catch (Throwable $exception) {
            Log::warning('Query triage AI call failed.', ['error' => $exception->getMessage()]);

            return 'error';
        }
    }

    /**
     * The DATA_JSON pack of one triage call.
     *
     * @param  array{sector: ServiceCategory, services: Collection<int, ServiceCatalogItem>, forbidden: list<string>}  $context
     * @param  Collection<int, Query>  $rows
     * @return array<string, mixed>
     */
    private function pack(array $context, Collection $rows): array
    {
        return [
            'sector' => ['id' => (int) $context['sector']->id, 'name' => (string) $context['sector']->name],
            'services' => $context['services']->map(fn (ServiceCatalogItem $s): array => [
                'id' => (int) $s->id, 'name' => (string) $s->primaryName->raw_label, 'keywords' => $s->matchingKeywords->pluck('label')->values()->all(),
            ])->values()->all(),
            'forbidden' => $context['forbidden'],
            'queries' => $rows->map(fn (Query $q): array => ['id' => (int) $q->id, 'text' => (string) $q->text, 'impressions' => (int) $q->impressions])->values()->all(),
        ];
    }

    /**
     * Keeps the screen line: last tick, totals since the start and of the last 24 hours.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, int>  $add
     */
    private function record(array $values, array $add = []): void
    {
        $state = self::state();
        $totals = (array) ($state['totals'] ?? []);
        foreach ($add as $key => $count) {
            $totals[$key] = (int) ($totals[$key] ?? 0) + $count;
        }
        Cache::forever(self::KEY, array_merge($state, $values, ['totals' => $totals, 'tick_at' => now()->toIso8601String()]));
    }
}
