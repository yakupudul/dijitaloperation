@php
    use MoxDop\MetaAds\Support\MetaPercentage;

    /** @var array<string, mixed> $data */
    $identity = $data['account_identity'] ?? [];
    $health = $data['data_health'] ?? [];
    $kpis = $data['kpis'] ?? [];
    $kpisSecondary = $data['kpis_secondary'] ?? [];
    $resultGrouped = $data['result_mix_grouped'] ?? ['contact_conversion' => [], 'traffic_engagement' => []];
    $trend = $data['trend'] ?? ['available' => false];
    $attention = $data['attention'] ?? ['items' => [], 'empty_label' => 'No high-confidence issues detected for this period.'];
    $snapshot = $data['campaign_snapshot'] ?? [];
    $comparison = $data['comparison'] ?? ['available' => false];
    $periodMatched = (bool) ($data['period_matched'] ?? false);
    $history = $data['history'] ?? ['state' => 'no_connection', 'message' => null];
    $historyState = $history['state'] ?? 'no_connection';
    $async = $data['async_collection'] ?? null;
    $brandName = $data['asset']->brand->name ?? null;

    $coverageRange = null;
    $dailyCoverage = data_get($history, 'coverage.daily_facts');
    if (is_array($dailyCoverage) && filled($dailyCoverage['start_date'] ?? null) && filled($dailyCoverage['end_date'] ?? null)) {
        $coverageRange = 'History '.$dailyCoverage['start_date'].' → '.$dailyCoverage['end_date'];
    }

    $formatKpi = function (array $kpi) {
        $value = $kpi['value'] ?? null;
        if (! is_numeric($value)) {
            return '—';
        }

        return match ($kpi['type'] ?? 'count') {
            'percentage_point' => MetaPercentage::format($value),
            'currency' => number_format((float) $value, 2),
            'decimal' => number_format((float) $value, 2),
            default => number_format((float) $value, 0),
        };
    };

    $kpiFamily = function (array $kpi): string {
        if (! empty($kpi['family'])) {
            return (string) $kpi['family'];
        }

        return match (true) {
            ($kpi['key'] ?? '') === 'spend' => 'spend',
            str_starts_with((string) ($kpi['key'] ?? ''), 'result_') => 'result',
            in_array($kpi['key'] ?? '', ['cpc', 'cpm', 'frequency', 'inline_link_click_ctr', 'ctr'], true) => 'efficiency',
            default => 'traffic',
        };
    };

    $deltaString = function (array $kpi) use ($comparison): ?string {
        if (! ($comparison['available'] ?? false) || ! is_numeric($kpi['delta_percent'] ?? null)) {
            return null;
        }
        $arrow = $kpi['delta_percent'] >= 0 ? '↑' : '↓';

        return $arrow.' '.number_format(abs((float) $kpi['delta_percent']), 1).'% vs previous period';
    };
@endphp

<div class="mox-website-workspace mox-meta-workspace mox-meta-expert">
    <x-moxdop.workspace-header
        :brand="$brandName"
        :account="$identity['name'] ?? 'Meta Ad Account'"
        :account-id="$identity['external_id'] ?? null"
        :business="$identity['business_name'] ?? null"
        :currency="$identity['currency'] ?? null"
        :last-sync="$data['last_updated_human'] ?? null"
        :history-coverage="$coverageRange"
    >
        <x-slot name="actions">
            <x-moxdop.data-health-badge
                :label="$health['label'] ?? 'Data Health'"
                :tone="$health['tone'] ?? 'neutral'"
                x-data
                x-on:click="$dispatch('open-modal', { id: 'meta-data-health' })"
            />
            <button type="button" class="mox-btn mox-btn--secondary" wire:click="refreshMetaWorkspaceData">Refresh data</button>
            <button type="button" class="mox-btn mox-btn--secondary" wire:click="generateMetaAdsAiGuidanceFromWorkspace">Generate analysis</button>
            @if ($async)
                <a href="{{ \App\Filament\App\Resources\Runs\RunResource::getUrl('index') }}" class="mox-btn mox-btn--ghost">Activity</a>
            @endif
        </x-slot>
    </x-moxdop.workspace-header>

    @include('meta-ads::workspace.partials.filter-bar', ['data' => $data])

    @if ($historyState === 'no_connection')
        <x-moxdop.empty-state
            title="No Meta Ad Account connected"
            body="Connect a Meta Ads account from the Connection tab to load performance history."
        />
    @elseif ($historyState === 'unavailable')
        <x-moxdop.empty-state
            title="Meta history is not available for this period"
            :body="'The selected range is older than the provider makes available. Choose a more recent period.'"
        />
    @elseif ($historyState === 'preparing')
        <x-moxdop.operation-progress
            :title="$history['message'] ?? 'Preparing missing history'"
            phase="Fetching daily facts and reach/frequency for the selected range"
            :status="$async ? 'running' : 'queued'"
        />
    @elseif ($historyState === 'fallback')
        <x-moxdop.section-card>
            <p class="mox-muted">{{ $history['message'] }}</p>
        </x-moxdop.section-card>
    @endif

    @if ($periodMatched)
        <section class="mox-kpi-grid mox-kpi-grid--priority mox-kpi-grid--hero">
            @forelse ($kpis as $kpi)
                <x-moxdop.kpi-card
                    :label="$kpi['label']"
                    :value="$formatKpi($kpi)"
                    :family="$kpiFamily($kpi)"
                    :delta="$deltaString($kpi)"
                    :hint="is_numeric($kpi['cost_per_result'] ?? null) ? 'Cost / result '.number_format((float) $kpi['cost_per_result'], 2) : null"
                />
            @empty
                <x-moxdop.empty-state compact title="Priority metrics unavailable" body="No account-level delivery in this period." />
            @endforelse
        </section>

        @if ($kpisSecondary !== [])
            <section class="mox-kpi-grid mox-kpi-grid--secondary">
                @foreach ($kpisSecondary as $kpi)
                    <x-moxdop.kpi-card
                        :label="$kpi['label']"
                        :value="$formatKpi($kpi)"
                        :family="$kpiFamily($kpi)"
                        :delta="$deltaString($kpi)"
                    />
                @endforeach
            </section>
        @endif

        <x-moxdop.chart-card
            title="How is performance trending across the period?"
            :description="$data['period_label'] ?? null"
        >
            <x-slot name="toolbar">
                <label class="mox-meta-inline-select">
                    <select wire:change="setMetaWorkspaceFilter('trend_metric', $event.target.value)">
                        @foreach (['spend' => 'Spend', 'inline_link_clicks' => 'Link clicks', 'inline_link_click_ctr' => 'Link CTR', 'cpm' => 'CPM', 'frequency' => 'Frequency'] as $key => $label)
                            <option value="{{ $key }}" @selected(($data['filters']['trend_metric'] ?? 'spend') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </x-slot>
            @include('meta-ads::workspace.partials.performance-chart', ['trend' => $trend])
        </x-moxdop.chart-card>

        <div class="mox-grid-2">
            <x-moxdop.section-card
                title="Results this period"
                description="Platform-attributed · distinct action types are never summed"
            >
                @if (($resultGrouped['contact_conversion'] ?? []) === [] && ($resultGrouped['traffic_engagement'] ?? []) === [])
                    <x-moxdop.empty-state compact title="No platform results observed" body="No compatible Meta result signals for this period." />
                @else
                    @if (($resultGrouped['contact_conversion'] ?? []) !== [])
                        <p class="mox-result-family-label">Contact / conversion results</p>
                        <div class="mox-result-card-list">
                            @foreach ($resultGrouped['contact_conversion'] as $item)
                                <x-moxdop.result-card
                                    family="contact"
                                    :label="$item['human_label'] ?? 'Result'"
                                    :value="is_numeric($item['count'] ?? null) ? number_format((float) $item['count'], 0) : '—'"
                                />
                            @endforeach
                        </div>
                    @endif
                    @if (($resultGrouped['traffic_engagement'] ?? []) !== [])
                        <p class="mox-result-family-label">Traffic / engagement signals</p>
                        <div class="mox-result-card-list">
                            @foreach ($resultGrouped['traffic_engagement'] as $item)
                                <x-moxdop.result-card
                                    family="traffic"
                                    :label="$item['human_label'] ?? 'Signal'"
                                    :value="is_numeric($item['count'] ?? null) ? number_format((float) $item['count'], 0) : '—'"
                                />
                            @endforeach
                        </div>
                    @endif
                @endif
            </x-moxdop.section-card>

            <x-moxdop.section-card title="What needs attention">
                @if (($attention['items'] ?? []) === [])
                    <x-moxdop.empty-state compact title="Nothing needs attention" :body="$attention['empty_label'] ?? 'No high-confidence issues detected for this period.'" />
                @else
                    <div class="mox-attention-list">
                        @foreach ($attention['items'] as $item)
                            <x-moxdop.attention-card
                                :severity="$item['severity']"
                                :title="$item['title']"
                                :body="$item['summary']"
                                :entity="$item['campaign_name'] ?? null"
                            />
                        @endforeach
                    </div>
                @endif
            </x-moxdop.section-card>
        </div>

        <x-moxdop.section-card
            title="Campaign snapshot"
            description="Delivered in the selected period · ranked by spend"
        >
            @if ($snapshot === [])
                <x-moxdop.empty-state compact title="No delivered campaigns" body="No campaigns delivered in the selected period." />
            @else
                <div class="mox-campaign-cards">
                    @foreach ($snapshot as $row)
                        <article class="mox-campaign-card">
                            <div class="mox-campaign-card__head">
                                <strong>{{ $row['name'] ?? '—' }}</strong>
                                <x-moxdop.status-pill
                                    :label="$row['effective_status'] ?? $row['status'] ?? '—'"
                                    :tone="strtolower((string) ($row['effective_status'] ?? $row['status'] ?? 'neutral')) === 'active' ? 'active' : 'neutral'"
                                />
                            </div>
                            <dl class="mox-campaign-card__metrics">
                                <div><dt>Spend</dt><dd>{{ is_numeric($row['spend'] ?? null) ? number_format((float) $row['spend'], 2) : '—' }}</dd></div>
                                <div><dt>Result</dt><dd>{{ $row['primary_result_human_label'] ?? ($row['primary_result_status'] ?? '—') }}</dd></div>
                                <div><dt>Count</dt><dd>{{ is_numeric($row['primary_result_count'] ?? null) ? number_format((float) $row['primary_result_count'], 0) : '—' }}</dd></div>
                                <div><dt>Link CTR</dt><dd>{{ is_numeric($row['inline_link_click_ctr'] ?? null) ? MetaPercentage::format($row['inline_link_click_ctr']) : '—' }}</dd></div>
                            </dl>
                        </article>
                    @endforeach
                </div>
            @endif
        </x-moxdop.section-card>
    @endif

    <x-filament::modal id="meta-data-health" width="lg">
        <x-slot name="heading">Data health</x-slot>
        <div class="mox-data-health-detail">
            @foreach (($health['detail'] ?? []) as $key => $status)
                <div class="mox-data-health-detail__row">
                    <span>{{ str_replace('_', ' ', ucfirst($key)) }}</span>
                    <strong>{{ $status }}</strong>
                </div>
            @endforeach
            @if ($coverageRange)
                <p class="mox-muted" style="margin-top:0.75rem;">{{ $coverageRange }}</p>
            @endif
            @if (! empty($data['partial_reasons']))
                <p class="mox-footnote" style="margin-top:0.5rem;">
                    Partial reasons: {{ implode('; ', $data['partial_reasons']) }}
                </p>
            @endif
            @if (! empty($health['sync_label']))
                <p class="mox-muted" style="margin-top:0.5rem;">Last sync: {{ $health['sync_label'] }}</p>
            @endif
        </div>
    </x-filament::modal>
</div>
