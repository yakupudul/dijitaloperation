@php
    $num = fn ($value, int $decimals = 0) => $value === null ? '—' : number_format((float) $value, $decimals, ',', '.');
    $cur = $numbers['currency'] ?? ($settings['currency'] ?? '');
    $money = fn ($value) => $value === null ? '—' : number_format((float) $value, 2, ',', '.');
    $pct = fn ($now, $before) => ($now === null || $before === null || (float) $before == 0.0) ? null : (int) round(((float) $now - (float) $before) / (float) $before * 100);
    /** Google Ads conversion categories in plain Turkish (raw enums never reach the screen). */
    $categoryLabel = ['SUBMIT_LEAD_FORM' => 'form', 'PHONE_CALL_LEAD' => 'telefon araması', 'CONTACT' => 'iletişim', 'BOOK_APPOINTMENT' => 'randevu', 'REQUEST_QUOTE' => 'teklif isteği', 'PURCHASE' => 'satın alma', 'QUALIFIED_LEAD' => 'uygun müşteri', 'CONVERTED_LEAD' => 'satışa dönen müşteri', 'IMPORTED_LEAD' => 'içe aktarılan müşteri', 'GET_DIRECTIONS' => 'yol tarifi', 'PAGE_VIEW' => 'sayfa görüntüleme', 'SIGNUP' => 'kayıt', 'DOWNLOAD' => 'indirme', 'ADD_TO_CART' => 'sepete ekleme', 'BEGIN_CHECKOUT' => 'ödeme başlatma', 'ENGAGEMENT' => 'etkileşim', 'OUTBOUND_CLICK' => 'dış tıklama', 'STORE_VISIT' => 'mağaza ziyareti', 'DEFAULT' => 'diğer', 'UNKNOWN' => 'diğer'];
    /** Panel concept: white bordered secondary, near-black primary, small row buttons, 36 px inputs. */
    $btn = 'btn';
    $primary = 'btn-primary';
    $small = 'btn h-7! px-2.5! text-xs!';
    $input = 'h-9 rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $stateLine = fn (?array $state): ?array => match ($state['status'] ?? null) {
        'running' => ['text-gray-500', 'Çalışıyor…', true],
        'failed' => ['text-error-600 dark:text-error-400', (string) ($state['message'] ?? 'Başarısız.'), false],
        'ready' => ['text-success-700 dark:text-success-400', (string) ($state['message'] ?? 'Hazır.'), false],
        default => null,
    };
    $checkBadge = ['pass' => ['Geçti', 'ok'], 'fail' => ['Sorun', 'critical'], 'nodata' => ['Veri yok', 'info']];
    $matchLabel = fn (string $m) => \App\Services\GoogleAds\GoogleAdsAssistant::matchLabel($m);
    $scopeLabel = fn (array $a) => ['shared' => 'Paylaşılan liste', 'campaign' => 'Kampanya: '.($a['campaign'] ?? ''), 'ad_group' => 'Reklam grubu: '.($a['ad_group'] ?? '')][$a['scope'] ?? ''] ?? '';
    $fitLabel = ['uygun' => ['Uygun', 'text-success-700 dark:text-success-300'], 'kismen' => ['Kısmen', 'text-warning-700 dark:text-warning-300'], 'uygunsuz' => ['Uygunsuz', 'text-error-700 dark:text-error-300']];
    /** A check row: name, the dot status, the reason; failing checks first. */
    $checkRows = fn (iterable $items) => collect($items)->sortBy(fn (array $c): int => ['fail' => 0, 'nodata' => 1, 'pass' => 2][$c['state']] ?? 3);
@endphp

<div class="space-y-10">
    @include('livewire.demo.partials.flash')
    <x-operator.asset-context :asset-id="$assetId" :title="$asset['name'] ?? 'Google Ads'">
        <x-slot:mark><x-demo.digital-asset-mark type="google_ads" size="lg" class="mt-0.5 shrink-0" /></x-slot:mark>
        <x-slot:meta>{{ 'Google Ads'.($numbers && $numbers['last_date'] ? ', son veri '.\Carbon\CarbonImmutable::parse($numbers['last_date'])->format('d.m.Y') : '') }}</x-slot:meta>
        <x-slot:actions>
            @if ($ranged)
                <x-operator.date-range-picker :range="$range" :last-day="$lastDay" note="Google Ads verisi her gün dünü kapsar." class="w-full sm:w-auto" />
            @endif
            <button type="button" wire:click="refreshData" wire:loading.attr="disabled" @disabled(! $bound) class="{{ $btn }} h-9! w-full sm:w-auto">Verileri yenile</button>
        </x-slot:actions>
        <x-slot:tabs>
            <nav class="no-scrollbar flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Google Ads">
                @foreach ($tabs as $key => $label)
                    <button type="button" wire:click="setTab('{{ $key }}')" @if ($tab === $key) aria-current="page" @endif @class(['tab', 'tab-active' => $tab === $key])>{{ $label }}@if ($key === 'todo' && $openCount > 0) <span class="text-[13px] font-normal tabular-nums text-gray-500">{{ $openCount }}</span>@endif</button>
                @endforeach
            </nav>
        </x-slot:tabs>
    </x-operator.asset-context>

    @if (! $bound)
        <div class="card">
            <div class="card-row flex-wrap sm:flex-nowrap">
                <span class="row-icon border-transparent bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg></span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Google Ads hesabı bağlı değil</p>
                    <p class="mt-0.5 text-[13px] text-gray-500">Hesap bağlanınca harcama, dönüşüm ve kontroller burada görünür.</p>
                </div>
                <a href="{{ route('operator.asset.sources', ['assetId' => $assetId]) }}" wire:navigate class="{{ $primary }} w-full sm:w-auto">Bağla</a>
            </div>
        </div>
    @endif

    @if ($tab === 'overview')
        {{-- 1. What waits on the operator (canlı panel 2026-10-20): open suggestions and failing checks as tinted cards. --}}
        @php $failing = collect($checks['items'] ?? [])->where('state', 'fail')->count(); @endphp
        @if ($openCount > 0 || $failing > 0)
            <div @class(['grid gap-3', 'sm:grid-cols-2' => $openCount > 0 && $failing > 0])>
                @if ($openCount > 0)
                    <div class="soft-card soft-accent sm:flex-row sm:items-center sm:gap-4">
                        <span class="soft-icon" aria-hidden="true"><svg class="h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[15px] font-semibold text-gray-900 dark:text-white"><span class="tabular-nums">{{ $openCount }}</span> açık öneri</p>
                            <p class="mt-0.5 text-[13px] text-gray-600 dark:text-gray-300">Negatif kelimeler, ayar değişiklikleri ve elle yapılacak kontroller.</p>
                        </div>
                        <button type="button" wire:click="setTab('todo')" class="{{ $primary }} h-9! w-full px-3.5! sm:w-auto">Yapılacaklar</button>
                    </div>
                @endif
                @if ($failing > 0)
                    <div class="soft-card soft-red sm:flex-row sm:items-center sm:gap-4">
                        <span class="soft-icon text-error-600 dark:text-error-400" aria-hidden="true"><svg class="h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5M12 16.5v.5"/><path d="M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[15px] font-semibold text-gray-900 dark:text-white"><span class="tabular-nums">{{ $failing }}</span> kontrol sorunlu</p>
                            <p class="mt-0.5 text-[13px] text-gray-600 dark:text-gray-300">{{ collect($checks['items'])->where('state', 'fail')->pluck('label')->take(3)->implode(', ') }}</p>
                        </div>
                        <a href="#kontroller" class="{{ $btn }} h-9! w-full sm:w-auto">Kontrollere bak</a>
                    </div>
                @endif
            </div>
        @endif

        {{-- 2. The period in four numbers: one dark panel, conversions marked. --}}
        @if ($numbers)
            @php $c = $numbers['current']; $p = $numbers['previous']; @endphp
            <section title="{{ $range->label() }}, değişim önceki döneme göre">
                <div class="section-head">
                    <h2 class="section-title">{{ $range->label() }}</h2>
                    <span class="text-[13px] text-gray-500">önceki döneme göre</span>
                </div>
                <div class="hero-panel grid-cols-2 lg:grid-cols-4" data-testid="ads-numbers">
                    @foreach ([['Maliyet', 'cost', true, false], ['Tıklama', 'clicks', false, false], ['Dönüşüm', 'conversions', false, false], ['Dönüşüm başı maliyet', 'cpa', true, true]] as [$label, $key, $isMoney, $lowerBetter])
                        @php
                            $neutralDelta = $key === 'cost'; /* spend change is information, not good or bad */
                            $change = $pct($c[$key], $p[$key]);
                            /* Spend reads in whole units so the change fits beside it; cost per conversion keeps kuruş. */
                            $format = fn ($v) => $v === null ? '—' : ($isMoney ? ($key === 'cost' ? $num($v) : $money($v)).' '.$cur : $num($v, $key === 'conversions' ? 1 : 0));
                            $accent = $key === 'conversions';
                            $chip = $change === null ? null : ($neutralDelta || $change === 0 ? 'chip-flat'.($accent ? '' : ' bg-white/10! text-white/80!') : ((($change >= 0) xor $lowerBetter) ? 'chip-up' : 'chip-down'));
                        @endphp
                        <div @class(['hero-tile', 'hero-tile-accent' => $accent])>
                            <span class="hero-label">{{ $label }}</span>
                            <p class="hero-value text-[24px]! sm:text-[30px]!">{{ $format($c[$key]) }}</p>
                            <p @class(['mt-2 flex flex-wrap items-center gap-2 text-xs', 'text-white/60' => ! $accent, 'opacity-70' => $accent])>
                                @if ($c[$key] === null)
                                    veri yok
                                @else
                                    @if ($chip)<span class="chip {{ $chip }}">{{ $change > 0 ? '▲ ' : ($change < 0 ? '▼ ' : '') }}{{ abs($change) }}%</span>@endif
                                    Önceki {{ $format($p[$key]) }}
                                @endif
                            </p>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- 3. The system checks, failing ones first. --}}
        <section id="kontroller" class="scroll-mt-20" data-testid="ads-checks">
            <div class="section-head">
                <h2 class="section-title">Sistem kontrolleri @if (($checks['items'] ?? []) !== [])<span class="section-count">{{ collect($checks['items'])->where('state', 'fail')->count() }} sorun</span>@endif</h2>
                <button type="button" wire:click="recheck" @disabled(! $bound) class="btn-quiet ml-auto">Yeniden kontrol et</button>
            </div>
            <div class="card">
                @forelse ($checkRows($checks['items'] ?? []) as $check)
                    <div class="card-row flex-wrap items-start gap-y-1 sm:flex-nowrap">
                        <span class="min-w-0 text-sm font-medium text-gray-900 sm:w-48 sm:shrink-0 dark:text-white">{{ $check['label'] }}</span>
                        <x-ta.pill :tone="$checkBadge[$check['state']][1]" class="shrink-0 sm:mt-0.5 sm:w-20">{{ $checkBadge[$check['state']][0] }}</x-ta.pill>
                        <span class="min-w-0 basis-full text-[13px] text-gray-500 sm:basis-auto dark:text-gray-400">{{ $check['reason'] }}</span>
                    </div>
                @empty
                    <x-ta.empty-state icon="none" size="sm" title="Kontroller henüz çalışmadı." />
                @endforelse
            </div>
        </section>

    @elseif ($tab === 'todo')
        <section data-testid="ads-suggestions">
            <div class="section-head">
                <h2 class="section-title">Açık öneriler <span class="section-count">{{ count($suggestions) }}</span></h2>
                <div class="flex w-full flex-wrap items-center gap-2 sm:ml-auto sm:w-auto">
                    <button type="button" wire:click="recheck" @disabled(! $bound) class="{{ $btn }}">Kontrolleri çalıştır</button>
                    <span class="inline-flex items-center">
                        <button type="button" wire:click="reviewTerms" wire:loading.attr="disabled" @disabled(! $operational || ! $bound || ($termsState['status'] ?? null) === 'running') class="{{ $btn }}">Arama terimlerini incele</button>
                        <x-operator.ai-prompt-info operation="google_ads.search_terms" />
                    </span>
                    @if ($canWrite && $selectedNegatives !== [])
                        <button type="button" x-on:click="if (confirm('Seçilen negatifler Google Ads paylaşılan listesine eklensin mi? Sonradan geri alınabilir.')) $wire.sendNegatives()" class="{{ $primary }}">Seçilenleri gönder ({{ count($selectedNegatives) }})</button>
                    @endif
                </div>
            </div>
            @unless ($operational)<p class="mb-2 text-xs text-gray-500">Marka operasyonel değil; AI kapalı.</p>@endunless
            @if ($line = $stateLine($termsState))
                <p class="mb-2 text-xs {{ $line[0] }}" @if ($line[2]) wire:poll.5s @endif>Arama terimleri: {{ $line[1] }}</p>
            @endif
            <div class="card">
                @forelse ($suggestions as $s)
                    @include('livewire.operator.google-ads.partials.suggestion', ['s' => $s])
                @empty
                    <x-ta.empty-state size="sm" title="Açık öneri yok." />
                @endforelse
            </div>
        </section>

        @include('livewire.operator.google-ads.partials.approved')

        @if ($writes->isNotEmpty())
            <section>
                <div class="section-head"><h2 class="section-title">Paylaşılan negatif listesi gönderimleri <span class="section-count">{{ $writes->count() }}</span></h2></div>
                <ul class="card">
                    @foreach ($writes as $write)
                        <li class="card-row flex-wrap text-sm sm:flex-nowrap" wire:key="write-{{ $write->id }}">
                            <span class="shrink-0 text-[13px] tabular-nums text-gray-500">{{ $write->created_at?->format('d.m.Y H:i') }}</span>
                            <span class="text-gray-900 dark:text-gray-200">{{ count((array) ($write->request_payload['keywords'] ?? [])) }} terim</span>
                            <span @class(['text-xs', 'text-success-700 dark:text-success-400' => in_array($write->status, ['succeeded', 'partial'], true), 'text-error-600 dark:text-error-400' => in_array($write->status, ['failed', 'undo_failed'], true), 'text-gray-500' => ! in_array($write->status, ['succeeded', 'partial', 'failed', 'undo_failed'], true)])
                                @if (in_array($write->status, ['queued', 'running', 'undoing'], true)) wire:poll.5s @endif>{{ ['queued' => 'Sırada', 'running' => 'Gönderiliyor', 'succeeded' => 'Gönderildi', 'partial' => 'Kısmen gönderildi', 'failed' => 'Başarısız', 'undoing' => 'Geri alınıyor', 'undone' => 'Geri alındı', 'undo_failed' => 'Geri alınamadı'][$write->status] ?? $write->status }}</span>
                            @if ($write->error)<span class="min-w-0 text-xs text-error-600 dark:text-error-400">{{ $write->error }}</span>@endif
                            @if ($canWrite && $write->isUndoable())
                                <button type="button" x-on:click="if (confirm('Bu gönderim geri alınsın mı?')) $wire.undoWrite({{ $write->id }})" class="{{ $small }} sm:ml-auto">Geri al</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

    @elseif ($tab === 'terms')
        <section>
            <div class="section-head">
                <h2 class="section-title">Arama terimleri <span class="section-count">{{ count($terms) }}</span></h2>
                <div class="flex w-full flex-wrap items-center gap-2 sm:ml-auto sm:w-auto">
                    <input type="search" wire:model.live.debounce.400ms="termFilter" placeholder="Terim ara" aria-label="Terim ara" class="{{ $input }} w-full sm:w-52">
                    <button type="button" wire:click="reviewTerms" wire:loading.attr="disabled" @disabled(! $operational || ! $bound || ($termsState['status'] ?? null) === 'running') class="{{ $btn }} h-9!">Tümünü incele</button>
                    <span class="inline-flex items-center">
                        <button type="button" wire:click="proposeNegatives" wire:loading.attr="disabled" @disabled(! $operational || $selectedTerms === [] || ($termsState['status'] ?? null) === 'running') class="{{ $primary }} h-9!">Negatif öner{{ $selectedTerms !== [] ? ' ('.count($selectedTerms).')' : '' }}</button>
                        <x-operator.ai-prompt-info operation="google_ads.search_terms" />
                    </span>
                </div>
            </div>
            @if ($line = $stateLine($termsState))
                <p class="mb-2 text-xs {{ $line[0] }}" @if ($line[2]) wire:poll.5s @endif>Arama terimleri: {{ $line[1] }}@if (($termsState['status'] ?? null) === 'ready'), <button type="button" wire:click="setTab('todo')" class="link-neutral">Yapılacaklar</button>@endif</p>
            @endif
            <div class="card overflow-x-auto" data-testid="ads-terms">
                @if ($terms === [])
                    <x-ta.empty-state icon="none" size="sm" title="Arama terimi verisi yok." />
                @else
                    <table class="data-table min-w-[820px]">
                        <thead><tr>
                            <th class="relative w-10"><span class="sr-only">Seç</span></th><th>Terim</th><th>Kampanya</th>
                            <th class="text-right!">Maliyet</th><th class="text-right!">Tık</th><th class="text-right!">Dönüşüm</th>
                            <th>Hizmet</th><th>AI</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($terms as $t)
                                <tr wire:key="term-{{ md5($t['term']) }}">
                                    <td><input type="checkbox" wire:model.live="selectedTerms" value="{{ $t['term'] }}" aria-label="Seç" class="block h-4 w-4 rounded border-gray-300"></td>
                                    <td class="min-w-[10rem] break-words font-medium text-gray-900 dark:text-white">{{ $t['term'] }}</td>
                                    <td class="text-[13px] text-gray-500">{{ implode(', ', $t['campaigns']) ?: '—' }}</td>
                                    <td @class(['whitespace-nowrap text-right tabular-nums', 'text-error-700 dark:text-error-400' => (float) $t['cost'] > 0 && (float) $t['conversions'] == 0.0]) @if ((float) $t['cost'] > 0 && (float) $t['conversions'] == 0.0) title="Dönüşüm getirmeden harcandı" @endif>{{ $money($t['cost']) }}</td>
                                    <td class="text-right tabular-nums">{{ $num($t['clicks']) }}</td>
                                    <td class="text-right tabular-nums">{{ $num($t['conversions'], 1) }}</td>
                                    <td class="text-[13px]">{{ $t['service'] ?? '—' }}</td>
                                    <td class="text-[13px]">
                                        @if ($t['verdict'])
                                            <span class="font-medium {{ $fitLabel[$t['verdict']['fit']][1] ?? '' }}" title="{{ $t['verdict']['reason'] }}">{{ $fitLabel[$t['verdict']['fit']][0] ?? $t['verdict']['fit'] }}</span>
                                            <span class="text-gray-500">{{ $t['verdict']['intent'] }}</span>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </section>

    @elseif ($tab === 'strategy')
        <section data-testid="ads-strategy">
            <div class="section-head">
                <h2 class="section-title">Taslaklar <span class="section-count">{{ count($strategy) }}</span></h2>
            </div>
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center">
                    <button type="button" wire:click="proposeStructure" wire:loading.attr="disabled" @disabled(! $operational || ! $bound || ($structureState['status'] ?? null) === 'running') class="{{ $btn }} h-9!">Kampanya yapısı öner</button>
                    <x-operator.ai-prompt-info operation="google_ads.structure" />
                </span>
                <span class="hidden h-5 border-l border-gray-200 sm:block dark:border-gray-800" aria-hidden="true"></span>
                <select wire:model="adGroupKey" aria-label="Reklam grubu" class="{{ $input }} w-full pr-8 sm:w-auto sm:max-w-xs">
                    <option value="">Reklam grubu seçin</option>
                    @foreach ($adGroups as $option)
                        <option value="{{ $option['key'] }}">{{ \Illuminate\Support\Str::limit($option['label'], 80) }}</option>
                    @endforeach
                </select>
                <span class="inline-flex items-center">
                    <button type="button" wire:click="writeAds" wire:loading.attr="disabled" @disabled(! $operational || ! $bound || ($adsState['status'] ?? null) === 'running') class="{{ $btn }} h-9!">Reklam metni yaz</button>
                    <x-operator.ai-prompt-info operation="google_ads.ad_texts" />
                </span>
            </div>
            @foreach (['Kampanya yapısı' => $structureState, 'Reklam metni' => $adsState] as $label => $state)
                @if ($line = $stateLine($state))
                    <p class="mb-2 text-xs {{ $line[0] }}" @if ($line[2]) wire:poll.5s @endif>{{ $label }}: {{ $line[1] }}</p>
                @endif
            @endforeach
            <div class="card">
                @forelse ($strategy as $s)
                    @include('livewire.operator.google-ads.partials.suggestion', ['s' => $s])
                @empty
                    <x-ta.empty-state icon="none" size="sm" title="Taslak yok." />
                @endforelse
            </div>
        </section>
        @include('livewire.operator.google-ads.partials.approved')

    @elseif ($tab === 'measurement')
        <section>
            @php $tracking = collect($checks['items'] ?? [])->whereIn('id', ['conversion_tracking', 'conversion_goals', 'landing']); @endphp
            <div class="section-head"><h2 class="section-title">İzleme kontrolleri</h2></div>
            <div class="card">
                @forelse ($tracking as $check)
                    <div class="card-row flex-wrap items-start gap-y-1 sm:flex-nowrap">
                        <span class="min-w-0 text-sm font-medium text-gray-900 sm:w-48 sm:shrink-0 dark:text-white">{{ $check['label'] }}</span>
                        <x-ta.pill :tone="$checkBadge[$check['state']][1]" class="shrink-0 sm:mt-0.5 sm:w-20">{{ $checkBadge[$check['state']][0] }}</x-ta.pill>
                        <span class="min-w-0 basis-full text-[13px] text-gray-500 sm:basis-auto dark:text-gray-400">{{ $check['reason'] }}</span>
                    </div>
                @empty
                    <x-ta.empty-state icon="none" size="sm" title="Kontroller henüz çalışmadı." />
                @endforelse
            </div>
        </section>
        <section data-testid="ads-conversions">
            <div class="section-head"><h2 class="section-title">Dönüşüm işlemleri <span class="section-count">{{ count($conversionActions) }}</span></h2></div>
            <div class="card overflow-x-auto">
                @if ($conversionActions === [])
                    <x-ta.empty-state icon="none" size="sm" title="Dönüşüm işlemi verisi yok." />
                @else
                    <table class="data-table min-w-[600px]">
                        <thead><tr><th>Dönüşüm</th><th>Hedef</th><th>Durum</th><th class="text-right!">30 gün</th><th>Son dönüşüm</th></tr></thead>
                        <tbody>
                            @foreach ($conversionActions as $action)
                                <tr>
                                    <td class="text-gray-900 dark:text-white">{{ $action['name'] }} <span class="text-xs text-gray-500">{{ $categoryLabel[strtoupper((string) $action['category'])] ?? '' }}</span></td>
                                    <td>{{ $action['primary'] ? 'Birincil' : 'İkincil' }}</td>
                                    <td class="text-[13px]">{{ ['ENABLED' => 'Etkin', 'REMOVED' => 'Kaldırıldı', 'HIDDEN' => 'Gizli'][$action['status']] ?? ($action['status'] ?? 'veri yok') }}</td>
                                    <td class="text-right tabular-nums">{{ $num($action['conversions'], 1) }}</td>
                                    <td @class(['whitespace-nowrap text-[13px] tabular-nums', 'text-gray-500' => $action['last_conversion'] === null])>{{ $action['last_conversion'] ? \Carbon\CarbonImmutable::parse($action['last_conversion'])->format('d.m.Y') : 'yok' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </section>
        <section data-testid="ads-lead-quality">
            <div class="section-head">
                <h2 class="section-title" title="Google Ads dönüşümünün karşılığı: gerçek form, uygun müşteri, randevu ve satış, her ay elle girilir.">Lead kalitesi <span class="section-count">elle</span></h2>
                <select wire:change="setLeadMonth($event.target.value)" aria-label="Ay" class="{{ $input }} pr-8 sm:ml-auto">
                    @foreach ($leadMonths as $month)
                        <option value="{{ $month }}" @selected($month === $leadMonthValue)>{{ \Illuminate\Support\Carbon::parse($month.'-01')->locale('tr')->translatedFormat('F Y') }}</option>
                    @endforeach
                </select>
            </div>
            <div class="card overflow-x-auto">
                @if ($leadQuality === [])
                    <x-ta.empty-state icon="none" size="sm" title="Bu ay kampanya verisi yok." />
                @else
                    <table class="data-table min-w-[720px]">
                        <thead><tr><th>Kampanya</th><th class="text-right!">Google Ads dönüşüm</th>
                            @foreach ($leadFields as $label)<th>{{ $label }}</th>@endforeach<th class="relative"><span class="sr-only">Kaydet</span></th></tr></thead>
                        <tbody>
                            @foreach ($leadQuality as $row)
                                <tr wire:key="lead-{{ $row['campaign_id'] }}">
                                    <td class="text-gray-900 dark:text-white">{{ $row['name'] }}</td>
                                    <td class="text-right tabular-nums">{{ $row['ads_conversions'] === null ? 'veri yok' : $num($row['ads_conversions'], 1) }}</td>
                                    @foreach ($leadFields as $field => $label)
                                        <td class="py-2!"><input type="number" min="0" wire:model="leadRows.{{ $row['campaign_id'] }}.{{ $field }}" aria-label="{{ $label }}" class="h-8 w-20 rounded-lg border border-gray-200 bg-white px-2 text-sm tabular-nums dark:border-gray-700 dark:bg-gray-900"></td>
                                    @endforeach
                                    <td class="py-2! text-right"><button type="button" wire:click="saveLeadQuality('{{ $row['campaign_id'] }}')" class="{{ $small }}">Kaydet</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </section>

    @elseif ($tab === 'analysis')
        <section>
            <div class="section-head">
                <h2 class="section-title">{{ $levels[$level] }} <span class="section-count">{{ count($analysis) }}</span></h2>
                <div class="no-scrollbar flex max-w-full gap-1.5 overflow-x-auto sm:ml-auto" role="group" aria-label="Kırılım">
                    @foreach ($levels as $key => $label)
                        <button type="button" wire:click="setLevel('{{ $key }}')" @if ($level === $key) aria-pressed="true" @endif @class(['seg h-8! px-3!', 'seg-active' => $level === $key])>{{ $label }}</button>
                    @endforeach
                </div>
            </div>
            <div class="card overflow-x-auto" data-testid="ads-analysis">
                @if ($analysis === [])
                    <x-ta.empty-state icon="none" size="sm" title="Veri yok." />
                @else
                    <table class="data-table min-w-[720px]">
                        <thead><tr><th>{{ $levels[$level] }}</th><th class="text-right!">Maliyet</th><th class="text-right!">Önceki</th><th class="text-right!">Tık</th><th class="text-right!">Dönüşüm</th><th class="text-right!">Önceki</th><th class="text-right!">Dönüşüm başı</th></tr></thead>
                        <tbody class="tabular-nums">
                            @foreach ($analysis as $row)
                                <tr>
                                    <td class="text-gray-900 dark:text-white">{{ $row['label'] }}@if ($row['sub']) <span class="block text-xs text-gray-500">{{ $row['sub'] }}</span>@endif @if ($row['open'] ?? null)<x-operator.open-link :link="$row['open']" class="mt-0.5 flex! w-fit" />@endif</td>
                                    <td class="whitespace-nowrap text-right">{{ $money($row['cost']) }}</td>
                                    <td class="whitespace-nowrap text-right text-gray-500">{{ $money($row['prev_cost']) }}</td>
                                    <td class="text-right">{{ $num($row['clicks']) }}</td>
                                    <td class="text-right">{{ $num($row['conversions'], 1) }}</td>
                                    <td class="text-right text-gray-500">{{ $num($row['prev_conversions'], 1) }}</td>
                                    <td class="whitespace-nowrap text-right font-medium text-gray-900 dark:text-white">{{ $money($row['cpa']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </section>

    @elseif ($tab === 'settings')
        <section data-testid="ads-settings">
            <div class="section-head"><h2 class="section-title">Hesap ayarları</h2></div>
            <dl class="card">
                @foreach ([
                    'Bağlı hesap' => $settings['customer_id'] ?? 'bağlı değil',
                    'Para birimi' => $settings['currency'] ?? 'veri yok',
                    'Saat dilimi' => $settings['timezone'] ?? 'veri yok',
                    'Son toplama' => $settings['last_collected'] ?? 'veri yok',
                    'Hedef bölgeler' => collect($settings['areas'] ?? [])->map(fn ($a) => $a['name'].($a['physical_branch'] ? ' (şube)' : ''))->implode(', ') ?: 'veri yok',
                    'Diller' => implode(', ', $settings['languages'] ?? []) ?: 'veri yok',
                ] as $label => $value)
                    <div class="card-row flex-wrap items-start gap-y-0.5 text-sm sm:flex-nowrap">
                        <dt class="w-full text-[13px] text-gray-500 sm:w-44 sm:shrink-0">{{ $label }}</dt>
                        <dd class="min-w-0 break-words text-gray-900 dark:text-white">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
            <div class="mt-3 flex flex-wrap gap-2">
                <a href="{{ route('operator.integrations.google-ads.connector') }}" wire:navigate class="{{ $btn }}">Google Ads entegrasyonu</a>
                <a href="{{ route('operator.asset.sources', ['assetId' => $assetId]) }}" wire:navigate class="{{ $btn }}">Hesap bağlantısı</a>
                @if ($assetModel->brand)
                    <a href="{{ route('operator.brand', ['brand' => $assetModel->brand->id]) }}" wire:navigate class="{{ $btn }}">Marka (bölge, dil, hizmet)</a>
                @endif
            </div>
        </section>
    @endif
</div>
