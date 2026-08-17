@php
    $assetId = $assetId ?? '';
    $active = $active ?? 'overview';
    $tabs = [
        ['key' => 'overview', 'label' => __('operator.meta_ads.tabs.overview'), 'url' => route('operator.meta.overview', ['assetId' => $assetId, 'tab' => 'overview'])],
        ['key' => 'campaigns', 'label' => __('operator.meta_ads.tabs.campaigns'), 'url' => route('operator.meta.overview', ['assetId' => $assetId, 'tab' => 'campaigns'])],
        ['key' => 'creatives', 'label' => __('operator.meta_ads.tabs.creatives'), 'url' => route('operator.meta.overview', ['assetId' => $assetId, 'tab' => 'creatives'])],
        ['key' => 'audience', 'label' => __('operator.meta_ads.tabs.audience'), 'url' => route('operator.meta.overview', ['assetId' => $assetId, 'tab' => 'audience'])],
        ['key' => 'funnel', 'label' => __('operator.meta_ads.tabs.funnel'), 'url' => route('operator.meta.overview', ['assetId' => $assetId, 'tab' => 'funnel'])],
        ['key' => 'measurement', 'label' => __('operator.meta_ads.tabs.measurement'), 'url' => route('operator.meta.overview', ['assetId' => $assetId, 'tab' => 'measurement'])],
        ['key' => 'operations', 'label' => __('operator.meta_ads.tabs.operations'), 'url' => route('operator.meta.overview', ['assetId' => $assetId, 'tab' => 'operations'])],
    ];
@endphp

@include('livewire.demo.partials.asset-nav', ['tabs' => $tabs, 'active' => $active])
