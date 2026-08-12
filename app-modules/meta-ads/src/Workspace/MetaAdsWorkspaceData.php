<?php

namespace MoxDop\MetaAds\Workspace;

use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Integrations\ComparisonPeriod;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use MoxDop\MetaAds\Ai\MetaAdsAiGuidanceConfig;
use MoxDop\MetaAds\Ai\MetaAdsAiGuidanceService;
use MoxDop\MetaAds\Collection\MetaAdsBoundCollector;
use MoxDop\MetaAds\Normalization\MetaActionNormalizer;
use MoxDop\MetaAds\Normalization\MetaResultResolver;
use MoxDop\MetaAds\Support\MetaAdsWorkspaceData as MetaAdsConnectionSummary;

/**
 * Meta Ads workspace presenter over latest Evidence / Findings / AI Guidance.
 *
 * Presentation only — never invents metrics, percentages, or historical
 * warehouse data that Evidence does not support.
 */
final class MetaAdsWorkspaceData
{
    private const array SEVERITY_WEIGHT = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

    /**
     * @param  array<string, mixed>|null  $filters
     * @return array<string, mixed>
     */
    public function for(DigitalAsset $asset, ?array $filters = null): array
    {
        $asset->loadMissing('brand');
        $filters = $filters ?? MetaWorkspaceFilters::get((int) $asset->id);
        $selectedPeriod = $this->resolveSelectedPeriod($filters);

        $account = $this->evidenceForPeriod($asset, MetaAdsBoundCollector::EVIDENCE_ACCOUNT_SUMMARY, $selectedPeriod['current']);
        $campaigns = $this->evidenceForPeriod($asset, MetaAdsBoundCollector::EVIDENCE_CAMPAIGN_PERFORMANCE, $selectedPeriod['current']);
        $adsets = $this->evidenceForPeriod($asset, MetaAdsBoundCollector::EVIDENCE_ADSET_PERFORMANCE, $selectedPeriod['current']);
        $ads = $this->evidenceForPeriod($asset, MetaAdsBoundCollector::EVIDENCE_AD_PERFORMANCE, $selectedPeriod['current']);
        $creatives = $this->evidenceForPeriod($asset, MetaAdsBoundCollector::EVIDENCE_CREATIVE_METADATA, $selectedPeriod['current']);
        $daily = $this->evidenceForPeriod($asset, MetaAdsBoundCollector::EVIDENCE_ACCOUNT_DAILY_TREND, $selectedPeriod['current']);

        $periodMatched = $account !== null || $campaigns !== null;
        $latestAnyAccount = $this->latestEvidence($asset, MetaAdsBoundCollector::EVIDENCE_ACCOUNT_SUMMARY);

        $period = data_get($account?->payload, 'requested_period')
            ?? data_get($campaigns?->payload, 'requested_period')
            ?? $selectedPeriod['current'];
        $comparisonPeriod = data_get($account?->payload, 'comparison_period')
            ?? data_get($campaigns?->payload, 'comparison_period')
            ?? $selectedPeriod['previous'];

        $lastUpdated = collect([$account, $campaigns, $adsets, $ads, $creatives])
            ->filter()
            ->map(fn (Evidence $e) => $e->observed_at)
            ->filter()
            ->sortDesc()
            ->first();

        $latestRun = Run::query()
            ->where('digital_asset_id', $asset->id)
            ->where('module_id', MetaAdsBoundCollector::MODULE_ID)
            ->latest('started_at')
            ->first();

        $asyncCollect = Run::query()
            ->where('digital_asset_id', $asset->id)
            ->where('metadata->async', true)
            ->where('metadata->operation_type', 'bound_collect')
            ->whereIn('status', ['queued', 'running'])
            ->latest('id')
            ->first();

        $findings = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'acknowledged' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderByDesc('last_seen_at')
            ->limit(40)
            ->get();

        $recommendations = Recommendation::query()
            ->where('digital_asset_id', $asset->id)
            ->whereIn('status', ['open', 'accepted'])
            ->orderByRaw("CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get();

        $connections = $this->connectionCards($asset);
        $connectionSummary = MetaAdsConnectionSummary::forAsset($asset);

        $campaignRows = $this->filterCampaignRows($this->boundedEntityRows($campaigns), $filters);
        $dataCoverage = $this->dataCoverage($account, $campaigns, $adsets, $ads, $creatives, $campaignRows);
        $comparison = $this->comparisonAvailability($account, $comparisonPeriod);
        $compareOn = ($filters['compare'] ?? true) === true && $comparison['available'] === true;

        return [
            'asset' => $asset,
            'filters' => $filters,
            'selected_period' => $selectedPeriod,
            'period_matched' => $periodMatched,
            'needs_analyze' => ! $periodMatched && $latestAnyAccount !== null,
            'period' => $period,
            'comparison_period' => $comparisonPeriod,
            'period_label' => $this->periodLabel($period, $comparisonPeriod, $compareOn),
            'last_updated' => $lastUpdated,
            'last_updated_human' => $lastUpdated instanceof CarbonInterface
                ? $lastUpdated->diffForHumans()
                : null,
            'account_identity' => $this->accountIdentity($account ?? $latestAnyAccount, $connectionSummary),
            'data_coverage' => $dataCoverage,
            'data_health' => $this->dataHealthBadge($dataCoverage, $latestRun, $asyncCollect),
            'workspace_state' => $this->workspaceState($connectionSummary, $account, $campaigns, $latestRun, $dataCoverage),
            'partial_reasons' => $this->partialReasons($latestRun),
            'latest_run_status' => $latestRun?->status,
            'async_collection' => $asyncCollect ? [
                'status' => $asyncCollect->status,
                'phase_label' => data_get($asyncCollect->metadata, 'phase_label'),
                'run_id' => $asyncCollect->id,
            ] : null,
            'kpis' => $periodMatched ? $this->priorityKpis($account, $compareOn) : [],
            'kpis_full' => $periodMatched ? $this->accountKpis($account, $compareOn) : [],
            'primary_result' => $this->primaryResultSummary($account),
            'result_mix' => $this->resultMixSummary($account),
            'raw_result_signals' => $this->rawResultSignalsSummary($account),
            'trend' => $this->trendSeries($daily, (string) ($filters['trend_metric'] ?? 'spend')),
            'delivery_flow' => $this->deliveryFlow($account),
            'attention' => $this->attentionItems($findings, $campaignRows),
            'collection_stages' => is_array($latestRun?->metadata['collection_stages'] ?? null)
                ? $latestRun->metadata['collection_stages']
                : [],
            'campaigns' => $campaignRows,
            'campaign_snapshot' => array_slice($campaignRows, 0, 8),
            'adsets' => $this->boundedEntityRows($adsets),
            'ads' => $this->boundedEntityRows($ads),
            'creatives' => $this->boundedCreativeRows($creatives, $ads),
            'actions_note' => $this->actionsNote($account),
            'findings' => [
                'open' => $findings->where('status', 'open')->values(),
                'acknowledged' => $findings->where('status', 'acknowledged')->values(),
                'resolved' => $findings->where('status', 'resolved')->values(),
                'all' => $findings,
                'counts' => [
                    'open' => Finding::query()->where('digital_asset_id', $asset->id)->where('status', 'open')->count(),
                    'acknowledged' => Finding::query()->where('digital_asset_id', $asset->id)->where('status', 'acknowledged')->count(),
                    'resolved' => Finding::query()->where('digital_asset_id', $asset->id)->where('status', 'resolved')->count(),
                    'high' => Finding::query()->where('digital_asset_id', $asset->id)->where('status', 'open')->whereIn('severity', ['critical', 'high'])->count(),
                ],
            ],
            'finding_groups' => $this->findingGroups($findings),
            'recommendations' => $recommendations,
            'ai_guidance' => $this->aiGuidance($asset),
            'connections' => $connections,
            'connection_summary' => $connectionSummary,
            'connection_health' => $this->connectionHealthLine($connections, $connectionSummary),
            'activity' => $this->activityRows($asset),
            'has_performance_data' => $periodMatched,
            'caveats' => $periodMatched ? $this->caveats($account) : [],
            'comparison' => $comparison,
            'preset_labels' => ComparisonPeriod::presetLabels(),
        ];
    }

    private function latestEvidence(DigitalAsset $asset, string $type): ?Evidence
    {
        return Evidence::query()
            ->where('digital_asset_id', $asset->id)
            ->where('type', $type)
            ->where('source_module', MetaAdsBoundCollector::MODULE_ID)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array{start: string, end: string}  $period
     */
    private function evidenceForPeriod(DigitalAsset $asset, string $type, array $period): ?Evidence
    {
        $exact = Evidence::query()
            ->where('digital_asset_id', $asset->id)
            ->where('type', $type)
            ->where('source_module', MetaAdsBoundCollector::MODULE_ID)
            ->where('payload->requested_period->start', $period['start'])
            ->where('payload->requested_period->end', $period['end'])
            ->orderByDesc('id')
            ->first();

        if ($exact !== null) {
            return $exact;
        }

        // Transitional: if filters request default last_30 and only legacy 28-day Evidence exists, do not pretend match.
        return null;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     current: array{start: string, end: string},
     *     previous: array{start: string, end: string},
     *     timezone: string,
     *     complete_days: int,
     *     preset: string
     * }
     */
    private function resolveSelectedPeriod(array $filters): array
    {
        $preset = (string) ($filters['period_preset'] ?? ComparisonPeriod::PRESET_LAST_30);

        try {
            return ComparisonPeriod::forPreset(
                $preset,
                isset($filters['period_start']) ? (string) $filters['period_start'] : null,
                isset($filters['period_end']) ? (string) $filters['period_end'] : null,
                (bool) ($filters['compare'] ?? true),
            );
        } catch (\Throwable) {
            return ComparisonPeriod::forPreset(ComparisonPeriod::PRESET_LAST_30);
        }
    }

    /**
     * Operator-facing Ad Account / Meta Business identity. Combines the bound
     * ExternalResource (connection layer) with the latest collected account
     * Evidence — never invents identity fields that neither source provides.
     *
     * @param  array<string, mixed>  $connectionSummary
     * @return array<string, mixed>
     */
    private function accountIdentity(?Evidence $account, array $connectionSummary): array
    {
        $resource = $connectionSummary['bound_resource'] ?? null;
        $resourceMeta = is_array($resource?->metadata ?? null) ? $resource->metadata : [];

        return [
            'name' => data_get($account?->payload, 'account_name')
                ?? $resource?->display_name
                ?? ($connectionSummary['account_label'] ?? null),
            'external_id' => $resource?->external_id ?? data_get($account?->payload, 'account_id'),
            'business_name' => data_get($account?->payload, 'business_name') ?? ($resourceMeta['business_name'] ?? null),
            'business_id' => data_get($account?->payload, 'business_id') ?? ($resourceMeta['business_id'] ?? null),
            'currency' => data_get($account?->payload, 'currency') ?? ($resourceMeta['currency'] ?? null),
            'timezone' => data_get($account?->payload, 'timezone_name') ?? ($resourceMeta['timezone_name'] ?? null),
        ];
    }

    /**
     * Categorical (never percentage) data coverage per workspace area, derived
     * strictly from Evidence presence and response_ok/truncated flags.
     *
     * @param  list<array<string, mixed>>  $campaignRows
     * @return array<string, string>
     */
    private function dataCoverage(?Evidence $account, ?Evidence $campaigns, ?Evidence $adsets, ?Evidence $ads, ?Evidence $creatives, array $campaignRows): array
    {
        return [
            'account' => $this->evidenceCoverage($account),
            'campaigns' => $this->evidenceCoverage($campaigns),
            'adsets' => $this->evidenceCoverage($adsets),
            'ads' => $this->evidenceCoverage($ads),
            'creative' => $this->evidenceCoverage($creatives),
            'attribution_context' => $this->attributionCoverage($account, $campaignRows),
            'result_signal' => $this->resultSignalCoverage($campaignRows),
            'business_validation' => $this->businessValidationCoverage($account),
        ];
    }

    /**
     * @return 'Complete'|'Partial'|'Unavailable'|'Unknown'
     */
    private function evidenceCoverage(?Evidence $evidence): string
    {
        if ($evidence === null) {
            return 'Unknown';
        }

        $responseOk = data_get($evidence->payload, 'response_ok');
        if ($responseOk === false) {
            return 'Partial';
        }

        if (data_get($evidence->payload, 'metrics_usable') === false
            && data_get($evidence->payload, 'metadata_usable') !== true) {
            return 'Unavailable';
        }

        if ((bool) data_get($evidence->payload, 'truncated')) {
            return 'Partial';
        }

        $missed = (int) data_get($evidence->payload, 'metadata_join.missed', 0);
        if ($missed > 0) {
            return 'Partial';
        }

        $rows = data_get($evidence->payload, 'rows');
        if (is_array($rows) && $rows === [] && data_get($evidence->payload, 'row_count') === 0) {
            return 'Unavailable';
        }

        return 'Complete';
    }

    /**
     * Attribution context (provider setting presence) — not business validation.
     *
     * @param  list<array<string, mixed>>  $campaignRows
     * @return 'Known'|'Partial'|'Unavailable'|'Unknown'
     */
    private function attributionCoverage(?Evidence $account, array $campaignRows): string
    {
        if ($account === null && $campaignRows === []) {
            return 'Unknown';
        }

        $accountAttribution = data_get($account?->payload, 'current.attribution_setting');
        $total = count($campaignRows);
        $withAttribution = collect($campaignRows)->filter(fn (array $row): bool => filled($row['attribution_setting'] ?? null))->count();

        if ($total === 0) {
            return $accountAttribution !== null ? 'Known' : 'Unavailable';
        }

        if ($withAttribution === $total) {
            return 'Known';
        }

        return $withAttribution > 0 ? 'Partial' : 'Unavailable';
    }

    /**
     * Platform primary-result signal coverage — never implies CRM/business verified outcomes.
     *
     * @param  list<array<string, mixed>>  $campaignRows
     * @return 'Resolved'|'Mixed'|'Unresolved'|'Unknown'
     */
    private function resultSignalCoverage(array $campaignRows): string
    {
        if ($campaignRows === []) {
            return 'Unknown';
        }

        $statuses = collect($campaignRows)->pluck('primary_result_status')->filter();
        if ($statuses->isEmpty()) {
            return 'Unknown';
        }

        $resolvedLike = $statuses->filter(fn (?string $status): bool => in_array($status, ['resolved', 'zero'], true))->count();
        $unresolved = $statuses->filter(fn (?string $status): bool => $status === 'unresolved')->count();
        $total = $statuses->count();

        if ($resolvedLike === $total) {
            return 'Resolved';
        }

        if ($unresolved === $total) {
            return 'Unresolved';
        }

        return 'Mixed';
    }

    /**
     * Business/CRM validation of platform results. Meta Intelligence alone cannot verify leads/profit.
     *
     * @return 'Verified'|'Not connected'|'Unavailable'
     */
    private function businessValidationCoverage(?Evidence $account): string
    {
        if ($account === null) {
            return 'Unavailable';
        }

        // No CRM / business Evidence connection in Meta Ads Intelligence V1.
        return 'Not connected';
    }

    /**
     * One of: no_connection | no_data | collection_failed | collection_partial | data_available.
     *
     * Collection completeness is NOT the same as result-signal Mixed/Unresolved.
     * Mixed primary-result semantics must not trigger a "collection is partial" banner.
     *
     * @param  array<string, mixed>  $connectionSummary
     * @param  array<string, string>  $dataCoverage
     */
    private function workspaceState(array $connectionSummary, ?Evidence $account, ?Evidence $campaigns, ?Run $latestRun, array $dataCoverage): string
    {
        if (($connectionSummary['active_binding'] ?? null) === null) {
            return 'no_connection';
        }

        if ($account === null && $campaigns === null) {
            return $latestRun !== null && $latestRun->status === 'failed' ? 'collection_failed' : 'no_data';
        }

        if ($latestRun !== null && $latestRun->status === 'failed') {
            return 'collection_failed';
        }

        if ($latestRun !== null && $latestRun->status === 'partial') {
            return 'collection_partial';
        }

        $collectionKeys = ['account', 'campaigns', 'adsets', 'ads', 'creative'];
        $hasCollectionGaps = collect($dataCoverage)
            ->only($collectionKeys)
            ->contains(fn (string $status): bool => in_array($status, ['Partial', 'Unavailable', 'Unknown'], true));

        return $hasCollectionGaps ? 'collection_partial' : 'data_available';
    }

    /**
     * @return list<string>
     */
    private function partialReasons(?Run $latestRun): array
    {
        if ($latestRun === null || $latestRun->status !== 'partial') {
            return [];
        }

        $reasons = $latestRun->metadata['partial_reasons'] ?? [];
        if (! is_array($reasons)) {
            return [];
        }

        return array_values(array_filter($reasons, fn (mixed $reason): bool => is_string($reason) && $reason !== ''));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accountKpis(?Evidence $account, bool $comparisonAvailable = false): array
    {
        if ($account === null) {
            return [];
        }

        $current = is_array($account->payload['current'] ?? null) ? $account->payload['current'] : [];
        $deltas = $comparisonAvailable && is_array($account->payload['deltas'] ?? null)
            ? $account->payload['deltas']
            : [];
        $actions = is_array($current['actions'] ?? null) ? $current['actions'] : [];

        $map = [
            'spend' => ['label' => 'Spend', 'type' => 'currency'],
            'impressions' => ['label' => 'Impressions', 'type' => 'count'],
            'reach' => ['label' => 'Reach', 'type' => 'count'],
            'frequency' => ['label' => 'Frequency', 'type' => 'decimal'],
            'clicks' => ['label' => 'All Clicks', 'type' => 'count'],
            'inline_link_clicks' => ['label' => 'Link Clicks', 'type' => 'count'],
            'outbound_clicks' => ['label' => 'Outbound Clicks', 'type' => 'count'],
            'ctr' => ['label' => 'All Clicks CTR', 'type' => 'percentage_point'],
            'inline_link_click_ctr' => ['label' => 'Link CTR', 'type' => 'percentage_point'],
            'cpc' => ['label' => 'CPC (All)', 'type' => 'currency'],
            'cost_per_inline_link_click' => ['label' => 'Cost / Link Click', 'type' => 'currency'],
            'cpm' => ['label' => 'CPM', 'type' => 'currency'],
        ];

        $out = [];
        foreach ($map as $key => $meta) {
            if (! array_key_exists($key, $current)) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'label' => $meta['label'],
                'value' => $current[$key],
                'type' => $meta['type'],
                'delta_percent' => $comparisonAvailable ? data_get($deltas, $key.'.percent') : null,
            ];
        }

        $landingPageViews = MetaActionNormalizer::countForType($actions, 'landing_page_view');
        if ($landingPageViews !== null) {
            $out[] = [
                'key' => 'landing_page_views',
                'label' => 'Landing Page Views',
                'value' => $landingPageViews,
                'type' => 'count',
                'delta_percent' => null,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function primaryResultSummary(?Evidence $account): ?array
    {
        if ($account === null) {
            return null;
        }

        $primary = data_get($account->payload, 'primary_result');
        if (! is_array($primary)) {
            return null;
        }

        return [
            'status' => $primary['status'] ?? null,
            'raw_action_type' => $primary['raw_action_type'] ?? null,
            'normalized_result_type' => $primary['normalized_result_type'] ?? null,
            'human_label' => $this->humanPrimaryResultLabel($primary['raw_action_type'] ?? null, $primary['normalized_result_type'] ?? null),
            'count' => $primary['count'] ?? null,
            'value' => $primary['value'] ?? null,
            'cost_per_result' => $primary['cost_per_result'] ?? null,
            'cost_per_result_source' => $primary['cost_per_result_source'] ?? null,
            'reason' => $primary['reason'] ?? null,
            'diagnostic' => is_array($primary['diagnostic'] ?? null) ? $primary['diagnostic'] : [],
        ];
    }

    /**
     * Operator Result Mix — precise labels, no blind sums, rebuilt from actions when present.
     *
     * @return array{mode: string, items: list<array<string, mixed>>, blind_action_sum: bool, note: ?string}|null
     */
    private function resultMixSummary(?Evidence $account): ?array
    {
        if ($account === null) {
            return null;
        }

        $actions = data_get($account->payload, 'actions');
        if (! is_array($actions)) {
            $actions = data_get($account->payload, 'current.actions');
        }
        if (is_array($actions)) {
            $mix = MetaResultResolver::resultMix($actions);

            return [
                'mode' => 'result_mix',
                'items' => $mix['operator_items'] ?? $mix['items'] ?? [],
                'blind_action_sum' => false,
                'note' => $mix['note'] ?? null,
            ];
        }

        $mix = data_get($account->payload, 'result_mix');
        if (is_array($mix) && is_array($mix['items'] ?? null)) {
            return [
                'mode' => (string) ($mix['mode'] ?? 'result_mix'),
                'items' => array_values(array_filter($mix['items'], fn (mixed $item): bool => is_array($item))),
                'blind_action_sum' => false,
                'note' => isset($mix['note']) ? (string) $mix['note'] : null,
            ];
        }

        return [
            'mode' => 'result_mix',
            'items' => [],
            'blind_action_sum' => false,
            'note' => 'No Meta actions observed for Result Mix.',
        ];
    }

    /**
     * Raw Result Signals for diagnostics — every preserved action type, never summed.
     *
     * @return list<array<string, mixed>>
     */
    private function rawResultSignalsSummary(?Evidence $account): array
    {
        if ($account === null) {
            return [];
        }

        $actions = data_get($account->payload, 'actions');
        if (! is_array($actions)) {
            $actions = data_get($account->payload, 'current.actions');
        }
        if (! is_array($actions)) {
            return [];
        }

        $mix = MetaResultResolver::resultMix($actions);

        return array_values(array_filter($mix['raw_items'] ?? [], fn (mixed $item): bool => is_array($item)));
    }

    /**
     * Operator-facing label for a resolved Meta primary result. Platform
     * wording only — never business-outcome language like "Qualified Leads",
     * "CAC", or "Profit" that Meta cannot verify.
     */
    private function humanPrimaryResultLabel(?string $rawActionType, ?string $normalizedResultType): ?string
    {
        if ($rawActionType === null && $normalizedResultType === null) {
            return null;
        }

        return match ($normalizedResultType) {
            'lead' => 'Meta-attributed Leads',
            'purchase' => 'Meta-attributed Purchases',
            'messaging' => 'Meta-attributed Messaging Conversations',
            'registration' => 'Meta-attributed Registrations',
            'appointment' => 'Meta-attributed Appointments',
            default => $rawActionType !== null
                ? 'Meta-attributed '.Str::of($rawActionType)->replace(['.', '_'], ' ')->title()->toString()
                : null,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function boundedEntityRows(?Evidence $evidence): array
    {
        $rows = data_get($evidence?->payload, 'rows');
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $row): ?array {
            if (! is_array($row)) {
                return null;
            }

            $primary = is_array($row['primary_result'] ?? null) ? $row['primary_result'] : [];
            $actions = is_array($row['actions'] ?? null) ? $row['actions'] : [];

            return [
                'entity_id' => $row['campaign_id'] ?? $row['adset_id'] ?? $row['ad_id'] ?? null,
                'campaign_id' => $row['campaign_id'] ?? null,
                'adset_id' => $row['adset_id'] ?? null,
                'ad_id' => $row['ad_id'] ?? null,
                'name' => $row['campaign_name'] ?? $row['adset_name'] ?? $row['ad_name'] ?? '—',
                'campaign_name' => $row['campaign_name'] ?? null,
                'adset_name' => $row['adset_name'] ?? null,
                'status' => $row['status'] ?? $row['effective_status'] ?? null,
                'effective_status' => $row['effective_status'] ?? $row['status'] ?? null,
                'objective' => $row['objective'] ?? null,
                'optimization_goal' => $row['optimization_goal'] ?? null,
                'destination_type' => $row['destination_type'] ?? null,
                'attribution_setting' => $row['attribution_setting'] ?? null,
                'spend' => $row['spend'] ?? null,
                'impressions' => $row['impressions'] ?? null,
                'reach' => $row['reach'] ?? null,
                'frequency' => $row['frequency'] ?? null,
                'clicks' => $row['clicks'] ?? null,
                'inline_link_clicks' => $row['inline_link_clicks'] ?? null,
                'outbound_clicks' => $row['outbound_clicks'] ?? null,
                'ctr' => $row['ctr'] ?? null,
                'inline_link_click_ctr' => $row['inline_link_click_ctr'] ?? null,
                'outbound_clicks_ctr' => $row['outbound_clicks_ctr'] ?? null,
                'cpc' => $row['cpc'] ?? null,
                'cpm' => $row['cpm'] ?? null,
                'cost_per_inline_link_click' => $row['cost_per_inline_link_click'] ?? null,
                'primary_result_status' => $primary['status'] ?? null,
                'primary_result_type' => $primary['raw_action_type'] ?? $primary['normalized_result_type'] ?? null,
                'primary_result_human_label' => $this->humanPrimaryResultLabel(
                    $primary['raw_action_type'] ?? null,
                    $primary['normalized_result_type'] ?? null,
                ),
                'primary_result_count' => $primary['count'] ?? null,
                'primary_result_cost' => $primary['cost_per_result'] ?? null,
                'primary_result_reason' => $primary['reason'] ?? null,
                'primary_result_diagnostic' => is_array($primary['diagnostic'] ?? null) ? $primary['diagnostic'] : [],
                'actions' => $this->boundedActionRows($actions),
                'creative_id' => $row['creative_id'] ?? null,
                'creative_name' => $row['creative_name'] ?? null,
            ];
        }, array_slice($rows, 0, 50))));
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     * @return list<array<string, mixed>>
     */
    private function boundedActionRows(array $actions): array
    {
        return array_values(array_filter(array_map(function (mixed $action): ?array {
            if (! is_array($action)) {
                return null;
            }

            return [
                'raw_action_type' => $action['raw_action_type'] ?? $action['action_type'] ?? '—',
                'normalized_result_type' => $action['normalized_result_type'] ?? null,
                'count' => $action['count'] ?? null,
                'value' => $action['value'] ?? null,
                'source' => $action['source'] ?? null,
            ];
        }, array_slice($actions, 0, 20))));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function boundedCreativeRows(?Evidence $creatives, ?Evidence $ads = null): array
    {
        $rows = data_get($creatives?->payload, 'rows');
        if (! is_array($rows)) {
            return [];
        }

        $adsByCreative = [];
        foreach ($this->boundedEntityRows($ads) as $ad) {
            $cid = $ad['creative_id'] ?? null;
            if (! is_string($cid) || $cid === '') {
                continue;
            }
            if (! isset($adsByCreative[$cid])) {
                $adsByCreative[$cid] = $ad;
            } else {
                $prevSpend = is_numeric($adsByCreative[$cid]['spend'] ?? null) ? (float) $adsByCreative[$cid]['spend'] : 0.0;
                $nextSpend = is_numeric($ad['spend'] ?? null) ? (float) $ad['spend'] : 0.0;
                if ($nextSpend > $prevSpend) {
                    $adsByCreative[$cid] = $ad;
                }
            }
        }

        return array_values(array_filter(array_map(function (mixed $row) use ($adsByCreative): ?array {
            if (! is_array($row)) {
                return null;
            }

            $creativeId = isset($row['creative_id']) ? (string) $row['creative_id'] : null;
            $perf = ($creativeId !== null && isset($adsByCreative[$creativeId]))
                ? $adsByCreative[$creativeId]
                : null;

            $primaryText = $row['primary_text'] ?? $row['body'] ?? null;
            $excerpt = is_string($primaryText) && $primaryText !== ''
                ? Str::limit($primaryText, 120)
                : null;

            return [
                'creative_id' => $creativeId,
                'creative_name' => $row['creative_name'] ?? $row['name'] ?? $creativeId ?? '—',
                'headline' => $row['headline'] ?? $row['title'] ?? null,
                'primary_text' => $primaryText,
                'primary_text_excerpt' => $excerpt,
                'cta_type' => $row['cta_type'] ?? $row['call_to_action_type'] ?? null,
                'destination_url' => $row['destination_url'] ?? $row['link_url'] ?? null,
                'destination_domain' => $this->destinationDomain($row['destination_url'] ?? $row['link_url'] ?? null),
                'object_type' => $row['object_type'] ?? null,
                'thumbnail_url' => $row['thumbnail_url'] ?? null,
                'status' => $row['status'] ?? null,
                'untrusted_text' => (bool) ($row['untrusted_text'] ?? true),
                'spend' => $perf['spend'] ?? null,
                'frequency' => $perf['frequency'] ?? null,
                'inline_link_click_ctr' => $perf['inline_link_click_ctr'] ?? null,
                'primary_result_human_label' => $perf['primary_result_human_label'] ?? null,
                'primary_result_count' => $perf['primary_result_count'] ?? null,
                'primary_result_cost' => $perf['primary_result_cost'] ?? null,
                'primary_result_status' => $perf['primary_result_status'] ?? null,
                'ad_name' => $perf['name'] ?? null,
                'campaign_name' => $perf['campaign_name'] ?? null,
            ];
        }, array_slice($rows, 0, 40))));
    }

    private function destinationDomain(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    private function actionsNote(?Evidence $account): string
    {
        $limitations = data_get($account?->payload, 'limitations');
        if (is_array($limitations) && $limitations !== []) {
            return implode(' ', array_map('strval', $limitations));
        }

        return 'Meta actions are platform-attributed results, not verified business outcomes. Distinct action_types are never summed into a fake total.';
    }

    /**
     * @return list<string>
     */
    private function caveats(?Evidence $account): array
    {
        $limitations = data_get($account?->payload, 'limitations');
        $base = [
            'Platform metrics reflect Meta attribution — not verified business profit or CRM outcomes.',
            'Reach and frequency are non-additive; use account-level values only.',
            'Primary result selection is conservative; ambiguous cases stay unresolved.',
        ];

        if (is_array($limitations)) {
            foreach ($limitations as $line) {
                if (is_string($line) && $line !== '') {
                    $base[] = $line;
                }
            }
        }

        return array_values(array_unique($base));
    }

    /**
     * Group open Findings that share the same title into an operator-facing
     * summary list. This is presentation-only — Finding identity, status, and
     * fingerprint are never changed by this grouping.
     *
     * @param  Collection<int, Finding>  $findings
     * @return list<array<string, mixed>>
     */
    private function findingGroups(Collection $findings): array
    {
        return $findings->where('status', 'open')
            ->groupBy('title')
            ->map(function (Collection $group, string $title): array {
                $sorted = $group->sortBy(fn (Finding $f): int => self::SEVERITY_WEIGHT[$f->severity] ?? 4);
                /** @var Finding $top */
                $top = $sorted->first();

                return [
                    'title' => $title,
                    'count' => $group->count(),
                    'severity' => $top->severity,
                    'sample_summary' => $top->summary,
                    'finding_ids' => $group->pluck('id')->all(),
                ];
            })
            ->sortBy(fn (array $g): int => self::SEVERITY_WEIGHT[$g['severity']] ?? 4)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function aiGuidance(DigitalAsset $asset): array
    {
        $service = app(MetaAdsAiGuidanceService::class);
        $insight = $service->latestSuccessfulInsight($asset);
        $failed = $service->latestFailedInsight($asset);

        if ($insight === null && $failed === null) {
            return [
                'available' => false,
                'insight' => null,
                'failed' => null,
            ];
        }

        $payload = is_array($insight?->payload) ? $insight->payload : [];
        $failedPayload = is_array($failed?->payload) ? $failed->payload : [];
        $showFailure = $failed !== null && ($insight === null || $failed->id > $insight->id);

        $interpretations = [];
        foreach ($payload['finding_interpretations'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $findingId = (int) ($row['finding_id'] ?? 0);
            $finding = $findingId > 0
                ? Finding::query()->where('digital_asset_id', $asset->id)->find($findingId)
                : null;

            $existingAiRec = Recommendation::query()
                ->where('digital_asset_id', $asset->id)
                ->where('finding_id', $findingId)
                ->where('source_module', MetaAdsAiGuidanceConfig::MODULE_ID)
                ->orderByDesc('id')
                ->first();

            $interpretations[] = [
                'finding_id' => $findingId,
                'finding_title' => $finding?->title ?? ('Finding #'.$findingId),
                'severity' => $finding?->severity ?? ($row['suggested_priority'] ?? 'medium'),
                'explanation' => (string) ($row['explanation'] ?? ''),
                'business_relevance' => (string) ($row['business_relevance'] ?? ''),
                'uncertainty' => (string) ($row['uncertainty'] ?? 'medium'),
                'suggested_priority' => (string) ($row['suggested_priority'] ?? 'medium'),
                'evidence_ids' => array_values(array_map('intval', $row['evidence_ids'] ?? [])),
                'watch_metrics' => is_array($row['watch_metrics'] ?? null) ? $row['watch_metrics'] : [],
                'recommendation_draft' => is_array($row['recommendation_draft'] ?? null)
                    ? $row['recommendation_draft']
                    : null,
                'existing_recommendation' => $existingAiRec,
                'can_accept' => $existingAiRec === null
                    || ! in_array($existingAiRec->status, ['dismissed', 'converted'], true),
            ];
        }

        $run = $insight?->run_id ? Run::query()->find($insight->run_id) : null;
        $meta = is_array($run?->metadata) ? $run->metadata : [];

        return [
            'available' => $insight !== null,
            'generated_at' => $insight?->observed_at,
            'generated_human' => $insight?->observed_at?->diffForHumans(),
            'executive_summary' => (string) ($payload['executive_summary'] ?? ''),
            'overall_priority' => (string) ($payload['overall_priority'] ?? ''),
            'finding_count' => count($payload['finding_ids'] ?? []),
            'evidence_count' => count($payload['evidence_ids'] ?? []),
            'agent_name' => data_get($meta, 'agent_profile_name') ?: data_get($payload, 'agent_profile_slug') ?: 'Meta Ads Analyst',
            'agent_version' => data_get($meta, 'agent_profile_version') ?: data_get($payload, 'agent_profile_version'),
            'skill_versions' => data_get($meta, 'skill_versions') ?: data_get($payload, 'skill_versions') ?: [],
            'ai_route_key' => data_get($meta, 'ai_route_key') ?: data_get($payload, 'ai_route_key'),
            'ai_route_name' => data_get($meta, 'ai_route_name') ?: 'Meta Ads AI Guidance',
            'provider' => data_get($meta, 'provider') ?: data_get($payload, 'provider'),
            'model' => data_get($meta, 'model') ?: data_get($payload, 'model'),
            'fallback_occurred' => (bool) (data_get($meta, 'fallback_occurred') ?: data_get($payload, 'fallback_occurred')),
            'period' => data_get($meta, 'period') ?: null,
            'interpretations' => $interpretations,
            'failed' => $showFailure ? [
                'at' => $failed?->observed_at,
                'error_class' => (string) ($failedPayload['error_class'] ?? 'unknown'),
                'message' => 'Latest AI request failed. Previous successful guidance is shown when available.',
            ] : null,
            'insight_id' => $insight?->id,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function connectionCards(DigitalAsset $asset): array
    {
        return CoreAssetBinding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('capability', MetaAdsBoundCollector::CAPABILITY)
            ->with(['externalResource.integration'])
            ->get()
            ->map(function (CoreAssetBinding $binding): array {
                $resource = $binding->externalResource;
                $integration = $resource?->integration;

                return [
                    'binding_id' => $binding->id,
                    'status' => $binding->status,
                    'resource_name' => $resource?->display_name,
                    'external_id' => $resource?->external_id,
                    'integration_name' => $integration?->name,
                    'integration_status' => $integration?->status,
                ];
            })
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $connections
     * @param  array<string, mixed>  $connectionSummary
     */
    private function connectionHealthLine(array $connections, array $connectionSummary): ?string
    {
        if ($connections === []) {
            return $connectionSummary['connection_label'] ?? 'No Meta Ads binding';
        }

        $active = collect($connections)->where('status', 'active')->count();
        $account = $connectionSummary['account_label'] ?? null;

        if ($active > 0) {
            if (is_string($account) && $account !== '' && $account !== 'Not bound') {
                return 'Connected · '.$account;
            }

            return 'Meta Ads account connected';
        }

        return 'No Meta Ads account connected';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activityRows(DigitalAsset $asset): array
    {
        return Run::query()
            ->where('digital_asset_id', $asset->id)
            ->latest('started_at')
            ->limit(25)
            ->get()
            ->map(function (Run $run): array {
                $started = $run->started_at;
                $finished = $run->finished_at;
                $duration = ($started && $finished)
                    ? $started->diffForHumans($finished, true)
                    : null;

                $title = data_get($run->metadata, 'human_title')
                    ?: match ($run->module_id) {
                        MetaAdsAiGuidanceConfig::MODULE_ID => MetaAdsAiGuidanceConfig::RUN_TITLE,
                        MetaAdsBoundCollector::MODULE_ID => 'Meta Ads data collection',
                        default => 'Run #'.$run->id,
                    };

                $source = data_get($run->metadata, 'resource_display_name')
                    ?: data_get($run->metadata, 'capability')
                    ?: 'Meta Ads';

                if ($run->module_id === MetaAdsAiGuidanceConfig::MODULE_ID) {
                    $provider = data_get($run->metadata, 'provider');
                    $providerLabel = is_string($provider) && $provider !== ''
                        ? AiProviderCatalog::label($provider)
                        : null;
                    $model = data_get($run->metadata, 'model');
                    $modelLabel = is_string($model) && $model !== ''
                        ? AiProviderCatalog::humanModelLabel($model)
                        : null;
                    $routeName = data_get($run->metadata, 'ai_route_name') ?: 'Meta Ads AI Guidance';
                    $agentName = data_get($run->metadata, 'agent_profile_name') ?: 'Meta Ads Analyst';
                    $activeSkills = data_get($run->metadata, 'active_skill_signatures', []);
                    $skillCount = is_array($activeSkills) ? count($activeSkills) : 0;
                    $fallback = data_get($run->metadata, 'fallback_occurred') ? 'Fallback' : null;
                    $source = implode(' · ', array_filter([
                        $agentName,
                        $routeName,
                        $providerLabel,
                        $modelLabel,
                        $skillCount > 0 ? $skillCount.' Skills' : null,
                        $fallback,
                    ]));
                }

                return [
                    'id' => $run->id,
                    'title' => $title,
                    'status' => $run->status,
                    'started_at' => $started,
                    'duration' => $duration,
                    'source' => $source,
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $period
     * @param  array<string, mixed>|null  $comparison
     */
    private function periodLabel(?array $period, ?array $comparison, bool $comparisonAvailable = false): string
    {
        if (! is_array($period) || empty($period['start']) || empty($period['end'])) {
            return 'Last 28 complete days';
        }

        $label = $period['start'].' → '.$period['end'];
        if ($comparisonAvailable && is_array($comparison) && ! empty($comparison['start']) && ! empty($comparison['end'])) {
            $label .= ' vs '.$comparison['start'].' → '.$comparison['end'];
        }

        return $label;
    }

    /**
     * Whether a complete prior period exists to compare against. Never
     * invents a historical warehouse — only reflects what the last collector
     * Run actually fetched for the comparison window.
     *
     * Stale/synthetic `deltas` without a populated `previous` payload must
     * never unlock comparison.
     *
     * @param  array<string, mixed>|null  $comparisonPeriod
     * @return array{period: ?array<string, mixed>, available: bool, reason: string}
     */
    private function comparisonAvailability(?Evidence $account, ?array $comparisonPeriod): array
    {
        $hasWindow = is_array($comparisonPeriod) && ! empty($comparisonPeriod['start']) && ! empty($comparisonPeriod['end']);
        $responseOk = data_get($account?->payload, 'response_ok') === true;
        $previous = data_get($account?->payload, 'previous');
        $previousHasData = is_array($previous)
            && (
                (isset($previous['spend']) && is_numeric($previous['spend']))
                || (isset($previous['impressions']) && is_numeric($previous['impressions']))
            );

        $available = $hasWindow && $responseOk && $previousHasData;

        return [
            'period' => $comparisonPeriod,
            'available' => $available,
            'reason' => $available
                ? 'Comparable prior period present in Evidence.'
                : 'No complete prior-period Evidence — comparison deltas are suppressed.',
        ];
    }

    /**
     * Compact priority KPI strip for Overview GLANCE — not the full metric wall.
     *
     * @return list<array<string, mixed>>
     */
    private function priorityKpis(?Evidence $account, bool $comparisonAvailable = false): array
    {
        $all = $this->accountKpis($account, $comparisonAvailable);
        if ($all === []) {
            return [];
        }

        $priorityKeys = ['spend', 'reach', 'frequency', 'inline_link_click_ctr'];
        $byKey = collect($all)->keyBy('key');
        $out = [];
        foreach ($priorityKeys as $key) {
            if ($byKey->has($key)) {
                $kpi = $byKey->get($key);
                $kpi['delta_sentiment'] = $this->deltaSentiment($key, $kpi['delta_percent'] ?? null);
                $out[] = $kpi;
            }
        }

        return $out;
    }

    private function deltaSentiment(string $metricKey, mixed $deltaPercent): ?string
    {
        if (! is_numeric($deltaPercent)) {
            return null;
        }

        $delta = (float) $deltaPercent;
        if (abs($delta) < 0.05) {
            return 'flat';
        }

        $upIsBad = in_array($metricKey, ['cpc', 'cpm', 'cost_per_inline_link_click', 'frequency'], true);

        if ($delta > 0) {
            return $upIsBad ? 'negative' : ($metricKey === 'spend' ? 'neutral' : 'positive');
        }

        return $upIsBad ? 'positive' : ($metricKey === 'spend' ? 'neutral' : 'negative');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function filterCampaignRows(array $rows, array $filters): array
    {
        $delivery = (string) ($filters['delivery'] ?? MetaWorkspaceFilters::DELIVERY_DELIVERED);
        $objective = strtoupper(trim((string) ($filters['objective'] ?? '')));
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));

        $filtered = array_values(array_filter($rows, function (array $row) use ($delivery, $objective, $search): bool {
            $spend = is_numeric($row['spend'] ?? null) ? (float) $row['spend'] : 0.0;
            $impressions = is_numeric($row['impressions'] ?? null) ? (float) $row['impressions'] : 0.0;
            $status = strtoupper((string) ($row['effective_status'] ?? $row['status'] ?? ''));

            $deliveryOk = match ($delivery) {
                MetaWorkspaceFilters::DELIVERY_ACTIVE => in_array($status, ['ACTIVE', 'WITH_ISSUES'], true),
                MetaWorkspaceFilters::DELIVERY_PAUSED => in_array($status, ['PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED'], true)
                    || str_contains($status, 'PAUSED'),
                MetaWorkspaceFilters::DELIVERY_ALL => true,
                default => $spend > 0.0 || $impressions > 0.0,
            };

            if (! $deliveryOk) {
                return false;
            }

            if ($objective !== '') {
                $rowObjective = strtoupper((string) ($row['objective'] ?? ''));
                if ($rowObjective === '' || ! str_contains($rowObjective, $objective)) {
                    return false;
                }
            }

            if ($search !== '') {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    (string) ($row['name'] ?? ''),
                    (string) ($row['entity_id'] ?? ''),
                    (string) ($row['objective'] ?? ''),
                ])));
                if (! str_contains($haystack, $search)) {
                    return false;
                }
            }

            return true;
        }));

        usort($filtered, function (array $a, array $b): int {
            $spendA = is_numeric($a['spend'] ?? null) ? (float) $a['spend'] : -1.0;
            $spendB = is_numeric($b['spend'] ?? null) ? (float) $b['spend'] : -1.0;

            return $spendB <=> $spendA;
        });

        return $filtered;
    }

    /**
     * @return array{metric: string, label: string, values: list<float|null>, labels: list<string>, available: bool, note: ?string}
     */
    private function trendSeries(?Evidence $daily, string $metric): array
    {
        $allowed = [
            'spend' => 'Spend',
            'inline_link_click_ctr' => 'Link CTR',
            'cpm' => 'CPM',
            'frequency' => 'Frequency',
            'impressions' => 'Impressions',
        ];
        if (! array_key_exists($metric, $allowed)) {
            $metric = 'spend';
        }

        $points = data_get($daily?->payload, 'points');
        if (! is_array($points) || $points === []) {
            return [
                'metric' => $metric,
                'label' => $allowed[$metric],
                'values' => [],
                'labels' => [],
                'available' => false,
                'note' => $daily === null
                    ? 'Daily trend not available for this period yet. Analyze this period to collect bounded daily Insights.'
                    : 'Daily trend Evidence has no points for this period.',
            ];
        }

        $values = [];
        $labels = [];
        foreach ($points as $point) {
            if (! is_array($point)) {
                continue;
            }
            $labels[] = (string) ($point['date'] ?? '');
            $values[] = isset($point[$metric]) && is_numeric($point[$metric]) ? (float) $point[$metric] : null;
        }

        $usable = array_values(array_filter($values, fn ($v): bool => $v !== null));

        return [
            'metric' => $metric,
            'label' => $allowed[$metric],
            'values' => $values,
            'labels' => $labels,
            'available' => count($usable) >= 2,
            'note' => count($usable) >= 2 ? null : 'Not enough daily points for a trend.',
        ];
    }

    /**
     * Platform delivery flow only — never invents CRM/business outcome stages.
     *
     * @return array{stages: list<array<string, mixed>>, note: string}
     */
    private function deliveryFlow(?Evidence $account): array
    {
        $current = is_array($account?->payload['current'] ?? null) ? $account->payload['current'] : [];
        $actions = is_array($current['actions'] ?? null) ? $current['actions'] : [];

        $impressions = array_key_exists('impressions', $current) && is_numeric($current['impressions'])
            ? (float) $current['impressions']
            : null;
        $linkClicks = array_key_exists('inline_link_clicks', $current) && is_numeric($current['inline_link_clicks'])
            ? (float) $current['inline_link_clicks']
            : null;
        $lpv = MetaActionNormalizer::countForType($actions, 'landing_page_view');

        return [
            'stages' => [
                [
                    'key' => 'impressions',
                    'label' => 'Impressions',
                    'value' => $impressions,
                    'available' => $impressions !== null,
                ],
                [
                    'key' => 'inline_link_clicks',
                    'label' => 'Link Clicks',
                    'value' => $linkClicks,
                    'available' => $linkClicks !== null,
                ],
                [
                    'key' => 'landing_page_view',
                    'label' => 'Landing Page Views',
                    'value' => $lpv,
                    'available' => $lpv !== null,
                ],
            ],
            'note' => 'Platform delivery path only. Business outcomes require CRM Evidence.',
        ];
    }

    /**
     * High-signal attention list for Overview — bounded, severity-ordered.
     *
     * @param  Collection<int, Finding>  $findings
     * @param  list<array<string, mixed>>  $campaignRows
     * @return array{items: list<array<string, mixed>>, empty_label: string}
     */
    private function attentionItems(Collection $findings, array $campaignRows): array
    {
        $items = [];
        $openHigh = $findings
            ->where('status', 'open')
            ->filter(fn (Finding $f): bool => in_array($f->severity, ['critical', 'high'], true))
            ->sortBy(fn (Finding $f): int => self::SEVERITY_WEIGHT[$f->severity] ?? 4)
            ->take(5);

        foreach ($openHigh as $finding) {
            $campaignHint = null;
            $titleLower = mb_strtolower($finding->title.' '.$finding->summary);
            foreach ($campaignRows as $row) {
                $name = (string) ($row['name'] ?? '');
                if ($name !== '' && str_contains($titleLower, mb_strtolower($name))) {
                    $campaignHint = $name;
                    break;
                }
            }

            $items[] = [
                'severity' => $finding->severity,
                'title' => $finding->title,
                'summary' => $finding->summary,
                'finding_id' => $finding->id,
                'inspect_label' => $campaignHint !== null ? 'Inspect '.$campaignHint : 'Inspect in Insights',
                'campaign_name' => $campaignHint,
            ];
        }

        return [
            'items' => $items,
            'empty_label' => 'No high-confidence issues detected for this period.',
        ];
    }

    /**
     * @param  array<string, string>  $dataCoverage
     * @return array{label: string, tone: string, detail: array<string, string>}
     */
    private function dataHealthBadge(array $dataCoverage, ?Run $latestRun, ?Run $asyncCollect): array
    {
        if ($asyncCollect !== null) {
            return [
                'label' => 'Data Health · Updating',
                'tone' => 'info',
                'detail' => $dataCoverage,
                'sync_status' => $asyncCollect->status,
                'sync_label' => (string) (data_get($asyncCollect->metadata, 'phase_label') ?: 'Collection running'),
            ];
        }

        $collectionKeys = ['account', 'campaigns', 'adsets', 'ads', 'creative'];
        $states = collect($dataCoverage)->only($collectionKeys)->values();

        if ($states->contains('Unavailable') || ($latestRun?->status === 'failed')) {
            $label = 'Data Health · Degraded';
            $tone = 'danger';
        } elseif ($states->contains('Partial') || $states->contains('Unknown') || ($latestRun?->status === 'partial')) {
            $label = 'Data Health · Partial';
            $tone = 'warning';
        } elseif ($states->every(fn (string $s): bool => $s === 'Complete')) {
            $label = 'Data Health · Complete';
            $tone = 'success';
        } else {
            $label = 'Data Health · Unknown';
            $tone = 'muted';
        }

        return [
            'label' => $label,
            'tone' => $tone,
            'detail' => $dataCoverage,
            'sync_status' => $latestRun?->status,
            'sync_label' => $latestRun?->finished_at?->diffForHumans() ?? ($latestRun?->status ?? null),
        ];
    }
}
