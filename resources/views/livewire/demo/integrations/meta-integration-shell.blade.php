<div>
    <livewire:operator.integrations.resource-automations provider="meta" />
    @include('livewire.demo.integrations.meta-integration')

    @if ($tab === 'resources')
        <div class="mt-10" wire:key="meta-ads-account-sync-control">
            @livewire(\App\Livewire\Demo\Integrations\MetaAdsAccountSyncControl::class, [], key('meta-ads-account-sync-control'))
        </div>
    @endif

    @if ($tab === 'connectors')
        <div class="mt-6 rounded-lg px-4 py-3 text-sm tone-info">
            <span class="font-semibold">Toplu güncelleme:</span>
            Bu sekmedeki “Şimdi Güncelle” aksiyonu markalara bağlanmış tüm uygun Meta Ads reklam hesaplarını birlikte günceller. Tek bir hesabı güncellemek için “Reklam Hesapları” sekmesindeki hesap bazlı güncelleme alanını kullanın.
        </div>
    @endif

    @if (in_array($tab, ['resources', 'connectors'], true))
        <div class="mt-10" wire:key="meta-ads-provider-live-monitor-{{ $tab }}">
            @livewire(\App\Livewire\Demo\Integrations\MetaAdsCollectionMonitor::class, [], key('meta-ads-provider-live-monitor-'.$tab))
        </div>
    @endif
</div>