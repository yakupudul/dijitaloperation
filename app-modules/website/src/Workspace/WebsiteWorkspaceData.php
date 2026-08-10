<?php

namespace MoxDop\Website\Workspace;

use App\Filament\App\Resources\Findings\FindingResource;
use App\Models\CoreAssetBinding;
use App\Models\CoreConnection;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Integrations\ProviderRegistry;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use MoxDop\Website\Ai\WebsiteAiRecommendationConfig;
use MoxDop\Website\Ai\WebsiteAiRecommendationService;
use MoxDop\Website\Opportunities\GscStrikingDistanceOpportunities;
use MoxDop\Website\SeoIntelligence\CrossSourceKeywordOpportunities;
use MoxDop\Website\SeoIntelligence\DataForSeoIntegrationResolver;
use MoxDop\Website\SeoIntelligence\SeoIntelligenceConfig;

/**
 * Website-module presenter: turns latest valid Evidence into workspace view-models.
 * Metric semantics stay here — Core only supplies generic records.
 */
final class WebsiteWorkspaceData
{
    /**
     * @return array<string, mixed>
     */
    public function for(DigitalAsset $asset): array
    {
        $gscSummary = $this->latestEvidence($asset, 'gsc_performance_summary');
        $gscDaily = $this->latestEvidence($asset, 'gsc_daily_performance');
        $gscQueries = $this->latestEvidence($asset, 'gsc_query_performance');
        $gscPages = $this->latestEvidence($asset, 'gsc_page_performance');
        $ga4Summary = $this->latestEvidence($asset, 'ga4_performance_summary');
        $ga4Landing = $this->latestEvidence($asset, 'ga4_landing_page_performance');
        $ga4Acquisition = $this->latestEvidence($asset, 'ga4_acquisition_summary');

        $period = data_get($gscSummary?->payload, 'requested_period')
            ?? data_get($ga4Summary?->payload, 'requested_period');
        $comparison = data_get($gscSummary?->payload, 'comparison_period')
            ?? data_get($ga4Summary?->payload, 'comparison_period');

        $lastUpdated = collect([$gscSummary, $ga4Summary])
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

        $diagnosisRun = Run::query()
            ->where('digital_asset_id', $asset->id)
            ->where('module_id', 'website-diagnosis')
            ->where('status', 'completed')
            ->latest('finished_at')
            ->first();

        $connections = $this->connectionCards($asset);
        $seoOpportunities = app(GscStrikingDistanceOpportunities::class)->for($asset);
        $seoIntelligence = $this->seoIntelligence($asset);
        $healthGroups = $this->healthFindingGroups($findings->where('status', 'open')->values());

        return [
            'asset' => $asset,
            'period' => $period,
            'comparison_period' => $comparison,
            'period_label' => $this->periodLabel($period, $comparison),
            'last_updated' => $lastUpdated,
            'last_updated_human' => $lastUpdated instanceof CarbonInterface
                ? $lastUpdated->diffForHumans()
                : null,
            'kpis' => array_values(array_filter([
                ...$this->gscKpis($gscSummary),
                ...$this->ga4Kpis($ga4Summary),
            ])),
            'gsc_daily' => $this->dailySeries($gscDaily),
            'queries' => $this->boundedRows($gscQueries, 12),
            'pages' => $this->boundedRows($gscPages, 12),
            'landing_pages' => $this->boundedRows($ga4Landing, 12),
            'acquisition' => $this->boundedRows($ga4Acquisition, 12),
            'ga4_summary' => $ga4Summary?->payload,
            'gsc_summary' => $gscSummary?->payload,
            'seo_opportunities' => $seoOpportunities,
            'seo_intelligence' => $seoIntelligence,
            'findings' => [
                'open' => $findings->where('status', 'open')->values(),
                'acknowledged' => $findings->where('status', 'acknowledged')->values(),
                'resolved' => $findings->where('status', 'resolved')->values(),
                'all' => $findings,
                'health_groups' => $healthGroups,
                'counts' => [
                    'open' => Finding::query()->where('digital_asset_id', $asset->id)->where('status', 'open')->count(),
                    'acknowledged' => Finding::query()->where('digital_asset_id', $asset->id)->where('status', 'acknowledged')->count(),
                    'resolved' => Finding::query()->where('digital_asset_id', $asset->id)->where('status', 'resolved')->count(),
                    'high' => Finding::query()->where('digital_asset_id', $asset->id)->where('status', 'open')->whereIn('severity', ['critical', 'high'])->count(),
                    'medium' => Finding::query()->where('digital_asset_id', $asset->id)->where('status', 'open')->where('severity', 'medium')->count(),
                ],
            ],
            'recommendations' => $recommendations,
            'diagnosis' => $this->diagnosisSummary($diagnosisRun),
            'ai_guidance' => $this->aiGuidance($asset),
            'connections' => $connections,
            'connection_health' => $this->connectionHealthLine($connections),
            'activity' => $this->activityRows($asset),
            'has_performance_data' => $gscSummary !== null || $ga4Summary !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aiGuidance(DigitalAsset $asset): array
    {
        $service = app(WebsiteAiRecommendationService::class);
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
                ->where('source_module', WebsiteAiRecommendationConfig::MODULE_ID)
                ->orderByDesc('id')
                ->first();

            $interpretations[] = [
                'finding_id' => $findingId,
                'finding_title' => $finding?->title ?? ('Finding #'.$findingId),
                'severity' => $finding?->severity ?? ($row['suggested_priority'] ?? 'medium'),
                'explanation' => (string) ($row['explanation'] ?? $row['likely_cause'] ?? ''),
                'business_relevance' => (string) ($row['business_relevance'] ?? $row['business_impact'] ?? ''),
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

        $completeness = is_array($payload['brand_completeness'] ?? null)
            ? $payload['brand_completeness']
            : null;

        return [
            'available' => $insight !== null,
            'generated_at' => $insight?->observed_at,
            'generated_human' => $insight?->observed_at?->diffForHumans(),
            'executive_summary' => (string) ($payload['executive_summary'] ?? $payload['summary'] ?? ''),
            'overall_priority' => (string) ($payload['overall_priority'] ?? ''),
            'finding_count' => count($payload['finding_ids'] ?? []),
            'evidence_count' => count($payload['evidence_ids'] ?? []),
            'brand_completeness' => $completeness,
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
     * @param  Collection<int, Finding>  $openFindings
     * @return list<array{label: string, findings: list<array<string, mixed>>}>
     */
    private function healthFindingGroups(Collection $openFindings): array
    {
        $order = [
            'Technical' => ['availability', 'transport', 'performance'],
            'Document Head' => ['document-head', 'on-page'],
            'Indexability' => ['indexability'],
            'Structured Data' => ['structured-data'],
            'Social metadata' => ['social'],
            'Other' => [],
        ];

        $buckets = [];
        foreach (array_keys($order) as $label) {
            $buckets[$label] = [];
        }

        foreach ($openFindings as $finding) {
            $category = (string) $finding->category;
            $label = 'Other';
            foreach ($order as $groupLabel => $categories) {
                if ($categories !== [] && in_array($category, $categories, true)) {
                    $label = $groupLabel;
                    break;
                }
            }

            $recommendation = $finding->relationLoaded('recommendations')
                ? $finding->recommendations->first()
                : Recommendation::query()
                    ->where('finding_id', $finding->id)
                    ->whereIn('status', ['open', 'accepted'])
                    ->first();

            $buckets[$label][] = [
                'id' => $finding->id,
                'title' => $finding->title,
                'summary' => $finding->summary,
                'severity' => $finding->severity,
                'category' => $category,
                'source' => $label,
                'status' => $finding->status,
                'recommendation' => $recommendation?->action,
                'url' => FindingResource::getUrl('view', ['record' => $finding]),
            ];
        }

        $groups = [];
        foreach ($buckets as $label => $items) {
            if ($items === []) {
                continue;
            }
            $groups[] = [
                'label' => $label,
                'findings' => $items,
            ];
        }

        return $groups;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function connectionCards(DigitalAsset $asset): array
    {
        $bindings = CoreAssetBinding::query()
            ->with(['externalResource.integration'])
            ->where('digital_asset_id', $asset->id)
            ->get()
            ->keyBy('capability');

        $wordpress = CoreConnection::query()
            ->where('digital_asset_id', $asset->id)
            ->where('type', 'wordpress')
            ->first();

        $cards = [];

        foreach (['ga4' => 'Google Analytics 4', 'search_console' => 'Google Search Console'] as $capability => $label) {
            /** @var CoreAssetBinding|null $binding */
            $binding = $bindings->get($capability);
            $resource = $binding?->externalResource;
            $lastRun = $this->latestBindingRun($asset, $capability);

            $cards[] = [
                'key' => $capability,
                'label' => $label,
                'kind' => 'provider',
                'connected' => $binding !== null && $binding->status === CoreAssetBinding::STATUS_ACTIVE,
                'binding_id' => $binding?->id,
                'resource_id' => $resource?->id,
                'display_name' => $resource?->display_name ?: ($resource?->external_id ?: null),
                'external_id' => $resource?->external_id,
                'subtitle' => $this->resourceSubtitle($capability, $resource),
                'last_sync' => $lastRun?->finished_at,
                'last_sync_human' => $lastRun?->finished_at?->diffForHumans(),
                'last_status' => $lastRun?->status,
            ];
        }

        $cards[] = [
            'key' => 'wordpress',
            'label' => 'WordPress',
            'kind' => 'site',
            'connected' => $wordpress !== null && $wordpress->enabled,
            'connection_id' => $wordpress?->id,
            'display_name' => $wordpress?->name,
            'external_id' => is_array($wordpress?->config) ? ($wordpress->config['base_url'] ?? null) : null,
            'subtitle' => is_array($wordpress?->config) ? ($wordpress->config['base_url'] ?? 'Site CMS connection') : 'Site CMS connection',
            'last_sync' => $wordpress?->last_success_at,
            'last_sync_human' => $wordpress?->last_success_at?->diffForHumans(),
            'last_status' => filled($wordpress?->last_error) ? 'failed' : ($wordpress?->last_success_at ? 'completed' : null),
            'last_error' => $wordpress?->last_error,
        ];

        return $cards;
    }

    /**
     * @return Collection<int, CoreExternalResource>
     */
    public function availableResourcesForCapability(DigitalAsset $asset, string $capability, ?int $exceptBindingId = null): Collection
    {
        $boundResourceIds = CoreAssetBinding::query()
            ->where('digital_asset_id', $asset->id)
            ->when($exceptBindingId, fn ($q) => $q->whereKeyNot($exceptBindingId))
            ->pluck('external_resource_id');

        $capabilityAlreadyBound = CoreAssetBinding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('capability', $capability)
            ->when($exceptBindingId, fn ($q) => $q->whereKeyNot($exceptBindingId))
            ->exists();

        if ($capabilityAlreadyBound && $exceptBindingId === null) {
            return collect();
        }

        return CoreExternalResource::query()
            ->with('integration')
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)
            ->where('resource_type', $capability)
            ->whereHas('integration', fn ($q) => $q->where('status', 'active'))
            ->whereNotIn('id', $boundResourceIds)
            ->orderBy('display_name')
            ->get();
    }

    public function bothProviderCapabilitiesBound(DigitalAsset $asset): bool
    {
        $caps = CoreAssetBinding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->whereIn('capability', ['ga4', 'search_console'])
            ->pluck('capability')
            ->unique();

        return $caps->contains('ga4') && $caps->contains('search_console');
    }

    /**
     * Human title for a Website collection/diagnosis run.
     */
    public function runTitle(Run $run): string
    {
        $capability = data_get($run->metadata, 'capability');

        return match (true) {
            $run->module_id === WebsiteAiRecommendationConfig::MODULE_ID => WebsiteAiRecommendationConfig::RUN_TITLE,
            $run->module_id === 'website-diagnosis' => 'Website technical check',
            $capability === 'search_console' => 'Search Console data refresh',
            $capability === 'ga4' => 'GA4 data refresh',
            $capability === SeoIntelligenceConfig::CAPABILITY_RANKED => 'SEO keyword visibility refresh',
            $capability === SeoIntelligenceConfig::CAPABILITY_KEYWORDS_FOR_SITE => 'Keyword opportunities refresh',
            $run->module_id === 'website' => 'Website data refresh',
            default => ProviderRegistry::capabilityLabel((string) ($capability ?: $run->module_id)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function runPresentation(Run $run): array
    {
        $run->loadMissing('evidence');
        $byType = $run->evidence->keyBy('type');

        return [
            'title' => $this->runTitle($run),
            'capability' => data_get($run->metadata, 'capability'),
            'period_label' => $this->periodLabel(
                data_get($run->metadata, 'period.current') ?? data_get($byType->get('gsc_performance_summary')?->payload, 'requested_period'),
                data_get($run->metadata, 'period.previous') ?? data_get($byType->get('gsc_performance_summary')?->payload, 'comparison_period'),
            ),
            'kpis' => match (data_get($run->metadata, 'capability')) {
                'search_console' => $this->gscKpis($byType->get('gsc_performance_summary')),
                'ga4' => $this->ga4Kpis($byType->get('ga4_performance_summary')),
                default => [],
            },
            'gsc_daily' => $this->dailySeries($byType->get('gsc_daily_performance')),
            'queries' => $this->boundedRows($byType->get('gsc_query_performance'), 15),
            'pages' => $this->boundedRows($byType->get('gsc_page_performance'), 15),
            'landing_pages' => $this->boundedRows($byType->get('ga4_landing_page_performance'), 15),
            'acquisition' => $this->boundedRows($byType->get('ga4_acquisition_summary'), 15),
            'evidence_types' => $run->evidence->pluck('type')->values()->all(),
            'findings_lifecycle' => data_get($run->metadata, 'findings_lifecycle'),
        ];
    }

    private function latestEvidence(DigitalAsset $asset, string $type): ?Evidence
    {
        return Evidence::query()
            ->where('digital_asset_id', $asset->id)
            ->where('type', $type)
            ->where('source_module', 'website')
            ->whereHas('run', fn ($q) => $q->where('status', 'completed'))
            ->where('payload->response_ok', true)
            ->latest('observed_at')
            ->latest('id')
            ->first();
    }

    private function latestBindingRun(DigitalAsset $asset, string $capability): ?Run
    {
        return Run::query()
            ->where('digital_asset_id', $asset->id)
            ->where('module_id', 'website')
            ->where('metadata->capability', $capability)
            ->whereIn('status', ['completed', 'failed'])
            ->latest('finished_at')
            ->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gscKpis(?Evidence $evidence): array
    {
        if ($evidence === null) {
            return [];
        }

        $current = is_array($evidence->payload['current'] ?? null) ? $evidence->payload['current'] : [];
        $previous = is_array($evidence->payload['previous'] ?? null) ? $evidence->payload['previous'] : [];
        $deltas = is_array($evidence->payload['deltas'] ?? null) ? $evidence->payload['deltas'] : [];

        return [
            $this->kpi('Organic clicks', $current['clicks'] ?? null, $deltas['clicks']['percent'] ?? null, 'number', 'gsc'),
            $this->kpi('Impressions', $current['impressions'] ?? null, $deltas['impressions']['percent'] ?? null, 'number', 'gsc'),
            $this->kpi('CTR', $current['ctr'] ?? null, $deltas['ctr']['percent'] ?? null, 'percent_ratio', 'gsc'),
            $this->kpi('Avg. position', $current['position'] ?? null, $deltas['position']['absolute'] ?? null, 'position', 'gsc', invertDelta: true),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ga4Kpis(?Evidence $evidence): array
    {
        if ($evidence === null) {
            return [];
        }

        $current = is_array($evidence->payload['current'] ?? null) ? $evidence->payload['current'] : [];
        $deltas = is_array($evidence->payload['deltas'] ?? null) ? $evidence->payload['deltas'] : [];

        return [
            $this->kpi('Users', $current['totalUsers'] ?? null, $deltas['totalUsers']['percent'] ?? null, 'number', 'ga4'),
            $this->kpi('Sessions', $current['sessions'] ?? null, $deltas['sessions']['percent'] ?? null, 'number', 'ga4'),
            $this->kpi('New users', $current['newUsers'] ?? null, $deltas['newUsers']['percent'] ?? null, 'number', 'ga4'),
            $this->kpi('Engagement rate', $current['engagementRate'] ?? null, $deltas['engagementRate']['percent'] ?? null, 'percent_ratio', 'ga4'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function kpi(
        string $label,
        mixed $value,
        mixed $delta,
        string $format,
        string $source,
        bool $invertDelta = false,
    ): array {
        $numericDelta = is_numeric($delta) ? (float) $delta : null;
        $direction = 'flat';
        if ($numericDelta !== null) {
            $improved = $invertDelta ? $numericDelta < 0 : $numericDelta > 0;
            $worsened = $invertDelta ? $numericDelta > 0 : $numericDelta < 0;
            $direction = $improved ? 'up' : ($worsened ? 'down' : 'flat');
        }

        return [
            'label' => $label,
            'value' => $this->formatValue($value, $format),
            'raw' => is_numeric($value) ? (float) $value : null,
            'delta' => $numericDelta,
            'delta_label' => $this->formatDelta($numericDelta, $format === 'position' ? 'absolute' : 'percent'),
            'direction' => $direction,
            'source' => $source,
        ];
    }

    private function formatValue(mixed $value, string $format): string
    {
        if (! is_numeric($value)) {
            return '—';
        }

        $number = (float) $value;

        return match ($format) {
            'percent_ratio' => number_format($number * 100, 2).'%',
            'position' => number_format($number, 1),
            default => abs($number - round($number)) < 0.0001
                ? number_format($number, 0)
                : number_format($number, 2),
        };
    }

    private function formatDelta(?float $delta, string $mode): ?string
    {
        if ($delta === null) {
            return null;
        }

        $prefix = $delta > 0 ? '↑' : ($delta < 0 ? '↓' : '→');

        if ($mode === 'absolute') {
            return $prefix.' '.number_format(abs($delta), 1).' vs previous period';
        }

        return $prefix.' '.number_format(abs($delta), 1).'% vs previous period';
    }

    /**
     * @return array{labels: list<string>, clicks: list<float|null>, impressions: list<float|null>}
     */
    private function dailySeries(?Evidence $evidence): array
    {
        $rows = is_array($evidence?->payload['rows'] ?? null) ? $evidence->payload['rows'] : [];
        $labels = [];
        $clicks = [];
        $impressions = [];

        foreach (array_slice($rows, 0, 28) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $labels[] = (string) ($row['date'] ?? '');
            $clicks[] = isset($row['clicks']) ? (float) $row['clicks'] : null;
            $impressions[] = isset($row['impressions']) ? (float) $row['impressions'] : null;
        }

        return compact('labels', 'clicks', 'impressions');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function boundedRows(?Evidence $evidence, int $limit): array
    {
        $rows = is_array($evidence?->payload['rows'] ?? null) ? $evidence->payload['rows'] : [];
        $out = [];

        foreach (array_slice($rows, 0, $limit) as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  array{start?: string, end?: string}|null  $period
     * @param  array{start?: string, end?: string}|null  $comparison
     */
    private function periodLabel(?array $period, ?array $comparison): string
    {
        if (! is_array($period) || empty($period['start']) || empty($period['end'])) {
            return 'Last 28 complete days vs previous 28 days';
        }

        $label = 'Last 28 complete days ('.$period['start'].' → '.$period['end'].')';
        if (is_array($comparison) && ! empty($comparison['start']) && ! empty($comparison['end'])) {
            $label .= ' vs '.$comparison['start'].' → '.$comparison['end'];
        }

        return $label;
    }

    private function resourceSubtitle(string $capability, ?CoreExternalResource $resource): string
    {
        if ($resource === null) {
            return 'Not connected';
        }

        return match ($capability) {
            'ga4' => 'Property '.str_replace('properties/', '', (string) $resource->external_id),
            'search_console' => str_starts_with((string) $resource->external_id, 'sc-domain:')
                ? 'Domain property'
                : 'URL prefix property',
            default => (string) $resource->external_id,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $connections
     */
    private function connectionHealthLine(array $connections): string
    {
        $parts = [];
        foreach ($connections as $card) {
            if (($card['kind'] ?? null) !== 'provider') {
                continue;
            }
            $mark = ($card['connected'] ?? false) ? '✓' : '—';
            $parts[] = ($card['label'] === 'Google Analytics 4' ? 'GA4' : 'Search Console').' '.$mark;
        }

        return implode(' · ', $parts);
    }

    /**
     * @return array{available: bool, status: ?string, finished_at: ?CarbonInterface, summary: string}
     */
    private function diagnosisSummary(?Run $run): array
    {
        if ($run === null) {
            return [
                'available' => false,
                'status' => null,
                'finished_at' => null,
                'summary' => 'No technical diagnosis has been run yet.',
            ];
        }

        return [
            'available' => true,
            'status' => $run->status,
            'finished_at' => $run->finished_at,
            'summary' => 'Latest technical check '.$run->status.($run->finished_at ? ' · '.$run->finished_at->diffForHumans() : ''),
            'run_id' => $run->id,
        ];
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

                $provider = data_get($run->metadata, 'provider');
                $market = data_get($run->metadata, 'market');
                $cacheStatus = data_get($run->metadata, 'cache_status');
                $cost = data_get($run->metadata, 'reported_cost_usd');
                $providerCalls = data_get($run->metadata, 'provider_calls');

                $source = data_get($run->metadata, 'resource_display_name')
                    ?: data_get($run->metadata, 'capability')
                    ?: 'Website';

                if ($provider === ProviderRegistry::DATAFORSEO) {
                    $marketLabel = null;
                    if (is_array($market)) {
                        $parts = array_filter([
                            $market['location_name'] ?? null,
                            $market['language_name'] ?? null,
                        ]);
                        $marketLabel = $parts !== [] ? implode(' · ', $parts) : null;
                    }
                    $cacheLabel = match ($cacheStatus) {
                        'HIT_FRESH' => 'Fresh data reused',
                        'MISS' => 'Provider refresh',
                        default => null,
                    };
                    $costLabel = null;
                    if (is_numeric($providerCalls) && (int) $providerCalls > 0 && is_numeric($cost)) {
                        $costLabel = '$'.number_format((float) $cost, 4);
                    } elseif ($cacheStatus === 'HIT_FRESH') {
                        $costLabel = '$0';
                    }

                    $source = implode(' · ', array_filter([
                        'DataForSEO',
                        $marketLabel,
                        $cacheLabel,
                        $costLabel,
                    ]));
                }

                if ($run->module_id === WebsiteAiRecommendationConfig::MODULE_ID) {
                    $findingCount = count(data_get($run->metadata, 'finding_ids', []) ?: []);
                    $providerLabel = is_string($provider) && $provider !== ''
                        ? AiProviderCatalog::label($provider)
                        : null;
                    $model = data_get($run->metadata, 'model');
                    $modelLabel = is_string($model) && $model !== ''
                        ? AiProviderCatalog::humanModelLabel($model)
                        : null;
                    $routeName = data_get($run->metadata, 'ai_route_name') ?: 'Website AI Guidance';
                    $fallback = data_get($run->metadata, 'fallback_occurred') ? 'Fallback' : null;
                    $tokens = data_get($run->metadata, 'usage.total_tokens');
                    $source = implode(' · ', array_filter([
                        $routeName,
                        $providerLabel,
                        $modelLabel,
                        $fallback,
                        $findingCount > 0 ? $findingCount.' Findings' : null,
                        is_numeric($tokens) ? $tokens.' tokens' : null,
                    ]));
                }

                return [
                    'id' => $run->id,
                    'title' => $this->runTitle($run),
                    'status' => $run->status,
                    'started_at' => $started,
                    'duration' => $duration,
                    'source' => $source,
                    'provider' => $provider,
                    'cache_status' => $cacheStatus,
                    'reported_cost_usd' => is_numeric($cost) ? (float) $cost : null,
                ];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function seoIntelligence(DigitalAsset $asset): array
    {
        $integrationStatus = app(DataForSeoIntegrationResolver::class)->status();
        $summary = $this->latestEvidence($asset, SeoIntelligenceConfig::EVIDENCE_RANKED_SUMMARY);
        $rowsEvidence = $this->latestEvidence($asset, SeoIntelligenceConfig::EVIDENCE_RANKED_ROWS);
        $opportunities = app(CrossSourceKeywordOpportunities::class)->for($asset);

        $state = 'ready';
        $stateMessage = null;

        if (! $integrationStatus['configured']) {
            $state = 'dataforseo_not_configured';
            $stateMessage = 'Connect DataForSEO in Settings → Integrations to enable market-wide keyword visibility.';
        } elseif (! $asset->hasSeoMarketConfigured()) {
            $state = 'seo_market_not_configured';
            $stateMessage = 'Choose the Website\'s SEO market and language before running external keyword analysis.';
        } elseif ($summary === null && $rowsEvidence === null) {
            $state = 'no_data';
            $stateMessage = 'No external SEO intelligence yet. Use Refresh SEO intelligence when you are ready to query DataForSEO.';
        }

        $payload = is_array($summary?->payload) ? $summary->payload : [];
        $distribution = is_array($payload['organic_distribution'] ?? null) ? $payload['organic_distribution'] : [];
        $rankedRows = $this->presentRankedRows($rowsEvidence);

        $kpis = [];
        if ($summary !== null) {
            $kpis = array_values(array_filter([
                [
                    'label' => 'Ranked keywords',
                    'value' => $this->formatCompactInt($payload['total_count'] ?? $distribution['count'] ?? null),
                    'source' => 'dataforseo',
                    'note' => 'External keyword database',
                ],
                [
                    'label' => 'Top 10 rankings',
                    'value' => $this->formatCompactInt($distribution['top_10'] ?? null),
                    'source' => 'dataforseo',
                    'note' => 'Organic position band',
                ],
                [
                    'label' => 'Top 20 rankings',
                    'value' => $this->formatCompactInt($distribution['top_20'] ?? null),
                    'source' => 'dataforseo',
                    'note' => 'Organic position band',
                ],
                [
                    'label' => 'Estimated organic traffic',
                    'value' => $this->formatCompactNumber($payload['estimated_organic_traffic'] ?? null),
                    'source' => 'dataforseo_estimate',
                    'note' => 'DataForSEO estimate — not GA4 measured traffic',
                ],
                [
                    'label' => 'Estimated traffic value',
                    'value' => isset($payload['estimated_traffic_value']) && is_numeric($payload['estimated_traffic_value'])
                        ? '$'.number_format((float) $payload['estimated_traffic_value'], 0)
                        : '—',
                    'source' => 'dataforseo_estimate',
                    'note' => 'DataForSEO estimate — not GA4 revenue',
                ],
            ], static fn (array $kpi): bool => ($kpi['value'] ?? '—') !== '—'));
        }

        $market = $asset->hasSeoMarketConfigured()
            ? [
                'location_name' => $asset->seo_market_location_name,
                'language_name' => $asset->seo_market_language_name,
                'label' => trim(($asset->seo_market_location_name ?: '').' · '.($asset->seo_market_language_name ?: ''), ' ·'),
            ]
            : null;

        $noResults = $summary !== null
            && (int) ($payload['total_count'] ?? $payload['items_count'] ?? 0) === 0
            && $rankedRows === [];

        if ($noResults && $state === 'ready') {
            $state = 'no_results';
            $stateMessage = 'No qualifying keyword data was returned for this market.';
        }

        return [
            'state' => $state,
            'state_message' => $stateMessage,
            'market' => $market,
            'dataforseo_configured' => $integrationStatus['configured'],
            'kpis' => $kpis,
            'ranked_keywords' => $rankedRows,
            'ranked_columns' => $this->rankedColumns($rankedRows),
            'keyword_opportunities' => $opportunities,
            'overview' => [
                'ranked_keywords' => $payload['total_count'] ?? $distribution['count'] ?? null,
                'top_10' => $distribution['top_10'] ?? null,
                'opportunity_count' => $opportunities['count'] ?? 0,
                'top_opportunities' => $opportunities['overview'] ?? [],
                'has_data' => $summary !== null || ($opportunities['count'] ?? 0) > 0,
            ],
            'fresh_until' => $summary?->fresh_until,
            'retrieved_at' => data_get($payload, 'retrieved_at'),
            'estimate_disclaimer' => 'Estimated DataForSEO metrics are not GA4 measured traffic.',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function presentRankedRows(?Evidence $evidence): array
    {
        $rows = is_array($evidence?->payload['rows'] ?? null) ? $evidence->payload['rows'] : [];
        $out = [];

        foreach (array_slice($rows, 0, 50) as $row) {
            if (! is_array($row) || empty($row['keyword'])) {
                continue;
            }

            $volume = isset($row['search_volume']) && is_numeric($row['search_volume'])
                ? (int) $row['search_volume']
                : null;
            $trendMonthly = data_get($row, 'search_volume_trend.monthly');

            $out[] = [
                'keyword' => $row['keyword'],
                'position' => $row['rank_group'] ?? null,
                'position_label' => isset($row['rank_group']) ? (string) $row['rank_group'] : '—',
                'page' => $row['url'] ?? null,
                'page_path' => $row['page_path'] ?? null,
                'search_volume' => $volume,
                'search_volume_label' => $volume === null ? null : $this->formatCompactInt($volume),
                'keyword_difficulty' => $row['keyword_difficulty'] ?? null,
                'cpc' => isset($row['cpc']) && is_numeric($row['cpc']) ? (float) $row['cpc'] : null,
                'cpc_label' => isset($row['cpc']) && is_numeric($row['cpc'])
                    ? '$'.number_format((float) $row['cpc'], 2)
                    : null,
                'trend_label' => is_numeric($trendMonthly)
                    ? (($trendMonthly > 0 ? '+' : '').(int) $trendMonthly.'%')
                    : null,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function rankedColumns(array $rows): array
    {
        $columns = ['keyword', 'position_label', 'page_path'];
        foreach (['search_volume_label', 'keyword_difficulty', 'cpc_label', 'trend_label'] as $column) {
            foreach ($rows as $row) {
                if (($row[$column] ?? null) !== null) {
                    $columns[] = $column;
                    break;
                }
            }
        }

        return $columns;
    }

    private function formatCompactInt(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '—';
        }

        $number = (int) round((float) $value);
        if ($number >= 1000000) {
            return rtrim(rtrim(number_format($number / 1000000, 1), '0'), '.').'M';
        }
        if ($number >= 1000) {
            return rtrim(rtrim(number_format($number / 1000, 1), '0'), '.').'K';
        }

        return number_format($number);
    }

    private function formatCompactNumber(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '—';
        }

        $number = (float) $value;
        if ($number >= 1000000) {
            return rtrim(rtrim(number_format($number / 1000000, 1), '0'), '.').'M';
        }
        if ($number >= 1000) {
            return rtrim(rtrim(number_format($number / 1000, 1), '0'), '.').'K';
        }

        return number_format($number, $number < 10 ? 1 : 0);
    }
}
