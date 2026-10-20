@php
    $select = 'h-9 min-w-0 rounded-lg border border-gray-200 bg-white px-2.5 text-sm text-gray-700 shadow-card focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-200';
    $chip = ['ok' => 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300', 'warn' => 'bg-warning-50 text-warning-800 dark:bg-warning-500/10 dark:text-warning-300', 'bad' => 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-300', 'muted' => 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300'];
    // Page-local badge colours: red only for lost money / traffic, amber "look at it", grey information, green current.
    $badge = ['loss' => $chip['bad'], 'warn' => $chip['warn'], 'ok' => $chip['ok'], 'muted' => $chip['muted']];
    $pillTone = ['loss' => 'critical', 'warn' => 'look', 'ok' => 'ok', 'muted' => 'info'];
    $dot = ['loss' => 'bg-error-500', 'warn' => 'bg-warning-500', 'ok' => 'bg-success-500', 'muted' => 'bg-gray-400'];
    $quickViews = ['all' => 'Tümü', 'needs_attention' => 'Dikkat istiyor', 'data_issues' => 'Veri sorunu', 'active_work' => 'Açık işi olan', 'recent' => 'Son güncellenen'];
    $views = ['matrix' => 'Marka × kanal', 'table' => 'Liste', 'cards' => 'Kart'];
@endphp
<div class="space-y-7" data-assets-index>
    @include('livewire.demo.partials.flash')

    <header class="flex flex-wrap items-end justify-between gap-x-6 gap-y-4">
        <div class="min-w-0">
            <h1 class="page-title" title="Markalara bağlı siteler ve hesaplar. Veri durumu her kaynağın en kötü durumunu gösterir.">Dijital <span class="highlight">varlıklar</span></h1>
            <p class="page-sub mt-1.5!" data-assets-summary><span class="tabular-nums">{{ $glance['managed'] }}</span> varlık, markalara bağlı siteler ve hesaplar</p>
        </div>
        <a href="{{ route('operator.asset.create', $filterBrand !== '' ? ['brandId' => $filterBrand] : []) }}" wire:navigate class="btn-primary">Varlık ekle</a>
    </header>

    {{-- Canlı panel (2026-10-20): what needs a look first, as tinted cards; each opens its quick view. --}}
    @if ($quickView === 'all' && ($glance['needs_attention'] + $glance['data_issues'] + $glance['active_work']) > 0)
        @php
            $glanceCards = [
                ['key' => 'needs_attention', 'tone' => $glance['needs_attention'] > 0 ? 'soft-amber' : '', 'title' => 'Dikkat istiyor', 'line' => 'Bağlantısı, verisi ya da kritik önerisi bakılmalı.',
                    'icon' => '<path d="M12 8v5M12 16.5v.5"/><path d="M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>'],
                ['key' => 'data_issues', 'tone' => '', 'title' => 'Veri sorunu', 'line' => 'Verisi gecikmiş ya da gelmeyen kaynaklar.',
                    'icon' => '<ellipse cx="12" cy="5.5" rx="7" ry="2.5"/><path d="M5 5.5v13c0 1.4 3.1 2.5 7 2.5s7-1.1 7-2.5v-13M5 12c0 1.4 3.1 2.5 7 2.5s7-1.1 7-2.5"/>'],
                ['key' => 'active_work', 'tone' => '', 'title' => 'Açık işi olan', 'line' => 'Bekleyen önerisi olan varlıklar.',
                    'icon' => '<path d="M9 6h11M9 12h11M9 18h11"/><path d="m4 6 .5.5L5.5 5M4 12l.5.5 1-1.5M4 18l.5.5 1-1.5"/>'],
            ];
        @endphp
        <section class="grid grid-cols-2 gap-3 lg:grid-cols-3" data-assets-glance>
            @foreach ($glanceCards as $card)
                <div @class(['soft-card', $card['tone'], 'col-span-2 lg:col-span-1' => $loop->first]) data-glance="{{ $card['key'] }}">
                    <span class="soft-icon {{ $card['tone'] === 'soft-red' ? 'text-error-600 dark:text-error-400' : ($card['tone'] === 'soft-amber' ? 'text-warning-600 dark:text-warning-400' : '') }}" aria-hidden="true"><svg class="h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">{!! $card['icon'] !!}</svg></span>
                    <p class="soft-number">{{ $glance[$card['key']] }} varlık</p>
                    <p class="text-[15px] font-semibold tracking-[-0.01em] text-gray-900 dark:text-white">{{ $card['title'] }}</p>
                    <p class="hidden text-[13px] text-gray-600 sm:block dark:text-gray-300">{{ $card['line'] }}</p>
                    @if ($glance[$card['key']] > 0)
                        <div class="mt-auto pt-1"><button type="button" wire:click="setQuickView('{{ $card['key'] }}')" class="btn">Göster</button></div>
                    @endif
                </div>
            @endforeach
        </section>
    @endif

    @php $quickCounts = ['needs_attention' => $glance['needs_attention'], 'data_issues' => $glance['data_issues'], 'active_work' => $glance['active_work']]; @endphp
    <nav class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3" aria-label="Görünüm">
        <div class="no-scrollbar -mx-4 flex w-[calc(100%+2rem)] items-center gap-1.5 overflow-x-auto px-4 sm:mx-0 sm:w-auto sm:px-0">
            @foreach ($quickViews as $key => $label)
                <button type="button" wire:click="setQuickView('{{ $key }}')" aria-pressed="{{ $quickView === $key ? 'true' : 'false' }}"
                    @class(['seg', 'seg-active' => $quickView === $key])>{{ $label }}@isset($quickCounts[$key]) <span @class(['tabular-nums text-xs', 'text-accent' => $quickView === $key, 'text-gray-400 dark:text-gray-500' => $quickView !== $key])>{{ $quickCounts[$key] }}</span>@endisset</button>
            @endforeach
        </div>
        <div class="inline-flex items-center gap-0.5 rounded-lg border border-gray-200 bg-white p-0.5 shadow-card dark:border-gray-800 dark:bg-white/[0.03]" role="group" aria-label="Görünüm türü">
            @foreach ($views as $key => $label)
                <button type="button" wire:click="setViewMode('{{ $key }}')" aria-pressed="{{ $view === $key ? 'true' : 'false' }}"
                    @class(['rounded-md px-2.5 py-1 text-[13px]', 'bg-gray-100 font-medium text-gray-900 dark:bg-white/10 dark:text-white' => $view === $key, 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white' => $view !== $key])>{{ $label }}</button>
            @endforeach
        </div>
    </nav>

    <div class="flex flex-wrap items-center gap-2">
        <label class="relative w-full min-w-0 sm:w-64">
            <svg class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Varlık, alan adı veya marka ara" aria-label="Ara"
                class="h-9 w-full min-w-0 rounded-lg border border-gray-200 bg-white pr-3 pl-9 text-sm shadow-card placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-white/[0.03] dark:text-white" />
        </label>
        <select wire:model.live="filterCustomer" class="{{ $select }} flex-1 sm:max-w-52 sm:flex-none" aria-label="Müşteri">
            <option value="">Müşteri: hepsi</option>
            @foreach ($customerOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="filterBrand" class="{{ $select }} flex-1 sm:max-w-52 sm:flex-none" aria-label="Marka">
            <option value="">Marka: hepsi</option>
            @foreach ($brandOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
        </select>
        @if ($view !== 'matrix')
            <select wire:model.live="filterType" class="{{ $select }} flex-1 sm:max-w-52 sm:flex-none" aria-label="Tür">
                <option value="">Tür: hepsi</option>
                @foreach ($typeOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
            </select>
            <select wire:model.live="filterDataState" class="{{ $select }} flex-1 sm:max-w-52 sm:flex-none" aria-label="Veri durumu">
                <option value="">Veri: hepsi</option>
                @foreach ($dataStateOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
            </select>
            <select wire:model.live="filterOperational" class="{{ $select }} flex-1 sm:max-w-52 sm:flex-none" aria-label="Durum">
                <option value="">Durum: hepsi</option>
                @foreach ($operationalOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
            </select>
            <select wire:model.live="filterResponsible" class="{{ $select }} flex-1 sm:max-w-52 sm:flex-none" aria-label="Sorumlu">
                <option value="">Sorumlu: herkes</option>
                @foreach ($responsibleOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
            </select>
        @endif
        <button type="button" wire:click="clearFilters" class="btn-quiet">Temizle</button>
    </div>

    @if ($view === 'matrix')
        <section data-estate-matrix>
            <div class="card relative overflow-x-auto">
            <table class="data-table">
                <caption class="sr-only">Marka ve kanal matrisi</caption>
                <thead>
                    <tr>
                        <th scope="col">Marka</th>
                        @foreach ($matrix['columns'] as $column => $label)<th scope="col">{{ $label }}</th>@endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($matrix['rows'] as $row)
                        <tr wire:key="matrix-{{ $row['brand_id'] }}" class="group" data-matrix-row="{{ $row['brand_id'] }}">
                            <th scope="row" class="bg-transparent! py-3! text-sm! font-normal! whitespace-normal! text-gray-900! group-hover:bg-gray-50/70! dark:text-white! dark:group-hover:bg-white/[0.02]!">
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <x-ta.brand-avatar :name="$row['brand']" :logo="$row['logo_url'] ?? null" />
                                    <div class="min-w-0">
                                        <a href="{{ route('operator.assets', ['brand' => $row['brand_id']]) }}" wire:navigate class="break-words font-medium text-gray-900 hover:underline dark:text-white">{{ $row['brand'] }}</a>
                                        {{-- The customer only when it is not the brand's own name (2026-10-19: it was the same name under itself). --}}
                                        @if (filled($row['customer']) && mb_strtolower(trim((string) $row['customer'])) !== mb_strtolower(trim((string) $row['brand'])))<p class="break-words text-[13px] text-gray-500">{{ $row['customer'] }}</p>@endif
                                    </div>
                                </div>
                            </th>
                            @foreach ($matrix['columns'] as $column => $label)
                                @php($cell = $row['cells'][$column] ?? ['count' => 0])
                                <td data-matrix-cell="{{ $column }}" data-tone="{{ $cell['tone'] ?? 'none' }}">
                                    @if (($cell['count'] ?? 0) === 0)
                                        <span class="text-gray-300 dark:text-gray-600" aria-hidden="true">—</span><span class="sr-only">Tanımlı değil</span>
                                    @else
                                        <a href="{{ $cell['url'] }}" wire:navigate title="{{ $cell['title'] }}" class="inline-flex items-center gap-2 whitespace-nowrap rounded-md px-1.5 py-0.5 text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5">
                                            <span class="h-2 w-2 shrink-0 rounded-full {{ $dot[$cell['tone']] ?? $dot['muted'] }}" aria-hidden="true"></span>
                                            <span class="tabular-nums">{{ $cell['count'] }}</span>
                                            <span class="sr-only">{{ $cell['label'] }}</span>
                                        </a>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($matrix['columns']) + 1 }}" class="py-6 text-center text-[13px] text-gray-500">Filtreye uyan marka yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
            <p class="flex flex-wrap items-center gap-x-4 gap-y-1 pt-3 text-xs text-gray-500">
                <span class="inline-flex items-center gap-1.5"><span class="h-1.5 w-1.5 rounded-full {{ $dot['ok'] }}"></span>Güncel</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-1.5 w-1.5 rounded-full {{ $dot['warn'] }}"></span>Bakılmalı</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-1.5 w-1.5 rounded-full {{ $dot['loss'] }}"></span>Yayın durdu / engelli</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-1.5 w-1.5 rounded-full {{ $dot['muted'] }}"></span>Pasif / bağlı değil</span>
                <span>Sayı: hesap adedi; GA4 / GSC sütunu web sitelerine bağlı kaynakları sayar. Üzerine gelince ayrıntı görünür.</span>
            </p>
        </section>
    @elseif (count($assets) === 0)
        <section class="card">
            <x-ta.empty-state size="sm" title="Filtreye uyan varlık yok">
                <button type="button" wire:click="clearFilters" class="btn">Filtreleri temizle</button>
            </x-ta.empty-state>
        </section>
    @elseif ($view === 'cards')
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($assets as $asset)
                <section class="card lift flex flex-col" wire:key="asset-card-{{ $asset['id'] }}">
                    <div class="flex items-start gap-3 px-4 pt-3.5">
                        <x-demo.digital-asset-mark :type="$asset['type']" :asset="$asset" size="md" />
                        <div class="min-w-0">
                            <a href="{{ $asset['url'] }}" wire:navigate class="text-sm font-medium text-gray-900 hover:underline dark:text-white">{{ $asset['display_name'] }}</a>
                            <p class="mt-0.5 text-[13px] text-gray-500">{{ $asset['type_label'] }}@if ($asset['stored_name']), {{ $asset['stored_name'] }}@endif</p>
                            <p class="text-[13px] text-gray-500"><a href="{{ route('operator.brand', ['brand' => $asset['brand_id']]) }}" wire:navigate class="hover:underline">{{ $asset['brand_name'] }}</a>@if (mb_strtolower(trim((string) $asset['customer_name'])) !== mb_strtolower(trim((string) $asset['brand_name']))), {{ $asset['customer_name'] }}@endif</p>
                        </div>
                    </div>
                    <div class="flex flex-1 flex-wrap items-center gap-x-3 gap-y-1 px-4 pt-3 pb-3.5 text-xs">
                        <x-ta.pill :tone="$pillTone[$asset['badge']['tone']] ?? 'info'" title="{{ $asset['badge']['title'] }}">{{ $asset['badge']['label'] }}</x-ta.pill>
                        @if ($asset['operational_status'] !== 'active')<span class="text-gray-500">{{ $asset['operational_status_label'] }}</span>@endif
                        <span class="text-gray-500"><span class="tabular-nums">{{ $asset['open_work'] }}</span> açık iş @if ($asset['critical'] > 0)· <span class="font-medium text-warning-700 dark:text-warning-300">{{ $asset['critical'] }} kritik</span>@endif</span>
                    </div>
                    <p class="flex items-center justify-between gap-2 border-t border-gray-200 px-4 py-2.5 dark:border-gray-800">
                        <a href="{{ $asset['sources_url'] }}" wire:navigate class="text-[13px] text-gray-500 hover:text-gray-900 hover:underline dark:hover:text-white">Veri kaynakları</a>
                        <a href="{{ $asset['url'] }}" wire:navigate class="btn h-7! px-2.5! text-xs!">Aç</a>
                    </p>
                </section>
            @endforeach
        </div>
    @else
        <section class="card relative overflow-x-auto">
            <table class="data-table">
                <caption class="sr-only">Dijital varlıklar</caption>
                <thead>
                    <tr>
                        <th scope="col">Varlık</th>
                        <th scope="col" class="hidden md:table-cell">Marka</th>
                        <th scope="col">Veri</th>
                        <th scope="col" class="text-right!">Açık iş</th>
                        <th scope="col" class="hidden sm:table-cell">Durum</th>
                        <th scope="col" class="hidden xl:table-cell">Sorumlu</th>
                        <th scope="col" class="hidden lg:table-cell">Son çekim</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($assets as $asset)
                        {{-- The whole row opens the asset; links and buttons inside keep their own target. --}}
                        <tr class="cursor-pointer" wire:key="asset-{{ $asset['id'] }}" data-asset-row="{{ $asset['id'] }}"
                            x-data x-on:click="if (! $event.target.closest('a, button')) { Livewire.navigate(@js($asset['url'])) }">
                            <td>
                                <div class="flex items-center gap-2.5">
                                    <x-demo.digital-asset-mark :type="$asset['type']" :asset="$asset" size="sm" />
                                    <div class="min-w-0">
                                        <a href="{{ $asset['url'] }}" wire:navigate class="font-medium text-gray-900 hover:underline dark:text-white">{{ $asset['display_name'] }}</a>
                                        <p class="text-[13px] text-gray-500">{{ $asset['type_label'] }}@if ($asset['stored_name']), {{ $asset['stored_name'] }}@endif<span class="md:hidden">, {{ $asset['brand_name'] }}</span></p>
                                    </div>
                                </div>
                            </td>
                            <td class="hidden md:table-cell">
                                <a href="{{ route('operator.brand', ['brand' => $asset['brand_id']]) }}" wire:navigate class="text-gray-700 hover:underline dark:text-gray-300">{{ $asset['brand_name'] }}</a>
                                @if (mb_strtolower(trim((string) $asset['customer_name'])) !== mb_strtolower(trim((string) $asset['brand_name'])))<p class="text-xs text-gray-500">{{ $asset['customer_name'] }}</p>@endif
                            </td>
                            <td data-asset-data="{{ $asset['tone'] }}">
                                <span class="inline-flex items-center gap-1.5 whitespace-nowrap">
                                    <x-ta.pill :tone="$pillTone[$asset['badge']['tone']] ?? 'info'" title="{{ $asset['badge']['title'] }}" data-asset-badge="{{ $asset['badge']['tone'] }}">{{ $asset['badge']['label'] }}</x-ta.pill>
                                    <a href="{{ $asset['sources_url'] }}" wire:navigate class="rounded p-0.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/10 dark:hover:text-gray-200" title="Veri kaynakları" aria-label="Veri kaynakları: {{ $asset['display_name'] }}">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><ellipse cx="10" cy="4.5" rx="6" ry="2.5"/><path d="M4 4.5v11c0 1.4 2.7 2.5 6 2.5s6-1.1 6-2.5v-11M4 10c0 1.4 2.7 2.5 6 2.5s6-1.1 6-2.5"/></svg>
                                    </a>
                                </span>
                            </td>
                            <td class="whitespace-nowrap text-right tabular-nums" data-asset-work>
                                {{ $asset['open_work'] }}@if ($asset['critical'] > 0)<span class="text-gray-500">, </span><span class="font-medium text-warning-700 dark:text-warning-300">{{ $asset['critical'] }} kritik</span>@endif
                            </td>
                            <td class="hidden sm:table-cell">
                                <x-ta.pill :tone="$asset['operational_status'] === 'active' ? 'ok' : 'info'">{{ $asset['operational_status_label'] }}</x-ta.pill>
                            </td>
                            <td class="hidden xl:table-cell">
                                @php($owner = $asset['responsible_users'][0] ?? null)
                                @if ($owner)
                                    <span class="inline-flex items-center gap-2" title="{{ collect($asset['responsible_users'])->pluck('name')->implode(', ') }}">
                                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-100 text-[11px] font-medium text-gray-700 dark:bg-white/10 dark:text-white/90" aria-hidden="true">{{ $owner['initials'] }}</span>
                                        <span class="text-sm text-gray-700 dark:text-gray-300">{{ $owner['name'] }}</span>
                                    </span>
                                @else
                                    <span class="text-gray-500">—</span>
                                @endif
                            </td>
                            <td class="hidden whitespace-nowrap text-xs text-gray-500 lg:table-cell">{{ ($asset['last_update'] ?? '') !== '' ? $asset['last_update'] : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
</div>
