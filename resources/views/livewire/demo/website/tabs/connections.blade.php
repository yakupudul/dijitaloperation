@php $conn = $data['connections']; @endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Connections</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Data sources and related digital assets that help MoxDOP understand this Website.</p>
        <p class="mt-2 text-xs text-gray-400">{{ $conn['note'] }}</p>
    </div>

    <section>
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Website data sources</h3>
        <div class="mt-3 grid gap-3 lg:grid-cols-3">
            @foreach ($conn['data_sources'] as $source)
                <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $source['name'] }}</p>
                        <span class="text-xs text-emerald-600 dark:text-emerald-400">{{ $source['state'] }}</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">{{ $source['detail'] }}</p>
                    <p class="mt-2 text-xs text-gray-400">Last successful collection · {{ $source['last'] }}</p>
                    <p class="mt-2 text-xs text-gray-500">Provides · {{ implode(', ', $source['provides']) }}</p>
                    @if (! empty($source['route']))
                        <a href="{{ route($source['route']) }}" wire:navigate class="mt-3 inline-flex text-xs font-medium text-brand-600 hover:underline">{{ $source['action'] }}</a>
                    @else
                        <p class="mt-3 text-xs text-gray-400">{{ $source['action'] }} · {{ $source['action_note'] ?? '' }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    <section>
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Deep Website connection</h3>
        <p class="mt-1 text-xs text-gray-400">Site Connectors pair a CMS-managed Website with MoxDOP. This is not a MoxDOP runtime plugin marketplace.</p>
        <div class="mt-3 rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">CMS detected · WordPress</p>
                    <p class="mt-1 text-xs text-gray-500">Recommended: MoxDOP WordPress Connector</p>
                    <p class="mt-2 text-xs text-emerald-700 dark:text-emerald-300">{{ __('operator.site_connectors.production_badge') }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-ta.button :href="route('operator.integrations.site-connector', ['connector' => 'wordpress'])" size="sm">{{ __('operator.actions.open') }}</x-ta.button>
                    <x-ta.button :href="route('operator.integrations.site-connector.download', ['connector' => 'wordpress'])" size="sm" variant="outline">{{ __('operator.site_connectors.download_package') }}</x-ta.button>
                </div>
            </div>
        </div>
    </section>

    <section>
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Related digital assets</h3>
        <p class="mt-1 text-xs text-gray-400">Independent Brand Digital Assets — not Website connections.</p>
        <div class="mt-3 grid gap-3 lg:grid-cols-3">
            @foreach ($conn['related_assets'] as $asset)
                <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $asset['name'] }}</p>
                    <p class="mt-1 text-xs text-gray-500">{{ $asset['detail'] }}</p>
                    <p class="mt-2 text-xs text-gray-400">{{ $asset['note'] }}</p>
                    <a href="{{ $asset['url'] ?? \App\Services\Operator\OperatorPortfolioPresenter::specialistHref($asset) }}" wire:navigate class="mt-3 inline-flex text-xs font-medium text-brand-600 hover:underline">{{ __('operator.actions.open') }} {{ $asset['name'] }}</a>
                </div>
            @endforeach
        </div>
    </section>
</div>
