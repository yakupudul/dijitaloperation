@php
    use App\Support\Demo\DemoCatalog;

    $assetId = $assetId ?? DemoCatalog::META_ASSET_ID;
    $active = $active ?? 'overview';
    $tabs = [
        ['key' => 'overview', 'label' => 'Overview', 'url' => route('demo.meta.overview', ['assetId' => $assetId, 'tab' => 'overview'])],
        ['key' => 'campaigns', 'label' => 'Campaigns', 'url' => route('demo.meta.overview', ['assetId' => $assetId, 'tab' => 'campaigns'])],
        ['key' => 'creatives', 'label' => 'Creatives', 'url' => route('demo.meta.overview', ['assetId' => $assetId, 'tab' => 'creatives'])],
        ['key' => 'audience', 'label' => 'Audience & Delivery', 'url' => route('demo.meta.overview', ['assetId' => $assetId, 'tab' => 'audience'])],
        ['key' => 'funnel', 'label' => 'Funnel & Destinations', 'url' => route('demo.meta.overview', ['assetId' => $assetId, 'tab' => 'funnel'])],
        ['key' => 'measurement', 'label' => 'Measurement', 'url' => route('demo.meta.overview', ['assetId' => $assetId, 'tab' => 'measurement'])],
        ['key' => 'operations', 'label' => 'Operations', 'url' => route('demo.meta.overview', ['assetId' => $assetId, 'tab' => 'operations'])],
    ];
@endphp

@include('livewire.demo.partials.asset-nav', ['tabs' => $tabs, 'active' => $active])
