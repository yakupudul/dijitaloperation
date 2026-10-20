@props(['padding' => ''])

{{-- Sade panel (2026-10-20): a page section is flat (no ring, no fill); the padding prop is kept for old callers. --}}
<div {{ $attributes->merge(['class' => "min-w-0 $padding"]) }}>
    {{ $slot }}
</div>
