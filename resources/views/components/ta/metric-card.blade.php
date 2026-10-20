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
    // One cell of a `metric-strip` card (panel concept 2026-10-20): gray label, big number with the change next to it,
    // one gray line under it, the trend as a thin line across the cell. `tone` says what the change means.
    $toneClass = match ($tone) {
        'positive', 'success', 'ok' => 'text-success-600 dark:text-success-400',
        'negative', 'error', 'critical', 'bad' => 'text-error-600 dark:text-error-400',
        'warning', 'look' => 'text-warning-700 dark:text-warning-400',
        default => 'text-gray-500 dark:text-gray-400',
    };
    $sparkTone ??= match ($tone) {
        'positive', 'success', 'ok' => 'up',
        'negative', 'error', 'critical', 'bad' => 'down',
        default => 'neutral',
    };
    $tag = $href ? 'a' : 'div';
    $classes = 'group flex min-w-0 flex-col bg-white px-[18px] py-4 dark:bg-gray-900 '.($href ? 'transition hover:bg-gray-50 dark:hover:bg-white/[0.03]' : '');
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @if (! str_starts_with($href, '#')) wire:navigate @endif @endif {{ $attributes->merge(['class' => $classes]) }}>
    <span class="text-[13px] text-gray-500 dark:text-gray-400">{{ $label }}</span>
    <span class="mt-1.5 flex flex-wrap items-baseline gap-x-2">
        <span @class(['tabular-nums tracking-[-0.02em]', 'text-[26px] leading-8 font-semibold text-gray-900 dark:text-white' => ! $muted, 'text-lg leading-8 font-medium text-gray-400 dark:text-gray-500' => $muted])>{{ $value }}</span>
        @if ($delta !== null && $delta !== '')
            <span class="whitespace-nowrap text-[13px] font-medium tabular-nums {{ $toneClass }}">{{ $delta }}</span>
        @endif
    </span>
    @if ($hint !== null && $hint !== '')
        <span class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</span>
    @endif
    @if (! $slot->isEmpty())
        <div class="mt-1 text-xs">{{ $slot }}</div>
    @endif
    @if (is_array($spark) && count($spark) >= 2)
        <x-ta.sparkline :values="$spark" :tone="$sparkTone" :width="200" :height="28" :fill="false" class="mt-auto block h-7 w-full pt-2" />
    @endif
</{{ $tag }}>
