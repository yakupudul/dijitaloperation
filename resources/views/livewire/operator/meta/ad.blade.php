@php
    $currency = $account['currency'] ?? '';
    $num = fn ($value, int $decimals = 0) => $value === null ? '—' : number_format((float) $value, $decimals, ',', '.');
    $money = fn ($value) => $value === null ? '—' : number_format((float) $value, 2, ',', '.').' '.$currency;
    $pct = fn ($value) => $value === null ? '—' : (($value > 0 ? '+' : '').number_format((float) $value, 1, ',', '.').'%');
    $count = fn (float $value) => $value >= 10000 ? $num($value / 1000, 1).' bin' : $num($value, $value == floor($value) ? 0 : 1);
    [$unit, $unitCost] = \App\Services\Meta\MetaCampaignBoard::TYPES[$ad['type']];
    $card = 'rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
    $btn = 'inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-inset ring-gray-300 text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-white/[0.04]';
    $chip = 'inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium';
    $statusLabel = ['live' => 'Yayında', 'paused' => 'Durmuş', 'ended' => 'Bitti'];
    $k = $ad['kpis'];
    $c = $ad['creative'];
    $d = $ad['destination'];
    $change = fn ($a, $b) => \App\Services\Meta\MetaScreen::change($a, $b);
    $campaignUrl = route('operator.meta.campaign', array_filter(['assetId' => $assetId, 'campaignId' => $ad['campaign']['id'], 'gun' => $days ?? null]));
    $image = $c['image_url'] !== '' ? $c['image_url'] : $c['thumbnail_url'];
    $questionTypes = ['FULL_NAME' => 'Ad soyad', 'FIRST_NAME' => 'Ad', 'LAST_NAME' => 'Soyad', 'EMAIL' => 'E-posta', 'PHONE' => 'Telefon', 'CITY' => 'Şehir',
        'COUNTRY' => 'Ülke', 'STATE' => 'Bölge', 'ZIP' => 'Posta kodu', 'POST_CODE' => 'Posta kodu', 'STREET_ADDRESS' => 'Adres', 'DOB' => 'Doğum tarihi',
        'GENDER' => 'Cinsiyet', 'JOB_TITLE' => 'Meslek', 'COMPANY_NAME' => 'Şirket', 'DATE_TIME' => 'Randevu tarihi'];

    $series = $ad['series'];
    $dates = array_keys($series);
    $n = max(1, count($dates));
    $maxSpend = max(1.0, ...array_map(fn ($row) => (float) $row['spend'], array_values($series) ?: [['spend' => 0]]));
    $maxResults = max(1.0, ...array_map(fn ($row) => (float) ($row[$ad['type']] ?? 0), array_values($series) ?: [[$ad['type'] => 0]]));
    $w = 1000; $h = 180; $left = 8; $step = ($w - $left) / $n;
    $points = [];
    foreach (array_values($series) as $i => $row) {
        $points[] = round($left + $i * $step + $step / 2, 1).','.round($h - 12 - ((float) ($row[$ad['type']] ?? 0) / $maxResults) * ($h - 40), 1);
    }
@endphp

<div class="space-y-5">
    <nav class="text-sm text-gray-500" aria-label="Konum">
        @if ($brand)<a href="{{ route('operator.brand', ['brand' => $brand->id]) }}" wire:navigate class="hover:text-brand-600">{{ $brand->name }}</a> <span class="text-gray-300">/</span>@endif
        <a href="{{ route('operator.meta.overview', ['assetId' => $assetId]) }}" wire:navigate class="hover:text-brand-600">{{ $asset['name'] ?? 'Meta' }}</a> <span class="text-gray-300">/</span>
        <a href="{{ $campaignUrl }}" wire:navigate class="hover:text-brand-600">{{ $ad['campaign']['name'] }}</a> <span class="text-gray-300">/</span>
        <span class="text-gray-700 dark:text-gray-300">{{ $ad['name'] }}</span>
    </nav>

    <section class="{{ $card }} flex flex-col gap-4 p-5 lg:flex-row lg:items-start lg:justify-between" data-testid="meta-ad-header">
        <div class="min-w-0 space-y-1.5">
            <p @class(['flex items-center gap-2 text-xs font-semibold', 'text-emerald-700 dark:text-emerald-400' => $ad['status'] === 'live', 'text-gray-500' => $ad['status'] !== 'live'])>
                <span @class(['h-2 w-2 rounded-full', 'bg-emerald-500' => $ad['status'] === 'live', 'bg-gray-400' => $ad['status'] !== 'live'])></span>{{ $statusLabel[$ad['status']] }}
                @if (in_array(strtoupper($ad['raw_status']), ['DISAPPROVED', 'WITH_ISSUES'], true))<span class="{{ $chip }} bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">Reddedildi</span>@endif
                @if ($ad['fatigue'])<span class="{{ $chip }} bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">Yoruldu · CTR {{ $ad['fatigue']['first_ctr'] }}% → {{ $ad['fatigue']['last_ctr'] }}%</span>@endif
            </p>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ $ad['name'] }}</h1>
            <p class="text-sm text-gray-500">Reklam seti: {{ $ad['adset']['name'] ?: '—' }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($c['post_url'] !== '')<a href="{{ $c['post_url'] }}" target="_blank" rel="noopener" class="{{ $btn }}">Gönderiyi Facebook’ta aç ↗</a>@endif
            <a href="{{ $ad['ads_manager_url'] }}" target="_blank" rel="noopener" class="{{ $btn }}">Reklam Yöneticisi’nde aç ↗</a>
        </div>
    </section>

    <div class="flex flex-wrap items-center gap-2">
        <x-operator.date-range-picker :range="$range" :last-day="$lastDay" note="Meta verisi her gün toplanır." />
    </div>

    @php
        $tiles = [
            ['Harcama', $money($k['spend']), 'önceki dönem '.$money($k['prev_spend']), null],
            [ucfirst($unit), $count($k['results']), 'önceki dönem '.($k['prev_results'] === null ? '—' : $count($k['prev_results'])), null],
            [ucfirst($unitCost), $money($k['cpr']), 'önceki dönem '.$money($k['prev_cpr']), $change($k['cpr'], $k['prev_cpr'])],
            ['CTR', $k['ctr'] === null ? '—' : $num($k['ctr'], 2).'%', 'önceki dönem '.($k['prev_ctr'] === null ? '—' : $num($k['prev_ctr'], 2).'%'), null],
            ['Gösterim · tıklama', $count((float) $k['impressions']), $num($k['clicks']).' tıklama', null],
            ['CPM', $money($k['cpm']), $k['frequency'] === null ? '1000 gösterim' : 'sıklık '.$num($k['frequency'], 2), null],
        ];
    @endphp
    <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-6" data-testid="meta-ad-kpis">
        @foreach ($tiles as [$label, $value, $sub, $delta])
            <section class="{{ $card }} p-4">
                <p class="text-xs text-gray-500">{{ $label }}</p>
                <p class="mt-1 text-xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $value }}</p>
                <p class="text-xs text-gray-500">{{ $sub }}@if ($delta !== null) <span @class(['font-semibold', 'text-emerald-600' => $delta < 0, 'text-rose-600' => $delta > 0])>{{ $pct($delta) }}</span>@endif</p>
            </section>
        @endforeach
    </div>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,26rem)_1fr]">
        {{-- What people see --}}
        <section class="{{ $card }} space-y-4 p-5" data-testid="meta-ad-creative" x-data="{ copied: '' }">
            <h2 class="font-semibold text-gray-900 dark:text-white">Reklam</h2>
            <div class="overflow-hidden rounded-xl ring-1 ring-inset ring-gray-200 dark:ring-gray-700">
                @if ($c['body'] !== '')
                    <p class="whitespace-pre-line p-3 text-sm leading-relaxed text-gray-800 dark:text-gray-200">{{ $c['body'] }}</p>
                @endif
                @if ($image !== '')
                    <img src="{{ $image }}" alt="" loading="lazy" class="block max-h-[28rem] w-full bg-gray-100 object-contain dark:bg-white/[0.04]">
                @else
                    <div class="grid h-48 place-items-center bg-gray-100 text-sm text-gray-500 dark:bg-white/[0.04]">{{ $c['video'] ? 'Video' : 'Görsel yok' }}</div>
                @endif
                @if ($c['title'] !== '' || $c['description'] !== '' || $c['cta'] !== '')
                    <div class="flex items-center gap-3 bg-gray-50 p-3 dark:bg-white/[0.03]">
                        <div class="min-w-0 flex-1">
                            @if ($d['kind'] === 'site')<p class="truncate text-[11px] uppercase text-gray-500">{{ $d['host'] }}</p>@endif
                            @if ($c['title'] !== '')<p class="font-semibold text-gray-900 dark:text-white">{{ $c['title'] }}</p>@endif
                            @if ($c['description'] !== '')<p class="text-xs text-gray-600 dark:text-gray-400">{{ $c['description'] }}</p>@endif
                        </div>
                        @if ($c['cta'] !== '')<span class="shrink-0 rounded-md bg-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-800 dark:bg-white/10 dark:text-gray-100">{{ $c['cta'] }}</span>@endif
                    </div>
                @endif
            </div>
            @if ($c['body'] !== '')
                <button type="button" x-on:click="navigator.clipboard.writeText(@js($c['body'])); copied = 'body'" class="text-xs font-medium text-brand-600 hover:underline"><span x-show="copied !== 'body'">Metni kopyala</span><span x-show="copied === 'body'" x-cloak>Kopyalandı</span></button>
            @elseif ($c['title'] === '')
                <p class="text-xs text-gray-500">Bu reklamın metni henüz okunmadı; bir sonraki günlük toplamada gelir.</p>
            @endif

            @php $variantGroups = array_filter(['Diğer ana metinler' => $c['variants']['bodies'], 'Diğer başlıklar' => $c['variants']['titles'], 'Diğer açıklamalar' => $c['variants']['descriptions']]); @endphp
            @if ($variantGroups !== [])
                <div class="space-y-3 border-t border-gray-100 pt-3 dark:border-gray-700" data-testid="meta-ad-variants">
                    <p class="text-xs text-gray-500">Meta bu varyasyonları kişiye göre değiştirerek gösterir.</p>
                    @foreach ($variantGroups as $label => $texts)
                        <div>
                            <p class="mb-1 text-xs font-semibold text-gray-700 dark:text-gray-300">{{ $label }} · {{ count($texts) }}</p>
                            <ul class="space-y-1.5">
                                @foreach ($texts as $text)<li class="whitespace-pre-line rounded-lg bg-gray-50 p-2 text-sm text-gray-800 dark:bg-white/[0.03] dark:text-gray-200">{{ $text }}</li>@endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <div class="space-y-5">
            {{-- Where it leads --}}
            <section class="{{ $card }} space-y-3 p-5" data-testid="meta-ad-destination">
                <h2 class="font-semibold text-gray-900 dark:text-white">Nereye gidiyor</h2>
                @if ($d['kind'] === 'form')
                    @php $form = $d['form']; @endphp
                    <p class="flex flex-wrap items-center gap-2 text-sm"><span class="{{ $chip }} bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">Anında form</span>
                        @if ($form && $form['name'] !== '')<span class="font-semibold text-gray-900 dark:text-white">{{ $form['name'] }}</span>@endif
                        @if ($form && $form['locale'] !== '')<span class="text-xs text-gray-500">{{ $form['locale'] }}</span>@endif
                        @if ($form && $form['status'] !== '' && strtoupper($form['status']) !== 'ACTIVE')<span class="{{ $chip }} bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300">{{ $form['status'] }}</span>@endif
                    </p>
                    @if ($form === null || ($form['error'] !== '' && $form['questions'] === []))
                        <p class="rounded-lg bg-gray-50 p-3 text-sm text-gray-600 dark:bg-white/[0.03] dark:text-gray-300">
                            @if ($d['form_id'] === '')
                                Reklam seti anında form açıyor ama Meta reklamda formun kimliğini vermedi; sorular burada gösterilemiyor.
                            @elseif ($form === null)
                                Formun soruları bir sonraki günlük toplamada okunacak.
                            @else
                                {{ $form['error'] }}
                            @endif
                            Formu Reklam Yöneticisi’nde görebilirsiniz.
                        </p>
                    @else
                        @if (($form['intro']['title'] ?? '') !== '' || ($form['intro']['content'] ?? []) !== [])
                            <div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/[0.03]">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">Giriş ekranı</p>
                                @if (($form['intro']['title'] ?? '') !== '')<p class="font-semibold text-gray-900 dark:text-white">{{ $form['intro']['title'] }}</p>@endif
                                @foreach ($form['intro']['content'] ?? [] as $line)<p class="text-gray-700 dark:text-gray-300">{{ $line }}</p>@endforeach
                            </div>
                        @endif
                        <div>
                            <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Sorular · {{ count($form['questions']) }}</p>
                            <ol class="space-y-1.5 text-sm" data-testid="meta-ad-form-questions">
                                @foreach ($form['questions'] as $i => $question)
                                    <li class="rounded-lg border border-gray-200 p-2 dark:border-gray-700">
                                        <span class="tabular-nums text-gray-400">{{ $i + 1 }}.</span>
                                        <span class="text-gray-900 dark:text-gray-100">{{ $question['label'] ?: ($questionTypes[strtoupper($question['type'])] ?? $question['type']) }}</span>
                                        @if (isset($questionTypes[strtoupper($question['type'])]) && $question['label'])<span class="text-xs text-gray-500">· {{ $questionTypes[strtoupper($question['type'])] }}</span>@endif
                                        @if ($question['options'] !== [])
                                            <span class="mt-1 flex flex-wrap gap-1">@foreach ($question['options'] as $option)<span class="{{ $chip }} bg-gray-100 text-gray-700 dark:bg-white/[0.06] dark:text-gray-300">{{ $option }}</span>@endforeach</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                        @if ($form['thank_you'] !== [])
                            <div class="rounded-lg bg-emerald-50/60 p-3 text-sm dark:bg-emerald-500/10">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Teşekkür ekranı</p>
                                @if (($form['thank_you']['title'] ?? '') !== '')<p class="font-semibold text-gray-900 dark:text-white">{{ $form['thank_you']['title'] }}</p>@endif
                                @if (($form['thank_you']['body'] ?? '') !== '')<p class="text-gray-700 dark:text-gray-300">{{ $form['thank_you']['body'] }}</p>@endif
                                @if (($form['thank_you']['button'] ?? '') !== '')<p class="mt-1 text-xs text-gray-500">Buton: {{ $form['thank_you']['button'] }}@if (($form['thank_you']['url'] ?? '') !== '') → {{ $form['thank_you']['url'] }}@endif</p>@endif
                            </div>
                        @endif
                        <p class="text-xs text-gray-500">Formu dolduranların bilgileri Moximu’ya alınmaz.@if ($form['privacy_policy_url'] !== '') Gizlilik: <a href="{{ $form['privacy_policy_url'] }}" target="_blank" rel="noopener" class="text-brand-600 hover:underline">{{ \Illuminate\Support\Str::limit($form['privacy_policy_url'], 60) }}</a>@endif</p>
                    @endif
                @elseif ($d['kind'] === 'whatsapp')
                    <p class="flex flex-wrap items-center gap-2 text-sm"><span class="{{ $chip }} bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">WhatsApp</span>
                        @if ($d['number'] !== '')<span class="font-semibold tabular-nums text-gray-900 dark:text-white" data-testid="meta-ad-whatsapp">{{ $d['number'] }}</span>
                            <a href="https://wa.me/{{ preg_replace('/\D+/', '', $d['number']) }}" target="_blank" rel="noopener" class="text-xs font-medium text-brand-600 hover:underline">wa.me ↗</a>
                        @else<span class="text-gray-600 dark:text-gray-300">Numara reklamda yok; sayfaya bağlı WhatsApp numarasına gider.</span>@endif
                    </p>
                    @if ($d['welcome'] !== '')
                        <div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/[0.03]"><p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">Karşılama mesajı</p><p class="whitespace-pre-line text-gray-800 dark:text-gray-200">{{ $d['welcome'] }}</p></div>
                    @endif
                @elseif ($d['kind'] === 'message')
                    <p class="text-sm"><span class="{{ $chip }} bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300">{{ $d['channel'] }}</span> Mesaj kutusuna gider.</p>
                    @if ($d['welcome'] !== '')
                        <div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/[0.03]"><p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">Karşılama mesajı</p><p class="whitespace-pre-line text-gray-800 dark:text-gray-200">{{ $d['welcome'] }}</p></div>
                    @endif
                @elseif ($d['kind'] === 'site')
                    <p class="text-sm"><span class="{{ $chip }} bg-gray-100 text-gray-700 dark:bg-white/[0.06] dark:text-gray-300">Web sitesi</span>
                        <a href="{{ $d['url'] }}" target="_blank" rel="noopener" class="break-all font-medium text-brand-600 hover:underline">{{ $d['host'] }}{{ $d['path'] }} ↗</a></p>
                    @if ($d['utm'] !== [])
                        <dl class="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1 text-xs">@foreach ($d['utm'] as $key => $value)<dt class="text-gray-500">{{ $key }}</dt><dd class="break-all text-gray-800 dark:text-gray-200">{{ $value }}</dd>@endforeach</dl>
                    @else
                        <p class="text-xs text-amber-700 dark:text-amber-300">Bağlantıda UTM yok; Analytics’te bu reklamdan gelenler ayrı görünmez.</p>
                    @endif
                @else
                    <p class="text-sm text-gray-500">Reklamın gittiği yer okunamadı.</p>
                @endif
            </section>

            @if ($ad['marks'])
                @php $m = $ad['marks']; @endphp
                <section class="{{ $card }} space-y-2 p-5" data-testid="meta-ad-marks">
                    <h2 class="font-semibold text-gray-900 dark:text-white">Form kalitesi</h2>
                    <p class="text-xs text-gray-500">İçe aktarılan lead listesinde bu reklamdan gelen {{ $m['total'] }} form ve senin işaretlerin (kişi bilgisi tutulmaz).</p>
                    <div class="flex flex-wrap gap-2 text-sm">
                        @foreach (['uygun' => 'Uygun', 'randevu' => 'Randevu', 'satis' => 'Satış', 'uygunsuz' => 'Uygunsuz', 'unmarked' => 'İşaretsiz'] as $key => $label)
                            <span class="{{ $chip }} bg-gray-100 text-gray-700 dark:bg-white/[0.06] dark:text-gray-300">{{ $label }} <span class="font-semibold tabular-nums">{{ $m[$key] }}</span></span>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="{{ $card }} space-y-2 p-5" data-testid="meta-ad-siblings">
                <h2 class="font-semibold text-gray-900 dark:text-white">Aynı setteki reklamlar · {{ count($ad['siblings']) }}</h2>
                <ul class="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    @foreach ($ad['siblings'] as $i => $other)
                        <li @class(['flex items-center justify-between gap-3 py-2', 'font-semibold' => $other['self']])>
                            <span class="min-w-0 truncate"><span class="tabular-nums text-gray-400">{{ $i + 1 }}.</span>
                                @if ($other['self'])<span class="text-gray-900 dark:text-white">{{ $other['name'] }}</span>
                                @else<a href="{{ route('operator.meta.campaign-ad', array_filter(['assetId' => $assetId, 'campaignId' => $ad['campaign']['id'], 'adId' => $other['id'], 'gun' => $days ?? null])) }}" wire:navigate class="text-gray-700 hover:text-brand-600 dark:text-gray-300">{{ $other['name'] }}</a>@endif
                                @if ($other['status'] !== 'live')<span class="text-xs font-normal text-gray-400">· {{ $statusLabel[$other['status']] }}</span>@endif
                            </span>
                            <span class="shrink-0 text-xs tabular-nums text-gray-500">{{ $count($other['results']) }} {{ $unit }} · {{ $money($other['cpr']) }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>
    </div>

    <section class="{{ $card }} p-5" data-testid="meta-ad-chart">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-gray-900 dark:text-white">Son 60 gün</h2>
            <div class="flex flex-wrap gap-4 text-xs text-gray-500">
                <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-brand-200"></span>Günlük harcama</span>
                <span class="flex items-center gap-1.5"><span class="h-0.5 w-4 bg-brand-600"></span>Günlük {{ $unit }}</span>
            </div>
        </div>
        <svg viewBox="0 0 {{ $w }} {{ $h + 18 }}" class="block h-auto w-full" role="img" aria-label="Günlük harcama ve {{ $unit }} grafiği">
            @foreach (array_values($series) as $i => $row)
                @php $bh = ((float) $row['spend'] / $maxSpend) * ($h - 40); @endphp
                <rect x="{{ round($left + $i * $step + 1, 1) }}" y="{{ round($h - 12 - $bh, 1) }}" width="{{ max(1, round($step - 2, 1)) }}" height="{{ round($bh, 1) }}" class="fill-brand-100 dark:fill-brand-500/20"><title>{{ $dates[$i] }} · {{ $money($row['spend']) }} · {{ $num($row[$ad['type']] ?? 0, 0) }} {{ $unit }}</title></rect>
            @endforeach
            <polyline points="{{ implode(' ', $points) }}" fill="none" stroke-width="2" stroke-linejoin="round" class="stroke-brand-600"/>
            @if ($dates !== [])
                <text x="{{ $left }}" y="{{ $h + 14 }}" class="fill-gray-500 text-[11px]">{{ \Carbon\CarbonImmutable::parse($dates[0])->format('d.m') }}</text>
                <text x="{{ $w }}" y="{{ $h + 14 }}" text-anchor="end" class="fill-gray-500 text-[11px]">{{ \Carbon\CarbonImmutable::parse(end($dates))->format('d.m') }}</text>
            @endif
        </svg>
    </section>

    @php $t = $ad['adset']['targeting']; @endphp
    <section class="{{ $card }} p-5" data-testid="meta-ad-targeting">
        <h2 class="mb-3 font-semibold text-gray-900 dark:text-white">Kime gösteriliyor</h2>
        <dl class="grid grid-cols-[8.5rem_1fr] gap-x-3 gap-y-1.5 text-sm">
            <dt class="text-gray-500">Konum</dt><dd class="text-gray-900 dark:text-gray-100">{{ $t['locations'] === [] ? '—' : implode(', ', $t['locations']) }}</dd>
            <dt class="text-gray-500">Yaş · cinsiyet</dt><dd class="text-gray-900 dark:text-gray-100">{{ $t['age'] }} · {{ $t['genders'] }}@if ($t['advantage']) · Advantage+ kitle @endif</dd>
            <dt class="text-gray-500">Yerleşim</dt><dd class="text-gray-900 dark:text-gray-100">{{ $t['placements'] }}</dd>
            <dt class="text-gray-500">Optimizasyon</dt><dd class="text-gray-900 dark:text-gray-100">{{ $ad['adset']['optimization'] ?: '—' }}{{ $ad['adset']['destination'] !== '' ? ' · '.$ad['adset']['destination'] : '' }}</dd>
            @if ($t['audiences'] !== [])<dt class="text-gray-500">Kitleler</dt><dd class="text-gray-900 dark:text-gray-100">{{ implode(', ', $t['audiences']) }}</dd>@endif
            @if ($t['excluded'] !== [])<dt class="text-gray-500">Dışlananlar</dt><dd class="text-gray-900 dark:text-gray-100">{{ implode(', ', $t['excluded']) }}</dd>@endif
            <dt class="text-gray-500">İlgi alanları</dt><dd class="text-gray-900 dark:text-gray-100">{{ $t['interests'] === [] ? 'Yok (geniş kitle)' : implode(', ', $t['interests']) }}</dd>
        </dl>
    </section>
</div>
