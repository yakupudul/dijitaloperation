<?php

namespace App\Services\Queries;

use App\Ai\Agents\QueryAssignServicesAgent;
use App\Jobs\Queries\RescanQueriesJob;
use App\Models\Query;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\SeoTasks\SeoText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sorgular "AI ile hizmet öner" (hizmet ataması kuyruğu): the unassigned library queries (visible, collected, with a
 * sector) go to AI BATCH per call, sector by sector with that sector's services and matching keywords, until every
 * batch is done (a queued job continues itself before its timeout). Each answer is validated (unknown / foreign query
 * ids, services of another sector and duplicates dropped) and collected per operator as a checklist; nothing changes
 * before "Onayla". Approval assigns the ticked queries (assignment `ai`, locked so a rescan keeps them) and adds the
 * ticked matching keywords (then a rescan review, like every keyword change).
 */
final class QueryServiceAssigner
{
    public const int BATCH = 200;

    /** Stops starting new calls before the job's timeout; the job then continues from the cursor. */
    private const int TIME_BUDGET_SECONDS = 1200;

    /** @var array<int, array{sector: ServiceCategory, services: Collection<int, ServiceCatalogItem>}|null> */
    private array $sectors = [];

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly ServiceKeywordService $keywords,
    ) {}

    public static function cacheKey(int $userId): string
    {
        return 'queries:assign:'.$userId;
    }

    /** @return array<string, mixed>|null */
    public static function current(int $userId): ?array
    {
        $value = Cache::get(self::cacheKey($userId));

        return is_array($value) ? $value : null;
    }

    public static function markRunning(int $userId, ?int $sectorId): void
    {
        Cache::put(self::cacheKey($userId), [
            'status' => 'running', 'sector_id' => $sectorId, 'total' => self::queue($sectorId)->count(), 'done' => 0, 'failed' => 0,
            'items' => [], 'keywords' => [],
        ], now()->addDay());
    }

    public static function discard(int $userId): void
    {
        Cache::forget(self::cacheKey($userId));
    }

    /** @return Builder<Query> the queue: unassigned, visible, collected (not AI-suggested) queries with a sector */
    public static function queue(?int $sectorId): Builder
    {
        return Query::query()->whereNull('service_id')->where('hidden', false)->where('is_suggested', false)->whereNotNull('sector_id')
            ->when($sectorId !== null, fn (Builder $q) => $q->where('sector_id', $sectorId));
    }

    /**
     * Runs batches from the cursor until the queue is done (returns null) or the time budget is used (returns the
     * cursor to continue from). The proposal in the cache grows after every batch.
     *
     * @param  array{sector: int, after: int}  $cursor
     * @return array{sector: int, after: int}|null
     */
    public function run(int $userId, ?int $sectorId, array $cursor): ?array
    {
        $state = self::current($userId) ?? [];
        if (($state['status'] ?? null) !== 'running') {
            return null;
        }
        $started = microtime(true);
        $calls = (int) ($state['calls'] ?? 0);
        $taken = array_flip(array_map(fn (array $row): string => $row['sector_id'].'|'.SeoText::fold($row['keyword']), (array) $state['keywords']));
        while (true) {
            if (microtime(true) - $started > self::TIME_BUDGET_SECONDS) {
                return $cursor;
            }
            $next = self::queue($sectorId)
                ->where(fn (Builder $q) => $q->where('sector_id', '>', $cursor['sector'])
                    ->orWhere(fn (Builder $q) => $q->where('sector_id', $cursor['sector'])->where('id', '>', $cursor['after'])))
                ->orderBy('sector_id')->orderBy('id')->first(['id', 'sector_id']);
            if ($next === null) {
                break;
            }
            $sector = (int) $next->sector_id;
            $rows = self::queue(null)->where('sector_id', $sector)->where('id', '>', $sector === $cursor['sector'] ? $cursor['after'] : 0)
                ->orderBy('id')->limit(self::BATCH)->get(['id', 'text', 'impressions']);
            $cursor = ['sector' => $sector, 'after' => (int) $rows->last()->id];
            $context = $this->sectorContext($sector);
            $state['done'] += $rows->count();
            if ($context === null) {
                Cache::put(self::cacheKey($userId), $state, now()->addDay());

                continue;
            }
            $structured = $this->call($context, $rows);
            $calls++;
            if ($structured === 'no_provider') {
                Cache::put(self::cacheKey($userId), ['status' => 'no_provider', 'items' => [], 'keywords' => []], now()->addDay());

                return null;
            }
            if (! is_array($structured)) {
                $state['failed']++;
            } else {
                $state['items'] = [...$state['items'], ...$this->validAssignments($structured, $context, $rows)];
                $state['keywords'] = [...$state['keywords'], ...$this->validKeywords($structured, $context, $rows, $taken)];
            }
            $state['calls'] = $calls;
            Cache::put(self::cacheKey($userId), $state, now()->addDay());
        }
        $state['status'] = match (true) {
            $state['done'] === 0 => 'nothing',
            $calls === 0 => 'no_services',
            $state['failed'] === $calls => 'error',
            default => 'ready',
        };
        Cache::put(self::cacheKey($userId), $state, now()->addDay());

        return null;
    }

    /**
     * Applies the proposal except the unticked lines: queries still unassigned get their service (locked); ticked
     * keywords are added (a keyword taken meanwhile is skipped) and then a rescan review starts.
     *
     * @param  array<string, mixed>  $proposal
     * @param  list<int>  $skipItems  unticked assignment indexes
     * @param  list<int>  $keywordIndexes  ticked keyword indexes
     * @return array{assigned: int, keywords: int}
     */
    public function approve(array $proposal, array $skipItems, array $keywordIndexes, User $actor): array
    {
        $skip = array_flip($skipItems);
        $byService = [];
        foreach ((array) ($proposal['items'] ?? []) as $index => $item) {
            if (! isset($skip[$index]) && is_array($item)) {
                $byService[(int) $item['service_id']][] = (int) $item['query_id'];
            }
        }
        $assigned = 0;
        $known = ServiceCatalogItem::query()->whereIn('id', array_keys($byService) ?: [0])->where('status', 'active')->pluck('id')->flip();
        foreach ($byService as $serviceId => $ids) {
            if (! $known->has($serviceId)) {
                continue;
            }
            foreach (array_chunk($ids, QueryPipeline::CHUNK) as $chunk) {
                $assigned += Query::query()->whereIn('id', $chunk)->whereNull('service_id')
                    ->update(['service_id' => $serviceId, 'assignment' => 'ai', 'locked' => true, 'updated_at' => now()]);
            }
        }
        $added = 0;
        foreach ($keywordIndexes as $index) {
            $row = $proposal['keywords'][$index] ?? null;
            $service = is_array($row) ? ServiceCatalogItem::query()->find((int) $row['service_id']) : null;
            if ($service === null) {
                continue;
            }
            try {
                $this->keywords->add($service, (string) $row['keyword']);
                $added++;
            } catch (ValidationException) {
                // taken meanwhile (same sector) — skipped
            }
        }
        if ($added > 0) {
            RescanQueriesJob::dispatch((int) $actor->id);
        }

        return ['assigned' => $assigned, 'keywords' => $added];
    }

    /** @return array{sector: ServiceCategory, services: Collection<int, ServiceCatalogItem>}|null null = no active services */
    private function sectorContext(int $sectorId): ?array
    {
        if (array_key_exists($sectorId, $this->sectors)) {
            return $this->sectors[$sectorId];
        }
        $sector = ServiceCategory::query()->find($sectorId);
        $services = $sector !== null ? QueryPlanner::sectorServices($sector)->keyBy('id') : collect();

        return $this->sectors[$sectorId] = $services->isEmpty() ? null : ['sector' => $sector, 'services' => $services];
    }

    /**
     * @param  array{sector: ServiceCategory, services: Collection<int, ServiceCatalogItem>}  $context
     * @param  Collection<int, Query>  $rows
     * @return array<string, mixed>|string structured output, or 'no_provider' / 'error'
     */
    private function call(array $context, Collection $rows): array|string
    {
        try {
            $route = $this->routes->resolve(QueryAssignServicesAgent::OPERATION);
            if ($route->isEmpty()) {
                return 'no_provider';
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $data = [
                'sector' => ['id' => (int) $context['sector']->id, 'name' => (string) $context['sector']->name],
                'services' => $context['services']->map(fn (ServiceCatalogItem $s): array => [
                    'id' => (int) $s->id, 'name' => (string) $s->primaryName->raw_label, 'keywords' => $s->matchingKeywords->pluck('label')->values()->all(),
                ])->values()->all(),
                'queries' => $rows->map(fn (Query $q): array => ['id' => (int) $q->id, 'text' => (string) $q->text, 'impressions' => (int) $q->impressions])->values()->all(),
            ];

            return (new QueryAssignServicesAgent)->prompt(
                "DATA_JSON\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels,
                timeout: 180,
            )->toArray();
        } catch (Throwable $exception) {
            Log::warning('Query service assignment AI call failed.', ['error' => $exception->getMessage()]);

            return 'error';
        }
    }

    /**
     * @param  array<string, mixed>  $structured
     * @param  array{sector: ServiceCategory, services: Collection<int, ServiceCatalogItem>}  $context
     * @param  Collection<int, Query>  $rows
     * @return list<array{query_id: int, text: string, service_id: int, service: string, reason: string}>
     */
    private function validAssignments(array $structured, array $context, Collection $rows): array
    {
        $texts = $rows->pluck('text', 'id')->all();
        $seen = [];
        $items = [];
        foreach ((array) ($structured['assignments'] ?? []) as $row) {
            $queryId = is_array($row) && is_int($row['query_id'] ?? null) ? $row['query_id'] : null;
            $service = is_array($row) && is_int($row['service_id'] ?? null) ? $context['services']->get($row['service_id']) : null;
            if ($queryId === null || $service === null || ! isset($texts[$queryId]) || isset($seen[$queryId])) {
                continue;
            }
            $seen[$queryId] = true;
            $items[] = [
                'query_id' => $queryId, 'text' => (string) $texts[$queryId], 'service_id' => (int) $service->id,
                'service' => (string) $service->primaryName->raw_label, 'reason' => mb_substr(trim((string) ($row['reason'] ?? '')), 0, 200),
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $structured
     * @param  array{sector: ServiceCategory, services: Collection<int, ServiceCatalogItem>}  $context
     * @param  Collection<int, Query>  $rows
     * @param  array<string, int>  $taken  service|folded keyword already proposed
     * @return list<array{sector_id: int, service_id: int, service: string, keyword: string, reason: string}>
     */
    private function validKeywords(array $structured, array $context, Collection $rows, array &$taken): array
    {
        $texts = $rows->pluck('text')->map(fn ($t): string => (string) $t)->all();
        $valid = [];
        foreach ((array) ($structured['keywords'] ?? []) as $row) {
            $service = is_array($row) && is_int($row['service_id'] ?? null) ? $context['services']->get($row['service_id']) : null;
            $keyword = is_array($row) && is_string($row['keyword'] ?? null) ? trim(preg_replace('/\s+/u', ' ', QueryNormalizer::lower($row['keyword'])) ?? '') : '';
            $key = SeoText::fold($keyword);
            if ($service === null || mb_strlen($key) < 3 || mb_strlen($keyword) > 100 || ServiceKeywordService::isGeneric($keyword)) {
                continue;
            }
            $sectorKey = $context['sector']->id.'|'.$key;
            if (isset($taken[$sectorKey]) || $service->matchingKeywords->contains('normalized_key', $key)
                || $this->keywords->conflicts($service, [$key]) !== []
                || ! collect($texts)->contains(fn (string $text): bool => SeoText::matchesPhrase($text, $keyword))) {
                continue;
            }
            $taken[$sectorKey] = 1;
            $valid[] = ['sector_id' => (int) $context['sector']->id, 'service_id' => (int) $service->id, 'service' => (string) $service->primaryName->raw_label, 'keyword' => $keyword, 'reason' => mb_substr(trim((string) ($row['reason'] ?? '')), 0, 200)];
        }

        return $valid;
    }
}
