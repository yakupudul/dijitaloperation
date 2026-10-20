@props([
    'tone' => 'look',
    'title' => '',
    'brand' => '',
    'meta' => null,
    'message' => null,
    'href' => null,
    'action' => 'Aç',
    'logo' => null,
    'hint' => null,
])

@php
    // Alarm row: one row of a `card` list (panel concept 2026-10-20): brand logo, title, one gray line, a severity dot
    // on the right; the whole row opens the account.
    $dot = match ($tone) {
        'critical' => 'bg-error-500',
        'ok' => 'bg-success-500',
        'info' => 'bg-gray-300 dark:bg-gray-600',
        default => 'bg-warning-500',
    };
@endphp

<li {{ $attributes->merge(['class' => 'relative flex items-start gap-3 border-t border-gray-200 px-4 py-3.5 first:border-t-0 dark:border-gray-800 '.($href ? 'hover:bg-gray-50 dark:hover:bg-white/[0.03]' : '')]) }}>
    <x-ta.brand-avatar :name="$brand" :logo="$logo" />
    <div class="min-w-0 flex-1">
        <p class="text-sm leading-7">
            <span class="font-medium text-gray-900 dark:text-white">{{ $title }}</span>
            <span class="text-gray-500">{{ $brand }}@if ($meta), {{ $meta }}@endif</span>
        </p>
        @if ($message)<p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400" @if ($hint && $hint !== $message) title="{{ $hint }}" @endif>{{ $message }}</p>@endif
        @if (! $slot->isEmpty())<div class="relative z-10 mt-1 text-xs">{{ $slot }}</div>@endif
    </div>
    <span class="mt-3 h-1.5 w-1.5 shrink-0 rounded-full {{ $dot }}" aria-hidden="true"></span>
    @if ($href)
        <a href="{{ $href }}" wire:navigate class="absolute inset-0" aria-label="{{ $action }}: {{ $title }}"></a>
    @endif
</li>
