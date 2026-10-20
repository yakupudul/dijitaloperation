@props([
    'tone' => 'look',
    'title' => '',
    'brand' => '',
    'meta' => null,
    'message' => null,
    'href' => null,
    'action' => 'Aç',
])

@php
    // Alarm row (sade panel 2026-10-20): brand mark, title, one quiet line, a severity dot on the right; the whole row
    // opens the account. No coloured stripe, no "Aç →" link.
    $dot = match ($tone) {
        'critical' => 'bg-error-500',
        'ok' => 'bg-success-500',
        'info' => 'bg-gray-300 dark:bg-gray-600',
        default => 'bg-warning-500',
    };
@endphp

<li {{ $attributes->merge(['class' => 'relative flex items-start gap-3 py-2.5 '.($href ? 'hover:bg-gray-50 dark:hover:bg-white/[0.03] -mx-2 px-2 rounded-md' : '')]) }}>
    <x-ta.brand-avatar :name="$brand" size="sm" class="mt-0.5" />
    <div class="min-w-0 flex-1">
        <p class="text-sm">
            <span class="font-medium text-gray-900 dark:text-white">{{ $title }}</span>
            <span class="text-gray-500">{{ $brand }}@if ($meta), {{ $meta }}@endif</span>
        </p>
        @if ($message)<p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $message }}</p>@endif
        @if (! $slot->isEmpty())<div class="relative z-10 mt-1 text-xs">{{ $slot }}</div>@endif
    </div>
    <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full {{ $dot }}" aria-hidden="true"></span>
    @if ($href)
        <a href="{{ $href }}" wire:navigate class="absolute inset-0" aria-label="{{ $action }}: {{ $title }}"></a>
    @endif
</li>
