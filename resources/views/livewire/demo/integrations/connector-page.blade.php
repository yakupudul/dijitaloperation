@php
    $tabs = [
        'overview' => 'Overview',
        'resources' => 'Resources',
        'bindings' => 'Bindings',
        'data' => 'Data',
        'sync' => 'Sync',
        'activity' => 'Activity',
    ];
@endphp

<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <x-demo.digital-asset-mark :type="$data['type']" size="lg" />
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-400">Connector · {{ $data['integration_label'] }} Integration</p>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $data['name'] }}</h1>
                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                    <span class="font-medium text-emerald-700 dark:text-emerald-400">Connection · {{ $data['connection'] }}</span>
                    <span>·</span>
                    <span>Data freshness · {{ $data['freshness'] }}</span>
                    <span>·</span>
                    <span>Latest collection · {{ $data['latest_collection'] }}</span>
                </div>
                <p class="mt-2 text-xs text-gray-400">{{ $data['ontology_note'] }}</p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route($data['integration_route']) }}" wire:navigate class="inline-flex rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">{{ $data['integration_label'] }} Integration</a>
            <a href="{{ route('demo.integrations') }}" wire:navigate class="inline-flex rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">All Integrations</a>
        </div>
    </div>

    <nav class="flex flex-wrap gap-1 border-b border-gray-200 dark:border-gray-800" aria-label="Connector sections">
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
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-5">
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-xs text-gray-400">Connection</p>
                <p class="mt-1 text-lg font-semibold text-emerald-700 dark:text-emerald-400">{{ $data['connection'] }}</p>
            </div>
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-xs text-gray-400">Resources</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $data['resources_count'] }}</p>
            </div>
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-xs text-gray-400">Bound</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $data['bound'] }}</p>
            </div>
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-xs text-gray-400">Available</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-warning-600 dark:text-warning-400">{{ $data['available'] }}</p>
            </div>
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-xs text-gray-400">Latest collection</p>
                <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $data['latest_collection'] }}</p>
            </div>
        </div>
        <p class="text-sm text-gray-500 dark:text-gray-400">This connector answers: is authorization healthy, which resources exist, how many are bound, and is data being collected? It is not the specialist Digital Asset workspace.</p>
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="setTab('resources')" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white">Browse resources</button>
            <button type="button" wire:click="setTab('data')" class="rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Data preview</button>
        </div>
    @endif

    @if ($tab === 'resources')
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="grid flex-1 gap-2 sm:grid-cols-3">
                <div>
                    <label for="res-q" class="mb-1 block text-xs font-medium text-gray-500">Search</label>
                    <input id="res-q" type="search" wire:model.live.debounce.250ms="q" placeholder="Name, ID, domain…" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90" />
                </div>
                <div>
                    <label for="res-state" class="mb-1 block text-xs font-medium text-gray-500">State</label>
                    <select id="res-state" wire:model.live="state" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90">
                        <option value="all">All</option>
                        <option value="bound">Bound</option>
                        <option value="available">Available</option>
                        <option value="conflict">Conflict</option>
                        <option value="unavailable">Unavailable</option>
                    </select>
                </div>
                <div>
                    <label for="res-brand" class="mb-1 block text-xs font-medium text-gray-500">Brand mapping</label>
                    <select id="res-brand" wire:model.live="brand" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90">
                        <option value="all">All</option>
                        <option value="unmapped">Unmapped</option>
                    </select>
                </div>
            </div>
            <p class="text-xs text-gray-400">{{ count($resources) }} shown · resources appear only after discovery</p>
        </div>

        <ul class="space-y-2">
            @forelse ($resources as $resource)
                <li class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" wire:key="res-{{ $resource['id'] }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $resource['name'] }}</h3>
                                <span @class([
                                    'text-[11px] font-semibold uppercase',
                                    'text-emerald-700 dark:text-emerald-400' => $resource['state'] === 'bound',
                                    'text-amber-700 dark:text-amber-400' => $resource['state'] === 'available',
                                    'text-rose-700 dark:text-rose-400' => $resource['state'] === 'conflict',
                                    'text-gray-400' => $resource['state'] === 'unavailable',
                                ])>{{ $resource['state_label'] }}</span>
                                @if (! empty($resource['recommended']))
                                    <span class="rounded-md bg-brand-50 px-1.5 py-0.5 text-[10px] font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">Recommended match</span>
                                @endif
                            </div>
                            <p class="mt-1 text-xs text-gray-500">
                                @if (! empty($resource['external_id'])) ID {{ $resource['external_id'] }} · @endif
                                @if (! empty($resource['stream'])) {{ $resource['stream_label'] ?? 'Stream' }} {{ $resource['stream'] }} · @endif
                                @if (! empty($resource['property_type'])) {{ $resource['property_type'] }} · @endif
                                @if (! empty($resource['address'])) {{ $resource['address'] }} · @endif
                                @if (! empty($resource['currency'])) {{ $resource['currency'] }} · @endif
                                @if (! empty($resource['timezone'])) {{ $resource['timezone'] }} · @endif
                                Data {{ $resource['data_state'] }} · {{ $resource['last_collection'] }}
                            </p>
                            @if (! empty($resource['match_signal']))
                                <p class="mt-1 text-xs text-gray-400">{{ $resource['match_signal'] }}</p>
                            @endif
                            @if (($resource['state'] ?? '') === 'bound' && ! empty($resource['asset_name']))
                                <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">Digital Asset · {{ $resource['asset_name'] }}</p>
                            @endif
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if (($resource['state'] ?? '') === 'available')
                                <button type="button" wire:click="openBind('{{ $resource['id'] }}')" class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-medium text-white">Bind…</button>
                            @elseif (($resource['state'] ?? '') === 'bound' && ! empty($resource['asset_route']))
                                <a href="{{ route($resource['asset_route']) }}" wire:navigate class="rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Open Digital Asset</a>
                                <button type="button" wire:click="unbindResource('{{ $resource['id'] }}')" class="rounded-lg px-3 py-1.5 text-xs font-medium text-gray-500 hover:underline">Unbind</button>
                            @elseif (($resource['state'] ?? '') === 'conflict')
                                <span class="text-xs text-rose-600 dark:text-rose-400">Requires operator review</span>
                            @endif
                        </div>
                    </div>
                </li>
            @empty
                <li class="rounded-xl bg-white p-6 text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">No resources match these filters.</li>
            @endforelse
        </ul>
    @endif

    @if ($tab === 'bindings')
        <div class="space-y-3">
            @forelse ($bindings as $binding)
                <article class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <p class="text-xs uppercase tracking-wide text-gray-400">External Resource</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $binding['name'] }} · {{ $binding['external_id'] }}</p>
                    <p class="mt-3 text-xs text-gray-400">↓ Binding · {{ $data['integration_label'] }} Integration</p>
                    <p class="mt-3 text-xs uppercase tracking-wide text-gray-400">Digital Asset</p>
                    <div class="mt-1 flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $binding['asset_name'] }}</p>
                        @if (! empty($binding['asset_route']))
                            <a href="{{ route($binding['asset_route']) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open Digital Asset →</a>
                        @endif
                    </div>
                    @if (! empty($binding['related_website']))
                        <p class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-white/[0.03] dark:text-gray-300">
                            Related · {{ $binding['related_website'] }} — <span class="font-medium">{{ $binding['related_website_note'] }}</span>
                        </p>
                    @endif
                    <div class="mt-3">
                        <a href="{{ route('demo.integrations.connector', ['connector' => $data['id'], 'tab' => 'resources']) }}" wire:navigate class="text-xs font-medium text-gray-500 hover:underline">Manage provider resource →</a>
                    </div>
                </article>
            @empty
                <p class="text-sm text-gray-500">No bindings yet.</p>
            @endforelse
        </div>
    @endif

    @if ($tab === 'data')
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Collection preview</h2>
            <p class="mt-1 text-xs text-gray-400">Latest available through {{ $data['data']['latest_through'] }} · Freshness {{ $data['freshness'] }}</p>
            <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach ($data['data']['metrics'] as $metric)
                    <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                        <dt class="text-xs text-gray-400">{{ $metric['label'] }}</dt>
                        <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $metric['value'] }}</dd>
                        <dd class="text-[11px] text-gray-500">{{ $metric['state'] }}</dd>
                    </div>
                @endforeach
            </dl>
            <p class="mt-4 text-sm text-gray-500">{{ $data['data']['note'] }}</p>
            @if (! empty($data['data']['asset_cta']))
                <a href="{{ route($data['data']['asset_cta']['route'], $data['data']['asset_cta']['params'] ?? []) }}" wire:navigate class="mt-4 inline-flex text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
                    {{ $data['data']['asset_cta']['label'] }}
                </a>
            @endif
        </section>
    @endif

    @if ($tab === 'sync')
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Sync / collection</h2>
            <dl class="mt-4 grid gap-3 sm:grid-cols-2 text-sm">
                <div><dt class="text-xs text-gray-400">Last successful collection</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $data['sync']['last_success'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Last attempted collection</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $data['sync']['last_attempt'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Status</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $data['sync']['status'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Reporting timezone</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $data['sync']['timezone'] }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-xs text-gray-400">Collection scope</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $data['sync']['scope'] }}</dd></div>
                @if (! empty($data['sync']['failure']))
                    <div class="sm:col-span-2"><dt class="text-xs text-gray-400">Recent failure / attention</dt><dd class="font-medium text-warning-700 dark:text-warning-400">{{ $data['sync']['failure'] }}</dd></div>
                @endif
            </dl>
            <button type="button" wire:click="refreshCollection" class="mt-4 rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white">Refresh data (Demo)</button>
        </section>
    @endif

    @if ($tab === 'activity')
        <ol class="space-y-3">
            @foreach ($data['activity'] as $event)
                <li class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <p class="text-[11px] text-gray-400">{{ $event['when'] }} · {{ $event['actor'] }} · {{ $event['kind'] }}</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $event['event'] }}</p>
                    <p class="text-xs text-gray-500">{{ $event['detail'] }}</p>
                </li>
            @endforeach
        </ol>
    @endif

    @if ($bindResource)
        <div class="fixed inset-0 z-50 flex justify-end bg-gray-900/40" role="dialog" aria-modal="true" aria-labelledby="bind-title" wire:click="closeBind">
            <div class="flex h-full w-full max-w-lg flex-col overflow-y-auto bg-white shadow-xl dark:bg-gray-900" wire:click.stop>
                <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-400">Bind resource</p>
                        <h2 id="bind-title" class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $bindResource['name'] }}</h2>
                        <p class="mt-1 text-xs text-gray-500">{{ $bindResource['external_id'] ?? '' }}</p>
                        @if (! empty($bindResource['recommended']) || ! empty($bindResource['match_signal']))
                            <p class="mt-2 text-xs text-brand-700 dark:text-brand-300">{{ $bindResource['match_signal'] ?? 'Recommended match — confirm before binding' }}</p>
                        @endif
                    </div>
                    <button type="button" wire:click="closeBind" class="rounded-lg px-2 py-1 text-sm text-gray-500" aria-label="Close">✕</button>
                </div>
                <div class="space-y-4 px-5 py-4 text-sm">
                    <fieldset>
                        <legend class="text-xs font-medium uppercase tracking-wide text-gray-400">Action</legend>
                        <div class="mt-2 space-y-2">
                            <label class="flex items-center gap-2">
                                <input type="radio" wire:model.live="bindMode" value="existing" class="text-brand-500" />
                                <span>Bind to existing Digital Asset</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="radio" wire:model.live="bindMode" value="create" class="text-brand-500" />
                                <span>Create Digital Asset then bind</span>
                            </label>
                        </div>
                    </fieldset>

                    @if ($bindMode === 'existing')
                        <div>
                            <label for="bind-asset" class="mb-1 block text-xs font-medium text-gray-500">Digital Asset (Brand-scoped)</label>
                            <select id="bind-asset" wire:model="selectedAssetId" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90">
                                <option value="">Select…</option>
                                @foreach ($data['existing_assets_for_brand'] as $asset)
                                    <option value="{{ $asset['id'] }}">{{ $asset['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    @else
                        <div>
                            <label for="new-asset" class="mb-1 block text-xs font-medium text-gray-500">New Digital Asset name</label>
                            <input id="new-asset" type="text" wire:model="newAssetName" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90" />
                        </div>
                    @endif

                    @if ($confirmBind)
                        <div class="rounded-lg bg-brand-50 p-3 text-sm dark:bg-brand-500/10">
                            <p class="font-semibold text-gray-900 dark:text-white">Confirm binding</p>
                            <p class="mt-1 text-gray-700 dark:text-gray-300">{{ $bindResource['name'] }} → {{ $bindMode === 'existing' ? (collect($data['existing_assets_for_brand'])->firstWhere('id', $selectedAssetId)['name'] ?? '—') : $newAssetName }}</p>
                            <p class="mt-2 text-xs text-gray-500">No silent auto-bind. No cross-Brand binding. No provider write.</p>
                            <button type="button" wire:click="confirmBinding" class="mt-3 rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white">Confirm binding</button>
                        </div>
                    @else
                        <button type="button" wire:click="prepareConfirm" class="rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white">Continue to confirm</button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
