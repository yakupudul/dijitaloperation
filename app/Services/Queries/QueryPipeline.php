<?php

namespace App\Services\Queries;

use App\Enums\OfferingStatus;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\Cluster;
use App\Models\DigitalAsset;
use App\Services\Site\SiteScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sorgular pipeline (idempotent, chunked by 1000, never all rows in memory):
 *  1. `query_sources` → normalized text per row (sector of the account's brand, else of its brand candidate) → ONE
 *     `queries` row per normalized text; rows that normalize to empty are unlinked.
 *  2. `queries` totals across accounts / months (search impressions / clicks, Ads cost / conversions, Business
 *     Profile impressions, sources, first / last month), dominant sector, service from the sector's matching keywords.
 *     Locked (manual) queries and queries of locked clusters keep their service; queries left without a source are
 *     deleted unless AI-suggested.
 *  3. `brand_queries`: last 28 days of Search Console / Google Ads per brand × query (bound accounts), then the brand's
 *     target queries (per served area for commercial / local clusters), language and mapped URL (brandTargets).
 */
final class QueryPipeline
{
    public const int CHUNK = 1000;

    private const int MEMO_LIMIT = 50000;

    public function __construct(
        private readonly QueryNormalizer $normalizer,
        private readonly QueryServiceMatcher $matcher,
    ) {}

    /** @return array{sources: int, queries: int, deleted: int, assigned: int, brand_queries: int} */
    public function run(): array
    {
        $this->normalizer->forget();
        $this->matcher->forget();
        $context = $this->resourceContext();
        $stats = ['sources' => 0, 'queries' => 0, 'deleted' => 0, 'assigned' => 0, 'brand_queries' => 0];
        $stats['sources'] = $this->linkSources($context);
        [$stats['queries'], $stats['deleted'], $stats['assigned']] = $this->refreshQueries($context);
        $stats['brand_queries'] = $this->brandQueries($context);

        return $stats;
    }

    /**
     * Account → brand (bound) and sector (brand's, else the brand candidate's proposal).
     *
     * @return array<int, array{brand: ?int, sector: ?int}>
     */
    private function resourceContext(): array
    {
        $context = [];
        DB::table('core_asset_bindings as b')
            ->join('digital_assets as a', 'a.id', '=', 'b.digital_asset_id')
            ->join('brands as br', 'br.id', '=', 'a.brand_id')
            ->where('b.status', 'active')->whereNull('a.deleted_at')->whereNull('br.deleted_at')
            ->whereIn('b.external_resource_id', DB::table('query_sources')->select('external_resource_id')->distinct())
            ->orderBy('b.id')
            ->get(['b.external_resource_id', 'br.id as brand_id', 'br.sector_id'])
            ->each(function (object $row) use (&$context): void {
                $context[(int) $row->external_resource_id] ??= ['brand' => (int) $row->brand_id, 'sector' => $row->sector_id !== null ? (int) $row->sector_id : null];
            });
        DB::table('brand_candidate_resources as r')
            ->join('brand_candidates as c', 'c.id', '=', 'r.brand_candidate_id')
            ->whereNotNull('r.external_resource_id')->whereNotNull('c.sector_id')->where('c.status', '!=', 'dismissed')
            ->orderBy('c.id')
            ->get(['r.external_resource_id', 'c.sector_id'])
            ->each(function (object $row) use (&$context): void {
                $id = (int) $row->external_resource_id;
                if (! isset($context[$id])) {
                    $context[$id] = ['brand' => null, 'sector' => (int) $row->sector_id];
                } elseif ($context[$id]['sector'] === null) {
                    $context[$id]['sector'] = (int) $row->sector_id;
                }
            });

        return $context;
    }

    /** @param array<int, array{brand: ?int, sector: ?int}> $context */
    private function linkSources(array $context): int
    {
        $memo = [];
        $count = 0;
        DB::table('query_sources')->select(['id', 'external_resource_id', 'raw_query', 'query_id'])
            ->chunkById(self::CHUNK, function ($rows) use ($context, &$memo, &$count): void {
                $targets = [];
                $texts = [];
                foreach ($rows as $row) {
                    $sector = $context[(int) $row->external_resource_id]['sector'] ?? null;
                    $key = ($sector ?? 0).'|'.$row->raw_query;
                    if (! isset($memo[$key])) {
                        if (count($memo) >= self::MEMO_LIMIT) {
                            $memo = [];
                        }
                        $memo[$key] = $this->normalizer->normalize((string) $row->raw_query, $sector);
                    }
                    $text = $memo[$key];
                    $hash = $text === '' ? null : QueryNormalizer::hash($text);
                    if ($hash !== null) {
                        $texts[$hash] = $text;
                    }
                    $targets[(int) $row->id] = [$hash, $row->query_id !== null ? (int) $row->query_id : null];
                }
                $ids = [];
                if ($texts !== []) {
                    $now = now();
                    DB::table('queries')->insertOrIgnore(array_map(fn (string $hash): array => [
                        'text' => $texts[$hash], 'text_hash' => $hash, 'created_at' => $now, 'updated_at' => $now,
                    ], array_keys($texts)));
                    $ids = DB::table('queries')->whereIn('text_hash', array_keys($texts))->pluck('id', 'text_hash')->all();
                }
                $groups = [];
                foreach ($targets as $sourceId => [$hash, $current]) {
                    $queryId = $hash !== null && isset($ids[$hash]) ? (int) $ids[$hash] : null;
                    if ($queryId !== $current) {
                        $groups[$queryId ?? 0][] = $sourceId;
                    }
                }
                foreach ($groups as $queryId => $sourceIds) {
                    DB::table('query_sources')->whereIn('id', $sourceIds)->update(['query_id' => $queryId === 0 ? null : $queryId]);
                }
                $count += count($rows);
            });

        return $count;
    }

    /**
     * @param  array<int, array{brand: ?int, sector: ?int}>  $context
     * @return array{0: int, 1: int, 2: int}
     */
    private function refreshQueries(array $context): array
    {
        $kept = 0;
        $deleted = 0;
        $assigned = 0;
        DB::table('queries')
            ->select(['id', 'text', 'sector_id', 'service_id', 'assignment', 'locked', 'is_suggested', 'impressions', 'clicks', 'ads_cost', 'ads_conversions', 'gbp_impressions', 'sources', 'first_seen_on', 'last_seen_on'])
            ->chunkById(self::CHUNK, function ($rows) use ($context, &$kept, &$deleted, &$assigned): void {
                $ids = $rows->pluck('id')->all();
                $totals = [];
                DB::table('query_sources')->whereIn('query_id', $ids)->groupBy('query_id', 'source')
                    ->selectRaw('query_id, source, sum(impressions) as impressions, sum(clicks) as clicks, sum(cost) as cost, sum(conversions) as conversions, min(month) as first_month, max(month) as last_month')
                    ->get()->each(function (object $row) use (&$totals): void {
                        $totals[(int) $row->query_id][(string) $row->source] = $row;
                    });
                $weights = [];
                DB::table('query_sources')->whereIn('query_id', $ids)->groupBy('query_id', 'external_resource_id')
                    ->selectRaw('query_id, external_resource_id, sum(impressions) + sum(clicks) as weight')
                    ->get()->each(function (object $row) use (&$weights, $context): void {
                        $sector = $context[(int) $row->external_resource_id]['sector'] ?? null;
                        if ($sector !== null) {
                            $weights[(int) $row->query_id][$sector] = ($weights[(int) $row->query_id][$sector] ?? 0) + (int) $row->weight;
                        }
                    });
                $inLockedCluster = array_flip(DB::table('cluster_queries as cq')->join('clusters as c', 'c.id', '=', 'cq.cluster_id')
                    ->whereIn('cq.query_id', $ids)->where('c.locked', true)->pluck('cq.query_id')->map(fn ($id): int => (int) $id)->all());

                $delete = [];
                $real = [];
                foreach ($rows as $row) {
                    $id = (int) $row->id;
                    $sources = $totals[$id] ?? [];
                    if ($sources === []) {
                        if (! (bool) $row->is_suggested) {
                            $delete[] = $id;
                        }

                        continue;
                    }
                    if ((bool) $row->is_suggested) {
                        $real[] = $id;
                    }
                    $values = $this->totals($sources);
                    $values['is_suggested'] = false;
                    $values['sector_id'] = $this->dominantSector($weights[$id] ?? []);
                    if (! (bool) $row->locked && ! isset($inLockedCluster[$id])) {
                        $service = $this->matcher->match((string) $row->text, $values['sector_id']);
                        $values['service_id'] = $service;
                        $values['assignment'] = $service === null ? 'none' : 'rule';
                    }
                    if ($this->changed($row, $values)) {
                        DB::table('queries')->where('id', $id)->update($values + ['updated_at' => now()]);
                    }
                    if ((array_key_exists('service_id', $values) ? $values['service_id'] : $row->service_id) !== null) {
                        $assigned++;
                    }
                    $kept++;
                }
                if ($real !== []) {
                    // A suggested query that got real data is no longer "önerilen" in its cluster either.
                    DB::table('cluster_queries')->whereIn('query_id', $real)->where('is_suggested', true)->update(['is_suggested' => false, 'updated_at' => now()]);
                }
                foreach (array_chunk($delete, self::CHUNK) as $chunk) {
                    DB::table('queries')->whereIn('id', $chunk)->delete();
                }
                $deleted += count($delete);
                $this->dropForeignMemberships(array_values(array_diff($ids, $delete)));
            });

        return [$kept, $deleted, $assigned];
    }

    /**
     * @param  array<string, object>  $sources
     * @return array<string, mixed>
     */
    private function totals(array $sources): array
    {
        $search = array_intersect_key($sources, ['gsc' => true, 'google_ads' => true]);
        $ads = $sources['google_ads'] ?? null;
        $months = array_map(fn (object $row): array => [(string) $row->first_month, (string) $row->last_month], $sources);

        return [
            'impressions' => (int) array_sum(array_map(fn (object $row): int => (int) $row->impressions, $search)),
            'clicks' => (int) array_sum(array_map(fn (object $row): int => (int) $row->clicks, $search)),
            'ads_cost' => $ads !== null ? round((float) $ads->cost, 4) : null,
            'ads_conversions' => $ads !== null ? round((float) $ads->conversions, 2) : null,
            'gbp_impressions' => isset($sources['gbp']) ? (int) $sources['gbp']->impressions : 0,
            'sources' => implode(',', array_values(array_intersect(['gsc', 'google_ads', 'gbp'], array_keys($sources)))),
            'first_seen_on' => CarbonImmutable::parse(min(array_column($months, 0)))->toDateString(),
            'last_seen_on' => CarbonImmutable::parse(max(array_column($months, 1)))->toDateString(),
        ];
    }

    /** @param array<int, int> $weights sector id => impressions + clicks */
    private function dominantSector(array $weights): ?int
    {
        if ($weights === []) {
            return null;
        }
        ksort($weights);
        arsort($weights);

        return (int) array_key_first($weights);
    }

    /** @param array<string, mixed> $values */
    private function changed(object $row, array $values): bool
    {
        foreach ($values as $column => $value) {
            $current = $row->{$column};
            if (in_array($column, ['ads_cost', 'ads_conversions'], true)) {
                if (($current === null) !== ($value === null) || ($value !== null && abs((float) $current - (float) $value) > 0.00001)) {
                    return true;
                }

                continue;
            }
            if (in_array($column, ['first_seen_on', 'last_seen_on'], true)) {
                $current = $current !== null ? substr((string) $current, 0, 10) : null;
            }
            if (is_bool($value)) {
                $current = (bool) $current;
            } elseif (is_int($value)) {
                $current = $current !== null ? (int) $current : null;
            }
            if ($current !== $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * A query whose service changed leaves the unlocked cluster of its old service.
     *
     * @param  list<int>  $ids
     */
    private function dropForeignMemberships(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $stale = DB::table('cluster_queries as cq')
            ->join('clusters as c', 'c.id', '=', 'cq.cluster_id')
            ->join('queries as q', 'q.id', '=', 'cq.query_id')
            ->whereIn('cq.query_id', $ids)->where('c.locked', false)
            ->where(fn ($q) => $q->whereNull('q.service_id')->orWhereColumn('q.service_id', '!=', 'c.service_id'))
            ->pluck('cq.id')->all();
        if ($stale !== []) {
            DB::table('cluster_queries')->whereIn('id', $stale)->delete();
        }
    }

    /**
     * Brand layer: performance rows (bound accounts: Search Console + Google Ads totals of the last 28 days per
     * brand × query, no target area), then the target rows, language and URL of every brand (brandTargets).
     *
     * @param  array<int, array{brand: ?int, sector: ?int}>  $context
     */
    private function brandQueries(array $context): int
    {
        $byBrand = [];
        foreach ($context as $resourceId => $row) {
            if ($row['brand'] !== null) {
                $byBrand[$row['brand']][] = $resourceId;
            }
        }
        $types = DB::table('core_external_resources')->whereIn('id', array_keys($context))->pluck('resource_type', 'id')->all();
        $written = 0;
        foreach ($byBrand as $brandId => $resourceIds) {
            $metrics = [];
            foreach ($resourceIds as $resourceId) {
                match ($types[$resourceId] ?? null) {
                    'search_console' => $this->addDaily($metrics, $resourceId, 'gsc_query_page_daily', 'query', true),
                    'google_ads' => $this->addDaily($metrics, $resourceId, 'google_ads_search_term_daily', 'search_term', false),
                    default => null,
                };
            }
            $written += $this->writeBrandQueries((int) $brandId, $metrics);
        }
        // Brands without a bound account keep no performance numbers.
        DB::table('brand_queries')->whereNull('target_area_id')->whereNotIn('brand_id', array_keys($byBrand) ?: [0])
            ->where(fn ($q) => $q->where('impressions_28d', '>', 0)->orWhere('clicks_28d', '>', 0))
            ->update(['impressions_28d' => 0, 'clicks_28d' => 0, 'position_28d' => null, 'updated_at' => now()]);

        $brands = DB::table('brand_offerings')->where('status', OfferingStatus::Active->value)->whereNotNull('service_catalog_item_id')
            ->distinct()->pluck('brand_id')
            ->merge(DB::table('brand_queries')->distinct()->pluck('brand_id'))
            ->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        foreach ($brands as $brandId) {
            $this->brandTargets($brandId);
        }

        return $written;
    }

    /**
     * Target rows of one brand (cheap; also run after cluster / brand edits and site mapping): for every approved
     * cluster of the brand's active services (main services first), not excluded for the brand, its main query — one
     * row per served area for commercial / local intent ("ankara implant merkezi"), one row without area otherwise.
     * Every row of the brand gets the brand's language and the URL mapped to its cluster (brand_cluster_pages).
     * Rows without area that are neither a target nor have numbers are removed.
     *
     * @return int target rows
     */
    public function brandTargets(int $brandId): int
    {
        $brand = Brand::query()->find($brandId);
        $targets = [];
        $urls = [];
        $language = null;
        if ($brand !== null && $brand->sector_id !== null) {
            $services = SiteScope::offerings($brand)->pluck('service_catalog_item_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
            $order = array_flip($services);
            $excluded = BrandClusterPage::query()->where('brand_id', $brand->id)->where('excluded', true)->pluck('cluster_id')->all();
            $clusters = Cluster::query()->where('approved', true)->where('sector_id', $brand->sector_id)->whereIn('service_id', $services)
                ->whereNotNull('main_query_id')->whereNotIn('id', $excluded)->orderBy('id')->get(['id', 'service_id', 'intent', 'main_query_id'])
                ->sortBy(fn (Cluster $c): array => [$order[(int) $c->service_id] ?? PHP_INT_MAX, (int) $c->id]);
            $areas = SiteScope::areas($brand)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            foreach ($clusters as $cluster) {
                $local = in_array($cluster->intent, ['commercial', 'local'], true) && $areas !== [];
                foreach ($local ? $areas : [null] as $area) {
                    $targets[(int) $cluster->main_query_id.'|'.($area ?? '')] = [(int) $cluster->main_query_id, $area];
                }
            }
            $language = self::brandLanguage($brand);
            $urls = self::clusterUrls((int) $brand->id, $language);
        }

        $existing = [];
        DB::table('brand_queries')->where('brand_id', $brandId)->orderBy('id')
            ->get(['id', 'query_id', 'target_area_id', 'impressions_28d', 'clicks_28d'])
            ->each(function (object $row) use (&$existing): void {
                $existing[(int) $row->query_id.'|'.($row->target_area_id ?? '')] = $row;
            });
        $delete = [];
        foreach ($existing as $key => $row) {
            if (! isset($targets[$key]) && ($row->target_area_id !== null || ((int) $row->impressions_28d === 0 && (int) $row->clicks_28d === 0))) {
                $delete[] = (int) $row->id;
                unset($existing[$key]);
            }
        }
        foreach (array_chunk($delete, self::CHUNK) as $chunk) {
            DB::table('brand_queries')->whereIn('id', $chunk)->delete();
        }
        $valid = $targets === [] ? [] : array_flip(DB::table('queries')->whereIn('id', array_values(array_unique(array_column($targets, 0))))
            ->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $now = now();
        $insert = [];
        foreach ($targets as $key => [$queryId, $area]) {
            if (! isset($existing[$key]) && isset($valid[$queryId])) {
                $insert[] = ['brand_id' => $brandId, 'query_id' => $queryId, 'target_area_id' => $area, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach (array_chunk($insert, 500) as $chunk) {
            DB::table('brand_queries')->insert($chunk);
        }

        // Language + URL of every row of the brand (URL = the page mapped to the query's cluster).
        $clusterOf = [];
        $updates = [];
        DB::table('brand_queries')->where('brand_id', $brandId)->select(['id', 'query_id', 'language', 'url'])
            ->chunkById(self::CHUNK, function ($rows) use (&$clusterOf, &$updates, $urls, $language): void {
                $missing = array_values(array_diff($rows->pluck('query_id')->map(fn ($id): int => (int) $id)->unique()->all(), array_keys($clusterOf)));
                foreach ($missing as $queryId) {
                    $clusterOf[$queryId] = null;
                }
                if ($missing !== []) {
                    DB::table('cluster_queries')->whereIn('query_id', $missing)->get(['query_id', 'cluster_id'])
                        ->each(function (object $link) use (&$clusterOf): void {
                            $clusterOf[(int) $link->query_id] = (int) $link->cluster_id;
                        });
                }
                foreach ($rows as $row) {
                    $cluster = $clusterOf[(int) $row->query_id] ?? null;
                    $url = $cluster !== null ? ($urls[$cluster] ?? null) : null;
                    if ($row->language !== $language || $row->url !== $url) {
                        $updates[(string) json_encode([$language, $url])][] = (int) $row->id;
                    }
                }
            });
        foreach ($updates as $values => $ids) {
            [$lang, $url] = json_decode($values, true);
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                DB::table('brand_queries')->whereIn('id', $chunk)->update(['language' => $lang, 'url' => $url, 'updated_at' => $now]);
            }
        }

        return count($targets);
    }

    /** Brand's target language: the brand setting (first language), else the primary page language of its website. */
    public static function brandLanguage(Brand $brand): ?string
    {
        $set = array_values(array_filter(array_map(fn ($l): string => mb_strtolower(trim((string) $l)), (array) ($brand->languages ?? []))));
        if ($set !== []) {
            return mb_substr($set[0], 0, 8);
        }
        $site = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->orderBy('id')->first();

        return $site !== null ? SiteScope::primaryLanguage($site) : null;
    }

    /**
     * Mapped page URL per cluster of the brand (brand_cluster_pages, not excluded), the brand's language first.
     *
     * @return array<int, string>
     */
    private static function clusterUrls(int $brandId, ?string $language): array
    {
        $urls = [];
        DB::table('brand_cluster_pages as b')->join('pages as p', 'p.id', '=', 'b.page_id')
            ->where('b.brand_id', $brandId)->where('b.excluded', false)
            ->orderBy('b.id')->get(['b.cluster_id', 'b.language', 'p.url'])
            ->sortBy(fn (object $row): int => $row->language === $language ? 0 : 1)
            ->each(function (object $row) use (&$urls): void {
                $urls[(int) $row->cluster_id] ??= (string) $row->url;
            });

        return $urls;
    }

    /** @param array<int, array{clicks: int, impressions: int, weighted: float, weight: int}> $metrics */
    private function addDaily(array &$metrics, int $resourceId, string $table, string $column, bool $position): void
    {
        $last = DB::table($table)->where('external_resource_id', $resourceId)
            ->when($position, fn ($q) => $q->where('search_type', 'web'))->max('reporting_date');
        if ($last === null) {
            return;
        }
        $end = CarbonImmutable::parse($last);
        $start = $end->subDays(27);
        $links = [];
        DB::table('query_sources')->where('external_resource_id', $resourceId)->whereNotNull('query_id')
            ->where('month', '>=', $start->startOfMonth()->toDateString())
            ->select(['raw_query', 'query_id'])->distinct()->orderBy('raw_query')
            ->each(function (object $row) use (&$links): void {
                $links[(string) $row->raw_query] = (int) $row->query_id;
            }, self::CHUNK);
        if ($links === []) {
            return;
        }
        $positionSql = $position ? QuerySourceAggregator::positionExpression() : 'NULL';
        DB::table($table)->where('external_resource_id', $resourceId)
            ->when($position, fn ($q) => $q->where('search_type', 'web'))
            ->whereBetween('reporting_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy($column)
            ->selectRaw("{$column} as raw, sum(impressions) as impressions, sum(clicks) as clicks, sum(({$positionSql}) * impressions) as weighted, sum(CASE WHEN ({$positionSql}) IS NULL THEN 0 ELSE impressions END) as weight")
            ->orderBy($column)
            ->each(function (object $row) use (&$metrics, $links): void {
                $queryId = $links[QuerySourceAggregator::cleanRaw((string) $row->raw)] ?? null;
                if ($queryId === null) {
                    return;
                }
                $current = $metrics[$queryId] ?? ['clicks' => 0, 'impressions' => 0, 'weighted' => 0.0, 'weight' => 0];
                $metrics[$queryId] = [
                    'clicks' => $current['clicks'] + (int) $row->clicks,
                    'impressions' => $current['impressions'] + (int) $row->impressions,
                    'weighted' => $current['weighted'] + (float) $row->weighted,
                    'weight' => $current['weight'] + (int) $row->weight,
                ];
            }, self::CHUNK);
    }

    /** @param array<int, array{clicks: int, impressions: int, weighted: float, weight: int}> $metrics */
    private function writeBrandQueries(int $brandId, array $metrics): int
    {
        $existing = DB::table('brand_queries')->where('brand_id', $brandId)->whereNull('target_area_id')->pluck('id', 'query_id')->all();
        $valid = $metrics === [] ? [] : array_flip(DB::table('queries')->whereIn('id', array_keys($metrics))->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $now = now();
        $insert = [];
        foreach ($metrics as $queryId => $row) {
            if (! isset($valid[$queryId])) {
                continue;
            }
            $values = [
                'clicks_28d' => $row['clicks'], 'impressions_28d' => $row['impressions'],
                'position_28d' => $row['weight'] > 0 ? round($row['weighted'] / $row['weight'], 2) : null, 'updated_at' => $now,
            ];
            if (isset($existing[$queryId])) {
                DB::table('brand_queries')->where('id', $existing[$queryId])->update($values);
                unset($existing[$queryId]);

                continue;
            }
            $insert[] = $values + ['brand_id' => $brandId, 'query_id' => $queryId, 'created_at' => $now];
        }
        foreach (array_chunk($insert, 500) as $chunk) {
            DB::table('brand_queries')->insert($chunk);
        }
        // No numbers in the window: zeroed (brandTargets removes it unless it is a target).
        foreach (array_chunk(array_values($existing), self::CHUNK) as $chunk) {
            DB::table('brand_queries')->whereIn('id', $chunk)->update(['clicks_28d' => 0, 'impressions_28d' => 0, 'position_28d' => null, 'updated_at' => $now]);
        }

        return count($valid === [] ? [] : array_intersect_key($metrics, $valid));
    }
}
