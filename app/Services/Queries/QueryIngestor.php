<?php

namespace App\Services\Queries;

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Free query pull into the core store — from EVERY discovered account without exception, bound to a brand or not
 * (operator decision): Search Console queries, Google Ads search terms and Business Profile search keywords, read from
 * the already collected provider facts (no provider call here).
 *
 * Per source account: the window metrics of each raw query are aggregated, normalized (QueryNormalizer) and archived
 * as query_variants with their strip flags; core-kind variants are linked to their core query (created when new, filed
 * under the account's sector) and the core metrics are re-aggregated. Incremental: an account is re-read only when its
 * facts (count / last id / last update / day) or its context (brand, competitors, sector, exclusion rules) changed.
 * Variants no longer in the window keep their row with zero metrics.
 */
final class QueryIngestor
{
    public const array QUERY_SOURCES = ['search_console', 'google_ads', 'google_business_profile'];

    /** source => [fact table, text column, date column] */
    private const array TABLES = [
        'search_console' => ['gsc_query_daily', 'query', 'reporting_date'],
        'google_ads' => ['google_ads_search_term_daily', 'search_term', 'reporting_date'],
        'google_business_profile' => ['gbp_search_keywords_monthly', 'search_keyword', 'month_start'],
    ];

    private const array METRICS = ['impressions', 'clicks', 'cost', 'conversions', 'position_weighted', 'position_impressions',
        'recent_impressions', 'previous_impressions', 'recent_clicks', 'previous_clicks'];

    public function __construct(
        private readonly QueryNormalizer $normalizer,
        private readonly QueryContextFactory $contexts,
        private readonly CoreQueryStore $store,
    ) {}

    /**
     * Every source account (bound or not), or only one resource.
     *
     * @return array{sources: int, ingested: int, variants: int, failed: int, new_queries: int}
     */
    public function run(?int $resourceId = null, bool $force = false): array
    {
        return $this->ingestSources($this->sources($resourceId), $force);
    }

    /**
     * Keys of the source accounts (and account-less websites) the pipeline reads — the unit the queued pipeline
     * splits into bounded chunks (RunQueryPipelineJob → IngestQuerySourcesJob).
     *
     * @return list<string>
     */
    public function sourceKeys(?int $resourceId = null): array
    {
        return array_map(static fn (array $source): string => $source['key'], $this->sources($resourceId));
    }

    /**
     * Ingest only these source keys ("google_ads:r12", "search_console:a24"); unknown or vanished keys are skipped.
     *
     * @param  list<string>  $keys
     * @return array{sources: int, ingested: int, variants: int, failed: int, new_queries: int}
     */
    public function runKeys(array $keys, bool $force = false): array
    {
        $resourceIds = [];
        $assetKeys = [];
        foreach ($keys as $key) {
            if (preg_match('/^([a-z_]+):(r|a)(\d+)$/', $key, $m) !== 1 || ! isset(self::TABLES[$m[1]])) {
                continue;
            }
            $m[2] === 'r' ? $resourceIds[] = (int) $m[3] : $assetKeys[(int) $m[3]][] = $m[1];
        }
        $sources = [];
        foreach (CoreExternalResource::query()->whereIn('resource_type', self::QUERY_SOURCES)->whereIn('id', $resourceIds ?: [0])->orderBy('id')->get() as $resource) {
            $sources[] = ['key' => $resource->resource_type.':r'.$resource->id, 'source' => (string) $resource->resource_type, 'resource' => $resource, 'asset' => null];
        }
        foreach (DigitalAsset::query()->whereIn('id', array_keys($assetKeys) ?: [0])->orderBy('id')->get() as $asset) {
            foreach ($assetKeys[$asset->id] as $source) {
                $sources[] = ['key' => $source.':a'.$asset->id, 'source' => $source, 'resource' => null, 'asset' => $asset];
            }
        }

        return $this->ingestSources($sources, $force);
    }

    /**
     * @param  list<array{key: string, source: string, resource: ?CoreExternalResource, asset: ?DigitalAsset}>  $sources
     * @return array{sources: int, ingested: int, variants: int, failed: int, new_queries: int}
     */
    private function ingestSources(array $sources, bool $force): array
    {
        $stats = ['sources' => 0, 'ingested' => 0, 'variants' => 0, 'failed' => 0, 'new_queries' => 0];
        $this->contexts->reset();
        $createdBefore = $this->store->createdCount();
        foreach ($sources as $source) {
            $stats['sources']++;
            try {
                $count = $this->ingest($source, $force);
                if ($count !== null) {
                    $stats['ingested']++;
                    $stats['variants'] += $count;
                }
            } catch (Throwable $exception) {
                report($exception);
                $stats['failed']++;
                DB::table('query_ingest_states')->updateOrInsert(['source_key' => $source['key']], [
                    'source' => $source['source'], 'external_resource_id' => $source['resource']?->id, 'digital_asset_id' => $source['asset']?->id,
                    'error' => mb_substr($exception->getMessage(), 0, 1000), 'updated_at' => now(), 'created_at' => now(),
                ]);
            }
        }
        $stats['new_queries'] = $this->store->createdCount() - $createdBefore;

        return $stats;
    }

    /**
     * Source keys of one brand: its bound accounts and its websites' account-less facts (moxdop:pilot:refresh).
     *
     * @return list<string>
     */
    public function sourceKeysForBrand(Brand $brand): array
    {
        [$resourceIds, $assetIds] = $this->brandScope($brand);

        return array_map(static fn (array $source): string => $source['key'], $this->sources(null, $resourceIds, $assetIds));
    }

    /** @return array{0: list<int>, 1: list<int>} bound resource ids, asset ids */
    private function brandScope(Brand $brand): array
    {
        $assetIds = DigitalAsset::query()->where('brand_id', $brand->id)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $resourceIds = CoreAssetBinding::query()->whereIn('digital_asset_id', $assetIds ?: [0])->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->pluck('external_resource_id')->map(fn ($id): int => (int) $id)->all();

        return [$resourceIds, $assetIds];
    }

    /** The accounts and websites of one brand (the brand query hub reads from the same store). */
    public function runForBrand(Brand $brand, bool $force = false): void
    {
        $this->contexts->reset();
        [$resourceIds, $assetIds] = $this->brandScope($brand);
        foreach ($this->sources(null, $resourceIds, $assetIds) as $source) {
            try {
                $this->ingest($source, $force);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * @param  list<int>|null  $resourceIds
     * @param  list<int>|null  $assetIds
     * @return list<array{key: string, source: string, resource: ?CoreExternalResource, asset: ?DigitalAsset}>
     */
    private function sources(?int $onlyResource, ?array $resourceIds = null, ?array $assetIds = null): array
    {
        $out = [];
        $resources = CoreExternalResource::query()->whereIn('resource_type', self::QUERY_SOURCES)
            ->when($onlyResource !== null, fn ($q) => $q->whereKey($onlyResource))
            ->when($resourceIds !== null, fn ($q) => $q->whereIn('id', $resourceIds ?: [0]))
            ->orderBy('id')->get();
        foreach ($resources as $resource) {
            $out[] = ['key' => $resource->resource_type.':r'.$resource->id, 'source' => (string) $resource->resource_type, 'resource' => $resource, 'asset' => null];
        }
        if ($onlyResource !== null) {
            return $out;
        }
        // Facts collected per website without a known account (older direct collections).
        foreach (array_keys(self::TABLES) as $source) {
            [$table, , $date] = self::TABLES[$source];
            if (! Schema::hasTable($table)) {
                continue;
            }
            $ids = DB::table($table)->where(fn ($q) => $this->withoutAccount($q))->whereNotNull('digital_asset_id')
                ->when($assetIds !== null, fn ($q) => $q->whereIn('digital_asset_id', $assetIds ?: [0]))
                ->where($date, '>=', $this->from($source))->distinct()->pluck('digital_asset_id');
            foreach (DigitalAsset::query()->whereIn('id', $ids)->get() as $asset) {
                $out[] = ['key' => $source.':a'.$asset->id, 'source' => $source, 'resource' => null, 'asset' => $asset];
            }
        }

        return $out;
    }

    /**
     * @param  array{key: string, source: string, resource: ?CoreExternalResource, asset: ?DigitalAsset}  $source
     * @return int|null variants written, null when nothing changed
     */
    private function ingest(array $source, bool $force): ?int
    {
        [$table, $column, $date] = self::TABLES[$source['source']];
        if (! Schema::hasTable($table)) {
            return null;
        }
        $context = $source['resource'] !== null ? $this->contexts->forResource($source['resource']) : $this->contexts->forAsset($source['asset']);
        $contextHash = $context->hash();
        $from = $this->from($source['source']);
        $scope = fn ($query) => $source['resource'] !== null
            ? $query->where('external_resource_id', $source['resource']->id)
            : $query->where(fn ($q) => $this->withoutAccount($q))->where('digital_asset_id', $source['asset']->id);
        $shape = DB::table($table)->where($scope)->where($date, '>=', $from)
            ->selectRaw('count(*) as n, max(id) as last_id, max(updated_at) as last_update')->first();
        $factsHash = hash('sha256', json_encode([(int) $shape->n, (int) $shape->last_id, (string) $shape->last_update, $from], JSON_THROW_ON_ERROR));
        $state = DB::table('query_ingest_states')->where('source_key', $source['key'])->first();
        if (! $force && $state !== null && $state->facts_fingerprint === $factsHash && $state->context_hash === $contextHash) {
            return null;
        }

        $rows = $this->aggregate($source['source'], $table, $column, $date, $scope, $from);
        $normalized = [];
        $cores = [];
        foreach ($rows as $row) {
            $text = trim((string) $row->text);
            if ($text === '' || mb_strlen($text) > 500) {
                continue;
            }
            $result = $this->normalizer->normalize($text, $context);
            $normalized[] = [$row, $text, $result];
            if ($result->isCore()) {
                $cores[QueryNormalizer::coreKey($result->core)] ??= $result->core;
            }
        }
        $resolved = $this->store->resolve($cores, $context->sector);
        $previous = DB::table('query_variants')->where('source_key', $source['key'])->whereNotNull('search_query_library_item_id')
            ->distinct()->pluck('search_query_library_item_id')->map('intval')->all();

        // First-seen dates only ever move back.
        $firstSeen = DB::table('query_variants')->where('source_key', $source['key'])->whereNotNull('first_seen_on')->pluck('first_seen_on', 'text_hash')->all();
        $now = now()->startOfSecond();
        $variants = [];
        $touched = $previous;
        foreach ($normalized as [$row, $text, $result]) {
            $core = $result->isCore() ? ($resolved[QueryNormalizer::coreKey($result->core)] ?? null) : null;
            $kind = $core !== null && $core['trashed'] ? QueryNormalization::SUPPRESSED : $result->kind;
            if ($core !== null) {
                $touched[] = $core['id'];
            }
            $hash = hash('sha256', $text);
            if (isset($variants[$hash])) {
                // Same text after trimming: one variant, metrics added.
                foreach (['impressions', 'clicks', 'cost', 'conversions', 'position_weighted', 'position_impressions', 'recent_impressions', 'previous_impressions', 'recent_clicks', 'previous_clicks'] as $metric) {
                    $variants[$hash][$metric] += (float) ($row->{$metric === 'position_weighted' ? 'pos_weighted' : ($metric === 'position_impressions' ? 'pos_impressions' : $metric)} ?? 0);
                }

                continue;
            }
            $variants[$hash] = [
                'source' => $source['source'], 'external_resource_id' => $source['resource']?->id, 'digital_asset_id' => $source['resource'] === null ? $source['asset']->id : null,
                'source_key' => $source['key'], 'text_hash' => hash('sha256', $text), 'raw_text' => $text,
                'search_query_library_item_id' => $core['id'] ?? null, 'kind' => $kind,
                'had_location' => $result->hadLocation, 'had_own_brand' => $result->hadOwnBrand,
                'had_competitor_brand' => $result->hadCompetitorBrand, 'had_product_brand' => $result->hadProductBrand,
                'removed' => $result->removed !== [] ? json_encode($result->removed, JSON_UNESCAPED_UNICODE) : null,
                'impressions' => (int) $row->impressions, 'clicks' => (int) ($row->clicks ?? 0), 'cost' => round((float) ($row->cost ?? 0), 6),
                'conversions' => round((float) ($row->conversions ?? 0), 4), 'position_weighted' => round((float) ($row->pos_weighted ?? 0), 4),
                'position_impressions' => (int) ($row->pos_impressions ?? 0),
                'recent_impressions' => (int) ($row->recent_impressions ?? 0), 'previous_impressions' => (int) ($row->previous_impressions ?? 0),
                'recent_clicks' => (int) ($row->recent_clicks ?? 0), 'previous_clicks' => (int) ($row->previous_clicks ?? 0),
                'first_seen_on' => $this->earliest($firstSeen[hash('sha256', $text)] ?? null, $row->first_date),
                'last_seen_on' => $row->last_date !== null ? substr((string) $row->last_date, 0, 10) : null,
                'context_hash' => $contextHash, 'ingested_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        $variants = array_values($variants);
        DB::transaction(function () use ($variants, $source, $now): void {
            $update = array_values(array_diff(array_keys($variants[0] ?? ['x' => 1]), ['source_key', 'text_hash', 'created_at']));
            foreach (array_chunk($variants, 300) as $chunk) {
                DB::table('query_variants')->upsert($chunk, ['source_key', 'text_hash'], $update);
            }
            // Outside this window: the row stays (gold), its window metrics go to zero.
            DB::table('query_variants')->where('source_key', $source['key'])
                ->where(fn ($q) => $q->whereNull('ingested_at')->orWhere('ingested_at', '<', $now))
                ->update(array_fill_keys(self::METRICS, 0) + ['updated_at' => $now]);
        });
        $this->store->refreshMetrics(array_values(array_unique($touched)));
        DB::table('query_ingest_states')->updateOrInsert(['source_key' => $source['key']], [
            'source' => $source['source'], 'external_resource_id' => $source['resource']?->id, 'digital_asset_id' => $source['resource'] === null ? $source['asset']->id : null,
            'facts_fingerprint' => $factsHash, 'context_hash' => $contextHash, 'variants' => count($variants), 'error' => null,
            'ingested_at' => $now, 'updated_at' => $now, 'created_at' => $state?->created_at ?? $now,
        ]);

        return count($variants);
    }

    /** @return Collection<int, object> */
    private function aggregate(string $source, string $table, string $column, string $date, \Closure $scope, string $from)
    {
        $limit = max(100, (int) config('moxdop-queries.max_queries_per_source', 5000));
        if ($source === 'google_business_profile') {
            return DB::table($table)->where($scope)->where($date, '>=', $from)->groupBy($column)
                ->selectRaw("{$column} as text, sum(coalesce(impressions, threshold, 0)) as impressions, min({$date}) as first_date, max({$date}) as last_date")
                ->orderByDesc('impressions')->limit($limit)->get();
        }
        $recent = now()->subDays(28)->toDateString();
        $previous = now()->subDays(56)->toDateString();
        $trend = 'sum(case when reporting_date >= ? then impressions else 0 end) as recent_impressions, '
            .'sum(case when reporting_date >= ? and reporting_date < ? then impressions else 0 end) as previous_impressions, '
            .'sum(case when reporting_date >= ? then clicks else 0 end) as recent_clicks, '
            .'sum(case when reporting_date >= ? and reporting_date < ? then clicks else 0 end) as previous_clicks, '
            .'min(reporting_date) as first_date, max(reporting_date) as last_date';
        $bindings = [$recent, $previous, $recent, $recent, $previous, $recent];
        if ($source === 'search_console') {
            $position = DB::getDriverName() === 'pgsql'
                ? "(nullif(metadata->>'provider_average_position', ''))::numeric"
                : "json_extract(metadata, '$.provider_average_position')";

            return DB::table($table)->where($scope)->where($date, '>=', $from)->groupBy($column)
                ->selectRaw("{$column} as text, sum(impressions) as impressions, sum(clicks) as clicks, {$trend}"
                    .", sum(case when {$position} is not null then {$position} * impressions else 0 end) as pos_weighted"
                    .", sum(case when {$position} is not null then impressions else 0 end) as pos_impressions", $bindings)
                ->orderByDesc('impressions')->limit($limit)->get();
        }

        return DB::table($table)->where($scope)->where($date, '>=', $from)->groupBy($column)
            ->selectRaw("{$column} as text, sum(impressions) as impressions, sum(clicks) as clicks, sum(cost_amount) as cost, sum(conversions) as conversions, {$trend}", $bindings)
            ->orderByDesc('impressions')->limit($limit)->get();
    }

    /** Facts whose account is unknown (no id, or an id that is not a discovered account). */
    private function withoutAccount($query)
    {
        return $query->whereNull('external_resource_id')
            ->orWhereNotExists(fn ($s) => $s->selectRaw('1')->from('core_external_resources as r')->whereColumn('r.id', 'external_resource_id'));
    }

    private function earliest(mixed $stored, mixed $seen): ?string
    {
        $dates = array_filter([$stored !== null ? substr((string) $stored, 0, 10) : null, $seen !== null ? substr((string) $seen, 0, 10) : null]);

        return $dates === [] ? null : min($dates);
    }

    private function from(string $source): string
    {
        if ($source === 'google_business_profile') {
            return now()->startOfMonth()->subMonths(max(1, (int) config('moxdop-queries.gbp_months', 3)))->toDateString();
        }

        return now()->subDays(max(7, (int) config('moxdop-queries.window_days', 90)))->toDateString();
    }
}
