<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Portfolio',
        'title' => 'Digital Assets',
        'subtitle' => 'Digital Estate Directory — inventory, ownership, operational state, and data freshness.',
        'actions' => '<div class="flex flex-wrap gap-2"><a href="'.route('operator.setup', ['entry' => 'asset']).'" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">Setup wizard</a><a href="'.route('operator.asset.create').'" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Quick add asset</a></div>',
    ])

    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs uppercase tracking-wide text-gray-400">Managed Assets</p>
            <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $glance['managed'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs uppercase tracking-wide text-gray-400">Needs Attention</p>
            <p class="mt-1 text-2xl font-bold text-warning-600 dark:text-warning-400">{{ $glance['needs_attention'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs uppercase tracking-wide text-gray-400">Data Stale / Unavailable</p>
            <p class="mt-1 text-2xl font-bold text-error-600 dark:text-error-400">{{ $glance['data_issues'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs uppercase tracking-wide text-gray-400">Assets with Active Work</p>
            <p class="mt-1 text-2xl font-bold text-brand-600 dark:text-brand-400">{{ $glance['active_work'] }}</p>
        </div>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-2" role="tablist" aria-label="Digital Asset quick views">
            @foreach ([
                'all' => 'All',
                'needs_attention' => 'Needs Attention',
                'data_issues' => 'Data Issues',
                'active_work' => 'Active Work',
                'recent' => 'Recently Updated',
            ] as $key => $label)
                <button type="button" wire:click="setQuickView('{{ $key }}')"
                    @class([
                        'rounded-lg px-3 py-2 text-sm font-medium',
                        'bg-brand-500 text-white' => $quickView === $key,
                        'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $quickView !== $key,
                    ])>{{ $label }}</button>
            @endforeach
        </div>
        <div class="flex rounded-lg ring-1 ring-inset ring-gray-200 dark:ring-gray-700" role="group" aria-label="Directory view">
            <button type="button" wire:click="setViewMode('table')"
                @class(['rounded-l-lg px-3 py-2 text-xs font-medium', 'bg-brand-500 text-white' => $viewMode === 'table', 'text-gray-600 dark:text-gray-300' => $viewMode !== 'table'])>List</button>
            <button type="button" wire:click="setViewMode('matrix')"
                @class(['px-3 py-2 text-xs font-medium', 'bg-brand-500 text-white' => $viewMode === 'matrix', 'text-gray-600 dark:text-gray-300' => $viewMode !== 'matrix'])>Estate Matrix</button>
            <button type="button" wire:click="setViewMode('cards')"
                @class(['rounded-r-lg px-3 py-2 text-xs font-medium', 'bg-brand-500 text-white' => $viewMode === 'cards', 'text-gray-600 dark:text-gray-300' => $viewMode !== 'cards'])>Cards</button>
        </div>
    </div>

    <div class="flex flex-wrap items-end gap-3 rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500" for="filter-customer">Customer</label>
            <select id="filter-customer" wire:model.live="filterCustomer" class="rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700">
                <option value="">All customers</option>
                @foreach ($customerOptions as $id => $label)
                    <option value="{{ $id }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500" for="filter-brand">Brand</label>
            <select id="filter-brand" wire:model.live="filterBrand" class="rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700">
                <option value="">All brands</option>
                @foreach ($brandOptions as $id => $label)
                    <option value="{{ $id }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500" for="filter-type">Asset Type</label>
            <select id="filter-type" wire:model.live="filterType" class="rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700">
                <option value="">All types</option>
                @foreach ($typeOptions as $id => $label)
                    <option value="{{ $id }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500" for="filter-op">Operational Status</label>
            <select id="filter-op" wire:model.live="filterOperational" class="rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700">
                <option value="">All</option>
                @foreach ($operationalOptions as $id => $label)
                    <option value="{{ $id }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500" for="filter-data">Data State</label>
            <select id="filter-data" wire:model.live="filterDataState" class="rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700">
                <option value="">All</option>
                @foreach ($dataStateOptions as $id => $label)
                    <option value="{{ $id }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500" for="filter-owner">Responsible</label>
            <select id="filter-owner" wire:model.live="filterResponsible" class="rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700">
                <option value="">Anyone</option>
                @foreach ($responsibleOptions as $id => $label)
                    <option value="{{ $id }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500" for="filter-search">Search</label>
            <input id="filter-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Name or type"
                class="rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700" />
        </div>
        <x-ta.button wire:click="clearFilters" size="sm" variant="outline">Clear</x-ta.button>
    </div>

    @if ($viewMode === 'matrix')
        <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <table class="min-w-full text-sm">
                <caption class="sr-only">Digital Estate Matrix — Brand rows by asset type. Missing asset does not mean error.</caption>
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-400">Brand</th>
                        @foreach ($matrix['columns'] as $type => $label)
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-400">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($matrix['rows'] as $row)
                        <tr class="border-b border-gray-50 dark:border-gray-800/60">
                            <th scope="row" class="px-4 py-3 text-left font-medium text-gray-800 dark:text-white/90">
                                {{ $row['brand'] }}
                                <span class="block text-xs font-normal text-gray-500">{{ $row['customer'] }}</span>
                            </th>
                            @foreach ($matrix['columns'] as $type => $label)
                                @php $cell = $row['cells'][$type] ?? ['state' => 'not_configured', 'label' => 'Not configured']; @endphp
                                <td class="px-4 py-3">
                                    @if (($cell['state'] ?? '') === 'not_configured')
                                        <span class="text-gray-400">—</span>
                                        <span class="sr-only">Not configured</span>
                                    @else
                                        <a href="{{ route($cell['route']) }}" wire:navigate
                                            @class([
                                                'inline-flex rounded-md px-2 py-1 text-xs font-medium',
                                                'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' => ($cell['state'] ?? '') === 'present',
                                                'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400' => ($cell['state'] ?? '') === 'attention',
                                                'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-400' => ($cell['state'] ?? '') === 'data_issue',
                                            ])>{{ $cell['label'] }}</a>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="border-t border-gray-100 px-4 py-3 text-xs text-gray-500 dark:border-gray-800">
                Missing asset types are “Not configured”, not errors. A Brand is not required to use every platform.
            </p>
        </div>
    @elseif (count($assets) === 0)
        @include('livewire.demo.partials.empty-panel', [
            'title' => 'No Digital Assets match',
            'message' => 'Create or connect the first Digital Asset for this portfolio, or adjust filters. Empty does not mean the portfolio has no problems.',
        ])
    @elseif ($viewMode === 'cards')
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($assets as $asset)
                <x-ta.card>
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex min-w-0 items-start gap-3">
                            <x-demo.digital-asset-mark :type="$asset['type']" :asset="$asset" size="md" />
                            <div class="min-w-0">
                                <h3 class="font-semibold text-gray-800 dark:text-white/90">{{ $asset['name'] }}</h3>
                                <p class="text-sm text-gray-500">{{ $asset['type_label'] }}</p>
                                <p class="mt-1 text-xs text-gray-400">{{ $asset['brand_name'] ?? '' }} · {{ $asset['customer_name'] ?? '' }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2 text-xs">
                        <x-ta.badge color="light" size="sm">{{ $asset['operational_status_label'] ?? 'Active' }}</x-ta.badge>
                        <x-ta.badge :color="match($asset['data_state'] ?? '') { 'fresh' => 'success', 'stale' => 'warning', 'unavailable' => 'error', default => 'light' }" size="sm">
                            Data · {{ $asset['data_state_label'] ?? '—' }}
                        </x-ta.badge>
                    </div>
                    <p class="mt-3 text-sm text-gray-500">{{ $asset['open_findings'] ?? 0 }} Findings · {{ $asset['open_tasks'] ?? 0 }} Tasks</p>
                    <div class="mt-4">
                        <x-ta.button :href="route($asset['route'])" size="sm">Open</x-ta.button>
                    </div>
                </x-ta.card>
            @endforeach
        </div>
    @else
        <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <table class="min-w-full text-sm">
                <caption class="sr-only">Digital Assets directory</caption>
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-400">Asset</th>
                        <th scope="col" class="hidden px-4 py-3 text-left text-xs font-medium uppercase text-gray-400 md:table-cell">Brand / Customer</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-400">Operational</th>
                        <th scope="col" class="hidden px-4 py-3 text-left text-xs font-medium uppercase text-gray-400 lg:table-cell">Data</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-400">Attention</th>
                        <th scope="col" class="hidden px-4 py-3 text-left text-xs font-medium uppercase text-gray-400 sm:table-cell">Work</th>
                        <th scope="col" class="hidden px-4 py-3 text-left text-xs font-medium uppercase text-gray-400 xl:table-cell">Responsible</th>
                        <th scope="col" class="hidden px-4 py-3 text-left text-xs font-medium uppercase text-gray-400 xl:table-cell">Activity</th>
                        <th scope="col" class="px-4 py-3"><span class="sr-only">Action</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($assets as $asset)
                        <tr class="border-b border-gray-50 dark:border-gray-800/60">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2.5">
                                    <x-demo.digital-asset-mark :type="$asset['type']" :asset="$asset" size="sm" />
                                    <div>
                                        <p class="font-medium text-gray-800 dark:text-white/90">{{ $asset['name'] }}</p>
                                        <p class="text-xs text-gray-500">{{ $asset['type_label'] }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="hidden px-4 py-3 text-gray-500 md:table-cell">
                                <span class="block text-gray-800 dark:text-white/90">{{ $asset['brand_name'] ?? '—' }}</span>
                                <span class="text-xs">{{ $asset['customer_name'] ?? '' }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <x-ta.badge color="light" size="sm">{{ $asset['operational_status_label'] ?? 'Active' }}</x-ta.badge>
                            </td>
                            <td class="hidden px-4 py-3 lg:table-cell">
                                <x-ta.badge :color="match($asset['data_state'] ?? '') { 'fresh' => 'success', 'stale' => 'warning', 'unavailable' => 'error', default => 'light' }" size="sm">
                                    {{ $asset['data_state_label'] ?? '—' }}
                                </x-ta.badge>
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $asset['open_findings'] ?? 0 }} Findings</td>
                            <td class="hidden px-4 py-3 text-gray-600 dark:text-gray-300 sm:table-cell">{{ $asset['open_tasks'] ?? 0 }} Tasks</td>
                            <td class="hidden px-4 py-3 xl:table-cell">
                                @php $owner = ($asset['responsible_users'][0] ?? null); @endphp
                                @if ($owner)
                                    <span class="inline-flex items-center gap-2" title="{{ $owner['name'] }}">
                                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-white/90" aria-hidden="true">{{ $owner['initials'] }}</span>
                                        <span class="sr-only">{{ $owner['name'] }}</span>
                                        <span class="text-sm text-gray-700 dark:text-gray-300">{{ $owner['name'] }}</span>
                                    </span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="hidden px-4 py-3 text-xs text-gray-500 xl:table-cell">{{ $asset['last_meaningful_activity'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <x-ta.button :href="route($asset['route'])" size="sm" variant="outline">Open</x-ta.button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
