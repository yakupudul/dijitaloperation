<?php

namespace App\Services\SearchDemand;

use App\Models\BrandQueryPortfolioItem;
use App\Models\DigitalAsset;
use App\Models\IntelligenceCore\IntelligenceSearchTermAlias;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\SearchDemandKeywordMetricSnapshot;
use App\Models\SearchDemandSerpSnapshot;
use App\Services\Ga4\Ga4SpecialistBindingResolver;
use App\Services\Gsc\GscSpecialistBindingResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SearchDemandVisibilityReadService
{
    public function __construct(
        private readonly GscSpecialistBindingResolver $gscBindings,
        private readonly Ga4SpecialistBindingResolver $ga4Bindings,
    ) {}

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function read(
        DigitalAsset $website,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        CarbonImmutable $comparisonStart,
        CarbonImmutable $comparisonEnd,
        array $filters = [],
    ): array {
        $items = $this->portfolioItems($website, $filters);
        $profiles = WebsitePageProfile::query()
            ->where('website_asset_id', $website->id)
            ->get()
            ->keyBy(fn (WebsitePageProfile $profile): string => $this->urlKey($profile->preferred_url));

        $gsc = $this->gscBindings->resolve((string) $website->id);
        $ga4 = $this->ga4Bindings->resolve((string) $website->id);
        $queryMap = $this->queryTextMap($items, $gsc->externalResourceId);
        $serpByItem = $this->latestSerp($website, $items);
        $metricByItem = $this->latestMetrics($website, $items);

        $currentRelations = $this->gscRelations(
            $gsc->isReal() ? $gsc->externalResourceId : null,
            $gsc->siteUrl,
            $periodStart,
            $periodEnd,
            $queryMap,
        );
        $comparisonRelations = $this->gscRelations(
            $gsc->isReal() ? $gsc->externalResourceId : null,
            $gsc->siteUrl,
            $comparisonStart,
            $comparisonEnd,
            $queryMap,
        );
        $currentLanding = $this->ga4Landing(
            $ga4->isReal() ? $ga4->externalResourceId : null,
            $ga4->propertyId,
            $periodStart,
            $periodEnd,
        );
        $comparisonLanding = $this->ga4Landing(
            $ga4->isReal() ? $ga4->externalResourceId : null,
            $ga4->propertyId,
            $comparisonStart,
            $comparisonEnd,
        );

        $rows = [];
        foreach ($items as $item) {
            $current = collect($currentRelations[$item->id] ?? [])->keyBy('url_key');
            $previous = collect($comparisonRelations[$item->id] ?? [])->keyBy('url_key');
            $urlKeys = $current->keys()->merge($previous->keys())->unique()->values();

            if ($urlKeys->isEmpty()) {
                $rows[] = $this->row($item, null, null, null, null, null, $serpByItem->get($item->id), $metricByItem->get($item->id));

                continue;
            }

            foreach ($urlKeys as $urlKey) {
                $relation = $current->get($urlKey);
                $comparison = $previous->get($urlKey);
                $displayRelation = $relation ?? $comparison;
                $profile = $profiles->get($urlKey);
                $landingKey = $this->landingKey((string) ($displayRelation['page'] ?? ''));
                $rows[] = $this->row(
                    $item,
                    $relation,
                    $profile instanceof WebsitePageProfile ? $profile : null,
                    $comparison,
                    $currentLanding[$landingKey] ?? null,
                    $comparisonLanding[$landingKey] ?? null,
                    $serpByItem->get($item->id),
                    $metricByItem->get($item->id),
                );
            }
        }

        $rows = collect($rows)
            ->when(($filters['observation'] ?? 'all') === 'observed', fn (Collection $rows) => $rows->where('observed', true))
            ->when(($filters['observation'] ?? 'all') === 'unobserved', fn (Collection $rows) => $rows->where('observed', false))
            ->sortByDesc(fn (array $row): int => $row['current']['impressions'] ?? -1)
            ->values();

        return [
            'rows' => $rows->take(500)->all(),
            'summary' => $this->summary($rows, $items),
            'cluster_summary' => $this->clusterSummary($rows),
            'period' => ['start' => $periodStart->toDateString(), 'end' => $periodEnd->toDateString()],
            'comparison_period' => ['start' => $comparisonStart->toDateString(), 'end' => $comparisonEnd->toDateString()],
            'coverage' => [
                'gsc' => [
                    'state' => $gsc->isReal() && Schema::hasTable('gsc_query_page_daily') ? 'available' : 'unavailable',
                    'reason' => $gsc->reason,
                    'external_resource_id' => $gsc->externalResourceId,
                    'site_url' => $gsc->siteUrl,
                    'source' => 'gsc_query_page_daily',
                ],
                'ga4' => [
                    'state' => $ga4->isReal() && Schema::hasTable('ga4_landing_page_daily') ? 'available' : 'unavailable',
                    'reason' => $ga4->reason,
                    'external_resource_id' => $ga4->externalResourceId,
                    'property_id' => $ga4->propertyId,
                    'source' => 'ga4_landing_page_daily',
                ],
                'website' => [
                    'state' => $profiles->isEmpty() ? 'unobserved' : 'available',
                    'profile_count' => $profiles->count(),
                    'source' => 'website_page_profiles',
                ],
                'dataforseo' => [
                    'state' => $serpByItem->isEmpty() && $metricByItem->isEmpty() ? 'unobserved' : 'available',
                    'serp_query_count' => $serpByItem->count(),
                    'metric_query_count' => $metricByItem->count(),
                    'source' => 'search_demand_serp_snapshots + search_demand_keyword_metric_snapshots',
                ],
            ],
            'truncated' => $rows->count() > 500,
        ];
    }

    /** @param array<string, mixed> $filters @return Collection<int, BrandQueryPortfolioItem> */
    private function portfolioItems(DigitalAsset $website, array $filters): Collection
    {
        return BrandQueryPortfolioItem::query()
            ->with(['libraryItem', 'services.primaryName', 'serviceAreas', 'clusterMembership.cluster'])
            ->where('brand_id', $website->brand_id)
            ->where('status', 'active')
            ->whereHas('assetStates', fn ($query) => $query
                ->where('digital_asset_id', $website->id)
                ->where('status', 'active'))
            ->when(filled($filters['cluster_id'] ?? null), fn ($query) => $query->whereHas(
                'clusterMembership',
                fn ($membership) => $membership->where('search_demand_cluster_id', (int) $filters['cluster_id']),
            ))
            ->when(filled($filters['service_id'] ?? null), fn ($query) => $query->whereHas(
                'services',
                fn ($services) => $services->whereKey((int) $filters['service_id']),
            ))
            ->when(filled($filters['area_id'] ?? null), function ($query) use ($filters): void {
                $query->where(function ($areaQuery) use ($filters): void {
                    $areaQuery->where('area_scope', 'all_brand_areas')
                        ->orWhereHas('serviceAreas', fn ($areas) => $areas->whereKey((int) $filters['area_id']));
                });
            })
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters): void {
                $like = '%'.trim((string) $filters['search']).'%';
                $query->where(function ($searchQuery) use ($like): void {
                    $searchQuery->whereLike('query_text_override', $like, caseSensitive: false)
                        ->orWhereLike('custom_canonical_text', $like, caseSensitive: false)
                        ->orWhereHas('libraryItem', fn ($library) => $library->whereLike('canonical_text', $like, caseSensitive: false));
                });
            })
            ->orderBy('id')
            ->limit(500)
            ->get();
    }

    /**
     * @param Collection<int, BrandQueryPortfolioItem> $items
     * @return array<string, int>
     */
    private function queryTextMap(Collection $items, ?int $gscResourceId): array
    {
        $map = [];
        foreach ($items as $item) {
            $text = trim($item->effectiveQueryText());
            if ($text !== '') {
                $map[$text] = $item->id;
            }
        }

        $identityIds = $items->pluck('intelligence_search_term_identity_id')->filter()->map(fn ($id): int => (int) $id);
        if ($identityIds->isEmpty() || $gscResourceId === null) {
            return $map;
        }
        $itemByIdentity = $items->filter(fn ($item) => $item->intelligence_search_term_identity_id !== null)
            ->keyBy(fn ($item): int => (int) $item->intelligence_search_term_identity_id);
        IntelligenceSearchTermAlias::query()
            ->whereIn('search_term_identity_id', $identityIds)
            ->where('provider_or_source', 'gsc')
            ->where('external_resource_id', $gscResourceId)
            ->get(['search_term_identity_id', 'observed_text'])
            ->each(function (IntelligenceSearchTermAlias $alias) use (&$map, $itemByIdentity): void {
                $item = $itemByIdentity->get((int) $alias->search_term_identity_id);
                $text = trim($alias->observed_text);
                if ($item instanceof BrandQueryPortfolioItem && $text !== '') {
                    $map[$text] = $item->id;
                }
            });

        return $map;
    }

    /**
     * @param array<string, int> $queryMap
     * @return array<int, list<array<string, mixed>>>
     */
    private function gscRelations(
        ?int $resourceId,
        ?string $siteUrl,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $queryMap,
    ): array {
        if ($resourceId === null || blank($siteUrl) || $queryMap === [] || ! Schema::hasTable('gsc_query_page_daily')) {
            return [];
        }

        $aggregates = [];
        DB::table('gsc_query_page_daily')
            ->where('external_resource_id', $resourceId)
            ->where('site_url', $siteUrl)
            ->whereBetween('reporting_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('query', array_keys($queryMap))
            ->orderBy('id')
            ->chunk(2000, function ($rows) use (&$aggregates, $queryMap): void {
                foreach ($rows as $source) {
                    $itemId = $queryMap[trim((string) $source->query)] ?? null;
                    $page = trim((string) $source->page);
                    if ($itemId === null || $page === '') {
                        continue;
                    }
                    $urlKey = $this->urlKey($page);
                    $key = $itemId.'|'.$urlKey;
                    $row = $aggregates[$key] ?? [
                        'portfolio_item_id' => $itemId,
                        'page' => $page,
                        'url_key' => $urlKey,
                        'clicks' => 0,
                        'impressions' => 0,
                        'position_numerator' => 0.0,
                        'position_impressions' => 0,
                        'last_collected_at' => null,
                    ];
                    $impressions = (int) ($source->impressions ?? 0);
                    $position = $this->metadataFloat($source->metadata ?? null, 'provider_average_position');
                    $row['clicks'] += (int) ($source->clicks ?? 0);
                    $row['impressions'] += $impressions;
                    if ($position !== null && $impressions > 0) {
                        $row['position_numerator'] += $position * $impressions;
                        $row['position_impressions'] += $impressions;
                    }
                    $row['last_collected_at'] = max((string) $row['last_collected_at'], (string) ($source->last_collected_at ?? '')) ?: null;
                    $aggregates[$key] = $row;
                }
            });

        $result = [];
        foreach ($aggregates as $row) {
            $row['average_position'] = $row['position_impressions'] > 0
                ? $row['position_numerator'] / $row['position_impressions']
                : null;
            $row['ctr'] = $row['impressions'] > 0 ? ($row['clicks'] / $row['impressions']) * 100 : null;
            unset($row['position_numerator'], $row['position_impressions']);
            $result[$row['portfolio_item_id']][] = $row;
        }

        return $result;
    }

    /** @return array<string, array<string, int|null>> */
    private function ga4Landing(
        ?int $resourceId,
        ?string $propertyId,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): array {
        if ($resourceId === null || blank($propertyId) || ! Schema::hasTable('ga4_landing_page_daily')) {
            return [];
        }

        $rows = DB::table('ga4_landing_page_daily')
            ->where('external_resource_id', $resourceId)
            ->where('property_id', $propertyId)
            ->whereBetween('reporting_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('"landingPage", SUM(sessions) AS sessions, SUM("engagedSessions") AS engaged_sessions')
            ->groupBy('landingPage')
            ->get();

        $aggregates = [];
        foreach ($rows as $row) {
            $key = $this->landingKey((string) $row->landingPage);
            if ($key === '') {
                continue;
            }
            $aggregates[$key] = [
                'sessions' => isset($row->sessions) ? (int) $row->sessions : null,
                'engaged_sessions' => isset($row->engaged_sessions) ? (int) $row->engaged_sessions : null,
            ];
        }

        return $aggregates;
    }

    /** @return array<string, mixed> */
    private function row(
        BrandQueryPortfolioItem $item,
        ?array $current,
        ?WebsitePageProfile $profile,
        ?array $comparison,
        ?array $landing,
        ?array $comparisonLanding,
        ?SearchDemandSerpSnapshot $serp,
        ?SearchDemandKeywordMetricSnapshot $metric,
    ): array {
        $states = $profile?->source_states ?? [];
        $status = data_get($states, 'website.http.status_code');
        $robots = data_get($states, 'website.document_head.robots');
        $htmlHash = data_get($states, 'website.html.html_hash');
        $indexability = $this->indexability($status, $robots);
        $cluster = $item->clusterMembership?->cluster;

        return [
            'portfolio_item_id' => $item->id,
            'query' => $item->effectiveQueryText(),
            'demand_family' => $item->effectiveDemandFamily(),
            'cluster' => $cluster ? ['id' => $cluster->id, 'name' => $cluster->name] : null,
            'services' => $item->services->map(fn ($service): array => [
                'id' => $service->id,
                'name' => $service->primaryName?->raw_label,
            ])->values()->all(),
            'areas' => $item->area_scope === 'all_brand_areas'
                ? [['id' => null, 'name' => 'Tüm marka bölgeleri']]
                : $item->serviceAreas->map(fn ($area): array => ['id' => $area->id, 'name' => $area->label()])->values()->all(),
            'observed' => $current !== null,
            'url' => $current['page'] ?? $comparison['page'] ?? null,
            'url_key' => $current['url_key'] ?? $comparison['url_key'] ?? null,
            'page_profile_id' => $profile?->id,
            'current' => [
                'clicks' => $current['clicks'] ?? null,
                'impressions' => $current['impressions'] ?? null,
                'ctr' => $current['ctr'] ?? null,
                'average_position' => $current['average_position'] ?? null,
                'sessions' => $landing['sessions'] ?? null,
                'engaged_sessions' => $landing['engaged_sessions'] ?? null,
            ],
            'comparison' => [
                'clicks' => $comparison['clicks'] ?? null,
                'impressions' => $comparison['impressions'] ?? null,
                'ctr' => $comparison['ctr'] ?? null,
                'average_position' => $comparison['average_position'] ?? null,
                'sessions' => $comparisonLanding['sessions'] ?? null,
                'engaged_sessions' => $comparisonLanding['engaged_sessions'] ?? null,
            ],
            'change' => [
                'clicks' => $this->change($current['clicks'] ?? null, $comparison['clicks'] ?? null),
                'impressions' => $this->change($current['impressions'] ?? null, $comparison['impressions'] ?? null),
                'sessions' => $this->change($landing['sessions'] ?? null, $comparisonLanding['sessions'] ?? null),
                'average_position' => $this->change($current['average_position'] ?? null, $comparison['average_position'] ?? null),
            ],
            'page' => [
                'identity_state' => $profile === null ? 'unresolved' : 'resolved',
                'preferred_url' => $profile?->preferred_url,
                'http_status' => is_numeric($status) ? (int) $status : null,
                'robots' => is_string($robots) && $robots !== '' ? $robots : null,
                'html_observed' => filled($htmlHash),
                'indexability' => $indexability,
                'last_observed_at' => $profile?->last_observed_at?->toIso8601String(),
            ],
            'enrichment' => [
                'search_volume' => $metric?->search_volume,
                'cpc' => $metric?->cpc !== null ? (float) $metric->cpc : null,
                'competition' => $metric?->competition,
                'monthly_searches' => $metric?->monthly_searches,
                'measurement_type' => $metric?->measurement_type,
                'brand_rank' => $serp?->brand_rank,
                'brand_url' => $serp?->brand_url,
                'serp_features' => $serp?->serp_features,
                'device' => $serp?->device,
                'retrieved_at' => $serp?->retrieved_at?->toIso8601String() ?? $metric?->retrieved_at?->toIso8601String(),
            ],
            'provenance' => [
                'search' => $current === null ? null : 'gsc_query_page_daily',
                'behavior' => $landing === null ? null : 'ga4_landing_page_daily',
                'page' => $profile === null ? null : 'website_page_profiles',
                'serp' => $serp === null ? null : 'search_demand_serp_snapshots',
                'market_estimate' => $metric === null ? null : 'search_demand_keyword_metric_snapshots',
            ],
        ];
    }

    /** @param Collection<int, BrandQueryPortfolioItem> $items @return Collection<int, SearchDemandSerpSnapshot> */
    private function latestSerp(DigitalAsset $website, Collection $items): Collection
    {
        if ($items->isEmpty() || ! Schema::hasTable('search_demand_serp_snapshots')) {
            return collect();
        }

        return SearchDemandSerpSnapshot::query()
            ->where('digital_asset_id', $website->id)
            ->whereIn('brand_query_portfolio_item_id', $items->pluck('id'))
            ->latest('retrieved_at')
            ->limit(1000)
            ->get()
            ->unique('brand_query_portfolio_item_id')
            ->keyBy('brand_query_portfolio_item_id');
    }

    /** @param Collection<int, BrandQueryPortfolioItem> $items @return Collection<int, SearchDemandKeywordMetricSnapshot> */
    private function latestMetrics(DigitalAsset $website, Collection $items): Collection
    {
        if ($items->isEmpty() || ! Schema::hasTable('search_demand_keyword_metric_snapshots')) {
            return collect();
        }

        return SearchDemandKeywordMetricSnapshot::query()
            ->where('digital_asset_id', $website->id)
            ->whereIn('brand_query_portfolio_item_id', $items->pluck('id'))
            ->latest('retrieved_at')
            ->limit(1000)
            ->get()
            ->unique('brand_query_portfolio_item_id')
            ->keyBy('brand_query_portfolio_item_id');
    }

    /** @param Collection<int, array<string, mixed>> $rows @param Collection<int, BrandQueryPortfolioItem> $items */
    private function summary(Collection $rows, Collection $items): array
    {
        $observed = $rows->where('observed', true);

        return [
            'portfolio_queries' => $items->count(),
            'observed_queries' => $observed->pluck('portfolio_item_id')->unique()->count(),
            'unobserved_queries' => $items->pluck('id')->diff($observed->pluck('portfolio_item_id')->unique())->count(),
            'observed_query_url_pairs' => $observed->count(),
            'resolved_urls' => $observed->whereNotNull('page_profile_id')->pluck('url_key')->unique()->count(),
            'clicks' => $observed->isEmpty() ? null : $observed->sum('current.clicks'),
            'impressions' => $observed->isEmpty() ? null : $observed->sum('current.impressions'),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function clusterSummary(Collection $rows): array
    {
        return $rows->groupBy(fn (array $row): string => (string) data_get($row, 'cluster.id', 'unclustered'))
            ->map(function (Collection $clusterRows): array {
                $observed = $clusterRows->where('observed', true);

                return [
                    'cluster_id' => data_get($clusterRows->first(), 'cluster.id'),
                    'cluster_name' => data_get($clusterRows->first(), 'cluster.name', 'Kümelenmemiş'),
                    'query_count' => $clusterRows->pluck('portfolio_item_id')->unique()->count(),
                    'observed_query_count' => $observed->pluck('portfolio_item_id')->unique()->count(),
                    'url_count' => $observed->pluck('url_key')->filter()->unique()->count(),
                    'clicks' => $observed->isEmpty() ? null : $observed->sum('current.clicks'),
                    'impressions' => $observed->isEmpty() ? null : $observed->sum('current.impressions'),
                ];
            })
            ->sortByDesc(fn (array $row): int => $row['impressions'] ?? -1)
            ->values()
            ->all();
    }

    private function change(int|float|null $current, int|float|null $comparison): int|float|null
    {
        return $current === null || $comparison === null ? null : $current - $comparison;
    }

    private function indexability(mixed $status, mixed $robots): string
    {
        if (! is_numeric($status) && ! is_string($robots)) {
            return 'unknown';
        }
        if ((is_numeric($status) && ((int) $status < 200 || (int) $status >= 400))
            || (is_string($robots) && str_contains(mb_strtolower($robots), 'noindex'))) {
            return 'non_indexable_observed';
        }

        return is_numeric($status) ? 'indexable_observed' : 'unknown';
    }

    private function urlKey(string $url): string
    {
        $parts = parse_url(trim($url));
        if ($parts === false) {
            return trim($url);
        }
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/');
        $path = $path !== '/' ? rtrim($path, '/') : $path;
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $host.$path.$query;
    }

    private function landingKey(string $url): string
    {
        $parts = parse_url(trim($url));
        $path = $parts !== false ? (string) ($parts['path'] ?? '/') : trim($url);
        if ($path === '' || $path === '(not set)') {
            return '';
        }
        $path = '/'.ltrim($path, '/');

        return $path !== '/' ? rtrim($path, '/') : $path;
    }

    private function metadataFloat(mixed $metadata, string $key): ?float
    {
        $decoded = is_array($metadata) ? $metadata : (is_string($metadata) ? json_decode($metadata, true) : null);
        $value = is_array($decoded) ? ($decoded[$key] ?? null) : null;

        return is_numeric($value) ? (float) $value : null;
    }
}
