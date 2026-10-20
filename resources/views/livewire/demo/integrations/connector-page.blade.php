@php
    // Panel concept 2026-10-20: "Hangi mülkün verisi geliyor, hangisi eksik?" The Hesaplar tab (one row per property with
    // its collection state) answers it; bulk collection, the data pool and the history are the other tabs.
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
            'overview' => 'Genel bakış',
            'resources' => 'Kaynaklar',
            'bindings' => 'Bağlantılar',
            'data' => 'Veri',
            'sync' => 'Veri çekimi',
            'activity' => 'Geçmiş',
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
    $mark = $isGsc ? 'gsc' : ($isGa4 ? 'ga4' : (string) ($data['type'] ?? 'google'));
    $field = 'h-9 rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-white';

    // The read model still speaks English; the screen speaks plain Turkish (panel concept 2026-10-20).
    $metricWords = [
        'Discovered resources' => ['Bulunan mülkler', 'Kayıtlı kaynaklar'],
        'Central/bound collection runs' => ['Veri çekimleri', 'Toplama geçmişi'],
        'History target' => ['Geçmiş hedefi', 'İlk merkezi aktarım'],
    ];
    $noteWords = [
        'Central provider data exists and can later be attached to a Digital Asset without recollecting the same history.' => 'Merkezi veri var; aynı geçmiş yeniden çekilmeden bir dijital varlığa bağlanabilir.',
        'No completed collection has been recorded for this connector yet.' => 'Bu bağlayıcıda henüz tamamlanmış veri çekimi yok.',
        'No collection data.' => 'Henüz veri yok.',
    ];
    $eventWords = ['completed' => 'tamamlandı', 'failed' => 'başarısız', 'partial' => 'kısmen tamamlandı', 'cancelled' => 'durduruldu', 'running' => 'sürüyor', 'queued' => 'sırada', 'pending' => 'sırada'];
    $eventText = function (string $event) use ($eventWords): string {
        if (! str_starts_with($event, 'Collection ')) {
            return $event;
        }
        $state = mb_strtolower(substr($event, strlen('Collection ')));

        return 'Veri çekimi '.($eventWords[$state] ?? $state);
    };
    $detailText = fn (string $detail): string => str_replace([' · ', ' datasets'], [', ', ' veri grubu'], $detail);
    $valueText = fn (string $value): string => str_replace(['486 days', 'Provider policy'], ['486 gün', 'Sağlayıcı kuralı'], $value);
@endphp

<div class="space-y-10">
    @include('livewire.demo.partials.flash')

    @if ($isCentralGoogle)
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex min-w-0 items-start gap-3">
                @include('livewire.demo.integrations.partials.provider-mark', ['provider' => $mark, 'size' => 'lg'])
                <div class="min-w-0">
                    <h1 class="page-title">{{ $connectorTitle }}</h1>
                    <p class="page-sub flex flex-wrap items-center gap-x-3 gap-y-1">
                        <x-ta.pill :tone="($data['integration_active'] ?? false) ? 'ok' : 'look'">{{ ($data['integration_active'] ?? false) ? 'Bağlı' : 'Bağlantı kapalı' }}</x-ta.pill>
                        <span>{{ $data['resources_count'] }} mülk bulundu</span>
                        @if (($data['freshness'] ?? null) === 'Collected')
                            <span>Son veri {{ $data['latest_collection'] }}</span>
                        @endif
                    </p>
                </div>
            </div>
            <a href="{{ route($data['integration_route']) }}" wire:navigate class="btn w-full sm:w-auto">Google hesabını yönet</a>
        </header>
    @else
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex min-w-0 items-start gap-3">
                @include('livewire.demo.integrations.partials.provider-mark', ['provider' => $mark, 'size' => 'lg'])
                <div class="min-w-0">
                    <h1 class="page-title">{{ $data['name'] }}</h1>
                    <p class="page-sub flex flex-wrap items-center gap-x-3 gap-y-1">
                        <x-ta.pill :tone="$data['connection'] === 'Not configured' ? 'info' : 'ok'">{{ \App\Support\Integrations\Presentation\OperatorLabel::turkish($data['connection']) }}</x-ta.pill>
                        <span>Son veri çekimi {{ $data['latest_collection'] }}</span>
                    </p>
                </div>
            </div>
            <div class="flex w-full flex-wrap gap-2 sm:w-auto">
                <a href="{{ route('operator.integrations') }}" wire:navigate class="btn-quiet">Tüm entegrasyonlar</a>
                <a href="{{ route($data['integration_route']) }}" wire:navigate class="btn">{{ $data['integration_label'] }} entegrasyonu</a>
            </div>
        </header>
    @endif

    <nav class="mb-6! flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" role="tablist" aria-label="Bağlayıcı bölümleri">
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $displayTab === $key ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')" @class(['tab', 'tab-active' => $displayTab === $key])>{{ $label }}</button>
        @endforeach
    </nav>

    @if ($displayTab === 'overview')
        <section>
            <div class="metric-strip grid-cols-2 lg:grid-cols-4">
                <x-ta.metric-card label="Bulunan kaynak" :value="$data['resources_count']" />
                <x-ta.metric-card label="Bağlı" :value="$data['bound']" />
                <x-ta.metric-card label="Bağlanabilir" :value="$data['available']" />
                <x-ta.metric-card label="Son veri çekimi" :value="$data['latest_collection']" :muted="$data['latest_collection'] === '—'" />
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <button type="button" wire:click="setTab('resources')" class="btn-primary w-full sm:w-auto">Kaynakları gör</button>
                <button type="button" wire:click="setTab('data')" class="btn w-full sm:w-auto">Veri önizlemesi</button>
            </div>
        </section>
    @endif

    @if ($displayTab === 'resources' && $isCentralGoogle)
        <livewire:operator.integrations.resource-automations provider="google" :resource-type="$isGa4 ? 'ga4' : 'search_console'" :expanded="true" />
    @endif

    @if ($displayTab === 'manual' || ($displayTab === 'resources' && ! $isCentralGoogle))
        @if ($isCentralGoogle)
            <section>
                <div class="section-head items-center">
                    <h2 class="section-title" title="{{ $resourceHint }}">{{ $resourceTitle }} <span class="section-count">{{ count($resources) }}</span></h2>
                    <div class="flex w-full flex-wrap items-center gap-3 sm:ml-auto sm:w-auto">
                        @if (count($selectedResourceIds) > 0)<span class="text-[13px] text-gray-600 dark:text-gray-300">{{ count($selectedResourceIds) }} mülk seçildi</span>@endif
                        <button type="button" wire:click="{{ $collectMethod }}" wire:loading.attr="disabled" wire:target="{{ $collectMethod }}" @disabled(count($selectedResourceIds) === 0) class="btn-primary w-full disabled:cursor-not-allowed disabled:opacity-40 sm:w-auto">
                            <span wire:loading.remove wire:target="{{ $collectMethod }}">{{ $primaryAction }}</span>
                            <span wire:loading wire:target="{{ $collectMethod }}">Başlatılıyor…</span>
                        </button>
                    </div>
                </div>

                <div class="mb-3 flex flex-wrap gap-2">
                    <input id="res-q" type="search" wire:model.live.debounce.250ms="q" placeholder="{{ $isGsc ? 'Mülk veya site adresi ara' : 'Mülk, hesap veya Property ID ara' }}" aria-label="Ara" class="{{ $field }} w-full min-w-0 flex-1 sm:max-w-md" />
                    <select id="res-state" wire:model.live="state" aria-label="Durum" class="{{ $field }} w-full sm:w-auto">
                        <option value="all">Tüm durumlar</option><option value="bound">Bağlı</option><option value="available">Kullanılabilir</option><option value="unavailable">Kullanılamıyor</option>
                    </select>
                </div>

                <ul class="card">
                    @forelse ($resources as $resource)
                        <li class="card-row flex-wrap items-start sm:flex-nowrap sm:items-center" wire:key="central-resource-{{ $data['id'] }}-{{ $resource['id'] }}">
                            @if (! empty($resource['selectable_for_collection']))
                                <input type="checkbox" wire:model.live="selectedResourceIds" value="{{ $resource['id'] }}" class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 text-gray-900 focus:ring-gray-500 sm:mt-0" aria-label="{{ $resource['name'] }} verisini seç" />
                            @else
                                <span class="h-4 w-4 shrink-0" aria-hidden="true"></span>
                            @endif
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5">
                                    <span class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $resource['name'] }}</span>
                                    @if (($resource['state'] ?? '') === 'bound')<x-ta.pill tone="ok">Bağlı</x-ta.pill>@endif
                                </p>
                                <p class="mt-0.5 truncate text-[13px] text-gray-500 dark:text-gray-400">
                                    @if ($isGsc){{ $resource['external_id'] }}@else{{ $resource['account_name'] ?? 'Google Analytics' }}, Property {{ $resource['property_id'] ?? $resource['external_id'] }}@endif
                                    @if (($resource['state'] ?? '') === 'bound' && ! empty($resource['asset_name'])). Dijital varlık: {{ $resource['asset_name'] }}@endif
                                </p>
                            </div>
                            <div class="w-full pl-7 text-[13px] sm:w-56 sm:shrink-0 sm:pl-0 sm:text-right">
                                @switch($resource['data_state'] ?? 'not_collected')
                                    @case('collecting')
                                        <p class="font-medium text-gray-700 dark:text-gray-300">Çekiliyor</p><p class="mt-0.5 text-xs text-gray-500">Aktarım arka planda devam ediyor</p>@break
                                    @case('needs_repair')
                                        <p class="font-medium text-warning-700 dark:text-warning-400">Eksik veri var</p><p class="mt-0.5 text-xs text-gray-500">{{ $resource['failed_datasets'] }} veri grubu, {{ $resource['collection_action'] }}</p>@break
                                    @case('resume')
                                        <p class="font-medium text-gray-700 dark:text-gray-300">Aktarım durduruldu</p><p class="mt-0.5 text-xs text-gray-500">{{ $resource['collection_action'] }}</p>@break
                                    @case('collected')
                                        <p class="font-medium text-gray-700 dark:text-gray-300">Veri mevcut</p>
                                        @if (! empty($resource['coverage_start']) && ! empty($resource['coverage_end']))<p class="mt-0.5 text-xs text-gray-500">{{ $resource['coverage_start'] }} – {{ $resource['coverage_end'] }}</p>@endif
                                        <p class="mt-0.5 text-xs text-gray-500">Son başarılı aktarım {{ $resource['last_success'] }}</p>@break
                                    @default
                                        <p class="font-medium text-gray-500">Henüz veri yok</p><p class="mt-0.5 text-xs text-gray-500">İlk aktarım, 486 gün</p>
                                @endswitch
                            </div>
                            @if (($resource['state'] ?? '') === 'bound' && ! empty($resource['asset_url']))<a href="{{ $resource['asset_url'] }}" wire:navigate class="btn-quiet w-full sm:w-auto">Varlığı aç</a>@endif
                        </li>
                    @empty
                        <li><x-ta.empty-state icon="none" size="sm" title="Bu filtrelerle eşleşen {{ $isGsc ? 'Search Console' : 'GA4' }} mülkü yok." /></li>
                    @endforelse
                </ul>
            </section>
        @else
            <section>
                <div class="section-head items-center">
                    <h2 class="section-title">Kaynaklar <span class="section-count">{{ count($resources) }}</span></h2>
                </div>
                <div class="mb-3 flex flex-wrap gap-2">
                    <input id="res-q" type="search" wire:model.live.debounce.250ms="q" placeholder="Ad, kimlik, hesap…" aria-label="Ara" class="{{ $field }} w-full min-w-0 flex-1 sm:max-w-md" />
                    <select id="res-state" wire:model.live="state" aria-label="Durum" class="{{ $field }} w-full sm:w-auto"><option value="all">Tüm durumlar</option><option value="bound">Bağlı</option><option value="available">Bağlanabilir</option><option value="unavailable">Kullanılamıyor</option></select>
                    <select id="res-brand" wire:model.live="brand" aria-label="Marka eşleşmesi" class="{{ $field }} w-full sm:w-auto"><option value="all">Tüm markalar</option><option value="unmapped">Markasız</option></select>
                </div>
                <ul class="card">
                    @forelse ($resources as $resource)
                        <li wire:key="res-{{ $resource['id'] }}" class="card-row flex-wrap sm:flex-nowrap">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $resource['name'] }}</p>
                                <p class="mt-0.5 truncate text-[13px] text-gray-500">Kimlik {{ $resource['external_id'] }}, {{ $resource['last_collection'] }}</p>
                            </div>
                            @if (($resource['state'] ?? '') === 'available')
                                <button type="button" wire:click="openBind('{{ $resource['id'] }}')" class="btn w-full sm:w-auto">Markaya bağla…</button>
                            @elseif (($resource['state'] ?? '') === 'bound' && ! empty($resource['asset_url']))
                                <a href="{{ $resource['asset_url'] }}" wire:navigate class="btn-quiet w-full sm:w-auto">Varlığı aç</a>
                            @endif
                        </li>
                    @empty
                        <li><x-ta.empty-state icon="none" size="sm" title="Bu filtrelerle eşleşen kaynak yok." /></li>
                    @endforelse
                </ul>
            </section>
        @endif
    @endif

    @if ($displayTab === 'bindings')
        <ul class="card">
            @forelse ($bindings as $binding)
                <li class="card-row flex-wrap sm:flex-nowrap">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $binding['name'] }} <span class="font-normal text-gray-500">{{ $binding['external_id'] }}</span></p>
                        <p class="mt-0.5 truncate text-[13px] text-gray-500">Dijital varlık: {{ $binding['asset_name'] }}</p>
                    </div>
                    @if (! empty($binding['asset_url']))<a href="{{ $binding['asset_url'] }}" wire:navigate class="btn-quiet w-full sm:w-auto">Varlığı aç</a>@endif
                </li>
            @empty
                <li><x-ta.empty-state icon="none" size="sm" title="Henüz bağlantı yok." /></li>
            @endforelse
        </ul>
    @endif

    @if ($displayTab === 'data')
        <section>
            <div class="section-head">
                <h2 class="section-title">{{ $isCentralGoogle ? 'Merkezi veri havuzu' : 'Veri önizlemesi' }}</h2>
                @unless ($isCentralGoogle)<span class="ml-auto text-[13px] text-gray-500">Son veri {{ $data['data']['latest_through'] }}</span>@endunless
            </div>
            @if ($data['data']['metrics'] !== [])
                <div class="metric-strip grid-cols-2 lg:grid-cols-3">
                    @foreach ($data['data']['metrics'] as $metric)
                        <x-ta.metric-card :label="$metricWords[$metric['label']][0] ?? $metric['label']" :value="$valueText((string) $metric['value'])" :hint="$metricWords[$metric['label']][1] ?? $metric['state']" />
                    @endforeach
                </div>
            @endif
            <p class="mt-3 text-[13px] text-gray-500 dark:text-gray-400">{{ $noteWords[$data['data']['note']] ?? $data['data']['note'] }}</p>
            @if ($isGsc)
                <details class="card mt-4">
                    <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">Toplanan veri grupları</summary>
                    <p class="border-t border-gray-200 px-4 py-3 text-[13px] text-gray-600 dark:border-gray-800 dark:text-gray-300">Günlük performans, sorgular, sayfalar, sorgu×sayfa, cihaz, ülke, sayfa×cihaz, sayfa×ülke, sorgu×cihaz, sorgu×ülke, arama görünümü, arama görünümü×sayfa, sitemap ve mülk metadatası. URL Inspection kota sınırlı anlık veri olduğu için toplu 486 günlük aktarımın parçası değildir.</p>
                </details>
            @endif
        </section>
    @endif

    @if ($displayTab === 'sync')
        <section>
            <div class="section-head"><h2 class="section-title">Veri çekimi</h2></div>
            <dl class="card">
                <div class="card-row flex-wrap"><dt class="w-44 shrink-0 text-[13px] text-gray-500">Son başarılı çekim</dt><dd class="min-w-0 flex-1 text-sm text-gray-900 dark:text-white">{{ $data['sync']['last_success'] }}</dd></div>
                <div class="card-row flex-wrap"><dt class="w-44 shrink-0 text-[13px] text-gray-500">Son deneme</dt><dd class="min-w-0 flex-1 text-sm text-gray-900 dark:text-white">{{ $data['sync']['last_attempt'] }}</dd></div>
                <div class="card-row flex-wrap"><dt class="w-44 shrink-0 text-[13px] text-gray-500">Durum</dt><dd class="min-w-0 flex-1 text-sm text-gray-900 dark:text-white">{{ \App\Support\Integrations\Presentation\OperatorLabel::turkish($data['sync']['status']) }}</dd></div>
                <div class="card-row flex-wrap"><dt class="w-44 shrink-0 text-[13px] text-gray-500">Rapor saat dilimi</dt><dd class="min-w-0 flex-1 text-sm text-gray-900 dark:text-white">{{ \App\Support\Integrations\Presentation\OperatorLabel::turkish($data['sync']['timezone']) }}</dd></div>
            </dl>
            <button type="button" wire:click="refreshCollection" class="btn mt-4 w-full sm:w-auto">Veri çekimi geçmişini aç</button>
        </section>
    @endif

    @if ($displayTab === 'activity')
        @if ($isGa4)<livewire:demo.integrations.ga4-collection-monitor />@endif
        @if ($isGsc)<livewire:demo.integrations.gsc-collection-monitor />@endif
        @if ($isCentralGoogle)
            <section wire:poll.15s.visible>
                <div class="section-head"><h2 class="section-title">Aktarım geçmişi <span class="section-count">{{ count($data['activity']) }}</span></h2></div>
                <ul class="card">
                    @forelse ($data['activity'] as $event)
                        @php $rawEvent = (string) ($event['event'] ?? ''); $isSuccess = str_contains($rawEvent, 'Completed'); $isProblem = str_contains($rawEvent, 'Failed') || str_contains($rawEvent, 'Partial'); $isStopped = str_contains($rawEvent, 'Cancelled'); @endphp
                        <li class="card-row flex-wrap sm:flex-nowrap">
                            <span @class(['h-1.5 w-1.5 shrink-0 rounded-full', 'bg-success-500' => $isSuccess, 'bg-warning-500' => $isProblem, 'bg-gray-300' => $isStopped, 'bg-gray-400' => ! $isSuccess && ! $isProblem && ! $isStopped]) aria-hidden="true"></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $eventText($rawEvent) }}</p>
                                <p class="mt-0.5 truncate text-[13px] text-gray-500">{{ $detailText((string) $event['detail']) }}</p>
                            </div>
                            <span class="shrink-0 pl-4 text-xs text-gray-500 sm:pl-0">{{ $event['when'] }}</span>
                        </li>
                    @empty
                        <li><x-ta.empty-state icon="none" size="sm" title="Henüz {{ $isGsc ? 'Search Console' : 'GA4' }} aktarım geçmişi yok." /></li>
                    @endforelse
                </ul>
            </section>
        @else
            <ul class="card">
                @forelse ($data['activity'] as $event)
                    <li class="card-row flex-wrap sm:flex-nowrap">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $eventText((string) $event['event']) }}</p>
                            <p class="mt-0.5 truncate text-[13px] text-gray-500">{{ $detailText((string) $event['detail']) }}</p>
                        </div>
                        <span class="shrink-0 text-xs text-gray-500">{{ $event['when'] }}</span>
                    </li>
                @empty
                    <li><x-ta.empty-state icon="none" size="sm" title="Bu bağlayıcıda kayıtlı veri çekimi yok." /></li>
                @endforelse
            </ul>
        @endif
    @endif
</div>
