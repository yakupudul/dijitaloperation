@props([
    'label' => '',
    'value' => '—',
    'delta' => null,
    'tone' => 'neutral',
    'icon' => null,
    'hint' => null,
    'spark' => null,
    'href' => null,
    'sparkTone' => null,
    'muted' => false,
])

@php
    // KPI card (panel redesign 2026-10-19): label, big number, direction-aware delta chip, sparkline of the last days.
    // `tone` says what the delta means (positive / negative / warning / neutral), never which way the number moved.
    $toneClass = match ($tone) {
        'positive', 'success', 'ok' => 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-400',
        'negative', 'error', 'critical', 'bad' => 'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400',
        'warning', 'look' => 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400',
        default => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300',
    };
    $sparkTone ??= match ($tone) {
        'positive', 'success', 'ok' => 'ok',
        'negative', 'error', 'critical', 'bad' => 'bad',
        'warning', 'look' => 'look',
        default => 'neutral',
    };
    $tag = $href ? 'a' : 'div';
    $classes = 'surface flex flex-col gap-2 px-4 py-3.5 '.($href ? 'transition hover:ring-gray-300 dark:hover:ring-gray-700' : '');
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @if (! str_starts_with($href, '#')) wire:navigate @endif @endif {{ $attributes->merge(['class' => $classes]) }}>
    <div class="flex items-center justify-between gap-2">
        <span class="truncate text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</span>
        @if ($icon)
            <span class="text-gray-400">{!! $icon !!}</span>
        @endif
    </div>

    <div class="flex items-end justify-between gap-3">
        <div class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-1">
            <span @class(['tabular-nums tracking-tight', 'text-2xl font-semibold text-gray-900 dark:text-white' => ! $muted, 'text-lg font-medium text-gray-400 dark:text-gray-500' => $muted])>{{ $value }}</span>
            @if ($delta !== null && $delta !== '')
                <span class="rounded-full px-1.5 py-0.5 text-[11px] font-semibold tabular-nums {{ $toneClass }}">{{ $delta }}</span>
            @endif
        </div>
        @if (is_array($spark) && count($spark) >= 2)
            <x-ta.sparkline :values="$spark" :tone="$sparkTone" :width="72" :height="24" />
        @endif
    </div>

    @if ($hint !== null && $hint !== '')
        <p class="text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $hint }}</p>
    @endif
    @if (! $slot->isEmpty())
        <div class="text-sm">{{ $slot }}</div>
    @endif
</{{ $tag }}>
