@php
    $tabs = $tabs ?? [];
    $active = $active ?? 'overview';
@endphp

@include('livewire.demo.partials.asset-nav', ['tabs' => $tabs, 'active' => $active])
