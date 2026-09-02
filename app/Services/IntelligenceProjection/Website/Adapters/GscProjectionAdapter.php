<?php

namespace App\Services\IntelligenceProjection\Website\Adapters;

use App\Contracts\IntelligenceCore\WebsiteProjectionSourceAdapter;
use App\Enums\IntelligenceCore\IntelligenceSourceClass;
use App\Enums\IntelligenceCore\SearchTermKind;
use App\Services\Gsc\GscSpecialistBindingResolver;
use App\Services\IntelligenceCore\Identity\PageIdentityResolver;
use App\Services\IntelligenceCore\Identity\SearchTermIdentityResolver;
use App\Services\IntelligenceProjection\Website\WebsiteProjectionAdapterSupport;
use App\Support\IntelligenceCore\IntelligenceSourceReference;
use App\Support\IntelligenceProjection\WebsiteProjectionContext;
use App\Support\IntelligenceProjection\WebsiteProjectionContribution;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class GscProjectionAdapter implements WebsiteProjectionSourceAdapter
{
    private const string SEARCH_TYPE = 'web';

    public function __construct(
        private readonly GscSpecialistBindingResolver $bindings,
        private readonly PageIdentityResolver $pages,
        private readonly SearchTermIdentityResolver $terms,
        private readonly WebsiteProjectionAdapterSupport $support,
    ) {}

    public function sourceId(): string
    {
        return 'gsc';
    }

    public function capabilityIds(): array
    {
        return ['search.first_party.read'];
    }

    public function profileIds(): array
    {
        return ['page', 'search_term'];
    }

    public function metricIds(): array
    {
        return ['gsc.clicks', 'gsc.impressions', 'gsc.average_position'];
    }

    public function project(WebsiteProjectionContext $context): WebsiteProjectionContribution
    {
        $asset = $context->websiteAsset;
        $binding = $this->bindings->resolve((string) $asset->getKey());
        if (! $binding->isReal() || $binding->externalResourceId === null || $binding->siteUrl === null) {
            return new WebsiteProjectionContribution(
                sourceId: $this->sourceId(),
                coverage: [
                    'state' => $binding->externalResourceId === null ? 'not_configured' : 'unavailable',
                    'reason' => $binding->reason,
                ],
            );
        }
        if (! Schema::hasTable('gsc_page_daily') || ! Schema::hasTable('gsc_query_daily')) {
            return new WebsiteProjectionContribution(
                sourceId: $this->sourceId(),
                coverage: ['state' => 'not_collected', 'external_resource_id' => $binding->externalResourceId],
            );
        }

        $start = $context->periodStart->toDateString();
        $end = $context->periodEnd->toDateString();
        $timezone = $binding->timezone ?: 'UTC';
        $pages = $this->aggregateDimension('gsc_page_daily', 'page', $binding->externalResourceId, $binding->siteUrl, $start, $end);
        $terms = $this->aggregateDimension('gsc_query_daily', 'query', $binding->externalResourceId, $binding->siteUrl, $start, $end);
        $relations = Schema::hasTable('gsc_query_page_daily')
            ? $this->aggregateQueryPages($binding->externalResourceId, $binding->siteUrl, $start, $end)
            : [];

        $pageContributions = [];
        $pageIndexes = [];
        $pageIdentityByUrl = [];
        foreach ($pages as $url => $aggregate) {
            $identity = $this->resolvePage($context, $url, $aggregate, $timezone, $start, $end, $binding->externalResourceId);
            if ($identity === null) {
                continue;
            }
            $source = $this->aggregateSource('gsc_page_daily', $aggregate, $binding->externalResourceId);
            $time = $this->support->time(
                timezone: $timezone,
                periodStart: $start,
                periodEnd: $end,
                observedAt: $aggregate['last_collected_at'],
                retrievedAt: $aggregate['last_collected_at'],
                marketCode: $asset->seo_market_location_code !== null ? (string) $asset->seo_market_location_code : null,
                languageCode: $asset->seo_market_language_code,
            );
            $metrics = $this->gscMetrics('page_period', ['page_identity_id' => $identity], $aggregate, $source, $time);
            $pageIndexes[$identity] = count($pageContributions);
            $pageIdentityByUrl[$url] = $identity;
            $pageContributions[] = [
                'identity_id' => $identity,
                'source_state' => [
                    'state' => 'collected',
                    'period' => ['start' => $start, 'end' => $end],
                    'site_url' => $binding->siteUrl,
                    'search_type' => self::SEARCH_TYPE,
                    'metrics' => $metrics,
                    'top_queries' => [],
                    'data_quality' => $this->dataQuality(),
                    'source' => $source->toArray(),
                    'time_context' => $time->toArray(),
                ],
                'observed_at' => $aggregate['last_collected_at'],
            ];
        }

        $termContributions = [];
        $termIndexes = [];
        $termIdentityByText = [];
        foreach ($terms as $query => $aggregate) {
            $source = $this->aggregateSource('gsc_query_daily', $aggregate, $binding->externalResourceId);
            $time = $this->support->time(
                timezone: $timezone,
                periodStart: $start,
                periodEnd: $end,
                observedAt: $aggregate['last_collected_at'],
                retrievedAt: $aggregate['last_collected_at'],
                marketCode: $asset->seo_market_location_code !== null ? (string) $asset->seo_market_location_code : null,
                languageCode: $asset->seo_market_language_code,
            );
            $identity = $this->terms->resolve(
                brand: $asset->brand,
                observedText: $query,
                termKind: SearchTermKind::GscQuery,
                source: $source,
                time: $time,
                locale: null,
                metadata: ['site_url' => $binding->siteUrl, 'search_type' => self::SEARCH_TYPE],
            );
            $identityId = (int) $identity->getKey();
            $termIndexes[$identityId] = count($termContributions);
            $termIdentityByText[$query] = $identityId;
            $termContributions[] = [
                'identity_id' => $identityId,
                'source_state' => [
                    'state' => 'collected',
                    'term_kind' => SearchTermKind::GscQuery->value,
                    'period' => ['start' => $start, 'end' => $end],
                    'site_url' => $binding->siteUrl,
                    'search_type' => self::SEARCH_TYPE,
                    'metrics' => $this->gscMetrics('query_period', ['search_term_identity_id' => $identityId], $aggregate, $source, $time),
                    'top_pages' => [],
                    'data_quality' => $this->dataQuality(),
                    'source' => $source->toArray(),
                    'time_context' => $time->toArray(),
                ],
                'observed_at' => $aggregate['last_collected_at'],
            ];
        }

        $this->attachRelations(
            context: $context,
            relations: $relations,
            pageContributions: $pageContributions,
            pageIndexes: $pageIndexes,
            pageIdentityByUrl: $pageIdentityByUrl,
            termContributions: $termContributions,
            termIndexes: $termIndexes,
            termIdentityByText: $termIdentityByText,
            timezone: $timezone,
            start: $start,
            end: $end,
            resourceId: $binding->externalResourceId,
        );

        $watermark = $this->support->latestTimestamp(
            ...array_column($pages, 'last_collected_at'),
            ...array_column($terms, 'last_collected_at'),
        );

        return new WebsiteProjectionContribution(
            sourceId: $this->sourceId(),
            pages: $pageContributions,
            searchTerms: $termContributions,
            coverage: [
                'state' => $pageContributions === [] && $termContributions === [] ? 'not_collected' : 'collected',
                'external_resource_id' => $binding->externalResourceId,
                'site_url' => $binding->siteUrl,
                'search_type' => self::SEARCH_TYPE,
                'requested_period' => ['start' => $start, 'end' => $end],
                'page_count' => count($pageContributions),
                'search_term_count' => count($termContributions),
                'query_page_relationship_count' => count($relations),
                'watermark' => $watermark,
                'data_quality' => $this->dataQuality(),
            ],
            watermark: $watermark,
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function aggregateDimension(
        string $table,
        string $dimension,
        int $resourceId,
        string $siteUrl,
        string $start,
        string $end,
    ): array {
        $aggregates = [];
        $this->baseQuery($table, $resourceId, $siteUrl, $start, $end)
            ->orderBy('reporting_date')
            ->orderBy('id')
            ->chunk(1000, function ($rows) use (&$aggregates, $dimension): void {
                foreach ($rows as $row) {
                    $value = trim((string) $row->{$dimension});
                    if ($value === '') {
                        continue;
                    }
                    $aggregate = $aggregates[$value] ?? $this->emptyAggregate($value);
                    $impressions = (int) ($row->impressions ?? 0);
                    $position = $this->metadataFloat($row->metadata ?? null, 'provider_average_position');
                    $aggregate['clicks'] += (int) ($row->clicks ?? 0);
                    $aggregate['impressions'] += $impressions;
                    if ($position !== null && $impressions > 0) {
                        $aggregate['position_numerator'] += $position * $impressions;
                        $aggregate['position_impressions'] += $impressions;
                    }
                    $aggregate['last_collected_at'] = $this->support->latestTimestamp($aggregate['last_collected_at'], $row->last_collected_at ?? null);
                    $aggregate['latest_row'] = $row;
                    $this->trackRunProvenance($aggregate, $row);
                    $aggregates[$value] = $aggregate;
                }
            });

        foreach ($aggregates as &$aggregate) {
            $aggregate['position'] = $aggregate['position_impressions'] > 0
                ? $aggregate['position_numerator'] / $aggregate['position_impressions']
                : null;
        }
        unset($aggregate);

        return $aggregates;
    }

    /** @return list<array<string, mixed>> */
    private function aggregateQueryPages(int $resourceId, string $siteUrl, string $start, string $end): array
    {
        $pairs = [];
        $this->baseQuery('gsc_query_page_daily', $resourceId, $siteUrl, $start, $end)
            ->orderBy('reporting_date')
            ->orderBy('id')
            ->chunk(1000, function ($rows) use (&$pairs): void {
                foreach ($rows as $row) {
                    $query = trim((string) $row->query);
                    $page = trim((string) $row->page);
                    if ($query === '' || $page === '') {
                        continue;
                    }
                    $key = hash('sha256', $query."\0".$page);
                    $aggregate = $pairs[$key] ?? $this->emptyAggregate($query.'|'.$page) + ['query' => $query, 'page' => $page];
                    $impressions = (int) ($row->impressions ?? 0);
                    $position = $this->metadataFloat($row->metadata ?? null, 'provider_average_position');
                    $aggregate['clicks'] += (int) ($row->clicks ?? 0);
                    $aggregate['impressions'] += $impressions;
                    if ($position !== null && $impressions > 0) {
                        $aggregate['position_numerator'] += $position * $impressions;
                        $aggregate['position_impressions'] += $impressions;
                    }
                    $aggregate['last_collected_at'] = $this->support->latestTimestamp($aggregate['last_collected_at'], $row->last_collected_at ?? null);
                    $aggregate['latest_row'] = $row;
                    $this->trackRunProvenance($aggregate, $row);
                    $pairs[$key] = $aggregate;
                }
            });

        foreach ($pairs as &$pair) {
            $pair['position'] = $pair['position_impressions'] > 0
                ? $pair['position_numerator'] / $pair['position_impressions']
                : null;
        }
        unset($pair);

        return array_values($pairs);
    }

    /** @param array<string, mixed> $aggregate */
    private function resolvePage(
        WebsiteProjectionContext $context,
        string $url,
        array $aggregate,
        string $timezone,
        string $start,
        string $end,
        int $resourceId,
    ): ?int {
        $absolute = $this->support->absolutePageUrl($context->websiteAsset, $url);
        if ($absolute === null) {
            return null;
        }
        $source = $this->aggregateSource('gsc_page_daily', $aggregate, $resourceId);
        $time = $this->support->time(
            timezone: $timezone,
            periodStart: $start,
            periodEnd: $end,
            observedAt: $aggregate['last_collected_at'],
            retrievedAt: $aggregate['last_collected_at'],
            marketCode: $context->websiteAsset->seo_market_location_code !== null ? (string) $context->websiteAsset->seo_market_location_code : null,
            languageCode: $context->websiteAsset->seo_market_language_code,
        );

        return (int) $this->pages->resolveObserved(
            websiteAsset: $context->websiteAsset,
            observedUrl: $absolute,
            source: $source,
            time: $time,
            aliasKind: 'gsc_page',
            metadata: ['site_url' => $aggregate['latest_row']->site_url ?? null],
        )->getKey();
    }

    /**
     * @param list<array<string,mixed>> $relations
     * @param list<array<string,mixed>> $pageContributions
     * @param array<int,int> $pageIndexes
     * @param array<string,int> $pageIdentityByUrl
     * @param list<array<string,mixed>> $termContributions
     * @param array<int,int> $termIndexes
     * @param array<string,int> $termIdentityByText
     */
    private function attachRelations(
        WebsiteProjectionContext $context,
        array $relations,
        array &$pageContributions,
        array &$pageIndexes,
        array &$pageIdentityByUrl,
        array &$termContributions,
        array &$termIndexes,
        array &$termIdentityByText,
        string $timezone,
        string $start,
        string $end,
        int $resourceId,
    ): void {
        usort($relations, static fn (array $left, array $right): int => $right['impressions'] <=> $left['impressions']);
        $pageRelationCounts = [];
        $termRelationCounts = [];
        foreach ($relations as $relation) {
            $pageIdentity = $pageIdentityByUrl[$relation['page']] ?? null;
            if ($pageIdentity === null) {
                $pageIdentity = $this->resolvePage($context, $relation['page'], $relation, $timezone, $start, $end, $resourceId);
                if ($pageIdentity !== null) {
                    $pageIdentityByUrl[$relation['page']] = $pageIdentity;
                }
            }
            $termIdentity = $termIdentityByText[$relation['query']] ?? null;
            if ($pageIdentity === null || $termIdentity === null) {
                continue;
            }

            $relationState = [
                'page_identity_id' => $pageIdentity,
                'search_term_identity_id' => $termIdentity,
                'clicks' => $relation['clicks'],
                'impressions' => $relation['impressions'],
                'ctr' => $relation['impressions'] > 0 ? ($relation['clicks'] / $relation['impressions']) * 100 : null,
                'average_position' => $relation['position'],
            ];
            $pageRelationCounts[$pageIdentity] = ($pageRelationCounts[$pageIdentity] ?? 0) + 1;
            if ($pageRelationCounts[$pageIdentity] <= 25 && isset($pageIndexes[$pageIdentity])) {
                $pageContributions[$pageIndexes[$pageIdentity]]['source_state']['top_queries'][] = $relationState;
            }
            $termRelationCounts[$termIdentity] = ($termRelationCounts[$termIdentity] ?? 0) + 1;
            if ($termRelationCounts[$termIdentity] <= 25 && isset($termIndexes[$termIdentity])) {
                $termContributions[$termIndexes[$termIdentity]]['source_state']['top_pages'][] = $relationState;
            }
        }
    }

    /** @param array<string,mixed> $aggregate @return list<array<string,mixed>> */
    private function gscMetrics(
        string $grain,
        array $dimensions,
        array $aggregate,
        IntelligenceSourceReference $source,
        \App\Support\IntelligenceCore\IntelligenceTimeContext $time,
    ): array {
        return [
            $this->support->metric('gsc.clicks', $aggregate['clicks'], $grain, $dimensions, $source, $time, metadata: $this->runProvenance($aggregate)),
            $this->support->metric('gsc.impressions', $aggregate['impressions'], $grain, $dimensions, $source, $time, metadata: $this->runProvenance($aggregate)),
            $this->support->metric('gsc.average_position', $aggregate['position'], $grain, $dimensions, $source, $time, metadata: $this->runProvenance($aggregate)),
        ];
    }

    /** @param array<string,mixed> $aggregate */
    private function aggregateSource(string $dataset, array $aggregate, int $resourceId): IntelligenceSourceReference
    {
        return $this->support->source(
            provider: 'gsc',
            sourceClass: IntelligenceSourceClass::FirstPartyMeasured,
            semantic: str_replace('gsc_', '', $dataset),
            datasetId: $dataset,
            row: $aggregate['latest_row'] ?? null,
            fallbackResourceId: $resourceId,
            recordKey: $dataset.'|'.($aggregate['identity_key'] ?? 'aggregate'),
        );
    }

    private function baseQuery(string $table, int $resourceId, string $siteUrl, string $start, string $end): Builder
    {
        $query = DB::table($table)
            ->where('external_resource_id', $resourceId)
            ->where('site_url', $siteUrl)
            ->whereBetween('reporting_date', [$start, $end]);
        if (Schema::hasColumn($table, 'search_type')) {
            $query->where('search_type', self::SEARCH_TYPE);
        }

        return $query;
    }

    /** @return array<string,mixed> */
    private function emptyAggregate(string $identityKey): array
    {
        return [
            'identity_key' => $identityKey,
            'clicks' => 0,
            'impressions' => 0,
            'position_numerator' => 0.0,
            'position_impressions' => 0,
            'position' => null,
            'last_collected_at' => null,
            'latest_row' => null,
            'collection_run_ids' => [],
            'dataset_run_ids' => [],
        ];
    }

    /** @param array<string,mixed> $aggregate */
    private function trackRunProvenance(array &$aggregate, object $row): void
    {
        if (($row->last_collection_run_id ?? null) !== null) {
            $aggregate['collection_run_ids'][(int) $row->last_collection_run_id] = true;
        }
        if (($row->last_dataset_run_id ?? null) !== null) {
            $aggregate['dataset_run_ids'][(int) $row->last_dataset_run_id] = true;
        }
    }

    /** @param array<string,mixed> $aggregate @return array<string,list<int>> */
    private function runProvenance(array $aggregate): array
    {
        return [
            'input_collection_run_ids' => array_map('intval', array_keys($aggregate['collection_run_ids'] ?? [])),
            'input_dataset_run_ids' => array_map('intval', array_keys($aggregate['dataset_run_ids'] ?? [])),
        ];
    }

    private function metadataFloat(mixed $metadata, string $key): ?float
    {
        $value = $this->support->json($metadata)[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /** @return array<string,mixed> */
    private function dataQuality(): array
    {
        return [
            'provider_row_limits_apply' => true,
            'query_page_rows_are_not_site_totals' => true,
            'average_position_is_impression_weighted' => true,
            'average_position_is_not_rank_tracker' => true,
            'relationship_display_limit_per_identity' => 25,
        ];
    }
}
