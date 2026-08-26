<div>
    @include('livewire.demo.integrations.meta-integration')

    @if ($tab === 'connectors')
        <div class="mt-6" wire:key="meta-ads-provider-live-monitor">
            @livewire(\App\Livewire\Demo\Integrations\MetaAdsCollectionMonitor::class, [], key('meta-ads-provider-live-monitor'))
        </div>
    @endif
</div>
