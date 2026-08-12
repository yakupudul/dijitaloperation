@props([
    'title' => null,
    'subtitle' => null,
    'options' => null,
    'chartId' => null,
])

@php
    $chartId ??= 'chart-'.uniqid();
@endphp

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6']) }}>
    @if ($title || $subtitle || isset($actions))
        <div class="mb-4 flex items-start justify-between gap-3">
            <div>
                @if ($title)
                    <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $title }}</h3>
                @endif
                @if ($subtitle)
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    @if ($options)
        {{-- operator.js renders any element carrying data-chart via ApexCharts. --}}
        <div id="{{ $chartId }}" wire:ignore data-chart='{!! json_encode($options, JSON_HEX_APOS | JSON_HEX_QUOT) !!}'></div>
    @else
        {{ $slot }}
    @endif
</div>
