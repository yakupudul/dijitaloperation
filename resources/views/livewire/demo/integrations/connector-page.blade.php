@php
    $isGa4 = ($data['id'] ?? null) === 'ga4';
    $isGsc = ($data['id'] ?? null) === 'gsc';
    $isCentralGoogle = $isGa4 || $isGsc;
    $tabs = $isCentralGoogle
        ? [
            'resources' => 'Hesaplar',
            'manual' => 'Toplu işlemler',
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
    $displayTab = $isCentralGoogle && ! array_key_exists($tab, $tabs) ? 'resources' : $tab;
    $selectedRows = $isCentralGoogle
        ? collect($resources ?? [])->filter(fn (array $row): bool => in_array((string) $row['id'], array_map('strval', $selectedResourceIds), true))
        : collect();
    $selectedActions = $selectedRows->pluck('collection_action')->filter()->unique()->values();
    $primaryAction = count($selectedResourceIds) === 0
        ? 'Verileri çek'
        : ($selectedActions->count() === 1 ? (string) $selectedActions->first() : 'Seçilenleri işle');
    $collectMethod = $isGsc ? 'collectSelectedGsc' : 'collectSelectedGa4';
    $connectorTitle = $isGsc ? 'Google Search Console' : 'Google Analytics';
    $resourceTitle = $isGsc ? 'Search Console mülkleri' : 'GA4 mülkleri';
    $resourceHint = $isGsc
        ? 'İlk aktarım 486 günlük kesinleşmiş arama performansını alır. Sonraki işlemlerde eksik dönem tamamlanır ve son 7 gün yeniden doğrulanır. Search Console verisi için son 3 gün kesin analiz döneminin dışında tutulur.'
        : 'İlk aktarım 486 günlük geçmişi alır. Sonraki işlemlerde eksik dönem tamamlanır ve son 14 gün yeniden doğrulanır.';
@endphp

<div class="space-y-8">
    @include('livewire.demo.partials.flash')

    @if ($isCentralGoogle)
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 items-start gap-3">
                <x-demo.digital-asset-mark :type="$data['type']" size="lg" />
                <div class="min-w-0">
                    <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white">{{ $connectorTitle }}</h1>
                    <div class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
                        <x-ta.pill :tone="($data['integration_active'] ?? false) ? 'ok' : 'look'" class="text-sm">{{ ($data['integration_active'] ?? false) ? 'Bağlı' : 'Bağlantı kapalı' }}</x-ta.pill>
                        <span>{{ $data['resources_count'] }} mülk bulundu</span>
                        @if (($data['freshness'] ?? null) === 'Collected')
                            <span>Son veri {{ $data['latest_collection'] }}</span>
                        @endif
                    </div>
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
                    <p class="text-xs text-gray-500">{{ $data['integration_label'] }} Integration</p>
                    <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white">{{ $data['name'] }}</h1>
                    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500">
                        <x-ta.pill tone="ok">Connection: {{ $data['connection'] }}</x-ta.pill>
                        <span>Data freshness: {{ $data['freshness'] }}</span><span>Latest collection: {{ $data['latest_collection'] }}</span>
                    </div>
                    <p class="mt-2 text-xs text-gray-500">{{ $data['ontology_note'] }}</p>
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
                    'border-gray-900 text-gray-900 dark:border-white dark:text-white' => $displayTab === $key,
                    'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-800 dark:hover:border-gray-700 dark:hover:text-white/90' => $displayTab !== $key,
                ])>{{ $label }}</button>
        @endforeach
    </nav>



    @if ($displayTab === 'overview')
        <div class="grid grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-3 xl:grid-cols-5">
            <div><p class="text-xs text-gray-500">Connection</p><p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $data['connection'] }}</p></div>
            <div><p class="text-xs text-gray-500">Resources</p><p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $data['resources_count'] }}</p></div>
            <div><p class="text-xs text-gray-500">Bound</p><p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $data['bound'] }}</p></div>
            <div><p class="text-xs text-gray-500">Available</p><p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $data['available'] }}</p></div>
            <div><p class="text-xs text-gray-500">Latest collection</p><p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $data['latest_collection'] }}</p></div>
        </div>
        <p class="text-sm text-gray-500 dark:text-gray-400">This connector reads the shared provider integration, persisted resources, bindings and collection history.</p>
        <div class="flex flex-wrap gap-2"><button type="button" wire:click="setTab('resources')" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white hover:bg-brand-600">Browse resources</button><button type="button" wire:click="setTab('data')" class="rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Data preview</button></div>
    @endif

    @if ($displayTab === 'resources' && $isCentralGoogle)
        <livewire:operator.integrations.resource-automations provider="google" :resource-type="$isGa4 ? 'ga4' : 'search_console'" :expanded="true" />
    @endif

    @if ($displayTab === 'manual' || ($displayTab === 'resources' && ! $isCentralGoogle))
        @if ($isCentralGoogle)
            <section>
                <div class="flex flex-col gap-3 pb-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="section-title">{{ $resourceTitle }}</h2>
                        <p class="mt-0.5 max-w-3xl text-xs text-gray-500 dark:text-gray-400">{{ $resourceHint }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        @if (count($selectedResourceIds) > 0)<span class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ count($selectedResourceIds) }} mülk seçildi</span>@endif
                        <button type="button" wire:click="{{ $collectMethod }}" wire:loading.attr="disabled" wire:target="{{ $collectMethod }}" @disabled(count($selectedResourceIds) === 0) class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600 disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-400 dark:disabled:bg-gray-800 dark:disabled:text-gray-500">
                            <span wire:loading.remove wire:target="{{ $collectMethod }}">{{ $primaryAction }}</span>
                            <span wire:loading wire:target="{{ $collectMethod }}">Başlatılıyor…</span>
                        </button>
                    </div>
                </div>

                <div class="flex flex-col gap-2 border-b border-gray-200 pb-3 dark:border-gray-800 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex w-full max-w-2xl gap-2">
                        <div class="relative flex-1">
                            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
                            <input id="res-q" type="search" wire:model.live.debounce.250ms="q" placeholder="{{ $isGsc ? 'Mülk veya site adresi ara' : 'Mülk, hesap veya Property ID ara' }}" class="w-full rounded-lg border border-gray-200 bg-white py-2 pl-9 pr-3 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90" />
                        </div>
                        <select id="res-state" wire:model.live="state" aria-label="Durum" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300">
                            <option value="all">Tüm durumlar</option><option value="bound">Bağlı</option><option value="available">Kullanılabilir</option><option value="unavailable">Kullanılamıyor</option>
                        </select>
                    </div>
                    <p class="text-xs text-gray-500">{{ count($resources) }} mülk</p>
                </div>

                <div class="divide-y divide-gray-200 dark:divide-gray-800">
                    @forelse ($resources as $resource)
                        <div class="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between hover:bg-gray-50 dark:hover:bg-white/[0.03]" wire:key="central-resource-{{ $data['id'] }}-{{ $resource['id'] }}">
                            <div class="flex min-w-0 items-start gap-3">
                                @if (! empty($resource['selectable_for_collection']))
                                    <input type="checkbox" wire:model.live="selectedResourceIds" value="{{ $resource['id'] }}" class="mt-1 h-4 w-4 shrink-0 rounded border-gray-300 text-brand-500 focus:ring-brand-500" aria-label="{{ $resource['name'] }} verisini seç" />
                                @else
                                    <span class="mt-1 h-4 w-4 shrink-0"></span>
                                @endif
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <h3 class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $resource['name'] }}</h3>
                                        @if (($resource['state'] ?? '') === 'bound')<x-ta.pill tone="ok">Bağlı</x-ta.pill>@endif
                                    </div>
                                    @if ($isGsc)
                                        <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">{{ $resource['external_id'] }}</p>
                                    @else
                                        <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">{{ $resource['account_name'] ?? 'Google Analytics' }}, Property {{ $resource['property_id'] ?? $resource['external_id'] }}</p>
                                    @endif
                                    @if (($resource['state'] ?? '') === 'bound' && ! empty($resource['asset_name']))<p class="mt-1 text-xs text-gray-500">Dijital varlık: {{ $resource['asset_name'] }}</p>@endif
                                </div>
                            </div>

                            <div class="flex shrink-0 items-center gap-3 pl-7 sm:pl-0">
                                <div class="min-w-0 text-left sm:min-w-[190px] sm:text-right">
                                    @switch($resource['data_state'] ?? 'not_collected')
                                        @case('collecting')
                                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Çekiliyor</p><p class="mt-0.5 text-xs text-gray-500">Aktarım arka planda devam ediyor</p>@break
                                        @case('needs_repair')
                                            <p class="text-sm font-medium text-warning-700 dark:text-warning-300">Eksik veri var</p><p class="mt-0.5 text-xs text-gray-500">{{ $resource['failed_datasets'] }} veri grubu, {{ $resource['collection_action'] }}</p>@break
                                        @case('resume')
                                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Aktarım durduruldu</p><p class="mt-0.5 text-xs text-gray-500">{{ $resource['collection_action'] }}</p>@break
                                        @case('collected')
                                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Veri mevcut</p>
                                            @if (! empty($resource['coverage_start']) && ! empty($resource['coverage_end']))<p class="mt-0.5 text-xs text-gray-500">{{ $resource['coverage_start'] }} – {{ $resource['coverage_end'] }}</p>@endif
                                            <p class="mt-0.5 text-xs text-gray-500">Son başarılı aktarım {{ $resource['last_success'] }}</p>@break
                                        @default
                                            <p class="text-sm font-medium text-gray-500">Henüz veri yok</p><p class="mt-0.5 text-xs text-gray-500">İlk aktarım, 486 gün</p>
                                    @endswitch
                                </div>
                                @if (($resource['state'] ?? '') === 'bound' && ! empty($resource['asset_url']))<a href="{{ $resource['asset_url'] }}" wire:navigate class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">Varlığı aç</a>@endif
                            </div>
                        </div>
                    @empty
                        <div class="py-8 text-sm text-gray-500">Bu filtrelerle eşleşen {{ $isGsc ? 'Search Console' : 'GA4' }} mülkü yok.</div>
                    @endforelse
                </div>
            </section>
        @else
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div class="grid flex-1 gap-2 sm:grid-cols-3">
                    <div><label for="res-q" class="mb-1 block text-xs font-medium text-gray-500">Search</label><input id="res-q" type="search" wire:model.live.debounce.250ms="q" placeholder="Name, ID, account…" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90" /></div>
                    <div><label for="res-state" class="mb-1 block text-xs font-medium text-gray-500">State</label><select id="res-state" wire:model.live="state" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90"><option value="all">All</option><option value="bound">Bound</option><option value="available">Available</option><option value="unavailable">Unavailable</option></select></div>
                    <div><label for="res-brand" class="mb-1 block text-xs font-medium text-gray-500">Brand mapping</label><select id="res-brand" wire:model.live="brand" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white/90"><option value="all">All</option><option value="unmapped">Unmapped</option></select></div>
                </div>
                <p class="text-xs text-gray-500">{{ count($resources) }} shown</p>
            </div>
            <ul class="divide-y divide-gray-200 dark:divide-gray-800">
                @forelse ($resources as $resource)
                    <li wire:key="res-{{ $resource['id'] }}" class="py-3"><div class="flex flex-wrap items-start justify-between gap-3"><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $resource['name'] }}</h3><span class="text-xs text-gray-500">{{ $resource['state_label'] }}</span></div><p class="mt-1 text-xs text-gray-500">ID {{ $resource['external_id'] }}, data {{ $resource['data_state'] }}, {{ $resource['last_collection'] }}</p></div><div class="flex flex-wrap gap-2">@if (($resource['state'] ?? '') === 'available')<button type="button" wire:click="openBind('{{ $resource['id'] }}')" class="rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Manage binding…</button>@elseif (($resource['state'] ?? '') === 'bound' && ! empty($resource['asset_url']))<a href="{{ $resource['asset_url'] }}" wire:navigate class="rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Open Digital Asset</a>@endif</div></div></li>
                @empty
                    <li class="py-6 text-sm text-gray-500">No resources match these filters.</li>
                @endforelse
            </ul>
        @endif
    @endif

    @if ($displayTab === 'bindings')
        <div class="divide-y divide-gray-200 dark:divide-gray-800">@forelse ($bindings as $binding)<article class="py-3"><p class="text-xs text-gray-500">External Resource</p><p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $binding['name'] }} <span class="font-normal text-gray-500">{{ $binding['external_id'] }}</span></p><p class="mt-3 text-xs text-gray-500">Active binding, {{ $data['integration_label'] }} Integration</p><p class="mt-3 text-xs text-gray-500">Digital Asset</p><div class="mt-1 flex flex-wrap items-center justify-between gap-2"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $binding['asset_name'] }}</p>@if (! empty($binding['asset_url']))<a href="{{ $binding['asset_url'] }}" wire:navigate class="text-xs link-neutral">Open Digital Asset</a>@endif</div></article>@empty<p class="text-sm text-gray-500">No bindings yet.</p>@endforelse</div>
    @endif

    @if ($displayTab === 'data')
        @if ($isCentralGoogle)
            <section>
                <div class="pb-3"><h2 class="section-title">Merkezi veri havuzu</h2></div>
                <dl class="grid grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-3">@foreach ($data['data']['metrics'] as $metric)<div><dt class="text-xs text-gray-500">{{ $metric['label'] }}</dt><dd class="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{{ $metric['value'] }}</dd><dd class="mt-0.5 text-xs text-gray-500">{{ $metric['state'] }}</dd></div>@endforeach</dl>
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ $data['data']['note'] }}</p>
                @if ($isGsc)<div class="mt-2 text-xs text-gray-500 dark:text-gray-400">Toplanan ana gruplar: günlük performans, sorgular, sayfalar, sorgu×sayfa, cihaz, ülke, sayfa×cihaz, sayfa×ülke, sorgu×cihaz, sorgu×ülke, arama görünümü, arama görünümü×sayfa, sitemap ve mülk metadatası. URL Inspection kota sınırlı anlık veri olduğu için toplu 486 günlük aktarımın parçası değildir.</div>@endif
            </section>
        @else
            <section><h2 class="section-title">Collection preview</h2><p class="mt-1 text-xs text-gray-500">Latest available through {{ $data['data']['latest_through'] }}, freshness {{ $data['freshness'] }}</p><dl class="mt-4 grid grid-cols-2 gap-x-8 gap-y-6">@foreach ($data['data']['metrics'] as $metric)<div><dt class="text-xs text-gray-500">{{ $metric['label'] }}</dt><dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $metric['value'] }}</dd><dd class="text-xs text-gray-500">{{ $metric['state'] }}</dd></div>@endforeach</dl><p class="mt-4 text-sm text-gray-500">{{ $data['data']['note'] }}</p></section>
        @endif
    @endif

    @if ($displayTab === 'sync')
        <section><h2 class="section-title">Sync / collection</h2><dl class="mt-4 grid gap-3 sm:grid-cols-2 text-sm"><div><dt class="text-xs text-gray-500">Last successful collection</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $data['sync']['last_success'] }}</dd></div><div><dt class="text-xs text-gray-500">Last attempted collection</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $data['sync']['last_attempt'] }}</dd></div><div><dt class="text-xs text-gray-500">Status</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $data['sync']['status'] }}</dd></div><div><dt class="text-xs text-gray-500">Reporting timezone</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $data['sync']['timezone'] }}</dd></div><div class="sm:col-span-2"><dt class="text-xs text-gray-500">Collection scope</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $data['sync']['scope'] }}</dd></div></dl><button type="button" wire:click="refreshCollection" class="mt-4 rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:ring-gray-700">Open collection activity</button></section>
    @endif

    @if ($displayTab === 'activity')
        @if ($isGa4)<livewire:demo.integrations.ga4-collection-monitor />@endif
        @if ($isGsc)<livewire:demo.integrations.gsc-collection-monitor />@endif
        @if ($isCentralGoogle)
            <section wire:poll.15s.visible>
                <div class="pb-2"><h2 class="section-title">Aktarım geçmişi</h2></div>
                <div class="divide-y divide-gray-200 dark:divide-gray-800">
                    @forelse ($data['activity'] as $event)
                        @php $eventText = (string) ($event['event'] ?? ''); $isSuccess = str_contains($eventText, 'Completed'); $isProblem = str_contains($eventText, 'Failed') || str_contains($eventText, 'Partial'); $isStopped = str_contains($eventText, 'Cancelled'); @endphp
                        <div class="flex flex-col gap-2 py-2.5 sm:flex-row sm:items-center sm:justify-between"><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><span @class(['h-1.5 w-1.5 rounded-full','bg-success-500' => $isSuccess,'bg-warning-500' => $isProblem,'bg-gray-400' => $isStopped,'bg-gray-500' => ! $isSuccess && ! $isProblem && ! $isStopped])></span><p class="text-sm font-medium text-gray-900 dark:text-white">{{ $event['event'] }}</p></div><p class="mt-1 pl-4 text-xs text-gray-500">{{ $event['detail'] }}</p></div><p class="shrink-0 pl-4 text-xs text-gray-500 sm:pl-0">{{ $event['when'] }}</p></div>
                    @empty
                        <div class="py-8 text-sm text-gray-500">Henüz {{ $isGsc ? 'Search Console' : 'GA4' }} aktarım geçmişi yok.</div>
                    @endforelse
                </div>
            </section>
        @else
            <ol class="divide-y divide-gray-200 dark:divide-gray-800">@forelse ($data['activity'] as $event)<li class="py-2.5"><p class="text-xs text-gray-500">{{ $event['when'] }} · {{ $event['actor'] }}, {{ $event['kind'] }}</p><p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $event['event'] }}</p><p class="text-xs text-gray-500">{{ $event['detail'] }}</p></li>@empty<li class="py-4 text-sm text-gray-500">No collection activity recorded for this connector.</li>@endforelse</ol>
        @endif
    @endif
</div>

