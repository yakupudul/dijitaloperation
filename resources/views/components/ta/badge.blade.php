@props([
    'variant' => 'light',
    'size' => 'md',
    'color' => 'info',
])

@php
    $base = 'inline-flex items-center px-2.5 py-0.5 justify-center gap-1 rounded-full font-medium';

    $sizeStyles = [
        'sm' => 'text-xs',
        'md' => 'text-sm',
    ];

    // Colour rules (panel-wide): orange is only the main action, so badges never use it. Red = money / traffic loss
    // (critical), amber = "bak" (look), gray = info, green = ok. The old names stay as aliases.
    $tones = [
        'light' => [
            'critical' => 'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400',
            'look' => 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400',
            'info' => 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-white/80',
            'ok' => 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-500',
            'dark' => 'bg-gray-500 text-white dark:bg-white/5 dark:text-white',
        ],
        'solid' => [
            'critical' => 'bg-error-500 text-white',
            'look' => 'bg-warning-500 text-white',
            'info' => 'bg-gray-500 text-white dark:bg-white/10 dark:text-white/80',
            'ok' => 'bg-success-500 text-white',
            'dark' => 'bg-gray-700 text-white',
        ],
    ];
    $aliases = ['error' => 'critical', 'danger' => 'critical', 'warning' => 'look', 'success' => 'ok', 'primary' => 'info', 'light' => 'info', 'neutral' => 'info'];
    $tone = $aliases[$color] ?? $color;

    $sizeClass = $sizeStyles[$size] ?? $sizeStyles['md'];
    $colorStyles = ($tones[$variant] ?? $tones['light'])[$tone] ?? $tones['light']['info'];
@endphp

<span {{ $attributes->merge(['class' => "$base $sizeClass $colorStyles"]) }}>
    {{ $slot }}
</span>
