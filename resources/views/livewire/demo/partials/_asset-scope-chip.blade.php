@props([
    'assetType' => null,
])

@php
    $type = is_string($assetType) ? $assetType : null;
@endphp

@if ($type === 'instagram')
    <p class="text-xs text-amber-700 dark:text-amber-300">
        <span class="font-medium">{{ __('operator.commercial.outside_scope') }}</span>
    </p>
@endif
