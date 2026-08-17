<?php

namespace App\Services\GoogleAds;

use App\Enums\DataPool\DataSourceState;
use App\Models\DigitalAsset;
use App\Services\Formulas\GoogleAdsFormulaCalculator;
use App\Services\Formulas\Support\FormulaResult;
use App\Services\GoogleAds\Support\GoogleAdsBindingContext;
use App\Services\GoogleAds\Support\GoogleAdsBindingMode;
use App\Services\GoogleAds\Support\GoogleAdsDatasetReadiness;
use App\Support\Demo\DemoPeriod;
use App\Support\Demo\GoogleAdsWorkspaceFixtures;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Prompt 30 Google Ads real-data read path. Builds the frozen
 * {@see GoogleAdsWorkspaceFixtures::workspace()} array shape from the data pool + formulas
 * when a real Customer is bound, falling back to Demo fixtures only for the DEMO_CATALOG
 * asset id and for explicit residual-Demo domains (needs_attention, search intent
 * clusters/inbox, operations findings/recommendations/tasks/outcomes, opportunities,
 * measurement health narrative, business goal/pacing plan) that have no real backing store.
 *
 * Hard rules enforced here:
 * - No Google Ads API / OAuth call — everything is read from the local data pool +
 *   binding tables.
 * - No fallback to Demo on a query exception — an error yields an UNAVAILABLE
 *   operational workspace, never fixture numbers.
 * - Account-level KPIs (spend, conversions) come from google_ads_account_daily ONLY —
 *   never summed from campaign/keyword/search-term rows.
 * - cost_amount is already normalized currency — never divide cost_micros again.
 * - CPA is always Unavailable (no canonical typed conversion / business-action mapping)
 *   and pacing is always Unavailable (agency planned budget is not stored in the pool).
 */
final class GoogleAdsSpecialistReadService
{
    private const string DATASET_ACCOUNT_DAILY = 'google_ads_account_daily';

    private const string DATASET_CAMPAIGN_DAILY = 'google_ads_campaign_daily';

    private const string DATASET_KEYWORD_DAILY = 'google_ads_keyword_daily';

    private const string DATASET_SEARCH_TERM_DAILY = 'google_ads_search_term_daily';

    private const string DATASET_LANDING_PAGE_DAILY = 'google_ads_landing_page_daily';

    private const string DATASET_CONVERSION_ACTION_SNAPSHOT = 'google_ads_conversion_action_snapshot';

    private const string DATASET_CONVERSION_ACTION_DAILY = 'google_ads_conversion_action_daily';

    private const string DATASET_CAMPAIGN_SNAPSHOT = 'google_ads_campaign_snapshot';

    private const string DATASET_AD_SNAPSHOT = 'google_ads_ad_snapshot';

    private const string DATASET_ASSET_COVERAGE_SNAPSHOT = 'google_ads_asset_coverage_snapshot';

    private const string DATASET_CAMPAIGN_BUDGET_SNAPSHOT = 'google_ads_campaign_budget_snapshot';

    public const string CONVERSION_NOTE = 'Google Ads conversions — not automatically Qualified Lead, Business Outcome, or verified revenue.';

    public const string SEARCH_VOLUME_NOTE = 'Search term impressions are advertising observations — not market search volume.';

    public const string CPA_UNAVAILABLE_NOTE = 'CPA requires a canonical typed conversion denominator / business-action mapping — unavailable in Prompt 30.';

    /**
     * Canonical field-path list every workspace mode must classify in `data_provenance`.
     *
     * @var list<string>
     */
    private const array PROVENANCE_FIELDS = [
        'identity.customer_id',
        'identity.reporting_timezone',
        'freshness.google_ads',
        'glance.spend',
        'glance.conversions',
        'glance.cpa',
        'glance.pacing',
        'performance_trend',
        'campaigns',
        'spend_by_offering',
        'search.terms',
        'search.keywords',
        'search.clusters',
        'search.inbox',
        'ads.rows',
        'ads.asset_groups',
        'landing_pages',
        'measurement',
        'needs_attention',
        'operations.collection_state',
        'operations.findings',
        'opportunities',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const array TAB_FIELD_MAP = [
        'overview' => ['glance.spend', 'campaigns', 'performance_trend', 'needs_attention', 'spend_by_offering'],
        'campaigns' => ['campaigns'],
        'search_demand' => ['search.terms', 'search.keywords', 'search.clusters'],
        'ads_assets' => ['ads.rows', 'ads.asset_groups'],
        'landing_pages' => ['landing_pages'],
        'measurement' => ['measurement'],
        'operations' => ['operations.collection_state', 'operations.findings'],
    ];

    public function __construct(
        private readonly GoogleAdsSpecialistBindingResolver $bindingResolver,
        private readonly GoogleAdsUiDatasetGate $gate,
        private readonly GoogleAdsPoolReadRepository $pool,
        private readonly GoogleAdsFormulaCalculator $formulas,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function workspace(string $assetId, string $preset = 'last_28', ?string $start = null, ?string $end = null): array
    {
        $binding = $this->bindingResolver->resolve($assetId);

        if ($binding->mode === GoogleAdsBindingMode::DemoCatalog) {
            return $this->demoWorkspace($preset, $start, $end);
        }

        if ($binding->mode !== GoogleAdsBindingMode::RealBound) {
            return $this->operationalWorkspace($binding, $preset, $start, $end, 'not_connected');
        }

        try {
            return $this->buildRealWorkspace($binding, $preset, $start, $end);
        } catch (Throwable $e) {
            Log::error('google_ads.read_service.real_workspace_failed', [
                'digital_asset_id' => $binding->digitalAssetId,
                'external_resource_id' => $binding->externalResourceId,
                'error' => $e->getMessage(),
            ]);

            return $this->operationalWorkspace($binding, $preset, $start, $end, 'real', $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function demoWorkspace(string $preset, ?string $start, ?string $end): array
    {
        $bounds = DemoPeriod::bounds($preset, $start, $end);
        $prev = DemoPeriod::previousBounds($preset, $bounds['start']->toDateString(), $bounds['end']->toDateString());

        $data = GoogleAdsWorkspaceFixtures::workspace($preset);
        $data['period_label'] = $bounds['label'];
        $data['period_days'] = $bounds['days'];
        $data['period_start'] = $bounds['start']->toDateString();
        $data['period_end'] = $bounds['end']->toDateString();
        $data['compare_label'] = 'vs '.$prev['label'];
        $data['migration_mode'] = 'demo_catalog';
        $data['data_provenance'] = $this->allProvenance(DataSourceState::Demo);
        $data['tab_status'] = array_fill_keys(array_keys(self::TAB_FIELD_MAP), DataSourceState::Demo->value);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRealWorkspace(
        GoogleAdsBindingContext $binding,
        string $preset,
        ?string $start,
        ?string $end,
    ): array {
        $bounds = DemoPeriod::bounds($preset, $start, $end);
        $rangeStart = $bounds['start']->toDateString();
        $rangeEnd = $bounds['end']->toDateString();
        $prev = DemoPeriod::previousBounds($preset, $rangeStart, $rangeEnd);
        $prevStart = $prev['start']->toDateString();
        $prevEnd = $prev['end']->toDateString();

        $digitalAssetId = (int) $binding->digitalAssetId;
        $externalResourceId = (int) $binding->externalResourceId;
        $customerId = (string) $binding->customerId;
        $timezone = $binding->timezone;

        $provenance = $this->allProvenance(DataSourceState::Demo);

        // Demo-shaped frozen fixture is the canonical shape; every key below is
        // overridden explicitly when a real/partial/unavailable value is computed.
        $data = GoogleAdsWorkspaceFixtures::workspace($preset);
        $data['period_label'] = $bounds['label'];
        $data['period_days'] = $bounds['days'];
        $data['period_start'] = $rangeStart;
        $data['period_end'] = $rangeEnd;
        $data['compare_label'] = 'vs '.$prev['label'];

        $accountGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_ACCOUNT_DAILY, $rangeStart, $rangeEnd, $timezone);
        $prevAccountGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_ACCOUNT_DAILY, $prevStart, $prevEnd, $timezone);
        $campaignGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_CAMPAIGN_DAILY, $rangeStart, $rangeEnd, $timezone);
        $keywordGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_KEYWORD_DAILY, $rangeStart, $rangeEnd, $timezone);
        $searchTermGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_SEARCH_TERM_DAILY, $rangeStart, $rangeEnd, $timezone);
        $landingPageGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_LANDING_PAGE_DAILY, $rangeStart, $rangeEnd, $timezone);
        $conversionActionDailyGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_CONVERSION_ACTION_DAILY, $rangeStart, $rangeEnd, $timezone);
        $conversionActionSnapshotGate = $this->gate->evaluateSnapshot($digitalAssetId, $externalResourceId, self::DATASET_CONVERSION_ACTION_SNAPSHOT, $timezone);
        $campaignSnapshotGate = $this->gate->evaluateSnapshot($digitalAssetId, $externalResourceId, self::DATASET_CAMPAIGN_SNAPSHOT, $timezone);
        $adSnapshotGate = $this->gate->evaluateSnapshot($digitalAssetId, $externalResourceId, self::DATASET_AD_SNAPSHOT, $timezone);
        $assetCoverageGate = $this->gate->evaluateSnapshot($digitalAssetId, $externalResourceId, self::DATASET_ASSET_COVERAGE_SNAPSHOT, $timezone);
        $campaignBudgetGate = $this->gate->evaluateSnapshot($digitalAssetId, $externalResourceId, self::DATASET_CAMPAIGN_BUDGET_SNAPSHOT, $timezone);

        $sums = $accountGate->isUsable()
            ? $this->pool->accountDailySums($digitalAssetId, $externalResourceId, $customerId, $accountGate->effectiveStart, $accountGate->effectiveEnd)
            : null;
        $prevSums = $prevAccountGate->isUsable()
            ? $this->pool->accountDailySums($digitalAssetId, $externalResourceId, $customerId, $prevAccountGate->effectiveStart, $prevAccountGate->effectiveEnd)
            : null;

        $currency = $binding->currency ?? 'XXX';
        if ($sums !== null && $sums['currency'] !== null) {
            $currency = $sums['currency'];
        }

        $asset = DigitalAsset::query()->with('brand')->find($digitalAssetId);

        $data['identity'] = $this->realIdentity($binding, $asset, $currency);
        $provenance['identity.customer_id'] = DataSourceState::Real->value;
        $provenance['identity.reporting_timezone'] = DataSourceState::Real->value;

        $data['freshness'] = $this->realFreshnessChips($data['freshness'], $accountGate, $customerId);
        $provenance['freshness.google_ads'] = DataSourceState::Real->value;

        // CPA/pacing are always Unavailable — no business-action mapping and no agency plan in the pool.
        $data['glance'] = $this->realGlance($accountGate, $sums, $prevSums, $currency);
        $provenance['glance.spend'] = $accountGate->dataSourceState()->value;
        $provenance['glance.conversions'] = $accountGate->dataSourceState()->value;
        $provenance['glance.cpa'] = DataSourceState::Unavailable->value;
        $provenance['glance.pacing'] = DataSourceState::Unavailable->value;

        $data['business_goal'] = [
            'goal' => null,
            'primary_conversion' => null,
            'note' => 'Business goal → primary conversion action mapping is unavailable — no Business Action mapping is configured in Prompt 30.',
        ];
        $data['pacing'] = [
            'source' => 'Unavailable',
            'note' => 'Pacing unavailable — agency planned monthly budget is not stored in the data pool.',
            'monthly_budget' => 0,
            'elapsed_pct' => 0,
            'expected_spend' => 0,
            'actual_spend' => 0,
            'spend_pct' => 0,
            'remaining' => 0,
            'ahead_by' => 0,
            'state' => 'Unavailable',
            'projected' => 0,
        ];

        $data['performance_trend'] = $this->realPerformanceTrend($accountGate, $digitalAssetId, $externalResourceId, $customerId);
        $provenance['performance_trend'] = $accountGate->dataSourceState()->value;

        // No fabricated AdGroups for Performance Max campaigns — campaign rows carry no ad_group field.
        $campaignRows = ($campaignGate->isUsable() && $campaignGate->effectiveStart !== null && $campaignGate->effectiveEnd !== null)
            ? $this->pool->campaignPerformance($digitalAssetId, $externalResourceId, $customerId, $campaignGate->effectiveStart, $campaignGate->effectiveEnd)
            : [];
        $campaignNames = array_column($campaignRows, 'name', 'campaign_id');
        $data['campaigns'] = $this->realCampaigns($campaignRows, $currency);
        $provenance['campaigns'] = $campaignGate->dataSourceState()->value;

        // No offering taxonomy exists in the pool.
        $data['spend_by_offering'] = [];
        $provenance['spend_by_offering'] = DataSourceState::Unavailable->value;

        $data['search'] = $this->realSearch(
            $data['search'] ?? [],
            $searchTermGate,
            $keywordGate,
            $digitalAssetId,
            $externalResourceId,
            $customerId,
            $campaignNames,
        );
        $provenance['search.terms'] = $searchTermGate->isUsable()
            ? DataSourceState::ProviderLimited->value
            : DataSourceState::Unavailable->value;
        $provenance['search.keywords'] = $keywordGate->isUsable()
            ? DataSourceState::ProviderLimited->value
            : DataSourceState::Unavailable->value;
        $provenance['search.clusters'] = DataSourceState::Unavailable->value;
        $provenance['search.inbox'] = DataSourceState::Unavailable->value;

        $data['ads'] = $this->realAds($adSnapshotGate, $digitalAssetId, $customerId);
        $provenance['ads.rows'] = $adSnapshotGate->dataSourceState()->value;
        $provenance['ads.asset_groups'] = DataSourceState::Unavailable->value;

        $data['landing_pages'] = $this->realLandingPages($landingPageGate, $digitalAssetId, $externalResourceId, $customerId, $data['landing_pages'] ?? []);
        $provenance['landing_pages'] = $landingPageGate->dataSourceState()->value;

        $data['measurement'] = $this->realMeasurement(
            $data['measurement'] ?? [],
            $conversionActionSnapshotGate,
            $conversionActionDailyGate,
            $digitalAssetId,
            $externalResourceId,
            $customerId,
        );
        $provenance['measurement'] = $this->combinedState($conversionActionSnapshotGate, $conversionActionDailyGate)->value;

        // Residual Demo domains: clear on real path (Prompt 67).
        $data['needs_attention'] = [];
        $data['opportunities'] = [];
        $provenance['needs_attention'] = DataSourceState::Unavailable->value;
        $provenance['opportunities'] = DataSourceState::Unavailable->value;

        $gates = [
            self::DATASET_ACCOUNT_DAILY => $accountGate,
            self::DATASET_CAMPAIGN_DAILY => $campaignGate,
            self::DATASET_KEYWORD_DAILY => $keywordGate,
            self::DATASET_SEARCH_TERM_DAILY => $searchTermGate,
            self::DATASET_LANDING_PAGE_DAILY => $landingPageGate,
            self::DATASET_CONVERSION_ACTION_SNAPSHOT => $conversionActionSnapshotGate,
            self::DATASET_CONVERSION_ACTION_DAILY => $conversionActionDailyGate,
            self::DATASET_CAMPAIGN_SNAPSHOT => $campaignSnapshotGate,
            self::DATASET_AD_SNAPSHOT => $adSnapshotGate,
            self::DATASET_ASSET_COVERAGE_SNAPSHOT => $assetCoverageGate,
            self::DATASET_CAMPAIGN_BUDGET_SNAPSHOT => $campaignBudgetGate,
        ];
        $data['operations']['collection_state'] = $this->realCollectionState($gates);
        $data['operations']['subtitle'] = 'Collection and dataset readiness for this Google Ads account. Findings live in Operations queues — not fabricated here.';
        $data['operations']['findings'] = [];
        $data['operations']['recommendations'] = [];
        $data['operations']['tasks'] = [];
        $data['operations']['outcomes'] = [];
        $provenance['operations.collection_state'] = DataSourceState::Real->value;
        $provenance['operations.findings'] = DataSourceState::Unavailable->value;

        $data['demo_boundary'] = 'Real Google Ads workspace · data pool + formulas — no live Google Ads API call on page render. Unbacked cards are empty/unavailable, never Demo.';
        $data['migration_mode'] = 'real';
        $data['data_provenance'] = $provenance;
        $data['tab_status'] = $this->rollupTabStatus($provenance);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function operationalWorkspace(
        GoogleAdsBindingContext $binding,
        string $preset,
        ?string $start,
        ?string $end,
        string $migrationMode,
        ?string $errorMessage = null,
    ): array {
        $bounds = DemoPeriod::bounds($preset, $start, $end);
        $rangeStart = $bounds['start']->toDateString();
        $rangeEnd = $bounds['end']->toDateString();
        $prev = DemoPeriod::previousBounds($preset, $rangeStart, $rangeEnd);

        $reason = $errorMessage !== null
            ? 'query_error'
            : ($binding->reason ?? 'no_active_google_ads_binding');
        $statusLabel = $errorMessage !== null
            ? 'Error'
            : ($binding->mode === GoogleAdsBindingMode::ActionRequired ? 'Action required' : 'Not connected');
        $collectionNote = $errorMessage !== null
            ? 'A read error occurred building this workspace — no data is shown and no Demo fixtures were substituted.'
            : "Google Ads binding {$reason} — no collection state available.";

        $unavailableChip = ['value' => '—', 'raw' => null, 'secondary' => 'Unavailable', 'tone' => 'neutral'];
        $asset = DigitalAsset::query()->with('brand')->find($binding->digitalAssetId);

        $data = [
            'period_label' => $bounds['label'],
            'period_days' => $bounds['days'],
            'period_start' => $rangeStart,
            'period_end' => $rangeEnd,
            'compare_label' => 'vs '.$prev['label'],
            'demo_boundary' => 'Real Google Ads workspace · no usable Customer binding — no live Google Ads API call performed.',
            'identity' => [
                'eyebrow' => 'Google Ads',
                'title' => $errorMessage !== null
                    ? (($asset?->name ?? 'Google Ads').' — read error')
                    : (($asset?->name ?? 'Google Ads').' — not connected'),
                'brand' => $asset?->brand?->name,
                'brand_id' => $asset?->brand_id,
                'brand_name' => $asset?->brand?->name ?? '—',
                'website_asset_id' => null,
                'google_ads_asset_id' => $binding->assetId,
                'strategy_line' => 'Not connected — no Google Ads Customer is bound.',
                'status' => $statusLabel,
                'freshness' => 'Not collected',
                'customer_id' => null,
                'reporting_timezone' => null,
                'currency' => null,
            ],
            'business_goal' => [
                'goal' => null,
                'primary_conversion' => null,
                'note' => 'Unavailable — '.$reason,
            ],
            'freshness' => [],
            'glance' => [
                'spend' => $unavailableChip,
                'conversions' => $unavailableChip,
                'cpa' => $unavailableChip + ['note' => self::CPA_UNAVAILABLE_NOTE],
                'pacing' => $unavailableChip,
            ],
            'pacing' => [
                'source' => 'Unavailable',
                'note' => 'Unavailable — '.$reason,
                'monthly_budget' => 0,
                'elapsed_pct' => 0,
                'expected_spend' => 0,
                'actual_spend' => 0,
                'spend_pct' => 0,
                'remaining' => 0,
                'ahead_by' => 0,
                'state' => 'Unavailable',
                'projected' => 0,
            ],
            'needs_attention' => [],
            'performance_trend' => [
                'labels' => [],
                'spend' => [],
                'leads' => [],
                'note' => 'Unavailable — '.$reason,
                'compare_label' => 'vs '.$prev['label'],
            ],
            'campaigns' => [],
            'spend_by_offering' => [],
            'search' => [
                'subtitle' => 'Unavailable — '.$reason,
                'terms_observed' => 0,
                'aligned_high_intent_pct' => 0,
                'review_spend' => 0,
                'inbox_count' => 0,
                'intent_distribution' => [],
                'intent_drift' => [],
                'reviewable_spend' => [],
                'inbox_summary' => ['negative' => 0, 'keyword' => 0, 'content' => 0, 'strategy' => 0],
                'terms' => [],
                'clusters' => [],
                'keywords' => [],
                'intent_provenance' => 'Unavailable',
                'search_volume_note' => self::SEARCH_VOLUME_NOTE,
            ],
            'ads' => [
                'subtitle' => 'Unavailable — '.$reason,
                'rows' => [],
                'policy_summary' => 'Unavailable — '.$reason,
                'asset_groups' => [],
                'asset_groups_note' => 'Unavailable — Performance Max Asset Group hierarchy is not modeled in the data pool.',
            ],
            'landing_pages' => [
                'subtitle' => 'Unavailable — '.$reason,
                'active' => 0,
                'need_review' => 0,
                'exposure_attention' => 0,
                'rows' => [],
            ],
            'measurement' => [
                'subtitle' => 'Unavailable — '.$reason,
                'glance' => [
                    'primary_goals' => '—',
                    'healthy' => '—',
                    'needs_mapping' => '—',
                    'findings' => '—',
                ],
                'matrix' => [],
                'debt' => [],
                'duplicate_risk' => [
                    'title' => 'Duplicate risk unavailable',
                    'detail' => 'Unavailable — '.$reason,
                ],
                'interruption' => [
                    'title' => 'Not connected',
                    'detail' => 'Unavailable — '.$reason,
                ],
                'trust' => 'Unavailable — '.$reason,
                'ga4_label' => 'GA4 · not collected',
                'mapping_trust_note' => self::CONVERSION_NOTE,
            ],
            'operations' => [
                'subtitle' => $errorMessage !== null
                    ? 'Google Ads read error — no findings, recommendations, tasks, or outcomes available.'
                    : 'Google Ads binding required — connect a Customer account to see findings, recommendations, tasks, and outcomes.',
                'findings' => [],
                'recommendations' => [],
                'tasks' => [],
                'outcomes' => [],
                'finding_detail' => [],
                'decision_history' => [],
                'collection_state' => [
                    'note' => $collectionNote,
                    'datasets' => [],
                ],
            ],
            'opportunities' => [],
            'recent_outcomes' => [],
            'conversion_lag_note' => null,
            'narrative' => null,
            'missing_note' => 'Missing ≠ zero — Not connected / Unavailable means the signal is absent, not a measured 0.',
            'migration_mode' => $migrationMode,
            'data_provenance' => $this->allProvenance(DataSourceState::Unavailable),
        ];

        $data['tab_status'] = $this->rollupTabStatus($data['data_provenance']);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function realIdentity(GoogleAdsBindingContext $binding, ?DigitalAsset $asset, string $currency): array
    {
        $brandName = $asset?->brand?->name;
        $assetName = $asset?->name;
        $title = $assetName ?? 'Google Ads account';

        return [
            'eyebrow' => 'Google Ads',
            'title' => "{$title} — Google Ads",
            'brand' => $brandName,
            'brand_id' => $asset?->brand_id,
            'brand_name' => $brandName,
            'website_asset_id' => null,
            'google_ads_asset_id' => $binding->assetId,
            'strategy_line' => $assetName !== null ? "Runs ads for · {$assetName}" : null,
            'status' => 'Connected',
            'freshness' => null,
            'customer_id' => $binding->customerId,
            'reporting_timezone' => $binding->timezone,
            'currency' => $currency,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $demoChips
     * @return list<array<string, mixed>>
     */
    private function realFreshnessChips(array $demoChips, GoogleAdsDatasetReadiness $accountGate, string $customerId): array
    {
        $stateLabel = match ($accountGate->freshnessState) {
            'FRESH', 'FRESH_WITH_LIMITATION' => 'current',
            'STALE' => 'stale',
            default => 'attention',
        };

        $ageLabel = match ($accountGate->freshnessState) {
            'FRESH', 'FRESH_WITH_LIMITATION' => 'Fresh',
            'DUE' => 'Due',
            'STALE' => 'Stale',
            'PARTIAL' => 'Partial',
            'ACTION_REQUIRED' => 'Action required',
            'INTEGRITY_BLOCKED' => 'Blocked',
            default => 'Unknown',
        };

        return [[
            'source' => 'Google Ads',
            'age' => $ageLabel,
            'detail' => "Customer {$customerId} · google_ads_account_daily · {$accountGate->coverageState}",
            'state' => $stateLabel,
        ]];
    }

    /**
     * @param  array{impressions?: int, clicks?: int, cost_amount?: float, conversions?: float, currency?: ?string}|null  $sums
     * @param  array{impressions?: int, clicks?: int, cost_amount?: float, conversions?: float, currency?: ?string}|null  $prevSums
     * @return array<string, mixed>
     */
    private function realGlance(
        GoogleAdsDatasetReadiness $accountGate,
        ?array $sums,
        ?array $prevSums,
        string $currency,
    ): array {
        $cpa = [
            'value' => 'CPA unavailable',
            'raw' => null,
            'secondary' => 'Primary mapping required',
            'tone' => 'neutral',
            'note' => self::CPA_UNAVAILABLE_NOTE,
        ];

        $pacing = [
            'value' => '—',
            'raw' => null,
            'secondary' => 'Unavailable',
            'tone' => 'neutral',
            'note' => 'Pacing unavailable — agency planned monthly budget is not stored in the data pool.',
        ];

        if (! $accountGate->isUsable() || $sums === null) {
            $note = 'Spend and conversions unavailable — google_ads_account_daily dataset is not ready for real UI. Unavailable ≠ zero.';
            $unavailable = ['value' => '—', 'raw' => null, 'secondary' => 'Unavailable', 'tone' => 'neutral', 'note' => $note];

            return [
                'spend' => $unavailable,
                'conversions' => $unavailable + ['note' => self::CONVERSION_NOTE],
                'cpa' => $cpa,
                'pacing' => $pacing,
            ];
        }

        $spendDelta = $prevSums !== null
            ? $this->formulas->periodRelativeChange((float) $sums['cost_amount'], (float) $prevSums['cost_amount'])
            : null;
        $conversionsDelta = $prevSums !== null
            ? $this->formulas->periodRelativeChange((float) $sums['conversions'], (float) $prevSums['conversions'])
            : null;

        $spend = [
            'value' => $this->formatMoney((float) $sums['cost_amount'], $currency),
            'raw' => round((float) $sums['cost_amount'], 2),
            'secondary' => $this->deltaSecondary($spendDelta, $accountGate),
            'tone' => 'neutral',
        ];

        $conversions = [
            'value' => number_format((float) $sums['conversions'], 1),
            'raw' => (float) $sums['conversions'],
            'secondary' => $this->deltaSecondary($conversionsDelta, $accountGate),
            'tone' => 'neutral',
            'note' => self::CONVERSION_NOTE,
        ];

        if ($accountGate->coverageState === GoogleAdsDatasetReadiness::COVERAGE_PARTIALLY_COVERED) {
            $partial = 'Partial coverage — metrics reflect only collected days in this range.';
            $spend['note'] = $partial;
            $conversions['note'] = self::CONVERSION_NOTE.' '.$partial;
        }

        return [
            'spend' => $spend,
            'conversions' => $conversions,
            'cpa' => $cpa,
            'pacing' => $pacing,
        ];
    }

    private function deltaSecondary(?FormulaResult $delta, GoogleAdsDatasetReadiness $gate): string
    {
        if (! $gate->isUsable()) {
            return 'Unavailable vs previous period';
        }

        if ($delta === null || ! $delta->isValue()) {
            return 'vs previous period unavailable';
        }

        $pct = $delta->toPercentDisplay();
        $prefix = $pct >= 0 ? '+' : '';

        return $prefix.number_format($pct, 1).'% vs previous period';
    }

    /**
     * @return array<string, mixed>
     */
    private function realPerformanceTrend(
        GoogleAdsDatasetReadiness $accountGate,
        int $digitalAssetId,
        int $externalResourceId,
        string $customerId,
    ): array {
        if (! $accountGate->isUsable() || $accountGate->effectiveStart === null || $accountGate->effectiveEnd === null) {
            return [
                'labels' => [],
                'spend' => [],
                'leads' => [],
                'compare_label' => 'vs prior period',
                'note' => 'Performance trend unavailable — google_ads_account_daily dataset is not ready for real UI.',
            ];
        }

        $series = $this->pool->accountDailySeries($digitalAssetId, $externalResourceId, $customerId, $accountGate->effectiveStart, $accountGate->effectiveEnd);

        $labels = [];
        $spend = [];
        $leads = [];
        foreach ($series as $point) {
            $labels[] = CarbonImmutable::parse($point['date'])->format('M j');
            $spend[] = round($point['cost_amount'], 2);
            $leads[] = $point['conversions'];
        }

        $partial = $accountGate->coverageState === GoogleAdsDatasetReadiness::COVERAGE_PARTIALLY_COVERED;

        return [
            'labels' => $labels,
            'spend' => $spend,
            'leads' => $leads,
            'compare_label' => 'vs prior period',
            'note' => ($partial ? 'Spend + provider conversions · real Google Ads data (partial coverage). ' : 'Spend + provider conversions · real Google Ads data. ')
                .'"leads" is the frozen chart key — the series is provider conversions, not Qualified Leads.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function realCampaigns(array $rows, string $currency): array
    {
        return array_map(function (array $row) use ($currency): array {
            $imprShare = $row['search_impression_share'] !== null ? round($row['search_impression_share'] * 100, 1) : null;
            $lostBudget = $row['lost_is_budget'] !== null ? round($row['lost_is_budget'] * 100, 1) : null;
            $lostRank = $row['lost_is_rank'] !== null ? round($row['lost_is_rank'] * 100, 1) : null;

            return [
                'id' => $row['campaign_id'],
                'name' => $row['name'],
                'type' => $this->humanizeChannelType((string) $row['channel_type']),
                'status' => $row['status'],
                'offering' => null,
                'market' => null,
                'language' => null,
                'goal' => null,
                'primary_conversion' => null,
                'funnel' => null,
                'search_strategy' => null,
                'price_intent' => null,
                'competitor_intent' => null,
                'brand_intent' => null,
                'budget' => $row['budget_amount'] !== null ? round((float) $row['budget_amount'], 2) : null,
                'spend' => round((float) $row['cost_amount'], 2),
                'leads' => (float) $row['conversions'],
                'cpa' => null,
                'pacing' => 'Unavailable',
                'impr_share' => $imprShare,
                'lost_is_budget' => $lostBudget,
                'lost_is_rank' => $lostRank,
                'attention' => [],
                'attention_primary' => null,
                'currency' => $row['currency'] ?? $currency,
                'is_pmax' => $row['is_pmax'],
                'leads_note' => self::CONVERSION_NOTE,
                'pacing_note' => 'Unavailable — agency planned budget pacing is not stored in the data pool.',
            ];
        }, $rows);
    }

    /**
     * @param  array<string, mixed>  $demoSearch
     * @param  array<string, string>  $campaignNames
     * @return array<string, mixed>
     */
    private function realSearch(
        array $demoSearch,
        GoogleAdsDatasetReadiness $termGate,
        GoogleAdsDatasetReadiness $keywordGate,
        int $digitalAssetId,
        int $externalResourceId,
        string $customerId,
        array $campaignNames,
    ): array {
        $terms = $this->realSearchTerms($termGate, $digitalAssetId, $externalResourceId, $customerId, $campaignNames);
        $keywords = $this->realKeywords($keywordGate, $digitalAssetId, $externalResourceId, $customerId);

        return [
            'subtitle' => $demoSearch['subtitle'] ?? 'What people searched and which spend deserves operator review.',
            'terms_observed' => count($terms),
            'aligned_high_intent_pct' => null,
            'review_spend' => 0,
            'inbox_count' => 0,
            'intent_distribution' => [],
            'intent_drift' => [],
            'reviewable_spend' => [],
            'inbox_summary' => ['negative' => 0, 'keyword' => 0, 'content' => 0, 'strategy' => 0],
            'terms' => $terms,
            'clusters' => [],
            'keywords' => $keywords,
            'intent_provenance' => 'Unavailable — intent clustering is not computed for real search terms in Prompt 30.',
            'search_volume_note' => self::SEARCH_VOLUME_NOTE,
        ];
    }

    /**
     * @param  array<string, string>  $campaignNames
     * @return list<array<string, mixed>>
     */
    private function realSearchTerms(
        GoogleAdsDatasetReadiness $termGate,
        int $digitalAssetId,
        int $externalResourceId,
        string $customerId,
        array $campaignNames,
    ): array {
        if (! $termGate->isUsable() || $termGate->effectiveStart === null || $termGate->effectiveEnd === null) {
            return [];
        }

        $rows = $this->pool->topSearchTerms($digitalAssetId, $externalResourceId, $customerId, $termGate->effectiveStart, $termGate->effectiveEnd);

        return array_map(function (array $row) use ($campaignNames): array {
            $campaignId = $row['campaign_id'];
            $isPmax = (bool) $row['is_pmax'];

            return [
                'term' => $row['search_term'],
                'campaign' => $campaignId !== null ? ($campaignNames[$campaignId] ?? 'Campaign '.$campaignId) : null,
                'campaign_id' => $campaignId,
                'ad_group' => $isPmax ? null : $row['ad_group_id'],
                'spend' => round((float) $row['cost_amount'], 2),
                'clicks' => $row['clicks'],
                'impressions' => $row['impressions'],
                'leads' => (float) $row['conversions'],
                'currency' => $row['currency'],
                'intent' => '',
                'fit' => 'Observed',
                'decision' => '',
                'is_pmax' => $isPmax,
                'provider_may_omit_terms' => $row['provider_may_omit_terms'],
                'completeness' => 'PROVIDER_LIMITED',
                'search_term_note' => self::SEARCH_VOLUME_NOTE,
                'keyword_distinction_note' => 'Search term ≠ keyword — the matched keyword that triggered this term is tracked separately.',
                'leads_note' => self::CONVERSION_NOTE,
            ];
        }, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function realKeywords(
        GoogleAdsDatasetReadiness $keywordGate,
        int $digitalAssetId,
        int $externalResourceId,
        string $customerId,
    ): array {
        if (! $keywordGate->isUsable() || $keywordGate->effectiveStart === null || $keywordGate->effectiveEnd === null) {
            return [];
        }

        $rows = $this->pool->topKeywords($digitalAssetId, $externalResourceId, $customerId, $keywordGate->effectiveStart, $keywordGate->effectiveEnd);

        return array_map(fn (array $row): array => [
            'criterion_id' => $row['criterion_id'],
            'keyword' => $row['keyword'],
            'match' => $this->humanizeMatchType((string) $row['match_type']),
            'spend' => round((float) $row['cost_amount'], 2),
            'clicks' => $row['clicks'],
            'impressions' => $row['impressions'],
            'leads' => (float) $row['conversions'],
            'currency' => $row['currency'],
            'keyword_neq_search_term' => true,
            'leads_note' => self::CONVERSION_NOTE,
        ], $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function realAds(GoogleAdsDatasetReadiness $adSnapshotGate, int $digitalAssetId, string $customerId): array
    {
        $rows = [];
        if ($adSnapshotGate->isUsable()) {
            $rows = array_map(fn (array $row): array => [
                'id' => $row['ad_id'],
                'name' => 'Ad '.$row['ad_id'],
                'campaign' => null,
                'ad_group' => null,
                'state' => $row['status'] ?? 'UNKNOWN',
                'final_url' => is_array($row['final_urls']) ? ($row['final_urls'][0] ?? null) : null,
                'asset_coverage' => 'Unavailable',
                'policy' => 'Unavailable',
                'theme' => null,
                'landing_match' => 'Unavailable',
                'intent_match' => 'Unavailable',
                'intent_why' => null,
                'brand_note' => null,
                'google_strength' => $row['ad_strength'] ?? 'Unavailable',
                'headlines' => [],
                'type' => $row['type'],
                'ad_strength_note' => 'Ad Strength is a provider creative-completeness score — not a performance score.',
            ], $this->pool->adSnapshots($digitalAssetId, $customerId));
        }

        return [
            'subtitle' => 'Ad creative inventory from real Google Ads ad snapshots — no ad-level daily performance is joined on the real path.',
            'rows' => $rows,
            'policy_summary' => $rows !== []
                ? count($rows).' ads observed · policy/approval status is not read from the provider on the real path.'
                : 'Unavailable — google_ads_ad_snapshot dataset is not ready for real UI.',
            'asset_groups' => [],
            'asset_groups_note' => 'Unavailable — Performance Max Asset Group hierarchy is not modeled in the data pool; assets are never shown as Ads or AdGroups.',
        ];
    }

    /**
     * @param  array<string, mixed>  $demoLandingPages
     * @return array<string, mixed>
     */
    private function realLandingPages(
        GoogleAdsDatasetReadiness $landingPageGate,
        int $digitalAssetId,
        int $externalResourceId,
        string $customerId,
        array $demoLandingPages,
    ): array {
        if (! $landingPageGate->isUsable() || $landingPageGate->effectiveStart === null || $landingPageGate->effectiveEnd === null) {
            return [
                'subtitle' => 'Landing pages unavailable — google_ads_landing_page_daily dataset is not ready for real UI.',
                'active' => null,
                'need_review' => null,
                'exposure_attention' => null,
                'rows' => [],
            ];
        }

        $rows = $this->pool->topLandingPages($digitalAssetId, $externalResourceId, $customerId, $landingPageGate->effectiveStart, $landingPageGate->effectiveEnd);

        $formatted = array_map(fn (array $row): array => [
            'id' => 'lp-'.Str::slug($row['landing_page']),
            'url' => $row['landing_page'],
            'title' => '',
            'spend' => round((float) $row['cost_amount'], 2),
            'clicks' => $row['clicks'],
            'impressions' => $row['impressions'],
            'leads' => (float) $row['conversions'],
            'currency' => $row['currency'],
            'campaigns' => [],
            'technical' => 'Unavailable',
            'mobile' => 'Unavailable',
            'measurement' => 'Unavailable',
            'message' => 'Unavailable',
            'language' => null,
            'attention' => null,
            'website_finding' => null,
            'query_themes' => [],
            'ad_themes' => [],
            'message_reason' => null,
            'leads_note' => self::CONVERSION_NOTE,
        ], $rows);

        return [
            'subtitle' => 'Where paid traffic lands — technical, mobile and message quality are Unavailable on the real path (no Website join in Prompt 30).',
            'active' => count($formatted),
            'need_review' => null,
            'exposure_attention' => null,
            'rows' => $formatted,
        ];
    }

    /**
     * @param  array<string, mixed>  $demoMeasurement
     * @return array<string, mixed>
     */
    private function realMeasurement(
        array $demoMeasurement,
        GoogleAdsDatasetReadiness $snapshotGate,
        GoogleAdsDatasetReadiness $dailyGate,
        int $digitalAssetId,
        int $externalResourceId,
        string $customerId,
    ): array {
        $mappingNote = 'Matrix reflects real Google Ads conversion actions — conversions and all_conversions are kept distinct and no generic "Results" metric is used. '
            .'Health/duplicate-risk narrative above remains illustrative; no Business Action mapping is configured in Prompt 30.';

        if (! $snapshotGate->isUsable()) {
            return array_merge($demoMeasurement, [
                'matrix' => [],
                'mapping_trust_note' => 'Conversion actions unavailable — google_ads_conversion_action_snapshot dataset is not ready for real UI.',
            ]);
        }

        $actions = $this->pool->conversionActions($digitalAssetId, $externalResourceId, $customerId);

        $dailyByAction = [];
        if ($dailyGate->isUsable() && $dailyGate->effectiveStart !== null && $dailyGate->effectiveEnd !== null) {
            $dailySums = $this->pool->conversionActionDailySums($digitalAssetId, $externalResourceId, $customerId, $dailyGate->effectiveStart, $dailyGate->effectiveEnd);
            $dailyByAction = array_column($dailySums, null, 'conversion_action_id');
        }

        $matrix = array_map(function (array $action) use ($dailyByAction): array {
            $daily = $dailyByAction[$action['conversion_action_id']] ?? null;
            $role = $action['primary_for_goal'] ? 'Primary' : ($action['include_in_conversions_metric'] ? 'Secondary' : 'Excluded');

            return [
                'action' => $action['name'],
                'source' => 'Google Ads conversion action',
                'role' => $role,
                'category' => $action['category'],
                'type' => $action['type'],
                'status' => $action['status'],
                'conversions' => $daily['conversions'] ?? null,
                'all_conversions' => $daily['all_conversions'] ?? null,
                'conversions_value' => $daily['conversions_value'] ?? null,
                'state' => $daily !== null ? 'Observed' : 'No recent signal',
                'note' => self::CONVERSION_NOTE,
            ];
        }, $actions);

        return array_merge($demoMeasurement, [
            'matrix' => $matrix,
            'mapping_trust_note' => $mappingNote,
        ]);
    }

    /**
     * @param  array<string, GoogleAdsDatasetReadiness>  $gates
     * @return array<string, mixed>
     */
    private function realCollectionState(array $gates): array
    {
        return [
            'note' => 'Real Google Ads collection/materialization/freshness/integrity/coverage state. Findings, Recommendations, Tasks and Outcomes below remain Demo — this migration creates no Evidence/Findings/Opportunities.',
            'datasets' => array_map(static fn (GoogleAdsDatasetReadiness $g): array => $g->toArray(), $gates),
        ];
    }

    private function combinedState(GoogleAdsDatasetReadiness ...$gates): DataSourceState
    {
        $usable = 0;
        $fully = 0;
        foreach ($gates as $g) {
            if ($g->isUsable()) {
                $usable++;
            }
            if ($g->isFullyCovered()) {
                $fully++;
            }
        }

        if ($usable === 0) {
            return DataSourceState::Unavailable;
        }

        return $fully === count($gates) ? DataSourceState::Real : DataSourceState::PartialReal;
    }

    private function humanizeChannelType(string $channel): string
    {
        return match (strtoupper($channel)) {
            'SEARCH' => 'Search',
            'DISPLAY' => 'Display',
            'PERFORMANCE_MAX' => 'Performance Max',
            'SHOPPING' => 'Shopping',
            'VIDEO' => 'Video',
            'DEMAND_GEN', 'DISCOVERY' => 'Demand Gen',
            'MULTI_CHANNEL' => 'Multi-channel',
            'LOCAL' => 'Local',
            'SMART' => 'Smart',
            'UNKNOWN', '' => 'Unknown',
            default => Str::title(strtolower(str_replace('_', ' ', $channel))),
        };
    }

    private function humanizeMatchType(string $matchType): string
    {
        return match (strtoupper($matchType)) {
            'EXACT' => 'Exact',
            'PHRASE' => 'Phrase',
            'BROAD' => 'Broad',
            'UNKNOWN', '' => 'Unknown',
            default => Str::title(strtolower($matchType)),
        };
    }

    private function formatMoney(float $amount, ?string $currency): string
    {
        $code = ($currency !== null && trim($currency) !== '' && strtoupper($currency) !== 'XXX')
            ? strtoupper($currency)
            : 'N/A';

        return $code.' '.number_format($amount, 2);
    }

    /**
     * @return array<string, string>
     */
    private function allProvenance(DataSourceState $state): array
    {
        return array_fill_keys(self::PROVENANCE_FIELDS, $state->value);
    }

    /**
     * @param  array<string, string>  $provenance
     * @return array<string, string>
     */
    private function rollupTabStatus(array $provenance): array
    {
        $status = [];
        foreach (self::TAB_FIELD_MAP as $tab => $fields) {
            $values = array_values(array_unique(array_map(
                static fn (string $field): string => $provenance[$field] ?? DataSourceState::Unavailable->value,
                $fields,
            )));

            $status[$tab] = match (true) {
                $values === [DataSourceState::Real->value] => 'REAL',
                $values === [DataSourceState::Demo->value] => 'DEMO',
                $values === [DataSourceState::Unavailable->value] => 'UNAVAILABLE',
                default => 'PARTIAL',
            };
        }

        return $status;
    }
}
