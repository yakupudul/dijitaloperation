@php
    $tabs = [
        'overview' => 'Overview',
        'assets' => 'Digital Assets',
        'cross_channel' => 'Cross-channel',
        'context' => 'Business Context',
        'operations' => 'Operations',
        'discovery' => 'Public Discovery',
        'ai' => 'Brand AI',
        'history' => 'Decision History',
    ];
    $initial = mb_strtoupper(mb_substr((string) ($brandRow['name'] ?? 'B'), 0, 1));
    $teamLabel = count($responsibleUsers) > 0
        ? (($responsibleUsers[0]['name'] ?? 'Team').(count($responsibleUsers) > 1 ? ' + '.(count($responsibleUsers) - 1) : ''))
        : null;
@endphp

<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    {{-- Brand identity header --}}
    <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 dark:border-gray-800 lg:flex-row lg:items-start lg:justify-between">
        <div class="flex min-w-0 items-start gap-4">
            <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-brand-500/10 text-xl font-bold text-brand-600 dark:text-brand-400" aria-hidden="true">{{ $initial }}</span>
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $brandRow['name'] }}</h1>
                    @include('livewire.demo.partials.demo-badge')
                </div>
                @if ($customer)
                    <a href="{{ route('demo.customer', ['customerId' => $customer['id']]) }}" wire:navigate class="mt-1 inline-block text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
                        {{ $customer['name'] }}
                    </a>
                @endif
                @if ($metaLine !== '')
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $metaLine }}</p>
                @endif
                <div class="mt-2 flex flex-wrap items-center gap-3">
                    @if ($teamLabel)
                        <div class="flex items-center gap-2" title="{{ collect($responsibleUsers)->pluck('name')->implode(', ') }}">
                            <div class="flex -space-x-1.5">
                                @foreach (array_slice($responsibleUsers, 0, 3) as $user)
                                    <span class="flex h-7 w-7 items-center justify-center rounded-full border-2 border-white bg-gray-100 text-[10px] font-semibold text-gray-700 dark:border-gray-900 dark:bg-gray-800 dark:text-gray-200">{{ $user['initials'] }}</span>
                                @endforeach
                            </div>
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $teamLabel }}</span>
                        </div>
                    @endif
                    <button type="button" wire:click="setTab('context')" class="text-xs font-medium text-gray-500 underline-offset-2 hover:text-gray-800 hover:underline dark:text-gray-400 dark:hover:text-white/90">
                        Business context · {{ $businessContext['completed'] }}/{{ $businessContext['total'] }}
                    </button>
                </div>
            </div>
        </div>
        <div class="shrink-0">
            @include('livewire.demo.partials._brand-show-actions', [
                'brandId' => $brandRow['id'],
                'customerId' => $customer['id'] ?? null,
            ])
        </div>
    </div>

    {{-- Tabs --}}
    <div class="-mx-1 overflow-x-auto">
        <div class="flex min-w-max gap-1 border-b border-gray-200 px-1 pb-px dark:border-gray-800" role="tablist" aria-label="Brand workspace">
            @foreach ($tabs as $key => $label)
                <button
                    type="button"
                    role="tab"
                    wire:click="setTab('{{ $key }}')"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    @class([
                        'rounded-t-lg px-3 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40',
                        'border-b-2 border-brand-500 text-brand-600 dark:text-brand-400' => $tab === $key,
                        'border-b-2 border-transparent text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white/90' => $tab !== $key,
                    ])
                >{{ $label }}</button>
            @endforeach
        </div>
    </div>

    {{-- OVERVIEW --}}
    @if ($tab === 'overview')
        <div class="space-y-6">
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
                <button type="button" wire:click="setTab('assets')" class="rounded-xl bg-white p-4 text-left ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-800 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    <p class="text-xs text-gray-500">Digital assets</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $glance['assets'] }}</p>
                    <p class="mt-1 text-xs text-gray-400">Assets under this brand</p>
                </button>
                <button type="button" wire:click="setTab('assets')" class="rounded-xl bg-white p-4 text-left ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-800 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    <p class="text-xs text-gray-500">Connected assets</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $glance['connected'] }} / {{ $glance['assets'] }}</p>
                    <p class="mt-1 text-xs text-gray-400">With usable connections</p>
                </button>
                <button type="button" wire:click="setOps('findings')" class="rounded-xl bg-white p-4 text-left ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-800 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    <p class="text-xs text-gray-500">Open findings</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $glance['open_findings'] }}</p>
                </button>
                <button type="button" wire:click="setOps('recommendations')" class="rounded-xl bg-white p-4 text-left ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-800 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    <p class="text-xs text-gray-500">Open recommendations</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $glance['open_recommendations'] }}</p>
                </button>
                <button type="button" wire:click="setOps('tasks')" class="rounded-xl bg-white p-4 text-left ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-800 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    <p class="text-xs text-gray-500">Open tasks</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $glance['open_tasks'] }}</p>
                </button>
            </div>

            <section>
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">Needs attention</h2>
                @if ($attention === [])
                    <div class="mt-3 rounded-xl bg-white px-4 py-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                        <p class="text-sm font-medium text-gray-800 dark:text-white/90">Nothing requires immediate attention.</p>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Open work and routine monitoring are still available below.</p>
                    </div>
                @else
                    <ul class="mt-3 space-y-2">
                        @foreach ($attention as $item)
                            <li class="flex flex-col gap-3 rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <span @class([
                                        'inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide',
                                        'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400' => in_array($item['severity'], ['HIGH', 'CRITICAL', 'OVERDUE', 'BLOCKED TASK'], true),
                                        'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400' => in_array($item['severity'], ['MEDIUM'], true) || str_contains($item['severity'], 'MEDIUM'),
                                        'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' => ! in_array($item['severity'], ['HIGH', 'CRITICAL', 'OVERDUE', 'BLOCKED TASK', 'MEDIUM'], true) && ! str_contains($item['severity'], 'MEDIUM'),
                                    ])>{{ $item['severity'] }}</span>
                                    <p class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">{{ $item['title'] }}</p>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $item['where'] }}@if (! empty($item['when'])) · {{ $item['when'] }}@endif</p>
                                    @if (! empty($item['why']))
                                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $item['why'] }}</p>
                                    @endif
                                </div>
                                <div class="shrink-0">
                                    @if (! empty($item['wire_tab']))
                                        <button type="button" wire:click="setTab('{{ $item['wire_tab'] }}')" class="inline-flex rounded-lg px-3 py-1.5 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">{{ $item['action_label'] }}</button>
                                    @elseif (! empty($item['route']))
                                        <a href="{{ route($item['route'], $item['route_params'] ?? []) }}" wire:navigate class="inline-flex rounded-lg px-3 py-1.5 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">{{ $item['action_label'] }}</a>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section>
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">Current priorities</h2>
                    <button type="button" wire:click="setTab('operations')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">View operations</button>
                </div>
                <ul class="mt-3 space-y-2">
                    @forelse ($priorities as $priority)
                        <li class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                            <a href="{{ $priority['href'] }}" wire:navigate class="block">
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $priority['title'] }}</p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $priority['kind'] }} · {{ $priority['priority'] }} · {{ $priority['asset'] }}</p>
                            </a>
                        </li>
                    @empty
                        <li class="rounded-xl bg-white px-4 py-5 text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-400 dark:ring-gray-700">No active priorities for this brand.</li>
                    @endforelse
                </ul>
            </section>

            <section>
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">Digital estate</h2>
                    <button type="button" wire:click="setTab('assets')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">View all</button>
                </div>
                <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($assets as $asset)
                        <a href="{{ route($asset['route']) }}" wire:navigate class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-800 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex min-w-0 items-start gap-2.5">
                                    <x-demo.digital-asset-mark :type="$asset['type']" :asset="$asset" size="sm" />
                                    <div class="min-w-0">
                                        <p class="text-xs text-gray-400">{{ $asset['type_label'] }}</p>
                                        <p class="mt-0.5 truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $asset['name'] }}</p>
                                    </div>
                                </div>
                                <span class="shrink-0 text-xs text-gray-500">{{ $asset['connection_label'] }}</span>
                            </div>
                            <p class="mt-2 text-xs text-gray-500">{{ $asset['freshness_label'] }} · {{ $asset['open_findings'] ?? 0 }} open findings</p>
                            <p class="mt-2 text-xs font-medium text-brand-600 dark:text-brand-400">Open →</p>
                        </a>
                    @endforeach
                </div>
            </section>

            <div class="grid gap-4 lg:grid-cols-2">
                <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Business context</h2>
                        <button type="button" wire:click="setTab('context')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">View business context</button>
                    </div>
                    <dl class="mt-3 space-y-3 text-sm">
                        <div>
                            <dt class="text-xs text-gray-400">What the business does</dt>
                            <dd class="mt-0.5 text-gray-700 dark:text-gray-300">{{ $businessContext['business_summary'] ?? 'Unknown' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-400">Priority offerings</dt>
                            <dd class="mt-0.5 text-gray-700 dark:text-gray-300">{{ collect($businessContext['priority_offerings'] ?? [])->take(3)->implode(' · ') ?: 'Unknown' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-400">Target audiences</dt>
                            <dd class="mt-0.5 text-gray-700 dark:text-gray-300">{{ collect($businessContext['target_audiences'] ?? [])->take(2)->implode(' · ') ?: 'Unknown' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-400">Positioning</dt>
                            <dd class="mt-0.5 line-clamp-2 text-gray-700 dark:text-gray-300">{{ $businessContext['positioning'] ?? 'Unknown' }}</dd>
                        </div>
                    </dl>
                    <a href="{{ route('demo.brand.edit', ['brandId' => $brandRow['id']]) }}" wire:navigate class="mt-4 inline-block text-xs font-medium text-gray-500 hover:underline">Edit context</a>
                </section>

                <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Cross-channel</h2>
                        <button type="button" wire:click="setTab('cross_channel')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open</button>
                    </div>
                    <ul class="mt-3 space-y-2">
                        @forelse ($crossChannel as $check)
                            <li class="flex items-start justify-between gap-3 text-sm">
                                <div>
                                    <p class="font-medium text-gray-800 dark:text-white/90">{{ $check['check'] }}</p>
                                    <p class="text-xs text-gray-500">
                                        {{ $check['state_label'] }}
                                        @if (($check['open_findings'] ?? 0) > 0)
                                            · {{ $check['open_findings'] }} findings
                                        @elseif (($check['state'] ?? '') === 'ok')
                                            · {{ $check['last_checked'] }}
                                        @endif
                                    </p>
                                </div>
                            </li>
                        @empty
                            <li class="text-sm text-gray-500">Not enough data for cross-channel checks.</li>
                        @endforelse
                    </ul>
                </section>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Recent decisions</h2>
                        <button type="button" wire:click="setTab('history')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">View decision history</button>
                    </div>
                    <ul class="mt-3 space-y-3">
                        @foreach (array_slice($decisionChains, 0, 4) as $chain)
                            <li class="text-sm">
                                <p class="text-xs text-gray-400">{{ $chain['date'] }} · {{ $chain['asset'] }}</p>
                                <p class="font-medium text-gray-800 dark:text-white/90">{{ $chain['finding'] ?? 'Decision chain' }}</p>
                                <p class="text-xs text-gray-500">
                                    @if (! empty($chain['recommendation'])) Recommendation · @endif
                                    @if (! empty($chain['task'])) Task · @endif
                                    {{ $chain['outcome'] ?? 'Incomplete chain' }}
                                </p>
                            </li>
                        @endforeach
                    </ul>
                </section>

                <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Recent activity</h2>
                        <a href="{{ route('demo.activity') }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">View all activity</a>
                    </div>
                    <ul class="mt-3 space-y-2">
                        @foreach (array_slice($recentActivity, 0, 5) as $activity)
                            <li class="flex items-center justify-between gap-3 text-sm">
                                <span class="text-gray-700 dark:text-gray-300">{{ $activity['title'] }}</span>
                                <span class="shrink-0 text-xs text-gray-400">{{ $activity['when'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            </div>
        </div>
    @endif

    {{-- DIGITAL ASSETS --}}
    @if ($tab === 'assets')
        <div class="space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Digital assets</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Managed digital properties and accounts belonging to this brand.</p>
                </div>
                <a href="{{ route('demo.asset.create', ['brandId' => $brandRow['id']]) }}" wire:navigate class="inline-flex rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600">Add digital asset</a>
            </div>

            <div class="flex flex-col gap-3 rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 lg:flex-row lg:items-end">
                <div class="min-w-0 flex-1">
                    <label for="asset-search" class="mb-1 block text-xs font-medium text-gray-500">Search</label>
                    <input id="asset-search" type="search" wire:model.live.debounce.300ms="asset_q" placeholder="Search digital assets..." class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </div>
                <select wire:model.live="asset_type" aria-label="Asset type" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    <option value="">Asset type</option>
                    @foreach ($assetTypeOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="asset_connection" aria-label="Connection state" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    <option value="">Connection state</option>
                    <option value="connected">Connected</option>
                    <option value="configured">Configured</option>
                    <option value="needs_attention">Needs attention</option>
                    <option value="not_configured">Not configured</option>
                </select>
                <select wire:model.live="asset_attention" aria-label="Attention" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    <option value="">Attention</option>
                    <option value="needs_attention">Needs attention</option>
                    <option value="clear">Clear</option>
                </select>
                @if ($asset_q !== '' || $asset_type !== '' || $asset_connection !== '' || $asset_attention !== '')
                    <button type="button" wire:click="clearAssetFilters" class="text-xs font-medium text-brand-600 hover:underline">Clear</button>
                @endif
            </div>

            <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-800">
                    <thead class="bg-gray-50 dark:bg-white/[0.02]">
                        <tr class="text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                            <th class="px-4 py-3">Asset</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Connection</th>
                            <th class="px-4 py-3">Data freshness</th>
                            <th class="px-4 py-3">Open findings</th>
                            <th class="px-4 py-3">Open tasks</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($filteredAssets as $asset)
                            <tr wire:key="asset-{{ $asset['id'] }}">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <x-demo.digital-asset-mark :asset="$asset" size="sm" />
                                        <span class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $asset['name'] }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500">{{ $asset['type_label'] }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $asset['connection_label'] }}</td>
                                <td class="px-4 py-3 text-sm text-gray-500">{{ $asset['freshness_label'] }}</td>
                                <td class="px-4 py-3 text-sm tabular-nums text-gray-700 dark:text-gray-300">{{ $asset['open_findings'] ?? 0 }}</td>
                                <td class="px-4 py-3 text-sm tabular-nums text-gray-700 dark:text-gray-300">{{ $asset['open_tasks'] ?? 0 }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route($asset['route']) }}" wire:navigate class="inline-flex rounded-lg px-3 py-1.5 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Open</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-500">No digital assets match these filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- CROSS-CHANNEL --}}
    @if ($tab === 'cross_channel')
        <div class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Cross-channel</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Evidence-based consistency checks across digital assets belonging to this brand.</p>
            </div>

            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Coverage</h3>
                <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($crossChannel as $check)
                        <li class="flex items-center justify-between gap-3 py-2 text-sm">
                            <span class="text-gray-800 dark:text-white/90">{{ $check['check'] }}</span>
                            <span class="text-xs text-gray-500">{{ $check['state_label'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-800">
                    <thead class="bg-gray-50 dark:bg-white/[0.02]">
                        <tr class="text-left text-xs font-medium uppercase text-gray-400">
                            <th class="px-4 py-3">Check</th>
                            <th class="px-4 py-3">Participating assets</th>
                            <th class="px-4 py-3">State</th>
                            <th class="px-4 py-3">Last checked</th>
                            <th class="px-4 py-3">Open findings</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($crossChannel as $check)
                            <tr>
                                <td class="px-4 py-3 text-sm font-medium text-gray-800 dark:text-white/90">{{ $check['check'] }}</td>
                                <td class="px-4 py-3 text-sm text-gray-500">
                                    @foreach ($check['assets'] as $a)
                                        <span class="block">{{ $a }}</span>
                                    @endforeach
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $check['state_label'] }}</td>
                                <td class="px-4 py-3 text-sm text-gray-500">{{ $check['last_checked'] }}</td>
                                <td class="px-4 py-3 text-sm tabular-nums">{{ $check['open_findings'] }}</td>
                                <td class="px-4 py-3 text-right">
                                    @if (! empty($check['route']))
                                        <a href="{{ route($check['route']) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open</a>
                                    @else
                                        <span class="text-xs text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-gray-400">Findings remain attached to the primary Digital Asset. Brand only rolls them up. Missing evidence is not treated as healthy.</p>
        </div>
    @endif

    {{-- BUSINESS CONTEXT --}}
    @if ($tab === 'context')
        <div class="space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Business context</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Factual business information used across MoxDOP analysis.</p>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $businessContext['completed'] }} of {{ $businessContext['total'] }} key areas completed</p>
                    @if (! empty($businessContext['updated_at']))
                        <p class="text-xs text-gray-400">Last updated {{ $businessContext['updated_at'] }}@if (! empty($businessContext['updated_by'])) by {{ $businessContext['updated_by'] }}@endif · {{ $businessContext['source'] ?? 'Operator maintained' }}</p>
                    @endif
                </div>
                <a href="{{ route('demo.brand.edit', ['brandId' => $brandRow['id']]) }}" wire:navigate class="inline-flex rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600">
                    {{ ($businessContext['completed'] ?? 0) > 0 ? 'Edit business context' : 'Add business context' }}
                </a>
            </div>

            <div class="space-y-4 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <section>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Business</h3>
                    <dl class="mt-2 grid gap-3 sm:grid-cols-2 text-sm">
                        <div><dt class="text-xs text-gray-400">Business summary</dt><dd class="mt-0.5 text-gray-700 dark:text-gray-300">{{ $businessContext['business_summary'] ?? 'Unknown' }}</dd></div>
                        <div><dt class="text-xs text-gray-400">Business model</dt><dd class="mt-0.5 text-gray-700 dark:text-gray-300">{{ $businessContext['business_model'] ?? 'Unknown' }}</dd></div>
                    </dl>
                </section>
                <section class="border-t border-gray-100 pt-4 dark:border-gray-800">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Offerings</h3>
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">{{ collect($businessContext['products_services'] ?? [])->implode(' · ') ?: 'Unknown' }}</p>
                    <p class="mt-2 text-xs text-gray-400">Priority order</p>
                    <ol class="mt-1 list-decimal space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                        @foreach ($businessContext['priority_offerings'] ?? [] as $offering)
                            <li>{{ $offering }}</li>
                        @endforeach
                    </ol>
                </section>
                <section class="border-t border-gray-100 pt-4 dark:border-gray-800">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Audiences & markets</h3>
                    <dl class="mt-2 grid gap-3 sm:grid-cols-2 text-sm">
                        <div><dt class="text-xs text-gray-400">Target audiences</dt><dd class="mt-0.5 text-gray-700 dark:text-gray-300">{{ collect($businessContext['target_audiences'] ?? [])->implode(' · ') ?: 'Unknown' }}</dd></div>
                        <div><dt class="text-xs text-gray-400">Target markets</dt><dd class="mt-0.5 text-gray-700 dark:text-gray-300">{{ collect($businessContext['target_markets'] ?? [])->implode(', ') ?: 'Unknown' }}</dd></div>
                    </dl>
                </section>
                <section class="border-t border-gray-100 pt-4 dark:border-gray-800">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Goals</h3>
                    <dl class="mt-2 grid gap-3 sm:grid-cols-2 text-sm">
                        <div><dt class="text-xs text-gray-400">Business goals</dt><dd class="mt-0.5 text-gray-700 dark:text-gray-300">{{ collect($businessContext['business_goals'] ?? [])->implode(' · ') ?: 'Unknown' }}</dd></div>
                        <div><dt class="text-xs text-gray-400">Conversion goals</dt><dd class="mt-0.5 text-gray-700 dark:text-gray-300">{{ collect($businessContext['conversion_goals'] ?? [])->implode(' · ') ?: 'Unknown' }}</dd></div>
                    </dl>
                </section>
                <section class="border-t border-gray-100 pt-4 dark:border-gray-800">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Positioning</h3>
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">{{ $businessContext['positioning'] ?? 'Unknown' }}</p>
                    <p class="mt-2 text-xs text-gray-400">Differentiators</p>
                    <p class="text-sm text-gray-700 dark:text-gray-300">{{ collect($businessContext['differentiators'] ?? [])->implode(' · ') ?: 'Unknown' }}</p>
                </section>
                <section class="border-t border-gray-100 pt-4 dark:border-gray-800">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Competition</h3>
                    <ul class="mt-2 space-y-2 text-sm">
                        @forelse ($businessContext['known_competitors'] ?? [] as $comp)
                            <li class="text-gray-700 dark:text-gray-300">
                                <span class="font-medium">{{ $comp['name'] }}</span>
                                @if (! empty($comp['note'])) — {{ $comp['note'] }}@endif
                                @if (! empty($comp['url']))
                                    <a href="{{ $comp['url'] }}" class="ml-1 text-brand-600 hover:underline" target="_blank" rel="noopener">Source</a>
                                @endif
                            </li>
                        @empty
                            <li class="text-gray-500">Unknown</li>
                        @endforelse
                    </ul>
                </section>
                <section class="border-t border-gray-100 pt-4 dark:border-gray-800">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Constraints</h3>
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">{{ collect($businessContext['important_constraints'] ?? [])->implode(' · ') ?: 'Unknown' }}</p>
                    @if (! empty($businessContext['unknown_areas']))
                        <p class="mt-3 text-xs text-gray-400">Still unknown: {{ collect($businessContext['unknown_areas'])->implode(' · ') }}</p>
                    @endif
                </section>
            </div>
        </div>
    @endif

    {{-- OPERATIONS --}}
    @if ($tab === 'operations')
        <div class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Operations</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Findings, decisions and active work across this brand.</p>
            </div>

            <div class="flex flex-wrap gap-3 text-sm text-gray-600 dark:text-gray-300">
                <span>Open Findings <strong class="tabular-nums text-gray-900 dark:text-white">{{ $opsSummary['open_findings'] }}</strong></span>
                <span>·</span>
                <span>Open Recommendations <strong class="tabular-nums text-gray-900 dark:text-white">{{ $opsSummary['open_recommendations'] }}</strong></span>
                <span>·</span>
                <span>Open Tasks <strong class="tabular-nums text-gray-900 dark:text-white">{{ $opsSummary['open_tasks'] }}</strong></span>
                @if ($opsSummary['blocked_overdue'] > 0)
                    <span>·</span>
                    <span class="text-error-600 dark:text-error-400">Blocked/overdue {{ $opsSummary['blocked_overdue'] }}</span>
                @endif
                @if ($opsSummary['awaiting_follow_up'] > 0)
                    <span>·</span>
                    <span>Awaiting follow-up {{ $opsSummary['awaiting_follow_up'] }}</span>
                @endif
            </div>

            <div class="inline-flex flex-wrap gap-1 rounded-lg bg-gray-100 p-1 dark:bg-white/[0.04]" role="tablist" aria-label="Operations">
                @foreach (['findings' => 'Findings', 'recommendations' => 'Recommendations', 'tasks' => 'Tasks', 'outcomes' => 'Outcomes'] as $key => $label)
                    <button type="button" wire:click="setOps('{{ $key }}')" @class([
                        'rounded-md px-3 py-1.5 text-sm font-medium',
                        'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-white' => $ops === $key,
                        'text-gray-600 dark:text-gray-400' => $ops !== $key,
                    ])>{{ $label }}</button>
                @endforeach
            </div>

            @if ($ops === 'findings')
                <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-800">
                        <thead class="bg-gray-50 dark:bg-white/[0.02]">
                            <tr class="text-left text-xs uppercase text-gray-400">
                                <th class="px-4 py-3">Severity</th>
                                <th class="px-4 py-3">Finding</th>
                                <th class="px-4 py-3">Digital Asset</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">First seen</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($findings as $finding)
                                <tr>
                                    <td class="px-4 py-3"><x-ta.badge :color="match($finding['severity']) { 'critical', 'high' => 'error', 'medium' => 'warning', default => 'info' }" size="sm">{{ $finding['severity'] }}</x-ta.badge></td>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-800 dark:text-white/90">{{ $finding['title'] }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $finding['asset'] }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $finding['status'] }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $finding['detected'] }}</td>
                                    <td class="px-4 py-3 text-right"><a href="{{ route('demo.findings') }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">Open</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($ops === 'recommendations')
                <div class="space-y-3">
                    @foreach ($recommendations as $rec)
                        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $rec['title'] }}</p>
                                    <p class="mt-1 text-xs text-gray-500">{{ $rec['asset'] ?? '' }} · Linked finding {{ $rec['finding_id'] ?? '—' }} · {{ $rec['status'] }}</p>
                                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $rec['observation'] ?? '' }}</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <a href="{{ route('demo.recommendations') }}" wire:navigate class="inline-flex rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Review</a>
                                    @if (($rec['status'] ?? '') === 'pending')
                                        <button type="button" wire:click="createTaskFromRecommendation('{{ $rec['id'] }}')" class="inline-flex rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-600">Create task</button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($ops === 'tasks')
                <div class="space-y-3">
                    @foreach ($tasks as $task)
                        @php
                            $isBlocked = ($task['status'] ?? '') === 'blocked';
                            $isOverdue = ($task['due'] ?? '') === 'Last week' && ($task['status'] ?? '') !== 'completed';
                        @endphp
                        <div @class([
                            'rounded-xl bg-white p-4 ring-1 ring-inset dark:bg-gray-800',
                            'ring-error-300 dark:ring-error-500/40' => $isBlocked || $isOverdue,
                            'ring-gray-200 dark:ring-gray-700' => ! $isBlocked && ! $isOverdue,
                        ])>
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $task['title'] }}</p>
                                    <p class="mt-1 text-xs text-gray-500">
                                        {{ $task['asset'] }} · {{ ucfirst($task['priority'] ?? '') }} · {{ $task['owner'] }} · due {{ $task['due'] }}
                                        · {{ str_replace('_', ' ', $task['status'] ?? '') }}
                                        @if ($isOverdue)<span class="text-error-600"> · overdue</span>@endif
                                        @if ($isBlocked)<span class="text-error-600"> · blocked</span>@endif
                                    </p>
                                </div>
                                <a href="{{ route('demo.task', ['taskId' => $task['id']]) }}" wire:navigate class="inline-flex rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Open</a>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($ops === 'outcomes')
                <div class="space-y-3">
                    @php
                        $outcomeTasks = collect($tasks)->filter(fn ($t) => ($t['status'] ?? '') === 'completed' || ! empty($t['outcome']))->values();
                    @endphp
                    @forelse ($outcomeTasks as $task)
                        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $task['title'] }}</p>
                            <p class="mt-1 text-xs text-gray-500">{{ $task['asset'] }}</p>
                            @if (! empty($task['outcome']))
                                <p class="mt-2 text-sm text-emerald-700 dark:text-emerald-400">{{ $task['outcome']['label'] ?? 'Observed outcome' }}</p>
                                <p class="mt-1 text-xs text-gray-500">{{ $task['outcome']['note'] ?? 'Improvement observed after this task was completed.' }}</p>
                            @else
                                <p class="mt-2 text-sm text-amber-700 dark:text-amber-400">Awaiting follow-up</p>
                                <p class="mt-1 text-xs text-gray-500">The linked finding has not yet been re-evaluated in a later eligible window.</p>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No completed tasks with outcome signals yet.</p>
                    @endforelse
                </div>
            @endif
        </div>
    @endif

    {{-- PUBLIC DISCOVERY --}}
    @if ($tab === 'discovery')
        @include('livewire.demo.portfolio.partials.brand-public-discovery')
    @endif

    {{-- BRAND AI --}}
    @if ($tab === 'ai')
        <div class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Brand AI</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Evidence-grounded interpretation of the brand's current digital operations.</p>
            </div>

            @if ($aiBrief)
                <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Analysis context</h3>
                    <ul class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($aiBrief['sources_available'] ?? [] as $source)
                            <li class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/[0.03]">
                                <span class="text-gray-700 dark:text-gray-300">{{ $source['label'] }}</span>
                                <span class="text-xs {{ ($source['state'] ?? '') === 'available' ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400' }}">
                                    {{ ($source['state'] ?? '') === 'available' ? 'Available' : 'Not connected' }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-3 text-xs text-gray-400">Analysis based on data available as of {{ $aiBrief['as_of'] ?? '—' }} · Demo Mode — no live model call</p>
                </div>

                <div class="space-y-4 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <section>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Executive summary</h3>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                            @foreach ($aiBrief['points'] as $point)
                                <li>{{ $point }}</li>
                            @endforeach
                        </ul>
                    </section>
                    <section>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Attention</h3>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                            @foreach ($aiBrief['attention'] ?? [] as $row)
                                <li>{{ $row }}</li>
                            @endforeach
                        </ul>
                    </section>
                    <section>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Opportunities</h3>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                            @foreach ($aiBrief['opportunities'] ?? [] as $row)
                                <li>{{ $row }}</li>
                            @endforeach
                        </ul>
                    </section>
                    <section>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Cross-channel interpretation</h3>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                            @foreach ($aiBrief['cross_channel'] ?? [] as $row)
                                <li>{{ $row }}</li>
                            @endforeach
                        </ul>
                    </section>
                    <section>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Operational priorities</h3>
                        <ul class="mt-2 space-y-3">
                            @foreach ($aiBrief['priorities'] as $index => $priority)
                                <li class="flex flex-wrap items-start justify-between gap-3 rounded-lg bg-gray-50 px-3 py-3 dark:bg-white/[0.03]">
                                    <div>
                                        <p class="text-sm text-gray-700 dark:text-gray-300">{{ $priority }}</p>
                                        <p class="mt-1 text-xs text-gray-400">Interpretation · Suggested next step (advisory — not an automatic Recommendation)</p>
                                    </div>
                                    <button type="button" wire:click="createRecommendationFromPriority({{ $index }})" class="rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Create recommendation</button>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                    <section>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Unknowns / limitations</h3>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                            @foreach ($aiBrief['unknowns'] ?? [] as $row)
                                <li>{{ $row }}</li>
                            @endforeach
                        </ul>
                    </section>
                    <p class="text-xs text-gray-400">{{ $aiBrief['disclaimer'] }}</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($aiBrief['evidence_links'] ?? [] as $link)
                            <a href="{{ route($link['route']) }}" wire:navigate class="inline-flex rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">{{ $link['label'] }}</a>
                        @endforeach
                    </div>
                </div>
            @else
                <div class="rounded-xl bg-white p-6 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <p class="text-sm text-gray-600 dark:text-gray-300">Brand-wide Digital Operations Analyst is presentation-ready in Demo Mode for brands with prepared analysis context.</p>
                    <button type="button" wire:click="runAiBrief" class="mt-4 inline-flex rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white">Show demo analysis</button>
                </div>
            @endif
        </div>
    @endif

    {{-- DECISION HISTORY --}}
    @if ($tab === 'history')
        <div class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Decision History</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">How findings became decisions, work and observed outcomes over time.</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <select wire:model.live="history_asset" aria-label="Digital Asset" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    <option value="">Digital Asset</option>
                    @foreach ($historyAssetOptions as $assetName)
                        <option value="{{ $assetName }}">{{ $assetName }}</option>
                    @endforeach
                </select>
                <select wire:model.live="history_type" aria-label="Decision type" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    <option value="">Decision type</option>
                    <option value="finding">Finding</option>
                    <option value="recommendation">Recommendation</option>
                    <option value="task">Task</option>
                    <option value="outcome">Outcome</option>
                </select>
            </div>

            <div class="space-y-3">
                @forelse ($decisionChains as $chain)
                    <article class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                        <p class="text-xs text-gray-400">{{ $chain['date'] }} · {{ $chain['asset'] }}</p>
                        <div class="mt-3 space-y-2 border-l-2 border-gray-200 pl-4 dark:border-gray-700">
                            @if (! empty($chain['finding']))
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Finding</p>
                                    <p class="text-sm text-gray-800 dark:text-white/90">{{ $chain['finding'] }}</p>
                                </div>
                            @endif
                            @if (! empty($chain['recommendation']))
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Recommendation</p>
                                    <p class="text-sm text-gray-800 dark:text-white/90">{{ $chain['recommendation'] }}</p>
                                    @if (! empty($chain['decision']))
                                        <p class="text-xs text-gray-500">{{ $chain['decision'] }}</p>
                                    @endif
                                </div>
                            @endif
                            @if (! empty($chain['task']))
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Task</p>
                                    <p class="text-sm text-gray-800 dark:text-white/90">{{ $chain['task'] }}</p>
                                    <p class="text-xs text-gray-500">
                                        @if (! empty($chain['assignee'])) Assigned to {{ $chain['assignee'] }} · @endif
                                        @if (! empty($chain['completed'])) Completed {{ $chain['completed'] }} @else Open @endif
                                    </p>
                                </div>
                            @endif
                            @if (! empty($chain['outcome']))
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Observed outcome</p>
                                    <p class="text-sm text-gray-800 dark:text-white/90">{{ $chain['outcome'] }}@if (! empty($chain['outcome_date'])) · {{ $chain['outcome_date'] }}@endif</p>
                                    @if (! empty($chain['outcome_note']))
                                        <p class="text-xs text-gray-500">{{ $chain['outcome_note'] }}</p>
                                    @endif
                                </div>
                            @elseif (empty($chain['recommendation']) && empty($chain['task']))
                                <p class="text-xs text-gray-500">{{ $chain['outcome_note'] ?? 'Incomplete chain — Finding only.' }}</p>
                            @endif
                        </div>
                    </article>
                @empty
                    <p class="text-sm text-gray-500">No decision chains match these filters.</p>
                @endforelse
            </div>
            <p class="text-xs text-gray-400">Decision History excludes raw sync Activity. Observed after ≠ caused by.</p>
        </div>
    @endif
</div>
