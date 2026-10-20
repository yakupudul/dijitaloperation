@php
    $currency = $account['currency'] ?? '';
    $num = fn ($value, int $decimals = 0) => $value === null ? '—' : number_format((float) $value, $decimals, ',', '.');
    $money = fn ($value) => $value === null ? '—' : number_format((float) $value, 2, ',', '.').' '.$currency;
    $pct = fn ($value) => $value === null ? '—' : (($value > 0 ? '+' : '').number_format((float) $value, 1, ',', '.').'%');
    $count = fn (float $value) => $value >= 10000 ? $num($value / 1000, 1).' bin' : $num($value, $value == floor($value) ? 0 : 1);
    $types = \App\Services\Meta\MetaCampaignBoard::TYPES;
    [$unit, $unitCost] = $types[$campaign['type']];
    /** Meta enums (status, optimisation goal, destination) in plain Turkish; unknown ones are humanised, never shown raw. */
    $enumText = fn (string $v): string => \Illuminate\Support\Str::of($v)->lower()->replace('_', ' ')->ucfirst()->toString();
    $metaStatus = fn (string $v): string => ['ACTIVE' => 'Aktif', 'PAUSED' => 'Durduruldu', 'DELETED' => 'Silindi', 'ARCHIVED' => 'Arşivlendi', 'IN_PROCESS' => 'İşleniyor', 'WITH_ISSUES' => 'Sorunlu', 'DISAPPROVED' => 'Reddedildi', 'PENDING_REVIEW' => 'İncelemede', 'CAMPAIGN_PAUSED' => 'Kampanya durduruldu', 'ADSET_PAUSED' => 'Set durduruldu'][strtoupper($v)] ?? $enumText($v);
    $optimizationText = fn (string $v): string => \App\Services\Meta\MetaStrategy::OPTIMIZATIONS[strtoupper($v)] ?? $enumText($v);
    $destinationText = fn (string $v): string => ['ON_AD' => 'Anında form', 'WEBSITE' => 'Web sitesi', 'MESSENGER' => 'Messenger', 'WHATSAPP' => 'WhatsApp', 'INSTAGRAM_DIRECT' => 'Instagram mesajı', 'MESSAGING_MESSENGER_WHATSAPP' => 'Messenger / WhatsApp', 'MESSAGING_INSTAGRAM_DIRECT_MESSENGER' => 'Instagram / Messenger', 'MESSAGING_INSTAGRAM_DIRECT_MESSENGER_WHATSAPP' => 'Instagram / Messenger / WhatsApp', 'MESSAGING_INSTAGRAM_DIRECT_WHATSAPP' => 'Instagram / WhatsApp', 'PHONE_CALL' => 'Telefon araması', 'APP' => 'Uygulama', 'ON_PAGE' => 'Facebook sayfası', 'ON_POST' => 'Gönderi', 'ON_VIDEO' => 'Video', 'UNDEFINED' => ''][strtoupper($v)] ?? $enumText($v);
    $chip = 'inline-flex h-7 items-center gap-1 whitespace-nowrap rounded-full pl-2.5 pr-1 text-xs font-medium';
    $statusLabel = ['live' => 'Yayında', 'paused' => 'Durmuş', 'ended' => 'Bitti'];
    $k = $campaign['kpis'];
    $change = fn ($a, $b) => \App\Services\Meta\MetaScreen::change($a, $b);

    // Chart: spend bars and the day's results line over 60 days, change events as dashed markers.
    $series = $campaign['series'];
    $dates = array_keys($series);
    $n = max(1, count($dates));
    $maxSpend = max(1.0, ...array_map(fn ($d) => (float) $d['spend'], array_values($series) ?: [['spend' => 0]]));
    $maxResults = max(1.0, ...array_map(fn ($d) => (float) $d[$campaign['type']] , array_values($series) ?: [[$campaign['type'] => 0]]));
    $w = 1000; $h = 200; $left = 8; $step = ($w - $left) / $n;
    $points = [];
    foreach (array_values($series) as $i => $d) {
        $points[] = round($left + $i * $step + $step / 2, 1).','.round($h - 12 - ((float) $d[$campaign['type']] / $maxResults) * ($h - 40), 1);
    }
    $eventDays = [];
    foreach ($campaign['events'] as $event) {
        $eventDays[$event['date']][] = $event;
    }
    $budgetText = $campaign['settings']['budget']['amount'] === null ? 'bütçe yok' : ($campaign['settings']['budget']['level'] === 'campaign' ? 'kampanya bütçesi ' : 'set bütçeleri ').$money($campaign['settings']['budget']['amount']).'/gün';
    $cprChange = $change($k['cpr'], $k['prev_cpr']);
@endphp

<div class="space-y-10">
    @include('livewire.demo.partials.flash')

    <section class="min-w-0" data-testid="meta-campaign-header">
        <nav class="mb-3 flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[13px] text-gray-500" aria-label="Konum">
            @if ($brand)<a href="{{ route('operator.brand', ['brand' => $brand->id]) }}" wire:navigate class="min-w-0 break-words hover:text-gray-900 dark:hover:text-white">{{ $brand->name }}</a><span class="text-gray-300 dark:text-gray-600" aria-hidden="true">›</span>@endif
            <a href="{{ route('operator.meta.overview', ['assetId' => $assetId]) }}" wire:navigate class="min-w-0 break-words hover:text-gray-900 dark:hover:text-white">{{ $asset['name'] ?? 'Meta' }}</a><span class="text-gray-300 dark:text-gray-600" aria-hidden="true">›</span>
            <a href="{{ route('operator.meta.overview', ['assetId' => $assetId, 'tab' => 'campaigns']) }}" wire:navigate class="hover:text-gray-900 dark:hover:text-white">Kampanyalar</a>
        </nav>
        <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
            <div class="min-w-0 flex-1 basis-80">
                <h1 class="page-title break-words">{{ $campaign['name'] }}</h1>
                <p class="page-sub flex flex-wrap items-center gap-x-1.5">
                    <span @class(['inline-block h-1.5 w-1.5 shrink-0 rounded-full', 'bg-success-500' => $campaign['status'] === 'live', 'bg-gray-300 dark:bg-gray-600' => $campaign['status'] !== 'live'])></span>
                    <span>{{ $statusLabel[$campaign['status']].($campaign['settings']['start'] ? ', '.\Carbon\CarbonImmutable::parse($campaign['settings']['start'])->format('d.m.Y').'’den beri' : '') }}. Hedef: {{ $campaign['objective'] }}, {{ $budgetText }}, {{ count($campaign['adsets']) }} reklam seti, {{ count($campaign['ads']) }} reklam</span>
                </p>
            </div>
            <a href="{{ $campaign['ads_manager_url'] }}" target="_blank" rel="noopener" class="btn h-9! w-full sm:w-auto">Reklam Yöneticisi’nde aç
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M7 17 17 7M9 7h8v8"/></svg></a>
        </div>

        {{-- Which service this campaign is counted under: the averages and the desk depend on it. --}}
        <div class="card mt-6" data-testid="meta-campaign-services">
            <div class="flex flex-wrap items-center gap-2 px-4 py-3">
                <span class="mr-1 text-[13px] text-gray-500">Hizmetler</span>
                @if ($entry['state'] === 'excluded')
                    <x-ta.pill tone="info">Hizmet dışı</x-ta.pill>
                    <button type="button" wire:click="reopen" class="btn-quiet h-7! px-2! text-xs!">Geri al</button>
                @endif
                @foreach ($entry['services'] as $s)
                    @if ($s['status'] === 'confirmed')
                        <span class="{{ $chip }} border border-gray-300 text-gray-900 dark:border-gray-700 dark:text-gray-200">
                            {{ $s['name'] }}
                            <button type="button" wire:click="removeService({{ $s['id'] }})" class="grid h-5 w-5 place-items-center rounded-full text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:hover:bg-white/10" aria-label="{{ $s['name'] }} hizmetini kaldır">×</button>
                        </span>
                    @else
                        <span class="{{ $chip }} border border-dashed border-gray-300 text-gray-700 dark:border-gray-600 dark:text-gray-300">
                            {{ $s['name'] }}
                            <button type="button" wire:click="confirmService({{ $s['id'] }})" class="grid h-5 w-5 place-items-center rounded-full font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-white/10" aria-label="{{ $s['name'] }} önerisini onayla">✓</button>
                            <button type="button" wire:click="removeService({{ $s['id'] }})" class="grid h-5 w-5 place-items-center rounded-full text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:hover:bg-white/10" aria-label="{{ $s['name'] }} önerisini kaldır">×</button>
                        </span>
                    @endif
                @endforeach
                @if ($available !== [] && $entry['state'] !== 'excluded')
                    <form wire:submit="addService" class="flex items-center gap-1.5">
                        <label class="sr-only" for="add-offering">Hizmet ekle</label>
                        <select id="add-offering" wire:model="addOffering" class="h-7 rounded-lg border border-gray-200 bg-white pl-2.5 pr-8 text-xs text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                            <option value="">Hizmet ekle</option>
                            @foreach ($available as $o)<option value="{{ $o['id'] }}">{{ $o['name'] }}</option>@endforeach
                        </select>
                        <button type="submit" class="btn h-7! px-2.5! text-xs!">Ekle</button>
                    </form>
                @endif
                @if ($entry['state'] !== 'excluded')
                    <button type="button" wire:click="exclude" wire:confirm="Bu kampanya tek bir hizmetin reklamı değil (marka bilinirliği gibi) olarak işaretlensin mi?" class="btn-quiet h-7! px-2! text-xs! sm:ml-auto">Hizmet dışı</button>
                @endif
            </div>
            @php $reasons = collect($entry['services'])->filter(fn ($s) => $s['reason'] !== ''); @endphp
            @if ($reasons->isNotEmpty() || ($entry['services'] === [] && $entry['state'] !== 'excluded'))
                <div class="space-y-0.5 border-t border-gray-200 px-4 py-2.5 dark:border-gray-800">
                    @foreach ($reasons as $s)
                        <p class="text-xs text-gray-500"><span class="font-medium text-gray-700 dark:text-gray-300">{{ $s['name'] }}:</span> {{ $s['reason'] }} <span>({{ $s['status'] === 'confirmed' ? 'onaylı' : 'öneri' }}, kaynak: {{ \App\Models\AdCampaignService::SOURCES[$s['source']] ?? $s['source'] }})</span></p>
                    @endforeach
                    @if ($entry['services'] === [] && $entry['state'] !== 'excluded')
                        <p class="text-xs text-gray-500">Sistem bu kampanyanın hizmetini bulamadı. Listeden ekleyin ya da hizmet dışı işaretleyin.</p>
                    @endif
                </div>
            @endif
        </div>
    </section>

    {{-- 1. What a result costs now against the period before. --}}
    @php
        $tiles = [
            [ucfirst($unitCost), $money($k['cpr']), 'Önceki dönem '.$money($k['prev_cpr']), $cprChange],
            [ucfirst($unit), $count($k['results']), 'Önceki dönem '.$count($k['prev_results']), null],
            ['Harcama', $money($k['spend']), 'Önceki dönem '.$money($k['prev_spend']), null],
            ['CTR', $k['ctr'] === null ? '—' : $num($k['ctr'], 2).'%', 'Tıklama / gösterim', null],
            ['Sıklık', $k['frequency'] === null ? '—' : $num($k['frequency'], 2), 'Kişi başı gösterim', null],
            ['CPM', $money($k['cpm']), '1000 gösterim', null],
        ];
    @endphp
    <section>
        <div class="section-head">
            <h2 class="section-title">Dönem</h2>
            <x-operator.date-range-picker :range="$range" :last-day="$lastDay" note="Meta verisi her gün toplanır." class="w-full sm:ml-auto sm:w-auto" />
        </div>
        <div class="metric-strip grid-cols-2 sm:grid-cols-3" data-testid="meta-campaign-kpis">
            @foreach ($tiles as [$label, $value, $sub, $delta])
                <x-ta.metric-card :label="$label" :value="$value" :hint="$sub" :delta="$delta === null ? null : $pct($delta)" :tone="$delta === null ? 'neutral' : ($delta < 0 ? 'positive' : ($delta > 0 ? 'negative' : 'neutral'))" />
            @endforeach
        </div>
    </section>

    {{-- 2. Sixty days of spend and results, with every change marked. --}}
    <section data-testid="meta-campaign-chart">
        <div class="section-head">
            <h2 class="section-title">Son 60 gün</h2>
            <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 sm:ml-auto">
                <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-second/20"></span>Günlük harcama</span>
                <span class="flex items-center gap-1.5"><span class="h-0.5 w-4 bg-second"></span>Günlük {{ $unit }}</span>
                <span class="flex items-center gap-1.5"><span class="h-3 border-l-2 border-dashed border-warning-500"></span>Değişiklik</span>
            </div>
        </div>
        <div class="card p-4">
            <svg viewBox="0 0 {{ $w }} {{ $h + 18 }}" class="block h-auto w-full" role="img" aria-label="Günlük harcama ve {{ $unit }} grafiği">
                @foreach (array_values($series) as $i => $d)
                    @php $bh = ((float) $d['spend'] / $maxSpend) * ($h - 40); @endphp
                    <rect x="{{ round($left + $i * $step + 1, 1) }}" y="{{ round($h - 12 - $bh, 1) }}" width="{{ max(1, round($step - 2, 1)) }}" height="{{ round($bh, 1) }}" rx="1.5" class="fill-second/20"><title>{{ $dates[$i] }}: {{ $money($d['spend']) }}, {{ $num($d[$campaign['type']], 0) }} {{ $unit }}</title></rect>
                @endforeach
                @foreach ($dates as $i => $date)
                    @if (isset($eventDays[$date]))
                        <line x1="{{ round($left + $i * $step + $step / 2, 1) }}" x2="{{ round($left + $i * $step + $step / 2, 1) }}" y1="4" y2="{{ $h - 12 }}" stroke-dasharray="4 4" class="stroke-warning-500" stroke-width="1.5"><title>{{ $date }}: {{ collect($eventDays[$date])->map(fn ($e) => $e['label'].($e['object'] !== '' ? ' ('.$e['object'].')' : ''))->implode(', ') }}</title></line>
                    @endif
                @endforeach
                <polyline points="{{ implode(' ', $points) }}" fill="none" stroke-width="2" stroke-linejoin="round" class="stroke-second"/>
                <text x="{{ $left }}" y="{{ $h + 14 }}" class="fill-gray-500 text-xs">{{ \Carbon\CarbonImmutable::parse($dates[0])->format('d.m') }}</text>
                <text x="{{ $w }}" y="{{ $h + 14 }}" text-anchor="end" class="fill-gray-500 text-xs">{{ \Carbon\CarbonImmutable::parse(end($dates))->format('d.m') }}</text>
            </svg>
            @if ($campaign['events'] !== [])
                <ul class="mt-3 space-y-1 border-t border-gray-200 pt-3 text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
                    @foreach (array_slice($campaign['events'], 0, 8) as $event)
                        <li class="flex flex-wrap gap-x-2"><span class="w-10 shrink-0 tabular-nums">{{ \Carbon\CarbonImmutable::parse($event['date'])->format('d.m') }}</span><span class="min-w-0"><span class="text-gray-700 dark:text-gray-300">{{ $event['label'] }}</span>@if ($event['object'] !== ''), {{ $event['object'] }}@endif @if ($event['actor'] !== '')<span class="ml-1">{{ $event['actor'] }}</span>@endif</span></li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>

    {{-- 3. Which ads carry the campaign, cheapest result first; the ad sets and settings beside them. --}}
    <div class="grid gap-10 xl:grid-cols-5">
        <section class="min-w-0 xl:col-span-3" data-testid="meta-campaign-ads">
            <div class="section-head">
                <h2 class="section-title">Reklamlar <span class="section-count">{{ count($campaign['ads']) }}</span></h2>
                <span class="text-[13px] text-gray-500 sm:ml-auto">{{ ucfirst($unitCost) }} maliyete göre</span>
            </div>
            @if ($campaign['ads'] === [])
                <div class="card"><x-ta.empty-state icon="none" size="sm" title="Reklam yok." /></div>
            @else
            <div class="card">
            @foreach ($campaign['ads'] as $ad)
                @php $adUrl = route('operator.meta.campaign-ad', array_filter(['assetId' => $assetId, 'campaignId' => $campaign['id'], 'adId' => $ad['id'], 'gun' => $days, 'bas' => $this->start ?: null, 'bit' => $this->end ?: null])); @endphp
                <article class="relative flex gap-3.5 border-t border-gray-200 px-4 py-3.5 first:border-t-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.02]" wire:key="ad-{{ $ad['id'] }}" data-testid="meta-campaign-ad">
                    @if ($ad['thumbnail_url'] !== '')
                        {{-- A broken creative URL falls back to the neutral placeholder instead of the browser's broken-image icon. --}}
                        <img src="{{ $ad['thumbnail_url'] }}" alt="" loading="lazy" class="h-20 w-16 shrink-0 rounded-lg object-cover sm:h-24 sm:w-20" onerror="this.onerror=null;this.hidden=true;this.nextElementSibling.hidden=false">
                        <div hidden class="grid h-20 w-16 shrink-0 place-items-center rounded-lg border border-gray-200 bg-gray-50 p-2 text-center text-xs text-gray-500 sm:h-24 sm:w-20 dark:border-gray-800 dark:bg-white/[0.04]">{{ $ad['video'] ? 'Video' : 'Görsel' }}</div>
                    @else
                        <div class="grid h-20 w-16 shrink-0 place-items-center rounded-lg border border-gray-200 bg-gray-50 p-2 text-center text-xs text-gray-500 sm:h-24 sm:w-20 dark:border-gray-800 dark:bg-white/[0.04]">{{ $ad['video'] ? 'Video' : 'Görsel yok' }}</div>
                    @endif
                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                            <a href="{{ $adUrl }}" wire:navigate class="min-w-0 break-words text-sm font-medium text-gray-900 after:absolute after:inset-0 hover:underline dark:text-white">{{ $ad['name'] }}</a>
                            @if ($ad['best'] ?? false)<x-ta.pill tone="ok">En iyi</x-ta.pill>@endif
                            @if ($ad['fatigue'])<x-ta.pill tone="look" title="CTR {{ $ad['fatigue']['first_ctr'] }}% → {{ $ad['fatigue']['last_ctr'] }}%, sıklık {{ $ad['fatigue']['frequency'] }}">Yoruldu</x-ta.pill>@endif
                            @if (in_array(strtoupper($ad['status']), ['DISAPPROVED', 'WITH_ISSUES'], true))<x-ta.pill tone="critical">Reddedildi</x-ta.pill>@endif
                        </div>
                        @if ($ad['body'] !== '')<p class="line-clamp-2 text-[13px] leading-relaxed text-gray-700 dark:text-gray-300">“{{ $ad['body'] }}”</p>@endif
                        <p class="break-words text-xs text-gray-500">@if ($ad['title'] !== '')Başlık: {{ $ad['title'] }}, @endif{{ $ad['form'] ? 'Anında form' : ($ad['whatsapp'] ? 'WhatsApp' : ($ad['link_url'] !== '' ? \App\Services\SeoTasks\SeoText::urlPath($ad['link_url']) : 'Bağlantı yok')) }}, {{ $ad['adset'] }}</p>
                        <p class="text-xs tabular-nums text-gray-500"><span class="font-medium text-gray-900 dark:text-white">{{ $count($ad['results']) }}</span> {{ $unit }}, {{ $money($ad['cpr']) }} {{ $unitCost }}, {{ $money($ad['spend']) }}, CTR {{ $ad['ctr'] === null ? '—' : $num($ad['ctr'], 2).'%' }}</p>
                    </div>
                </article>
            @endforeach
            </div>
            @endif
        </section>

        <div class="min-w-0 space-y-10 xl:col-span-2">
            <section data-testid="meta-campaign-adsets">
                <div class="section-head"><h2 class="section-title">Reklam setleri <span class="section-count">{{ count($campaign['adsets']) }}</span></h2></div>
                @if ($campaign['adsets'] === [])
                    <div class="card"><x-ta.empty-state icon="none" size="sm" title="Reklam seti yok." /></div>
                @else
                <div class="card">
                @foreach ($campaign['adsets'] as $set)
                    @php $t = $set['targeting']; @endphp
                    <article class="space-y-2.5 border-t border-gray-200 px-4 py-3.5 first:border-t-0 dark:border-gray-800" wire:key="adset-{{ $set['id'] }}">
                        <div>
                            <p class="flex min-w-0 items-center gap-2 break-words text-sm font-medium text-gray-900 dark:text-white"><span @class(['inline-block h-1.5 w-1.5 shrink-0 rounded-full', 'bg-success-500' => $set['status'] === 'live', 'bg-gray-300 dark:bg-gray-600' => $set['status'] !== 'live'])></span><span class="min-w-0">{{ $set['name'] }}</span></p>
                            <p class="mt-0.5 text-xs tabular-nums text-gray-500"><span class="font-medium text-gray-900 dark:text-white">{{ $count($set['results']) }}</span> {{ $unit }}, {{ $money($set['cpr']) }} {{ $unitCost }}, {{ $money($set['spend']) }}</p>
                        </div>
                        <dl class="grid grid-cols-[6.5rem_minmax(0,1fr)] gap-x-3 gap-y-1 break-words text-[13px]">
                            <dt class="text-gray-500">Konum</dt><dd class="text-gray-900 dark:text-gray-100">{{ $t['locations'] === [] ? '—' : implode(', ', $t['locations']) }}</dd>
                            <dt class="text-gray-500">Yaş, cinsiyet</dt><dd class="text-gray-900 dark:text-gray-100">{{ $t['age'] }}, {{ $t['genders'] }}@if ($t['advantage']), Advantage+ kitle @endif</dd>
                            <dt class="text-gray-500">Yerleşim</dt><dd class="text-gray-900 dark:text-gray-100">{{ $t['placements'] }}</dd>
                            <dt class="text-gray-500">Optimizasyon</dt><dd class="text-gray-900 dark:text-gray-100">{{ $set['optimization'] ? $optimizationText((string) $set['optimization']) : '—' }}{{ $set['destination'] !== '' && $destinationText((string) $set['destination']) !== '' ? ', '.$destinationText((string) $set['destination']) : '' }}</dd>
                            @if ($set['budget'] !== null)<dt class="text-gray-500">Set bütçesi</dt><dd class="text-gray-900 dark:text-gray-100">{{ $money($set['budget']) }}/gün</dd>@endif
                            @if ($set['attribution'])<dt class="text-gray-500">İlişkilendirme</dt><dd class="text-gray-900 dark:text-gray-100">{{ $set['attribution'] }}</dd>@endif
                            @if ($t['audiences'] !== [])<dt class="text-gray-500">Kitleler</dt><dd class="text-gray-900 dark:text-gray-100">{{ implode(', ', $t['audiences']) }}</dd>@endif
                            @if ($t['excluded'] !== [])<dt class="text-gray-500">Dışlananlar</dt><dd class="text-gray-900 dark:text-gray-100">{{ implode(', ', $t['excluded']) }}</dd>@endif
                            <dt class="text-gray-500">İlgi alanları</dt><dd class="text-gray-900 dark:text-gray-100">@if ($t['interests'] !== []){{ implode(', ', $t['interests']) }}@else<span class="text-gray-500">Yok (geniş kitle)</span>@endif</dd>
                        </dl>
                    </article>
                @endforeach
                </div>
                @endif
            </section>

            <section data-testid="meta-campaign-settings">
                <div class="section-head"><h2 class="section-title">Kampanya ayarları</h2></div>
                @php
                    $settingRows = array_filter([
                        'Hedef' => $campaign['objective'],
                        'Satın alma türü' => match ($campaign['settings']['buying_type']) { 'AUCTION' => 'Açık artırma', 'RESERVED' => 'Erişim ve sıklık', '' => null, default => $enumText((string) $campaign['settings']['buying_type']) },
                        'Günlük bütçe' => $campaign['settings']['budget']['amount'] === null ? null : $money($campaign['settings']['budget']['amount']).($campaign['settings']['budget']['level'] === 'campaign' ? ' (kampanya bütçesi)' : ' (set bütçelerinin toplamı)'),
                        'Toplam bütçe' => $campaign['settings']['lifetime_budget'] === null ? null : $money($campaign['settings']['lifetime_budget']),
                        'Başlangıç' => $campaign['settings']['start'] ? \Carbon\CarbonImmutable::parse($campaign['settings']['start'])->format('d.m.Y') : null,
                        'Bitiş' => $campaign['settings']['stop'] ? \Carbon\CarbonImmutable::parse($campaign['settings']['stop'])->format('d.m.Y') : 'Yok',
                        'Durum (Meta)' => $campaign['raw_status'] ? $metaStatus((string) $campaign['raw_status']) : null,
                    ]);
                @endphp
                <dl class="card">
                    @foreach ($settingRows as $label => $value)
                        <div class="flex gap-3 border-t border-gray-200 px-4 py-2.5 text-[13px] first:border-t-0 dark:border-gray-800">
                            <dt class="w-32 shrink-0 text-gray-500">{{ $label }}</dt><dd class="min-w-0 break-words text-gray-900 dark:text-gray-100">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        </div>
    </div>

    <section data-testid="meta-campaign-analysis">
        <div class="section-head">
            <h2 class="section-title" title="Meta ilgi alanına göre sonuç vermez; ilgi alanları reklam setlerinin hedeflemesinden ve sonuçlarından gelir.">Analiz</h2>
        </div>
        @include('livewire.demo.meta.partials.analysis', [
            'card' => 'card min-w-0 p-4',
            'panel' => 'min-w-0',
            'btn' => 'btn',
            'th' => 'px-3 py-2 text-right font-normal',
            'td' => 'px-3 py-1.5 text-right',
            'bound' => $account !== null,
        ])
    </section>
</div>
