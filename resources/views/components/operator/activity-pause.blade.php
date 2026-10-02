@props(['asset'])
{{-- Ad account pause toggle ("Duraklatıldı (müşteri kararı)") for Google Ads / Meta Ads asset headers. --}}
@if (in_array((string) $asset->type, ['google_ads', 'meta_ads'], true))
    <livewire:operator.activity-pause-toggle :asset-id="(string) $asset->id" :key="'activity-pause-'.$asset->id" />
@endif
