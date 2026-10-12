{{--
    Özet (panel önerileri §3, 2026-10-11): the period numbers first, then the brand's approvals waiting on the Onarım masası,
    the Yolculuk strip, then digital assets, open work and services. Marka bilgi kartı and Kimlik tutarlılığı live under
    Bilgi dosyası. Colours: orange only the main action, red only lost traffic, amber "bak", gray info, green ok.
--}}
@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $panel = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $missingSetup = collect($checklist['items'])->where('required', true)->where('done', false)->values();
    $reviewSetup = collect($checklist['items'])->where('required', true)->where('done', true)->where('review', true)->values();
    $setupComplete = $missingSetup->isEmpty();
    $chip = fn (bool $active): string => $active
        ? 'inline-flex h-8 items-center gap-1.5 rounded-full bg-gray-900 px-3 text-xs font-semibold text-white dark:bg-white dark:text-gray-900'
        : 'inline-flex h-8 items-center gap-1.5 rounded-full bg-white px-3 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-gray-800';
    $serviceChipLimit = \App\Livewire\Operator\Portfolio\BrandShow::SERVICE_CHIPS;
    $serviceRows = collect($serviceSummary['rows'])->sortBy(fn (array $s): int => $s['is_priority'] ? 0 : 1)->values();
@endphp
<div class="space-y-4" data-brand-overview>
    @unless ($operational)
        <p class="rounded-xl bg-gray-50 p-3 text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-white/[0.03] dark:text-gray-400 dark:ring-gray-800" data-brand-not-served>{{ \App\Support\ServiceScope::NOT_SERVED }}</p>
    @endunless

    {{-- 1. Numbers: each against the previous period of the same length --}}
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
                        @if ($kpi['action'])<a href="{{ $kpi['action']['url'] }}" wire:navigate class="shrink-0 font-medium text-gray-600 hover:text-gray-900 hover:underline dark:text-gray-300 dark:hover:text-white">{{ $kpi['action']['label'] }} →</a>@endif
                    </p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- 2. Bekleyen onaylar: the brand's prepared fixes on the Onarım masası (loaded after the page) --}}
    <livewire:operator.portfolio.brand-approvals-card lazy :brand-id="(int) $brandModel->id" :key="'brand-approvals-'.$brandModel->id" />

    {{-- Marka eksikleri (yakup, 2026-10-07): amber = missing, gray = filled automatically and not checked yet. Nothing missing → one line. --}}
    @if (! $setupComplete)
        <section class="{{ $card }}" data-setup-missing>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white" data-setup-title="missing">Marka eksikleri · {{ $checklist['done'] }}/{{ $checklist['total'] }}</h2>
                    <p class="text-xs text-gray-500">Tamamlanınca içerik fikirleri, küme eşleşmesi, kampanya kararları ve raporlar tam veriyle çalışır. Sistem eksikleri her gece kendisi doldurmayı dener.</p>
                    @if ($autofillNote = \App\Services\BrandSetup\BrandAutofill::note((int) $brandModel->id))
                        <p class="mt-1 text-xs text-gray-500" data-autofill-note>Son tur ({{ \Carbon\Carbon::parse($autofillNote['at'])->timezone('Europe/Istanbul')->format('d.m H:i') }}): {{ $autofillNote['text'] }}</p>
                    @endif
                </div>
                <a href="{{ route('operator.brand.setup', ['brand' => $brandModel->id]) }}" wire:navigate class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600">Otomatik kur</a>
            </div>
            @include('livewire.operator.portfolio.partials.brand-setup-items', ['items' => $missingSetup->concat($reviewSetup)])
        </section>
    @elseif ($reviewSetup->isNotEmpty())
        <section x-data="{ open: false }" class="rounded-xl bg-white px-4 py-2.5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-setup-missing>
            <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-expanded="false" aria-controls="setup-review-list" class="flex w-full items-center gap-2 text-left text-xs" data-setup-toggle>
                <span class="h-2 w-2 shrink-0 rounded-full bg-emerald-500"></span>
                <span class="font-semibold text-gray-900 dark:text-white" data-setup-title="review">Tamam · {{ $reviewSetup->count() }} alan kontrolünü bekliyor</span>
                <span class="ml-auto text-gray-500" x-text="open ? 'Gizle' : 'Göster'">Göster</span>
            </button>
            <div id="setup-review-list" x-show="open" x-cloak>
                <p class="mt-2 text-xs text-gray-500">Eksik yok; aşağıdakileri sistem doldurdu ya da otomatik seçti. Bakıp "Kontrol ettim" de.</p>
                @include('livewire.operator.portfolio.partials.brand-setup-items', ['items' => $reviewSetup])
            </div>
        </section>
    @endif

    {{-- 3. Yolculuk (yakup, 2026-10-07): searches → services → clusters → site → idea pool → sent; the step losing the most is red. --}}
    @if ($journey)
        @php $worstKey = $journey['worst']['key'] ?? null; @endphp
        <section class="{{ $card }}" data-brand-journey>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Yolculuk</h2>
                <span class="text-xs text-gray-500">aramadan yazılan içeriğe</span>
            </div>
            <ol class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6">
                @foreach ($journey['stages'] as $stage)
                    @php
                        $isWorst = $worstKey === $stage['key'];
                        $box = $isWorst ? 'bg-red-50 ring-red-200 dark:bg-red-500/10 dark:ring-red-500/30' : 'bg-gray-50 ring-gray-200 dark:bg-white/[0.03] dark:ring-gray-800';
                    @endphp
                    <li data-journey-stage="{{ $stage['key'] }}" @if ($isWorst) data-journey-worst @endif @if (($journey['broken']['key'] ?? null) === $stage['key']) data-journey-broken @endif>
                        @if ($stage['url'] ?? null)
                            <a href="{{ $stage['url'] }}" wire:navigate class="block h-full rounded-lg p-2.5 ring-1 ring-inset transition hover:ring-gray-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900 dark:hover:ring-gray-600 dark:focus-visible:outline-white {{ $box }}" data-journey-link="{{ $stage['key'] }}">
                        @else
                            <div class="h-full rounded-lg p-2.5 ring-1 ring-inset {{ $box }}">
                        @endif
                            <p class="truncate text-xs text-gray-500">{{ $loop->iteration }}. {{ $stage['label'] }}</p>
                            <p @class(['text-xl font-semibold tabular-nums', 'text-red-700 dark:text-red-300' => $isWorst, 'text-gray-900 dark:text-white' => ! $isWorst])>{{ number_format($stage['value'], 0, ',', '.') }}</p>
                            <p @class(['truncate text-xs', 'font-medium text-red-700 dark:text-red-300' => $isWorst, 'text-gray-500' => ! $isWorst]) title="{{ $stage['detail'] }}">{{ $stage['detail'] }}</p>
                        @if ($stage['url'] ?? null)
                            </a>
                        @else
                            </div>
                        @endif
                    </li>
                @endforeach
            </ol>
            @if ($journey['broken'])
                <p class="mt-2 flex flex-wrap items-baseline gap-x-2 text-xs text-red-700 dark:text-red-300" data-journey-reason>
                    <span>{{ $journey['broken']['reason'] }}</span>
                    @if ($journey['broken']['fix'] !== 'ozet')
                        <button type="button" wire:click="setTab('{{ $journey['broken']['fix'] }}')" class="font-semibold text-gray-700 hover:underline dark:text-gray-200">Düzelt →</button>
                    @endif
                </p>
            @elseif ($journey['worst'])
                <p class="mt-2 text-xs text-red-700 dark:text-red-300" data-journey-reason>En çok kayıp: {{ $journey['worst']['text'] }}.</p>
            @endif
            @if ($journey['foreign'] !== [])
                <p class="mt-2 text-xs text-gray-500" data-journey-foreign>Markada olmayan hizmetlere ait aramalar: @foreach ($journey['foreign'] as $row){{ $row['service'] }} ({{ $row['queries'] }})@if (! $loop->last), @endif @endforeach · Bu hizmetler veriliyorsa markaya ekle.</p>
            @endif
        </section>
    @endif

    {{-- 4. Channel filter: the channel tabs' content until each channel has its own tab --}}
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
                    <button type="button" wire:click="setTab('varliklar')" class="font-medium text-gray-600 hover:text-gray-900 hover:underline dark:text-gray-300 dark:hover:text-white">Hesap bağla</button>
                    <a href="{{ route('operator.asset.create', ['brandId' => $brandModel->id]) }}" wire:navigate class="font-medium text-gray-600 hover:text-gray-900 hover:underline dark:text-gray-300 dark:hover:text-white">Varlık ekle</a>
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
                    <a href="{{ route('operator.brand.setup', ['brand' => $brandModel->id]) }}" wire:navigate class="ml-1 font-medium text-gray-700 hover:underline dark:text-gray-200">Otomatik kur →</a>
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
                            @if ($item['url'])<a href="{{ $item['url'] }}" wire:navigate class="shrink-0 text-xs font-medium text-gray-600 hover:text-gray-900 hover:underline dark:text-gray-300 dark:hover:text-white">Aç →</a>@endif
                        </div>
                        @if ($item['reason'] !== '')<p class="mt-1 line-clamp-2 text-xs text-gray-500">{{ $item['reason'] }}</p>@endif
                    </div>
                @empty
                    <p class="px-4 py-3 text-gray-500" data-work-empty>{{ $channelFilter !== null ? 'Bu kanalda açık öneri yok.' : 'Açık öneri yok. Kanal analizleri yeni iş bulduğunda burada görünür.' }}@if ($channelFilter !== null && $assetCards === []) Önce {{ $channelFilter['asset_label'] }} bağlanmalı.@endif</p>
                @endforelse
                @if ($work['total'] > count($work['items']))
                    <button type="button" wire:click="showAllWork" class="block w-full px-4 py-2 text-left text-xs font-medium text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.03]" data-work-more>+{{ $work['total'] - count($work['items']) }} iş paketi daha · tümünü göster</button>
                @elseif ($allWork && $work['total'] > \App\Livewire\Operator\Portfolio\BrandShow::WORK_LIMIT)
                    <button type="button" wire:click="showAllWork(false)" class="block w-full px-4 py-2 text-left text-xs font-medium text-gray-500 hover:bg-gray-50 dark:hover:bg-white/[0.03]">Daha az göster</button>
                @endif
            </section>

            {{-- Services: chips, ★ (main) first and filled, at most 10 before "+N hizmet"; edited under Ayarlar --}}
            <section class="{{ $panel }}" aria-labelledby="services-heading" data-brand-services x-data="{ allServices: false }">
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                    <h2 id="services-heading" class="text-sm font-semibold text-gray-900 dark:text-white">Hizmetler <span class="font-normal text-gray-400">{{ $serviceSummary['total'] }}</span></h2>
                    <button type="button" wire:click="setTab('ayarlar')" class="text-xs font-medium text-gray-600 hover:text-gray-900 hover:underline dark:text-gray-300 dark:hover:text-white">Düzenle →</button>
                </div>
                @if ($serviceSummary['total'] > 0)
                    <p class="px-4 pt-3 text-xs text-gray-500">{{ $serviceSummary['priority'] }} ana (★) · {{ $serviceSummary['mapped'] }}/{{ $serviceSummary['total'] }} hizmetin sitede sayfası eşlendi</p>
                    <ul class="flex flex-wrap gap-1.5 px-4 py-3" aria-label="Hizmetler">
                        @foreach ($serviceRows as $service)
                            @php
                                $chipClass = $service['is_priority']
                                    ? 'bg-gray-900 text-white ring-gray-900 dark:bg-white dark:text-gray-900 dark:ring-white'
                                    : 'bg-white text-gray-700 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700';
                                $pageNote = $service['pages'] > 0 ? $service['pages'].' sayfa' : 'Sayfa eşlenmedi';
                            @endphp
                            <li wire:key="svc-{{ $service['id'] }}" data-service-row="{{ $service['id'] }}" @if ($loop->index >= $serviceChipLimit) x-show="allServices" x-cloak @endif>
                                @if ($service['hub'])
                                    <a href="{{ route('operator.website', ['assetId' => $service['hub']['website_asset_id'], 'tab' => 'eslestirme']) }}" wire:navigate title="{{ $service['name'] }} · {{ $pageNote }} · ana sayfa {{ $service['hub']['path'] }}" class="inline-flex max-w-full items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset hover:ring-gray-400 {{ $chipClass }}" data-service-hub>
                                @else
                                    <span title="{{ $service['name'] }} · {{ $pageNote }}" class="inline-flex max-w-full items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $chipClass }}">
                                @endif
                                    @if ($service['is_priority'])<span aria-label="Ana hizmet">★</span>@endif
                                    <span class="truncate">{{ $service['name'] }}</span>
                                    @if ($service['pages'] === 0)<span class="h-1.5 w-1.5 shrink-0 rounded-full bg-amber-400" aria-hidden="true"></span><span class="sr-only">Sayfa eşlenmedi</span>@endif
                                @if ($service['hub'])
                                    </a>
                                @else
                                    </span>
                                @endif
                            </li>
                        @endforeach
                        @if ($serviceRows->count() > $serviceChipLimit)
                            <li>
                                <button type="button" x-on:click="allServices = ! allServices" x-bind:aria-expanded="allServices.toString()" aria-expanded="false" class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium text-gray-600 border border-dashed border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800" data-services-more>
                                    <span x-show="! allServices">+{{ $serviceRows->count() - $serviceChipLimit }} hizmet</span>
                                    <span x-show="allServices" x-cloak>Daha az</span>
                                </button>
                            </li>
                        @endif
                    </ul>
                    @if ($serviceSummary['mapped'] < $serviceSummary['total'])
                        <p class="flex items-center gap-1.5 px-4 pb-3 text-[11px] text-gray-500"><span class="h-1.5 w-1.5 rounded-full bg-amber-400" aria-hidden="true"></span>Sayfa eşlenmedi</p>
                    @endif
                @else
                    <p class="px-4 py-3 text-gray-500">Markaya hizmet eklenmedi. "Otomatik kur" siteden önerir ya da Ayarlar → Marka bilgileri'nden eklenir.</p>
                @endif
            </section>
        </div>
    </div>
</div>
