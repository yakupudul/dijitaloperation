@php
    /** @var list<array<string, mixed>> $kpis */
    $kpis = $kpis ?? [];
@endphp

@if ($kpis === [])
    <div class="mox-empty">No performance Evidence yet. Use <strong>Refresh data</strong> to collect Search Console and GA4.</div>
@else
    <div class="mox-kpi-grid">
        @foreach ($kpis as $kpi)
            <div class="mox-kpi-card">
                <div class="mox-kpi-card__label">{{ $kpi['label'] }}</div>
                <div class="mox-kpi-card__value">{{ $kpi['value'] }}</div>
                @if (! empty($kpi['delta_label']))
                    <div class="mox-kpi-card__delta mox-kpi-card__delta--{{ $kpi['direction'] }}">
                        {{ $kpi['delta_label'] }}
                    </div>
                @endif
                <div class="mox-kpi-card__source">{{ $kpi['source'] === 'gsc' ? 'Search Console' : 'GA4' }}</div>
            </div>
        @endforeach
    </div>
@endif
