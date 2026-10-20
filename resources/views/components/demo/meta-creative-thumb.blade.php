@props([
    'gradient' => 'slate',
    'name' => 'Creative',
])

@php
    // Sade panel: a quiet neutral placeholder, never a coloured gradient; `gradient` is kept for old callers.
    $gradientClass = 'bg-gray-100 dark:bg-white/[0.04]';
@endphp

<div
    {{ $attributes->merge([
        'class' => 'aspect-[4/5] w-full '.$gradientClass,
        'role' => 'img',
        'aria-label' => $name,
    ]) }}
></div>
