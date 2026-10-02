{{-- Özet: period numbers, the brand's digital assets with their data status, open work and services. --}}
@php
    $panel = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $missingSetup = collect($checklist['items'])->where('required', true)->where('done', false)->pluck('label');
@endphp
<div class="space-y-6" data-brand-overview>
    @unless ($operational)
        <p class="rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-white/[0.03] dark:text-gray-400 dark:ring-gray-800" data-brand-not-served>{{ \App\Support\ServiceScope::NOT_SERVED }}</p>
    @endunless

    @if ($missingSetup->isNotEmpty())
        <section class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-warning-50 px-4 py-3 text-sm ring-1 ring-inset ring-warning-200 dark:bg-warning-500/10 dark:ring-warning-500/20" data-setup-missing>
            <p class="text-warning-800 dark:text-warning-300"><span class="font-semibold">Kurulum {{ $checklist['done'] }}/{{ $checklist['total'] }}</span> · Eksik: {{ $missingSetup->implode(', ') }}</p>
            <span class="flex items-center gap-3">
                <button type="button" wire:click="setTab('overview')" class="text-xs font-medium text-warning-800 hover:underline dark:text-warning-300">Ayrıntılar</button>
                <a href="{{ route('operator.brand.setup', ['brand' => $brandModel->id]) }}" wire:navigate class="rounded-lg bg-success-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-success-600">Otomatik kur</a>
            </span>
        </section>
    @endif

    {{-- KPIs: each against the previous period of the same length --}}
    <section aria-labelledby="kpi-heading" data-brand-kpis>
        <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="kpi-heading" class="text-sm font-semibold text-gray-800 dark:text-white/90">Son {{ $days }} gün</h2>
            <p class="text-xs text-gray-400">Önceki {{ $days }} günle karşılaştırma · her kaynak kendi son veri gününe kadar</p>
        </div>
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            @foreach ($kpis as $kpi)
                <div class="{{ $panel }} p-4" data-kpi="{{ $kpi['key'] }}" data-state="{{ $kpi['state'] }}">
                    <p class="truncate text-xs font-medium text-gray-500">{{ $kpi['label'] }}</p>
                    @if ($kpi['state'] === 'ok')
                        <p class="mt-1 truncate text-2xl font-semibold tabular-nums text-gray-900 dark:text-white" title="{{ $kpi['value'] }}">{{ $kpi['value'] }}</p>
                        <p class="mt-0.5 text-xs">
                            @if ($kpi['delta'] !== null)
                                <span @class(['font-medium', 'text-success-600' => $kpi['delta'] > 0, 'text-error-600' => $kpi['delta'] < 0, 'text-gray-500' => $kpi['delta'] === 0])>{{ $kpi['delta'] > 0 ? '▲ +' : ($kpi['delta'] < 0 ? '▼ ' : '') }}%{{ abs($kpi['delta']) }}</span>
                            @else
                                <span class="text-gray-400">karşılaştırma yok</span>
                            @endif
                        </p>
                    @else
                        <p class="mt-1 text-lg font-semibold text-gray-300 dark:text-gray-600">veri yok</p>
                    @endif
                    <p class="mt-1 text-[11px] leading-snug text-gray-400">{{ $kpi['note'] }}</p>
                    <p class="mt-1 flex items-center justify-between gap-2 text-[11px]">
                        <span class="truncate text-gray-400">{{ $kpi['source'] }}</span>
                        @if ($kpi['action'])<a href="{{ $kpi['action']['url'] }}" wire:navigate class="shrink-0 font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $kpi['action']['label'] }} →</a>@endif
                    </p>
                </div>
            @endforeach
        </div>
    </section>

    <div class="grid gap-6 xl:grid-cols-3">
        {{-- Digital assets --}}
        <section class="xl:col-span-2" aria-labelledby="assets-heading" data-brand-assets>
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h2 id="assets-heading" class="text-sm font-semibold text-gray-800 dark:text-white/90">Dijital varlıklar <span class="font-normal text-gray-400">{{ count($assetCards) }}</span></h2>
                <span class="flex items-center gap-3 text-xs">
                    <a href="{{ route('operator.brand', ['brand' => $brandModel->id, 'tab' => 'assets']) }}" wire:navigate class="font-medium text-brand-600 hover:underline dark:text-brand-400">Hesap bağla</a>
                    <a href="{{ route('operator.asset.create', ['brandId' => $brandModel->id]) }}" wire:navigate class="font-medium text-brand-600 hover:underline dark:text-brand-400">Varlık ekle</a>
                </span>
            </div>
            @if ($assetCards === [])
                <div class="{{ $panel }} px-5 py-6 text-sm text-gray-500">
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

        <div class="space-y-6">
            {{-- Open work --}}
            <section class="{{ $panel }}" aria-labelledby="work-heading" data-brand-work>
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                    <h2 id="work-heading" class="text-sm font-semibold text-gray-800 dark:text-white/90">Açık işler <span class="font-normal text-gray-400">{{ $work['total'] }}</span></h2>
                    @if (count($work['by_channel']) > 1)
                        <span class="flex flex-wrap gap-1 text-[11px] text-gray-500">
                            @foreach ($work['by_channel'] as $channelLabel => $count)<span class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-gray-800">{{ $channelLabel }} {{ $count }}</span>@endforeach
                        </span>
                    @endif
                </div>
                @forelse ($work['items'] as $item)
                    <div wire:key="work-{{ $item['id'] }}" class="border-b border-gray-100 px-4 py-3 last:border-0 dark:border-gray-800" data-work-item="{{ $item['id'] }}">
                        <div class="flex items-start justify-between gap-3">
                            <p class="min-w-0 text-sm font-medium text-gray-800 dark:text-white/90"><span class="mr-1 rounded bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium text-gray-500 dark:bg-gray-800">{{ $item['channel_label'] }}</span>{{ $item['title'] }}</p>
                            @if ($item['url'])<a href="{{ $item['url'] }}" wire:navigate class="shrink-0 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Aç →</a>@endif
                        </div>
                        @if ($item['reason'] !== '')<p class="mt-1 line-clamp-2 text-xs text-gray-500">{{ $item['reason'] }}</p>@endif
                    </div>
                @empty
                    <p class="px-4 py-3 text-sm text-gray-500" data-work-empty>Açık öneri yok. Kanal analizleri yeni iş bulduğunda burada görünür.</p>
                @endforelse
                @if ($work['total'] > count($work['items']))
                    <p class="px-4 py-2 text-xs text-gray-400">+{{ $work['total'] - count($work['items']) }} iş daha · kanal sekmelerinde</p>
                @endif
            </section>

            {{-- Services --}}
            <section class="{{ $panel }}" aria-labelledby="services-heading" data-brand-services>
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                    <h2 id="services-heading" class="text-sm font-semibold text-gray-800 dark:text-white/90">Hizmetler <span class="font-normal text-gray-400">{{ $serviceSummary['total'] }}</span></h2>
                    <button type="button" wire:click="setTab('business')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Yönet →</button>
                </div>
                @if ($serviceSummary['total'] > 0)
                    <p class="px-4 pt-3 text-xs text-gray-500">{{ $serviceSummary['priority'] }} öncelikli · {{ $serviceSummary['mapped'] }}/{{ $serviceSummary['total'] }} hizmetin sitede sayfası eşlendi</p>
                    <ul class="px-4 py-2">
                        @foreach ($serviceSummary['rows'] as $service)
                            <li wire:key="svc-{{ $service['id'] }}" class="flex items-center justify-between gap-3 py-1.5 text-sm">
                                <span class="min-w-0 truncate text-gray-800 dark:text-white/90">@if ($service['is_priority'])<span class="text-warning-500">★</span> @endif{{ $service['name'] }}</span>
                                <span @class(['shrink-0 text-xs', 'text-gray-500' => $service['pages'] > 0, 'text-warning-700 dark:text-warning-400' => $service['pages'] === 0])>{{ $service['pages'] > 0 ? $service['pages'].' sayfa' : 'Sayfa eşlenmedi' }}</span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($serviceSummary['total'] > count($serviceSummary['rows']))<p class="px-4 pb-3 text-xs text-gray-400">+{{ $serviceSummary['total'] - count($serviceSummary['rows']) }} hizmet daha</p>@endif
                @else
                    <p class="px-4 py-3 text-sm text-gray-500">Markaya hizmet eklenmedi. "Otomatik kur" siteden önerir ya da Ayarlar → İşletme'den eklenir.</p>
                @endif
            </section>
        </div>
    </div>
</div>
