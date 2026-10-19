@props([
    'size' => 'md',
    'variant' => 'primary',
    'href' => null,
    'disabled' => false,
])

@php
    $base = 'inline-flex items-center justify-center font-medium gap-2 rounded-lg transition';

    // Compact sizes (panel redesign 2026-10-19): 32 / 36 px tall, like the header controls.
    $sizeMap = [
        'xs' => 'h-7 px-2.5 text-xs',
        'sm' => 'h-8 px-3 text-sm',
        'md' => 'h-9 px-4 text-sm',
    ];
    $sizeClass = $sizeMap[$size] ?? $sizeMap['md'];

    // Orange (primary) is the one main action of a screen; everything else is outline / secondary.
    $variantMap = [
        'primary' => 'bg-brand-500 text-white shadow-theme-xs hover:bg-brand-600 disabled:bg-brand-300',
        'outline' => 'bg-white text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03] dark:hover:text-gray-300',
        'secondary' => 'bg-white text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03] dark:hover:text-gray-300',
        'danger' => 'bg-error-500 text-white shadow-theme-xs hover:bg-error-600 disabled:bg-error-300',
        'ghost' => 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5',
    ];
    $variantClass = $variantMap[$variant] ?? $variantMap['primary'];
    $disabledClass = $disabled ? 'cursor-not-allowed opacity-50' : '';
    $classes = trim("$base $sizeClass $variantClass $disabledClass");
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['class' => $classes, 'type' => $attributes->get('type', 'button')]) }} @if($disabled) disabled @endif>
        {{ $slot }}
    </button>
@endif
