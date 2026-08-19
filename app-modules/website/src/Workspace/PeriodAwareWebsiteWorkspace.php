<?php

namespace MoxDop\Website\Workspace;

use App\Enums\DataPool\DataSourceState;
use App\Models\DigitalAsset;
use App\Services\Ga4\Ga4SpecialistReadService;
use App\Services\Gsc\GscSpecialistReadService;

/**
 * Composes Website operational data with period-aware reads from the canonical
 * GA4 and Search Console local data pools. Provider APIs are never called here.
 */
final class PeriodAwareWebsiteWorkspace
{
    public function __construct(
        private readonly WebsiteWorkspaceData $base,
        private readonly Ga4SpecialistReadService $ga4,
        private readonly GscSpecialistReadService $gsc,
    ) {}

    /** @return array<string, mixed> */
    public function for(DigitalAsset $asset, string $preset, ?string $start = null, ?string $end = null): array
    {
        $data = $this->base->for($asset);
        $assetId = (string) $asset->id;

        $gsc = $this->gsc->workspace($assetId, $preset, $start, $end);
        $ga4 = $this->ga4->workspace($assetId, $preset, $start, $end);

        $periodStart = $gsc['period_start'] ?? $ga4['period_start'] ?? $start;
        $periodEnd = $gsc['period_end'] ?? $ga4['period_end'] ?? $end;
        $periodLabel = $gsc['period_label'] ?? $ga4['period_label'] ?? null;
        $compareLabel = $gsc['compare_label'] ?? $ga4['compare_label'] ?? null;

        $data['period'] = [
            'preset' => $preset,
            'start' => $periodStart,
            'end' => $periodEnd,
        ];
        $data['comparison_period'] = ['label' => $compareLabel];
        $data['period_label'] = $periodLabel;
        $data['period_is_live_query'] = true;
        $data['kpis'] = $this->kpis($gsc, $ga4);

        if ($this->trusted($gsc, 'performance_trend.clicks')) {
            $data['gsc_daily'] = $gsc['metric_series'] ?? $gsc['performance_trend'] ?? [];
        }
        if ($this->trusted($gsc, 'demand.queries')) {
            $data['queries'] = array_slice($gsc['demand']['queries'] ?? [], 0, 20);
        }
        if ($this->trusted($gsc, 'pages.directory')) {
            $data['pages'] = array_slice($gsc['pages']['directory'] ?? [], 0, 20);
        }
        if ($this->trusted($ga4, 'behavior.landing_pages')) {
            $data['landing_pages'] = array_slice($ga4['behavior']['landing_pages'] ?? [], 0, 20);
        }
        if ($this->trusted($ga4, 'acquisition.channels')) {
            $data['acquisition'] = array_slice($ga4['acquisition']['channels'] ?? [], 0, 20);
        }

        $data['ga4_summary'] = $this->trusted($ga4, 'glance.sessions') ? $ga4['glance'] : null;
        $data['gsc_summary'] = $this->trusted($gsc, 'glance.clicks') ? $gsc['glance'] : null;
        $data['has_performance_data'] = $data['kpis'] !== [];
        $data['period_provenance'] = [
            'ga4' => $ga4['migration_mode'] ?? null,
            'gsc' => $gsc['migration_mode'] ?? null,
        ];

        return $data;
    }

    /** @return list<array{label:string,value:mixed,delta_label:?string,source:string}> */
    private function kpis(array $gsc, array $ga4): array
    {
        $rows = [];

        if ($this->trusted($gsc, 'glance.clicks')) {
            $rows[] = $this->kpi(__('operator_runtime.website.kpi.organic_clicks'), data_get($gsc, 'glance.clicks.value'), data_get($gsc, 'glance.clicks.secondary'), 'gsc');
        }
        if ($this->trusted($gsc, 'glance.impressions')) {
            $rows[] = $this->kpi(__('operator_runtime.website.kpi.impressions'), data_get($gsc, 'glance.impressions.value'), data_get($gsc, 'glance.impressions.secondary'), 'gsc');
        }
        if ($this->trusted($gsc, 'glance.ctr')) {
            $rows[] = $this->kpi(__('operator_runtime.website.kpi.ctr'), data_get($gsc, 'glance.ctr.value'), data_get($gsc, 'glance.ctr.secondary'), 'gsc');
        }
        if ($this->trusted($ga4, 'glance.sessions')) {
            $rows[] = $this->kpi(__('operator_runtime.website.kpi.sessions'), data_get($ga4, 'glance.sessions.value'), data_get($ga4, 'glance.sessions.secondary'), 'ga4');
        }

        return array_values(array_filter($rows, static fn (array $row): bool => $row['value'] !== null));
    }

    /** @return array{label:string,value:mixed,delta_label:?string,source:string} */
    private function kpi(string $label, mixed $value, mixed $delta, string $source): array
    {
        return [
            'label' => $label,
            'value' => $value,
            'delta_label' => is_string($delta) && trim($delta) !== '' ? $delta : null,
            'source' => $source,
        ];
    }

    private function trusted(array $workspace, string $field): bool
    {
        $provenance = is_array($workspace['data_provenance'] ?? null)
            ? $workspace['data_provenance']
            : [];
        $state = $provenance[$field] ?? null;
        if (! is_string($state)) {
            return false;
        }

        $enum = DataSourceState::tryFrom($state);

        return $enum?->isTrustedPresentation() ?? false;
    }
}
