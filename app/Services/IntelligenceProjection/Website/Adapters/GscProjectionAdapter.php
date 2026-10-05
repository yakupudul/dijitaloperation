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
use App\Support\IntelligenceCore\IntelligenceTimeContext;
use App\Support\IntelligenceProjection\WebsiteProjectionContext;
use App\Support\IntelligenceProjection\WebsiteProjectionContribution;
use App\Support\Time\SafeTimezone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class GscProjectionAdapter implements WebsiteProjectionSourceAdapter
{
    private const string SEARCH_TYPE = 'web';

    /** Columns of a group's latest fact row that its source reference and alias read. */
    private const array LATEST_COLUMNS = ['digital_asset_id', 'external_resource_id', 'site_url', 'last_collection_run_id', 'last_dataset_run_id', 'contract_version'];

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
        $timezone = SafeTimezone::normalize($binding->timezone ?: 'UTC', 'UTC');
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

        $observations = [];
        foreach ($terms as $query => $aggregate) {
            $observations[] = [
                'observed_text' => (string) $query,
                'term_kind' => SearchTermKind::GscQuery,
                'source' => $this->aggregateSource('gsc_query_daily', $aggregate, $binding->externalResourceId),
                'time' => $this->support->time(
                    timezone: $timezone,
                    periodStart: $start,
                    periodEnd: $end,
                    observedAt: $aggregate['last_collected_at'],
                    retrievedAt: $aggregate['last_collected_at'],
                    marketCode: $asset->seo_market_location_code !== null ? (string) $asset->seo_market_location_code : null,
                    languageCode: $asset->seo_market_language_code,
                ),
                'locale' => null,
                'metadata' => ['site_url' => $binding->siteUrl, 'search_type' => self::SEARCH_TYPE],
            ];
        }
        // All terms in a few statements per 500 instead of a transaction and several statements per term.
        $identityIds = $this->terms->resolveMany($asset->brand, $observations);

        $termContributions = [];
        $termIndexes = [];
        $termIdentityByText = [];
        foreach (array_keys($terms) as $position => $query) {
            $aggregate = $terms[$query];
            $source = $observations[$position]['source'];
            $time = $observations[$position]['time'];
            $identityId = $identityIds[$position];
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

    /**
     * Page or query totals for the period, aggregated in SQL (one statement, no OFFSET pages over the fact table).
     * SQL groups by the stored value; values that only differ by surrounding whitespace fold into one, as before.
     *
     * @return array<string, array<string, mixed>>
     */
    private function aggregateDimension(
        string $table,
        string $dimension,
        int $resourceId,
        string $siteUrl,
        string $start,
        string $end,
    ): array {
        $facts = $this->baseQuery($table, $resourceId, $siteUrl, $start, $end);
        $dimensions = ['dimension_value' => $facts->getGrammar()->wrap($dimension)];
        $aggregates = $this->foldGroups(
            $this->support->factGroups($facts, $dimensions, $this->metricSums($facts), self::LATEST_COLUMNS),
            function (object $group): ?array {
                $value = trim((string) $group->dimension_value);

                return $value === '' ? null : ['key' => $value, 'empty' => $this->emptyAggregate($value)];
            },
        );
        foreach ($this->support->factFirstAppearances($facts, $dimensions, ['last_collection_run_id', 'last_dataset_run_id']) as $appearance) {
            $value = trim((string) $appearance->dimension_value);
            if (isset($aggregates[$value])) {
                $this->trackRunProvenance($aggregates[$value], $appearance);
            }
        }

        return $this->withPositions($aggregates);
    }

    /**
     * Query x page totals for the period, aggregated in SQL. Relations carry no run provenance (only page and
     * query metrics do), so the runs of this, the largest fact table, are not read.
     *
     * @return list<array<string, mixed>>
     */
    private function aggregateQueryPages(int $resourceId, string $siteUrl, string $start, string $end): array
    {
        $facts = $this->baseQuery('gsc_query_page_daily', $resourceId, $siteUrl, $start, $end);
        $grammar = $facts->getGrammar();
        $pairs = $this->foldGroups(
            $this->support->factGroups(
                $facts,
                ['query_value' => $grammar->wrap('query'), 'page_value' => $grammar->wrap('page')],
                $this->metricSums($facts),
                self::LATEST_COLUMNS,
            ),
            function (object $group): ?array {
                $query = trim((string) $group->query_value);
                $page = trim((string) $group->page_value);
                if ($query === '' || $page === '') {
                    return null;
                }

                return [
                    'key' => hash('sha256', $query."\0".$page),
                    'empty' => $this->emptyAggregate($query.'|'.$page) + ['query' => $query, 'page' => $page],
                ];
            },
        );

        return array_values($this->withPositions($pairs));
    }

    /**
     * Adds SQL groups into aggregates keyed like the row loop keyed them. Groups arrive in first-appearance order,
     * so keys are created in the order the rows first met them; the latest row is the latest of all folded groups.
     *
     * @param  list<object>  $groups
     * @param  callable(object): (array{key:string,empty:array<string,mixed>}|null)  $describe
     * @return array<string, array<string, mixed>>
     */
    private function foldGroups(array $groups, callable $describe): array
    {
        $aggregates = [];
        $latest = [];
        foreach ($groups as $group) {
            $described = $describe($group);
            if ($described === null) {
                continue;
            }
            $key = $described['key'];
            $aggregate = $aggregates[$key] ?? $described['empty'];
            $aggregate['clicks'] += (int) $group->clicks;
            $aggregate['impressions'] += (int) $group->impressions;
            $aggregate['position_numerator'] += (float) $group->position_numerator;
            $aggregate['position_impressions'] += (int) $group->position_impressions;
            $aggregate['last_collected_at'] = $this->support->latestTimestamp($aggregate['last_collected_at'], $group->last_collected_at);
            $order = [(string) $group->latest_date, $group->latest_id !== null ? (int) $group->latest_id : null];
            if ($this->support->isLaterFact($latest[$key] ?? null, $order)) {
                $latest[$key] = $order;
                $aggregate['latest_row'] = (object) array_intersect_key((array) $group, array_flip(self::LATEST_COLUMNS));
            }
            $aggregates[$key] = $aggregate;
        }

        return $aggregates;
    }

    /**
     * Clicks, impressions and the impression-weighted position (rows with a position and impressions only).
     *
     * @return array<string, string>
     */
    private function metricSums(Builder $facts): array
    {
        $grammar = $facts->getGrammar();
        $impressions = $grammar->wrap('impressions');
        $position = 'CAST('.$grammar->wrap('metadata->provider_average_position').' AS DOUBLE PRECISION)';
        $weighted = 'CASE WHEN '.$position.' IS NOT NULL AND '.$impressions.' > 0 THEN ';

        return [
            'clicks' => $grammar->wrap('clicks'),
            'impressions' => $impressions,
            'position_numerator' => $weighted.$position.' * '.$impressions.' END',
            'position_impressions' => $weighted.$impressions.' END',
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $aggregates
     * @return array<string, array<string, mixed>>
     */
    private function withPositions(array $aggregates): array
    {
        foreach ($aggregates as &$aggregate) {
            $aggregate['position'] = $aggregate['position_impressions'] > 0
                ? $aggregate['position_numerator'] / $aggregate['position_impressions']
                : null;
        }
        unset($aggregate);

        return $aggregates;
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
     * @param  list<array<string,mixed>>  $relations
     * @param  list<array<string,mixed>>  $pageContributions
     * @param  array<int,int>  $pageIndexes
     * @param  array<string,int>  $pageIdentityByUrl
     * @param  list<array<string,mixed>>  $termContributions
     * @param  array<int,int>  $termIndexes
     * @param  array<string,int>  $termIdentityByText
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
        IntelligenceTimeContext $time,
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
