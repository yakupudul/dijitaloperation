@props([
    'name' => '',
    'logo' => null,
    'size' => 'md',
])

@php
    // One deterministic hue per brand name, so a brand looks the same on every screen (Bugün, Onarım masası, Markalar).
    $hues = ['bg-amber-100 text-amber-800', 'bg-live-100 text-live-800', 'bg-emerald-100 text-emerald-800', 'bg-rose-100 text-rose-800', 'bg-violet-100 text-violet-800', 'bg-teal-100 text-teal-800', 'bg-indigo-100 text-indigo-800', 'bg-lime-100 text-lime-800'];
    $hue = $hues[crc32((string) $name) % count($hues)];
    $initial = mb_strtoupper(mb_substr(trim((string) $name), 0, 1)) ?: '·';
    $dim = match ($size) { 'xs' => 'h-5 w-5 text-[10px]', 'sm' => 'h-6 w-6 text-[11px]', 'lg' => 'h-10 w-10 text-sm', default => 'h-7 w-7 text-xs' };
@endphp

@if ($logo)
    {{-- A logo URL that no longer loads falls back to the initial instead of a broken image. --}}
    <img src="{{ $logo }}" alt="" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex'" {{ $attributes->merge(['class' => "shrink-0 rounded-md object-contain bg-white ring-1 ring-gray-200 dark:ring-gray-700 $dim"]) }} />
    <span {{ $attributes->merge(['class' => "shrink-0 items-center justify-center rounded-md font-semibold $dim $hue dark:bg-white/10 dark:text-gray-200"]) }} style="display: none" aria-hidden="true">{{ $initial }}</span>
@else
    <span {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center justify-center rounded-md font-semibold $dim $hue dark:bg-white/10 dark:text-gray-200"]) }} aria-hidden="true">{{ $initial }}</span>
@endif
