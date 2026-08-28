<div>
    @include('livewire.demo.integrations.meta-integration')

    @if ($tab === 'resources')
        <div class="mt-6" wire:key="meta-ads-account-sync-control">
            @livewire(\App\Livewire\Demo\Integrations\MetaAdsAccountSyncControl::class, [], key('meta-ads-account-sync-control'))
        </div>
    @endif

    @if ($tab === 'connectors')
        <div class="mt-6 rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-800 ring-1 ring-inset ring-brand-200 dark:bg-brand-500/10 dark:text-brand-200 dark:ring-brand-500/20">
            <span class="font-semibold">Toplu güncelleme:</span>
            Bu sekmedeki “Şimdi Güncelle” aksiyonu markalara bağlanmış tüm uygun Meta Ads reklam hesaplarını birlikte günceller. Tek bir hesabı güncellemek için “Reklam Hesapları” sekmesindeki hesap bazlı güncelleme alanını kullanın.
        </div>
    @endif

    @if (in_array($tab, ['resources', 'connectors'], true))
        <div class="mt-6" wire:key="meta-ads-provider-live-monitor-{{ $tab }}">
            @livewire(\App\Livewire\Demo\Integrations\MetaAdsCollectionMonitor::class, [], key('meta-ads-provider-live-monitor-'.$tab))
        </div>
    @endif
</div>
