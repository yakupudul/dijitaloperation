@props([
    'label' => '',
    'value' => '—',
    'delta' => null,
    'tone' => 'neutral',
    'icon' => null,
])

@php
    // Delta chip tone, adapted from TailAdmin ecommerce-metrics up/down chips.
    $toneClass = match ($tone) {
        'positive' => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
        'negative' => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
        default => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300',
    };
@endphp

<div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
    <div class="flex items-center justify-center w-11 h-11 bg-brand-50 rounded-xl text-brand-600 dark:bg-brand-500/15 dark:text-brand-400">
        @if ($icon)
            {!! $icon !!}
        @else
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 9l-5 5-3-3-4 4"/></svg>
        @endif
    </div>

    <div class="flex items-end justify-between mt-5">
        <div>
            <span class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</span>
            <h4 class="mt-2 font-bold text-gray-800 text-title-sm dark:text-white/90">{{ $value }}</h4>
        </div>

        @if ($delta !== null && $delta !== '')
            <span class="flex items-center gap-1 rounded-full py-0.5 pl-2 pr-2.5 text-sm font-medium {{ $toneClass }}">
                {{ $delta }}
            </span>
        @endif
    </div>
</div>
