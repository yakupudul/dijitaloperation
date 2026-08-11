<?php

namespace MoxDop\MetaAds\Workspace;

use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Support\Ai\AiProviderCatalog;
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
     * @return array<string, mixed>
     */
    public function for(DigitalAsset $asset): array
    {
        $account = $this->latestEvidence($asset, MetaAdsBoundCollector::EVIDENCE_ACCOUNT_SUMMARY);
        $campaigns = $this->latestEvidence($asset, MetaAdsBoundCollector::EVIDENCE_CAMPAIGN_PERFORMANCE);
        $adsets = $this->latestEvidence($asset, MetaAdsBoundCollector::EVIDENCE_ADSET_PERFORMANCE);
        $ads = $this->latestEvidence($asset, MetaAdsBoundCollector::EVIDENCE_AD_PERFORMANCE);
        $creatives = $this->latestEvidence($asset, MetaAdsBoundCollector::EVIDENCE_CREATIVE_METADATA);

        $period = data_get($account?->payload, 'requested_period')
            ?? data_get($campaigns?->payload, 'requested_period');
        $comparisonPeriod = data_get($account?->payload, 'comparison_period')
            ?? data_get($campaigns?->payload, 'comparison_period');

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

        $campaignRows = $this->boundedEntityRows($campaigns);
        $dataCoverage = $this->dataCoverage($account, $campaigns, $adsets, $ads, $creatives, $campaignRows);
        $comparison = $this->comparisonAvailability($account, $comparisonPeriod);

        return [
            'asset' => $asset,
            'period' => $period,
            'comparison_period' => $comparisonPeriod,
            'period_label' => $this->periodLabel($period, $comparisonPeriod, $comparison['available'] === true),
            'last_updated' => $lastUpdated,
            'last_updated_human' => $lastUpdated instanceof CarbonInterface
                ? $lastUpdated->diffForHumans()
                : null,
            'account_identity' => $this->accountIdentity($account, $connectionSummary),
            'data_coverage' => $dataCoverage,
            'workspace_state' => $this->workspaceState($connectionSummary, $account, $campaigns, $latestRun, $dataCoverage),
            'kpis' => $this->accountKpis($account, $comparison['available'] === true),
            'primary_result' => $this->primaryResultSummary($account),
            'result_mix' => $this->resultMixSummary($account),
            'collection_stages' => is_array($latestRun?->metadata['collection_stages'] ?? null)
                ? $latestRun->metadata['collection_stages']
                : [],
            'campaigns' => $campaignRows,
            'adsets' => $this->boundedEntityRows($adsets),
            'ads' => $this->boundedEntityRows($ads),
            'creatives' => $this->boundedCreativeRows($creatives),
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
            'has_performance_data' => $account !== null || $campaigns !== null,
            'caveats' => $this->caveats($account),
            'comparison' => $comparison,
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

        $hasGaps = collect($dataCoverage)
            ->except(['business_validation'])
            ->contains(fn (string $status): bool => in_array($status, ['Partial', 'Unavailable', 'Unresolved', 'Mixed', 'Unknown'], true));

        return $hasGaps ? 'collection_partial' : 'data_available';
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
            'cpc' => ['label' => 'CPC', 'type' => 'currency'],
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
     * Account Overview Result Mix — never a blind sum of unrelated actions.
     *
     * @return array{mode: string, items: list<array<string, mixed>>, blind_action_sum: bool, note: ?string}|null
     */
    private function resultMixSummary(?Evidence $account): ?array
    {
        if ($account === null) {
            return null;
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

        $actions = data_get($account->payload, 'actions');
        if (! is_array($actions)) {
            $actions = data_get($account->payload, 'current.actions');
        }
        if (! is_array($actions)) {
            return [
                'mode' => 'result_mix',
                'items' => [],
                'blind_action_sum' => false,
                'note' => 'No Meta actions observed for Result Mix.',
            ];
        }

        return MetaResultResolver::resultMix($actions);
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
                'name' => $row['campaign_name'] ?? $row['adset_name'] ?? $row['ad_name'] ?? '—',
                'campaign_name' => $row['campaign_name'] ?? null,
                'adset_name' => $row['adset_name'] ?? null,
                'status' => $row['status'] ?? $row['effective_status'] ?? null,
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
        }, array_slice($rows, 0, 25))));
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
    private function boundedCreativeRows(?Evidence $creatives): array
    {
        $rows = data_get($creatives?->payload, 'rows');
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $row): ?array {
            if (! is_array($row)) {
                return null;
            }

            return [
                'creative_id' => $row['creative_id'] ?? null,
                'creative_name' => $row['creative_name'] ?? $row['creative_id'] ?? '—',
                'headline' => $row['headline'] ?? null,
                'primary_text' => $row['primary_text'] ?? null,
                'cta_type' => $row['cta_type'] ?? null,
                'destination_url' => $row['destination_url'] ?? null,
                'object_type' => $row['object_type'] ?? null,
                'status' => $row['status'] ?? null,
                'untrusted_text' => (bool) ($row['untrusted_text'] ?? true),
            ];
        }, array_slice($rows, 0, 25))));
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
            $line = $active.' active Meta binding'.($active === 1 ? '' : 's');
            if (is_string($account) && $account !== '' && $account !== 'Not bound') {
                $line .= ' · '.$account;
            }

            return $line;
        }

        return 'No active Meta Ads binding';
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
}
