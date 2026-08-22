<?php

namespace App\Services\Gsc;

use App\Enums\DataPool\DataSourceState;
use App\Models\DigitalAsset;
use App\Services\Formulas\GscFormulaCalculator;
use App\Services\Formulas\Support\FormulaResult;
use App\Services\Gsc\Support\GscBindingContext;
use App\Services\Gsc\Support\GscBindingMode;
use App\Services\Gsc\Support\GscDatasetReadiness;
use App\Support\Demo\GscWorkspaceFixtures;
use App\Support\Operator\OperatorReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Prompt 29 GSC real-data read path. Builds the frozen {@see GscWorkspaceFixtures::workspace()}
 * array shape from the data pool + formulas when a real property is bound.
 */
final class GscSpecialistReadService
{
    private const string DATASET_PROPERTY_DAILY = 'gsc_property_daily';
    private const string DATASET_QUERY_DAILY = 'gsc_query_daily';
    private const string DATASET_PAGE_DAILY = 'gsc_page_daily';
    private const string DATASET_DEVICE_DAILY = 'gsc_device_daily';
    private const string DATASET_COUNTRY_DAILY = 'gsc_country_daily';
    private const string DATASET_SITEMAP_SNAPSHOT = 'gsc_sitemap_snapshot';
    private const string DATASET_URL_INSPECTION_SNAPSHOT = 'gsc_url_inspection_snapshot';

    public const string POSITION_PROVENANCE_NOTE = 'Average position is impression-weighted provider metadata — not an exact SERP rank.';

    /** @var list<string> */
    private const array PROVENANCE_FIELDS = [
        'identity.site_url', 'identity.reporting_timezone', 'freshness.gsc',
        'glance.clicks', 'glance.impressions', 'glance.ctr', 'glance.search_attention',
        'performance_trend.clicks', 'performance_trend.impressions', 'search_momentum',
        'page_pulse', 'discoverability', 'performance.devices', 'performance.countries',
        'performance.brand_nonbrand', 'performance.diagnosis', 'demand.clusters',
        'demand.queries', 'demand.momentum', 'demand.ownership', 'pages.directory',
        'indexing.coverage', 'indexing.urls', 'indexing.sitemaps', 'indexing.reconciliation',
        'needs_attention', 'relationships.technical_connection', 'relationships.narrative',
        'operations.collection_state', 'operations.findings', 'opportunities',
    ];

    /** @var array<string, list<string>> */
    private const array TAB_FIELD_MAP = [
        'overview' => ['glance.clicks', 'glance.impressions', 'search_momentum', 'page_pulse', 'discoverability', 'needs_attention'],
        'performance' => ['performance_trend.clicks', 'performance.devices', 'performance.countries', 'performance.brand_nonbrand', 'performance.diagnosis'],
        'demand' => ['demand.clusters', 'demand.queries', 'demand.momentum', 'demand.ownership'],
        'pages' => ['pages.directory'],
        'indexing' => ['indexing.coverage', 'indexing.urls', 'indexing.sitemaps', 'indexing.reconciliation'],
        'operations' => ['operations.collection_state', 'operations.findings'],
    ];

    public function __construct(
        private readonly GscSpecialistBindingResolver $bindingResolver,
        private readonly GscUiDatasetGate $gate,
        private readonly GscPoolReadRepository $pool,
        private readonly GscFormulaCalculator $formulas,
    ) {}

    /** @return array<string, mixed> */
    public function workspace(string $assetId, string $preset = 'last_28', ?string $start = null, ?string $end = null, string $compareMode = 'previous'): array
    {
        $binding = $this->bindingResolver->resolve($assetId);

        if ($binding->mode === GscBindingMode::DemoCatalog) {
            return $this->demoWorkspace($preset, $start, $end);
        }
        if ($binding->mode !== GscBindingMode::RealBound) {
            return $this->operationalWorkspace($binding, $preset, $start, $end, 'not_connected');
        }

        try {
            return $this->buildRealWorkspace($binding, $preset, $start, $end, $compareMode);
        } catch (Throwable $e) {
            Log::error('gsc.read_service.real_workspace_failed', [
                'digital_asset_id' => $binding->digitalAssetId,
                'external_resource_id' => $binding->externalResourceId,
                'error' => $e->getMessage(),
            ]);

            return $this->operationalWorkspace($binding, $preset, $start, $end, 'real', $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function demoWorkspace(string $preset, ?string $start, ?string $end): array
    {
        $data = GscWorkspaceFixtures::workspace($preset, $start, $end);
        $data['migration_mode'] = 'demo_catalog';
        $data['data_provenance'] = $this->allProvenance(DataSourceState::Demo);
        $data['tab_status'] = array_fill_keys(array_keys(self::TAB_FIELD_MAP), DataSourceState::Demo->value);
        $data['metric_series'] = GscWorkspaceFixtures::metricSeries($data['period_start'], $data['period_end']);

        return $data;
    }

    /** @return array<string, mixed> */
    private function buildRealWorkspace(GscBindingContext $binding, string $preset, ?string $start, ?string $end, string $compareMode = 'previous'): array
    {
        $compareMode = $compareMode === 'yoy' ? 'yoy' : 'previous';
        $bounds = OperatorReportingPeriod::queryBounds($preset, $start, $end);
        $rangeStart = $bounds['start']->toDateString();
        $rangeEnd = $bounds['end']->toDateString();
        $prev = OperatorReportingPeriod::comparisonQueryBounds($compareMode, $preset, $start, $end);
        $prevStart = $prev['start']->toDateString();
        $prevEnd = $prev['end']->toDateString();

        $digitalAssetId = (int) $binding->digitalAssetId;
        $externalResourceId = (int) $binding->externalResourceId;
        $siteUrl = (string) $binding->siteUrl;
        $timezone = $binding->timezone;

        $provenance = $this->allProvenance(DataSourceState::Demo);
        $data = GscWorkspaceFixtures::workspace($preset, $start, $end);
        $data['period_label'] = $bounds['label'];
        $data['period_days'] = $bounds['days'];
        $data['period_start'] = $rangeStart;
        $data['period_end'] = $rangeEnd;
        $data['compare_label'] = 'vs '.$prev['label'];
        $data['compare_mode'] = $compareMode;

        $propertyGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_PROPERTY_DAILY, $rangeStart, $rangeEnd, $timezone);
        $prevPropertyGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_PROPERTY_DAILY, $prevStart, $prevEnd, $timezone);
        $queryGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_QUERY_DAILY, $rangeStart, $rangeEnd, $timezone);
        $pageGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_PAGE_DAILY, $rangeStart, $rangeEnd, $timezone);
        $deviceGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_DEVICE_DAILY, $rangeStart, $rangeEnd, $timezone);
        $countryGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_COUNTRY_DAILY, $rangeStart, $rangeEnd, $timezone);
        $sitemapGate = $this->gate->evaluateSnapshot($digitalAssetId, $externalResourceId, self::DATASET_SITEMAP_SNAPSHOT, $timezone);
        $inspectionGate = $this->gate->evaluateSnapshot($digitalAssetId, $externalResourceId, self::DATASET_URL_INSPECTION_SNAPSHOT, $timezone);

        $sums = $propertyGate->isUsable()
            ? $this->pool->propertyDailySums($digitalAssetId, $externalResourceId, $siteUrl, $propertyGate->effectiveStart, $propertyGate->effectiveEnd)
            : null;
        $prevSums = $prevPropertyGate->isUsable()
            ? $this->pool->propertyDailySums($digitalAssetId, $externalResourceId, $siteUrl, $prevPropertyGate->effectiveStart, $prevPropertyGate->effectiveEnd)
            : null;

        $asset = DigitalAsset::query()->with('brand')->find($digitalAssetId);
        $data['identity'] = $this->realIdentity($binding, $asset);
        $provenance['identity.site_url'] = DataSourceState::Real->value;
        $provenance['identity.reporting_timezone'] = DataSourceState::Real->value;

        $data['freshness'] = $this->realFreshnessChips($data['freshness'], $propertyGate, $siteUrl);
        $provenance['freshness.gsc'] = DataSourceState::Real->value;

        $data['glance'] = $this->realGlance($propertyGate, $sums, $prevSums, $compareMode);
        $provenance['glance.clicks'] = $propertyGate->dataSourceState()->value;
        $provenance['glance.impressions'] = $propertyGate->dataSourceState()->value;
        $provenance['glance.ctr'] = $propertyGate->dataSourceState()->value;
        $provenance['glance.search_attention'] = DataSourceState::Unavailable->value;

        $data['performance_trend'] = $this->realPerformanceTrend($propertyGate, $digitalAssetId, $externalResourceId, $siteUrl, $timezone);
        $provenance['performance_trend.clicks'] = $propertyGate->dataSourceState()->value;
        $provenance['performance_trend.impressions'] = $propertyGate->dataSourceState()->value;
        $data['metric_series'] = $this->realMetricSeries($propertyGate, $digitalAssetId, $externalResourceId, $siteUrl, $timezone);

        $data['performance'] = $this->realPerformance($digitalAssetId, $externalResourceId, $siteUrl, $deviceGate, $countryGate);
        $provenance['performance.devices'] = $deviceGate->dataSourceState()->value;
        $provenance['performance.countries'] = $countryGate->dataSourceState()->value;
        $provenance['performance.brand_nonbrand'] = DataSourceState::Unavailable->value;
        $provenance['performance.diagnosis'] = DataSourceState::Unavailable->value;

        $data['demand'] = $this->realDemand($digitalAssetId, $externalResourceId, $siteUrl, $queryGate);
        $provenance['demand.queries'] = $queryGate->isUsable() ? DataSourceState::ProviderLimited->value : DataSourceState::Unavailable->value;
        $provenance['demand.clusters'] = DataSourceState::Unavailable->value;
        $provenance['demand.momentum'] = DataSourceState::Unavailable->value;
        $provenance['demand.ownership'] = DataSourceState::Unavailable->value;

        $data['pages']['directory'] = $this->realPagesDirectory($digitalAssetId, $externalResourceId, $siteUrl, $pageGate);
        $data['page_pulse'] = $data['pages']['directory'];
        $provenance['pages.directory'] = $pageGate->dataSourceState()->value;
        $provenance['page_pulse'] = $pageGate->dataSourceState()->value;

        $data['indexing'] = $this->realIndexing($digitalAssetId, $siteUrl, $sitemapGate, $inspectionGate);
        $provenance['indexing.coverage'] = DataSourceState::Unavailable->value;
        $provenance['indexing.urls'] = $inspectionGate->isUsable() ? DataSourceState::ProviderLimited->value : DataSourceState::Unavailable->value;
        $provenance['indexing.sitemaps'] = $sitemapGate->dataSourceState()->value;
        $provenance['indexing.reconciliation'] = DataSourceState::Unavailable->value;

        $data['discoverability'] = $this->unavailableDiscoverability();
        $provenance['discoverability'] = DataSourceState::Unavailable->value;
        $data['search_momentum'] = ['note' => 'Unavailable — search momentum heuristics are not computed on real GSC data.'];
        $data['needs_attention'] = [];
        $data['opportunities'] = [];
        $provenance['search_momentum'] = DataSourceState::Unavailable->value;
        $provenance['needs_attention'] = DataSourceState::Unavailable->value;
        $provenance['opportunities'] = DataSourceState::Unavailable->value;

        $data['relationships']['technical_connection'] = $this->realTechnicalConnection($binding);
        $data['relationships']['narrative'] = null;
        $provenance['relationships.technical_connection'] = DataSourceState::Real->value;
        $provenance['relationships.narrative'] = DataSourceState::Unavailable->value;

        $gates = [
            self::DATASET_PROPERTY_DAILY => $propertyGate,
            self::DATASET_QUERY_DAILY => $queryGate,
            self::DATASET_PAGE_DAILY => $pageGate,
            self::DATASET_DEVICE_DAILY => $deviceGate,
            self::DATASET_COUNTRY_DAILY => $countryGate,
            self::DATASET_SITEMAP_SNAPSHOT => $sitemapGate,
            self::DATASET_URL_INSPECTION_SNAPSHOT => $inspectionGate,
        ];
        $data['operations']['collection_state'] = $this->realCollectionState($gates);
        $data['operations']['subtitle'] = 'Collection and dataset readiness for this Search Console property. Findings live in Operations queues — not fabricated here.';
        $data['operations']['findings'] = [];
        $data['operations']['recommendations'] = [];
        $data['operations']['tasks'] = [];
        $data['operations']['outcomes'] = [];
        $provenance['operations.collection_state'] = DataSourceState::Real->value;
        $provenance['operations.findings'] = DataSourceState::Unavailable->value;

        $data['demo_boundary'] = 'Search Console workspace uses collected data. Unbacked cards stay empty. Live API calls are not made on page render.';
        $data['migration_mode'] = 'real';
        $data['data_provenance'] = $provenance;
        $data['tab_status'] = $this->rollupTabStatus($provenance);

        return $data;
    }

    /** @return array<string, mixed> */
    private function operationalWorkspace(GscBindingContext $binding, string $preset, ?string $start, ?string $end, string $migrationMode, ?string $errorMessage = null): array
    {
        $bounds = OperatorReportingPeriod::queryBounds($preset, $start, $end);
        $rangeStart = $bounds['start']->toDateString();
        $rangeEnd = $bounds['end']->toDateString();
        $prev = OperatorReportingPeriod::previousQueryBounds($preset, $start, $end);
        $reason = $errorMessage !== null ? 'query_error' : ($binding->reason ?? 'no_active_search_console_binding');
        $statusLabel = $errorMessage !== null ? 'Error' : ($binding->mode === GscBindingMode::ActionRequired ? 'Action required' : 'Not connected');
        $collectionNote = $errorMessage !== null ? 'A read error occurred building this workspace — no data is shown.' : "Search Console binding {$reason} — no collection state available.";
        $unavailableChip = ['value' => '—', 'raw' => null, 'secondary' => 'Unavailable', 'tone' => 'neutral'];
        $asset = DigitalAsset::query()->with('brand')->find($binding->digitalAssetId);

        $data = [
            'period_label' => $bounds['label'], 'period_days' => $bounds['days'], 'period_start' => $rangeStart, 'period_end' => $rangeEnd,
            'compare_label' => 'vs '.$prev['label'],
            'demo_boundary' => 'Search Console workspace has no usable property binding. Live API calls are not made on page render.',
            'identity' => [
                'eyebrow' => 'Google Search Console',
                'title' => $errorMessage !== null ? (($asset?->name ?? 'Search Console').' — read error') : (($asset?->name ?? 'Search Console').' — not connected'),
                'brand' => $asset?->brand?->name, 'brand_id' => $asset?->brand_id, 'brand_name' => $asset?->brand?->name ?? '—',
                'website_asset_id' => null, 'ga4_asset_id' => null, 'google_ads_asset_id' => null, 'gbp_asset_id' => null, 'gsc_asset_id' => $binding->assetId,
                'relationship_line' => 'Not connected — no Search Console property is bound.', 'property_label' => null, 'property_type' => null,
                'status' => $statusLabel, 'freshness' => 'Not collected', 'reporting_timezone' => null,
            ],
            'freshness' => [],
            'glance' => ['clicks' => $unavailableChip, 'impressions' => $unavailableChip, 'ctr' => $unavailableChip, 'search_attention' => $unavailableChip],
            'needs_attention' => [],
            'performance_trend' => ['labels' => [], 'clicks' => [], 'impressions' => [], 'note' => 'Unavailable — '.$reason],
            'metric_series' => ['labels' => [], 'clicks' => [], 'impressions' => [], 'ctr' => [], 'position' => []],
            'search_momentum' => ['note' => 'Unavailable — '.$reason], 'page_pulse' => [],
            'discoverability' => ['subtitle' => 'Unavailable — '.$reason, 'stages' => [], 'note' => 'Unavailable'],
            'opportunities' => [], 'recent_outcomes' => [],
            'performance' => ['devices' => [], 'countries' => [], 'brand_nonbrand' => ['source' => 'Unavailable', 'note' => 'Unavailable — '.$reason], 'diagnosis' => ['interpretation' => 'Unavailable — '.$reason]],
            'demand' => ['clusters' => [], 'queries' => [], 'momentum' => [], 'ownership_reviews' => [], 'observed_query_note' => GscWorkspaceFixtures::demand(GscWorkspaceFixtures::aggregateProperty($rangeStart, $rangeEnd))['observed_query_note']],
            'pages' => ['subtitle' => 'Unavailable — '.$reason, 'directory' => [], 'attribution_note' => 'GA4 context unavailable — not connected.'],
            'indexing' => ['subtitle' => 'Unavailable — '.$reason, 'coverage' => ['state' => 'Unavailable', 'note' => 'Site-wide indexing totals unavailable from GSC API.'], 'urls' => [], 'sitemaps' => [], 'reconciliation' => ['note' => 'Unavailable — '.$reason], 'inspection_note' => 'URL Inspection is a selective sample only — never extrapolated to uninspected URLs.'],
            'relationships' => ['observes' => [], 'provides_evidence_to' => [], 'technical_connection' => ['type' => 'Search Console property binding', 'property' => null, 'property_type' => null, 'status' => $statusLabel, 'note' => $errorMessage !== null ? 'Read error — retry once the underlying issue is resolved.' : 'Connect a Search Console property to this Digital Asset to enable real data.']],
            'operations' => ['subtitle' => $errorMessage !== null ? 'Search Console read error — no findings, recommendations, tasks, or outcomes available.' : 'Search Console binding required — connect a property to see findings, recommendations, tasks, and outcomes.', 'findings' => [], 'recommendations' => [], 'tasks' => [], 'outcomes' => [], 'finding_detail' => [], 'collection_state' => ['note' => $collectionNote, 'datasets' => []]],
            'narrative' => null,
            'missing_note' => 'Missing ≠ zero — Not connected / Unavailable means the signal is absent, not a measured 0. Impressions ≠ search volume.',
            'migration_mode' => $migrationMode,
            'data_provenance' => $this->allProvenance(DataSourceState::Unavailable),
        ];
        $data['tab_status'] = $this->rollupTabStatus($data['data_provenance']);
        return $data;
    }

    /** @return array<string, mixed> */
    private function realIdentity(GscBindingContext $binding, ?DigitalAsset $asset): array
    {
        $brandName = $asset?->brand?->name;
        $assetName = $asset?->name;
        $title = $assetName ?? 'Search Console property';
        $propertyType = str_starts_with((string) $binding->siteUrl, 'sc-domain:') ? 'Domain property' : 'URL-prefix property';
        return ['eyebrow' => 'Google Search Console', 'title' => "{$title} — Search Console", 'brand' => $brandName, 'brand_id' => $asset?->brand_id, 'brand_name' => $brandName, 'website_asset_id' => null, 'ga4_asset_id' => null, 'google_ads_asset_id' => null, 'gbp_asset_id' => null, 'gsc_asset_id' => $binding->assetId, 'relationship_line' => $assetName !== null ? "Observes · {$assetName}" : null, 'property_label' => $binding->siteUrl, 'property_type' => $propertyType, 'status' => 'Connected', 'freshness' => null, 'reporting_timezone' => $binding->timezone];
    }

    /** @return list<array<string, mixed>> */
    private function realFreshnessChips(array $demoChips, GscDatasetReadiness $propertyGate, string $siteUrl): array
    {
        $stateLabel = match ($propertyGate->freshnessState) {'FRESH', 'FRESH_WITH_LIMITATION' => 'current', 'STALE' => 'stale', default => 'attention'};
        $ageLabel = match ($propertyGate->freshnessState) {'FRESH', 'FRESH_WITH_LIMITATION' => 'Fresh', 'DUE' => 'Due', 'STALE' => 'Stale', 'PARTIAL' => 'Partial', 'ACTION_REQUIRED' => 'Action required', 'INTEGRITY_BLOCKED' => 'Blocked', default => 'Unknown'};
        return [['source' => 'Search Console', 'age' => $ageLabel, 'detail' => "Property {$siteUrl} · gsc_property_daily · {$propertyGate->coverageState}", 'state' => $stateLabel]];
    }

    /** @return array<string, mixed> */
    private function realGlance(GscDatasetReadiness $propertyGate, ?array $sums, ?array $prevSums, string $compareMode): array
    {
        $clicksRaw = $sums !== null ? (int) $sums['clicks'] : 0;
        $impressionsRaw = $sums !== null ? (int) $sums['impressions'] : 0;
        $ctrResult = $sums !== null ? $this->formulas->ctr($clicksRaw, $impressionsRaw) : FormulaResult::state(FormulaResult::STATE_NOT_COLLECTED);
        $positionResult = ($sums !== null && ($sums['position_impressions'] ?? 0) > 0) ? $this->formulas->impressionWeightedPosition((float) $sums['position_weighted_numerator'], (int) $sums['position_impressions']) : FormulaResult::state(FormulaResult::STATE_NOT_COLLECTED);
        $clicksDelta = ($sums !== null && $prevSums !== null) ? $this->formulas->periodRelativeChange((float) $sums['clicks'], (float) $prevSums['clicks']) : null;
        $impressionsDelta = ($sums !== null && $prevSums !== null) ? $this->formulas->periodRelativeChange((float) $sums['impressions'], (float) $prevSums['impressions']) : null;
        $prevCtr = $prevSums !== null ? $this->formulas->ctr((int) $prevSums['clicks'], (int) $prevSums['impressions']) : null;
        $ctrDeltaPp = null;
        if ($ctrResult->isValue() && $prevCtr !== null && $prevCtr->isValue()) {
            $ctrDeltaPp = round((($ctrResult->toDisplay() - $prevCtr->toDisplay()) * 100), 1);
        }
        $avgPosition = $positionResult->isValue() ? round((float) $positionResult->toDisplay(), 1) : null;
        $comparisonText = $compareMode === 'yoy' ? 'year-ago period' : 'previous period';

        if (! $propertyGate->isUsable()) {
            $note = 'Property metrics unavailable — gsc_property_daily dataset is not ready for real UI. Unavailable ≠ zero.';
            $unavailable = ['value' => '—', 'raw' => null, 'secondary' => 'Unavailable', 'tone' => 'neutral', 'note' => $note];
            return [
                'clicks' => $unavailable + ['avg_position' => null, 'position_note' => self::POSITION_PROVENANCE_NOTE],
                'impressions' => $unavailable + ['note' => $note.' Impressions are observed Search Console appearances — not search volume or total keyword universe.'],
                'ctr' => $unavailable + ['note' => $note],
                'search_attention' => ['value' => '—', 'raw' => null, 'secondary' => 'Unavailable', 'tone' => 'neutral', 'note' => 'Search attention scoring is not computed on real GSC data.'],
            ];
        }

        $clicks = ['value' => $this->formatCompact($clicksRaw), 'raw' => $clicksRaw, 'secondary' => $this->deltaSecondary($clicksDelta, $propertyGate, $compareMode).($avgPosition !== null ? ' · avg pos '.number_format($avgPosition, 1) : ''), 'tone' => 'neutral', 'avg_position' => $avgPosition, 'position_note' => self::POSITION_PROVENANCE_NOTE];
        $impressions = ['value' => $this->formatCompact($impressionsRaw), 'raw' => $impressionsRaw, 'secondary' => $this->deltaSecondary($impressionsDelta, $propertyGate, $compareMode).' · impressions (not search volume)', 'tone' => 'neutral', 'note' => 'Impressions are observed Search Console appearances — not search volume or total keyword universe.'];
        $ctr = ['value' => $ctrResult->isValue() ? number_format((float) $ctrResult->toPercentDisplay(), 2).'%' : '—', 'raw' => $ctrResult->isValue() ? (float) $ctrResult->toDisplay() : null, 'secondary' => $ctrDeltaPp !== null ? (($ctrDeltaPp > 0 ? '+' : '').$ctrDeltaPp.'pp vs '.$comparisonText.' · sum(clicks)/sum(impressions)') : 'vs '.$comparisonText.' unavailable', 'tone' => 'neutral'];
        if ($propertyGate->coverageState === GscDatasetReadiness::COVERAGE_PARTIALLY_COVERED) {
            $partial = 'Partial coverage — metrics reflect only collected days in this range.';
            $clicks['note'] = $partial;
            $impressions['note'] = $partial;
        }
        return ['clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $ctr, 'search_attention' => ['value' => '—', 'raw' => null, 'secondary' => 'Unavailable', 'tone' => 'neutral', 'note' => 'Search attention scoring is not computed on real GSC data.']];
    }

    private function deltaSecondary(?FormulaResult $delta, GscDatasetReadiness $gate, string $compareMode): string
    {
        $comparisonText = $compareMode === 'yoy' ? 'year-ago period' : 'previous period';
        if (! $gate->isUsable()) return 'Unavailable vs '.$comparisonText;
        if ($delta === null || ! $delta->isValue()) return 'vs '.$comparisonText.' unavailable';
        $pct = $delta->toPercentDisplay();
        $prefix = $pct >= 0 ? '+' : '';
        return $prefix.number_format($pct, 1).'% vs '.$comparisonText;
    }

    /** @return array<string, mixed> */
    private function realPerformanceTrend(GscDatasetReadiness $propertyGate, int $digitalAssetId, int $externalResourceId, string $siteUrl, string $timezone): array
    {
        if (! $propertyGate->isUsable() || $propertyGate->effectiveStart === null || $propertyGate->effectiveEnd === null) return ['labels' => [], 'clicks' => [], 'impressions' => [], 'note' => 'Performance trend unavailable — gsc_property_daily dataset is not ready for real UI.'];
        $series = $this->pool->propertyDailySeries($digitalAssetId, $externalResourceId, $siteUrl, $propertyGate->effectiveStart, $propertyGate->effectiveEnd);
        $labels = []; $clicks = []; $impressions = [];
        foreach ($series as $point) { $labels[] = CarbonImmutable::parse($point['date'], $timezone)->format('M j'); $clicks[] = $point['clicks']; $impressions[] = $point['impressions']; }
        $partial = $propertyGate->coverageState === GscDatasetReadiness::COVERAGE_PARTIALLY_COVERED;
        return ['labels' => $labels, 'clicks' => $clicks, 'impressions' => $impressions, 'note' => ($partial ? 'Real GSC property daily data (partial coverage). ' : 'Real GSC property daily data. ').self::POSITION_PROVENANCE_NOTE];
    }

    /** @return array{labels: list<string>, clicks: list<int>, impressions: list<int>, ctr: list<float>, position: list<float>} */
    private function realMetricSeries(GscDatasetReadiness $propertyGate, int $digitalAssetId, int $externalResourceId, string $siteUrl, string $timezone): array
    {
        if (! $propertyGate->isUsable() || $propertyGate->effectiveStart === null || $propertyGate->effectiveEnd === null) return ['labels' => [], 'clicks' => [], 'impressions' => [], 'ctr' => [], 'position' => []];
        $series = $this->pool->propertyDailySeries($digitalAssetId, $externalResourceId, $siteUrl, $propertyGate->effectiveStart, $propertyGate->effectiveEnd);
        $labels = []; $clicks = []; $impressions = []; $ctr = []; $position = [];
        foreach ($series as $point) {
            $labels[] = CarbonImmutable::parse($point['date'], $timezone)->format('M j'); $clicks[] = $point['clicks']; $impressions[] = $point['impressions'];
            $dayCtr = $this->formulas->ctr($point['clicks'], $point['impressions']); $ctr[] = $dayCtr->isValue() ? round((float) $dayCtr->toDisplay(), 4) : 0.0;
            $dayPosition = ($point['position'] !== null && $point['impressions'] > 0) ? (float) $point['position'] : null; $position[] = $dayPosition ?? 0.0;
        }
        return ['labels' => $labels, 'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $ctr, 'position' => $position];
    }

    /** @return array<string, mixed> */
    private function realPerformance(int $digitalAssetId, int $externalResourceId, string $siteUrl, GscDatasetReadiness $deviceGate, GscDatasetReadiness $countryGate): array
    {
        $devices = [];
        if ($deviceGate->isUsable() && $deviceGate->effectiveStart !== null && $deviceGate->effectiveEnd !== null) {
            foreach ($this->pool->devices($digitalAssetId, $externalResourceId, $siteUrl, $deviceGate->effectiveStart, $deviceGate->effectiveEnd) as $row) {
                $ctr = $this->formulas->ctr($row['clicks'], $row['impressions']);
                $position = ($row['position_impressions'] ?? 0) > 0 ? $this->formulas->impressionWeightedPosition((float) $row['position_weighted_numerator'], (int) $row['position_impressions']) : FormulaResult::state(FormulaResult::STATE_NOT_COLLECTED);
                $devices[] = ['device' => $this->humanizeDevice((string) $row['device']), 'clicks' => $row['clicks'], 'impressions' => $row['impressions'], 'ctr' => $ctr->isValue() ? round((float) $ctr->toPercentDisplay(), 1) : null, 'position' => $position->isValue() ? round((float) $position->toDisplay(), 1) : null, 'position_note' => self::POSITION_PROVENANCE_NOTE];
            }
        }
        $countries = [];
        if ($countryGate->isUsable() && $countryGate->effectiveStart !== null && $countryGate->effectiveEnd !== null) {
            foreach ($this->pool->countries($digitalAssetId, $externalResourceId, $siteUrl, $countryGate->effectiveStart, $countryGate->effectiveEnd) as $row) $countries[] = ['country' => strtoupper((string) $row['country']), 'clicks' => $row['clicks'], 'impressions' => $row['impressions'], 'note' => 'Impressions are observed appearances — not search volume.'];
        }
        return ['devices' => $devices, 'countries' => $countries, 'brand_nonbrand' => ['note' => 'Unavailable — brand vs non-brand query classification is not configured for real GSC data.'], 'diagnosis' => ['interpretation' => 'Unavailable — performance diagnosis heuristics are not computed on real GSC data.']];
    }

    /** @return array<string, mixed> */
    private function realDemand(int $digitalAssetId, int $externalResourceId, string $siteUrl, GscDatasetReadiness $queryGate): array
    {
        $queries = [];
        if ($queryGate->isUsable() && $queryGate->effectiveStart !== null && $queryGate->effectiveEnd !== null) {
            foreach ($this->pool->topQueries($digitalAssetId, $externalResourceId, $siteUrl, $queryGate->effectiveStart, $queryGate->effectiveEnd) as $row) {
                $ctr = $this->formulas->ctr($row['clicks'], $row['impressions']);
                $position = ($row['position_impressions'] ?? 0) > 0 ? $this->formulas->impressionWeightedPosition((float) $row['position_weighted_numerator'], (int) $row['position_impressions']) : FormulaResult::state(FormulaResult::STATE_NOT_COLLECTED);
                $queries[] = ['query' => $row['query'], 'clicks' => $row['clicks'], 'impressions' => $row['impressions'], 'ctr' => $ctr->isValue() ? round((float) $ctr->toPercentDisplay(), 1) : null, 'position' => $position->isValue() ? round((float) $position->toDisplay(), 1) : null, 'page' => null, 'trend' => 'observed', 'completeness' => 'PROVIDER_LIMITED', 'position_note' => self::POSITION_PROVENANCE_NOTE];
            }
        }
        return ['clusters' => [], 'queries' => $queries, 'momentum' => [], 'ownership_reviews' => [], 'observed_query_note' => 'Queries observed in selected Search Console dataset — not an exhaustive keyword universe. Impressions ≠ search volume. Clusters/momentum/ownership are unavailable on the real path.'];
    }

    /** @return list<array<string, mixed>> */
    private function realPagesDirectory(int $digitalAssetId, int $externalResourceId, string $siteUrl, GscDatasetReadiness $pageGate): array
    {
        if (! $pageGate->isUsable() || $pageGate->effectiveStart === null || $pageGate->effectiveEnd === null) return [];
        $rows = $this->pool->topPages($digitalAssetId, $externalResourceId, $siteUrl, $pageGate->effectiveStart, $pageGate->effectiveEnd);
        return array_map(static fn (array $row): array => ['path' => $row['page'], 'title' => '', 'content_role' => '', 'offering' => null, 'clicks' => $row['clicks'], 'impressions' => $row['impressions'], 'ga4_context' => ['sessions' => null, 'engagement_rate' => null, 'mapped_actions' => null, 'note' => 'GA4 page context unavailable on real path — not query-attributed.'], 'website_attention' => null], $rows);
    }

    /** @return array<string, mixed> */
    private function realIndexing(int $digitalAssetId, string $siteUrl, GscDatasetReadiness $sitemapGate, GscDatasetReadiness $inspectionGate): array
    {
        $sitemaps = [];
        if ($sitemapGate->isUsable()) {
            foreach ($this->pool->sitemaps($digitalAssetId, $siteUrl) as $row) {
                $meta = is_array($row['metadata']) ? $row['metadata'] : []; $submittedTotal = 0;
                foreach ($meta['contents'] ?? [] as $content) if (is_array($content) && isset($content['submitted'])) $submittedTotal += (int) $content['submitted'];
                $sitemaps[] = ['path' => $row['path'], 'submitted' => $meta['last_submitted'] ?? null, 'last_downloaded' => $meta['last_downloaded'] ?? null, 'discovered' => $submittedTotal > 0 ? $submittedTotal : null, 'warnings' => $meta['warnings'] ?? 0, 'errors' => $meta['errors'] ?? 0, 'status' => ($meta['errors'] ?? 0) > 0 ? 'Errors' : 'Success', 'note' => 'Submitted URL count ≠ indexed URL count — deprecated indexed field is never used.'];
            }
        }
        $urls = [];
        if ($inspectionGate->isUsable()) {
            foreach ($this->pool->urlInspectionSamples($digitalAssetId, $siteUrl) as $row) {
                $meta = is_array($row['metadata']) ? $row['metadata'] : []; $userCanonical = is_string($meta['user_canonical'] ?? null) ? $meta['user_canonical'] : null; $googleCanonical = is_string($meta['google_canonical'] ?? null) ? $meta['google_canonical'] : null;
                $urls[] = ['id' => 'url-'.Str::slug($row['page']), 'path' => $this->pathFromUrl($row['page']), 'url' => $row['page'], 'role' => '', 'sitemap' => is_array($meta['sitemap'] ?? null) && ($meta['sitemap'] ?? []) !== [] ? 'Present' : 'Unknown', 'index_state' => $meta['coverage_state'] ?? 'Unavailable', 'last_crawl' => $meta['last_crawl_time'] ?? null, 'canonical' => ($userCanonical !== null && $googleCanonical !== null && $userCanonical !== $googleCanonical) ? 'Mismatch' : ($userCanonical !== null || $googleCanonical !== null ? 'Match' : 'Unknown'), 'user_canonical' => $userCanonical, 'google_canonical' => $googleCanonical, 'search_visibility' => 'Sample only', 'attention' => null, 'sample_note' => 'URL Inspection selective sample — not extrapolated to uninspected URLs.'];
            }
        }
        return ['subtitle' => 'Google index state and sitemap metadata — read-only observation from pool snapshots.', 'coverage' => ['indexed' => null, 'not_indexed' => null, 'unknown' => null, 'excluded' => null, 'state' => 'Unavailable', 'note' => 'Site-wide Page Indexing totals are unavailable from the GSC API — not substituted with Demo numbers.'], 'urls' => $urls, 'sitemaps' => $sitemaps, 'reconciliation' => ['website_urls' => null, 'sitemap_urls' => null, 'index_observed' => null, 'priority_missing_sitemap' => null, 'gaps' => [], 'note' => 'Site-wide reconciliation including index_observed totals is unavailable on the real path.'], 'discoverability_by_role' => [], 'inspection_note' => 'URL Inspection is a selective sample only — never extrapolated to uninspected URLs. No inspection record ≠ not indexed.'];
    }

    private function unavailableDiscoverability(): array { return ['subtitle' => 'Site-wide discoverability funnel unavailable on real path — GSC API cannot supply full index coverage totals.', 'stages' => [], 'note' => 'GA4 actions are page-attributed — GSC cannot prove query→conversion. Index observed site-wide totals remain unavailable.']; }
    private function realCollectionState(array $gates): array { return ['note' => 'Real GSC collection/materialization/freshness/integrity/coverage state. Findings, Recommendations, Tasks and Outcomes below remain Demo — this migration creates no Evidence/Findings/Opportunities.', 'datasets' => array_map(static fn (GscDatasetReadiness $g): array => $g->toArray(), $gates)]; }
    private function realTechnicalConnection(GscBindingContext $binding): array { $propertyType = str_starts_with((string) $binding->siteUrl, 'sc-domain:') ? 'Domain property' : 'URL-prefix property'; return ['type' => 'Search Console property binding', 'property' => $binding->siteUrl, 'property_type' => $propertyType, 'status' => 'Connected', 'note' => 'Real binding · CoreAssetBinding #'.$binding->coreAssetBindingId]; }
    private function humanizeDevice(string $device): string { return match (strtoupper($device)) {'MOBILE' => 'Mobile', 'DESKTOP' => 'Desktop', 'TABLET' => 'Tablet', default => Str::title(strtolower($device))}; }
    private function pathFromUrl(string $url): string { $path = parse_url($url, PHP_URL_PATH); return is_string($path) && $path !== '' ? $path : '/'; }
    private function formatCompact(int $value): string { if ($value >= 1_000_000) return number_format($value / 1_000_000, 1).'M'; if ($value >= 1_000) return number_format($value / 1_000, 1).'K'; return number_format($value); }
    private function allProvenance(DataSourceState $state): array { return array_fill_keys(self::PROVENANCE_FIELDS, $state->value); }
    private function rollupTabStatus(array $provenance): array
    {
        $status = [];
        foreach (self::TAB_FIELD_MAP as $tab => $fields) {
            $values = array_values(array_unique(array_map(static fn (string $field): string => $provenance[$field] ?? DataSourceState::Unavailable->value, $fields)));
            $status[$tab] = match (true) { $values === [DataSourceState::Real->value] => 'REAL', $values === [DataSourceState::Demo->value] => 'DEMO', $values === [DataSourceState::Unavailable->value] => 'UNAVAILABLE', default => 'PARTIAL' };
        }
        return $status;
    }
}
