@props(['padding' => ''])

{{-- White card with a hairline edge (panel concept 2026-10-20). Rows inside use `card-row`; `padding` for free content. --}}
<div {{ $attributes->merge(['class' => "card $padding"]) }}>
    {{ $slot }}
</div>
