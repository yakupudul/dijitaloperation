<?php

namespace App\Services\Ga4;

use App\Enums\DataPool\DataSourceState;
use App\Models\DigitalAsset;
use App\Services\Formulas\Ga4FormulaCalculator;
use App\Services\Formulas\Support\FormulaResult;
use App\Services\Ga4\Support\Ga4BindingContext;
use App\Services\Ga4\Support\Ga4BindingMode;
use App\Services\Ga4\Support\Ga4DatasetReadiness;
use App\Support\Demo\Ga4WorkspaceFixtures;
use App\Support\Operator\OperatorReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GA4 real-data read path. Real workspaces read persisted Data Pool facts only.
 * Missing optional metrics stay unavailable rather than becoming measured zero.
 */
final class Ga4SpecialistReadService
{
    private const string DATASET_PROPERTY_METADATA = 'ga4_property_metadata';
    private const string DATASET_PROPERTY_DAILY = 'ga4_property_daily';
    private const string DATASET_ACQUISITION_CHANNEL = 'ga4_acquisition_channel_daily';
    private const string DATASET_SOURCE_MEDIUM = 'ga4_source_medium_daily';
    private const string DATASET_CAMPAIGN = 'ga4_campaign_daily';
    private const string DATASET_LANDING_PAGE = 'ga4_landing_page_daily';
    private const string DATASET_EVENT = 'ga4_event_daily';
    private const string DATASET_DEVICE = 'ga4_device_daily';

    /** @var list<string> */
    private const array PROVENANCE_FIELDS = [
        'identity.property_id', 'identity.timezone', 'freshness.ga4', 'glance.users',
        'glance.sessions', 'glance.new_users', 'glance.conversions', 'glance.revenue',
        'glance.business_actions', 'glance.measurement_state', 'performance_trend.sessions',
        'performance_trend.business_actions', 'acquisition.channels', 'acquisition.source_medium',
        'acquisition.campaigns', 'behavior.landing_pages', 'behavior.engagement', 'behavior.devices',
        'measurement.events', 'measurement.streams', 'measurement.business_actions',
        'measurement.utm_hygiene', 'journeys', 'needs_attention', 'relationships.technical_connection',
        'relationships.narrative', 'operations.collection_state', 'operations.findings', 'opportunities',
    ];

    /** @var array<string, list<string>> */
    private const array TAB_FIELD_MAP = [
        'overview' => ['glance.sessions', 'glance.users', 'glance.business_actions', 'acquisition.channels', 'behavior.landing_pages', 'needs_attention'],
        'measurement' => ['measurement.events', 'measurement.streams', 'measurement.business_actions', 'measurement.utm_hygiene'],
        'acquisition' => ['acquisition.channels', 'acquisition.source_medium', 'acquisition.campaigns'],
        'behavior' => ['behavior.landing_pages', 'behavior.engagement', 'behavior.devices'],
        'journeys' => ['journeys'],
        'operations' => ['operations.collection_state', 'operations.findings'],
    ];

    public function __construct(
        private readonly Ga4SpecialistBindingResolver $bindingResolver,
        private readonly Ga4UiDatasetGate $gate,
        private readonly Ga4PoolReadRepository $pool,
        private readonly Ga4FormulaCalculator $formulas,
    ) {}

    /** @return array<string, mixed> */
    public function workspace(string $assetId, string $preset = 'last_28', ?string $start = null, ?string $end = null, string $compareMode = 'previous'): array
    {
        $binding = $this->bindingResolver->resolve($assetId);
        if ($binding->mode === Ga4BindingMode::DemoCatalog) return $this->demoWorkspace($preset, $start, $end);
        if ($binding->mode !== Ga4BindingMode::RealBound) return $this->operationalWorkspace($binding, $preset, $start, $end, 'not_connected');

        try {
            return $this->buildRealWorkspace($binding, $preset, $start, $end, $compareMode);
        } catch (Throwable $e) {
            Log::error('ga4.read_service.real_workspace_failed', ['digital_asset_id' => $binding->digitalAssetId, 'external_resource_id' => $binding->externalResourceId, 'error' => $e->getMessage()]);
            return $this->operationalWorkspace($binding, $preset, $start, $end, 'real', $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function demoWorkspace(string $preset, ?string $start, ?string $end): array
    {
        $data = Ga4WorkspaceFixtures::workspace($preset, $start, $end);
        $data['migration_mode'] = 'demo_catalog';
        $data['data_provenance'] = $this->allProvenance(DataSourceState::Demo);
        $data['tab_status'] = array_fill_keys(array_keys(self::TAB_FIELD_MAP), DataSourceState::Demo->value);
        return $data;
    }

    /** @return array<string, mixed> */
    private function buildRealWorkspace(Ga4BindingContext $binding, string $preset, ?string $start, ?string $end, string $compareMode = 'previous'): array
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
        $propertyId = (string) $binding->propertyId;
        $timezone = $binding->timezone;
        $provenance = $this->allProvenance(DataSourceState::Demo);

        $data = Ga4WorkspaceFixtures::workspace($preset, $start, $end);
        $data['period_label'] = $bounds['label'];
        $data['period_days'] = $bounds['days'];
        $data['period_start'] = $rangeStart;
        $data['period_end'] = $rangeEnd;
        $data['compare_label'] = 'vs '.$prev['label'];
        $data['compare_mode'] = $compareMode;

        $propertyMetaGate = $this->gate->evaluateSnapshot($digitalAssetId, $externalResourceId, self::DATASET_PROPERTY_METADATA, $timezone);
        $propertyMeta = $propertyMetaGate->isUsable() ? $this->pool->propertyMetadata($digitalAssetId, $propertyId) : null;
        $dailyGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_PROPERTY_DAILY, $rangeStart, $rangeEnd, $timezone);
        $prevDailyGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_PROPERTY_DAILY, $prevStart, $prevEnd, $timezone);
        $channelGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_ACQUISITION_CHANNEL, $rangeStart, $rangeEnd, $timezone);
        $sourceMediumGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_SOURCE_MEDIUM, $rangeStart, $rangeEnd, $timezone);
        $campaignGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_CAMPAIGN, $rangeStart, $rangeEnd, $timezone);
        $landingGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_LANDING_PAGE, $rangeStart, $rangeEnd, $timezone);
        $eventGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_EVENT, $rangeStart, $rangeEnd, $timezone);
        $deviceGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_DEVICE, $rangeStart, $rangeEnd, $timezone);

        $sums = $dailyGate->isUsable() ? $this->pool->propertyDailySums($digitalAssetId, $externalResourceId, $propertyId, $dailyGate->effectiveStart, $dailyGate->effectiveEnd) : null;
        $prevSums = $prevDailyGate->isUsable() ? $this->pool->propertyDailySums($digitalAssetId, $externalResourceId, $propertyId, $prevDailyGate->effectiveStart, $prevDailyGate->effectiveEnd) : null;
        $asset = DigitalAsset::query()->with('brand')->find($digitalAssetId);

        $data['identity'] = $this->realIdentity($binding, $asset, $propertyMeta);
        $provenance['identity.property_id'] = DataSourceState::Real->value;
        $provenance['identity.timezone'] = DataSourceState::Real->value;
        $data['freshness'] = $this->realFreshnessChips($dailyGate, $propertyId);
        $provenance['freshness.ga4'] = DataSourceState::Real->value;

        $data['glance'] = $this->realGlance($dailyGate, $sums, $prevSums, $compareMode);
        $provenance['glance.sessions'] = $dailyGate->dataSourceState()->value;
        $provenance['glance.users'] = DataSourceState::Unavailable->value;
        $provenance['glance.new_users'] = $dailyGate->isUsable() && ($sums['newUsers'] ?? null) !== null ? $dailyGate->dataSourceState()->value : DataSourceState::Unavailable->value;
        $provenance['glance.conversions'] = $dailyGate->isUsable() && (($sums['keyEvents'] ?? null) !== null || ($sums['conversions'] ?? null) !== null) ? $dailyGate->dataSourceState()->value : DataSourceState::Unavailable->value;
        $provenance['glance.revenue'] = $dailyGate->isUsable() && ($sums['totalRevenue'] ?? null) !== null ? $dailyGate->dataSourceState()->value : DataSourceState::Unavailable->value;
        $provenance['glance.business_actions'] = DataSourceState::Unavailable->value;
        $provenance['glance.measurement_state'] = DataSourceState::Unavailable->value;

        $data['performance_trend'] = $this->realPerformanceTrend($dailyGate, $digitalAssetId, $externalResourceId, $propertyId);
        $provenance['performance_trend.sessions'] = $dailyGate->dataSourceState()->value;
        $provenance['performance_trend.business_actions'] = DataSourceState::Unavailable->value;

        $data['acquisition'] = $this->realAcquisition($digitalAssetId, $externalResourceId, $propertyId, $channelGate, $sourceMediumGate, $campaignGate);
        $data['acquisition_mix'] = $data['acquisition']['channels'];
        $provenance['acquisition.channels'] = $channelGate->dataSourceState()->value;
        $provenance['acquisition.source_medium'] = $sourceMediumGate->dataSourceState()->value;
        $provenance['acquisition.campaigns'] = $campaignGate->dataSourceState()->value;

        $data['behavior'] = $this->realBehavior($digitalAssetId, $externalResourceId, $propertyId, $landingGate, $dailyGate, $deviceGate, $sums);
        $data['landing_pulse'] = $data['behavior']['landing_pages'];
        $provenance['behavior.landing_pages'] = $landingGate->dataSourceState()->value;
        $provenance['behavior.engagement'] = $dailyGate->dataSourceState()->value;
        $provenance['behavior.devices'] = $deviceGate->dataSourceState()->value;

        $data['measurement']['events'] = $this->realMeasurementEvents($digitalAssetId, $externalResourceId, $propertyId, $eventGate);
        $data['measurement']['streams'] = $this->realStreams($propertyMeta);
        $data['measurement']['utm_hygiene'] = $this->realUtmHygiene($digitalAssetId, $externalResourceId, $propertyId, $campaignGate, $sums);
        $data['measurement']['subtitle'] = 'Real GA4 events and streams for this property. Business action mapping is unavailable — no mapping store is configured.';
        $data['measurement']['business_actions'] = [];
        $provenance['measurement.events'] = $eventGate->dataSourceState()->value;
        $provenance['measurement.streams'] = $propertyMetaGate->dataSourceState()->value;
        $provenance['measurement.business_actions'] = DataSourceState::Unavailable->value;
        $provenance['measurement.utm_hygiene'] = $campaignGate->dataSourceState()->value;

        $data['journeys'] = [];
        $provenance['journeys'] = DataSourceState::Unavailable->value;
        $data['needs_attention'] = [];
        $data['opportunities'] = [];
        $data['business_actions'] = [];
        $data['narrative'] = null;
        $provenance['needs_attention'] = DataSourceState::Unavailable->value;
        $provenance['opportunities'] = DataSourceState::Unavailable->value;

        $data['relationships']['technical_connection'] = $this->realTechnicalConnection($binding, $propertyMeta);
        $data['relationships']['measures'] = [];
        $data['relationships']['provides_evidence_to'] = [];
        $provenance['relationships.technical_connection'] = DataSourceState::Real->value;
        $provenance['relationships.narrative'] = DataSourceState::Unavailable->value;

        $gates = [self::DATASET_PROPERTY_METADATA => $propertyMetaGate, self::DATASET_PROPERTY_DAILY => $dailyGate, self::DATASET_ACQUISITION_CHANNEL => $channelGate, self::DATASET_SOURCE_MEDIUM => $sourceMediumGate, self::DATASET_CAMPAIGN => $campaignGate, self::DATASET_LANDING_PAGE => $landingGate, self::DATASET_EVENT => $eventGate, self::DATASET_DEVICE => $deviceGate];
        $data['operations']['collection_state'] = $this->realCollectionState($gates);
        $data['operations']['subtitle'] = 'Collection and dataset readiness for this GA4 property. Findings and Recommendations live in Operations queues — not fabricated here.';
        $data['operations']['findings'] = [];
        $data['operations']['recommendations'] = [];
        $data['operations']['tasks'] = [];
        $data['operations']['outcomes'] = [];
        $provenance['operations.collection_state'] = DataSourceState::Real->value;
        $provenance['operations.findings'] = DataSourceState::Unavailable->value;

        $data['demo_boundary'] = 'GA4 workspace uses collected data. Unbacked cards stay empty. Live API calls are not made on page render.';
        $data['migration_mode'] = 'real';
        $data['data_provenance'] = $provenance;
        $data['tab_status'] = $this->rollupTabStatus($provenance);
        return $data;
    }

    /** @return array<string, mixed> */
    private function operationalWorkspace(Ga4BindingContext $binding, string $preset, ?string $start, ?string $end, string $migrationMode, ?string $errorMessage = null): array
    {
        $bounds = OperatorReportingPeriod::queryBounds($preset, $start, $end);
        $rangeStart = $bounds['start']->toDateString(); $rangeEnd = $bounds['end']->toDateString();
        $prev = OperatorReportingPeriod::previousQueryBounds($preset, $start, $end);
        $reason = $errorMessage !== null ? 'query_error' : ($binding->reason ?? 'no_active_ga4_binding');
        $statusLabel = $errorMessage !== null ? 'Error' : ($binding->mode === Ga4BindingMode::ActionRequired ? 'Action required' : 'Not connected');
        $collectionNote = $errorMessage !== null ? 'A read error occurred building this workspace — no data is shown.' : "GA4 binding {$reason} — no collection state available.";
        $unavailableChip = ['value' => '—', 'raw' => null, 'secondary' => 'Unavailable', 'tone' => 'neutral'];
        $asset = DigitalAsset::query()->with('brand')->find($binding->digitalAssetId);
        $data = [
            'period_label' => $bounds['label'], 'period_days' => $bounds['days'], 'period_start' => $rangeStart, 'period_end' => $rangeEnd, 'compare_label' => 'vs '.$prev['label'],
            'demo_boundary' => 'GA4 workspace has no usable property binding. Live API calls are not made on page render.',
            'identity' => ['eyebrow' => 'Google Analytics', 'title' => $errorMessage !== null ? (($asset?->name ?? 'GA4').' — read error') : (($asset?->name ?? 'GA4').' — not connected'), 'brand' => $asset?->brand?->name, 'brand_id' => $asset?->brand_id, 'brand_name' => $asset?->brand?->name ?? '—', 'website_asset_id' => null, 'google_ads_asset_id' => null, 'meta_asset_id' => null, 'ga4_asset_id' => $binding->assetId, 'relationship_line' => 'Not connected — no GA4 property is bound.', 'status' => $statusLabel, 'freshness' => 'Not collected', 'reporting_timezone' => null, 'property_id' => null, 'measurement_id' => null, 'property_name' => $asset?->name, 'stream_name' => null],
            'freshness' => [],
            'glance' => ['users' => $unavailableChip, 'sessions' => $unavailableChip, 'new_users' => $unavailableChip, 'conversions' => $unavailableChip, 'revenue' => $unavailableChip, 'business_actions' => $unavailableChip, 'measurement_state' => ['value' => 'Unavailable', 'secondary' => $reason, 'tone' => 'neutral']],
            'needs_attention' => [], 'performance_trend' => ['labels' => [], 'sessions' => [], 'business_actions' => [], 'note' => 'Unavailable — '.$reason], 'acquisition_mix' => [], 'landing_pulse' => [], 'business_actions' => [],
            'measurement' => ['subtitle' => 'Unavailable — '.$reason, 'missing_note' => 'Not mapped / Unavailable ≠ measured zero.', 'business_actions' => [], 'events' => [], 'streams' => [], 'data_quality' => [], 'interruptions' => [], 'duplicates' => [], 'utm_hygiene' => ['unavailable_pct' => null, 'prior_unavailable_pct' => null, 'unavailable_sessions' => null, 'trend' => null, 'note' => 'Unavailable — '.$reason, 'finding_id' => null], 'referrals' => [], 'trust_chips' => []],
            'acquisition' => ['channels' => [], 'source_medium' => [], 'campaigns' => [], 'utm_note' => 'Unavailable — '.$reason],
            'behavior' => ['subtitle' => 'Unavailable — '.$reason, 'landing_pages' => [], 'engagement' => [], 'devices' => []], 'journeys' => [],
            'relationships' => ['measures' => [], 'provides_evidence_to' => [], 'technical_connection' => ['type' => 'GA4 property binding', 'property_id' => null, 'measurement_id' => null, 'status' => $statusLabel, 'note' => $errorMessage !== null ? 'Read error — retry once the underlying issue is resolved.' : 'Connect a GA4 property to this Digital Asset to enable real data.']],
            'operations' => ['subtitle' => $errorMessage !== null ? 'GA4 read error — no findings, recommendations, tasks, or outcomes available.' : 'GA4 binding required — connect a property to see findings, recommendations, tasks, and outcomes.', 'findings' => [], 'recommendations' => [], 'tasks' => [], 'outcomes' => [], 'finding_detail' => [], 'collection_state' => ['note' => $collectionNote, 'datasets' => []]],
            'recent_outcomes' => [], 'opportunities' => [], 'narrative' => null, 'missing_note' => 'Missing ≠ zero — Not connected / Unavailable means the signal is absent, not a measured 0.', 'migration_mode' => $migrationMode, 'data_provenance' => $this->allProvenance(DataSourceState::Unavailable),
        ];
        $data['tab_status'] = $this->rollupTabStatus($data['data_provenance']); return $data;
    }

    private function realIdentity(Ga4BindingContext $binding, ?DigitalAsset $asset, ?array $propertyMeta): array
    {
        $metaJson = is_array($propertyMeta['metadata'] ?? null) ? $propertyMeta['metadata'] : [];
        $propertyName = is_string($metaJson['display_name'] ?? null) ? $metaJson['display_name'] : null;
        $primaryStream = $this->primaryDataStream($metaJson);
        $measurementId = is_string($primaryStream['measurement_id'] ?? null) ? $primaryStream['measurement_id'] : null;
        $streamName = is_string($primaryStream['name'] ?? null) ? $primaryStream['name'] : null;
        $brandName = $asset?->brand?->name; $assetName = $asset?->name; $title = $propertyName ?? $assetName ?? 'GA4 property';
        return ['eyebrow' => 'Google Analytics', 'title' => "{$title} — GA4", 'brand' => $brandName, 'brand_id' => $asset?->brand_id, 'brand_name' => $brandName, 'website_asset_id' => null, 'google_ads_asset_id' => null, 'meta_asset_id' => null, 'ga4_asset_id' => $binding->assetId, 'relationship_line' => $assetName !== null ? "Measures · {$assetName}" : null, 'status' => 'Connected', 'freshness' => null, 'reporting_timezone' => $binding->timezone, 'property_id' => $binding->propertyId, 'measurement_id' => $measurementId, 'property_name' => $propertyName ?? $assetName, 'stream_name' => $streamName];
    }

    private function realFreshnessChips(Ga4DatasetReadiness $dailyGate, string $propertyId): array
    {
        $stateLabel = match ($dailyGate->freshnessState) {'FRESH', 'FRESH_WITH_LIMITATION' => 'current', 'STALE' => 'stale', default => 'attention'};
        $ageLabel = match ($dailyGate->freshnessState) {'FRESH', 'FRESH_WITH_LIMITATION' => 'Fresh', 'DUE' => 'Due', 'STALE' => 'Stale', 'PARTIAL' => 'Partial', 'ACTION_REQUIRED' => 'Action required', 'INTEGRITY_BLOCKED' => 'Blocked', default => 'Unknown'};
        return [['source' => 'GA4', 'age' => $ageLabel, 'detail' => "Property {$propertyId} · ga4_property_daily · {$dailyGate->coverageState}", 'state' => $stateLabel]];
    }

    private function realGlance(Ga4DatasetReadiness $dailyGate, ?array $sums, ?array $prevSums, string $compareMode): array
    {
        $comparisonText = $compareMode === 'yoy' ? 'year-ago period' : 'previous period';
        if (! $dailyGate->isUsable()) {
            $unavailable = ['value' => '—', 'raw' => null, 'secondary' => 'Unavailable', 'tone' => 'neutral'];
            return ['users' => $unavailable + ['note' => 'GA4 unique users are non-additive.'], 'sessions' => $unavailable + ['note' => 'Sessions unavailable — ga4_property_daily dataset is not ready for real UI.'], 'new_users' => $unavailable + ['note' => 'New users unavailable — missing is not zero.'], 'conversions' => $unavailable + ['note' => 'Key events / conversions unavailable — missing is not zero.'], 'revenue' => $unavailable + ['note' => 'Revenue unavailable — missing is not zero.'], 'business_actions' => $unavailable + ['note' => 'Business action mapping is not configured for this property.'], 'measurement_state' => ['value' => 'Unavailable', 'secondary' => 'No business-action mapping store', 'tone' => 'neutral']];
        }

        $sessionsRaw = $sums !== null ? (int) $sums['sessions'] : 0;
        $delta = ($sums !== null && $prevSums !== null) ? $this->formulas->periodRelativeChange((float) $sums['sessions'], (float) $prevSums['sessions']) : null;
        $sessions = ['value' => number_format($sessionsRaw), 'raw' => $sessionsRaw, 'secondary' => $this->deltaSecondary($delta, $dailyGate, $compareMode), 'tone' => 'neutral'];
        if ($dailyGate->coverageState === Ga4DatasetReadiness::COVERAGE_PARTIALLY_COVERED) $sessions['note'] = 'Partial coverage — sessions reflect only collected days in this range.';

        $users = ['value' => '—', 'raw' => null, 'secondary' => 'Unavailable · not additive', 'tone' => 'neutral', 'note' => 'GA4 unique users cannot be summed across days into a period total — showing Unavailable rather than an inflated/incorrect sum.'];

        $newUsersValue = $sums['newUsers'] ?? null;
        $newUsers = $newUsersValue === null
            ? ['value' => '—', 'raw' => null, 'secondary' => 'Unavailable', 'tone' => 'neutral', 'note' => 'New users was not collected for this property/period. Missing ≠ zero.']
            : ['value' => number_format((int) $newUsersValue), 'raw' => (int) $newUsersValue, 'secondary' => 'New users (additive)', 'tone' => 'neutral'];

        $conversionMetric = ($sums['keyEvents'] ?? null) !== null ? 'keyEvents' : ((($sums['conversions'] ?? null) !== null) ? 'conversions' : null);
        $conversionValue = $conversionMetric !== null ? (float) $sums[$conversionMetric] : null;
        $conversions = $conversionValue === null
            ? ['value' => '—', 'raw' => null, 'secondary' => 'Unavailable', 'tone' => 'neutral', 'note' => 'Neither keyEvents nor conversions was collected for this property/period. Missing ≠ zero.']
            : ['value' => $this->formatOptionalDecimal($conversionValue), 'raw' => $conversionValue, 'secondary' => $conversionMetric === 'keyEvents' ? 'Key events' : 'Conversions', 'tone' => 'neutral'];

        $revenueValue = $sums['totalRevenue'] ?? null;
        $revenue = $revenueValue === null
            ? ['value' => '—', 'raw' => null, 'secondary' => 'Unavailable', 'tone' => 'neutral', 'note' => 'totalRevenue was not collected for this property/period. Missing ≠ zero.']
            : ['value' => number_format((float) $revenueValue, 2), 'raw' => (float) $revenueValue, 'secondary' => 'totalRevenue', 'tone' => 'neutral'];

        $unavailableChip = ['value' => '—', 'raw' => null, 'secondary' => 'Unavailable', 'tone' => 'neutral', 'note' => 'Business action mapping is not configured for this property.'];
        return ['users' => $users, 'sessions' => $sessions, 'new_users' => $newUsers, 'conversions' => $conversions, 'revenue' => $revenue, 'business_actions' => $unavailableChip, 'measurement_state' => ['value' => 'Unavailable', 'secondary' => 'No business-action mapping store', 'tone' => 'neutral']];
    }

    private function deltaSecondary(?FormulaResult $delta, Ga4DatasetReadiness $gate, string $compareMode): string
    {
        $comparisonText = $compareMode === 'yoy' ? 'year-ago period' : 'previous period';
        if (! $gate->isUsable()) return 'Unavailable vs '.$comparisonText;
        if ($delta === null || ! $delta->isValue()) return 'vs '.$comparisonText.' unavailable';
        $pct = $delta->toPercentDisplay(); $prefix = $pct >= 0 ? '+' : '';
        return $prefix.number_format($pct, 1).'% vs '.$comparisonText;
    }

    private function formatOptionalDecimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ','), '0'), '.');
    }

    private function realPerformanceTrend(Ga4DatasetReadiness $dailyGate, int $digitalAssetId, int $externalResourceId, string $propertyId): array
    {
        if (! $dailyGate->isUsable() || $dailyGate->effectiveStart === null || $dailyGate->effectiveEnd === null) return ['labels' => [], 'sessions' => [], 'business_actions' => [], 'note' => 'Sessions trend unavailable — ga4_property_daily dataset is not ready for real UI.'];
        $series = $this->pool->propertyDailySeries($digitalAssetId, $externalResourceId, $propertyId, $dailyGate->effectiveStart, $dailyGate->effectiveEnd); $labels = []; $sessions = [];
        foreach ($series as $point) { $labels[] = CarbonImmutable::parse($point['date'])->format('M j'); $sessions[] = $point['sessions']; }
        $partial = $dailyGate->coverageState === Ga4DatasetReadiness::COVERAGE_PARTIALLY_COVERED;
        return ['labels' => $labels, 'sessions' => $sessions, 'business_actions' => [], 'note' => ($partial ? 'Sessions · real GA4 data (partial coverage). ' : 'Sessions · real GA4 data. ').'Business actions omitted — no Business Action mapping configured (not mixed with Demo on this chart).'];
    }

    private function realAcquisition(int $digitalAssetId, int $externalResourceId, string $propertyId, Ga4DatasetReadiness $channelGate, Ga4DatasetReadiness $sourceMediumGate, Ga4DatasetReadiness $campaignGate): array
    {
        $channels = [];
        if ($channelGate->isUsable() && $channelGate->effectiveStart !== null && $channelGate->effectiveEnd !== null) {
            $rows = $this->pool->acquisitionChannels($digitalAssetId, $externalResourceId, $propertyId, $channelGate->effectiveStart, $channelGate->effectiveEnd); $totalSessions = (int) array_sum(array_column($rows, 'sessions'));
            foreach ($rows as $row) { $share = $this->formulas->channelShare($row['sessions'], $totalSessions); $channels[] = ['channel' => $row['channel'], 'sessions' => $row['sessions'], 'share_pct' => $share->toPercentDisplay() ?? 0.0, 'bar' => $totalSessions > 0 ? (int) round(($row['sessions'] / $totalSessions) * 100) : 0, 'mapped_actions' => null, 'related' => null]; }
        }
        $sourceMedium = [];
        if ($sourceMediumGate->isUsable() && $sourceMediumGate->effectiveStart !== null && $sourceMediumGate->effectiveEnd !== null) foreach ($this->pool->sourceMedium($digitalAssetId, $externalResourceId, $propertyId, $sourceMediumGate->effectiveStart, $sourceMediumGate->effectiveEnd) as $row) $sourceMedium[] = ['source_medium' => $row['source_medium'], 'sessions' => $row['sessions'], 'mapped_actions' => null];
        $campaigns = [];
        if ($campaignGate->isUsable() && $campaignGate->effectiveStart !== null && $campaignGate->effectiveEnd !== null) foreach ($this->pool->campaigns($digitalAssetId, $externalResourceId, $propertyId, $campaignGate->effectiveStart, $campaignGate->effectiveEnd) as $row) $campaigns[] = ['campaign' => $row['campaign'], 'source' => null, 'sessions' => $row['sessions'], 'mapped_actions' => null, 'related_asset' => null, 'related_asset_id' => null, 'route' => null];
        $anyReady = $channelGate->isUsable() || $sourceMediumGate->isUsable() || $campaignGate->isUsable();
        return ['channels' => $channels, 'source_medium' => $sourceMedium, 'campaigns' => $campaigns, 'utm_note' => $anyReady ? 'Real GA4 acquisition data · mapped business actions Unavailable (no Business Action mapping configured).' : 'Acquisition data unavailable — GA4 acquisition datasets are not ready for real UI.'];
    }

    private function realBehavior(int $digitalAssetId, int $externalResourceId, string $propertyId, Ga4DatasetReadiness $landingGate, Ga4DatasetReadiness $dailyGate, Ga4DatasetReadiness $deviceGate, ?array $propertySums): array
    {
        $landingPages = [];
        if ($landingGate->isUsable() && $landingGate->effectiveStart !== null && $landingGate->effectiveEnd !== null) foreach ($this->pool->landingPages($digitalAssetId, $externalResourceId, $propertyId, $landingGate->effectiveStart, $landingGate->effectiveEnd) as $row) { $engagedRate = $this->formulas->engagementRate($row['engagedSessions'], $row['sessions']); $landingPages[] = ['path' => $row['path'], 'title' => '', 'content_role' => '', 'sessions' => $row['sessions'], 'engaged_sessions' => $row['engagedSessions'], 'engaged_rate' => $engagedRate->toPercentDisplay(0) ?? 0.0, 'mapped_actions' => 0, 'website_asset_id' => null, 'attention' => null]; }
        $engagement = [];
        if ($dailyGate->isUsable() && $propertySums !== null) { $rate = $this->formulas->engagementRate($propertySums['engagedSessions'], $propertySums['sessions']); $avgTime = $this->formulas->avgEngagementTime($propertySums['userEngagementDuration'], $propertySums['activeUsers']); $viewsPerSession = $this->formulas->viewsPerSession($propertySums['screenPageViews'], $propertySums['sessions']); $engagement = [['metric' => 'Engagement rate', 'value' => $rate->isValue() ? number_format((float) $rate->toPercentDisplay(), 1).'%' : 'Unavailable', 'state' => $rate->isValue() ? 'Measured' : 'Unavailable'], ['metric' => 'Avg engagement time', 'value' => $avgTime->isValue() ? $this->formatSeconds((float) $avgTime->toDisplay()) : 'Unavailable', 'state' => $avgTime->isValue() ? 'Measured' : 'Unavailable'], ['metric' => 'Views / session', 'value' => $viewsPerSession->isValue() ? number_format((float) $viewsPerSession->toDisplay(1), 1) : 'Unavailable', 'state' => $viewsPerSession->isValue() ? 'Measured' : 'Unavailable'], ['metric' => 'Appointment completion', 'value' => 'Unavailable', 'state' => 'Not mapped']]; }
        $devices = [];
        if ($deviceGate->isUsable() && $deviceGate->effectiveStart !== null && $deviceGate->effectiveEnd !== null) { $rows = $this->pool->devices($digitalAssetId, $externalResourceId, $propertyId, $deviceGate->effectiveStart, $deviceGate->effectiveEnd); $total = (int) array_sum(array_column($rows, 'sessions')); foreach ($rows as $row) { $share = $this->formulas->deviceShare($row['sessions'], $total); $devices[] = ['device' => $row['device'], 'share_pct' => (int) round($share->toPercentDisplay() ?? 0.0), 'sessions' => $row['sessions']]; } }
        return ['subtitle' => 'Landing behaviour from live GA4 data — business action mapping stays Unavailable (no mapping store configured).', 'landing_pages' => $landingPages, 'engagement' => $engagement, 'devices' => $devices];
    }

    private function formatSeconds(float $seconds): string { $minutes = (int) floor($seconds / 60); $secs = (int) round($seconds - ($minutes * 60)); return $minutes > 0 ? "{$minutes}m {$secs}s" : "{$secs}s"; }

    private function realMeasurementEvents(int $digitalAssetId, int $externalResourceId, string $propertyId, Ga4DatasetReadiness $eventGate): array
    {
        if (! $eventGate->isUsable() || $eventGate->effectiveStart === null || $eventGate->effectiveEnd === null) return [];
        return array_map(static fn (array $row): array => ['event' => $row['event'], 'count' => $row['count'], 'mapped_action' => 'Unavailable', 'state' => 'Observed'], $this->pool->events($digitalAssetId, $externalResourceId, $propertyId, $eventGate->effectiveStart, $eventGate->effectiveEnd));
    }

    private function realStreams(?array $propertyMeta): array
    {
        if ($propertyMeta === null) return [];
        $metaJson = is_array($propertyMeta['metadata'] ?? null) ? $propertyMeta['metadata'] : []; $streams = is_array($metaJson['data_streams'] ?? null) ? $metaJson['data_streams'] : [];
        if ($streams === []) return [['name' => $metaJson['display_name'] ?? 'GA4 web stream', 'stream_id' => null, 'measurement_id' => null, 'type' => 'Web', 'status' => 'Receiving', 'last_hit' => $propertyMeta['last_collected_at'] ?? null]];
        return array_map(static fn (array $stream): array => ['name' => $stream['displayName'] ?? $stream['name'] ?? 'GA4 stream', 'stream_id' => $stream['name'] ?? null, 'measurement_id' => $stream['webStreamData']['measurementId'] ?? null, 'type' => $stream['type'] ?? 'Web', 'status' => 'Receiving', 'last_hit' => $propertyMeta['last_collected_at'] ?? null], $streams);
    }

    private function primaryDataStream(array $metaJson): array
    {
        $streams = is_array($metaJson['data_streams'] ?? null) ? $metaJson['data_streams'] : []; $first = $streams[0] ?? null;
        if (! is_array($first)) return ['name' => null, 'measurement_id' => null];
        return ['name' => $first['displayName'] ?? $first['name'] ?? null, 'measurement_id' => $first['webStreamData']['measurementId'] ?? null];
    }

    private function realUtmHygiene(int $digitalAssetId, int $externalResourceId, string $propertyId, Ga4DatasetReadiness $campaignGate, ?array $propertySums): array
    {
        if (! $campaignGate->isUsable() || $propertySums === null || $campaignGate->effectiveStart === null || $campaignGate->effectiveEnd === null) return ['unavailable_pct' => null, 'prior_unavailable_pct' => null, 'unavailable_sessions' => null, 'trend' => null, 'note' => 'UTM hygiene unavailable — ga4_campaign_daily dataset is not ready for real UI.', 'finding_id' => null];
        $unavailableSessions = $this->pool->utmUnavailableSessions($digitalAssetId, $externalResourceId, $propertyId, $campaignGate->effectiveStart, $campaignGate->effectiveEnd); $totalSessions = (int) $propertySums['sessions']; $pct = $this->formulas->utmUnavailablePct($unavailableSessions, $totalSessions);
        return ['unavailable_pct' => $pct->toPercentDisplay() ?? 0.0, 'prior_unavailable_pct' => null, 'unavailable_sessions' => $unavailableSessions, 'trend' => null, 'note' => 'Real GA4 campaign data — no prior-window comparison computed.', 'finding_id' => null];
    }

    private function realCollectionState(array $gates): array { return ['note' => 'Real GA4 collection/materialization/freshness/integrity/coverage state. Findings, Recommendations, Tasks and Outcomes below remain Demo — this migration creates no Evidence/Findings/Opportunities/Business Outcomes.', 'datasets' => array_map(static fn (Ga4DatasetReadiness $g): array => $g->toArray(), $gates)]; }
    private function realTechnicalConnection(Ga4BindingContext $binding, ?array $propertyMeta): array { $metaJson = is_array($propertyMeta['metadata'] ?? null) ? $propertyMeta['metadata'] : []; $primaryStream = $this->primaryDataStream($metaJson); return ['type' => 'GA4 property binding', 'property_id' => $binding->propertyId, 'measurement_id' => $primaryStream['measurement_id'], 'status' => 'Connected', 'note' => 'Real binding · CoreAssetBinding #'.$binding->coreAssetBindingId]; }
    private function allProvenance(DataSourceState $state): array { return array_fill_keys(self::PROVENANCE_FIELDS, $state->value); }
    private function rollupTabStatus(array $provenance): array
    {
        $status = [];
        foreach (self::TAB_FIELD_MAP as $tab => $fields) { $values = array_values(array_unique(array_map(static fn (string $field): string => $provenance[$field] ?? DataSourceState::Unavailable->value, $fields))); $status[$tab] = match (true) { $values === [DataSourceState::Real->value] => 'REAL', $values === [DataSourceState::Demo->value] => 'DEMO', $values === [DataSourceState::Unavailable->value] => 'UNAVAILABLE', default => 'PARTIAL' }; }
        return $status;
    }
}
