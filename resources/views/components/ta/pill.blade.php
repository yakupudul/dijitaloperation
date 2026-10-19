@props([
    'tone' => 'info',
    'dot' => true,
])

@php
    // Status pill with a dot: ok (green), look (amber), critical (red), live (violet, pulsing), info (gray). Orange is never a status.
    $classes = match ($tone) {
        'ok' => 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-400',
        'look' => 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400',
        'critical' => 'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400',
        'live' => 'bg-live-50 text-live-700 dark:bg-live-500/15 dark:text-live-300',
        default => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300',
    };
    $dotClass = match ($tone) {
        'ok' => 'bg-success-500',
        'look' => 'bg-warning-500',
        'critical' => 'bg-error-500',
        'live' => 'live-dot',
        default => 'bg-gray-400',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium whitespace-nowrap $classes"]) }}>
    @if ($dot)<span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dotClass }}" aria-hidden="true"></span>@endif
    {{ $slot }}
</span>
