<div class="flex flex-wrap items-start gap-2">
    <livewire:demo.partials.data-sync-control
        :asset-id="(int) $assetId"
        :capabilities="['google_ads']"
        :providers="['GOOGLE_ADS']"
        :button-label="app()->getLocale() === 'tr' ? 'Şimdi Güncelle' : 'Update Now'"
        :title="app()->getLocale() === 'tr' ? 'Google Ads Veri Güncelliği' : 'Google Ads Data Freshness'"
        :compact="true"
        :key="'google-ads-data-sync-'.$assetId"
    />
    <button type="button" wire:click="runAnalysis"
        class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]"
        title="{{ __('operator_runtime.sources.analysis_requires_data') }}">
        {{ __('operator_runtime.sources.run_analysis') }}
    </button>
    <a href="{{ route('operator.asset.sources', ['assetId' => $assetId]) }}" wire:navigate
        class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold text-brand-700 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:text-brand-300 dark:ring-brand-500/30">
        {{ __('operator_runtime.sources.title') }}
    </a>
    <details class="relative">
        <summary class="cursor-pointer list-none inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">
            {{ __('operator_runtime.sources.more') }}
        </summary>
        <div class="absolute right-0 z-20 mt-2 w-56 rounded-xl bg-white p-2 shadow-lg ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700">
            <a href="{{ route('operator.asset.create', ['brandId' => $identity['brand_id']]) }}" wire:navigate class="block rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">{{ __('operator_runtime.sources.edit_asset') }}</a>
            <a href="{{ route('operator.brand', ['brand' => $identity['brand_id']]) }}" wire:navigate class="block rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">{{ __('operator_runtime.sources.open_brand') }}</a>
            @if (filled($identity['website_asset_id'] ?? null))
                <a href="{{ route('operator.website', ['assetId' => $identity['website_asset_id']]) }}" wire:navigate class="block rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">{{ __('operator.chrome.open_website') }}</a>
            @endif
            <button type="button" wire:click="setTab('data_connection')" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">{{ __('operator.workspace.manage_connection') }}</button>
        </div>
    </details>
</div>