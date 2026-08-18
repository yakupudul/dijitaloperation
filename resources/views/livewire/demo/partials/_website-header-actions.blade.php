<div class="flex flex-wrap gap-2">
    <button type="button" wire:click="refreshData"
        class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600">
        Refresh data
    </button>
    <button type="button" wire:click="runDiagnosis"
        class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
        Run diagnosis
    </button>
    <a href="{{ route('operator.website.sources', ['assetId' => $this->assetId]) }}" wire:navigate
        class="inline-flex items-center justify-center rounded-lg border border-brand-200 bg-brand-50 px-3 py-2 text-sm font-semibold text-brand-700 hover:bg-brand-100 dark:border-brand-500/30 dark:bg-brand-500/10 dark:text-brand-300">
        Veri Kaynakları
    </a>
    <a href="{{ route('operator.website.discovery', ['assetId' => $this->assetId]) }}" wire:navigate
        class="inline-flex items-center justify-center rounded-lg bg-violet-600 px-3 py-2 text-sm font-semibold text-white hover:bg-violet-700">
        Kamu Keşif
    </a>
    <a href="{{ route('operator.activity', ['asset' => $this->assetId]) }}" wire:navigate
        class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
        {{ __('operator.website.actions.view_activity') }}
    </a>
    <details class="relative">
        <summary class="cursor-pointer list-none inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">
            More
        </summary>
        <div class="absolute right-0 z-20 mt-2 w-56 rounded-xl bg-white p-2 shadow-lg ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700">
            <button type="button" wire:click="refreshSeoIntelligence" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">Refresh SEO intelligence</button>
            <a href="{{ route('operator.website.discovery', ['assetId' => $this->assetId]) }}" wire:navigate class="block rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">Open Public Discovery</a>
            <button type="button" wire:click="generateAiGuidance" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">Generate AI guidance</button>
            <a href="{{ route('operator.files', ['scope' => 'digital_asset', 'asset' => $this->assetId]) }}" wire:navigate class="block rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">{{ __('operator.website.actions.open_files') }}</a>
            <button type="button" wire:click="setTab('setup')" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">{{ __('operator.website.tabs.setup') }}</button>
        </div>
    </details>
</div>
