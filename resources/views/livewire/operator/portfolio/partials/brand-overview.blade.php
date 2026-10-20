{{--
    Özet (panel concept 2026-10-20): what the brand needs from the operator first (approvals waiting on the Onarım masası,
    missing brand data), then the period numbers and the Yolculuk strip, then open work and digital assets filtered by
    channel, then services. Red only lost traffic, amber "bak", green ok; near-black only the page's one main action.
--}}
@php
    $missingSetup = collect($checklist['items'])->where('required', true)->where('done', false)->values();
    $reviewSetup = collect($checklist['items'])->where('required', true)->where('done', true)->where('review', true)->values();
    $setupComplete = $missingSetup->isEmpty();
    $serviceChipLimit = \App\Livewire\Operator\Portfolio\BrandShow::SERVICE_CHIPS;
    $serviceRows = collect($serviceSummary['rows'])->sortBy(fn (array $s): int => $s['is_priority'] ? 0 : 1)->values();
    $channelIcons = [
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
        'maps' => '<path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.3"/>',
        'google_ads' => '<path d="m3 17 6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        'meta' => '<path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/>',
    ];
    $quietLink = 'text-[13px] font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white';
@endphp
<div class="space-y-10" data-brand-overview>
    @unless ($operational)
        <p class="card px-4 py-3 text-sm text-gray-600 dark:text-gray-300" data-brand-not-served>{{ \App\Support\ServiceScope::NOT_SERVED }}</p>
    @endunless

    {{-- 1. What waits on the operator: the brand's prepared fixes on the Onarım masası (loaded after the page) --}}
    <livewire:operator.portfolio.brand-approvals-card lazy :brand-id="(int) $brandModel->id" :key="'brand-approvals-'.$brandModel->id" />

    {{-- Marka eksikleri (yakup, 2026-10-07): amber = missing, gray = filled automatically and not checked yet. Nothing missing → one row. --}}
    @if (! $setupComplete)
        <section data-setup-missing>
            <div class="section-head">
                <h2 class="section-title" data-setup-title="missing" title="Tamamlanınca içerik fikirleri, küme eşleşmesi, kampanya kararları ve raporlar tam veriyle çalışır. Sistem eksikleri her gece kendisi doldurmayı dener.">Marka eksikleri <span class="section-count">{{ $checklist['done'] }}/{{ $checklist['total'] }}</span></h2>
                @if ($autofillNote = \App\Services\BrandSetup\BrandAutofill::note((int) $brandModel->id))
                    <span class="min-w-0 text-[13px] text-gray-500" data-autofill-note>Son tur {{ \Carbon\Carbon::parse($autofillNote['at'])->timezone('Europe/Istanbul')->format('d.m H:i') }}: {{ $autofillNote['text'] }}</span>
                @endif
                <a href="{{ route('operator.brand.setup', ['brand' => $brandModel->id]) }}" wire:navigate class="{{ $quietLink }} ml-auto">Otomatik kur</a>
            </div>
            @include('livewire.operator.portfolio.partials.brand-setup-items', ['items' => $missingSetup->concat($reviewSetup)])
        </section>
    @elseif ($reviewSetup->isNotEmpty())
        <section x-data="{ open: false }" data-setup-missing>
            <div class="card">
                <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-expanded="false" aria-controls="setup-review-list" class="card-row w-full text-left hover:bg-gray-50 dark:hover:bg-white/[0.02]" data-setup-toggle>
                    <span class="row-icon text-success-600 dark:text-success-400" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg></span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium text-gray-900 dark:text-white" data-setup-title="review">Tamam · {{ $reviewSetup->count() }} alan kontrolünü bekliyor</span>
                        <span class="mt-0.5 block text-[13px] text-gray-500">Eksik yok; aşağıdakileri sistem doldurdu ya da otomatik seçti. Bakıp "Kontrol ettim" de.</span>
                    </span>
                    <span class="btn-quiet" x-text="open ? 'Gizle' : 'Göster'">Göster</span>
                </button>
                <div id="setup-review-list" x-show="open" x-cloak class="border-t border-gray-200 dark:border-gray-800">
                    @include('livewire.operator.portfolio.partials.brand-setup-items', ['items' => $reviewSetup, 'bare' => true])
                </div>
            </div>
        </section>
    @endif

    {{-- 2. Numbers: each against the previous period of the same length --}}
    <section aria-labelledby="kpi-heading" data-brand-kpis>
        <div class="section-head">
            <h2 id="kpi-heading" class="section-title">Son {{ $days }} gün</h2>
            <span class="text-sm text-gray-500" title="Her kaynak kendi son veri gününe kadar">önceki {{ $days }} günle</span>
        </div>
        {{-- Canlı panel (2026-10-20): the brand's key numbers in the dark number panel; the change as a chip. --}}
        <div class="hero-panel grid-cols-2 lg:grid-cols-3">
            @foreach ($kpis as $kpi)
                @php
                    $kpiOk = $kpi['state'] === 'ok';
                    // Spend going up is not good or bad by itself: only results are coloured.
                    $kpiChip = ! $kpiOk || $kpi['delta'] === null ? null : ($kpi['delta'] === 0 || $kpi['key'] === 'ad_spend' ? 'chip-flat bg-white/10! text-white/80!' : ($kpi['delta'] > 0 ? 'chip-up' : 'chip-down'));
                    $kpiDelta = $kpiOk && $kpi['delta'] !== null ? ($kpi['delta'] > 0 ? '▲ ' : ($kpi['delta'] < 0 ? '▼ ' : '')).'%'.abs($kpi['delta']) : null;
                    $kpiDown = $kpiOk && $kpi['delta'] !== null && $kpi['delta'] < 0 && $kpi['key'] !== 'ad_spend';
                @endphp
                <div class="hero-tile" data-kpi="{{ $kpi['key'] }}" data-state="{{ $kpi['state'] }}" title="{{ $kpi['source'] }}{{ $kpiOk ? ': '.$kpi['value'] : '' }}">
                    <span class="hero-label"><span class="truncate">{{ $kpi['label'] }}</span></span>
                    <p @class(['hero-value', 'text-white/35! text-xl! font-medium!' => ! $kpiOk])>{{ $kpiOk ? $kpi['value'] : 'veri yok' }}</p>
                    <p class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-white/60">
                        @if ($kpiDelta !== null)<span class="chip {{ $kpiChip }}">{{ $kpiDelta }}</span>@endif
                        @if ($kpiOk && $kpi['delta'] === null)<span>karşılaştırma yok</span>@endif
                        @if (filled($kpi['note']))<span class="min-w-0">{{ $kpi['note'] }}</span>@endif
                    </p>
                    @if ($kpi['action'])<a href="{{ $kpi['action']['url'] }}" wire:navigate class="mt-2 w-max text-xs font-medium text-white underline decoration-white/30 underline-offset-2 hover:decoration-white" title="{{ $kpi['source'] }}">{{ $kpi['action']['label'] }}</a>@endif
                    @if ($kpiOk && is_array($kpi['spark'] ?? null) && count($kpi['spark']) >= 2)
                        <x-ta.sparkline :values="$kpi['spark']" :tone="$kpiDown ? 'down' : 'second'" :width="200" :height="28" :fill="false" class="mt-auto block h-7 w-full pt-2" />
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    {{-- 3. Yolculuk (yakup, 2026-10-07): searches → services → clusters → site → idea pool → sent; the step losing the most is red. --}}
    @if ($journey)
        @php $worstKey = $journey['worst']['key'] ?? null; @endphp
        <section data-brand-journey>
            <div class="section-head">
                <h2 class="section-title">Yolculuk</h2>
                <span class="text-sm text-gray-500">aramadan yazılan içeriğe</span>
            </div>
            <div class="card" data-journey-scroll>
                <ol class="grid grid-cols-2 gap-px bg-gray-200 sm:grid-cols-3 lg:grid-cols-6 dark:bg-gray-800">
                    @foreach ($journey['stages'] as $stage)
                        @php $isWorst = $worstKey === $stage['key']; @endphp
                        <li class="min-w-0 bg-white dark:bg-gray-900" data-journey-stage="{{ $stage['key'] }}" @if ($isWorst) data-journey-worst @endif @if (($journey['broken']['key'] ?? null) === $stage['key']) data-journey-broken @endif>
                            @if ($stage['url'] ?? null)
                                <a href="{{ $stage['url'] }}" wire:navigate class="flex h-full min-w-0 flex-col px-[18px] py-4 transition hover:bg-gray-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-gray-900 dark:hover:bg-white/[0.03] dark:focus-visible:outline-white" data-journey-link="{{ $stage['key'] }}">
                            @else
                                <div class="flex h-full min-w-0 flex-col px-[18px] py-4">
                            @endif
                                <span class="truncate text-[13px] text-gray-500 dark:text-gray-400" title="{{ $stage['label'] }}">{{ $loop->iteration }}. {{ $stage['label'] }}</span>
                                <span @class(['mt-1.5 text-[26px] leading-8 font-semibold tabular-nums tracking-[-0.02em]', 'text-error-600 dark:text-error-400' => $isWorst, 'text-gray-900 dark:text-white' => ! $isWorst])>{{ number_format($stage['value'], 0, ',', '.') }}</span>
                                <span @class(['mt-0.5 truncate text-xs', 'text-error-600 dark:text-error-400' => $isWorst, 'text-gray-500 dark:text-gray-400' => ! $isWorst]) title="{{ $stage['detail'] }}">{{ $stage['detail'] }}</span>
                            @if ($stage['url'] ?? null)
                                </a>
                            @else
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ol>
                @if ($journey['broken'] || $journey['worst'])
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-gray-200 bg-gray-50/60 px-4 py-3 text-[13px] dark:border-gray-800 dark:bg-white/[0.02]" data-journey-reason>
                        <span @class(['h-1.5 w-1.5 shrink-0 rounded-full', 'bg-warning-500' => $journey['broken'], 'bg-error-500' => ! $journey['broken']]) aria-hidden="true"></span>
                        @if ($journey['broken'])
                            <span class="min-w-0 flex-1 text-gray-700 dark:text-gray-300">{{ $journey['broken']['reason'] }}</span>
                            @if ($journey['broken']['fix'] !== 'ozet')
                                <button type="button" wire:click="setTab('{{ $journey['broken']['fix'] }}')" class="link-primary shrink-0">Düzelt</button>
                            @endif
                        @else
                            <span class="min-w-0 flex-1 text-gray-700 dark:text-gray-300">En çok kayıp: {{ $journey['worst']['text'] }}.</span>
                        @endif
                    </div>
                @endif
                @if ($journey['foreign'] !== [])
                    <p class="border-t border-gray-200 px-4 py-3 text-[13px] text-gray-500 dark:border-gray-800" data-journey-foreign>Markada olmayan hizmetlere ait aramalar: @foreach ($journey['foreign'] as $row){{ $row['service'] }} ({{ $row['queries'] }})@if (! $loop->last), @endif @endforeach. Bu hizmetler veriliyorsa markaya ekle.</p>
                @endif
            </div>
        </section>
    @endif

    {{-- 4. Open work and the digital assets, filtered by channel (the channel tabs' content until each channel has its own tab) --}}
    <div class="space-y-6">
        <div class="no-scrollbar -mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0" data-channel-filter>
            <div class="flex min-w-max items-center gap-1.5" role="group" aria-label="Kanal">
                <button type="button" wire:click="setChannel('')" aria-pressed="{{ $channelFilter === null ? 'true' : 'false' }}" @class(['seg', 'seg-active' => $channelFilter === null])>Tüm kanallar <span @class(['tabular-nums text-xs', 'text-accent' => $channelFilter === null, 'text-gray-400 dark:text-gray-500' => $channelFilter !== null])>{{ $workTotal }}</span></button>
                @foreach ($channelCounts as $channelKey => $channelRow)
                    @php $channelActive = ($channelFilter['key'] ?? null) === $channelKey; @endphp
                    <button type="button" wire:click="setChannel('{{ $channelKey }}')" data-channel="{{ $channelKey }}" aria-pressed="{{ $channelActive ? 'true' : 'false' }}" @class(['seg', 'seg-active' => $channelActive])>{{ $channelRow['label'] }} <span @class(['tabular-nums text-xs', 'text-accent' => $channelActive, 'text-gray-400 dark:text-gray-500' => ! $channelActive])>{{ $channelRow['count'] }}</span></button>
                @endforeach
            </div>
        </div>

        <div class="space-y-10">
            {{-- Open work --}}
            <section aria-labelledby="work-heading" data-brand-work>
                <div class="section-head">
                    <h2 id="work-heading" class="section-title">{{ $channelFilter !== null ? $channelFilter['label'].' açık öneriler' : 'Açık işler' }}</h2>
                </div>
                <ul class="card">
                    @forelse ($work['items'] as $item)
                        <li wire:key="work-{{ $item['id'] }}" class="card-row flex-wrap sm:flex-nowrap" data-work-item="{{ $item['id'] }}">
                            <span class="row-icon" title="{{ $item['channel_label'] }}" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! $channelIcons[$item['channel']] ?? $channelIcons['search'] !!}</svg></span>
                            <div class="min-w-0 flex-1">
                                <p class="break-words text-sm font-medium text-gray-900 dark:text-white"><span class="sr-only">{{ $item['channel_label'] }}: </span>{{ $item['title'] }}</p>
                                <p class="mt-0.5 line-clamp-2 text-[13px] text-gray-500 dark:text-gray-400">@if ($channelFilter === null){{ $item['channel_label'] }}@if ($item['reason'] !== ''), @endif @endif{{ $item['reason'] }}</p>
                            </div>
                            @if ($item['url'])<a href="{{ $item['url'] }}" wire:navigate class="btn ml-[46px] w-[calc(100%-46px)] sm:ml-0 sm:w-auto">Aç</a>@endif
                        </li>
                    @empty
                        <li class="card-row text-sm text-gray-500" data-work-empty>{{ $channelFilter !== null ? 'Bu kanalda açık öneri yok.' : 'Açık öneri yok.' }}@if ($channelFilter !== null && $assetCards === []) Önce {{ $channelFilter['asset_label'] }} bağlanmalı.@endif</li>
                    @endforelse
                    @if ($work['total'] > count($work['items']))
                        <li class="border-t border-gray-200 dark:border-gray-800"><button type="button" wire:click="showAllWork" class="w-full px-4 py-2.5 text-left text-[13px] font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-white/[0.02] dark:hover:text-white" data-work-more>Hepsini göster</button></li>
                    @elseif ($allWork && $work['total'] > \App\Livewire\Operator\Portfolio\BrandShow::WORK_LIMIT)
                        <li class="border-t border-gray-200 dark:border-gray-800"><button type="button" wire:click="showAllWork(false)" class="w-full px-4 py-2.5 text-left text-[13px] font-medium text-gray-500 hover:bg-gray-50 hover:text-gray-900 dark:hover:bg-white/[0.02] dark:hover:text-white">Daha az göster</button></li>
                    @endif
                </ul>
            </section>

            {{-- Digital assets --}}
            <section aria-labelledby="assets-heading" data-brand-assets>
                <div class="section-head">
                    <h2 id="assets-heading" class="section-title">{{ $channelFilter !== null ? $channelFilter['label'].' veri kaynakları' : 'Dijital varlıklar' }} <span class="section-count">{{ count($assetCards) }}</span></h2>
                    <span class="ml-auto flex items-center gap-4">
                        <button type="button" wire:click="setTab('varliklar')" class="{{ $quietLink }}">Hesap bağla</button>
                        <a href="{{ route('operator.asset.create', ['brandId' => $brandModel->id]) }}" wire:navigate class="{{ $quietLink }}">Varlık ekle</a>
                    </span>
                </div>
                @if ($assetCards === [] && $channelFilter !== null)
                    <div class="card">
                        <div class="card-row flex-wrap sm:flex-nowrap" data-channel-missing>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">Markaya bağlı {{ $channelFilter['asset_label'] }} yok.</p>
                                <p class="mt-0.5 text-[13px] text-gray-500">Bağlanmadan bu kanal için veri toplanmaz. Markada yoksa sorun değil.</p>
                            </div>
                            <span class="flex w-full items-center gap-2 sm:w-auto">
                                <x-ta.button variant="outline" size="sm" :href="route('operator.brand', ['brand' => $brandModel->id, 'tab' => 'varliklar'])" wire:navigate>Hesap bağla</x-ta.button>
                                <x-ta.button variant="ghost" size="sm" :href="route('operator.asset.create', ['brandId' => $brandModel->id])" wire:navigate>Varlık ekle</x-ta.button>
                            </span>
                        </div>
                    </div>
                @elseif ($assetCards === [])
                    <div class="card">
                        <div class="card-row"><span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span><div class="min-w-0 flex-1"><p class="text-sm font-medium text-gray-900 dark:text-white">Henüz dijital varlık yok</p><p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">Otomatik kur web sitesinden hesapları bulur.</p></div><a href="{{ route('operator.brand.setup', ['brand' => $brandModel->id]) }}" wire:navigate class="btn">Otomatik kur</a></div>
                    </div>
                @else
                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach ($assetCards as $asset)
                            @include('livewire.operator.portfolio.partials.asset-card', ['asset' => $asset])
                        @endforeach
                    </div>
                @endif
            </section>
        </div>
    </div>

    {{-- 5. Services: chips, ★ (main) first, at most 10 before "+N hizmet"; edited under Ayarlar --}}
    <section aria-labelledby="services-heading" data-brand-services x-data="{ allServices: false }">
        <div class="section-head">
            <h2 id="services-heading" class="section-title">Hizmetler <span class="section-count">{{ $serviceSummary['total'] }}</span></h2>
            <button type="button" wire:click="setTab('ayarlar')" class="{{ $quietLink }} ml-auto">Düzenle</button>
        </div>
        <div class="card">
            @if ($serviceSummary['total'] > 0)
                <ul class="flex flex-wrap gap-1.5 px-4 py-3.5" aria-label="Hizmetler">
                    @foreach ($serviceRows as $service)
                        @php
                            $chipClass = $service['is_priority']
                                ? 'border-gray-300 font-medium text-gray-900 dark:border-gray-600 dark:text-white'
                                : 'border-gray-200 text-gray-700 dark:border-gray-700 dark:text-gray-300';
                            $pageNote = $service['pages'] > 0 ? $service['pages'].' sayfa' : 'Sayfa eşlenmedi';
                        @endphp
                        <li wire:key="svc-{{ $service['id'] }}" data-service-row="{{ $service['id'] }}" @if ($loop->index >= $serviceChipLimit) x-show="allServices" x-cloak @endif>
                            @if ($service['hub'])
                                <a href="{{ route('operator.website', ['assetId' => $service['hub']['website_asset_id'], 'tab' => 'eslestirme']) }}" wire:navigate title="{{ $service['name'] }}, {{ $pageNote }}, ana sayfa {{ $service['hub']['path'] }}" class="inline-flex max-w-full items-center gap-1.5 rounded-md border bg-white px-2 py-1 text-[13px] hover:bg-gray-50 dark:bg-white/[0.03] dark:hover:bg-white/[0.06] {{ $chipClass }}" data-service-hub>
                            @else
                                <span title="{{ $service['name'] }}, {{ $pageNote }}" class="inline-flex max-w-full items-center gap-1.5 rounded-md border bg-white px-2 py-1 text-[13px] dark:bg-white/[0.03] {{ $chipClass }}">
                            @endif
                                @if ($service['is_priority'])<span aria-label="Ana hizmet">★</span>@endif
                                <span class="truncate">{{ $service['name'] }}</span>
                                @if ($service['pages'] === 0)<span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span><span class="sr-only">Sayfa eşlenmedi</span>@endif
                            @if ($service['hub'])
                                </a>
                            @else
                                </span>
                            @endif
                        </li>
                    @endforeach
                    @if ($serviceRows->count() > $serviceChipLimit)
                        <li>
                            <button type="button" x-on:click="allServices = ! allServices" x-bind:aria-expanded="allServices.toString()" aria-expanded="false" class="inline-flex items-center rounded-md border border-dashed border-gray-300 px-2 py-1 text-[13px] text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.04]" data-services-more>
                                <span x-show="! allServices">+{{ $serviceRows->count() - $serviceChipLimit }} hizmet</span>
                                <span x-show="allServices" x-cloak>Daha az</span>
                            </button>
                        </li>
                    @endif
                </ul>
                <p class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-gray-200 px-4 py-2.5 text-xs text-gray-500 dark:border-gray-800">
                    <span>{{ $serviceSummary['priority'] }} ana (★) · {{ $serviceSummary['mapped'] }}/{{ $serviceSummary['total'] }} hizmetin sitede sayfası eşlendi</span>
                    @if ($serviceSummary['mapped'] < $serviceSummary['total'])
                        <span class="flex items-center gap-1.5 sm:ml-auto"><span class="h-1.5 w-1.5 rounded-full bg-warning-500" aria-hidden="true"></span>Sayfa eşlenmedi</span>
                    @endif
                </p>
            @else
                <div class="card-row"><span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span><div class="min-w-0 flex-1"><p class="text-sm font-medium text-gray-900 dark:text-white">Markaya hizmet eklenmedi.</p><p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">Otomatik kur siteden önerir ya da Ayarlar, Marka bilgileri'nden eklenir.</p></div></div>
            @endif
        </div>
    </section>
</div>
