@props([
    'size' => 'md',
    'variant' => 'primary',
    'href' => null,
    'disabled' => false,
])

@php
    $base = 'inline-flex shrink-0 items-center justify-center gap-1.5 whitespace-nowrap rounded-lg font-medium transition';

    // Sizes: 28 / 32 / 36 px tall (panel concept 2026-10-20: 32 px is the normal row button).
    $sizeMap = [
        'xs' => 'h-7 px-2.5 text-xs',
        'sm' => 'h-8 px-3 text-[13px]',
        'md' => 'h-9 px-4 text-sm',
    ];
    $sizeClass = $sizeMap[$size] ?? $sizeMap['md'];

    // The accent (primary) is the one main action of a screen, near-black (dark) a strong row action; others white.
    $white = 'border border-gray-300/80 bg-white text-gray-900 shadow-card hover:bg-gray-50 dark:border-gray-700 dark:bg-white/[0.04] dark:text-gray-200 dark:hover:bg-white/[0.08]';
    $variantMap = [
        'primary' => 'border border-transparent bg-accent font-semibold text-accent-ink shadow-card hover:brightness-95',
        'dark' => 'border border-gray-900 bg-gray-900 text-white shadow-card hover:bg-gray-700 dark:border-white dark:bg-white dark:text-gray-900 dark:hover:bg-gray-200',
        'outline' => $white,
        'secondary' => $white,
        'danger' => 'border border-error-600 bg-error-600 text-white shadow-card hover:bg-error-700',
        'ghost' => 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white',
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
