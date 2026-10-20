@php
    $currency = $account['currency'] ?? '';
    $num = fn ($value, int $decimals = 0) => $value === null ? '—' : number_format((float) $value, $decimals, ',', '.');
    $money = fn ($value) => $value === null ? '—' : number_format((float) $value, 2, ',', '.').' '.$currency;
    $pct = fn ($value) => $value === null ? '—' : (($value > 0 ? '+' : '').number_format((float) $value, 1, ',', '.').'%');
    $count = fn (float $value) => $value >= 10000 ? $num($value / 1000, 1).' bin' : $num($value, $value == floor($value) ? 0 : 1);
    [$unit, $unitCost] = \App\Services\Meta\MetaCampaignBoard::TYPES[$ad['type']];
    /** Meta enums in plain Turkish; unknown ones are humanised, never shown raw. */
    $enumText = fn (string $v): string => \Illuminate\Support\Str::of($v)->lower()->replace('_', ' ')->ucfirst()->toString();
    $optimizationText = fn (string $v): string => \App\Services\Meta\MetaStrategy::OPTIMIZATIONS[strtoupper($v)] ?? $enumText($v);
    $destinationText = fn (string $v): string => ['ON_AD' => 'Anında form', 'WEBSITE' => 'Web sitesi', 'MESSENGER' => 'Messenger', 'WHATSAPP' => 'WhatsApp', 'INSTAGRAM_DIRECT' => 'Instagram mesajı', 'MESSAGING_MESSENGER_WHATSAPP' => 'Messenger / WhatsApp', 'MESSAGING_INSTAGRAM_DIRECT_MESSENGER' => 'Instagram / Messenger', 'MESSAGING_INSTAGRAM_DIRECT_MESSENGER_WHATSAPP' => 'Instagram / Messenger / WhatsApp', 'MESSAGING_INSTAGRAM_DIRECT_WHATSAPP' => 'Instagram / WhatsApp', 'PHONE_CALL' => 'Telefon araması', 'APP' => 'Uygulama', 'ON_PAGE' => 'Facebook sayfası', 'ON_POST' => 'Gönderi', 'ON_VIDEO' => 'Video', 'UNDEFINED' => ''][strtoupper($v)] ?? $enumText($v);
    $formStatus = fn (string $v): string => ['ARCHIVED' => 'Arşivlendi', 'DELETED' => 'Silindi', 'DRAFT' => 'Taslak', 'PAUSED' => 'Durduruldu'][strtoupper($v)] ?? $enumText($v);
    $btn = 'btn h-9! w-full sm:w-auto';
    /** Destination kind label: plain medium text, no filled chip. */
    $chip = 'font-medium text-gray-900 dark:text-white';
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

<div class="space-y-10">
    <section class="min-w-0" data-testid="meta-ad-header">
        <nav class="mb-3 flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[13px] text-gray-500" aria-label="Konum">
            @if ($brand)<a href="{{ route('operator.brand', ['brand' => $brand->id]) }}" wire:navigate class="min-w-0 break-words hover:text-gray-900 dark:hover:text-white">{{ $brand->name }}</a><span class="text-gray-300 dark:text-gray-600" aria-hidden="true">›</span>@endif
            <a href="{{ route('operator.meta.overview', ['assetId' => $assetId]) }}" wire:navigate class="min-w-0 break-words hover:text-gray-900 dark:hover:text-white">{{ $asset['name'] ?? 'Meta' }}</a><span class="text-gray-300 dark:text-gray-600" aria-hidden="true">›</span>
            <a href="{{ $campaignUrl }}" wire:navigate class="min-w-0 break-words hover:text-gray-900 dark:hover:text-white" title="{{ $ad['campaign']['name'] }}">{{ \Illuminate\Support\Str::limit($ad['campaign']['name'], 50) }}</a>
        </nav>
        <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="min-w-0 flex-1 basis-80">
            <h1 class="page-title break-words">{{ $ad['name'] }}</h1>
            <p class="page-sub flex flex-wrap items-center gap-x-2 gap-y-1">
                <span class="inline-flex items-center gap-1.5"><span @class(['h-1.5 w-1.5 rounded-full', 'bg-success-500' => $ad['status'] === 'live', 'bg-gray-300 dark:bg-gray-600' => $ad['status'] !== 'live'])></span>{{ $statusLabel[$ad['status']] }}</span>
                <span class="break-words">Reklam seti: {{ $ad['adset']['name'] ?: '—' }}</span>
                @if (in_array(strtoupper($ad['raw_status']), ['DISAPPROVED', 'WITH_ISSUES'], true))<x-ta.pill tone="critical">Reddedildi</x-ta.pill>@endif
                @if ($ad['fatigue'])<x-ta.pill tone="look">Yoruldu, CTR {{ $ad['fatigue']['first_ctr'] }}% iken {{ $ad['fatigue']['last_ctr'] }}%</x-ta.pill>@endif
            </p>
        </div>
        <div class="flex w-full flex-wrap gap-2 sm:w-auto">
            @if ($c['post_url'] !== '')<a href="{{ $c['post_url'] }}" target="_blank" rel="noopener" class="{{ $btn }}">Gönderiyi Facebook’ta aç <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M7 17 17 7M9 7h8v8"/></svg></a>@endif
            <a href="{{ $ad['ads_manager_url'] }}" target="_blank" rel="noopener" class="{{ $btn }}">Reklam Yöneticisi’nde aç <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M7 17 17 7M9 7h8v8"/></svg></a>
        </div>
        </div>
    </section>

    @php
        $tiles = [
            [ucfirst($unitCost), $money($k['cpr']), 'Önceki dönem '.$money($k['prev_cpr']), $change($k['cpr'], $k['prev_cpr'])],
            [ucfirst($unit), $count($k['results']), 'Önceki dönem '.($k['prev_results'] === null ? '—' : $count($k['prev_results'])), null],
            ['Harcama', $money($k['spend']), 'Önceki dönem '.$money($k['prev_spend']), null],
            ['CTR', $k['ctr'] === null ? '—' : $num($k['ctr'], 2).'%', 'Önceki dönem '.($k['prev_ctr'] === null ? '—' : $num($k['prev_ctr'], 2).'%'), null],
            ['Gösterim, tıklama', $count((float) $k['impressions']), $num($k['clicks']).' tıklama', null],
            ['CPM', $money($k['cpm']), $k['frequency'] === null ? '1000 gösterim' : 'Sıklık '.$num($k['frequency'], 2), null],
        ];
    @endphp
    <section>
        <div class="section-head">
            <h2 class="section-title">Dönem</h2>
            <x-operator.date-range-picker :range="$range" :last-day="$lastDay" note="Meta verisi her gün toplanır." class="w-full sm:ml-auto sm:w-auto" />
        </div>
        <div class="metric-strip grid-cols-2 sm:grid-cols-3" data-testid="meta-ad-kpis">
            @foreach ($tiles as [$label, $value, $sub, $delta])
                <x-ta.metric-card :label="$label" :value="$value" :hint="$sub" :delta="$delta === null ? null : $pct($delta)" :tone="$delta === null ? 'neutral' : ($delta < 0 ? 'positive' : ($delta > 0 ? 'negative' : 'neutral'))" />
            @endforeach
        </div>
    </section>

    <div class="grid gap-10 xl:grid-cols-[minmax(0,26rem)_1fr]">
        {{-- What people see --}}
        <section class="min-w-0 space-y-3" data-testid="meta-ad-creative" x-data="{ copied: '' }">
            <div class="section-head mb-0!">
                <h2 class="section-title">Reklam</h2>
                @if ($c['body'] !== '')
                    <button type="button" x-on:click="navigator.clipboard.writeText(@js($c['body'])); copied = 'body'" class="btn-quiet h-7! px-2! text-xs! sm:ml-auto"><span x-show="copied !== 'body'">Metni kopyala</span><span x-show="copied === 'body'" x-cloak>Kopyalandı</span></button>
                @endif
            </div>
            <div class="card">
                @if ($c['body'] !== '')
                    <p class="whitespace-pre-line p-3 text-sm leading-relaxed text-gray-800 dark:text-gray-200">{{ $c['body'] }}</p>
                @endif
                @if ($image !== '')
                    {{-- A broken creative URL falls back to the neutral placeholder instead of the browser's broken-image icon. --}}
                    <img src="{{ $image }}" alt="" loading="lazy" class="block max-h-[28rem] w-full bg-gray-100 object-contain dark:bg-white/[0.04]" onerror="this.onerror=null;this.hidden=true;this.nextElementSibling.hidden=false">
                    <div hidden class="grid h-48 place-items-center bg-gray-100 text-sm text-gray-500 dark:bg-white/[0.04]">{{ $c['video'] ? 'Video' : 'Görsel' }}</div>
                @else
                    <div class="grid h-48 place-items-center bg-gray-100 text-sm text-gray-500 dark:bg-white/[0.04]">{{ $c['video'] ? 'Video' : 'Görsel yok' }}</div>
                @endif
                @if ($c['title'] !== '' || $c['description'] !== '' || $c['cta'] !== '')
                    <div class="flex items-center gap-3 bg-gray-50 p-3 dark:bg-white/[0.03]">
                        <div class="min-w-0 flex-1">
                            @if ($d['kind'] === 'site')<p class="truncate text-xs text-gray-500">{{ $d['host'] }}</p>@endif
                            @if ($c['title'] !== '')<p class="break-words font-medium text-gray-900 dark:text-white">{{ $c['title'] }}</p>@endif
                            @if ($c['description'] !== '')<p class="text-xs text-gray-500 dark:text-gray-400">{{ $c['description'] }}</p>@endif
                        </div>
                        @if ($c['cta'] !== '')<span class="shrink-0 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-900 dark:border-gray-700 dark:bg-white/[0.06] dark:text-gray-100">{{ $c['cta'] }}</span>@endif
                    </div>
                @endif
            </div>
            @if ($c['body'] === '' && $c['title'] === '')
                <p class="text-xs text-gray-500">Bu reklamın metni henüz okunmadı; bir sonraki günlük toplamada gelir.</p>
            @endif

            @php $variantGroups = array_filter(['Diğer ana metinler' => $c['variants']['bodies'], 'Diğer başlıklar' => $c['variants']['titles'], 'Diğer açıklamalar' => $c['variants']['descriptions']]); @endphp
            @if ($variantGroups !== [])
                <div class="space-y-4 pt-3" data-testid="meta-ad-variants" title="Meta bu varyasyonları kişiye göre değiştirerek gösterir.">
                    @foreach ($variantGroups as $label => $texts)
                        <div>
                            <p class="mb-1.5 text-[13px] font-medium text-gray-900 dark:text-white">{{ $label }} <span class="font-normal tabular-nums text-gray-500">{{ count($texts) }}</span></p>
                            <ul class="card">
                                @foreach ($texts as $text)<li class="whitespace-pre-line border-t border-gray-200 px-4 py-2.5 text-[13px] text-gray-800 first:border-t-0 dark:border-gray-800 dark:text-gray-200">{{ $text }}</li>@endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <div class="min-w-0 space-y-10">
            {{-- Where it leads --}}
            <section data-testid="meta-ad-destination">
                <div class="section-head"><h2 class="section-title">Nereye gidiyor</h2></div>
                <div class="card space-y-3 p-4 sm:p-5">
                @if ($d['kind'] === 'form')
                    @php $form = $d['form']; @endphp
                    <p class="flex flex-wrap items-center gap-2 text-sm"><span class="{{ $chip }}">Anında form</span>
                        @if ($form && $form['name'] !== '')<span class="text-gray-700 dark:text-gray-300">{{ $form['name'] }}</span>@endif
                        @if ($form && $form['locale'] !== '')<span class="text-xs text-gray-500">{{ $form['locale'] }}</span>@endif
                        @if ($form && $form['status'] !== '' && strtoupper($form['status']) !== 'ACTIVE')<x-ta.pill tone="info">{{ $formStatus((string) $form['status']) }}</x-ta.pill>@endif
                    </p>
                    @if ($form === null || ($form['error'] !== '' && $form['questions'] === []))
                        <p class="text-sm text-gray-500 dark:text-gray-400">
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
                            <div class="text-sm">
                                <p class="text-xs text-gray-500">Giriş ekranı</p>
                                @if (($form['intro']['title'] ?? '') !== '')<p class="font-medium text-gray-900 dark:text-white">{{ $form['intro']['title'] }}</p>@endif
                                @foreach ($form['intro']['content'] ?? [] as $line)<p class="text-gray-700 dark:text-gray-300">{{ $line }}</p>@endforeach
                            </div>
                        @endif
                        <div>
                            <p class="mb-1 text-xs text-gray-500">Sorular <span class="tabular-nums">{{ count($form['questions']) }}</span></p>
                            <ol class="divide-y divide-gray-200 rounded-lg border border-gray-200 text-sm dark:divide-gray-800 dark:border-gray-800" data-testid="meta-ad-form-questions">
                                @foreach ($form['questions'] as $i => $question)
                                    <li class="px-3 py-2">
                                        <span class="tabular-nums text-gray-500">{{ $i + 1 }}.</span>
                                        <span class="text-gray-900 dark:text-gray-100">{{ $question['label'] ?: ($questionTypes[strtoupper($question['type'])] ?? $question['type']) }}</span>
                                        @if (isset($questionTypes[strtoupper($question['type'])]) && $question['label'])<span class="ml-1 text-xs text-gray-500">{{ $questionTypes[strtoupper($question['type'])] }}</span>@endif
                                        @if ($question['options'] !== [])
                                            <span class="mt-0.5 block text-xs text-gray-500">@foreach ($question['options'] as $option)<span>{{ $option }}</span>@if (! $loop->last), @endif @endforeach</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                        @if ($form['thank_you'] !== [])
                            <div class="text-sm">
                                <p class="text-xs text-gray-500">Teşekkür ekranı</p>
                                @if (($form['thank_you']['title'] ?? '') !== '')<p class="font-medium text-gray-900 dark:text-white">{{ $form['thank_you']['title'] }}</p>@endif
                                @if (($form['thank_you']['body'] ?? '') !== '')<p class="text-gray-700 dark:text-gray-300">{{ $form['thank_you']['body'] }}</p>@endif
                                @if (($form['thank_you']['button'] ?? '') !== '')<p class="mt-1 text-xs text-gray-500">Buton: {{ $form['thank_you']['button'] }}@if (($form['thank_you']['url'] ?? '') !== ''), {{ $form['thank_you']['url'] }}@endif</p>@endif
                            </div>
                        @endif
                        <p class="text-xs text-gray-500">Formu dolduranların bilgileri Moximu’ya alınmaz.@if ($form['privacy_policy_url'] !== '') Gizlilik: <a href="{{ $form['privacy_policy_url'] }}" target="_blank" rel="noopener" class="link-neutral break-all">{{ \Illuminate\Support\Str::limit($form['privacy_policy_url'], 60) }}</a>@endif</p>
                    @endif
                @elseif ($d['kind'] === 'whatsapp')
                    <p class="flex flex-wrap items-center gap-2 text-sm"><span class="{{ $chip }}">WhatsApp</span>
                        @if ($d['number'] !== '')<span class="tabular-nums text-gray-900 dark:text-white" data-testid="meta-ad-whatsapp">{{ $d['number'] }}</span>
                            <a href="https://wa.me/{{ preg_replace('/\D+/', '', $d['number']) }}" target="_blank" rel="noopener" class="link-neutral text-xs">wa.me</a>
                        @else<span class="text-gray-500 dark:text-gray-400">Numara reklamda yok; sayfaya bağlı WhatsApp numarasına gider.</span>@endif
                    </p>
                    @if ($d['welcome'] !== '')
                        <div class="text-sm"><p class="text-xs text-gray-500">Karşılama mesajı</p><p class="whitespace-pre-line text-gray-800 dark:text-gray-200">{{ $d['welcome'] }}</p></div>
                    @endif
                @elseif ($d['kind'] === 'message')
                    <p class="text-sm"><span class="{{ $chip }}">{{ $d['channel'] }}</span> Mesaj kutusuna gider.</p>
                    @if ($d['welcome'] !== '')
                        <div class="text-sm"><p class="text-xs text-gray-500">Karşılama mesajı</p><p class="whitespace-pre-line text-gray-800 dark:text-gray-200">{{ $d['welcome'] }}</p></div>
                    @endif
                @elseif ($d['kind'] === 'site')
                    <p class="text-sm"><span class="{{ $chip }}">Web sitesi</span>
                        <a href="{{ $d['url'] }}" target="_blank" rel="noopener" class="link-neutral break-all">{{ $d['host'] }}{{ $d['path'] }}</a></p>
                    @if ($d['utm'] !== [])
                        <dl class="grid grid-cols-[6rem_minmax(0,1fr)] gap-x-3 gap-y-1 text-xs sm:grid-cols-[8rem_minmax(0,1fr)]">@foreach ($d['utm'] as $key => $value)<dt class="text-gray-500">{{ $key }}</dt><dd class="break-all text-gray-800 dark:text-gray-200">{{ $value }}</dd>@endforeach</dl>
                    @else
                        <p class="text-xs text-warning-700 dark:text-warning-300">Bağlantıda UTM yok; Analytics’te bu reklamdan gelenler ayrı görünmez.</p>
                    @endif
                @else
                    <p class="text-sm text-gray-500">Reklamın gittiği yer okunamadı.</p>
                @endif
                </div>
            </section>

            @if ($ad['marks'])
                @php $m = $ad['marks']; @endphp
                <section data-testid="meta-ad-marks">
                    <div class="section-head"><h2 class="section-title" title="İçe aktarılan lead listesinde bu reklamdan gelen formlar ve senin işaretlerin (kişi bilgisi tutulmaz).">Form kalitesi <span class="section-count">{{ $m['total'] }} form</span></h2></div>
                    <div class="metric-strip grid-cols-3 sm:grid-cols-5">
                        @foreach (['uygun' => 'Uygun', 'randevu' => 'Randevu', 'satis' => 'Satış', 'uygunsuz' => 'Uygunsuz', 'unmarked' => 'İşaretsiz'] as $key => $label)
                            <p class="bg-white px-4 py-3 text-xs text-gray-500 dark:bg-gray-900"><span class="block text-xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $m[$key] }}</span>{{ $label }}</p>
                        @endforeach
                    </div>
                </section>
            @endif

            <section data-testid="meta-ad-siblings">
                <div class="section-head"><h2 class="section-title">Aynı setteki reklamlar <span class="section-count">{{ count($ad['siblings']) }}</span></h2></div>
                <ul class="card text-sm">
                    @foreach ($ad['siblings'] as $i => $other)
                        <li @class(['card-row justify-between py-3!', 'bg-gray-50 font-medium dark:bg-white/[0.03]' => $other['self']])>
                            <span class="min-w-0 truncate"><span class="tabular-nums text-gray-500">{{ $i + 1 }}.</span>
                                @if ($other['self'])<span class="text-gray-900 dark:text-white">{{ $other['name'] }}</span>
                                @else<a href="{{ route('operator.meta.campaign-ad', array_filter(['assetId' => $assetId, 'campaignId' => $ad['campaign']['id'], 'adId' => $other['id'], 'gun' => $days ?? null])) }}" wire:navigate class="text-gray-700 hover:underline dark:text-gray-300" title="{{ $other['name'] }}">{{ $other['name'] }}</a>@endif
                                @if ($other['status'] !== 'live')<span class="ml-1 text-xs font-normal text-gray-500">{{ $statusLabel[$other['status']] }}</span>@endif
                            </span>
                            <span class="shrink-0 text-xs tabular-nums text-gray-500">{{ $count($other['results']) }} {{ $unit }}, {{ $money($other['cpr']) }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>
    </div>

    <section data-testid="meta-ad-chart">
        <div class="section-head">
            <h2 class="section-title">Son 60 gün</h2>
            <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 sm:ml-auto">
                <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-second/20"></span>Günlük harcama</span>
                <span class="flex items-center gap-1.5"><span class="h-0.5 w-4 bg-second"></span>Günlük {{ $unit }}</span>
            </div>
        </div>
        <div class="card p-4">
        <svg viewBox="0 0 {{ $w }} {{ $h + 18 }}" class="block h-auto w-full" role="img" aria-label="Günlük harcama ve {{ $unit }} grafiği">
            @foreach (array_values($series) as $i => $row)
                @php $bh = ((float) $row['spend'] / $maxSpend) * ($h - 40); @endphp
                <rect x="{{ round($left + $i * $step + 1, 1) }}" y="{{ round($h - 12 - $bh, 1) }}" width="{{ max(1, round($step - 2, 1)) }}" height="{{ round($bh, 1) }}" rx="1.5" class="fill-second/20"><title>{{ $dates[$i] }}: {{ $money($row['spend']) }}, {{ $num($row[$ad['type']] ?? 0, 0) }} {{ $unit }}</title></rect>
            @endforeach
            <polyline points="{{ implode(' ', $points) }}" fill="none" stroke-width="2" stroke-linejoin="round" class="stroke-second"/>
            @if ($dates !== [])
                <text x="{{ $left }}" y="{{ $h + 14 }}" class="fill-gray-500 text-xs">{{ \Carbon\CarbonImmutable::parse($dates[0])->format('d.m') }}</text>
                <text x="{{ $w }}" y="{{ $h + 14 }}" text-anchor="end" class="fill-gray-500 text-xs">{{ \Carbon\CarbonImmutable::parse(end($dates))->format('d.m') }}</text>
            @endif
        </svg>
        </div>
    </section>

    @php $t = $ad['adset']['targeting']; @endphp
    <section data-testid="meta-ad-targeting">
        <div class="section-head"><h2 class="section-title">Kime gösteriliyor</h2></div>
        <dl class="card grid grid-cols-[7rem_minmax(0,1fr)] gap-x-3 gap-y-2 break-words p-4 text-sm sm:grid-cols-[9rem_minmax(0,1fr)] sm:p-5">
            <dt class="text-gray-500">Konum</dt><dd class="text-gray-900 dark:text-gray-100">{{ $t['locations'] === [] ? '—' : implode(', ', $t['locations']) }}</dd>
            <dt class="text-gray-500">Yaş, cinsiyet</dt><dd class="text-gray-900 dark:text-gray-100">{{ $t['age'] }}, {{ $t['genders'] }}@if ($t['advantage']), Advantage+ kitle @endif</dd>
            <dt class="text-gray-500">Yerleşim</dt><dd class="text-gray-900 dark:text-gray-100">{{ $t['placements'] }}</dd>
            <dt class="text-gray-500">Optimizasyon</dt><dd class="text-gray-900 dark:text-gray-100">{{ $ad['adset']['optimization'] ? $optimizationText((string) $ad['adset']['optimization']) : '—' }}{{ $ad['adset']['destination'] !== '' && $destinationText((string) $ad['adset']['destination']) !== '' ? ', '.$destinationText((string) $ad['adset']['destination']) : '' }}</dd>
            @if ($t['audiences'] !== [])<dt class="text-gray-500">Kitleler</dt><dd class="text-gray-900 dark:text-gray-100">{{ implode(', ', $t['audiences']) }}</dd>@endif
            @if ($t['excluded'] !== [])<dt class="text-gray-500">Dışlananlar</dt><dd class="text-gray-900 dark:text-gray-100">{{ implode(', ', $t['excluded']) }}</dd>@endif
            <dt class="text-gray-500">İlgi alanları</dt><dd class="text-gray-900 dark:text-gray-100">{{ $t['interests'] === [] ? 'Yok (geniş kitle)' : implode(', ', $t['interests']) }}</dd>
        </dl>
    </section>
</div>
