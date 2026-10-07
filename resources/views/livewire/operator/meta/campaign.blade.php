@php
    $currency = $account['currency'] ?? '';
    $num = fn ($value, int $decimals = 0) => $value === null ? '—' : number_format((float) $value, $decimals, ',', '.');
    $money = fn ($value) => $value === null ? '—' : number_format((float) $value, 2, ',', '.').' '.$currency;
    $pct = fn ($value) => $value === null ? '—' : (($value > 0 ? '+' : '').number_format((float) $value, 1, ',', '.').'%');
    $count = fn (float $value) => $value >= 10000 ? $num($value / 1000, 1).' bin' : $num($value, $value == floor($value) ? 0 : 1);
    $types = \App\Services\Meta\MetaCampaignBoard::TYPES;
    [$unit, $unitCost] = $types[$campaign['type']];
    $card = 'rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
    $btn = 'inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-inset ring-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-white/[0.04]';
    $chip = 'inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium';
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
@endphp

<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <nav class="text-sm text-gray-500" aria-label="Konum">
        @if ($brand)<a href="{{ route('operator.brand', ['brand' => $brand->id]) }}" wire:navigate class="hover:text-brand-600">{{ $brand->name }}</a> <span class="text-gray-300">/</span>@endif
        <a href="{{ route('operator.meta.overview', ['assetId' => $assetId]) }}" wire:navigate class="hover:text-brand-600">{{ $asset['name'] ?? 'Meta' }}</a> <span class="text-gray-300">/</span>
        <a href="{{ route('operator.meta.overview', ['assetId' => $assetId, 'tab' => 'campaigns']) }}" wire:navigate class="hover:text-brand-600">Kampanyalar</a> <span class="text-gray-300">/</span>
        <span class="text-gray-700 dark:text-gray-300">{{ $campaign['name'] }}</span>
    </nav>

    <section class="{{ $card }} space-y-4 p-5" data-testid="meta-campaign-header">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 space-y-1.5">
                <p @class(['flex items-center gap-2 text-xs font-semibold', 'text-emerald-700 dark:text-emerald-400' => $campaign['status'] === 'live', 'text-gray-500' => $campaign['status'] !== 'live'])>
                    <span @class(['h-2 w-2 rounded-full', 'bg-emerald-500' => $campaign['status'] === 'live', 'bg-gray-400' => $campaign['status'] !== 'live'])></span>{{ $statusLabel[$campaign['status']] }}@if ($campaign['settings']['start']) · {{ \Carbon\CarbonImmutable::parse($campaign['settings']['start'])->format('d.m.Y') }}’den beri @endif
                </p>
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ $campaign['name'] }}</h1>
                <p class="text-sm text-gray-500">Hedef: {{ $campaign['objective'] }} · {{ $campaign['settings']['budget']['amount'] === null ? 'Bütçe yok' : ($campaign['settings']['budget']['level'] === 'campaign' ? 'Kampanya bütçesi ' : 'Set bütçeleri ').$money($campaign['settings']['budget']['amount']).'/gün' }} · {{ count($campaign['adsets']) }} reklam seti · {{ count($campaign['ads']) }} reklam</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ $campaign['ads_manager_url'] }}" target="_blank" rel="noopener" class="{{ $btn }}">Reklam Yöneticisi’nde aç
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M7 17 17 7M9 7h8v8"/></svg></a>
            </div>
        </div>

        <div class="space-y-2 rounded-xl bg-gray-50 p-3 dark:bg-white/[0.03]" data-testid="meta-campaign-services">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">Hizmetler</span>
                @if ($entry['state'] === 'excluded')
                    <span class="{{ $chip }} bg-gray-200 text-gray-700 dark:bg-white/[0.08] dark:text-gray-300">Hizmet dışı</span>
                    <button type="button" wire:click="reopen" class="text-xs font-semibold text-brand-600 hover:underline">Geri al</button>
                @endif
                @foreach ($entry['services'] as $s)
                    @if ($s['status'] === 'confirmed')
                        <span class="{{ $chip }} bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-200 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/30">
                            {{ $s['name'] }}
                            <button type="button" wire:click="removeService({{ $s['id'] }})" class="-mr-1 rounded-full px-1 text-brand-500 hover:bg-brand-100 hover:text-brand-800" aria-label="{{ $s['name'] }} hizmetini kaldır">×</button>
                        </span>
                    @else
                        <span class="{{ $chip }} border border-dashed border-gray-400 bg-white text-gray-700 dark:bg-transparent dark:text-gray-300">
                            {{ $s['name'] }}
                            <button type="button" wire:click="confirmService({{ $s['id'] }})" class="rounded-full px-1 font-semibold text-emerald-700 hover:bg-emerald-50" aria-label="{{ $s['name'] }} önerisini onayla">✓</button>
                            <button type="button" wire:click="removeService({{ $s['id'] }})" class="-mr-1 rounded-full px-1 text-gray-500 hover:bg-gray-100" aria-label="{{ $s['name'] }} önerisini kaldır">×</button>
                        </span>
                    @endif
                @endforeach
                @if ($available !== [] && $entry['state'] !== 'excluded')
                    <form wire:submit="addService" class="flex items-center gap-1">
                        <label class="sr-only" for="add-offering">Hizmet ekle</label>
                        <select id="add-offering" wire:model="addOffering" class="rounded-lg border-gray-300 py-1 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                            <option value="">+ Hizmet ekle</option>
                            @foreach ($available as $o)<option value="{{ $o['id'] }}">{{ $o['name'] }}</option>@endforeach
                        </select>
                        <button type="submit" class="rounded-lg bg-brand-500 px-2 py-1 text-xs font-semibold text-white hover:bg-brand-600">Ekle</button>
                    </form>
                @endif
                @if ($entry['state'] !== 'excluded')
                    <button type="button" wire:click="exclude" wire:confirm="Bu kampanya tek bir hizmetin reklamı değil (marka bilinirliği gibi) olarak işaretlensin mi?" class="text-xs font-medium text-gray-500 hover:text-gray-800 hover:underline">Hizmet dışı</button>
                @endif
            </div>
            @foreach ($entry['services'] as $s)
                @if ($s['reason'] !== '')<p class="text-xs text-gray-500"><span class="font-medium text-gray-700 dark:text-gray-300">{{ $s['name'] }}:</span> {{ $s['reason'] }} <span class="text-gray-400">({{ $s['status'] === 'confirmed' ? 'onaylı' : 'öneri' }} · kaynak: {{ \App\Models\AdCampaignService::SOURCES[$s['source']] ?? $s['source'] }})</span></p>@endif
            @endforeach
            @if ($entry['services'] === [] && $entry['state'] !== 'excluded')
                <p class="text-xs text-gray-500">Sistem bu kampanyanın hizmetini bulamadı. Listeden ekleyin ya da hizmet dışı işaretleyin.</p>
            @endif
        </div>
    </section>

    <div class="flex flex-wrap items-center gap-2">
        <x-operator.date-range-picker :range="$range" :last-day="$lastDay" note="Meta verisi her gün toplanır." />
    </div>

    @php
        $tiles = [
            ['Harcama', $money($k['spend']), 'önceki dönem '.$money($k['prev_spend']), null],
            [ucfirst($unit), $count($k['results']), 'önceki dönem '.$count($k['prev_results']), null],
            [ucfirst($unitCost), $money($k['cpr']), 'önceki dönem '.$money($k['prev_cpr']), $change($k['cpr'], $k['prev_cpr'])],
            ['CTR', $k['ctr'] === null ? '—' : $num($k['ctr'], 2).'%', 'tıklama / gösterim', null],
            ['Sıklık', $k['frequency'] === null ? '—' : $num($k['frequency'], 2), 'kişi başı gösterim', null],
            ['CPM', $money($k['cpm']), '1000 gösterim', null],
        ];
    @endphp
    <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-6" data-testid="meta-campaign-kpis">
        @foreach ($tiles as [$label, $value, $sub, $delta])
            <section class="{{ $card }} p-4">
                <p class="text-xs text-gray-500">{{ $label }}</p>
                <p class="mt-1 text-xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $value }}</p>
                <p class="text-xs text-gray-500">{{ $sub }}@if ($delta !== null) <span @class(['font-semibold', 'text-emerald-600' => $delta < 0, 'text-rose-600' => $delta > 0])>{{ $pct($delta) }}</span>@endif</p>
            </section>
        @endforeach
    </div>

    <section class="{{ $card }} p-5" data-testid="meta-campaign-chart">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-gray-900 dark:text-white">Son 60 gün</h2>
            <div class="flex flex-wrap gap-4 text-xs text-gray-500">
                <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-brand-200"></span>Günlük harcama</span>
                <span class="flex items-center gap-1.5"><span class="h-0.5 w-4 bg-brand-600"></span>Günlük {{ $unit }}</span>
                <span class="flex items-center gap-1.5"><span class="h-3 border-l-2 border-dashed border-amber-600"></span>Değişiklik</span>
            </div>
        </div>
        <svg viewBox="0 0 {{ $w }} {{ $h + 18 }}" class="block h-auto w-full" role="img" aria-label="Günlük harcama ve {{ $unit }} grafiği">
            @foreach (array_values($series) as $i => $d)
                @php $bh = ((float) $d['spend'] / $maxSpend) * ($h - 40); @endphp
                <rect x="{{ round($left + $i * $step + 1, 1) }}" y="{{ round($h - 12 - $bh, 1) }}" width="{{ max(1, round($step - 2, 1)) }}" height="{{ round($bh, 1) }}" class="fill-brand-100 dark:fill-brand-500/20"><title>{{ $dates[$i] }} · {{ $money($d['spend']) }} · {{ $num($d[$campaign['type']], 0) }} {{ $unit }}</title></rect>
            @endforeach
            @foreach ($dates as $i => $date)
                @if (isset($eventDays[$date]))
                    <line x1="{{ round($left + $i * $step + $step / 2, 1) }}" x2="{{ round($left + $i * $step + $step / 2, 1) }}" y1="4" y2="{{ $h - 12 }}" stroke-dasharray="4 4" class="stroke-amber-600" stroke-width="1.5"><title>{{ $date }}: {{ collect($eventDays[$date])->map(fn ($e) => $e['label'].($e['object'] !== '' ? ' ('.$e['object'].')' : ''))->implode(', ') }}</title></line>
                @endif
            @endforeach
            <polyline points="{{ implode(' ', $points) }}" fill="none" stroke-width="2" stroke-linejoin="round" class="stroke-brand-600"/>
            <text x="{{ $left }}" y="{{ $h + 14 }}" class="fill-gray-500 text-[11px]">{{ \Carbon\CarbonImmutable::parse($dates[0])->format('d.m') }}</text>
            <text x="{{ $w }}" y="{{ $h + 14 }}" text-anchor="end" class="fill-gray-500 text-[11px]">{{ \Carbon\CarbonImmutable::parse(end($dates))->format('d.m') }}</text>
        </svg>
        @if ($campaign['events'] !== [])
            <ul class="mt-3 space-y-1 text-xs text-gray-600 dark:text-gray-400">
                @foreach (array_slice($campaign['events'], 0, 8) as $event)
                    <li><span class="tabular-nums text-gray-400">{{ \Carbon\CarbonImmutable::parse($event['date'])->format('d.m') }}</span> {{ $event['label'] }}@if ($event['object'] !== '') · {{ $event['object'] }}@endif @if ($event['actor'] !== '')<span class="text-gray-400">· {{ $event['actor'] }}</span>@endif</li>
                @endforeach
            </ul>
        @endif
    </section>

    <div class="grid gap-5 xl:grid-cols-2">
        <div class="space-y-5">
            <section class="{{ $card }} p-5" data-testid="meta-campaign-settings">
                <h2 class="mb-3 font-semibold text-gray-900 dark:text-white">Kampanya ayarları</h2>
                @php
                    $settingRows = array_filter([
                        'Hedef' => $campaign['objective'],
                        'Satın alma türü' => match ($campaign['settings']['buying_type']) { 'AUCTION' => 'Açık artırma', 'RESERVED' => 'Erişim ve sıklık', '' => null, default => $campaign['settings']['buying_type'] },
                        'Günlük bütçe' => $campaign['settings']['budget']['amount'] === null ? null : $money($campaign['settings']['budget']['amount']).($campaign['settings']['budget']['level'] === 'campaign' ? ' (kampanya bütçesi)' : ' (set bütçelerinin toplamı)'),
                        'Toplam bütçe' => $campaign['settings']['lifetime_budget'] === null ? null : $money($campaign['settings']['lifetime_budget']),
                        'Başlangıç' => $campaign['settings']['start'] ? \Carbon\CarbonImmutable::parse($campaign['settings']['start'])->format('d.m.Y') : null,
                        'Bitiş' => $campaign['settings']['stop'] ? \Carbon\CarbonImmutable::parse($campaign['settings']['stop'])->format('d.m.Y') : 'Yok',
                        'Durum (Meta)' => $campaign['raw_status'] ?: null,
                    ]);
                @endphp
                <dl class="grid grid-cols-[10rem_1fr] gap-x-4 gap-y-2 text-sm">
                    @foreach ($settingRows as $label => $value)
                        <dt class="text-gray-500">{{ $label }}</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $value }}</dd>
                    @endforeach
                </dl>
            </section>

            <section class="{{ $card }} space-y-3 p-5" data-testid="meta-campaign-adsets">
                <h2 class="font-semibold text-gray-900 dark:text-white">Reklam setleri · {{ count($campaign['adsets']) }}</h2>
                @forelse ($campaign['adsets'] as $set)
                    @php $t = $set['targeting']; @endphp
                    <article class="space-y-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700" wire:key="adset-{{ $set['id'] }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <p class="font-semibold text-gray-900 dark:text-white"><span @class(['mr-1.5 inline-block h-2 w-2 rounded-full', 'bg-emerald-500' => $set['status'] === 'live', 'bg-gray-400' => $set['status'] !== 'live'])></span>{{ $set['name'] }}</p>
                            <p class="text-xs tabular-nums text-gray-500"><span class="font-semibold text-gray-900 dark:text-white">{{ $count($set['results']) }}</span> {{ $unit }} · {{ $money($set['cpr']) }} {{ $unitCost }} · {{ $money($set['spend']) }}</p>
                        </div>
                        <dl class="grid grid-cols-[8.5rem_1fr] gap-x-3 gap-y-1.5 text-sm">
                            <dt class="text-gray-500">Konum</dt><dd class="text-gray-900 dark:text-gray-100">{{ $t['locations'] === [] ? '—' : implode(', ', $t['locations']) }}</dd>
                            <dt class="text-gray-500">Yaş · cinsiyet</dt><dd class="text-gray-900 dark:text-gray-100">{{ $t['age'] }} · {{ $t['genders'] }}@if ($t['advantage']) · Advantage+ kitle @endif</dd>
                            <dt class="text-gray-500">Yerleşim</dt><dd class="text-gray-900 dark:text-gray-100">{{ $t['placements'] }}</dd>
                            <dt class="text-gray-500">Optimizasyon</dt><dd class="text-gray-900 dark:text-gray-100">{{ $set['optimization'] ?: '—' }}{{ $set['destination'] !== '' ? ' · '.$set['destination'] : '' }}</dd>
                            @if ($set['budget'] !== null)<dt class="text-gray-500">Set bütçesi</dt><dd class="text-gray-900 dark:text-gray-100">{{ $money($set['budget']) }}/gün</dd>@endif
                            @if ($set['attribution'])<dt class="text-gray-500">İlişkilendirme</dt><dd class="text-gray-900 dark:text-gray-100">{{ $set['attribution'] }}</dd>@endif
                            @if ($t['audiences'] !== [])<dt class="text-gray-500">Kitleler</dt><dd class="text-gray-900 dark:text-gray-100">{{ implode(', ', $t['audiences']) }}</dd>@endif
                            @if ($t['excluded'] !== [])<dt class="text-gray-500">Dışlananlar</dt><dd class="text-gray-900 dark:text-gray-100">{{ implode(', ', $t['excluded']) }}</dd>@endif
                        </dl>
                        <div>
                            <p class="mb-1 text-xs text-gray-500">Hedeflenen ilgi alanları</p>
                            <div class="flex flex-wrap gap-1.5">
                                @forelse ($t['interests'] as $interest)<span class="{{ $chip }} bg-gray-100 text-gray-700 dark:bg-white/[0.06] dark:text-gray-300">{{ $interest }}</span>@empty<span class="text-xs text-gray-400">Yok (geniş kitle)</span>@endforelse
                            </div>
                        </div>
                    </article>
                @empty
                    <p class="text-sm text-gray-500">Reklam seti yok.</p>
                @endforelse
            </section>
        </div>

        <section class="{{ $card }} space-y-3 p-5" data-testid="meta-campaign-ads">
            <div class="flex items-baseline justify-between">
                <h2 class="font-semibold text-gray-900 dark:text-white">Reklamlar · {{ count($campaign['ads']) }}</h2>
                <span class="text-xs text-gray-500">{{ ucfirst($unitCost) }} maliyete göre</span>
            </div>
            @forelse ($campaign['ads'] as $ad)
                <article class="flex gap-4 rounded-xl border border-gray-200 p-4 dark:border-gray-700" wire:key="ad-{{ $ad['id'] }}">
                    @if ($ad['thumbnail_url'] !== '')
                        <img src="{{ $ad['thumbnail_url'] }}" alt="" loading="lazy" class="h-28 w-24 shrink-0 rounded-lg object-cover">
                    @else
                        <div class="grid h-28 w-24 shrink-0 place-items-center rounded-lg bg-gray-100 p-2 text-center text-[11px] text-gray-500 dark:bg-white/[0.04]">{{ $ad['video'] ? 'Video' : 'Görsel yok' }}</div>
                    @endif
                    <div class="min-w-0 space-y-1.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-semibold text-gray-900 dark:text-white">{{ $ad['name'] }}</p>
                            @if ($ad['best'] ?? false)<span class="{{ $chip }} bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">En iyi</span>@endif
                            @if ($ad['fatigue'])<span class="{{ $chip }} bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300" title="CTR {{ $ad['fatigue']['first_ctr'] }}% → {{ $ad['fatigue']['last_ctr'] }}%, sıklık {{ $ad['fatigue']['frequency'] }}">Yoruldu</span>@endif
                            @if (in_array(strtoupper($ad['status']), ['DISAPPROVED', 'WITH_ISSUES'], true))<span class="{{ $chip }} bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">Reddedildi</span>@endif
                        </div>
                        @if ($ad['body'] !== '')<p class="line-clamp-3 text-sm leading-relaxed text-gray-700 dark:text-gray-300">“{{ $ad['body'] }}”</p>@endif
                        <p class="text-xs text-gray-500">@if ($ad['title'] !== '')Başlık: {{ $ad['title'] }} · @endif{{ $ad['form'] ? 'Anında form' : ($ad['link_url'] !== '' ? \App\Services\SeoTasks\SeoText::urlPath($ad['link_url']) : 'Bağlantı yok') }} · {{ $ad['adset'] }}</p>
                        <p class="text-xs tabular-nums text-gray-500"><span class="font-semibold text-gray-900 dark:text-white">{{ $count($ad['results']) }}</span> {{ $unit }} · {{ $money($ad['cpr']) }} {{ $unitCost }} · {{ $money($ad['spend']) }} · CTR {{ $ad['ctr'] === null ? '—' : $num($ad['ctr'], 2).'%' }}</p>
                    </div>
                </article>
            @empty
                <p class="text-sm text-gray-500">Reklam yok.</p>
            @endforelse
        </section>
    </div>

    <section class="space-y-4" data-testid="meta-campaign-analysis">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Analiz</h2>
            <p class="text-xs text-gray-500">Meta ilgi alanına göre sonuç vermez; ilgi alanları reklam setlerinin hedeflemesinden ve sonuçlarından gelir.</p>
        </div>
        @include('livewire.demo.meta.partials.analysis', [
            'card' => 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700',
            'panel' => 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700',
            'btn' => 'rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-inset ring-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-white/[0.04]',
            'th' => 'px-3 py-2 text-right font-medium',
            'td' => 'px-3 py-1.5 text-right',
            'bound' => $account !== null,
        ])
    </section>
</div>
