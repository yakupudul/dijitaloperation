@props([
    'tone' => 'info',
    'dot' => true,
])

@php
    // Status (sade panel 2026-10-20): a small coloured dot and plain text, no filled pill. Red / amber text only where
    // something is lost or needs a look; everything else reads in the normal gray.
    $text = match ($tone) {
        'critical' => 'text-error-700 dark:text-error-400',
        'look' => 'text-warning-800 dark:text-warning-400',
        default => 'text-gray-600 dark:text-gray-300',
    };
    $dotClass = match ($tone) {
        'ok' => 'bg-success-500',
        'look' => 'bg-warning-500',
        'critical' => 'bg-error-500',
        'live' => 'live-dot',
        default => 'bg-gray-400',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 text-xs tracking-normal whitespace-nowrap $text"]) }}>
    @if ($dot)<span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dotClass }}" aria-hidden="true"></span>@endif
    {{ $slot }}
</span>
