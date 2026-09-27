<?php

namespace MoxDop\Website\Workspace;

use App\Enums\DataPool\DataSourceState;
use App\Models\DigitalAsset;
use App\Services\Ga4\Ga4SpecialistReadService;
use App\Services\Ga4\WebsiteGa4AnalysisService;
use App\Services\Gsc\GscSpecialistReadService;
use App\Services\Gsc\WebsiteSearchConsoleAnalysisService;

/**
 * Composes Website operational data with period-aware reads from the canonical
 * GA4 and Search Console local data pools. Provider APIs are never called here.
 *
 * The overview KPI cards read the same resource-first Data Pool property totals as the website's
 * Search Console and Google Analytics tabs (WebsiteSearchConsoleAnalysisService / WebsiteGa4AnalysisService),
 * so a site whose facts are collected always shows its numbers for the selected period.
 */
final class PeriodAwareWebsiteWorkspace
{
    public function __construct(
        private readonly WebsiteWorkspaceData $base,
        private readonly Ga4SpecialistReadService $ga4,
        private readonly GscSpecialistReadService $gsc,
        private readonly WebsiteSearchConsoleAnalysisService $gscPool,
        private readonly WebsiteGa4AnalysisService $ga4Pool,
    ) {}

    /** @return array<string, mixed> */
    public function for(DigitalAsset $asset, string $preset, ?string $start = null, ?string $end = null): array
    {
        $data = $this->base->for($asset, $start, $end);
        $explicitPeriod = filled($start) && filled($end);
        $assetId = (string) $asset->id;

        $gsc = $this->gsc->workspace($assetId, $preset, $start, $end);
        $ga4 = $this->ga4->workspace($assetId, $preset, $start, $end);

        $periodStart = $gsc['period_start'] ?? $ga4['period_start'] ?? $start;
        $periodEnd = $gsc['period_end'] ?? $ga4['period_end'] ?? $end;
        $periodLabel = $gsc['period_label'] ?? $ga4['period_label'] ?? $data['period_label'] ?? null;
        $compareLabel = $gsc['compare_label'] ?? $ga4['compare_label'] ?? data_get($data, 'comparison_period.label');

        $data['period'] = [
            'preset' => $preset,
            'start' => $periodStart,
            'end' => $periodEnd,
        ];
        $data['comparison_period'] = ['label' => $compareLabel];
        $data['period_label'] = $periodLabel;
        $data['period_is_live_query'] = true;

        $gscHeadline = $this->gscPool->build($asset, $preset, $start, $end, true, 'previous', headlineOnly: true);
        $ga4Headline = $this->ga4Pool->build($asset, $preset, $start, $end, true, 'previous', headlineOnly: true);
        $factKpis = $this->factKpis($gscHeadline, $ga4Headline);
        $poolKpis = $factKpis !== [] ? $factKpis : $this->kpis($gsc, $ga4);
        if ($poolKpis !== []) {
            $data['kpis'] = $poolKpis;
        } elseif (! $explicitPeriod) {
            $data['kpis'] = [];
        }

        if ($this->trusted($gsc, 'performance_trend.clicks')) {
            $data['gsc_daily'] = $gsc['metric_series'] ?? $gsc['performance_trend'] ?? [];
        } elseif (! $explicitPeriod) {
            $data['gsc_daily'] = ['labels' => [], 'clicks' => [], 'impressions' => []];
        }
        if ($this->trusted($gsc, 'demand.queries')) {
            $data['queries'] = array_slice($gsc['demand']['queries'] ?? [], 0, 20);
        } elseif (! $explicitPeriod) {
            $data['queries'] = [];
        }
        if ($this->trusted($gsc, 'pages.directory')) {
            $data['pages'] = array_slice($gsc['pages']['directory'] ?? [], 0, 20);
        } elseif (! $explicitPeriod) {
            $data['pages'] = [];
        }
        if ($this->trusted($ga4, 'behavior.landing_pages')) {
            $data['landing_pages'] = array_slice($ga4['behavior']['landing_pages'] ?? [], 0, 20);
        } elseif (! $explicitPeriod) {
            $data['landing_pages'] = [];
        }
        if ($this->trusted($ga4, 'acquisition.channels')) {
            $data['acquisition'] = array_slice($ga4['acquisition']['channels'] ?? [], 0, 20);
        } elseif (! $explicitPeriod) {
            $data['acquisition'] = [];
        }

        if ($this->trusted($ga4, 'glance.sessions')) {
            $data['ga4_summary'] = $ga4['glance'];
        } elseif (! $explicitPeriod) {
            $data['ga4_summary'] = null;
        }
        if ($this->trusted($gsc, 'glance.clicks')) {
            $data['gsc_summary'] = $gsc['glance'];
        } elseif (! $explicitPeriod) {
            $data['gsc_summary'] = null;
        }
        $hasFacts = filled(data_get($gscHeadline, 'coverage.end')) || filled(data_get($ga4Headline, 'coverage.end'));
        if ($poolKpis !== [] || $hasFacts) {
            $data['has_performance_data'] = true;
        } elseif (! $explicitPeriod) {
            $data['has_performance_data'] = $data['kpis'] !== [];
        }
        if ($factKpis !== []) {
            $data['period_has_data'] = true;
        } elseif ($hasFacts) {
            $data['period_has_data'] = false;
        }
        $data['period_provenance'] = [
            'ga4' => $ga4['migration_mode'] ?? null,
            'gsc' => $gsc['migration_mode'] ?? null,
        ];

        return $data;
    }

    /**
     * KPI cards from the Data Pool property totals of the selected period (Search Console clicks, impressions,
     * CTR; GA4 sessions), with the change against the previous period of the same length.
     *
     * @param  array<string, mixed>  $gsc  WebsiteSearchConsoleAnalysisService headline
     * @param  array<string, mixed>  $ga4  WebsiteGa4AnalysisService headline
     * @return list<array{label:string,value:mixed,delta_label:?string,source:string}>
     */
    private function factKpis(array $gsc, array $ga4): array
    {
        $metric = static fn (array $model, string $key): ?array => collect($model['metrics'] ?? [])->firstWhere('key', $key);
        $rows = [];
        if (($gsc['has_data'] ?? false) === true) {
            foreach (['clicks' => 'organic_clicks', 'impressions' => 'impressions', 'ctr' => 'ctr'] as $key => $label) {
                if (($m = $metric($gsc, $key)) !== null && $m['value'] !== null) {
                    $rows[] = $this->kpi(__('operator_runtime.website.kpi.'.$label), $this->formatValue($m), $this->deltaLabel($m), 'gsc');
                }
            }
        }
        if (($ga4['has_data'] ?? false) === true && ($m = $metric($ga4, 'sessions')) !== null && $m['value'] !== null) {
            $rows[] = $this->kpi(__('operator_runtime.website.kpi.sessions'), $this->formatValue($m), $this->deltaLabel($m), 'ga4');
        }

        return $rows;
    }

    /** @param  array<string, mixed>  $metric */
    private function formatValue(array $metric): string
    {
        [$thousands, $decimal] = app()->getLocale() === 'tr' ? ['.', ','] : [',', '.'];

        return ($metric['format'] ?? 'number') === 'percent'
            ? number_format((float) $metric['value'], 1, $decimal, $thousands).'%'
            : number_format((float) $metric['value'], 0, $decimal, $thousands);
    }

    /** @param  array<string, mixed>  $metric */
    private function deltaLabel(array $metric): ?string
    {
        $delta = $metric['delta'] ?? null;
        if ($delta === null) {
            return null;
        }
        [$thousands, $decimal] = app()->getLocale() === 'tr' ? ['.', ','] : [',', '.'];
        $unit = ($metric['delta_kind'] ?? 'percent') === 'pp' ? ' pp' : '%';

        return ($delta > 0 ? '+' : '').number_format((float) $delta, 1, $decimal, $thousands).$unit.' '.__('operator.period.vs').' '.mb_strtolower(__('operator.period.compare_previous'));
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
