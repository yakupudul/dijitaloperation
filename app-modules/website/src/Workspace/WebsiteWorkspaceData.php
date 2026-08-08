<?php

namespace MoxDop\Website\Workspace;

use App\Models\CoreAssetBinding;
use App\Models\CoreConnection;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Support\Integrations\ProviderRegistry;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

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
                    'medium' => Finding::query()->where('digital_asset_id', $asset->id)->where('status', 'open')->where('severity', 'medium')->count(),
                ],
            ],
            'recommendations' => $recommendations,
            'diagnosis' => $this->diagnosisSummary($diagnosisRun),
            'connections' => $connections,
            'connection_health' => $this->connectionHealthLine($connections),
            'activity' => $this->activityRows($asset),
            'has_performance_data' => $gscSummary !== null || $ga4Summary !== null,
        ];
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
            $run->module_id === 'website-diagnosis' => 'Website technical check',
            $capability === 'search_console' => 'Search Console data refresh',
            $capability === 'ga4' => 'GA4 data refresh',
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

                return [
                    'id' => $run->id,
                    'title' => $this->runTitle($run),
                    'status' => $run->status,
                    'started_at' => $started,
                    'duration' => $duration,
                    'source' => data_get($run->metadata, 'resource_display_name')
                        ?: data_get($run->metadata, 'capability')
                        ?: 'Website',
                ];
            })
            ->all();
    }
}
