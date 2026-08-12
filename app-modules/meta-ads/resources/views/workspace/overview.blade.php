@php
    use MoxDop\MetaAds\Support\MetaPercentage;

    /** @var array<string, mixed> $data */
    $identity = $data['account_identity'] ?? [];
    $health = $data['data_health'] ?? [];
    $kpis = $data['kpis'] ?? [];
    $kpisSecondary = $data['kpis_secondary'] ?? [];
    $resultGrouped = $data['result_mix_grouped'] ?? ['contact_conversion' => [], 'traffic_engagement' => []];
    $trend = $data['trend'] ?? ['available' => false];
    $flow = $data['delivery_flow'] ?? ['stages' => []];
    $attention = $data['attention'] ?? ['items' => [], 'empty_label' => 'No high-confidence issues detected for this period.'];
    $snapshot = $data['campaign_snapshot'] ?? [];
    $comparison = $data['comparison'] ?? ['available' => false];
    $needsAnalyze = (bool) ($data['needs_analyze'] ?? false);
    $periodMatched = (bool) ($data['period_matched'] ?? false);
    $async = $data['async_collection'] ?? null;
    $workspaceState = $data['workspace_state'] ?? 'no_connection';
    $brandName = $data['asset']->brand->name ?? null;

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

    $deltaClass = function (?string $sentiment): string {
        return match ($sentiment) {
            'positive' => 'mox-kpi-card__delta--up',
            'negative' => 'mox-kpi-card__delta--down',
            'neutral', 'flat' => 'mox-kpi-card__delta--flat',
            default => 'mox-kpi-card__delta--flat',
        };
    };
@endphp

<div class="mox-website-workspace mox-meta-workspace mox-meta-expert">
    <div class="mox-meta-context">
        <div>
            <p class="mox-meta-context__brand">{{ $brandName ?? 'Brand' }}</p>
            <h3 class="mox-section-title">
                {{ $identity['name'] ?? 'Meta Ad Account' }}
                @if (! empty($identity['external_id']))
                    <span class="mox-meta-id">{{ $identity['external_id'] }}</span>
                @endif
            </h3>
            <p class="mox-section-sub">
                @if (! empty($identity['business_name']))
                    Meta Business {{ $identity['business_name'] }} ·
                @endif
                @if (! empty($identity['currency']))
                    {{ $identity['currency'] }} ·
                @endif
                {{ $data['period_label'] ?? '' }}
                @if (! empty($data['last_updated_human']))
                    · Updated {{ $data['last_updated_human'] }}
                @endif
            </p>
        </div>
        <div class="mox-meta-context__actions">
            <button
                type="button"
                class="mox-meta-health mox-meta-health--{{ $health['tone'] ?? 'muted' }}"
                x-data
                x-on:click="$dispatch('open-modal', { id: 'meta-data-health' })"
            >
                {{ $health['label'] ?? 'Data Health' }}
            </button>
            @if ($async)
                <a href="{{ \App\Filament\App\Resources\Runs\RunResource::getUrl('index') }}" class="mox-meta-pill mox-meta-pill--link">
                    Collection running · View activity
                </a>
            @endif
        </div>
    </div>

    @include('meta-ads::workspace.partials.filter-bar', ['data' => $data])

    @if ($workspaceState === 'no_connection')
        <div class="mox-empty">Connect a Meta Ads account from Connection to start.</div>
    @elseif ($needsAnalyze || ! $periodMatched)
        <div class="mox-meta-analyze">
            <div>
                <h4>No trustworthy data for this period yet</h4>
                <p class="mox-muted">
                    Selected period metrics are not shown from a different window.
                    @if ($async)
                        Analysis is already running — you can keep working.
                    @else
                        Queue a read-only collection for this period.
                    @endif
                </p>
            </div>
            @if (! $async)
                <button type="button" class="mox-btn mox-btn--primary" wire:click="analyzeMetaSelectedPeriod">
                    Analyze this period
                </button>
            @endif
        </div>
    @endif

    @if ($periodMatched)
        <section class="mox-kpi-grid mox-kpi-grid--priority mox-kpi-grid--hero">
            @forelse ($kpis as $kpi)
                <div class="mox-kpi-card mox-kpi-card--primary">
                    <div class="mox-kpi-card__label">{{ $kpi['label'] }}</div>
                    <div class="mox-kpi-card__value">{{ $formatKpi($kpi) }}</div>
                    @if (is_numeric($kpi['cost_per_result'] ?? null))
                        <div class="mox-kpi-card__sub">Cost / result {{ number_format((float) $kpi['cost_per_result'], 2) }}</div>
                    @endif
                    @if (($comparison['available'] ?? false) && is_numeric($kpi['delta_percent'] ?? null))
                        <div class="mox-kpi-card__delta {{ $deltaClass($kpi['delta_sentiment'] ?? null) }}">
                            {{ ($kpi['delta_percent'] >= 0 ? '↑' : '↓') }}
                            {{ number_format(abs((float) $kpi['delta_percent']), 1) }}% vs previous period
                        </div>
                    @endif
                </div>
            @empty
                <div class="mox-empty">Priority metrics unavailable for this period.</div>
            @endforelse
        </section>

        @if ($kpisSecondary !== [])
            <section class="mox-kpi-grid mox-kpi-grid--secondary">
                @foreach ($kpisSecondary as $kpi)
                    <div class="mox-kpi-card mox-kpi-card--secondary">
                        <div class="mox-kpi-card__label">{{ $kpi['label'] }}</div>
                        <div class="mox-kpi-card__value mox-kpi-card__value--compact">{{ $formatKpi($kpi) }}</div>
                        @if (($comparison['available'] ?? false) && is_numeric($kpi['delta_percent'] ?? null))
                            <div class="mox-kpi-card__delta {{ $deltaClass($kpi['delta_sentiment'] ?? null) }}">
                                {{ ($kpi['delta_percent'] >= 0 ? '↑' : '↓') }}
                                {{ number_format(abs((float) $kpi['delta_percent']), 1) }}%
                            </div>
                        @endif
                    </div>
                @endforeach
            </section>
        @endif

        <section class="mox-panel mox-panel--chart">
            <div class="mox-panel__head">
                <h4>Performance trend</h4>
                <label class="mox-meta-inline-select">
                    <select wire:change="setMetaWorkspaceFilter('trend_metric', $event.target.value)">
                        @foreach (['spend' => 'Spend', 'inline_link_clicks' => 'Link clicks', 'inline_link_click_ctr' => 'Link CTR', 'cpm' => 'CPM', 'frequency' => 'Frequency'] as $key => $label)
                            <option value="{{ $key }}" @selected(($data['filters']['trend_metric'] ?? 'spend') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            @include('meta-ads::workspace.partials.performance-chart', ['trend' => $trend])
        </section>

        <div class="mox-grid-2">
            <section class="mox-panel">
                <div class="mox-panel__head">
                    <h4>Result summary</h4>
                    <span class="mox-muted">Platform-attributed · never summed across types</span>
                </div>
                @if (($resultGrouped['contact_conversion'] ?? []) === [] && ($resultGrouped['traffic_engagement'] ?? []) === [])
                    <div class="mox-empty">No compatible platform results observed.</div>
                @else
                    @if (($resultGrouped['contact_conversion'] ?? []) !== [])
                        <p class="mox-result-family-label">Contact / conversion results</p>
                        <ul class="mox-result-mix">
                            @foreach ($resultGrouped['contact_conversion'] as $item)
                                <li>
                                    <span class="mox-result-mix__label">{{ $item['human_label'] ?? 'Result' }}</span>
                                    <span class="mox-result-mix__value">{{ is_numeric($item['count'] ?? null) ? number_format((float) $item['count'], 0) : '—' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if (($resultGrouped['traffic_engagement'] ?? []) !== [])
                        <p class="mox-result-family-label">Traffic / engagement signals</p>
                        <ul class="mox-result-mix">
                            @foreach ($resultGrouped['traffic_engagement'] as $item)
                                <li>
                                    <span class="mox-result-mix__label">{{ $item['human_label'] ?? 'Signal' }}</span>
                                    <span class="mox-result-mix__value">{{ is_numeric($item['count'] ?? null) ? number_format((float) $item['count'], 0) : '—' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </section>

            <section class="mox-panel">
                <div class="mox-panel__head"><h4>What needs attention</h4></div>
                @if (($attention['items'] ?? []) === [])
                    <div class="mox-empty mox-empty--calm">{{ $attention['empty_label'] ?? 'No high-confidence issues detected for this period.' }}</div>
                @else
                    <ul class="mox-attention-list">
                        @foreach ($attention['items'] as $item)
                            <li>
                                <span class="mox-severity mox-severity--{{ $item['severity'] }}">{{ strtoupper($item['severity']) }}</span>
                                <div>
                                    <strong>{{ $item['title'] }}</strong>
                                    <p class="mox-muted">{{ $item['summary'] }}</p>
                                    <span class="mox-muted">{{ $item['inspect_label'] }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <section class="mox-panel">
            <div class="mox-panel__head">
                <h4>Campaign snapshot</h4>
                <span class="mox-muted">Delivered in selected period · by spend</span>
            </div>
            @if ($snapshot === [])
                <div class="mox-empty">No delivered campaigns for this period.</div>
            @else
                <div class="mox-campaign-cards">
                    @foreach ($snapshot as $row)
                        <article class="mox-campaign-card">
                            <div class="mox-campaign-card__head">
                                <strong>{{ $row['name'] ?? '—' }}</strong>
                                <span class="mox-status-pill mox-status-pill--{{ strtolower((string) ($row['effective_status'] ?? $row['status'] ?? 'unknown')) }}">
                                    {{ $row['effective_status'] ?? $row['status'] ?? '—' }}
                                </span>
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
        </section>
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
            @if (! empty($data['partial_reasons']))
                <p class="mox-footnote" style="margin-top:0.75rem;">
                    Partial reasons: {{ implode('; ', $data['partial_reasons']) }}
                </p>
            @endif
            @if (! empty($health['sync_label']))
                <p class="mox-muted" style="margin-top:0.5rem;">Last sync: {{ $health['sync_label'] }}</p>
            @endif
        </div>
    </x-filament::modal>
</div>
