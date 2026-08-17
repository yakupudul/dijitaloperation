<div class="flex flex-wrap gap-2">
    <button type="button" wire:click="refreshData"
        class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600">
        Refresh data
    </button>
    <button type="button" wire:click="runDiagnosis"
        class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
        Run diagnosis
    </button>
    <a href="{{ route('demo.activity', ['asset' => $this->assetId]) }}" wire:navigate
        class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
        {{ __('operator.website.actions.view_activity') }}
    </a>
    <a href="{{ route('demo.files', ['scope' => 'digital_asset', 'asset' => $this->assetId]) }}" wire:navigate
        class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.03]">
        {{ __('operator.website.actions.open_files') }}
    </a>
    <details class="relative">
        <summary class="cursor-pointer list-none inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">
            More
        </summary>
        <div class="absolute right-0 z-20 mt-2 w-56 rounded-xl bg-white p-2 shadow-lg ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700">
            <button type="button" wire:click="setTab('visibility')" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">Refresh SEO intelligence</button>
            <a href="{{ route('demo.brand', ['brand' => $identity['brand_id'], 'tab' => 'business', 'business' => 'discovery']) }}" wire:navigate class="block rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">Discover public context</a>
            <button type="button" wire:click="setTab('overview')" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">Generate AI guidance</button>
            <a href="{{ route('demo.asset.create', ['brandId' => $identity['brand_id']]) }}" wire:navigate class="block rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">Edit Website</a>
            <button type="button" wire:click="setTab('setup')" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">{{ __('operator.website.tabs.setup') }}</button>
        </div>
    </details>
</div>
