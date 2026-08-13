@props([
    'type' => 'ga4',
    'size' => 'md',
])

@php
    $sizeClass = match ($size) {
        'sm' => 'h-7 w-7 text-[10px]',
        'lg' => 'h-11 w-11 text-sm',
        default => 'h-8 w-8 text-xs',
    };

    $palette = match ($type) {
        'website' => 'bg-sky-500/10 text-sky-700 ring-sky-500/20 dark:text-sky-300',
        'google_ads', 'gads' => 'bg-amber-500/10 text-amber-800 ring-amber-500/20 dark:text-amber-300',
        'meta_ads', 'meta' => 'bg-blue-500/10 text-blue-700 ring-blue-500/20 dark:text-blue-300',
        'gbp' => 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/20 dark:text-emerald-300',
        'gsc' => 'bg-indigo-500/10 text-indigo-700 ring-indigo-500/20 dark:text-indigo-300',
        default => 'bg-orange-500/10 text-orange-800 ring-orange-500/20 dark:text-orange-300',
    };

    $label = match ($type) {
        'website' => 'WEB',
        'google_ads', 'gads' => 'GAds',
        'meta_ads', 'meta' => 'Meta',
        'gbp' => 'GBP',
        'gsc' => 'GSC',
        'ga4', 'analytics' => 'GA4',
        default => strtoupper(mb_substr((string) $type, 0, 3)),
    };
@endphp

<span {{ $attributes->class([
    'inline-flex shrink-0 items-center justify-center rounded-xl font-semibold ring-1 ring-inset',
    $sizeClass,
    $palette,
]) }} title="{{ $label }}" aria-hidden="true">{{ $label }}</span>
