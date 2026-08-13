@props([
    'service' => null,
    'goal' => null,
    'offering' => null,
])

@if ($service || $goal || $offering)
    <div {{ $attributes->merge(['class' => 'flex flex-wrap gap-2']) }}>
        @if ($service)
            <span class="inline-flex items-center gap-1 rounded-md bg-brand-50 px-2 py-1 text-xs font-medium text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                <span class="text-gray-500 dark:text-gray-400">{{ __('operator.commercial.service') }}:</span>
                {{ $service }}
            </span>
        @endif
        @if ($goal)
            <span class="inline-flex items-center gap-1 rounded-md bg-violet-50 px-2 py-1 text-xs font-medium text-violet-700 dark:bg-violet-500/10 dark:text-violet-300">
                <span class="text-gray-500 dark:text-gray-400">{{ __('operator.commercial.goal') }}:</span>
                {{ $goal }}
            </span>
        @endif
        @if ($offering)
            <span class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                <span class="text-gray-500 dark:text-gray-400">{{ __('operator.commercial.offering') }}:</span>
                {{ $offering }}
            </span>
        @endif
    </div>
@endif
