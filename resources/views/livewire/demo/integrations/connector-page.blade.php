@php
    $isGa4 = ($data['id'] ?? null) === 'ga4';
    $tabs = $isGa4
        ? [
            'resources' => 'Mülkler',
            'data' => 'Veri',
            'activity' => 'Geçmiş',
        ]
        : [
            'overview' => 'Overview',
            'resources' => 'Resources',
            'bindings' => 'Bindings',
            'data' => 'Data',
            'sync' => 'Sync',
            'activity' => 'Activity',
        ];
    $displayTab = $isGa4 && ! array_key_exists($tab, $tabs) ? 'resources' : $tab;
@endphp

<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    @if ($isGa4)
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 items-start gap-3">
                <x-demo.digital-asset-mark :type="$data['type']" size="lg" />
                <div class="min-w-0">
                    <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Google Analytics</h1>
                    <div class="mt-1.5 flex flex-wrap items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                        <span class="inline-flex items-center gap-1.5 font-medium text-emerald-700 dark:text-emerald-400">
                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                            Bağlı
                        </span>
                        <span class="text-gray-300 dark:text-gray-700">·</span>
                        <span>{{ $data['resources_count'] }} mülk bulundu</span>
                        @if (($data['freshness'] ?? null) === 'Collected')
                            <span class="text-gray-300 dark:text-gray-700">·</span>
                            <span>Son veri {{ $data['latest_collection'] }}</span>
                        @endif
                    </div>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">GA4 mülklerinizi seçerek geçmiş verileri MoxDOP'a aktarın.</p>
                </div>
            </div>
            <a href="{{ route($data['integration_route']) }}" wire:navigate class="inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                Google hesabını yönet
            </a>
        </header>
    @else
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
                <a href="{{ route('operator.integrations') }}" wire:navigate class="inline-flex rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">All Integrations</a>
            </div>
        </div>
    @endif

    <nav class="flex flex-wrap gap-5 border-b border-gray-200 dark:border-gray-800" aria-label="Connector sections">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')"
                @class([
                    '-mb-px border-b-2 px-0.5 pb-3 pt-1 text-sm font-medium transition',
                    'border-brand-500 text-brand-700 dark:text-brand-400' => $displayTab === $key,
                    'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-800 dark:hover:border-gray-700 dark:hover:text-white/90' => $displayTab !== $key,
                ])>{{ $label }}</button>
        @endforeach
    </nav>

    @if ($isGa4)
        <livewire:demo.integrations.ga4-collection-monitor />
    @endif

    @if ($displayTab === 'overview')
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
        <p class="text-sm text-gray-500 dark:text-gray-400">This connector reads the shared provider integration, persisted resources, bindings and collection history.</p>
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="setTab('resources')" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white">Browse resources</button>
            <button type="button" wire:click="setTab('data')" class="rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Data preview</button>
        </div>
    @endif

    @if ($displayTab === 'resources')
        @if ($isGa4)
            <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="flex flex-col gap-3 border-b border-gray-100 px-4 py-4 dark:border-gray-800 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">GA4 mülkleri</h2>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">İlk aktarım son 486 günü alır. Sonraki güncellemelerde son 14 gün yeniden işlenir.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        @if (count($selectedResourceIds) > 0)
                            <span class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ count($selectedResourceIds) }} mülk seçildi</span>
                        @endif
                        <button
                            type="button"
                            wire:click="collectSelectedGa4"
                            wire:loading.attr="disabled"
                            wire:target="collectSelectedGa4"
                            @disabled(count($selectedResourceIds) === 0)
                            class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600 disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-400 dark:disabled:bg-gray-800 dark:disabled:text-gray-500"
                        >
                            <span wire:loading.remove wire:target="collectSelectedGa4">Verileri çek</span>
                            <span wire:loading wire:target="collectSelectedGa4">Başlatılıyor…</span>
                        </button>
                    </div>
                </div>

                <div class="flex flex-col gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-800 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex w-full max-w-2xl gap-2">
                        <div class="relative flex-1">
                            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
                            <input id="res-q" type="search" wire:model.live.debounce.250ms="q" placeholder="Mülk, hesap veya Property ID ara" class="w-full rounded-lg border border-gray-200 bg-white py-2 pl-9 pr-3 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90" />
                        </div>
                        <select id="res-state" wire:model.live="state" aria-label="Durum" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300">
                            <option value="all">Tüm durumlar</option>
                            <option value="bound">Bağlı</option>
                            <option value="available">Kullanılabilir</option>
                            <option value="conflict">Sorunlu</option>
                            <option value="unavailable">Kullanılamıyor</option>
                        </select>
                    </div>
                    <p class="text-xs text-gray-400">{{ count($resources) }} mülk</p>
                </div>

                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($resources as $resource)
                        <div class="flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between" wire:key="ga4-resource-{{ $resource['id'] }}">
                            <div class="flex min-w-0 items-start gap-3">
                                @if (! empty($resource['selectable_for_collection']))
                                    <input
                                        type="checkbox"
                                        wire:model.live="selectedResourceIds"
                                        value="{{ $resource['id'] }}"
                                        class="mt-1 h-4 w-4 shrink-0 rounded border-gray-300 text-brand-500 focus:ring-brand-500"
                                        aria-label="{{ $resource['name'] }} verisini seç"
                                    />
                                @else
                                    <span class="mt-1 h-4 w-4 shrink-0"></span>
                                @endif
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <h3 class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $resource['name'] }}</h3>
                                        @if (($resource['state'] ?? '') === 'bound')
                                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">Bağlı</span>
                                        @endif
                                    </div>
                                    <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">{{ $resource['account_name'] ?? 'Google Analytics' }} · Property {{ $resource['property_id'] ?? $resource['external_id'] }}</p>
                                    @if (($resource['state'] ?? '') === 'bound' && ! empty($resource['asset_name']))
                                        <p class="mt-1 text-xs text-gray-400">Dijital varlık · {{ $resource['asset_name'] }}</p>
                                    @endif
                                </div>
                            </div>

                            <div class="flex shrink-0 items-center gap-3 pl-7 sm:pl-0">
                                <div class="text-left sm:text-right">
                                    @if (($resource['data_state'] ?? 'Not collected') === 'Not collected')
                                        <p class="text-sm font-medium text-gray-500">Henüz veri yok</p>
                                    @else
                                        <p class="text-sm font-medium text-emerald-700 dark:text-emerald-400">Veri mevcut</p>
                                        <p class="mt-0.5 text-xs text-gray-400">{{ $resource['last_collection'] }}</p>
                                    @endif
                                </div>
                                @if (($resource['state'] ?? '') === 'bound' && ! empty($resource['asset_url']))
                                    <a href="{{ $resource['asset_url'] }}" wire:navigate class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">Varlığı aç</a>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-10 text-center text-sm text-gray-500">Bu filtrelerle eşleşen GA4 mülkü yok.</div>
                    @endforelse
                </div>
            </section>
        @else
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div class="grid flex-1 gap-2 sm:grid-cols-3">
                    <div>
                        <label for="res-q" class="mb-1 block text-xs font-medium text-gray-500">Search</label>
                        <input id="res-q" type="search" wire:model.live.debounce.250ms="q" placeholder="Name, ID, account…" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90" />
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
                <p class="text-xs text-gray-400">{{ count($resources) }} shown · persisted discovery resources</p>
            </div>

            <ul class="space-y-2">
                @forelse ($resources as $resource)
                    <li class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" wire:key="res-{{ $resource['id'] }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $resource['name'] }}</h3>
                                    <span class="text-[11px] font-semibold uppercase text-gray-400">{{ $resource['state_label'] }}</span>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">ID {{ $resource['external_id'] }} · Data {{ $resource['data_state'] }} · {{ $resource['last_collection'] }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if (($resource['state'] ?? '') === 'available')
                                    <button type="button" wire:click="openBind('{{ $resource['id'] }}')" class="rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Manage binding…</button>
                                @elseif (($resource['state'] ?? '') === 'bound' && ! empty($resource['asset_url']))
                                    <a href="{{ $resource['asset_url'] }}" wire:navigate class="rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Open Digital Asset</a>
                                @endif
                            </div>
                        </div>
                    </li>
                @empty
                    <li class="rounded-xl bg-white p-6 text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">No resources match these filters.</li>
                @endforelse
            </ul>
        @endif
    @endif

    @if ($displayTab === 'bindings')
        <div class="space-y-3">
            @forelse ($bindings as $binding)
                <article class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <p class="text-xs uppercase tracking-wide text-gray-400">External Resource</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $binding['name'] }} · {{ $binding['external_id'] }}</p>
                    <p class="mt-3 text-xs text-gray-400">↓ Active binding · {{ $data['integration_label'] }} Integration</p>
                    <p class="mt-3 text-xs uppercase tracking-wide text-gray-400">Digital Asset</p>
                    <div class="mt-1 flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $binding['asset_name'] }}</p>
                        @if (! empty($binding['asset_url']))
                            <a href="{{ $binding['asset_url'] }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open Digital Asset →</a>
                        @endif
                    </div>
                </article>
            @empty
                <p class="text-sm text-gray-500">No bindings yet.</p>
            @endforelse
        </div>
    @endif

    @if ($displayTab === 'data')
        @if ($isGa4)
            <section class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">Merkezi veri havuzu</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">GA4'ten alınan verilerin merkezi durumunu görüntüleyin.</p>
                </div>
                <dl class="grid divide-y divide-gray-100 dark:divide-gray-800 sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                    @foreach ($data['data']['metrics'] as $metric)
                        <div class="px-5 py-4">
                            <dt class="text-xs text-gray-400">{{ $metric['label'] }}</dt>
                            <dd class="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{{ $metric['value'] }}</dd>
                            <dd class="mt-0.5 text-xs text-gray-500">{{ $metric['state'] }}</dd>
                        </div>
                    @endforeach
                </dl>
                <div class="border-t border-gray-100 px-5 py-4 text-sm text-gray-500 dark:border-gray-800 dark:text-gray-400">
                    {{ $data['data']['note'] }}
                </div>
            </section>
        @else
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
            </section>
        @endif
    @endif

    @if ($displayTab === 'sync')
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Sync / collection</h2>
            <dl class="mt-4 grid gap-3 sm:grid-cols-2 text-sm">
                <div><dt class="text-xs text-gray-400">Last successful collection</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $data['sync']['last_success'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Last attempted collection</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $data['sync']['last_attempt'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Status</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $data['sync']['status'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Reporting timezone</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $data['sync']['timezone'] }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-xs text-gray-400">Collection scope</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $data['sync']['scope'] }}</dd></div>
            </dl>
            <button type="button" wire:click="refreshCollection" class="mt-4 rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white">Open collection activity</button>
        </section>
    @endif

    @if ($displayTab === 'activity')
        @if ($isGa4)
            <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">Aktarım geçmişi</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Son GA4 toplama hareketleri.</p>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($data['activity'] as $event)
                        @php
                            $eventText = (string) ($event['event'] ?? '');
                            $isSuccess = str_contains($eventText, 'Completed');
                            $isProblem = str_contains($eventText, 'Failed') || str_contains($eventText, 'Partial');
                            $isStopped = str_contains($eventText, 'Cancelled');
                        @endphp
                        <div class="flex flex-col gap-2 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span @class([
                                        'h-2 w-2 rounded-full',
                                        'bg-emerald-500' => $isSuccess,
                                        'bg-amber-500' => $isProblem,
                                        'bg-gray-400' => $isStopped,
                                        'bg-blue-500' => ! $isSuccess && ! $isProblem && ! $isStopped,
                                    ])></span>
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $event['event'] }}</p>
                                </div>
                                <p class="mt-1 pl-4 text-xs text-gray-500">{{ $event['detail'] }}</p>
                            </div>
                            <p class="shrink-0 pl-4 text-xs text-gray-400 sm:pl-0">{{ $event['when'] }}</p>
                        </div>
                    @empty
                        <div class="px-5 py-10 text-center text-sm text-gray-500">Henüz GA4 aktarım geçmişi yok.</div>
                    @endforelse
                </div>
            </section>
        @else
            <ol class="space-y-3">
                @forelse ($data['activity'] as $event)
                    <li class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                        <p class="text-[11px] text-gray-400">{{ $event['when'] }} · {{ $event['actor'] }} · {{ $event['kind'] }}</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $event['event'] }}</p>
                        <p class="text-xs text-gray-500">{{ $event['detail'] }}</p>
                    </li>
                @empty
                    <li class="rounded-xl bg-white p-4 text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">No collection activity recorded for this connector.</li>
                @endforelse
            </ol>
        @endif
    @endif
</div>