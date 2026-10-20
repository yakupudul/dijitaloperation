@props([
    'variant' => 'light',
    'size' => 'md',
    'color' => 'info',
])

@php
    // Sade panel 2026-10-20: a light badge is plain coloured text, never a filled chip; solid stays for counters.
    $base = $variant === 'solid' ? 'inline-flex items-center px-2 py-0.5 justify-center gap-1 rounded-md font-medium' : 'inline-flex items-center gap-1 font-medium';

    $sizeStyles = [
        'sm' => 'text-xs',
        'md' => 'text-sm',
    ];

    // Colour rules (panel-wide): orange is only the main action, so badges never use it. Red = money / traffic loss
    // (critical), amber = "bak" (look), gray = info, green = ok. The old names stay as aliases.
    $tones = [
        'light' => [
            'critical' => 'text-error-700 dark:text-error-400',
            'look' => 'text-warning-700 dark:text-warning-400',
            'info' => 'text-gray-500 dark:text-gray-400',
            'ok' => 'text-success-700 dark:text-success-500',
            'dark' => 'text-gray-900 dark:text-white',
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
