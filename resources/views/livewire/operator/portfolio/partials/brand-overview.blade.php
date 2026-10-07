{{-- Özet: period numbers, the brand's digital assets with their data status, open work (channel filter) and services. --}}
@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $panel = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $missingSetup = collect($checklist['items'])->where('required', true)->where('done', false)->values();
    $reviewSetup = collect($checklist['items'])->where('required', true)->where('done', true)->where('review', true)->values();
    $chip = fn (bool $active): string => $active
        ? 'inline-flex h-8 items-center gap-1.5 rounded-full bg-gray-900 px-3 text-xs font-semibold text-white dark:bg-white dark:text-gray-900'
        : 'inline-flex h-8 items-center gap-1.5 rounded-full bg-white px-3 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-gray-800';
@endphp
<div class="space-y-4" data-brand-overview>
    @unless ($operational)
        <p class="rounded-xl bg-gray-50 p-3 text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-white/[0.03] dark:text-gray-400 dark:ring-gray-800" data-brand-not-served>{{ \App\Support\ServiceScope::NOT_SERVED }}</p>
    @endunless

    @if ($missingSetup->isNotEmpty() || $reviewSetup->isNotEmpty())
        {{-- Marka eksikleri (yakup, 2026-10-07): every fact the system needs, red = missing, amber = filled automatically and not checked yet. --}}
        <section class="{{ $card }}" data-setup-missing>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Marka eksikleri · {{ $checklist['done'] }}/{{ $checklist['total'] }}</h2>
                    <p class="text-xs text-gray-500">Tamamlanınca içerik fikirleri, küme eşleşmesi, kampanya kararları ve raporlar tam veriyle çalışır.</p>
                </div>
                <a href="{{ route('operator.brand.setup', ['brand' => $brandModel->id]) }}" wire:navigate class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600">Otomatik kur</a>
            </div>
            <ul class="mt-3 divide-y divide-gray-100 text-xs dark:divide-gray-800">
                @foreach ($missingSetup->concat($reviewSetup) as $item)
                    @php $missing = ! $item['done']; @endphp
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2" data-setup-item="{{ $item['key'] }}" data-setup-state="{{ $missing ? 'missing' : 'review' }}">
                        <span @class(['h-2 w-2 shrink-0 rounded-full', 'bg-red-500' => $missing, 'bg-amber-400' => ! $missing])></span>
                        <span class="w-40 shrink-0 font-semibold text-gray-900 dark:text-white">{{ $item['label'] }}</span>
                        <span @class(['min-w-0 flex-1', 'text-red-700 dark:text-red-300' => $missing, 'text-amber-700 dark:text-amber-300' => ! $missing])>{{ $item['detail'] }}</span>
                        <button type="button" wire:click="setTab('{{ $item['fix'] }}')" class="shrink-0 font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ $missing ? 'Düzelt' : 'Kontrol et' }} →</button>
                        @if (! $missing && $isAdmin)
                            <button type="button" wire:click="confirmChecked('{{ $item['key'] }}')" class="shrink-0 rounded-md px-2 py-0.5 font-semibold text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-gray-800" data-confirm-checked="{{ $item['key'] }}">Kontrol ettim</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Numbers: each against the previous period of the same length --}}
    <section aria-labelledby="kpi-heading" data-brand-kpis>
        <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2 text-xs text-gray-500">
            <h2 id="kpi-heading" class="text-sm font-semibold text-gray-900 dark:text-white">Son {{ $days }} gün</h2>
            <span>önceki {{ $days }} günle karşılaştırma · her kaynak kendi son veri gününe kadar</span>
        </div>
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            @foreach ($kpis as $kpi)
                <div class="{{ $card }}" data-kpi="{{ $kpi['key'] }}" data-state="{{ $kpi['state'] }}">
                    <p class="truncate text-xs text-gray-500">{{ $kpi['label'] }}</p>
                    @if ($kpi['state'] === 'ok')
                        <p class="truncate text-2xl font-semibold tabular-nums text-gray-900 dark:text-white" title="{{ $kpi['value'] }}">{{ $kpi['value'] }}</p>
                        <p class="text-xs">
                            @if ($kpi['delta'] !== null)
                                <span @class(['font-medium', 'text-emerald-600' => $kpi['delta'] > 0, 'text-rose-600' => $kpi['delta'] < 0, 'text-gray-500' => $kpi['delta'] === 0])>{{ $kpi['delta'] > 0 ? '▲ +' : ($kpi['delta'] < 0 ? '▼ ' : '') }}%{{ abs($kpi['delta']) }}</span>
                            @else
                                <span class="text-gray-400">karşılaştırma yok</span>
                            @endif
                        </p>
                    @else
                        <p class="text-lg font-semibold text-gray-300 dark:text-gray-600">veri yok</p>
                    @endif
                    <p class="mt-1 text-[11px] leading-snug text-gray-500">{{ $kpi['note'] }}</p>
                    <p class="mt-1 flex items-center justify-between gap-2 text-[11px]">
                        <span class="truncate text-gray-400">{{ $kpi['source'] }}</span>
                        @if ($kpi['action'])<a href="{{ $kpi['action']['url'] }}" wire:navigate class="shrink-0 font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $kpi['action']['label'] }} →</a>@endif
                    </p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Channel filter: the channel tabs' content until each channel has its own tab --}}
    <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0" data-channel-filter>
        <div class="flex min-w-max items-center gap-2" role="group" aria-label="Kanal">
            <button type="button" wire:click="setChannel('')" aria-pressed="{{ $channelFilter === null ? 'true' : 'false' }}" class="{{ $chip($channelFilter === null) }}">Tüm kanallar <span class="tabular-nums opacity-70">{{ $workTotal }}</span></button>
            @foreach ($channelCounts as $channelKey => $channelRow)
                <button type="button" wire:click="setChannel('{{ $channelKey }}')" data-channel="{{ $channelKey }}" aria-pressed="{{ ($channelFilter['key'] ?? null) === $channelKey ? 'true' : 'false' }}" class="{{ $chip(($channelFilter['key'] ?? null) === $channelKey) }}">{{ $channelRow['label'] }} <span class="tabular-nums opacity-70">{{ $channelRow['count'] }}</span></button>
            @endforeach
        </div>
    </div>

    <div class="grid gap-4 xl:grid-cols-3">
        {{-- Digital assets --}}
        <section class="space-y-2 xl:col-span-2" aria-labelledby="assets-heading" data-brand-assets>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="assets-heading" class="text-sm font-semibold text-gray-900 dark:text-white">{{ $channelFilter !== null ? $channelFilter['label'].' · veri kaynakları' : 'Dijital varlıklar' }} <span class="font-normal text-gray-400">{{ count($assetCards) }}</span></h2>
                <span class="flex items-center gap-3 text-xs">
                    <button type="button" wire:click="setTab('varliklar')" class="font-medium text-brand-600 hover:underline dark:text-brand-400">Hesap bağla</button>
                    <a href="{{ route('operator.asset.create', ['brandId' => $brandModel->id]) }}" wire:navigate class="font-medium text-brand-600 hover:underline dark:text-brand-400">Varlık ekle</a>
                </span>
            </div>
            @if ($assetCards === [] && $channelFilter !== null)
                <div class="{{ $card }} flex flex-wrap items-center justify-between gap-3" data-channel-missing>
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">Markaya bağlı {{ $channelFilter['asset_label'] }} yok.</p>
                        <p class="text-xs text-gray-500">Bağlanmadan bu kanal için veri toplanmaz ve öneri üretilmez. Markada yoksa sorun değil.</p>
                    </div>
                    <span class="flex items-center gap-2">
                        <a href="{{ route('operator.brand', ['brand' => $brandModel->id, 'tab' => 'varliklar']) }}" wire:navigate class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600">Hesap bağla</a>
                        <a href="{{ route('operator.asset.create', ['brandId' => $brandModel->id]) }}" wire:navigate class="rounded-lg px-3 py-1.5 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Varlık ekle</a>
                    </span>
                </div>
            @elseif ($assetCards === [])
                <div class="{{ $card }} text-gray-500">
                    Henüz dijital varlık yok. Web sitesini ekleyip Google / Meta hesaplarını bağlayınca rakamlar burada görünür.
                    <a href="{{ route('operator.brand.setup', ['brand' => $brandModel->id]) }}" wire:navigate class="ml-1 font-medium text-brand-600 hover:underline">Otomatik kur →</a>
                </div>
            @else
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($assetCards as $asset)
                        @include('livewire.operator.portfolio.partials.asset-card', ['asset' => $asset])
                    @endforeach
                </div>
            @endif
        </section>

        <div class="space-y-4">
            {{-- Open work --}}
            <section class="{{ $panel }}" aria-labelledby="work-heading" data-brand-work>
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                    <h2 id="work-heading" class="text-sm font-semibold text-gray-900 dark:text-white">{{ $channelFilter !== null ? $channelFilter['label'].' · açık öneriler' : 'Açık işler' }} <span class="font-normal text-gray-400">{{ $work['total'] }}</span></h2>
                </div>
                @forelse ($work['items'] as $item)
                    <div wire:key="work-{{ $item['id'] }}" class="border-b border-gray-100 px-4 py-3 last:border-0 dark:border-gray-800" data-work-item="{{ $item['id'] }}">
                        <div class="flex items-start justify-between gap-3">
                            <p class="min-w-0 font-medium text-gray-900 dark:text-white">@if ($channelFilter === null)<span class="mr-1 rounded bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium text-gray-500 dark:bg-gray-800">{{ $item['channel_label'] }}</span>@endif{{ $item['title'] }}</p>
                            @if ($item['url'])<a href="{{ $item['url'] }}" wire:navigate class="shrink-0 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Aç →</a>@endif
                        </div>
                        @if ($item['reason'] !== '')<p class="mt-1 line-clamp-2 text-xs text-gray-500">{{ $item['reason'] }}</p>@endif
                    </div>
                @empty
                    <p class="px-4 py-3 text-gray-500" data-work-empty>{{ $channelFilter !== null ? 'Bu kanalda açık öneri yok.' : 'Açık öneri yok. Kanal analizleri yeni iş bulduğunda burada görünür.' }}@if ($channelFilter !== null && $assetCards === []) Önce {{ $channelFilter['asset_label'] }} bağlanmalı.@endif</p>
                @endforelse
                @if ($work['total'] > count($work['items']))
                    <button type="button" wire:click="showAllWork" class="block w-full px-4 py-2 text-left text-xs font-medium text-brand-600 hover:bg-gray-50 dark:text-brand-400 dark:hover:bg-white/[0.03]" data-work-more>+{{ $work['total'] - count($work['items']) }} iş daha · tümünü göster</button>
                @elseif ($allWork && $work['total'] > \App\Livewire\Operator\Portfolio\BrandShow::WORK_LIMIT)
                    <button type="button" wire:click="showAllWork(false)" class="block w-full px-4 py-2 text-left text-xs font-medium text-gray-500 hover:bg-gray-50 dark:hover:bg-white/[0.03]">Daha az göster</button>
                @endif
            </section>

            {{-- Services: read-only here; edited under Ayarlar --}}
            <section class="{{ $panel }}" aria-labelledby="services-heading" data-brand-services>
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                    <h2 id="services-heading" class="text-sm font-semibold text-gray-900 dark:text-white">Hizmetler <span class="font-normal text-gray-400">{{ $serviceSummary['total'] }}</span></h2>
                    <button type="button" wire:click="setTab('ayarlar')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Düzenle →</button>
                </div>
                @if ($serviceSummary['total'] > 0)
                    <p class="px-4 pt-3 text-xs text-gray-500">{{ $serviceSummary['priority'] }} ana (★) · {{ $serviceSummary['mapped'] }}/{{ $serviceSummary['total'] }} hizmetin sitede sayfası eşlendi</p>
                    <ul class="px-4 py-2">
                        @foreach ($serviceSummary['rows'] as $service)
                            <li wire:key="svc-{{ $service['id'] }}" class="py-1.5" data-service-row="{{ $service['id'] }}">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="min-w-0 truncate text-gray-900 dark:text-white">@if ($service['is_priority'])<span class="text-amber-500" title="Ana hizmet">★</span> @endif{{ $service['name'] }}</span>
                                    <span @class(['shrink-0 text-xs', 'text-gray-500' => $service['pages'] > 0, 'text-amber-700 dark:text-amber-400' => $service['pages'] === 0])>{{ $service['pages'] > 0 ? $service['pages'].' sayfa' : 'Sayfa eşlenmedi' }}</span>
                                </div>
                                @if ($service['hub'])
                                    <a href="{{ route('operator.website', ['assetId' => $service['hub']['website_asset_id'], 'tab' => 'eslestirme']) }}" wire:navigate class="block truncate text-xs text-gray-500 hover:text-brand-600" title="Ana sayfa: {{ $service['hub']['url'] }} · eşleşmeyi değiştir" data-service-hub>{{ $service['hub']['path'] }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if ($serviceSummary['total'] > count($serviceSummary['rows']))<button type="button" wire:click="setTab('ayarlar')" class="px-4 pb-3 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">+{{ $serviceSummary['total'] - count($serviceSummary['rows']) }} hizmet daha →</button>@endif
                @else
                    <p class="px-4 py-3 text-gray-500">Markaya hizmet eklenmedi. "Otomatik kur" siteden önerir ya da Ayarlar → Marka bilgileri'nden eklenir.</p>
                @endif
            </section>
        </div>
    </div>
</div>
