@props([
    'value' => 0,
    'max' => 100,
    'tone' => 'primary',
    'label' => null,
])

@php
    $max = (float) $max > 0 ? (float) $max : 1;
    $pct = max(0, min(100, ($value / $max) * 100));
    $toneClass = match ($tone) {
        'success' => 'bg-success-500',
        'warning' => 'bg-warning-500',
        'error' => 'bg-error-500',
        default => 'bg-brand-500',
    };
@endphp

<div {{ $attributes }}>
    @if ($label)
        <div class="mb-1.5 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
            <span>{{ $label }}</span>
            <span>{{ round($pct) }}%</span>
        </div>
    @endif
    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
        <div class="h-full rounded-full {{ $toneClass }} transition-all duration-300" style="width: {{ $pct }}%"></div>
    </div>
</div>
