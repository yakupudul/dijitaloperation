@props(['padding' => 'p-5 md:p-6'])

<div {{ $attributes->merge(['class' => "surface $padding"]) }}>
    {{ $slot }}
</div>
