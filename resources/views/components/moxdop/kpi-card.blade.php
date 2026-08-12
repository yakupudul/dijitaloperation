@props([
    'label',
    'value',
    'delta' => null,
    'hint' => null,
    'family' => 'spend',
])

{{--
    Single KPI tile (KPI card standard, docs/product/MOXDOP_DESIGN_SYSTEM.md).
    "family" drives only a thin top accent border (spend/result/traffic/
    efficiency) — never a saturated background fill. "delta" accepts an
    array (['value' => ..., 'direction' => 'up'|'down'|'flat'|'na',
    'positiveIsGood' => bool]) rendered via <x-moxdop.metric-delta>, a
    pre-rendered string, or null (no comparison available).
--}}
@php
    $familyClass = match (strtolower((string) $family)) {
        'result' => 'mox-kpi-card--result',
        'traffic' => 'mox-kpi-card--traffic',
        'efficiency' => 'mox-kpi-card--efficiency',
        default => 'mox-kpi-card--spend',
    };
@endphp

<div {{ $attributes->class(['mox-kpi-card', $familyClass]) }}>
    <div class="mox-kpi-card__label">{{ $label }}</div>
    <div class="mox-kpi-card__value">{{ $value === null || $value === '' ? '—' : $value }}</div>

    @if (is_array($delta))
        <div class="mox-kpi-card__delta">
            <x-moxdop.metric-delta
                :value="$delta['value'] ?? null"
                :direction="$delta['direction'] ?? 'na'"
                :positive-is-good="$delta['positiveIsGood'] ?? true"
            />
        </div>
    @elseif (filled($delta))
        <div class="mox-kpi-card__delta">{{ $delta }}</div>
    @endif

    @if (filled($hint))
        <div class="mox-kpi-card__hint">{{ $hint }}</div>
    @endif

    @isset($sparkline)
        <div class="mox-kpi-card__sparkline">
            {{ $sparkline }}
        </div>
    @endisset
</div>
