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
    // Alarm row (Bugün, Onarım masası): 3 px severity stripe + brand avatar instead of a full red box per row.
    $stripe = match ($tone) {
        'critical' => 'before:bg-error-500',
        'ok' => 'before:bg-success-500',
        'info' => 'before:bg-gray-300 dark:before:bg-gray-600',
        default => 'before:bg-warning-500',
    };
@endphp

<li {{ $attributes->merge(['class' => "relative flex items-start gap-3 py-2.5 pl-4 pr-1 before:absolute before:left-0 before:top-2.5 before:bottom-2.5 before:w-[3px] before:rounded-full $stripe"]) }}>
    <x-ta.brand-avatar :name="$brand" size="sm" class="mt-0.5" />
    <div class="min-w-0 flex-1">
        <p class="flex flex-wrap items-baseline gap-x-2 text-sm">
            <span class="font-medium text-gray-900 dark:text-white">{{ $title }}</span>
            <span class="text-xs text-gray-500">{{ $brand }}@if ($meta) · {{ $meta }}@endif</span>
        </p>
        @if ($message)<p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $message }}</p>@endif
        @if (! $slot->isEmpty())<div class="mt-1 text-xs">{{ $slot }}</div>@endif
    </div>
    @if ($href)
        <a href="{{ $href }}" wire:navigate class="link-neutral shrink-0 text-xs">{{ $action }} →</a>
    @endif
</li>
