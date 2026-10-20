<div class="space-y-10">
    @include('livewire.demo.integrations.meta-integration')

    @if ($tab === 'resources')
        <div wire:key="meta-ads-account-sync-control">
            @livewire(\App\Livewire\Demo\Integrations\MetaAdsAccountSyncControl::class, [], key('meta-ads-account-sync-control'))
        </div>
    @endif

    @if ($tab === 'connectors')
        <div class="card flex items-start gap-3.5 px-4 py-3.5">
            <span class="row-icon" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.5"/></svg></span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-gray-900 dark:text-white">Toplu güncelleme</p>
                <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">Bu sekmedeki “Şimdi Güncelle” markalara bağlanmış tüm uygun Meta Ads reklam hesaplarını birlikte günceller. Tek bir hesap için “Reklam Hesapları” sekmesindeki hesap bazlı güncellemeyi kullan.</p>
            </div>
        </div>
    @endif

    @if (in_array($tab, ['resources', 'connectors'], true))
        <div wire:key="meta-ads-provider-live-monitor-{{ $tab }}">
            @livewire(\App\Livewire\Demo\Integrations\MetaAdsCollectionMonitor::class, [], key('meta-ads-provider-live-monitor-'.$tab))
        </div>
    @endif

    <livewire:operator.integrations.resource-automations provider="meta" />
</div>
