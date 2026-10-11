@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $select = 'h-9 rounded-lg border border-gray-300 bg-white px-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white';
    $chip = ['ok' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300', 'warn' => 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300', 'bad' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300', 'muted' => 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300'];
    // Page-local badge colours: red only for lost money / traffic, amber "look at it", grey information, green current.
    $badge = ['loss' => $chip['bad'], 'warn' => $chip['warn'], 'ok' => $chip['ok'], 'muted' => $chip['muted']];
    $dot = ['loss' => 'bg-rose-500', 'warn' => 'bg-amber-500', 'ok' => 'bg-emerald-500', 'muted' => 'bg-gray-400'];
    $quickViews = ['all' => 'Tümü', 'needs_attention' => 'Dikkat istiyor', 'data_issues' => 'Veri sorunu', 'active_work' => 'Açık işi olan', 'recent' => 'Son güncellenen'];
    $views = ['matrix' => 'Marka × kanal', 'table' => 'Liste', 'cards' => 'Kart'];
@endphp
<div class="space-y-4" data-assets-index>
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Dijital varlıklar</h1>
            <p class="mt-1 text-sm text-gray-500">Markalara bağlı siteler ve hesaplar. Veri durumu her kaynağın en kötü durumunu gösterir.</p>
            <p class="mt-1 text-xs text-gray-500" data-assets-summary>
                <span class="tabular-nums">{{ $glance['managed'] }}</span> varlık ·
                <button type="button" wire:click="setQuickView('needs_attention')" class="hover:underline"><span class="tabular-nums">{{ $glance['needs_attention'] }}</span> dikkat istiyor</button> ·
                <button type="button" wire:click="setQuickView('data_issues')" class="hover:underline"><span class="tabular-nums">{{ $glance['data_issues'] }}</span> veri sorunu</button> ·
                <button type="button" wire:click="setQuickView('active_work')" class="hover:underline"><span class="tabular-nums">{{ $glance['active_work'] }}</span> açık işi olan</button>
            </p>
        </div>
        <a href="{{ route('operator.asset.create', $filterBrand !== '' ? ['brandId' => $filterBrand] : []) }}" wire:navigate class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600">Varlık ekle</a>
    </div>

    <nav class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 border-b border-gray-200 dark:border-gray-800" aria-label="Görünüm">
        <div class="flex flex-wrap items-center gap-x-6">
            @foreach ($quickViews as $key => $label)
                <button type="button" wire:click="setQuickView('{{ $key }}')" aria-pressed="{{ $quickView === $key ? 'true' : 'false' }}"
                    @class(['-mb-px h-11 border-b-2 text-sm', 'border-gray-900 font-semibold text-gray-900 dark:border-white dark:text-white' => $quickView === $key, 'border-transparent font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400' => $quickView !== $key])>{{ $label }}</button>
            @endforeach
        </div>
        <div class="flex items-center gap-1 pb-1 text-xs" role="group" aria-label="Görünüm türü">
            @foreach ($views as $key => $label)
                <button type="button" wire:click="setViewMode('{{ $key }}')" aria-pressed="{{ $view === $key ? 'true' : 'false' }}"
                    @class(['rounded-lg px-2.5 py-1.5 font-medium', 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $view === $key, 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5' => $view !== $key])>{{ $label }}</button>
            @endforeach
        </div>
    </nav>

    <div class="flex flex-wrap items-center gap-2">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Varlık, alan adı veya marka ara" aria-label="Ara"
            class="h-9 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm sm:w-64 dark:border-gray-700 dark:bg-gray-900 dark:text-white" />
        <select wire:model.live="filterCustomer" class="{{ $select }}" aria-label="Müşteri">
            <option value="">Müşteri: hepsi</option>
            @foreach ($customerOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="filterBrand" class="{{ $select }}" aria-label="Marka">
            <option value="">Marka: hepsi</option>
            @foreach ($brandOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
        </select>
        @if ($view !== 'matrix')
            <select wire:model.live="filterType" class="{{ $select }}" aria-label="Tür">
                <option value="">Tür: hepsi</option>
                @foreach ($typeOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
            </select>
            <select wire:model.live="filterDataState" class="{{ $select }}" aria-label="Veri durumu">
                <option value="">Veri: hepsi</option>
                @foreach ($dataStateOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
            </select>
            <select wire:model.live="filterOperational" class="{{ $select }}" aria-label="Durum">
                <option value="">Durum: hepsi</option>
                @foreach ($operationalOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
            </select>
            <select wire:model.live="filterResponsible" class="{{ $select }}" aria-label="Sorumlu">
                <option value="">Sorumlu: herkes</option>
                @foreach ($responsibleOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
            </select>
        @endif
        <button type="button" wire:click="clearFilters" class="text-xs font-medium text-gray-600 hover:underline dark:text-gray-300">Temizle</button>
    </div>

    @if ($view === 'matrix')
        <section class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-estate-matrix>
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Marka ve kanal matrisi</caption>
                <thead class="border-b border-gray-100 text-xs text-gray-500 dark:border-gray-800">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Marka</th>
                        @foreach ($matrix['columns'] as $column => $label)<th scope="col" class="whitespace-nowrap px-4 py-3 font-medium">{{ $label }}</th>@endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($matrix['rows'] as $row)
                        <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.03]" wire:key="matrix-{{ $row['brand_id'] }}" data-matrix-row="{{ $row['brand_id'] }}">
                            <th scope="row" class="px-4 py-2.5 font-normal">
                                <a href="{{ route('operator.assets', ['brand' => $row['brand_id']]) }}" wire:navigate class="font-medium text-gray-900 hover:underline dark:text-white">{{ $row['brand'] }}</a>
                                <p class="text-xs text-gray-500">{{ $row['customer'] }}</p>
                            </th>
                            @foreach ($matrix['columns'] as $column => $label)
                                @php($cell = $row['cells'][$column] ?? ['count' => 0])
                                <td class="px-4 py-2.5" data-matrix-cell="{{ $column }}" data-tone="{{ $cell['tone'] ?? 'none' }}">
                                    @if (($cell['count'] ?? 0) === 0)
                                        <span class="text-gray-300 dark:text-gray-600" aria-hidden="true">—</span><span class="sr-only">Tanımlı değil</span>
                                    @else
                                        <a href="{{ $cell['url'] }}" wire:navigate title="{{ $cell['title'] }}" class="inline-flex items-center gap-2 whitespace-nowrap rounded-md px-1.5 py-0.5 text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5">
                                            <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $dot[$cell['tone']] ?? $dot['muted'] }}" aria-hidden="true"></span>
                                            <span class="tabular-nums">{{ $cell['count'] }}</span>
                                            <span class="sr-only">{{ $cell['label'] }}</span>
                                        </a>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($matrix['columns']) + 1 }}" class="px-4 py-6 text-center text-sm text-gray-500">Filtreye uyan marka yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <p class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-gray-100 px-4 py-3 text-xs text-gray-500 dark:border-gray-800">
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full {{ $dot['ok'] }}"></span>Güncel</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full {{ $dot['warn'] }}"></span>Bakılmalı</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full {{ $dot['loss'] }}"></span>Yayın durdu / engelli</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full {{ $dot['muted'] }}"></span>Pasif / bağlı değil</span>
                <span>Sayı: hesap adedi; GA4 / GSC sütunu web sitelerine bağlı kaynakları sayar. Üzerine gelince ayrıntı görünür.</span>
            </p>
        </section>
    @elseif (count($assets) === 0)
        <section class="{{ $card }} text-center">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Filtreye uyan varlık yok</h2>
            <button type="button" wire:click="clearFilters" class="mt-2 text-sm font-medium text-gray-700 hover:underline dark:text-gray-300">Filtreleri temizle</button>
        </section>
    @elseif ($view === 'cards')
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($assets as $asset)
                <section class="{{ $card }}" wire:key="asset-card-{{ $asset['id'] }}">
                    <div class="flex items-start gap-3">
                        <x-demo.digital-asset-mark :type="$asset['type']" :asset="$asset" size="md" />
                        <div class="min-w-0">
                            <a href="{{ $asset['url'] }}" wire:navigate class="font-medium text-gray-900 hover:underline dark:text-white">{{ $asset['display_name'] }}</a>
                            <p class="text-xs text-gray-500">{{ $asset['type_label'] }}@if ($asset['stored_name']) · {{ $asset['stored_name'] }}@endif</p>
                            <p class="text-xs text-gray-500"><a href="{{ route('operator.brand', ['brand' => $asset['brand_id']]) }}" wire:navigate class="hover:underline">{{ $asset['brand_name'] }}</a> · {{ $asset['customer_name'] }}</p>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                        <span class="whitespace-nowrap rounded-full px-2 py-0.5 font-medium {{ $badge[$asset['badge']['tone']] ?? $chip['muted'] }}" title="{{ $asset['badge']['title'] }}">{{ $asset['badge']['label'] }}</span>
                        @if ($asset['operational_status'] !== 'active')<span class="rounded-full px-2 py-0.5 {{ $chip['muted'] }}">{{ $asset['operational_status_label'] }}</span>@endif
                        <span class="text-gray-500"><span class="tabular-nums">{{ $asset['open_work'] }}</span> açık iş @if ($asset['critical'] > 0)· <span class="font-medium text-amber-700 dark:text-amber-300">{{ $asset['critical'] }} kritik</span>@endif</span>
                    </div>
                    <p class="mt-3 flex gap-3 text-xs">
                        <a href="{{ $asset['url'] }}" wire:navigate class="font-medium text-gray-900 hover:underline dark:text-white">Aç</a>
                        <a href="{{ $asset['sources_url'] }}" wire:navigate class="text-gray-600 hover:underline dark:text-gray-300">Veri kaynakları</a>
                    </p>
                </section>
            @endforeach
        </div>
    @else
        <section class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Dijital varlıklar</caption>
                <thead class="border-b border-gray-100 text-xs text-gray-500 dark:border-gray-800">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Varlık</th>
                        <th scope="col" class="hidden px-4 py-3 font-medium md:table-cell">Marka</th>
                        <th scope="col" class="px-4 py-3 font-medium">Veri</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Açık iş</th>
                        <th scope="col" class="hidden px-4 py-3 font-medium sm:table-cell">Durum</th>
                        <th scope="col" class="hidden px-4 py-3 font-medium xl:table-cell">Sorumlu</th>
                        <th scope="col" class="hidden px-4 py-3 font-medium lg:table-cell">Son çekim</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($assets as $asset)
                        {{-- The whole row opens the asset; links and buttons inside keep their own target. --}}
                        <tr class="cursor-pointer hover:bg-gray-50 dark:hover:bg-white/[0.03]" wire:key="asset-{{ $asset['id'] }}" data-asset-row="{{ $asset['id'] }}"
                            x-data x-on:click="if (! $event.target.closest('a, button')) { Livewire.navigate(@js($asset['url'])) }">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2.5">
                                    <x-demo.digital-asset-mark :type="$asset['type']" :asset="$asset" size="sm" />
                                    <div class="min-w-0">
                                        <a href="{{ $asset['url'] }}" wire:navigate class="font-medium text-gray-900 hover:underline dark:text-white">{{ $asset['display_name'] }}</a>
                                        <p class="text-xs text-gray-500">{{ $asset['type_label'] }}@if ($asset['stored_name']) · {{ $asset['stored_name'] }}@endif<span class="md:hidden"> · {{ $asset['brand_name'] }}</span></p>
                                    </div>
                                </div>
                            </td>
                            <td class="hidden px-4 py-3 md:table-cell">
                                <a href="{{ route('operator.brand', ['brand' => $asset['brand_id']]) }}" wire:navigate class="text-gray-700 hover:underline dark:text-gray-300">{{ $asset['brand_name'] }}</a>
                                <p class="text-xs text-gray-400">{{ $asset['customer_name'] }}</p>
                            </td>
                            <td class="px-4 py-3" data-asset-data="{{ $asset['tone'] }}">
                                <span class="inline-flex items-center gap-1.5 whitespace-nowrap">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $badge[$asset['badge']['tone']] ?? $chip['muted'] }}" title="{{ $asset['badge']['title'] }}" data-asset-badge="{{ $asset['badge']['tone'] }}">{{ $asset['badge']['label'] }}</span>
                                    <a href="{{ $asset['sources_url'] }}" wire:navigate class="rounded p-0.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/10 dark:hover:text-gray-200" title="Veri kaynakları" aria-label="Veri kaynakları: {{ $asset['display_name'] }}">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><ellipse cx="10" cy="4.5" rx="6" ry="2.5"/><path d="M4 4.5v11c0 1.4 2.7 2.5 6 2.5s6-1.1 6-2.5v-11M4 10c0 1.4 2.7 2.5 6 2.5s6-1.1 6-2.5"/></svg>
                                    </a>
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums" data-asset-work>
                                {{ $asset['open_work'] }}@if ($asset['critical'] > 0)<span class="text-gray-400"> · </span><span class="font-medium text-amber-700 dark:text-amber-300">{{ $asset['critical'] }} kritik</span>@endif
                            </td>
                            <td class="hidden px-4 py-3 sm:table-cell">
                                <span @class(['whitespace-nowrap rounded-full px-2 py-0.5 text-xs', $chip['ok'] => $asset['operational_status'] === 'active', $chip['muted'] => $asset['operational_status'] !== 'active'])>{{ $asset['operational_status_label'] }}</span>
                            </td>
                            <td class="hidden px-4 py-3 xl:table-cell">
                                @php($owner = $asset['responsible_users'][0] ?? null)
                                @if ($owner)
                                    <span class="inline-flex items-center gap-2" title="{{ collect($asset['responsible_users'])->pluck('name')->implode(', ') }}">
                                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-100 text-[10px] font-semibold text-gray-700 dark:bg-white/10 dark:text-white/90" aria-hidden="true">{{ $owner['initials'] }}</span>
                                        <span class="text-sm text-gray-700 dark:text-gray-300">{{ $owner['name'] }}</span>
                                    </span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="hidden whitespace-nowrap px-4 py-3 text-xs text-gray-500 lg:table-cell">{{ ($asset['last_update'] ?? '') !== '' ? $asset['last_update'] : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
</div>
