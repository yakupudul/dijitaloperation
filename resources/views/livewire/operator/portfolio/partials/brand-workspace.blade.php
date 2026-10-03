{{-- Channel tab (Arama · Harita · Google Ads · Meta): rendered only once the channel's own component exists. --}}
<section aria-label="{{ $tabs[$tab] ?? $tab }}" data-workspace-tab="{{ $tab }}">
    @livewire($channelComponent, ['brandId' => $brandModel->id], key('workspace-'.$tab.'-'.$brandModel->id))
</section>
