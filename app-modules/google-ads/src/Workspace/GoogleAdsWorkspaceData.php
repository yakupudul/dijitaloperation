<?php

namespace MoxDop\GoogleAds\Workspace;

use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Support\Ai\AiProviderCatalog;
use Carbon\CarbonInterface;
use MoxDop\GoogleAds\Ai\GoogleAdsAiGuidanceConfig;
use MoxDop\GoogleAds\Ai\GoogleAdsAiGuidanceService;
use MoxDop\GoogleAds\Collection\GoogleAdsBoundCollector;

/**
 * Google Ads workspace presenter over latest Evidence / Findings / AI Guidance.
 */
final class GoogleAdsWorkspaceData
{
    /**
     * @return array<string, mixed>
     */
    public function for(DigitalAsset $asset): array
    {
        $account = $this->latestEvidence($asset, 'google_ads_account_summary');
        $campaigns = $this->latestEvidence($asset, 'google_ads_campaign_performance');
        $searchTerms = $this->latestEvidence($asset, GoogleAdsBoundCollector::EVIDENCE_TYPE_SEARCH_TERM_PERFORMANCE);
        $conversions = $this->latestEvidence($asset, GoogleAdsBoundCollector::EVIDENCE_TYPE_CONVERSION_ACTIONS);
        $landing = $this->latestEvidence($asset, GoogleAdsBoundCollector::EVIDENCE_TYPE_LANDING_FINAL_URLS);

        $period = data_get($account?->payload, 'requested_period')
            ?? data_get($campaigns?->payload, 'requested_period');
        $comparison = data_get($account?->payload, 'comparison_period');

        $lastUpdated = collect([$account, $campaigns, $searchTerms, $conversions, $landing])
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
            'campaigns' => $this->boundedCampaignRows($campaigns),
            'search_terms' => $this->boundedSearchTermRows($searchTerms),
            'search_terms_meta' => [
                'ok' => data_get($searchTerms?->payload, 'response_ok'),
                'row_count' => data_get($searchTerms?->payload, 'row_count'),
                'partial_note' => data_get($searchTerms?->payload, 'sources'),
            ],
            'conversion_actions' => $this->conversionSummary($conversions),
            'landing' => [
                'final_url_count' => data_get($landing?->payload, 'final_url_count'),
                'hosts' => array_slice(data_get($landing?->payload, 'final_url_hosts') ?? [], 0, 12),
                'urls' => array_slice(data_get($landing?->payload, 'final_urls') ?? [], 0, 12),
            ],
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
            'connection_health' => $this->connectionHealthLine($connections),
            'activity' => $this->activityRows($asset),
            'has_performance_data' => $account !== null || $campaigns !== null,
        ];
    }

    private function latestEvidence(DigitalAsset $asset, string $type): ?Evidence
    {
        return Evidence::query()
            ->where('digital_asset_id', $asset->id)
            ->where('type', $type)
            ->where('source_module', 'google-ads')
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
            'cost' => 'Spend',
            'clicks' => 'Clicks',
            'impressions' => 'Impressions',
            'conversions' => 'Conversions (platform)',
            'conversion_value' => 'Conv. value (platform)',
            'ctr' => 'CTR',
            'average_cpc' => 'Avg CPC',
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
     * @return list<array<string, mixed>>
     */
    private function boundedCampaignRows(?Evidence $campaigns): array
    {
        $rows = data_get($campaigns?->payload, 'rows');
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $row): ?array {
            if (! is_array($row)) {
                return null;
            }

            return [
                'campaign_name' => $row['campaign_name'] ?? $row['campaign_id'] ?? '—',
                'status' => $row['status'] ?? null,
                'channel' => $row['advertising_channel_type'] ?? null,
                'cost' => $row['cost'] ?? null,
                'clicks' => $row['clicks'] ?? null,
                'impressions' => $row['impressions'] ?? null,
                'ctr' => $row['ctr'] ?? null,
                'conversions' => $row['conversions'] ?? null,
                'conversion_value' => $row['conversion_value'] ?? null,
            ];
        }, array_slice($rows, 0, 25))));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function boundedSearchTermRows(?Evidence $searchTerms): array
    {
        $rows = data_get($searchTerms?->payload, 'rows');
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $row): ?array {
            if (! is_array($row)) {
                return null;
            }

            return [
                'search_term' => $row['search_term'] ?? '—',
                'campaign_name' => $row['campaign_name'] ?? $row['campaign_id'] ?? '—',
                'ad_group_name' => $row['ad_group_name'] ?? null,
                'channel' => $row['advertising_channel_type'] ?? null,
                'targeting_status' => $row['targeting_status'] ?? null,
                'cost' => $row['cost'] ?? null,
                'clicks' => $row['clicks'] ?? null,
                'conversions' => $row['conversions'] ?? null,
                'conversion_value' => $row['conversion_value'] ?? null,
                'source_report' => $row['source_report'] ?? null,
            ];
        }, array_slice($rows, 0, 50))));
    }

    /**
     * @return array<string, mixed>
     */
    private function conversionSummary(?Evidence $conversions): array
    {
        if ($conversions === null) {
            return ['available' => false];
        }

        $actions = data_get($conversions->payload, 'actions');
        $list = is_array($actions) ? array_slice($actions, 0, 20) : [];

        return [
            'available' => true,
            'ok' => data_get($conversions->payload, 'response_ok') === true,
            'action_count' => data_get($conversions->payload, 'action_count'),
            'enabled_count' => data_get($conversions->payload, 'enabled_count'),
            'usable_primary_or_included_count' => data_get($conversions->payload, 'usable_primary_or_included_count'),
            'limitations' => data_get($conversions->payload, 'limitations') ?? [],
            'actions' => $list,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aiGuidance(DigitalAsset $asset): array
    {
        $service = app(GoogleAdsAiGuidanceService::class);
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
                ->where('source_module', GoogleAdsAiGuidanceConfig::MODULE_ID)
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
            'agent_name' => data_get($meta, 'agent_profile_name') ?: data_get($payload, 'agent_profile_slug') ?: 'Google Ads Analyst',
            'agent_version' => data_get($meta, 'agent_profile_version') ?: data_get($payload, 'agent_profile_version'),
            'skill_versions' => data_get($meta, 'skill_versions') ?: data_get($payload, 'skill_versions') ?: [],
            'ai_route_key' => data_get($meta, 'ai_route_key') ?: data_get($payload, 'ai_route_key'),
            'ai_route_name' => data_get($meta, 'ai_route_name') ?: 'Google Ads AI Guidance',
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
            ->where('capability', 'google_ads')
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
     */
    private function connectionHealthLine(array $connections): ?string
    {
        if ($connections === []) {
            return 'No Google Ads binding';
        }
        $active = collect($connections)->where('status', 'active')->count();

        return $active > 0 ? $active.' active Ads binding'.($active === 1 ? '' : 's') : 'No active Ads binding';
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
                        GoogleAdsAiGuidanceConfig::MODULE_ID => 'Google Ads AI Guidance',
                        'google-ads' => 'Google Ads data collection',
                        default => 'Run #'.$run->id,
                    };

                $source = data_get($run->metadata, 'resource_display_name')
                    ?: data_get($run->metadata, 'capability')
                    ?: 'Google Ads';

                if ($run->module_id === GoogleAdsAiGuidanceConfig::MODULE_ID) {
                    $provider = data_get($run->metadata, 'provider');
                    $providerLabel = is_string($provider) && $provider !== ''
                        ? AiProviderCatalog::label($provider)
                        : null;
                    $model = data_get($run->metadata, 'model');
                    $modelLabel = is_string($model) && $model !== ''
                        ? AiProviderCatalog::humanModelLabel($model)
                        : null;
                    $routeName = data_get($run->metadata, 'ai_route_name') ?: 'Google Ads AI Guidance';
                    $agentName = data_get($run->metadata, 'agent_profile_name') ?: 'Google Ads Analyst';
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
