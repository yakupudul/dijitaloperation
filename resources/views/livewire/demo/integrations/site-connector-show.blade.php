@php
    $tabs = [
        'overview' => __('operator.site_connectors.overview'),
        'releases' => __('operator.site_connectors.releases'),
        'install' => __('operator.site_connectors.install'),
        'connected' => __('operator.site_connectors.connected'),
        'activity' => __('operator.site_connectors.activity'),
    ];
@endphp

<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <x-demo.digital-asset-mark :type="$data['logo_type'] ?? 'website'" size="lg" />
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-400">{{ __('operator.site_connectors.title') }}</p>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $data['name'] }}</h1>
                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                    <x-ta.badge color="warning" size="sm">{{ $data['status_label'] }}</x-ta.badge>
                    <span>{{ __('operator.site_connectors.demo_badge') }}</span>
                </div>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $data['summary'] }}</p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="downloadDemoPackage" class="inline-flex rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white hover:bg-brand-600">
                {{ __('operator.site_connectors.download_demo') }}
            </button>
            <a href="{{ route('operator.integrations.site-connectors') }}" wire:navigate class="inline-flex rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">{{ __('operator.site_connectors.catalog') }}</a>
            <a href="{{ route('operator.integrations') }}" wire:navigate class="inline-flex rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">{{ __('operator.nav.integrations') }}</a>
        </div>
    </div>

    <nav class="flex flex-wrap gap-1 border-b border-gray-200 dark:border-gray-800" aria-label="Site connector sections">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')"
                @class([
                    'rounded-t-lg px-3 py-2 text-sm font-medium',
                    'border-b-2 border-brand-500 text-brand-700 dark:text-brand-400' => $tab === $key,
                    'text-gray-500 hover:text-gray-800 dark:hover:text-white/90' => $tab !== $key,
                ])>{{ $label }}</button>
        @endforeach
    </nav>

    @if ($tab === 'overview')
        <div class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Capabilities</h2>
                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                    @foreach ($data['capabilities'] as $cap)
                        <li>{{ $cap }}</li>
                    @endforeach
                </ul>
            </div>
            <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Requirements</h2>
                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                    @foreach ($data['requirements'] as $req)
                        <li>{{ $req }}</li>
                    @endforeach
                </ul>
                <p class="mt-4 text-xs font-medium text-amber-700 dark:text-amber-300">{{ $data['demo_boundary'] }}</p>
            </div>
        </div>
        <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Pairing (demo)</h2>
            <dl class="mt-3 grid gap-3 sm:grid-cols-3 text-sm">
                <div><dt class="text-gray-400">State</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['pairing']['state'] }}</dd></div>
                <div><dt class="text-gray-400">Code hint</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['pairing']['code_hint'] }}</dd></div>
                <div><dt class="text-gray-400">Expiry</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['pairing']['expires'] }}</dd></div>
            </dl>
            <p class="mt-3 text-sm text-gray-500">{{ $data['pairing']['note'] }}</p>
        </div>
    @endif

    @if ($tab === 'releases')
        <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <th class="px-4 py-3 text-left text-xs uppercase text-gray-400">Version</th>
                        <th class="px-4 py-3 text-left text-xs uppercase text-gray-400">Channel</th>
                        <th class="px-4 py-3 text-left text-xs uppercase text-gray-400">Released</th>
                        <th class="px-4 py-3 text-left text-xs uppercase text-gray-400">Notes</th>
                        <th class="px-4 py-3 text-right text-xs uppercase text-gray-400">{{ __('operator.actions.download') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($data['releases'] as $release)
                        <tr class="border-b border-gray-50 dark:border-gray-800/60">
                            <td class="px-4 py-3 font-medium text-gray-800 dark:text-white/90">{{ $release['version'] }}</td>
                            <td class="px-4 py-3"><x-ta.badge color="warning" size="sm">{{ $release['channel'] }}</x-ta.badge></td>
                            <td class="px-4 py-3 text-gray-500">{{ $release['released_at'] }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $release['notes'] }}</td>
                            <td class="px-4 py-3 text-right">
                                @if ($release['downloadable'])
                                    <button type="button" wire:click="downloadDemoPackage" class="text-sm font-medium text-brand-600 dark:text-brand-400">
                                        {{ __('operator.site_connectors.download_demo') }}
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-xs text-amber-700 dark:text-amber-300">{{ __('operator.site_connectors.demo_badge') }}</p>
    @endif

    @if ($tab === 'install')
        <ol class="space-y-3">
            @foreach ($data['install_steps'] as $step)
                <li class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Step {{ $step['step'] }}</p>
                    <h3 class="mt-1 text-sm font-semibold text-gray-800 dark:text-white/90">{{ $step['title'] }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $step['detail'] }}</p>
                </li>
            @endforeach
        </ol>
    @endif

    @if ($tab === 'connected')
        <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <th class="px-4 py-3 text-left text-xs uppercase text-gray-400">Site</th>
                        <th class="px-4 py-3 text-left text-xs uppercase text-gray-400">CMS</th>
                        <th class="px-4 py-3 text-left text-xs uppercase text-gray-400">Brand</th>
                        <th class="px-4 py-3 text-left text-xs uppercase text-gray-400">Pairing</th>
                        <th class="px-4 py-3 text-left text-xs uppercase text-gray-400">Last check</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($data['connected_sites'] as $site)
                        <tr class="border-b border-gray-50 dark:border-gray-800/60">
                            <td class="px-4 py-3">
                                <p class="font-medium text-gray-800 dark:text-white/90">{{ $site['site'] }}</p>
                                <a href="{{ route('operator.website', ['assetId' => $site['asset_id']]) }}" wire:navigate class="text-xs text-brand-600 dark:text-brand-400">{{ __('operator.chrome.open_website_asset') }}</a>
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $site['cms'] }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $site['brand'] }}</td>
                            <td class="px-4 py-3"><x-ta.badge :color="$site['pair_state'] === 'demo_paired' ? 'success' : 'warning'" size="sm">{{ $site['pair_label'] }}</x-ta.badge></td>
                            <td class="px-4 py-3 text-gray-500">{{ $site['last_check'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($tab === 'activity')
        <ul class="divide-y divide-gray-100 rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:divide-gray-800 dark:bg-gray-900 dark:ring-gray-800">
            @foreach ($data['activity'] as $row)
                <li class="px-4 py-3 text-sm">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="font-medium text-gray-800 dark:text-white/90">{{ $row['event'] }}</p>
                        <span class="text-xs text-gray-400">{{ $row['at'] }}</span>
                    </div>
                    <p class="text-xs text-gray-500">{{ $row['actor'] }} · {{ $row['detail'] }}</p>
                </li>
            @endforeach
        </ul>
    @endif
</div>
