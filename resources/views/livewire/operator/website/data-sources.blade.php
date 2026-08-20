<div class="space-y-6">
    <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 dark:border-gray-800 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('operator.website', ['assetId' => $asset->id]) }}" wire:navigate class="text-sm font-medium text-brand-600 hover:underline">Website</a>
                <span class="text-gray-300">/</span>
                <span class="text-sm text-gray-500">{{ __('operator.data_sources.title') }}</span>
            </div>
            <h1 class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{{ $asset->name }} · {{ __('operator.data_sources.title') }}</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">
                {{ __('operator.data_sources.intro') }}
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="collectNow" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600">{{ __('operator.data_sources.collect_bound') }}</button>
            <button type="button" wire:click="refreshSeoIntelligence" class="rounded-lg px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">{{ __('operator.data_sources.refresh_seo') }}</button>
            <a href="{{ route('operator.website.discovery', ['assetId' => $asset->id]) }}" wire:navigate class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700">{{ __('operator.website.actions.public_discovery') }}</a>
        </div>
    </div>

    @if ($message !== '')
        <div @class([
            'rounded-xl px-4 py-3 text-sm ring-1 ring-inset',
            'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20' => $messageTone === 'success',
            'bg-blue-50 text-blue-800 ring-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20' => $messageTone !== 'success',
        ])>{{ $message }}</div>
    @endif

    <section class="space-y-3">
        <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator.data_sources.direct_title') }}</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.data_sources.direct_hint') }}</p>
        </div>
        <div class="grid gap-4 lg:grid-cols-3">
            @foreach ($connections as $source)
                <article class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-gray-900 dark:text-white">{{ $source['label'] }}</h3>
                            <p class="mt-1 text-xs text-gray-500">{{ $source['subtitle'] ?? '—' }}</p>
                        </div>
                        <span @class([
                            'rounded-full px-2.5 py-1 text-xs font-medium',
                            'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $source['connected'],
                            'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => ! $source['connected'],
                        ])>{{ $source['connected'] ? __('operator.data_sources.connected') : __('operator.data_sources.not_connected') }}</span>
                    </div>

                    @if (! empty($source['display_name']))
                        <p class="mt-3 text-sm font-medium text-gray-800 dark:text-white/90">{{ $source['display_name'] }}</p>
                    @endif

                    <dl class="mt-4 space-y-2 text-xs">
                        <div class="flex justify-between gap-3"><dt class="text-gray-400">{{ __('operator.data_sources.last_data') }}</dt><dd class="text-right text-gray-600 dark:text-gray-300">{{ $source['last_sync_human'] ?? __('operator.data_sources.none_yet') }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-gray-400">{{ __('operator.data_sources.last_status') }}</dt><dd class="text-right text-gray-600 dark:text-gray-300">{{ $source['last_status'] ?? '—' }}</dd></div>
                    </dl>

                    @if ($source['key'] === 'ga4')
                        <div class="mt-4 space-y-2 border-t border-gray-100 pt-4 dark:border-gray-700">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('operator.data_sources.discovered_ga4') }}</label>
                            <select wire:model="ga4ResourceId" class="w-full rounded-lg border-gray-300 bg-white text-sm dark:border-gray-700 dark:bg-gray-900">
                                <option value="">{{ __('operator.data_sources.select_resource') }}</option>
                                @foreach ($ga4Resources as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('ga4ResourceId') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="bindGa4" class="rounded-lg bg-brand-500 px-3 py-2 text-xs font-semibold text-white hover:bg-brand-600">{{ $source['connected'] ? __('operator.data_sources.change_binding') : __('operator.data_sources.bind_to_website') }}</button>
                                @if ($source['connected'])
                                    <button type="button" wire:click="disableBinding('ga4')" class="rounded-lg px-3 py-2 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">{{ __('operator.data_sources.disable') }}</button>
                                @endif
                            </div>
                            @if ($ga4Resources === [])
                                <p class="text-xs text-gray-400">{{ __('operator.data_sources.no_other_ga4') }}</p>
                            @endif
                        </div>
                    @elseif ($source['key'] === 'search_console')
                        <div class="mt-4 space-y-2 border-t border-gray-100 pt-4 dark:border-gray-700">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('operator.data_sources.discovered_gsc') }}</label>
                            <select wire:model="searchConsoleResourceId" class="w-full rounded-lg border-gray-300 bg-white text-sm dark:border-gray-700 dark:bg-gray-900">
                                <option value="">{{ __('operator.data_sources.select_resource') }}</option>
                                @foreach ($gscResources as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('searchConsoleResourceId') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="bindSearchConsole" class="rounded-lg bg-brand-500 px-3 py-2 text-xs font-semibold text-white hover:bg-brand-600">{{ $source['connected'] ? __('operator.data_sources.change_binding') : __('operator.data_sources.bind_to_website') }}</button>
                                @if ($source['connected'])
                                    <button type="button" wire:click="disableBinding('search_console')" class="rounded-lg px-3 py-2 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">{{ __('operator.data_sources.disable') }}</button>
                                @endif
                            </div>
                            @if ($gscResources === [])
                                <p class="text-xs text-gray-400">{{ __('operator.data_sources.no_other_gsc') }}</p>
                            @endif
                        </div>
                    @elseif ($source['key'] === 'wordpress')
                        <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                            <a href="{{ route('operator.integrations.site-connectors') }}" wire:navigate class="text-sm font-medium text-brand-600 hover:underline">{{ __('operator.data_sources.manage_site_connector') }}</a>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    </section>

    <section class="space-y-3">
        <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator.data_sources.engines_title') }}</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.data_sources.engines_hint') }}</p>
        </div>
        <div class="grid gap-4 md:grid-cols-3">
            <article class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="flex items-center justify-between gap-3"><h3 class="font-semibold text-gray-900 dark:text-white">Google</h3><span class="text-xs font-medium text-gray-500">{{ $googleStatusLabel }}</span></div>
                <p class="mt-2 text-sm text-gray-500">{{ __('operator.data_sources.google_hint') }}</p>
                <a href="{{ route('operator.integrations.google') }}" wire:navigate class="mt-4 inline-flex text-sm font-medium text-brand-600 hover:underline">{{ __('operator.data_sources.open_google') }}</a>
            </article>
            <article class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="flex items-center justify-between gap-3"><h3 class="font-semibold text-gray-900 dark:text-white">Meta</h3><span class="text-xs font-medium text-gray-500">{{ $metaStatusLabel }}</span></div>
                <p class="mt-2 text-sm text-gray-500">{{ __('operator.data_sources.meta_hint') }}</p>
                <a href="{{ route('operator.integrations.meta') }}" wire:navigate class="mt-4 inline-flex text-sm font-medium text-brand-600 hover:underline">{{ __('operator.data_sources.open_meta') }}</a>
            </article>
            <article class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="flex items-center justify-between gap-3"><h3 class="font-semibold text-gray-900 dark:text-white">DataForSEO</h3><span class="text-xs font-medium {{ $dataForSeoConfigured ? 'text-emerald-600' : 'text-gray-500' }}">{{ $dataForSeoConfigured ? __('operator.data_sources.configured') : __('operator.data_sources.not_configured') }}</span></div>
                <p class="mt-2 text-sm text-gray-500">{{ __('operator.data_sources.dfs_market', ['location' => $asset->seo_market_location_name ?: __('operator.data_sources.not_configured'), 'language' => $asset->seo_market_language_name ?: __('operator.data_sources.not_configured')]) }}</p>
                @if ($dataForSeoConnectionStatus)
                    <p class="mt-2 text-xs text-gray-400">Connection: {{ $dataForSeoConnectionStatus }}</p>
                @endif
                <a href="{{ route('operator.integrations.dataforseo') }}" wire:navigate class="mt-4 inline-flex text-sm font-medium text-brand-600 hover:underline">{{ __('operator.data_sources.open_dfs') }}</a>
            </article>
        </div>
    </section>

    <section class="space-y-3">
        <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator.data_sources.related_title') }}</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.data_sources.related_hint') }}</p>
        </div>
        <div class="grid gap-3 lg:grid-cols-2">
            @forelse ($relatedAssets as $related)
                @php
                    $route = match ($related->type) {
                        'google_ads' => route('operator.google-ads.overview', ['assetId' => $related->id]),
                        'meta_ads' => route('operator.meta.overview', ['assetId' => $related->id]),
                        'google_business_profile', 'gbp' => route('operator.gbp', ['assetId' => $related->id]),
                        'ga4', 'google_analytics', 'analytics' => route('operator.analytics', ['assetId' => $related->id]),
                        'gsc', 'search_console', 'google_search_console' => route('operator.search-console', ['assetId' => $related->id]),
                        default => route('operator.assets'),
                    };
                @endphp
                <a href="{{ $route }}" wire:navigate class="flex items-center justify-between gap-4 rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">{{ $related->name }}</p>
                        <p class="mt-1 text-xs text-gray-500">{{ str($related->type)->replace('_', ' ')->title() }}</p>
                    </div>
                    <span class="text-sm font-medium text-brand-600">{{ __('operator.data_sources.open') }}</span>
                </a>
            @empty
                <div class="rounded-xl bg-gray-50 p-5 text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">{{ __('operator.data_sources.related_empty') }}</div>
            @endforelse
        </div>
    </section>
</div>
