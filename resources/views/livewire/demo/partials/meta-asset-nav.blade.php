@php
    use App\Support\Demo\DemoCatalog;

    $assetId = $assetId ?? DemoCatalog::META_ASSET_ID;
    $active = $active ?? 'overview';
    $tabs = [
        ['key' => 'overview', 'label' => 'Overview', 'url' => route('demo.meta.overview', ['assetId' => $assetId])],
        ['key' => 'campaigns', 'label' => 'Campaigns', 'url' => route('demo.meta.campaigns', ['assetId' => $assetId])],
        ['key' => 'adsets', 'label' => 'Ad Sets', 'url' => route('demo.meta.adsets', ['assetId' => $assetId])],
        ['key' => 'ads', 'label' => 'Ads', 'url' => route('demo.meta.ads', ['assetId' => $assetId])],
        ['key' => 'creatives', 'label' => 'Creatives', 'url' => route('demo.meta.creatives', ['assetId' => $assetId])],
        ['key' => 'breakdowns', 'label' => 'Breakdowns', 'url' => route('demo.meta.breakdowns', ['assetId' => $assetId])],
        ['key' => 'insights', 'label' => 'Insights', 'url' => route('demo.meta.insights', ['assetId' => $assetId])],
    ];
@endphp

@include('livewire.demo.partials.asset-nav', ['tabs' => $tabs, 'active' => $active])
