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
use MoxDop\MetaAds\Ai\MetaAdsAiGuidanceConfig;
use MoxDop\MetaAds\Ai\MetaAdsAiGuidanceService;
use MoxDop\MetaAds\Collection\MetaAdsBoundCollector;
use MoxDop\MetaAds\Support\MetaAdsWorkspaceData as MetaAdsConnectionSummary;

/**
 * Meta Ads workspace presenter over latest Evidence / Findings / AI Guidance.
 */
final class MetaAdsWorkspaceData
{
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
        $comparison = data_get($account?->payload, 'comparison_period')
            ?? data_get($campaigns?->payload, 'comparison_period');

        $lastUpdated = collect([$account, $campaigns, $adsets, $ads, $creatives])
            ->filter()
            ->map(fn (Evidence $e) => $e->observed_at)
            ->filter()
            ->sortDesc()
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

        return [
            'asset' => $asset,
            'period' => $period,
            'comparison_period' => $comparison,
            'period_label' => $this->periodLabel($period, $comparison),
            'last_updated' => $lastUpdated,
            'last_updated_human' => $lastUpdated instanceof CarbonInterface
                ? $lastUpdated->diffForHumans()
                : null,
            'kpis' => $this->accountKpis($account),
            'primary_result' => $this->primaryResultSummary($account),
            'campaigns' => $this->boundedEntityRows($campaigns),
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
            'recommendations' => $recommendations,
            'ai_guidance' => $this->aiGuidance($asset),
            'connections' => $connections,
            'connection_summary' => $connectionSummary,
            'connection_health' => $this->connectionHealthLine($connections, $connectionSummary),
            'activity' => $this->activityRows($asset),
            'has_performance_data' => $account !== null || $campaigns !== null,
            'caveats' => $this->caveats($account),
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
     * @return list<array<string, mixed>>
     */
    private function accountKpis(?Evidence $account): array
    {
        if ($account === null) {
            return [];
        }

        $current = is_array($account->payload['current'] ?? null) ? $account->payload['current'] : [];
        $deltas = is_array($account->payload['deltas'] ?? null) ? $account->payload['deltas'] : [];

        $map = [
            'spend' => 'Spend',
            'impressions' => 'Impressions',
            'reach' => 'Reach',
            'clicks' => 'Clicks',
            'ctr' => 'CTR',
            'cpc' => 'CPC',
            'cpm' => 'CPM',
        ];

        $out = [];
        foreach ($map as $key => $label) {
            if (! array_key_exists($key, $current)) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'label' => $label,
                'value' => $current[$key],
                'delta_percent' => data_get($deltas, $key.'.percent'),
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
            'count' => $primary['count'] ?? null,
            'value' => $primary['value'] ?? null,
            'cost_per_result' => $primary['cost_per_result'] ?? null,
            'cost_per_result_source' => $primary['cost_per_result_source'] ?? null,
            'reason' => $primary['reason'] ?? null,
        ];
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
                'spend' => $row['spend'] ?? null,
                'impressions' => $row['impressions'] ?? null,
                'reach' => $row['reach'] ?? null,
                'clicks' => $row['clicks'] ?? null,
                'ctr' => $row['ctr'] ?? null,
                'cpc' => $row['cpc'] ?? null,
                'cpm' => $row['cpm'] ?? null,
                'primary_result_status' => $primary['status'] ?? null,
                'primary_result_type' => $primary['raw_action_type'] ?? $primary['normalized_result_type'] ?? null,
                'primary_result_count' => $primary['count'] ?? null,
                'primary_result_cost' => $primary['cost_per_result'] ?? null,
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
    private function periodLabel(?array $period, ?array $comparison): string
    {
        if (! is_array($period) || empty($period['start']) || empty($period['end'])) {
            return 'Last 28 complete days vs previous period';
        }

        $label = $period['start'].' → '.$period['end'];
        if (is_array($comparison) && ! empty($comparison['start']) && ! empty($comparison['end'])) {
            $label .= ' vs '.$comparison['start'].' → '.$comparison['end'];
        }

        return $label;
    }
}
