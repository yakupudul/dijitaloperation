<div class="flex flex-wrap items-start gap-2">
    <livewire:demo.partials.data-sync-control
        :asset-id="(int) $assetId"
        :capabilities="['meta_ads']"
        :providers="['META_ADS']"
        :button-label="app()->getLocale() === 'tr' ? 'Şimdi Güncelle' : 'Update Now'"
        :title="app()->getLocale() === 'tr' ? 'Meta Ads Veri Güncelliği' : 'Meta Ads Data Freshness'"
        :compact="true"
        :key="'meta-data-sync-'.$assetId"
    />
    <button type="button" wire:click="runAnalysis" wire:loading.attr="disabled"
        class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-60 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
        {{ app()->getLocale() === 'tr' ? 'Analizi çalıştır' : 'Run analysis' }}
    </button>
    <details class="relative">
        <summary class="cursor-pointer list-none inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">
            {{ __('operator_runtime.sources.more') }}
        </summary>
        <div class="absolute right-0 z-20 mt-2 w-56 rounded-xl bg-white p-2 shadow-lg ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700">
            @if (filled($identity['website_asset_id'] ?? null))
                <a href="{{ route('operator.website', ['assetId' => $identity['website_asset_id']]) }}" wire:navigate class="block rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">{{ __('operator.chrome.open_website') }}</a>
            @endif
            <button type="button" wire:click="setTab('operations')" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">
                {{ app()->getLocale() === 'tr' ? 'Veri sağlığı ve değişiklikler' : 'Data health & changes' }}
            </button>
        </div>
    </details>
</div>