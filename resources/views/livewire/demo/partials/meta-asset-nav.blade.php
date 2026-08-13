@php
    use App\Support\Demo\DemoCatalog;

    $assetId = $assetId ?? DemoCatalog::META_ASSET_ID;
    $active = $active ?? 'overview';
    $tabs = [
        ['key' => 'overview', 'label' => __('operator.meta_ads.tabs.overview'), 'url' => route('demo.meta.overview', ['assetId' => $assetId, 'tab' => 'overview'])],
        ['key' => 'campaigns', 'label' => __('operator.meta_ads.tabs.campaigns'), 'url' => route('demo.meta.overview', ['assetId' => $assetId, 'tab' => 'campaigns'])],
        ['key' => 'creatives', 'label' => __('operator.meta_ads.tabs.creatives'), 'url' => route('demo.meta.overview', ['assetId' => $assetId, 'tab' => 'creatives'])],
        ['key' => 'audience', 'label' => __('operator.meta_ads.tabs.audience'), 'url' => route('demo.meta.overview', ['assetId' => $assetId, 'tab' => 'audience'])],
        ['key' => 'funnel', 'label' => __('operator.meta_ads.tabs.funnel'), 'url' => route('demo.meta.overview', ['assetId' => $assetId, 'tab' => 'funnel'])],
        ['key' => 'measurement', 'label' => __('operator.meta_ads.tabs.measurement'), 'url' => route('demo.meta.overview', ['assetId' => $assetId, 'tab' => 'measurement'])],
        ['key' => 'operations', 'label' => __('operator.meta_ads.tabs.operations'), 'url' => route('demo.meta.overview', ['assetId' => $assetId, 'tab' => 'operations'])],
    ];
@endphp

@include('livewire.demo.partials.asset-nav', ['tabs' => $tabs, 'active' => $active])
