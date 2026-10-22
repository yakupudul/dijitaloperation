{{--
    Özet (Aşama 6, 2026-10-22): the goal and the four channel cards first (numbers, top findings, "Hepsini çöz"), then
    missing brand data, the Yolculuk strip and services. Red only lost traffic, amber "bak", green ok; near-black only the page's one main action.
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

    {{-- 1. Aşama 6 (2026-10-22): the month's goal and one card per channel with its top findings and "Hepsini çöz" (loaded after the page) --}}
    <livewire:operator.portfolio.brand-page-panel lazy :brand-id="(int) $brandModel->id" :days="$days" :key="'brand-page-'.$brandModel->id.'-'.$days" />

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
