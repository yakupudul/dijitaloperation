@php
    $num = fn ($value, int $decimals = 0) => $value === null ? '—' : number_format((float) $value, $decimals, ',', '.');
    $cur = $numbers['currency'] ?? ($settings['currency'] ?? '');
    $money = fn ($value) => $value === null ? '—' : number_format((float) $value, 2, ',', '.');
    $pct = fn ($now, $before) => ($now === null || $before === null || (float) $before == 0.0) ? null : (int) round(((float) $now - (float) $before) / (float) $before * 100);
    /** Sade panel: sections are flat (heading + content), tables and lists have hairline rows only. */
    $th = 'px-3 py-2 text-xs font-normal text-gray-500 dark:text-gray-400';
    $tr = 'border-b border-gray-200 dark:border-gray-800';
    /** Google Ads conversion categories in plain Turkish (raw enums never reach the screen). */
    $categoryLabel = ['SUBMIT_LEAD_FORM' => 'form', 'PHONE_CALL_LEAD' => 'telefon araması', 'CONTACT' => 'iletişim', 'BOOK_APPOINTMENT' => 'randevu', 'REQUEST_QUOTE' => 'teklif isteği', 'PURCHASE' => 'satın alma', 'QUALIFIED_LEAD' => 'uygun müşteri', 'CONVERTED_LEAD' => 'satışa dönen müşteri', 'IMPORTED_LEAD' => 'içe aktarılan müşteri', 'GET_DIRECTIONS' => 'yol tarifi', 'PAGE_VIEW' => 'sayfa görüntüleme', 'SIGNUP' => 'kayıt', 'DOWNLOAD' => 'indirme', 'ADD_TO_CART' => 'sepete ekleme', 'BEGIN_CHECKOUT' => 'ödeme başlatma', 'ENGAGEMENT' => 'etkileşim', 'OUTBOUND_CLICK' => 'dış tıklama', 'STORE_VISIT' => 'mağaza ziyareti', 'DEFAULT' => 'diğer', 'UNKNOWN' => 'diğer'];
    $btn = 'rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-inset ring-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-white/[0.04]';
    $primary = 'rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-500 disabled:opacity-50';
    $small = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    $input = 'rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900';
    $stateLine = fn (?array $state): ?array => match ($state['status'] ?? null) {
        'running' => ['text-gray-500', 'Çalışıyor…', true],
        'failed' => ['text-error-600', (string) ($state['message'] ?? 'Başarısız.'), false],
        'ready' => ['text-success-600', (string) ($state['message'] ?? 'Hazır.'), false],
        default => null,
    };
    $checkBadge = ['pass' => ['Geçti', 'ok'], 'fail' => ['Sorun', 'critical'], 'nodata' => ['Veri yok', 'info']];
    $matchLabel = fn (string $m) => \App\Services\GoogleAds\GoogleAdsAssistant::matchLabel($m);
    $scopeLabel = fn (array $a) => ['shared' => 'Paylaşılan liste', 'campaign' => 'Kampanya: '.($a['campaign'] ?? ''), 'ad_group' => 'Reklam grubu: '.($a['ad_group'] ?? '')][$a['scope'] ?? ''] ?? '';
    $fitLabel = ['uygun' => ['Uygun', 'text-success-700 dark:text-success-300'], 'kismen' => ['Kısmen', 'text-warning-700 dark:text-warning-300'], 'uygunsuz' => ['Uygunsuz', 'text-error-700 dark:text-error-300']];
@endphp

<div class="space-y-8">
    @include('livewire.demo.partials.flash')
    <x-operator.asset-context :asset-id="$assetId" :title="$asset['name'] ?? 'Google Ads'">
        <x-slot:mark><x-demo.digital-asset-mark type="google_ads" size="lg" class="mt-0.5 shrink-0" /></x-slot:mark>
        <x-slot:meta>Google Ads{{ $assetModel->brand ? ' · '.$assetModel->brand->name : '' }}@if ($numbers && $numbers['last_date']) · Son veri: {{ $numbers['last_date'] }}@endif</x-slot:meta>
        <x-slot:actions>
            @if ($ranged)
                <x-operator.date-range-picker :range="$range" :last-day="$lastDay" note="Google Ads verisi her gün dünü kapsar." class="w-full sm:w-auto" />
            @endif
            <button type="button" wire:click="refreshData" wire:loading.attr="disabled" @disabled(! $bound) class="{{ $btn }}">Verileri yenile</button>
        </x-slot:actions>
        <x-slot:tabs>
            <nav class="flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Google Ads">
                @foreach ($tabs as $key => $label)
                    <button type="button" wire:click="setTab('{{ $key }}')" @class([
                        'whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium',
                        'border-gray-900 text-gray-900 dark:border-white dark:text-white' => $tab === $key,
                        'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200' => $tab !== $key,
                    ])>{{ $label }}@if ($key === 'todo' && $openCount > 0) <span class="ml-1 text-xs font-normal tabular-nums text-gray-500">{{ $openCount }}</span>@endif</button>
                @endforeach
            </nav>
        </x-slot:tabs>
    </x-operator.asset-context>

    @if (! $bound)
        <section class="flex flex-wrap items-center gap-3 text-sm">
            <x-ta.pill tone="look" class="text-sm">Google Ads hesabı bağlı değil</x-ta.pill>
            <a href="{{ route('operator.asset.sources', ['assetId' => $assetId]) }}" wire:navigate class="{{ $primary }} inline-flex">Bağla</a>
        </section>
    @endif

    @if ($tab === 'overview')
        @if ($numbers)
            @php $c = $numbers['current']; $p = $numbers['previous']; @endphp
            <div class="grid grid-cols-2 gap-x-8 gap-y-6 lg:grid-cols-4" data-testid="ads-numbers" title="{{ $range->label() }}, değişim önceki döneme göre">
                @foreach ([['Maliyet', 'cost', true, false], ['Tıklama', 'clicks', false, false], ['Dönüşüm', 'conversions', false, false], ['Dönüşüm başı maliyet', 'cpa', true, true]] as [$label, $key, $isMoney, $lowerBetter])
                    @php $neutralDelta = $key === 'cost'; /* spend change is information, not good or bad */ @endphp
                    @php $change = $pct($c[$key], $p[$key]); @endphp
                    <x-ta.metric-card :label="$label"
                        :value="($isMoney ? $money($c[$key]) : $num($c[$key], $key === 'conversions' ? 1 : 0)).($isMoney && $c[$key] !== null ? ' '.$cur : '')"
                        :delta="$c[$key] === null ? 'veri yok' : ($change === null ? null : ($change > 0 ? '+' : ($change < 0 ? '−' : '')).abs($change).'%')"
                        :tone="$change === null || $neutralDelta ? 'neutral' : ((($change >= 0) xor $lowerBetter) ? 'positive' : 'negative')" />
                @endforeach
            </div>
        @endif
        <section data-testid="ads-checks">
            <div class="mb-2 flex items-center justify-between gap-3">
                <h2 class="section-title">Sistem kontrolleri</h2>
                <button type="button" wire:click="recheck" @disabled(! $bound) class="link-neutral text-sm">Yeniden kontrol et</button>
            </div>
            <div>
                @forelse ($checks['items'] ?? [] as $check)
                    <div class="flex flex-wrap items-start gap-x-3 gap-y-1 border-b border-gray-200 py-2.5 text-sm sm:flex-nowrap dark:border-gray-800">
                        <span class="min-w-0 font-medium text-gray-900 sm:w-44 sm:shrink-0 dark:text-white">{{ $check['label'] }}</span>
                        <x-ta.pill :tone="$checkBadge[$check['state']][1]" class="shrink-0 sm:w-20">{{ $checkBadge[$check['state']][0] }}</x-ta.pill>
                        <span class="min-w-0 basis-full text-gray-500 sm:basis-auto dark:text-gray-400">{{ $check['reason'] }}</span>
                    </div>
                @empty
                    <x-ta.empty-state size="sm" title="Kontroller henüz çalışmadı." />
                @endforelse
            </div>
        </section>
        @if ($openCount > 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $openCount }} açık öneri, <button type="button" wire:click="setTab('todo')" class="link-neutral">Yapılacaklar</button></p>
        @endif

    @elseif ($tab === 'todo')
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="recheck" @disabled(! $bound) class="{{ $btn }}">Kontrolleri çalıştır</button>
            <button type="button" wire:click="reviewTerms" wire:loading.attr="disabled" @disabled(! $operational || ! $bound || ($termsState['status'] ?? null) === 'running') class="{{ $btn }}">Arama terimlerini incele</button>
            <x-operator.ai-prompt-info operation="google_ads.search_terms" />
            @if ($canWrite && $selectedNegatives !== [])
                <button type="button" x-on:click="if (confirm('Seçilen negatifler Google Ads paylaşılan listesine eklensin mi? Sonradan geri alınabilir.')) $wire.sendNegatives()" class="{{ $primary }}">Seçilenleri gönder ({{ count($selectedNegatives) }})</button>
            @endif
            @unless ($operational)<span class="text-xs text-gray-500">Marka operasyonel değil; AI kapalı.</span>@endunless
        </div>
        @if ($line = $stateLine($termsState))
            <p class="text-xs {{ $line[0] }}" @if ($line[2]) wire:poll.5s @endif>Arama terimleri: {{ $line[1] }}</p>
        @endif

        <section class="divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800" data-testid="ads-suggestions">
            @forelse ($suggestions as $s)
                @include('livewire.operator.google-ads.partials.suggestion', ['s' => $s])
            @empty
                <x-ta.empty-state size="sm" title="Açık öneri yok." />
            @endforelse
        </section>

        @include('livewire.operator.google-ads.partials.approved')

        @if ($writes->isNotEmpty())
            <section>
                <h2 class="section-title mb-2">Paylaşılan negatif listesi gönderimleri</h2>
                <div>
                    @foreach ($writes as $write)
                        <div class="flex flex-wrap items-center gap-3 border-b border-gray-200 py-2.5 text-sm dark:border-gray-800" wire:key="write-{{ $write->id }}">
                            <span class="text-gray-500">{{ $write->created_at?->format('d.m.Y H:i') }}</span>
                            <span class="text-gray-800 dark:text-gray-200">{{ count((array) ($write->request_payload['keywords'] ?? [])) }} terim</span>
                            <span @class(['text-xs', 'text-success-700' => in_array($write->status, ['succeeded', 'partial'], true), 'text-error-600' => in_array($write->status, ['failed', 'undo_failed'], true), 'text-gray-500' => ! in_array($write->status, ['succeeded', 'partial', 'failed', 'undo_failed'], true)])
                                @if (in_array($write->status, ['queued', 'running', 'undoing'], true)) wire:poll.5s @endif>{{ ['queued' => 'Sırada', 'running' => 'Gönderiliyor', 'succeeded' => 'Gönderildi', 'partial' => 'Kısmen gönderildi', 'failed' => 'Başarısız', 'undoing' => 'Geri alınıyor', 'undone' => 'Geri alındı', 'undo_failed' => 'Geri alınamadı'][$write->status] ?? $write->status }}</span>
                            @if ($write->error)<span class="text-xs text-error-600">{{ $write->error }}</span>@endif
                            @if ($canWrite && $write->isUndoable())
                                <button type="button" x-on:click="if (confirm('Bu gönderim geri alınsın mı?')) $wire.undoWrite({{ $write->id }})" class="link-neutral ml-auto text-xs">Geri al</button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

    @elseif ($tab === 'terms')
        <div class="flex flex-wrap items-center gap-2">
            <input type="search" wire:model.live.debounce.400ms="termFilter" placeholder="Terim ara" aria-label="Terim ara" class="{{ $input }} w-full sm:w-56">
            <button type="button" wire:click="reviewTerms" wire:loading.attr="disabled" @disabled(! $operational || ! $bound || ($termsState['status'] ?? null) === 'running') class="{{ $btn }}">Tümünü incele</button>
            <button type="button" wire:click="proposeNegatives" wire:loading.attr="disabled" @disabled(! $operational || $selectedTerms === [] || ($termsState['status'] ?? null) === 'running') class="{{ $primary }}">Negatif öner{{ $selectedTerms !== [] ? ' ('.count($selectedTerms).')' : '' }}</button>
            <x-operator.ai-prompt-info operation="google_ads.search_terms" />
        </div>
        @if ($line = $stateLine($termsState))
            <p class="text-xs {{ $line[0] }}" @if ($line[2]) wire:poll.5s @endif>Arama terimleri: {{ $line[1] }}@if (($termsState['status'] ?? null) === 'ready'), <button type="button" wire:click="setTab('todo')" class="link-neutral">Yapılacaklar</button>@endif</p>
        @endif
        <section class="overflow-x-auto" data-testid="ads-terms">
            @if ($terms === [])
                <p class="py-5 text-sm text-gray-500">Arama terimi verisi yok.</p>
            @else
                <table class="w-full text-sm">
                    <thead><tr class="{{ $tr }} text-left">
                        <th class="{{ $th }}"></th><th class="{{ $th }}">Terim</th><th class="{{ $th }}">Kampanya</th>
                        <th class="{{ $th }} text-right">Maliyet</th><th class="{{ $th }} text-right">Tık</th><th class="{{ $th }} text-right">Dönüşüm</th>
                        <th class="{{ $th }}">Hizmet</th><th class="{{ $th }}">AI</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($terms as $t)
                            <tr class="{{ $tr }} hover:bg-gray-50 dark:hover:bg-white/[0.03]" wire:key="term-{{ md5($t['term']) }}">
                                <td class="px-3 py-1.5"><input type="checkbox" wire:model.live="selectedTerms" value="{{ $t['term'] }}" aria-label="Seç" class="rounded border-gray-300"></td>
                                <td class="min-w-[10rem] break-words px-3 py-1.5 text-gray-900 dark:text-white">{{ $t['term'] }}</td>
                                <td class="px-3 py-1.5 text-xs text-gray-500">{{ implode(', ', $t['campaigns']) ?: '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums">{{ $money($t['cost']) }}</td>
                                <td class="px-3 py-1.5 text-right">{{ $num($t['clicks']) }}</td>
                                <td class="px-3 py-1.5 text-right">{{ $num($t['conversions'], 1) }}</td>
                                <td class="px-3 py-1.5 text-xs">{{ $t['service'] ?? '—' }}</td>
                                <td class="px-3 py-1.5 text-xs">
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
        </section>

    @elseif ($tab === 'strategy')
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="proposeStructure" wire:loading.attr="disabled" @disabled(! $operational || ! $bound || ($structureState['status'] ?? null) === 'running') class="{{ $btn }}">Kampanya yapısı öner</button>
            <x-operator.ai-prompt-info operation="google_ads.structure" />
            <select wire:model="adGroupKey" aria-label="Reklam grubu" class="{{ $input }} w-full sm:w-auto sm:max-w-xs">
                <option value="">Reklam grubu seçin</option>
                @foreach ($adGroups as $option)
                    <option value="{{ $option['key'] }}">{{ \Illuminate\Support\Str::limit($option['label'], 80) }}</option>
                @endforeach
            </select>
            <button type="button" wire:click="writeAds" wire:loading.attr="disabled" @disabled(! $operational || ! $bound || ($adsState['status'] ?? null) === 'running') class="{{ $btn }}">Reklam metni yaz</button>
            <x-operator.ai-prompt-info operation="google_ads.ad_texts" />
        </div>
        @foreach (['Kampanya yapısı' => $structureState, 'Reklam metni' => $adsState] as $label => $state)
            @if ($line = $stateLine($state))
                <p class="text-xs {{ $line[0] }}" @if ($line[2]) wire:poll.5s @endif>{{ $label }}: {{ $line[1] }}</p>
            @endif
        @endforeach
        <section class="divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800" data-testid="ads-strategy">
            @forelse ($strategy as $s)
                @include('livewire.operator.google-ads.partials.suggestion', ['s' => $s])
            @empty
                <x-ta.empty-state size="sm" title="Taslak yok." />
            @endforelse
        </section>
        @include('livewire.operator.google-ads.partials.approved')

    @elseif ($tab === 'measurement')
        <section class="overflow-x-auto" data-testid="ads-conversions">
            <h2 class="section-title mb-2">Dönüşüm işlemleri</h2>
            @if ($conversionActions === [])
                <p class="py-5 text-sm text-gray-500">Dönüşüm işlemi verisi yok.</p>
            @else
                <table class="w-full text-sm">
                    <thead><tr class="{{ $tr }} text-left"><th class="{{ $th }} pl-0">Dönüşüm</th><th class="{{ $th }}">Hedef</th><th class="{{ $th }}">Durum</th><th class="{{ $th }} text-right">30 gün</th><th class="{{ $th }}">Son dönüşüm</th></tr></thead>
                    <tbody>
                        @foreach ($conversionActions as $action)
                            <tr class="{{ $tr }}">
                                <td class="py-1.5 pr-3 text-gray-900 dark:text-white">{{ $action['name'] }} <span class="text-xs text-gray-500">{{ $categoryLabel[strtoupper((string) $action['category'])] ?? '' }}</span></td>
                                <td class="px-3 py-1.5">{{ $action['primary'] ? 'Birincil' : 'İkincil' }}</td>
                                <td class="px-3 py-1.5 text-xs">{{ ['ENABLED' => 'Etkin', 'REMOVED' => 'Kaldırıldı', 'HIDDEN' => 'Gizli'][$action['status']] ?? ($action['status'] ?? 'veri yok') }}</td>
                                <td class="px-3 py-1.5 text-right">{{ $num($action['conversions'], 1) }}</td>
                                <td class="px-3 py-1.5 text-xs">{{ $action['last_conversion'] ?? 'yok' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
        <section class="overflow-x-auto" data-testid="ads-lead-quality">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h2 class="section-title">Lead kalitesi <span class="text-sm font-normal text-gray-500">elle</span></h2>
                <select wire:change="setLeadMonth($event.target.value)" aria-label="Ay" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                    @foreach ($leadMonths as $month)
                        <option value="{{ $month }}" @selected($month === $leadMonthValue)>{{ $month }}</option>
                    @endforeach
                </select>
            </div>
            @if ($leadQuality === [])
                <p class="py-5 text-sm text-gray-500">Bu ay kampanya verisi yok.</p>
            @else
                <table class="w-full text-sm">
                    <thead><tr class="{{ $tr }} text-left"><th class="{{ $th }} pl-0">Kampanya</th><th class="{{ $th }} text-right">Google Ads dönüşüm</th>
                        @foreach ($leadFields as $label)<th class="{{ $th }}">{{ $label }}</th>@endforeach<th></th></tr></thead>
                    <tbody>
                        @foreach ($leadQuality as $row)
                            <tr class="border-b border-gray-200 dark:border-gray-800" wire:key="lead-{{ $row['campaign_id'] }}">
                                <td class="py-1.5 pr-3 text-gray-900 dark:text-white">{{ $row['name'] }}</td>
                                <td class="px-3 py-1.5 text-right">{{ $row['ads_conversions'] === null ? 'veri yok' : $num($row['ads_conversions'], 1) }}</td>
                                @foreach ($leadFields as $field => $label)
                                    <td class="px-3 py-1.5"><input type="number" min="0" wire:model="leadRows.{{ $row['campaign_id'] }}.{{ $field }}" aria-label="{{ $label }}" class="w-20 rounded border-gray-200 text-sm dark:border-gray-700 dark:bg-gray-900"></td>
                                @endforeach
                                <td class="px-3 py-1.5"><button type="button" wire:click="saveLeadQuality('{{ $row['campaign_id'] }}')" class="{{ $small }}">Kaydet</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
        <section>
            <h2 class="section-title mb-2">İzleme kontrolleri</h2>
            @php $tracking = collect($checks['items'] ?? [])->whereIn('id', ['conversion_tracking', 'conversion_goals', 'landing']); @endphp
            @forelse ($tracking as $check)
                <div class="flex flex-wrap items-start gap-x-3 gap-y-1 border-b border-gray-200 py-2.5 text-sm sm:flex-nowrap dark:border-gray-800">
                    <span class="min-w-0 font-medium text-gray-900 sm:w-44 sm:shrink-0 dark:text-white">{{ $check['label'] }}</span>
                    <x-ta.pill :tone="$checkBadge[$check['state']][1]" class="shrink-0 sm:w-20">{{ $checkBadge[$check['state']][0] }}</x-ta.pill>
                    <span class="min-w-0 basis-full text-gray-500 sm:basis-auto dark:text-gray-400">{{ $check['reason'] }}</span>
                </div>
            @empty
                <p class="py-4 text-sm text-gray-500">Kontroller henüz çalışmadı.</p>
            @endforelse
        </section>

    @elseif ($tab === 'analysis')
        <div class="flex flex-wrap items-center gap-2 text-sm">
            @foreach ($levels as $key => $label)
                <button type="button" wire:click="setLevel('{{ $key }}')" @class(['rounded-lg px-2.5 py-1', 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $level === $key, 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5' => $level !== $key])>{{ $label }}</button>
            @endforeach
        </div>
        <section class="overflow-x-auto" data-testid="ads-analysis">
            @if ($analysis === [])
                <p class="py-5 text-sm text-gray-500">Veri yok.</p>
            @else
                <table class="w-full text-sm">
                    <thead><tr class="{{ $tr }} text-left"><th class="{{ $th }} pl-0">{{ $levels[$level] }}</th><th class="{{ $th }} text-right">Maliyet</th><th class="{{ $th }} text-right">Önceki</th><th class="{{ $th }} text-right">Tık</th><th class="{{ $th }} text-right">Dönüşüm</th><th class="{{ $th }} text-right">Önceki</th><th class="{{ $th }} text-right">Dönüşüm başı</th></tr></thead>
                    <tbody>
                        @foreach ($analysis as $row)
                            <tr class="border-b border-gray-200 dark:border-gray-800">
                                <td class="py-1.5 pr-3 text-gray-900 dark:text-white">{{ $row['label'] }}@if ($row['sub']) <span class="block text-xs text-gray-500">{{ $row['sub'] }}</span>@endif</td>
                                <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums">{{ $money($row['cost']) }}</td>
                                <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums text-gray-500">{{ $money($row['prev_cost']) }}</td>
                                <td class="px-3 py-1.5 text-right">{{ $num($row['clicks']) }}</td>
                                <td class="px-3 py-1.5 text-right">{{ $num($row['conversions'], 1) }}</td>
                                <td class="px-3 py-1.5 text-right text-gray-500">{{ $num($row['prev_conversions'], 1) }}</td>
                                <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums">{{ $money($row['cpa']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

    @elseif ($tab === 'settings')
        <section class="text-sm" data-testid="ads-settings">
            @foreach ([
                'Bağlı hesap' => $settings['customer_id'] ?? 'bağlı değil',
                'Para birimi' => $settings['currency'] ?? 'veri yok',
                'Saat dilimi' => $settings['timezone'] ?? 'veri yok',
                'Son toplama' => $settings['last_collected'] ?? 'veri yok',
                'Hedef bölgeler' => collect($settings['areas'] ?? [])->map(fn ($a) => $a['name'].($a['physical_branch'] ? ' (şube)' : ''))->implode(', ') ?: 'veri yok',
                'Diller' => implode(', ', $settings['languages'] ?? []) ?: 'veri yok',
            ] as $label => $value)
                <div class="flex flex-wrap gap-x-3 gap-y-0.5 border-b border-gray-200 py-2.5 sm:flex-nowrap dark:border-gray-800"><span class="w-full text-gray-500 sm:w-40 sm:shrink-0">{{ $label }}</span><span class="min-w-0 break-words text-gray-900 dark:text-white">{{ $value }}</span></div>
            @endforeach
            <div class="flex flex-wrap gap-x-5 gap-y-2 py-3">
                <a href="{{ route('operator.integrations.google-ads.connector') }}" wire:navigate class="link-neutral">Google Ads entegrasyonu</a>
                <a href="{{ route('operator.asset.sources', ['assetId' => $assetId]) }}" wire:navigate class="link-neutral">Hesap bağlantısı</a>
                @if ($assetModel->brand)
                    <a href="{{ route('operator.brand', ['brand' => $assetModel->brand->id]) }}" wire:navigate class="link-neutral">Marka (bölge, dil, hizmet)</a>
                @endif
            </div>
        </section>
    @endif
</div>
