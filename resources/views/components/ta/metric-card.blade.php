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
    // Stat (sade panel 2026-10-20): big number, small label with the change as plain coloured text, no box around it.
    // A row of these is the page's summary strip. `tone` says what the change means, never which way the number moved.
    $toneClass = match ($tone) {
        'positive', 'success', 'ok' => 'text-success-700 dark:text-success-400',
        'negative', 'error', 'critical', 'bad' => 'text-error-700 dark:text-error-400',
        'warning', 'look' => 'text-warning-700 dark:text-warning-400',
        default => 'text-gray-500 dark:text-gray-400',
    };
    $sparkTone ??= 'neutral';
    $tag = $href ? 'a' : 'div';
    $classes = 'group flex min-w-0 flex-col gap-1 py-1 '.($href ? 'rounded-md transition hover:bg-gray-50 dark:hover:bg-white/[0.03] -mx-2 px-2' : '');
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @if (! str_starts_with($href, '#')) wire:navigate @endif @endif {{ $attributes->merge(['class' => $classes]) }}>
    <div class="flex items-end justify-between gap-3">
        <span @class(['tabular-nums tracking-tight', 'text-[26px] leading-8 font-semibold text-gray-900 dark:text-white' => ! $muted, 'text-lg leading-8 font-medium text-gray-400 dark:text-gray-500' => $muted])>{{ $value }}</span>
        @if (is_array($spark) && count($spark) >= 2)
            <x-ta.sparkline :values="$spark" :tone="$sparkTone" :width="64" :height="22" class="mb-1.5" />
        @endif
    </div>
    <p class="text-xs text-gray-500 dark:text-gray-400">
        <span>{{ $label }}</span>
        @if ($delta !== null && $delta !== '')
            <span class="ml-1 whitespace-nowrap tabular-nums {{ $toneClass }}">{{ $delta }}</span>
        @endif
    </p>
    @if ($hint !== null && $hint !== '')
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
    @endif
    @if (! $slot->isEmpty())
        <div class="text-xs">{{ $slot }}</div>
    @endif
</{{ $tag }}>
