@props([
    'variant' => 'light',
    'size' => 'md',
    'color' => 'primary',
])

@php
    $base = 'inline-flex items-center px-2.5 py-0.5 justify-center gap-1 rounded-full font-medium';

    $sizeStyles = [
        'sm' => 'text-xs',
        'md' => 'text-sm',
    ];

    $variants = [
        'light' => [
            'primary' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
            'success' => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            'error' => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
            'warning' => 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400',
            'info' => 'bg-blue-50 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400',
            'light' => 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-white/80',
            'dark' => 'bg-gray-500 text-white dark:bg-white/5 dark:text-white',
        ],
        'solid' => [
            'primary' => 'bg-brand-500 text-white',
            'success' => 'bg-success-500 text-white',
            'error' => 'bg-error-500 text-white',
            'warning' => 'bg-warning-500 text-white',
            'info' => 'bg-blue-500 text-white',
            'light' => 'bg-gray-400 text-white dark:bg-white/5 dark:text-white/80',
            'dark' => 'bg-gray-700 text-white',
        ],
    ];

    $sizeClass = $sizeStyles[$size] ?? $sizeStyles['md'];
    $colorStyles = $variants[$variant][$color] ?? $variants['light']['primary'];
@endphp

<span {{ $attributes->merge(['class' => "$base $sizeClass $colorStyles"]) }}>
    {{ $slot }}
</span>
