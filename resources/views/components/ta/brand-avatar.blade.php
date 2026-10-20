@props([
    'name' => '',
    'logo' => null,
    'size' => 'md',
])

@php
    // The brand's own logo (taken from its website); without one a quiet gray initial, never a pastel colour.
    $hue = 'bg-gray-100 text-gray-600';
    $initial = mb_strtoupper(mb_substr(trim((string) $name), 0, 1)) ?: '·';
    $dim = match ($size) { 'xs' => 'h-5 w-5 text-xs', 'sm' => 'h-6 w-6 text-xs', 'lg' => 'h-10 w-10 text-sm', default => 'h-7 w-7 text-xs' };
@endphp

@if ($logo)
    {{-- A logo URL that no longer loads falls back to the initial instead of a broken image. --}}
    <img src="{{ $logo }}" alt="" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex'" {{ $attributes->merge(['class' => "shrink-0 rounded-md object-contain bg-white ring-1 ring-gray-200 dark:ring-gray-700 $dim"]) }} />
    <span {{ $attributes->merge(['class' => "shrink-0 items-center justify-center rounded-md font-semibold $dim $hue dark:bg-white/10 dark:text-gray-200"]) }} style="display: none" aria-hidden="true">{{ $initial }}</span>
@else
    <span {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center justify-center rounded-md font-semibold $dim $hue dark:bg-white/10 dark:text-gray-200"]) }} aria-hidden="true">{{ $initial }}</span>
@endif
