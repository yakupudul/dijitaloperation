@props([
    'assetType' => null,
])

@php
    $type = is_string($assetType) ? $assetType : null;
@endphp

@if ($type === 'instagram')
    <p class="text-xs text-amber-700 dark:text-amber-300">
        <span class="font-medium">{{ __('operator.commercial.outside_scope') }}</span>
        {{-- Faz 14: no Instagram data model; state it plainly. --}}
        <span>· {{ app()->getLocale() === 'tr' ? 'Otomatik veri çekimi yok — yalnız elle takip' : 'No automatic collection — manual only' }}</span>
    </p>
@endif
