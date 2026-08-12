@props([
    'variant' => 'info',
    'title' => '',
    'message' => '',
])

@php
    $variantClasses = [
        'success' => 'border-success-500 bg-success-50 dark:border-success-500/30 dark:bg-success-500/15',
        'error' => 'border-error-500 bg-error-50 dark:border-error-500/30 dark:bg-error-500/15',
        'warning' => 'border-warning-500 bg-warning-50 dark:border-warning-500/30 dark:bg-warning-500/15',
        'info' => 'border-brand-500 bg-brand-50 dark:border-brand-500/30 dark:bg-brand-500/15',
    ];
    $iconColor = [
        'success' => 'text-success-500',
        'error' => 'text-error-500',
        'warning' => 'text-warning-500',
        'info' => 'text-brand-500',
    ];
    $container = $variantClasses[$variant] ?? $variantClasses['info'];
    $icon = $iconColor[$variant] ?? $iconColor['info'];
@endphp

<div class="rounded-xl border p-4 {{ $container }}">
    <div class="flex items-start gap-3">
        <div class="-mt-0.5 {{ $icon }}">
            <svg class="fill-current" width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path fill-rule="evenodd" clip-rule="evenodd" d="M3.65 12A8.35 8.35 0 1 1 20.35 12 8.35 8.35 0 0 1 3.65 12ZM12 1.85A10.15 10.15 0 1 0 22.15 12 10.15 10.15 0 0 0 12 1.85Zm-1 5.68a1 1 0 1 0 2 0 1 1 0 0 0-2 0Zm1 3.66a.75.75 0 0 1 .75.75v5.68a.75.75 0 0 1-1.5 0v-5.68a.75.75 0 0 1 .75-.75Z"/>
            </svg>
        </div>
        <div class="flex-1">
            @if ($title)
                <h4 class="mb-1 text-sm font-semibold text-gray-800 dark:text-white/90">{{ $title }}</h4>
            @endif
            @if ($message)
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $message }}</p>
            @endif
            {{ $slot }}
        </div>
    </div>
</div>
