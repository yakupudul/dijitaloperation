<?php

namespace App\Services\MetaAds;

use App\Enums\DataPool\DataSourceState;
use App\Models\DigitalAsset;
use App\Services\Formulas\MetaAdsFormulaCalculator;
use App\Services\Formulas\Support\FormulaResult;
use App\Services\MetaAds\Support\MetaAdsBindingContext;
use App\Services\MetaAds\Support\MetaAdsBindingMode;
use App\Services\MetaAds\Support\MetaAdsDatasetReadiness;
use App\Support\Demo\DemoPeriod;
use App\Support\Demo\MetaAdsWorkspaceFixtures;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Prompt 31 Meta Ads real-data read path. Builds the frozen
 * {@see MetaAdsWorkspaceFixtures::workspace()} array shape from the data pool + formulas
 * when a real Ad Account is bound, falling back to Demo fixtures only for the DEMO_CATALOG
 * asset id and for explicit residual-Demo domains (needs_attention, operations
 * findings/recommendations/tasks/outcomes/decision_history, opportunities, recent_outcomes,
 * narrative, creative angle/persona/test taxonomy, measurement business-outcome narrative)
 * that have no real backing store in Prompt 31.
 *
 * Hard rules enforced here:
 * - No Meta Graph API call — everything is read from the local data pool + binding tables.
 * - No fallback to Demo on a query exception — an error yields an UNAVAILABLE
 *   operational workspace, never fixture numbers.
 * - Account-level additive KPIs (spend, impressions, clicks) come from
 *   meta_campaign_daily ONLY — there is no meta_account_daily table.
 * - `spend` is already major currency units — never divide by 1e6 (that is a Google
 *   Ads micros assumption and does not apply here).
 * - Reach is never summed across days/campaigns for a period total; frequency is
 *   never averaged into a period value. Both are always Unavailable at the period level.
 * - Result Mix / Cost-per-primary-result / CPA / Pacing are always Unavailable — no
 *   canonical typed-action → business-outcome mapping and no agency planned budget
 *   is stored in the pool for Prompt 31.
 */
final class MetaAdsSpecialistReadService
{
    private const string DATASET_CAMPAIGN_DAILY = 'meta_campaign_daily';

    private const string DATASET_ADSET_DAILY = 'meta_adset_daily';

    private const string DATASET_AD_DAILY = 'meta_ad_daily';

    private const string DATASET_TYPED_ACTION_DAILY = 'meta_typed_action_daily';

    private const string DATASET_DELIVERY_BREAKDOWN_DAILY = 'meta_delivery_breakdown_daily';

    private const string DATASET_CAMPAIGN_SNAPSHOT = 'meta_campaign_snapshot';

    private const string DATASET_ADSET_SNAPSHOT = 'meta_adset_snapshot';

    private const string DATASET_CREATIVE_SNAPSHOT = 'meta_creative_snapshot';

    private const string DATASET_AD_ACCOUNT_SNAPSHOT = 'meta_ad_account_snapshot';

    public const string ACTION_NOTE = 'Meta typed action — not automatically a qualified lead, generic "Results", or verified business outcome. No Business Action mapping is configured in Prompt 31.';

    public const string REACH_NOTE = 'Reach is de-duplicated by Meta and must never be summed across days or campaigns — period Reach is Unavailable.';

    public const string FREQUENCY_NOTE = 'Frequency (impressions ÷ reach) must never be averaged into a period value — Frequency is Unavailable.';

    public const string RESULTS_UNAVAILABLE_NOTE = 'Results / cost-per-result require a canonical typed-action → business-outcome mapping — unavailable in Prompt 31.';

    public const string CLICKS_NOTE = 'Clicks (all click types) and Link Clicks (metadata inline_link_clicks) are distinct — never conflate them.';

    /**
     * Canonical field-path list every workspace mode must classify in `data_provenance`.
     *
     * @var list<string>
     */
    private const array PROVENANCE_FIELDS = [
        'identity.account_id',
        'identity.reporting_timezone',
        'freshness.meta_ads',
        'glance.spend',
        'glance.result_mix',
        'glance.cost_primary',
        'glance.pacing',
        'performance_trend',
        'campaigns',
        'creatives',
        'audience.age',
        'audience.gender',
        'audience.platform',
        'audience.country',
        'funnel',
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
        'overview' => ['glance.spend', 'campaigns', 'performance_trend', 'needs_attention'],
        'campaigns' => ['campaigns'],
        'creatives' => ['creatives'],
        'audience' => ['audience.age', 'audience.gender', 'audience.platform', 'audience.country'],
        'funnel' => ['funnel'],
        'measurement' => ['measurement'],
        'operations' => ['operations.collection_state', 'operations.findings'],
    ];

    public function __construct(
        private readonly MetaAdsSpecialistBindingResolver $bindingResolver,
        private readonly MetaAdsUiDatasetGate $gate,
        private readonly MetaAdsPoolReadRepository $pool,
        private readonly MetaAdsFormulaCalculator $formulas,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function workspace(string $assetId, string $preset = 'last_28', ?string $start = null, ?string $end = null): array
    {
        $binding = $this->bindingResolver->resolve($assetId);

        if ($binding->mode === MetaAdsBindingMode::DemoCatalog) {
            return $this->demoWorkspace($preset, $start, $end);
        }

        if ($binding->mode !== MetaAdsBindingMode::RealBound) {
            return $this->operationalWorkspace($binding, $preset, $start, $end, 'not_connected');
        }

        try {
            return $this->buildRealWorkspace($binding, $preset, $start, $end);
        } catch (Throwable $e) {
            Log::error('meta_ads.read_service.real_workspace_failed', [
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

        $data = MetaAdsWorkspaceFixtures::workspace($preset, $start, $end);
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
        MetaAdsBindingContext $binding,
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
        $accountId = (string) $binding->accountId;
        $timezone = $binding->timezone;

        $provenance = $this->allProvenance(DataSourceState::Demo);

        // Demo-shaped frozen fixture is the canonical shape; every key below is
        // overridden explicitly when a real/partial/unavailable value is computed.
        $data = MetaAdsWorkspaceFixtures::workspace($preset, $start, $end);
        $data['period_label'] = $bounds['label'];
        $data['period_days'] = $bounds['days'];
        $data['period_start'] = $rangeStart;
        $data['period_end'] = $rangeEnd;
        $data['compare_label'] = 'vs '.$prev['label'];

        $campaignDailyGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_CAMPAIGN_DAILY, $rangeStart, $rangeEnd, $timezone);
        $prevCampaignDailyGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_CAMPAIGN_DAILY, $prevStart, $prevEnd, $timezone);
        $adsetDailyGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_ADSET_DAILY, $rangeStart, $rangeEnd, $timezone);
        $adDailyGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_AD_DAILY, $rangeStart, $rangeEnd, $timezone);
        $typedActionGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_TYPED_ACTION_DAILY, $rangeStart, $rangeEnd, $timezone);
        $deliveryBreakdownGate = $this->gate->evaluate($digitalAssetId, $externalResourceId, self::DATASET_DELIVERY_BREAKDOWN_DAILY, $rangeStart, $rangeEnd, $timezone);
        $campaignSnapshotGate = $this->gate->evaluateSnapshot($digitalAssetId, $externalResourceId, self::DATASET_CAMPAIGN_SNAPSHOT, $timezone);
        $adsetSnapshotGate = $this->gate->evaluateSnapshot($digitalAssetId, $externalResourceId, self::DATASET_ADSET_SNAPSHOT, $timezone);
        $creativeSnapshotGate = $this->gate->evaluateSnapshot($digitalAssetId, $externalResourceId, self::DATASET_CREATIVE_SNAPSHOT, $timezone);
        $adAccountSnapshotGate = $this->gate->evaluateSnapshot($digitalAssetId, $externalResourceId, self::DATASET_AD_ACCOUNT_SNAPSHOT, $timezone);

        $sums = $campaignDailyGate->isUsable()
            ? $this->pool->campaignDailySums($digitalAssetId, $externalResourceId, $accountId, $campaignDailyGate->effectiveStart, $campaignDailyGate->effectiveEnd)
            : null;
        $prevSums = $prevCampaignDailyGate->isUsable()
            ? $this->pool->campaignDailySums($digitalAssetId, $externalResourceId, $accountId, $prevCampaignDailyGate->effectiveStart, $prevCampaignDailyGate->effectiveEnd)
            : null;

        $currency = $binding->currency ?? 'XXX';
        if ($sums !== null && $sums['currency'] !== null) {
            $currency = $sums['currency'];
        }

        $asset = DigitalAsset::query()->with('brand')->find($digitalAssetId);

        $data['identity'] = $this->realIdentity($binding, $asset, $currency);
        $provenance['identity.account_id'] = DataSourceState::Real->value;
        $provenance['identity.reporting_timezone'] = DataSourceState::Real->value;

        $data['freshness'] = $this->realFreshnessChips($data['freshness'], $campaignDailyGate, $accountId);
        $provenance['freshness.meta_ads'] = DataSourceState::Real->value;

        // result_mix / cost_primary / pacing are always Unavailable — no canonical
        // typed-action → business-outcome mapping and no agency planned budget in the pool.
        $data['glance'] = $this->realGlance($campaignDailyGate, $sums, $prevSums, $currency);
        $provenance['glance.spend'] = $campaignDailyGate->dataSourceState()->value;
        $provenance['glance.result_mix'] = DataSourceState::Unavailable->value;
        $provenance['glance.cost_primary'] = DataSourceState::Unavailable->value;
        $provenance['glance.pacing'] = DataSourceState::Unavailable->value;

        $data['result_mix'] = [
            'items' => [],
            'note' => self::RESULTS_UNAVAILABLE_NOTE,
        ];

        $data['pacing'] = [
            'source' => 'Unavailable',
            'monthly_budget' => 0,
            'planned_for_period' => 0,
            'elapsed_pct' => 0,
            'expected_spend' => 0,
            'actual_spend' => 0,
            'spend_pct' => 0,
            'remaining' => 0,
            'ahead_by' => 0,
            'state' => 'Unavailable',
            'summary' => 'Unavailable — agency planned budget is not stored in the data pool.',
            'projected' => 0,
        ];

        $data['business_goal'] = [
            'goal' => null,
            'primary_conversion' => null,
            'note' => 'Business goal → primary result mapping is unavailable — no Business Action mapping is configured in Prompt 31.',
        ];
        $data['conversion_lag_note'] = 'Meta-attributed typed actions · attribution windows apply — provider actions are not automatically verified business outcomes.';

        $data['performance_trend'] = $this->realPerformanceTrend($campaignDailyGate, $digitalAssetId, $externalResourceId, $accountId);
        $provenance['performance_trend'] = $campaignDailyGate->dataSourceState()->value;

        $campaignRows = ($campaignDailyGate->isUsable() && $campaignDailyGate->effectiveStart !== null && $campaignDailyGate->effectiveEnd !== null)
            ? $this->pool->campaignPerformance($digitalAssetId, $externalResourceId, $accountId, $campaignDailyGate->effectiveStart, $campaignDailyGate->effectiveEnd)
            : [];
        $adsetRows = ($adsetDailyGate->isUsable() && $adsetDailyGate->effectiveStart !== null && $adsetDailyGate->effectiveEnd !== null)
            ? $this->pool->adsetPerformance($digitalAssetId, $externalResourceId, $accountId, $adsetDailyGate->effectiveStart, $adsetDailyGate->effectiveEnd)
            : [];

        $data['campaigns'] = $this->realCampaigns($campaignRows, $adsetRows, $currency);
        $provenance['campaigns'] = $campaignDailyGate->dataSourceState()->value;

        $campaignNames = array_column($campaignRows, 'name', 'campaign_id');

        [$creativesData, $creativePulse] = $this->realCreatives(
            $adDailyGate,
            $creativeSnapshotGate,
            $digitalAssetId,
            $externalResourceId,
            $accountId,
            $rangeStart,
            $rangeEnd,
            $campaignNames,
        );
        $data['creatives'] = $creativesData;
        $data['creative_pulse'] = $creativePulse;
        $provenance['creatives'] = $this->combinedState($adDailyGate, $creativeSnapshotGate)->value;

        $data['audience'] = $this->realAudience($deliveryBreakdownGate, $digitalAssetId, $externalResourceId, $accountId, $rangeStart, $rangeEnd);
        $provenance['audience.age'] = $deliveryBreakdownGate->dataSourceState()->value;
        $provenance['audience.gender'] = $deliveryBreakdownGate->dataSourceState()->value;
        $provenance['audience.platform'] = $deliveryBreakdownGate->dataSourceState()->value;
        $provenance['audience.country'] = DataSourceState::Unavailable->value;

        $data['funnel'] = $this->realFunnel($adsetRows, $currency);
        $provenance['funnel'] = $this->combinedState($adsetDailyGate, $adsetSnapshotGate)->value;

        $data['measurement'] = $this->realMeasurement(
            $data['measurement'] ?? [],
            $typedActionGate,
            $digitalAssetId,
            $externalResourceId,
            $accountId,
            $rangeStart,
            $rangeEnd,
        );
        $provenance['measurement'] = $typedActionGate->dataSourceState()->value;

        // needs_attention/opportunities remain Demo — no Evidence/Findings pipeline is created here.
        $provenance['needs_attention'] = DataSourceState::Demo->value;
        $provenance['opportunities'] = DataSourceState::Demo->value;

        $gates = [
            self::DATASET_CAMPAIGN_DAILY => $campaignDailyGate,
            self::DATASET_ADSET_DAILY => $adsetDailyGate,
            self::DATASET_AD_DAILY => $adDailyGate,
            self::DATASET_TYPED_ACTION_DAILY => $typedActionGate,
            self::DATASET_DELIVERY_BREAKDOWN_DAILY => $deliveryBreakdownGate,
            self::DATASET_CAMPAIGN_SNAPSHOT => $campaignSnapshotGate,
            self::DATASET_ADSET_SNAPSHOT => $adsetSnapshotGate,
            self::DATASET_CREATIVE_SNAPSHOT => $creativeSnapshotGate,
            self::DATASET_AD_ACCOUNT_SNAPSHOT => $adAccountSnapshotGate,
        ];
        $data['operations']['collection_state'] = $this->realCollectionState($gates);
        $provenance['operations.collection_state'] = DataSourceState::Real->value;
        $provenance['operations.findings'] = DataSourceState::Demo->value;

        $data['demo_boundary'] = 'Real Meta Ads workspace · data pool + formulas — no live Meta Graph API call on page render.';
        $data['migration_mode'] = 'real';
        $data['currency'] = $currency;
        $data['data_provenance'] = $provenance;
        $data['tab_status'] = $this->rollupTabStatus($provenance);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function operationalWorkspace(
        MetaAdsBindingContext $binding,
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
            : ($binding->reason ?? 'no_active_meta_ads_binding');
        $statusLabel = $errorMessage !== null
            ? 'Error'
            : ($binding->mode === MetaAdsBindingMode::ActionRequired ? 'Action required' : 'Not connected');
        $collectionNote = $errorMessage !== null
            ? 'A read error occurred building this workspace — no data is shown and no Demo fixtures were substituted.'
            : "Meta Ads binding {$reason} — no collection state available.";

        $unavailableChip = ['value' => '—', 'raw' => null, 'secondary' => 'Unavailable', 'tone' => 'neutral'];

        $data = [
            'period_label' => $bounds['label'],
            'period_days' => $bounds['days'],
            'period_start' => $rangeStart,
            'period_end' => $rangeEnd,
            'compare_label' => 'vs '.$prev['label'],
            'demo_boundary' => 'Real Meta Ads workspace · no usable Ad Account binding — no live Meta Graph API call performed.',
            'identity' => [
                'eyebrow' => 'Meta Ads',
                'title' => $errorMessage !== null ? 'Meta Ads — read error' : 'Meta Ads — not connected',
                'brand' => null,
                'brand_id' => null,
                'brand_name' => null,
                'website_asset_id' => null,
                'meta_asset_id' => $binding->assetId,
                'strategy_line' => null,
                'status' => $statusLabel,
                'freshness' => null,
                'reporting_timezone' => null,
                'currency' => null,
                'ad_account' => null,
            ],
            'business_goal' => [
                'goal' => null,
                'primary_conversion' => null,
                'note' => 'Unavailable — '.$reason,
            ],
            'freshness' => [],
            'glance' => [
                'spend' => $unavailableChip,
                'result_mix' => $unavailableChip,
                'cost_primary' => $unavailableChip + ['note' => self::RESULTS_UNAVAILABLE_NOTE],
                'pacing' => $unavailableChip,
            ],
            'result_mix' => ['items' => [], 'note' => self::RESULTS_UNAVAILABLE_NOTE],
            'pacing' => [
                'source' => 'Unavailable',
                'monthly_budget' => 0,
                'planned_for_period' => 0,
                'elapsed_pct' => 0,
                'expected_spend' => 0,
                'actual_spend' => 0,
                'spend_pct' => 0,
                'remaining' => 0,
                'ahead_by' => 0,
                'state' => 'Unavailable',
                'summary' => 'Unavailable — '.$reason,
                'projected' => 0,
            ],
            'needs_attention' => [],
            'performance_trend' => [
                'labels' => [],
                'spend' => [],
                'leads' => [],
                'messaging' => [],
                'compare_label' => 'vs '.$prev['label'],
                'note' => 'Unavailable — '.$reason,
            ],
            'campaigns' => [],
            'campaigns_tab' => ['subtitle' => 'Unavailable — '.$reason],
            'creative_pulse' => [],
            'creatives' => [
                'subtitle' => 'Unavailable — '.$reason,
                'gallery' => [],
                'angles' => [],
                'coverage' => [],
                'persona_coverage' => [],
                'active_tests' => [],
                'tests' => [],
                'variants' => [],
            ],
            'audience' => [
                'subtitle' => 'Unavailable — '.$reason,
                'configured' => [],
                'observed' => [],
                'placements' => [],
                'age' => [],
                'country' => [],
                'gender' => [],
                'platform' => [],
                'concentration_note' => null,
                'gaps' => [],
            ],
            'funnel' => [
                'subtitle' => 'Unavailable — '.$reason,
                'destinations' => [],
                'instant_form' => [],
                'website' => [],
                'messaging' => [],
                'instagram_profile' => [],
                'message_match' => [],
                'shapes' => [],
            ],
            'measurement' => [
                'subtitle' => 'Unavailable — '.$reason,
                'missing_note' => 'Missing ≠ zero — absent business evidence is not a Meta result of 0.',
                'glance' => ['primary_mappings' => 0, 'healthy' => 'Unavailable', 'needs_mapping' => 0, 'findings' => 0],
                'matrix' => [],
                'interruption' => null,
                'business_funnel' => ['note' => 'Unavailable — '.$reason, 'steps' => []],
                'lead_quality' => ['source' => 'Unavailable', 'metrics' => [], 'note' => null],
                'debt' => [],
                'trust_chips' => [],
                'trust' => 'Unavailable — '.$reason,
                'finding_id' => null,
                'interpretation_note' => null,
            ],
            'operations' => [
                'subtitle' => $errorMessage !== null
                    ? 'Meta Ads read error — no findings, recommendations, tasks, or outcomes available.'
                    : 'Meta Ads binding required — connect an Ad Account to see findings, recommendations, tasks, and outcomes.',
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
            'narrative' => null,
            'currency' => null,
            'conversion_lag_note' => null,
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
    private function realIdentity(MetaAdsBindingContext $binding, ?DigitalAsset $asset, string $currency): array
    {
        $brandName = $asset?->brand?->name;
        $assetName = $asset?->name;
        $title = $assetName ?? 'Meta Ads account';

        return [
            'eyebrow' => 'Meta Ads',
            'title' => "{$title} — Meta Ads",
            'brand' => $brandName,
            'brand_id' => $asset?->brand_id,
            'brand_name' => $brandName,
            'website_asset_id' => null,
            'meta_asset_id' => $binding->assetId,
            'strategy_line' => $assetName !== null ? "Runs paid social for · {$assetName}" : null,
            'status' => 'Connected',
            'freshness' => null,
            'reporting_timezone' => $binding->timezone,
            'currency' => $currency,
            'ad_account' => ($assetName !== null ? "{$assetName} " : '').'(act_'.$binding->accountId.')',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $demoChips
     * @return list<array<string, mixed>>
     */
    private function realFreshnessChips(array $demoChips, MetaAdsDatasetReadiness $campaignDailyGate, string $accountId): array
    {
        $stateLabel = match ($campaignDailyGate->freshnessState) {
            'FRESH', 'FRESH_WITH_LIMITATION' => 'current',
            'STALE' => 'stale',
            default => 'attention',
        };

        $ageLabel = match ($campaignDailyGate->freshnessState) {
            'FRESH', 'FRESH_WITH_LIMITATION' => 'Fresh',
            'DUE' => 'Due',
            'STALE' => 'Stale',
            'PARTIAL' => 'Partial',
            'ACTION_REQUIRED' => 'Action required',
            'INTEGRITY_BLOCKED' => 'Blocked',
            default => 'Unknown',
        };

        $chips = $demoChips;
        foreach ($chips as $i => $chip) {
            if (($chip['source'] ?? null) === 'Meta Ads') {
                $chips[$i] = [
                    'source' => 'Meta Ads',
                    'age' => $ageLabel,
                    'detail' => "act_{$accountId} · meta_campaign_daily · {$campaignDailyGate->coverageState}",
                    'state' => $stateLabel,
                ];
            }
        }

        return $chips;
    }

    /**
     * @param  array{spend?: float, impressions?: int, clicks?: int, link_clicks?: ?int, outbound_clicks?: ?int, currency?: ?string}|null  $sums
     * @param  array{spend?: float, impressions?: int, clicks?: int, link_clicks?: ?int, outbound_clicks?: ?int, currency?: ?string}|null  $prevSums
     * @return array<string, mixed>
     */
    private function realGlance(
        MetaAdsDatasetReadiness $campaignDailyGate,
        ?array $sums,
        ?array $prevSums,
        string $currency,
    ): array {
        $resultMix = [
            'value' => 'Result mix unavailable',
            'raw' => null,
            'secondary' => 'No canonical result mapping',
            'tone' => 'neutral',
            'note' => self::RESULTS_UNAVAILABLE_NOTE,
        ];

        $costPrimary = [
            'value' => 'Cost / primary unavailable',
            'raw' => null,
            'secondary' => 'No canonical result mapping',
            'tone' => 'neutral',
            'note' => self::RESULTS_UNAVAILABLE_NOTE,
        ];

        $pacing = [
            'value' => 'Unavailable',
            'raw' => null,
            'secondary' => 'Unavailable',
            'tone' => 'neutral',
            'note' => 'Pacing unavailable — agency planned budget is not stored in the data pool.',
        ];

        if (! $campaignDailyGate->isUsable() || $sums === null) {
            $note = 'Spend unavailable — meta_campaign_daily dataset is not ready for real UI. Unavailable ≠ zero.';
            $unavailable = ['value' => '—', 'raw' => null, 'secondary' => 'Unavailable', 'tone' => 'neutral', 'note' => $note];

            return [
                'spend' => $unavailable,
                'result_mix' => $resultMix,
                'cost_primary' => $costPrimary,
                'pacing' => $pacing,
            ];
        }

        $spendDelta = $prevSums !== null
            ? $this->formulas->periodRelativeChange((float) $sums['spend'], (float) $prevSums['spend'])
            : null;

        $spend = [
            'value' => $this->formatMoney((float) $sums['spend'], $currency),
            'raw' => round((float) $sums['spend'], 2),
            'secondary' => $this->deltaSecondary($spendDelta, $campaignDailyGate),
            'tone' => 'neutral',
        ];

        if ($campaignDailyGate->coverageState === MetaAdsDatasetReadiness::COVERAGE_PARTIALLY_COVERED) {
            $spend['note'] = 'Partial coverage — spend reflects only collected days in this range.';
        }

        return [
            'spend' => $spend,
            'result_mix' => $resultMix,
            'cost_primary' => $costPrimary,
            'pacing' => $pacing,
        ];
    }

    private function deltaSecondary(?FormulaResult $delta, MetaAdsDatasetReadiness $gate): string
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
        MetaAdsDatasetReadiness $campaignDailyGate,
        int $digitalAssetId,
        int $externalResourceId,
        string $accountId,
    ): array {
        if (! $campaignDailyGate->isUsable() || $campaignDailyGate->effectiveStart === null || $campaignDailyGate->effectiveEnd === null) {
            return [
                'labels' => [],
                'spend' => [],
                'leads' => [],
                'messaging' => [],
                'note' => 'Performance trend unavailable — meta_campaign_daily dataset is not ready for real UI.',
            ];
        }

        $series = $this->pool->campaignDailySeries($digitalAssetId, $externalResourceId, $accountId, $campaignDailyGate->effectiveStart, $campaignDailyGate->effectiveEnd);

        $labels = [];
        $spend = [];
        foreach ($series as $point) {
            $labels[] = CarbonImmutable::parse($point['date'])->format('M j');
            $spend[] = round($point['spend'], 2);
        }

        $partial = $campaignDailyGate->coverageState === MetaAdsDatasetReadiness::COVERAGE_PARTIALLY_COVERED;

        return [
            'labels' => $labels,
            'spend' => $spend,
            // "leads" is the frozen chart key from the demo fixture — real typed-action
            // counts are not available as a daily series in Prompt 31 (only as a period
            // total), so this stays empty rather than fabricating a per-day series.
            'leads' => [],
            'messaging' => [],
            'note' => ($partial ? 'Spend · real Meta Ads data (partial coverage). ' : 'Spend · real Meta Ads data. ')
                .'Daily typed-action ("leads") series is Unavailable in Prompt 31 — provider actions are not automatically Qualified Leads. '
                .self::REACH_NOTE.' '.self::FREQUENCY_NOTE,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $campaignRows
     * @param  list<array<string, mixed>>  $adsetRows
     * @return list<array<string, mixed>>
     */
    private function realCampaigns(array $campaignRows, array $adsetRows, string $currency): array
    {
        $adsetsByCampaign = [];
        foreach ($adsetRows as $adset) {
            $campaignId = $adset['campaign_id'];
            if ($campaignId === null) {
                continue;
            }
            $adsetsByCampaign[$campaignId][] = $adset;
        }

        return array_map(function (array $row) use ($adsetsByCampaign, $currency): array {
            $campaignAdsets = $adsetsByCampaign[$row['campaign_id']] ?? [];

            $optimizationGoals = array_values(array_unique(array_filter(
                array_map(static fn (array $a): ?string => $a['optimization_goal'], $campaignAdsets),
            )));
            $optimization = count($optimizationGoals) === 1 ? $this->humanizeIdentifier($optimizationGoals[0]) : null;

            $destinationTypes = array_values(array_unique(array_filter(
                array_map(static fn (array $a): ?string => $a['destination_type'], $campaignAdsets),
            )));
            $destination = count($destinationTypes) === 1 ? $this->humanizeIdentifier($destinationTypes[0]) : null;

            $adSets = array_map(static fn (array $a): array => [
                'id' => $a['adset_id'],
                'name' => $a['name'],
                'status' => $a['status'],
                'audience' => null,
                'spend' => round((float) $a['spend'], 2),
                'results' => 0,
                'result_label' => 'Unavailable',
            ], $campaignAdsets);

            return [
                'id' => $row['campaign_id'],
                'name' => $row['name'],
                'status' => $row['status'],
                'objective' => $row['objective'] !== null ? $this->humanizeIdentifier((string) $row['objective']) : null,
                'objective_family' => null,
                'optimization' => $optimization,
                'destination' => $destination,
                'result_label' => 'Unavailable',
                'offering' => null,
                'market' => null,
                'language' => null,
                'goal' => null,
                'funnel' => null,
                'attention' => [],
                'attention_primary' => null,
                'story' => null,
                'pacing' => 'Unavailable',
                'spend' => round((float) $row['spend'], 2),
                'results' => 0,
                'cost_result' => 0,
                'impressions' => (int) $row['impressions'],
                'reach' => null,
                'link_clicks' => $row['link_clicks'],
                'frequency' => null,
                'ctr' => $row['impressions'] > 0 ? round(($row['clicks'] / $row['impressions']) * 100, 2) : null,
                'delivered' => $row['spend'] > 0 || $row['impressions'] > 0,
                'currency' => $row['currency'] ?? $currency,
                'ad_sets' => $adSets,
                'results_note' => self::RESULTS_UNAVAILABLE_NOTE,
                'reach_note' => self::REACH_NOTE,
                'frequency_note' => self::FREQUENCY_NOTE,
                'clicks_note' => self::CLICKS_NOTE,
            ];
        }, $campaignRows);
    }

    /**
     * @param  array<string, string>  $campaignNames
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function realCreatives(
        MetaAdsDatasetReadiness $adDailyGate,
        MetaAdsDatasetReadiness $creativeSnapshotGate,
        int $digitalAssetId,
        int $externalResourceId,
        string $accountId,
        string $rangeStart,
        string $rangeEnd,
        array $campaignNames,
    ): array {
        if (! $creativeSnapshotGate->isUsable()) {
            $creatives = [
                'subtitle' => 'Creatives unavailable — meta_creative_snapshot dataset is not ready for real UI.',
                'gallery' => [],
                'angles' => [],
                'coverage' => [],
                'persona_coverage' => [],
                'active_tests' => [],
                'tests' => [],
                'variants' => [],
            ];

            return [$creatives, []];
        }

        $snapshots = $this->pool->creativeSnapshots($digitalAssetId, $accountId);

        $adRows = ($adDailyGate->isUsable() && $adDailyGate->effectiveStart !== null && $adDailyGate->effectiveEnd !== null)
            ? $this->pool->topAdsWithCreatives($digitalAssetId, $externalResourceId, $accountId, $adDailyGate->effectiveStart, $adDailyGate->effectiveEnd)
            : [];

        // Ads are already aggregated per ad_id across the date range by the repository —
        // grouping those totals by creative_id here sums each ad's spend exactly once,
        // never fanning spend out across multiple creatives.
        $byCreative = [];
        foreach ($adRows as $ad) {
            $creativeId = $ad['creative_id'];
            if ($creativeId === null) {
                continue;
            }
            if (! isset($byCreative[$creativeId])) {
                $byCreative[$creativeId] = ['spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'campaign_id' => null];
            }
            $byCreative[$creativeId]['spend'] += (float) $ad['spend'];
            $byCreative[$creativeId]['impressions'] += (int) $ad['impressions'];
            $byCreative[$creativeId]['clicks'] += (int) $ad['clicks'];
            $byCreative[$creativeId]['campaign_id'] ??= $ad['campaign_id'];
        }

        $gradients = ['trust', 'price', 'transform', 'expert'];

        $gallery = [];
        foreach ($snapshots as $snapshot) {
            $creativeId = $snapshot['creative_id'];
            $agg = $byCreative[$creativeId] ?? ['spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'campaign_id' => null];
            $campaignId = $agg['campaign_id'];

            $headline = $snapshot['title'] ?? ($snapshot['body'] !== null ? Str::limit((string) $snapshot['body'], 120) : null);

            $gallery[] = [
                'id' => $creativeId,
                'name' => $snapshot['name'] ?? ('Creative '.$creativeId),
                'angle' => null,
                'thumb' => $gradients[abs(crc32($creativeId)) % count($gradients)],
                'thumb_gradient' => $gradients[abs(crc32($creativeId)) % count($gradients)],
                'campaign_id' => $campaignId,
                'campaign' => $campaignId !== null ? ($campaignNames[$campaignId] ?? 'Campaign '.$campaignId) : null,
                'campaign_name' => $campaignId !== null ? ($campaignNames[$campaignId] ?? 'Campaign '.$campaignId) : null,
                'format' => $this->humanizeCreativeFormat($snapshot['object_type']),
                'status' => $snapshot['status'] ?? 'UNKNOWN',
                'persona' => null,
                'spend' => round($agg['spend'], 2),
                'results' => 0,
                'result' => 0,
                'result_label' => 'Unavailable',
                'cost_result' => 0,
                'frequency' => null,
                'ctr' => $agg['impressions'] > 0 ? round(($agg['clicks'] / $agg['impressions']) * 100, 2) : null,
                'note' => null,
                'signal' => null,
                'headline' => $headline,
                'frequency_from' => null,
                'frequency_to' => null,
                'period_start' => $rangeStart,
                'period_end' => $rangeEnd,
                'results_note' => self::RESULTS_UNAVAILABLE_NOTE,
            ];
        }

        usort($gallery, static fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        $pulse = array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'name' => $row['name'],
            'thumb' => $row['thumb'],
            'format' => $row['format'],
            'spend' => $row['spend'],
            'result' => $row['result'],
            'result_label' => $row['result_label'],
            'signal' => $row['signal'],
        ], array_slice($gallery, 0, 4));

        $creatives = [
            'subtitle' => 'Real Meta creative inventory — creative angle, persona coverage and A/B test taxonomy are Unavailable in Prompt 31 (no creative-tagging pipeline).',
            'gallery' => $gallery,
            'angles' => [],
            'coverage' => [],
            'persona_coverage' => [],
            'active_tests' => [],
            'tests' => [],
            'variants' => [],
        ];

        return [$creatives, $pulse];
    }

    /**
     * @return array<string, mixed>
     */
    private function realAudience(
        MetaAdsDatasetReadiness $deliveryBreakdownGate,
        int $digitalAssetId,
        int $externalResourceId,
        string $accountId,
        string $rangeStart,
        string $rangeEnd,
    ): array {
        if (! $deliveryBreakdownGate->isUsable() || $deliveryBreakdownGate->effectiveStart === null || $deliveryBreakdownGate->effectiveEnd === null) {
            return [
                'subtitle' => 'Audience unavailable — meta_delivery_breakdown_daily dataset is not ready for real UI.',
                'configured' => [],
                'observed' => [],
                'placements' => [],
                'age' => [],
                'country' => [],
                'gender' => [],
                'platform' => [],
                'concentration_note' => null,
                'gaps' => [
                    'Country breakdown is not collected in Prompt 31 — Unavailable, not zero.',
                    'Placement (platform_position) breakdown is not collected in Prompt 31 — Unavailable, not zero.',
                ],
            ];
        }

        $ageRows = $this->deliveryBarRows('age', $digitalAssetId, $externalResourceId, $accountId, $rangeStart, $rangeEnd);
        $genderRows = $this->deliveryBarRows('gender', $digitalAssetId, $externalResourceId, $accountId, $rangeStart, $rangeEnd);
        $platformRows = $this->deliveryBarRows('publisher_platform', $digitalAssetId, $externalResourceId, $accountId, $rangeStart, $rangeEnd);

        $observed = [];
        if ($ageRows !== []) {
            $observed[] = ['label' => 'Top age band', 'value' => $ageRows[0]['label']];
        }
        if ($genderRows !== []) {
            $observed[] = ['label' => 'Top gender', 'value' => $genderRows[0]['label']];
        }
        if ($platformRows !== []) {
            $observed[] = ['label' => 'Top platform', 'value' => $platformRows[0]['label']];
        }

        return [
            'subtitle' => 'How Meta actually distributed spend across observed age, gender and platform breakdowns — country and placement are Unavailable in Prompt 31.',
            'configured' => [],
            'observed' => $observed,
            'placements' => [],
            'age' => $ageRows,
            'country' => [],
            'gender' => $genderRows,
            'platform' => $platformRows,
            'concentration_note' => $platformRows !== [] ? ($platformRows[0]['label'].' carries the largest observed spend share — informational until creative fit is reviewed.') : null,
            'gaps' => [
                'Country breakdown is not collected in Prompt 31 — Unavailable, not zero.',
                'Placement (platform_position) breakdown is not collected in Prompt 31 — Unavailable, not zero.',
                'Configured targeting is not read from the provider on the real path — Unavailable, not zero.',
            ],
        ];
    }

    /**
     * @return list<array{label: string, spend: float, share: int}>
     */
    private function deliveryBarRows(
        string $breakdownType,
        int $digitalAssetId,
        int $externalResourceId,
        string $accountId,
        string $start,
        string $end,
    ): array {
        $rows = $this->pool->deliveryBreakdowns($breakdownType, $digitalAssetId, $externalResourceId, $accountId, $start, $end);
        $total = array_sum(array_column($rows, 'spend'));

        return array_map(fn (array $row): array => [
            'label' => $this->humanizeBreakdownValue($breakdownType, $row['breakdown_value']),
            'spend' => round($row['spend'], 2),
            'share' => $total > 0 ? (int) round(($row['spend'] / $total) * 100) : 0,
        ], $rows);
    }

    private function humanizeBreakdownValue(string $type, string $value): string
    {
        if ($type === 'age') {
            return $value;
        }

        if ($type === 'gender') {
            return match (strtolower($value)) {
                'male' => 'Male',
                'female' => 'Female',
                'unknown' => 'Unknown',
                default => Str::title($value),
            };
        }

        return match (strtolower($value)) {
            'facebook' => 'Facebook',
            'instagram' => 'Instagram',
            'messenger' => 'Messenger',
            'audience_network' => 'Audience Network',
            default => Str::title(str_replace('_', ' ', $value)),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $adsetRows
     * @return array<string, mixed>
     */
    private function realFunnel(array $adsetRows, string $currency): array
    {
        $byDestination = [];
        foreach ($adsetRows as $adset) {
            $destinationType = $adset['destination_type'];
            if ($destinationType === null) {
                continue;
            }
            if (! isset($byDestination[$destinationType])) {
                $byDestination[$destinationType] = 0.0;
            }
            $byDestination[$destinationType] += (float) $adset['spend'];
        }

        $total = array_sum($byDestination);

        $destinations = [];
        foreach ($byDestination as $type => $spend) {
            $destinations[] = [
                'label' => $this->humanizeDestinationType($type),
                'destination' => $this->humanizeDestinationType($type),
                'campaigns' => [],
                'spend' => round($spend, 2),
                'results' => 0,
                'result_label' => 'Unavailable',
                'share' => $total > 0 ? (int) round(($spend / $total) * 100) : 0,
            ];
        }
        usort($destinations, static fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        $note = 'Real Meta ad set destination_type · typed actions are not linked per destination in Prompt 31 — results Unavailable.';

        $instantForm = $this->destinationSection($byDestination, ['LEAD_FORM']);
        $website = $this->destinationSection($byDestination, ['WEBSITE']);
        $messaging = $this->destinationSection($byDestination, ['MESSENGER', 'INSTAGRAM_DIRECT', 'WHATSAPP']);
        $instagramProfile = $this->destinationSection($byDestination, ['INSTAGRAM_PROFILE', 'INSTAGRAM_PROFILE_AND_FACEBOOK_PAGE']);

        return [
            'subtitle' => $destinations !== []
                ? 'Spend distribution by real Meta ad set destination_type — downstream results are Unavailable in Prompt 31 (no lead-form/CRM/Website join).'
                : 'Funnel unavailable — no ad set destination_type observed for real ad sets in this range.',
            'destinations' => $destinations,
            'instant_form' => [
                'spend' => $instantForm,
                'leads' => 0,
                'cost_lead' => 0,
                'complete_rate' => '—',
                'notes' => [$note],
                'attention' => null,
            ],
            'website' => [
                'spend' => $website,
                'landings' => null,
                'primary_action' => 'Unavailable',
                'message_match' => 'Unavailable',
                'note' => $note,
            ],
            'messaging' => [
                'spend' => $messaging,
                'conversations' => 0,
                'cost_conversation' => 0,
                'downstream' => 'Unavailable',
                'state' => 'Unavailable',
                'note' => $note,
            ],
            'instagram_profile' => [
                'spend' => $instagramProfile,
                'visits' => 0,
                'profile_visits' => 0,
                'role' => 'Unavailable',
                'state' => 'Unavailable',
                'cost_visit' => 0,
                'note' => $note,
            ],
            'message_match' => [],
            'shapes' => [],
        ];
    }

    /**
     * @param  array<string, float>  $byDestination
     * @param  list<string>  $types
     */
    private function destinationSection(array $byDestination, array $types): float
    {
        $sum = 0.0;
        foreach ($types as $type) {
            $sum += $byDestination[$type] ?? 0.0;
        }

        return round($sum, 2);
    }

    private function humanizeDestinationType(string $type): string
    {
        return match (strtoupper($type)) {
            'WEBSITE' => 'Website',
            'APP' => 'App',
            'MESSENGER' => 'Messaging',
            'INSTAGRAM_DIRECT' => 'Instagram Direct',
            'WHATSAPP' => 'WhatsApp',
            'LEAD_FORM' => 'Instant Form',
            'INSTAGRAM_PROFILE', 'INSTAGRAM_PROFILE_AND_FACEBOOK_PAGE' => 'Instagram Profile',
            'ON_AD', 'ON_POST', 'ON_EVENT', 'ON_VIDEO', 'ON_PAGE' => 'On Facebook/Instagram',
            'PHONE_CALL' => 'Phone Call',
            'SHOP_AUTOMATIC', 'SHOP' => 'Shop',
            'UNDEFINED', '' => 'Unknown',
            default => Str::title(strtolower(str_replace('_', ' ', $type))),
        };
    }

    /**
     * @param  array<string, mixed>  $demoMeasurement
     * @return array<string, mixed>
     */
    private function realMeasurement(
        array $demoMeasurement,
        MetaAdsDatasetReadiness $typedActionGate,
        int $digitalAssetId,
        int $externalResourceId,
        string $accountId,
        string $rangeStart,
        string $rangeEnd,
    ): array {
        $mappingNote = 'Matrix reflects real Meta Ads typed actions — action_type is kept distinct and no generic "Results" metric is used. '
            .self::ACTION_NOTE.' Business outcome funnel / lead quality narrative above remains Demo Mode illustrative content; no Business Action mapping is configured in Prompt 31.';

        if (! $typedActionGate->isUsable() || $typedActionGate->effectiveStart === null || $typedActionGate->effectiveEnd === null) {
            return array_merge($demoMeasurement, [
                'subtitle' => 'Measurement unavailable — meta_typed_action_daily dataset is not ready for real UI.',
                'matrix' => [],
                'mapping_trust_note' => 'Typed actions unavailable — meta_typed_action_daily dataset is not ready for real UI.',
            ]);
        }

        $actions = $this->pool->typedActions($digitalAssetId, $externalResourceId, $accountId, $rangeStart, $rangeEnd);

        $matrix = array_map(fn (array $action): array => [
            'action' => $this->humanizeIdentifier($action['action_type']),
            'action_type' => $action['action_type'],
            'meta_result' => null,
            'source' => 'Meta Ads typed action',
            'role' => 'Observed',
            'state' => $action['rows'] > 0 ? 'Observed' : 'No recent signal',
            'action_value' => $action['action_value'],
            'currency' => $action['currency'],
            'note' => self::ACTION_NOTE,
        ], $actions);

        return array_merge($demoMeasurement, [
            'subtitle' => 'Whether real Meta typed actions can be connected to meaningful business outcomes — matrix below reflects real observed action types.',
            'matrix' => $matrix,
            'mapping_trust_note' => $mappingNote,
        ]);
    }

    /**
     * @param  array<string, MetaAdsDatasetReadiness>  $gates
     * @return array<string, mixed>
     */
    private function realCollectionState(array $gates): array
    {
        return [
            'note' => 'Real Meta Ads collection/materialization/freshness/integrity/coverage state. Findings, Recommendations, Tasks and Outcomes below remain Demo — this migration creates no Evidence/Findings/Opportunities.',
            'datasets' => array_map(static fn (MetaAdsDatasetReadiness $g): array => $g->toArray(), $gates),
        ];
    }

    private function combinedState(MetaAdsDatasetReadiness ...$gates): DataSourceState
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

    private function humanizeIdentifier(string $identifier): string
    {
        return Str::title(strtolower(str_replace('_', ' ', $identifier)));
    }

    private function humanizeCreativeFormat(?string $objectType): string
    {
        if ($objectType === null) {
            return 'Unknown';
        }

        return match (strtoupper($objectType)) {
            'VIDEO' => 'Video',
            'PHOTO', 'SHARE' => 'Image',
            'CAROUSEL' => 'Carousel',
            default => 'Unknown',
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
