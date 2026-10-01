<?php

namespace App\Services\Queries;

use App\Ai\Agents\QueryTriageAgent;
use App\Models\FilterTerm;
use App\Models\PendingQuery;
use App\Models\Query;
use App\Models\QueryReviewItem;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Services\Ai\AiCancellation;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Compliance\ForbiddenTerms;
use App\Services\SeoTasks\SeoText;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
 *  3. When both are empty, at most once a day: services with new, never clustered queries are clustered one by one
 *     ("Hepsini kümele" queue: place into the existing clusters, or a full run for a service without clusters). New
 *     clusters wait for the operator's approval before they reach any brand.
 *  4. Nightly clean-up (01:00): a full rescan with the grown filter basket and keywords, then every open Silinecekler
 *     line is applied (filter deletions, keyword service changes) except conflicts and lines the operator kept.
 */
final class QueryAutopilot
{
    public const string KEY = 'queries:autopilot';

    public const string PAUSED_KEY = 'queries:autopilot:paused';

    public const int BATCH = 200;

    /** Stops starting new AI calls before the job's timeout; the job continues in the next tick. */
    private const int TIME_BUDGET_SECONDS = 600;

    private const int CLUSTER_EVERY_HOURS = 20;

    /** @var array<int, array{sector: ServiceCategory, services: Collection<int, ServiceCatalogItem>, forbidden: list<string>}|null> */
    private array $sectors = [];

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly ServiceKeywordService $keywords,
        private readonly PendingQueries $pending,
        private readonly QueryClusterQueue $clusters,
        private readonly QueryRescanner $rescanner,
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

    /** @return Builder<Query> unassigned, visible, collected queries with a sector that AI has not seen */
    public static function queue(): Builder
    {
        return Query::query()->whereNull('service_id')->where('hidden', false)->where('is_suggested', false)
            ->whereNotNull('sector_id')->whereNull('ai_checked_at');
    }

    /**
     * One tick: triage until the queue or the time budget ends, then Bekleyenler, then (daily) clustering.
     *
     * @return string more (call again) | imported | clustering | idle | off | no_provider
     */
    public function tick(): string
    {
        if (! self::enabled()) {
            return 'off';
        }
        $started = microtime(true);
        $skipSectors = [];
        $termsAdded = false;
        while (microtime(true) - $started < self::TIME_BUDGET_SECONDS) {
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
                $this->record(['status' => 'no_provider']);

                return 'no_provider';
            }
            if (! is_array($structured)) {
                $this->record(['status' => 'error']);

                return 'more';
            }
            $result = $this->apply($context, $rows, $structured);
            $termsAdded = $termsAdded || $result['filters'] > 0;
            $this->record(['status' => 'running'], $result);
        }
        if ($termsAdded) {
            $this->pending->prune();
        }
        if (self::queue()->whereNotIn('sector_id', $skipSectors ?: [0])->exists()) {
            return 'more';
        }

        $pendingIds = PendingQuery::query()->where('status', PendingQuery::PENDING)->orderBy('id')->limit(5000)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($pendingIds !== []) {
            $imported = $this->pending->import($pendingIds);
            $this->record(['status' => 'running'], ['imported' => $imported]);

            return 'imported';
        }

        $last = self::state()['clustered_at'] ?? null;
        if ((QueryClusterQueue::state()['status'] ?? null) !== 'running' && ($last === null || now()->diffInHours(CarbonImmutable::parse((string) $last), true) >= self::CLUSTER_EVERY_HOURS)
            && $this->clusters->servicesToCluster() !== []) {
            $count = $this->clusters->start();
            $this->record(['status' => 'idle', 'clustered_at' => now()->toIso8601String()], ['clustered_services' => $count]);

            return 'clustering';
        }
        $this->record(['status' => 'idle']);

        return 'idle';
    }

    /**
     * 01:00 clean-up: full rescan, then the open pool lines are applied (filter deletions and keyword service changes);
     * conflicts and lines the operator kept stay for the operator.
     *
     * @return array{deleted: int, changed: int}
     */
    public function nightlyClean(): array
    {
        if (! self::enabled()) {
            return ['deleted' => 0, 'changed' => 0];
        }
        $this->rescanner->scan(null);
        $ids = QueryReviewItem::query()->whereNull('kept_at')
            ->where(fn ($q) => $q->where('kind', QueryReviewItem::DELETE)
                ->orWhere(fn ($s) => $s->where('kind', QueryReviewItem::SERVICE)->where(fn ($r) => $r->whereNull('reason')->orWhere('reason', '!=', QueryReviewItem::REASON_CONFLICT))))
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $result = $this->rescanner->apply($ids);
        $this->record(['cleaned_at' => now()->toIso8601String(), 'last_clean' => $result], ['deleted' => $result['deleted'], 'changed' => $result['changed']]);

        return $result;
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
     * @param  list<string>  $guards  folded service names and matching keywords of the sector
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
     * @param  array{sector: ServiceCategory, services: Collection<int, ServiceCatalogItem>, forbidden: list<string>}  $context
     * @return list<string>
     */
    private function guardWords(array $context): array
    {
        return $context['services']->flatMap(fn (ServiceCatalogItem $s): array => [SeoText::fold((string) $s->primaryName?->raw_label),
            ...$s->matchingKeywords->map(fn ($k): string => SeoText::fold((string) $k->label))->all()])
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
            $data = [
                'sector' => ['id' => (int) $context['sector']->id, 'name' => (string) $context['sector']->name],
                'services' => $context['services']->map(fn (ServiceCatalogItem $s): array => [
                    'id' => (int) $s->id, 'name' => (string) $s->primaryName->raw_label, 'keywords' => $s->matchingKeywords->pluck('label')->values()->all(),
                ])->values()->all(),
                'forbidden' => $context['forbidden'],
                'queries' => $rows->map(fn (Query $q): array => ['id' => (int) $q->id, 'text' => (string) $q->text, 'impressions' => (int) $q->impressions])->values()->all(),
            ];

            return (new QueryTriageAgent)->prompt(
                "DATA_JSON\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels,
                timeout: 180,
            )->toArray();
        } catch (Throwable $exception) {
            Log::warning('Query triage AI call failed.', ['error' => $exception->getMessage()]);

            return 'error';
        }
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
