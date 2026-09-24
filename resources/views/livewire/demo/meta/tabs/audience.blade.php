@php
    $isTr = app()->getLocale() === 'tr';
    $breakdowns = $professional['breakdowns'] ?? [];
    $targeting = $professional['targeting'] ?? [];
    $sections = [
        ['key' => 'age', 'title' => $isTr ? 'Yaş Grupları' : 'Age Groups'],
        ['key' => 'gender', 'title' => $isTr ? 'Cinsiyet' : 'Gender'],
        ['key' => 'country', 'title' => $isTr ? 'Ülkeler' : 'Countries'],
        ['key' => 'region', 'title' => $isTr ? 'İller / Bölgeler' : 'Regions'],
        ['key' => 'publisher_platform', 'title' => $isTr ? 'Facebook / Instagram Dağılımı' : 'Platform Distribution'],
        ['key' => 'platform_position', 'title' => $isTr ? 'Reklam Konumu' : 'Ad Placement'],
        ['key' => 'device', 'title' => $isTr ? 'Cihazlar' : 'Devices'],
    ];

    $countryNamesTr = [
        'TR' => 'Türkiye', 'GB' => 'Birleşik Krallık', 'DE' => 'Almanya', 'US' => 'ABD',
        'NL' => 'Hollanda', 'FR' => 'Fransa', 'AT' => 'Avusturya', 'BE' => 'Belçika',
        'CH' => 'İsviçre', 'SE' => 'İsveç', 'DK' => 'Danimarka', 'NO' => 'Norveç',
        'FI' => 'Finlandiya', 'IT' => 'İtalya', 'ES' => 'İspanya', 'PL' => 'Polonya',
        'RO' => 'Romanya', 'BG' => 'Bulgaristan', 'GR' => 'Yunanistan', 'IE' => 'İrlanda',
        'CA' => 'Kanada', 'AU' => 'Avustralya', 'AE' => 'BAE', 'SA' => 'Suudi Arabistan',
    ];

    $breakdownLabel = static function (string $key, string $label) use ($isTr, $countryNamesTr): string {
        if (! $isTr) return $label;

        $normalized = trim($label);

        if ($key === 'country') {
            $code = strtoupper($normalized);
            return $countryNamesTr[$code] ?? $code;
        }

        if ($key === 'gender') {
            return match (strtolower($normalized)) {
                'male', 'men' => 'Erkek',
                'female', 'women' => 'Kadın',
                'unknown', 'unclassified' => 'Bilinmiyor',
                default => $normalized,
            };
        }

        if ($key === 'device') {
            return match (strtolower($normalized)) {
                'iphone' => 'iPhone',
                'ipad' => 'iPad',
                'android smartphone', 'android_phone', 'android phone' => 'Android Telefon',
                'android tablet', 'android_tablet' => 'Android Tablet',
                'desktop' => 'Masaüstü',
                'other' => 'Diğer',
                default => $normalized,
            };
        }

        if ($key === 'platform_position') {
            return match (strtolower($normalized)) {
                'facebook feed' => 'Facebook Akışı',
                'instagram feed' => 'Instagram Akışı',
                'facebook stories' => 'Facebook Hikayeler',
                'instagram stories' => 'Instagram Hikayeler',
                'facebook reels' => 'Facebook Reels',
                'instagram reels' => 'Instagram Reels',
                'messenger inbox' => 'Messenger Gelen Kutusu',
                'marketplace' => 'Facebook Marketplace',
                default => $normalized,
            };
        }

        return $normalized;
    };

    $optimizationLabel = static function (?string $value) use ($isTr): string {
        if (! filled($value) || ! $isTr) return $value ?: '—';

        return match (strtolower(trim((string) $value))) {
            'offsite conversions', 'conversions' => 'Dönüşümler',
            'landing page views' => 'Açılış Sayfası Görüntülemeleri',
            'link clicks' => 'Bağlantı Tıklamaları',
            'conversations' => 'Mesajlaşmalar',
            'lead generation', 'leads' => 'Lead Toplama',
            'profile visit', 'profile visits' => 'Profil Ziyaretleri',
            'reach' => 'Erişim',
            'impressions' => 'Gösterimler',
            'post engagement' => 'Gönderi Etkileşimi',
            'video views' => 'Video İzlemeleri',
            default => (string) $value,
        };
    };

    $billingLabel = static function (?string $value) use ($isTr): string {
        if (! filled($value) || ! $isTr) return $value ?: '—';

        return match (strtolower(trim((string) $value))) {
            'impressions' => 'Gösterimler',
            'link clicks' => 'Bağlantı Tıklamaları',
            default => (string) $value,
        };
    };

    $bidLabel = static function (?string $value) use ($isTr): string {
        if (! filled($value) || ! $isTr) return $value ?: '—';

        return match (strtolower(trim((string) $value))) {
            'lowest cost without cap' => 'En Düşük Maliyet · Teklif Sınırı Yok',
            'lowest cost with bid cap' => 'En Düşük Maliyet · Teklif Sınırı',
            'cost cap' => 'Maliyet Sınırı',
            'bid cap' => 'Teklif Sınırı',
            default => (string) $value,
        };
    };

    $translateTargetingChip = static function (string $chip) use ($isTr, $countryNamesTr): string {
        if (! $isTr) return $chip;

        if (str_starts_with($chip, 'Countries: ')) {
            $codes = array_map('trim', explode(',', substr($chip, strlen('Countries: '))));
            $names = array_map(static fn (string $code): string => $countryNamesTr[strtoupper($code)] ?? strtoupper($code), $codes);
            return 'Ülkeler: '.implode(', ', $names);
        }

        if (str_starts_with($chip, 'Age ')) {
            return 'Yaş '.substr($chip, 4);
        }

        if (str_starts_with($chip, 'Platforms: ')) {
            return 'Platformlar: '.substr($chip, strlen('Platforms: '));
        }

        if (preg_match('/^(\d+) custom audiences?$/i', $chip, $m)) {
            return $m[1].' özel hedef kitle';
        }

        if (preg_match('/^(\d+) interests?$/i', $chip, $m)) {
            return $m[1].' ilgi alanı';
        }

        return $chip;
    };
@endphp

<section class="space-y-5">
    <div>
        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">{{ $isTr ? 'Kitle & Dağıtım' : 'Audience & Delivery' }}</p>
        <h2 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Reklam bütçesi gerçekte kimlere ve nerelere gitti?' : 'Who and where actually received the ad budget?' }}</h2>
        <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Üst bölüm reklamların gerçekte kimlere ve hangi alanlarda gösterildiğini anlatır. Alt bölüm ise reklam setlerinde tanımlanan hedefleme ayarlarını gösterir. Böylece “hedeflediğimiz kitle” ile “reklamı gerçekten gören kitle” birbirine karışmaz.' : 'The top shows observed delivery; the lower section shows configured targeting, keeping intended audience separate from actual delivery.' }}</p>
    </div>

    <div class="grid gap-5 lg:grid-cols-2 xl:grid-cols-3">
        @foreach ($sections as $section)
            @php $rows = array_slice($breakdowns[$section['key']] ?? [], 0, 10); @endphp
            <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between gap-3"><h3 class="font-bold text-gray-900 dark:text-white">{{ $section['title'] }}</h3><span class="text-xs font-medium text-gray-400">{{ count($breakdowns[$section['key']] ?? []) }} {{ $isTr ? 'kırılım' : 'values' }}</span></div>
                <div class="mt-5 space-y-4">
                    @forelse ($rows as $row)
                        <div>
                            <div class="flex items-center justify-between gap-3 text-sm"><span class="truncate font-medium text-gray-700 dark:text-gray-300">{{ $breakdownLabel($section['key'], (string) $row['label']) }}</span><span class="shrink-0 text-xs font-semibold tabular-nums text-gray-500">{{ number_format((float) $row['share'], 1) }}% {{ $isTr ? 'harcama payı' : 'spend share' }} · {{ $isTr ? 'Tıklama oranı' : 'Click rate' }} {{ $row['ctr'] !== null ? number_format($row['ctr'], 2).'%' : '—' }}</span></div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/[0.05]"><div class="h-full rounded-full bg-brand-500" style="width: {{ min(100, max(0, (float) $row['share'])) }}%"></div></div>
                            <div class="mt-1 flex justify-between text-[10px] text-gray-400"><span>{{ $professional['currency'] ?? '' }} {{ number_format((float) $row['spend'], 2) }} {{ $isTr ? 'harcama' : 'spend' }}</span><span>{{ number_format((int) $row['impressions']) }} {{ $isTr ? 'gösterim' : 'impressions' }}</span></div>
                        </div>
                    @empty
                        <div class="rounded-xl border border-dashed border-gray-300 px-4 py-10 text-center text-sm text-gray-400 dark:border-gray-700">{{ $isTr ? 'Bu kırılım için yeterli veri yok.' : 'No usable data for this breakdown.' }}</div>
                    @endforelse
                </div>
            </article>
        @endforeach
    </div>

    @if (! empty($geo))
        @php
            $geoSummary = $geo['summary'];
            $geoCurrency = (string) ($geoSummary['currency'] ?? $professional['currency'] ?? '');
            $geoMoney = static fn (?float $value): string => $value === null ? '—' : number_format($value, 0, ',', '.').($geoCurrency !== '' ? ' '.$geoCurrency : '');
            $geoNumber = static fn (float $value): string => $value > 0 ? rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',') : '—';
            $geoRunning = ($geo['state']['state'] ?? null) === 'running';
        @endphp
        <article class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900" @if ($geoRunning) wire:poll.15s @endif>
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800 sm:px-6">
                <div>
                    <h3 class="font-bold text-gray-900 dark:text-white">Ülke ve şehir performansı</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Harcama, tıklama ve sonuçlar (lead, satın alma, mesaj) ülkeye ve şehre göre. Ülkeye tıklayınca şehirleri açılır.@if ($geoSummary['last_date']) <span class="text-gray-400">Son veri: {{ \Illuminate\Support\Carbon::parse($geoSummary['last_date'])->format('d.m.Y') }}</span>@endif</p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" wire:click="collectGeoResults" wire:loading.attr="disabled" @disabled($geoRunning)
                        class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.04]">{{ $geoRunning ? 'Çekiliyor…' : ($geoSummary['has_data'] ? 'Yenile' : 'Veriyi getir') }}</button>
                    <button type="button" x-data @click="$refs.geoAi.classList.toggle('hidden')" title="AI: hangi hizmet, hangi bölge, hangi kitle dönüşüm getirdi?"
                        class="inline-flex h-7 items-center gap-1 rounded-full bg-violet-600 px-2.5 text-[11px] font-semibold text-white hover:bg-violet-700">✨ AI</button>
                </div>
            </div>
            <div x-ref="geoAi" class="{{ ($geo['insight']['production'] ?? null) || ($geo['insight']['state'] ?? null) === 'running' ? '' : 'hidden' }} border-b border-gray-100 px-5 py-4 dark:border-gray-800 sm:px-6">
                <x-operator.ai-insight :insight="$geo['insight']" :compact="true" />
            </div>
            @if (($geo['state']['state'] ?? null) === 'failed')
                <p class="border-b border-rose-100 bg-rose-50 px-5 py-2 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">Son çekim başarısız: {{ $geo['state']['error'] ?? '' }}</p>
            @endif
            @if ($geoSummary['has_data'])
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-gray-50/80 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:bg-white/[0.02]">
                            <tr>
                                <th class="px-5 py-3">Ülke / Şehir</th>
                                <th class="px-3 py-3 text-right">Harcama</th>
                                <th class="px-3 py-3 text-right">Tıklama</th>
                                <th class="px-3 py-3 text-right">Lead</th>
                                <th class="px-3 py-3 text-right">Satın alma</th>
                                <th class="px-3 py-3 text-right">Mesaj</th>
                                <th class="px-5 py-3 text-right">Sonuç başı</th>
                            </tr>
                        </thead>
                        @foreach (array_slice($geoSummary['countries'], 0, 30) as $country)
                            <tbody x-data="{ open: {{ $loop->first ? 'true' : 'false' }} }" class="border-t border-gray-100 dark:border-gray-800">
                                <tr class="cursor-pointer bg-white hover:bg-gray-50 dark:bg-gray-900 dark:hover:bg-white/[0.03]" @click="open = ! open">
                                    <td class="px-5 py-3 font-semibold text-gray-900 dark:text-white"><span class="mr-1 inline-block w-3 text-gray-400" x-text="open ? '▾' : '▸'"></span>{{ $country['country'] !== '' ? ($countryNamesTr[$country['country']] ?? $country['country']) : 'Birden fazla ülke (şehir ülkeye ayrılamadı)' }}@if (count($country['regions']))<span class="ml-1 text-xs font-normal text-gray-400">· {{ count($country['regions']) }} şehir</span>@endif</td>
                                    <td class="px-3 py-3 text-right tabular-nums">{{ $country['country'] !== '' ? $geoMoney($country['spend']) : '' }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums">{{ $country['country'] !== '' ? number_format($country['clicks'], 0, ',', '.') : '' }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums">{{ $country['country'] !== '' ? $geoNumber($country['leads']) : '' }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums">{{ $country['country'] !== '' ? $geoNumber($country['purchases']) : '' }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums">{{ $country['country'] !== '' ? $geoNumber($country['messages']) : '' }}</td>
                                    <td class="px-5 py-3 text-right font-semibold tabular-nums">{{ $country['country'] !== '' ? $geoMoney($country['cost_per_result']) : '' }}</td>
                                </tr>
                                @foreach (array_slice($country['regions'], 0, 40) as $region)
                                    <tr x-show="open" x-cloak class="bg-gray-50/50 text-gray-600 dark:bg-white/[0.01] dark:text-gray-300">
                                        <td class="py-2 pl-10 pr-5">{{ $region['region'] !== '' ? $region['region'] : 'Bilinmiyor' }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ $geoMoney($region['spend']) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($region['clicks'], 0, ',', '.') }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ $geoNumber($region['leads']) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ $geoNumber($region['purchases']) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ $geoNumber($region['messages']) }}</td>
                                        <td class="px-5 py-2 text-right tabular-nums">{{ $geoMoney($region['cost_per_result']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        @endforeach
                    </table>
                </div>
            @else
                <div class="px-5 py-10 text-center text-sm text-gray-400">{{ $geoRunning ? 'Veri Meta’dan çekiliyor…' : 'Bu dönem için ülke / şehir sonuç verisi yok. “Veriyi getir” ile son 90 günü çekin; sonra her gün kendiliğinden güncellenir.' }}</div>
            @endif
        </article>
    @endif

    <article class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800 sm:px-6">
            <h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Tanımlanan Hedef Kitle ve Dağıtım Ayarları' : 'Configured Targeting and Delivery Settings' }}</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Bunlar Meta’ya reklam seti seviyesinde verilen hedefleme ve optimizasyon talimatlarıdır; reklamın gerçekte kimlere gösterildiğini değil, nasıl çalışmasının istendiğini anlatır.' : 'These are Ad Set targeting and optimization instructions, not observed delivery.' }}</p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-left">
                <thead class="bg-gray-50/80 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:bg-white/[0.02]">
                    <tr>
                        <th class="px-5 py-3">{{ $isTr ? 'Reklam Seti' : 'Ad Set' }}</th>
                        <th class="px-4 py-3">{{ $isTr ? 'Neye Göre Optimize Ediliyor?' : 'Optimization' }}</th>
                        <th class="px-4 py-3">{{ $isTr ? 'Teklif Stratejisi' : 'Bidding' }}</th>
                        <th class="px-5 py-3">{{ $isTr ? 'Hedef Kitle Özeti' : 'Targeting Summary' }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse (array_slice($targeting, 0, 100) as $row)
                        <tr>
                            <td class="max-w-xs px-5 py-3.5"><p class="truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $row['adset_name'] }}</p><p class="text-[11px] text-gray-400">ID {{ $row['adset_id'] }}</p></td>
                            <td class="px-4 py-3.5 text-sm text-gray-600 dark:text-gray-300">{{ $optimizationLabel($row['optimization_goal'] ?? null) }}@if ($row['billing_event'])<p class="mt-0.5 text-[11px] text-gray-400">{{ $isTr ? 'Faturalandırma: ' : 'Billing: ' }}{{ $billingLabel($row['billing_event']) }}</p>@endif</td>
                            <td class="px-4 py-3.5 text-sm text-gray-600 dark:text-gray-300">{{ $bidLabel($row['bid_strategy'] ?? null) }}</td>
                            <td class="max-w-xl px-5 py-3.5"><div class="flex flex-wrap gap-1.5">@forelse ($row['summary'] as $chip)<span class="rounded-full bg-gray-100 px-2 py-1 text-[11px] text-gray-600 dark:bg-white/[0.05] dark:text-gray-300">{{ $translateTargetingChip($chip) }}</span>@empty<span class="text-xs text-gray-400">{{ $isTr ? 'Özetlenebilir hedefleme bilgisi yok' : 'No summarized targeting fields' }}</span>@endforelse</div></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-5 py-12 text-center text-sm text-gray-400">{{ $isTr ? 'Tanımlanan hedefleme ayarları henüz alınmamış.' : 'Configured targeting data is not ready.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>

    <div class="rounded-xl border border-blue-200 bg-blue-50/60 px-4 py-3 text-xs leading-5 text-blue-800 dark:border-blue-500/20 dark:bg-blue-500/[0.06] dark:text-blue-300">{{ $isTr ? 'Buradaki yüzdeler kitle büyüklüğü değildir; reklam harcamasının hangi gruba veya alana ne oranda dağıldığını gösterir. Yaş / cinsiyet / reklam konumu kırılımlarında dönüşüm toplanmaz; ülke ve şehir tablosundaki sonuçlar ise reklam bazında Meta’dan ayrıca çekilir.' : 'Percentages are spend share, not audience size. Conversions are not inferred because action data is not collected at these breakdown grains.' }}</div>
</section>
