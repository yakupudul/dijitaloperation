<div class="flex flex-wrap items-center gap-2">
    <button type="button" wire:click="refreshData" class="{{ ($connected ?? true) ? 'btn-primary' : 'btn' }}">
        {{ __('operator_runtime.sources.refresh_data') }}
    </button>
    <button type="button" wire:click="runAnalysis" class="btn" title="{{ __('operator.workspace.analysis_requires_data') }}">
        {{ __('operator.workspace.run_analysis') }}
    </button>
    <details class="relative">
        <summary class="btn cursor-pointer list-none">
            {{ __('operator_runtime.sources.more') }}
        </summary>
        <div class="absolute right-0 z-20 mt-1 w-56 rounded-xl bg-white p-1.5 shadow-lg ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700">
            @if (filled($identity['website_asset_id'] ?? null))
                <a href="{{ route('operator.website', ['assetId' => $identity['website_asset_id']]) }}" wire:navigate class="block rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">{{ __('operator.chrome.open_website') }}</a>
            @endif
            <a href="{{ route('operator.asset.sources', ['assetId' => $assetId]) }}" wire:navigate class="block rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">{{ __('operator_asset.sources') }}</a>
        </div>
    </details>
</div>
