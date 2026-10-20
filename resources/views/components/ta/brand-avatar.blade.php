@props([
    'name' => '',
    'logo' => null,
    'size' => 'md',
])

@php
    // The brand's own logo (taken from its website) on a white tile; without one a soft coloured initial, the same
    // colour for the same brand everywhere (yakup liked the initials, 2026-10-19; approved concept 2026-10-20).
    $tints = [
        'bg-[#eef3ff] text-[#3b5bdb]',
        'bg-[#fff1e6] text-[#c2560e]',
        'bg-[#eaf7ef] text-[#1d7a46]',
        'bg-[#f6ecff] text-[#7b3fc4]',
        'bg-[#fdeef3] text-[#b83266]',
        'bg-[#e9f6f8] text-[#137b8a]',
    ];
    $hue = $tints[crc32(mb_strtolower(trim((string) $name))) % count($tints)];
    $initial = mb_strtoupper(mb_substr(trim((string) $name), 0, 1)) ?: '·';
    $dim = match ($size) { 'xs' => 'h-5 w-5 rounded text-[11px]', 'sm' => 'h-6 w-6 rounded-md text-xs', 'lg' => 'h-10 w-10 rounded-lg text-sm', default => 'h-7 w-7 rounded-[7px] text-xs' };
@endphp

@if ($logo)
    {{-- A logo URL that no longer loads falls back to the initial instead of a broken image. --}}
    <img src="{{ $logo }}" alt="" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex'" {{ $attributes->merge(['class' => "shrink-0 border border-gray-200 bg-white object-contain dark:border-gray-700 $dim"]) }} />
    <span {{ $attributes->merge(['class' => "shrink-0 items-center justify-center font-semibold $dim $hue"]) }} style="display: none" aria-hidden="true">{{ $initial }}</span>
@else
    <span {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center justify-center font-semibold $dim $hue"]) }} aria-hidden="true">{{ $initial }}</span>
@endif
