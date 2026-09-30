<?php

namespace App\Services\Queries;

use App\Ai\Agents\QueryRulesAgent;
use App\Jobs\Queries\RescanQueriesJob;
use App\Models\FilterTerm;
use App\Models\Query;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "AI ile kural üret" / "Filtreye ekle › AI ile düzenle": selected queries → ONE AI call → proposed negative filter
 * terms (a query containing one is deleted) + matching keywords per service, each checked against the selected
 * queries (must occur), the given sectors / services and the existing rules. The proposal waits (per operator) until
 * approved; approval saves the ticked items and starts a rescan whose result the operator approves (nothing is
 * deleted or reassigned before that).
 */
final class QueryRuleProposer
{
    public const int MAX_QUERIES = 200;

    private const int MAX_ITEMS = 300;

    private const int SAMPLE = 300;

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly ServiceKeywordService $keywords,
    ) {}

    public static function cacheKey(int $userId): string
    {
        return 'queries:rules:'.$userId;
    }

    /** @return array<string, mixed>|null */
    public static function current(int $userId): ?array
    {
        $value = Cache::get(self::cacheKey($userId));

        return is_array($value) ? $value : null;
    }

    /**
     * Library queries (outside $excludeIds) a filter term would delete: count + the 3 with most impressions.
     *
     * @param  list<int>  $excludeIds
     * @return array{count: int, examples: list<string>}
     */
    public static function catches(string $term, array $excludeIds): array
    {
        $first = (string) (explode(' ', trim(QueryNormalizer::lower($term)))[0] ?? '');
        $needle = str_replace(['%', '_', '\\'], '', mb_substr($first, 0, 4));
        if (mb_strlen($needle) < 2) {
            return ['count' => 0, 'examples' => []];
        }
        $texts = Query::query()->where('text', 'like', '%'.$needle.'%')->whereNotIn('id', $excludeIds ?: [0])
            ->orderByDesc('impressions')->orderBy('id')->limit(5000)->pluck('text')
            ->filter(fn ($text): bool => QueryNormalizer::containsTerm((string) $text, $term))->values();

        return ['count' => $texts->count(), 'examples' => $texts->take(3)->map(fn ($t): string => (string) $t)->all()];
    }

    public static function markRunning(int $userId): void
    {
        Cache::put(self::cacheKey($userId), ['status' => 'running'], now()->addDay());
    }

    public static function discard(int $userId): void
    {
        Cache::forget(self::cacheKey($userId));
    }

    /**
     * @param  list<int>  $queryIds
     * @return array{status: string, terms: list<array{term: string, sector_id: ?int, sector: ?string, reason: string}>, keywords: list<array{service_id: int, service: string, keyword: string, reason: string}>}
     */
    public function propose(array $queryIds): array
    {
        $empty = ['terms' => [], 'keywords' => []];
        $queries = Query::query()->whereIn('id', array_slice($queryIds, 0, self::MAX_QUERIES))->with('service.primaryName')->get();
        if ($queries->isEmpty()) {
            return ['status' => 'no_queries'] + $empty;
        }
        $sectorIds = $queries->pluck('sector_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $sectors = ServiceCategory::query()->whereIn('id', $sectorIds)->get(['id', 'code', 'name']);
        $services = ServiceCatalogItem::query()->with(['primaryName', 'matchingKeywords'])
            ->whereIn('sector', $sectors->pluck('code'))->where('status', 'active')->limit(300)->get()
            ->filter(fn (ServiceCatalogItem $item): bool => $item->primaryName !== null)->keyBy('id');
        $sectorByCode = $sectors->pluck('id', 'code');

        try {
            $route = $this->routes->resolve(QueryRulesAgent::OPERATION);
            if ($route->isEmpty()) {
                return ['status' => 'no_provider'] + $empty;
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $structured = (new QueryRulesAgent)->prompt(
                "DATA_JSON\n".json_encode([
                    'queries' => $queries->map(fn (Query $q): array => ['id' => (int) $q->id, 'text' => (string) $q->text, 'sector_id' => $q->sector_id, 'service' => $q->service?->primaryName?->raw_label])->values()->all(),
                    'library_sample' => Query::query()->whereNotIn('id', $queries->pluck('id'))->where('hidden', false)->where('is_suggested', false)
                        ->orderByDesc('impressions')->orderBy('id')->limit(self::SAMPLE)->pluck('text')->all(),
                    'sectors' => $sectors->map(fn (ServiceCategory $s): array => ['id' => (int) $s->id, 'name' => (string) $s->name])->values()->all(),
                    'services' => $services->map(fn (ServiceCatalogItem $item): array => [
                        'id' => (int) $item->id, 'sector_id' => $sectorByCode[$item->sector] ?? null, 'name' => (string) $item->primaryName->raw_label,
                        'keywords' => $item->matchingKeywords->pluck('label')->take(30)->values()->all(),
                    ])->values()->all(),
                    'filter_terms' => FilterTerm::query()->orderBy('id')->limit(500)->get(['term', 'sector_id'])->map(fn (FilterTerm $t): array => ['term' => $t->term, 'sector_id' => $t->sector_id])->all(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels,
                timeout: 180,
            )->toArray();
        } catch (Throwable $exception) {
            Log::warning('Query rule proposal failed.', ['error' => $exception->getMessage()]);

            return ['status' => 'error'] + $empty;
        }

        $texts = $queries->pluck('text')->map(fn ($t): string => (string) $t)->all();
        $sectorNames = $sectors->pluck('name', 'id')->all();

        return [
            'status' => 'ready',
            'terms' => $this->validTerms((array) ($structured['filter_terms'] ?? []), $texts, $sectorNames),
            'keywords' => $this->validKeywords((array) ($structured['keywords'] ?? []), $texts, $services->all()),
        ];
    }

    /**
     * Saves the ticked items (indexes into the proposal), then reprocesses all queries (only when something was saved).
     *
     * @param  array<string, mixed>  $proposal
     * @param  list<int>  $termIndexes
     * @param  list<int>  $keywordIndexes
     * @return array{terms: int, keywords: int}
     */
    public function approve(array $proposal, array $termIndexes, array $keywordIndexes, User $actor): array
    {
        $saved = ['terms' => 0, 'keywords' => 0];
        foreach ($termIndexes as $index) {
            $row = $proposal['terms'][$index] ?? null;
            if (! is_array($row)) {
                continue;
            }
            $term = FilterTerm::query()->firstOrCreate(
                ['sector_id' => $row['sector_id'], 'term' => $row['term']],
                ['source' => 'ai', 'created_by' => $actor->id],
            );
            $saved['terms'] += $term->wasRecentlyCreated ? 1 : 0;
        }
        foreach ($keywordIndexes as $index) {
            $row = $proposal['keywords'][$index] ?? null;
            $service = is_array($row) ? ServiceCatalogItem::query()->find($row['service_id']) : null;
            if ($service === null) {
                continue;
            }
            try {
                $this->keywords->add($service, (string) $row['keyword']);
                $saved['keywords']++;
            } catch (ValidationException) {
                // taken meanwhile (same sector) — skipped
            }
        }
        if ($saved['terms'] + $saved['keywords'] > 0) {
            RescanQueriesJob::dispatch((int) $actor->id);
        }

        return $saved;
    }

    /**
     * @param  array<mixed>  $rows
     * @param  list<string>  $texts
     * @param  array<int, string>  $sectorNames
     * @return list<array{term: string, sector_id: ?int, sector: ?string, reason: string}>
     */
    private function validTerms(array $rows, array $texts, array $sectorNames): array
    {
        $valid = [];
        $seen = [];
        foreach (array_slice($rows, 0, self::MAX_ITEMS) as $row) {
            $term = is_array($row) && is_string($row['term'] ?? null) ? trim(QueryNormalizer::lower($row['term'])) : '';
            $sectorId = is_array($row) && is_int($row['sector_id'] ?? null) ? $row['sector_id'] : null;
            if (mb_strlen($term) < 2 || mb_strlen($term) > 100 || ($sectorId !== null && ! isset($sectorNames[$sectorId]))) {
                continue;
            }
            $key = SeoText::fold($term);
            if (isset($seen[$key]) || ! $this->occurs($texts, fn (string $text): bool => QueryNormalizer::containsTerm($text, $term))) {
                continue;
            }
            if (FilterTerm::query()->where('term', $term)->exists()) {
                continue;
            }
            $seen[$key] = true;
            $valid[] = ['term' => $term, 'sector_id' => $sectorId, 'sector' => $sectorId !== null ? $sectorNames[$sectorId] : null, 'reason' => mb_substr(trim((string) ($row['reason'] ?? '')), 0, 200)];
        }

        return $valid;
    }

    /**
     * @param  array<mixed>  $rows
     * @param  list<string>  $texts
     * @param  array<int, ServiceCatalogItem>  $services
     * @return list<array{service_id: int, service: string, keyword: string, reason: string}>
     */
    private function validKeywords(array $rows, array $texts, array $services): array
    {
        $valid = [];
        $seen = [];
        foreach (array_slice($rows, 0, self::MAX_ITEMS) as $row) {
            $service = is_array($row) && is_int($row['service_id'] ?? null) ? ($services[$row['service_id']] ?? null) : null;
            $keyword = is_array($row) && is_string($row['keyword'] ?? null) ? trim(QueryNormalizer::lower($row['keyword'])) : '';
            $key = SeoText::fold($keyword);
            if ($service === null || mb_strlen($key) < 3 || mb_strlen($keyword) > 100 || ServiceKeywordService::isGeneric($keyword)) {
                continue;
            }
            $sectorKey = $service->sector.'|'.$key;
            if (isset($seen[$sectorKey]) || $service->matchingKeywords->contains('normalized_key', $key)
                || $this->keywords->conflicts($service, [$key]) !== []
                || ! $this->occurs($texts, fn (string $text): bool => SeoText::matchesPhrase($text, $keyword))) {
                continue;
            }
            $seen[$sectorKey] = true;
            $valid[] = ['service_id' => (int) $service->id, 'service' => (string) $service->primaryName->raw_label, 'keyword' => $keyword, 'reason' => mb_substr(trim((string) ($row['reason'] ?? '')), 0, 200)];
        }

        return $valid;
    }

    /** @param list<string> $texts */
    private function occurs(array $texts, callable $test): bool
    {
        foreach ($texts as $text) {
            if ($test($text)) {
                return true;
            }
        }

        return false;
    }
}
