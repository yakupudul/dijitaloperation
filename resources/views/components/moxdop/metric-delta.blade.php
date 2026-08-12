@props([
    'value' => null,
    'direction' => 'na',
    'positiveIsGood' => true,
])

{{--
    Colored up/down/flat delta indicator. Renders nothing when direction is
    "na" or value is null — a delta is only shown when a valid comparison
    period actually exists (Missing != zero, docs/product/MOXDOP_DESIGN_SYSTEM.md).

    "direction" describes the arrow (up/down/flat); "positiveIsGood" decides
    whether an "up" move is colored as good (--mox-result) or bad
    (--mox-critical) — e.g. spend going up is not automatically good.
--}}
@php
    $showable = $direction !== 'na' && $value !== null && $value !== '';

    $isGood = match ($direction) {
        'up' => (bool) $positiveIsGood,
        'down' => ! $positiveIsGood,
        default => null,
    };

    $toneClass = match (true) {
        $direction === 'flat' => 'mox-metric-delta--flat',
        $isGood === true => 'mox-metric-delta--up',
        $isGood === false => 'mox-metric-delta--down',
        default => 'mox-metric-delta--flat',
    };

    $arrow = match ($direction) {
        'up' => '↑',
        'down' => '↓',
        default => '→',
    };
@endphp

@if ($showable)
    <span {{ $attributes->class(['mox-metric-delta', $toneClass]) }}>
        <span aria-hidden="true">{{ $arrow }}</span>
        <span>{{ $value }}</span>
    </span>
@endif
