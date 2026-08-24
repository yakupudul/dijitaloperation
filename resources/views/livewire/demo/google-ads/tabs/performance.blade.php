@php
    $isTr = app()->getLocale() === 'tr';
    $currency = (string) ($professional['currency'] ?? '');
    $perf = is_array($professional['performance'] ?? null) ? $professional['performance'] : [];

    $deviceRows = collect($perf['device'] ?? [])->values();
    $networkRows = collect($perf['network'] ?? [])->values();
    $hourRows = collect($perf['hour'] ?? [])->values();
    $locationRows = collect($perf['location'] ?? [])->values();
    $ageRows = collect($perf['age'] ?? [])->values();
    $genderRows = collect($perf['gender'] ?? [])->values();
    $campaignAudienceRows = collect($perf['campaign_audience'] ?? [])->values();
    $adGroupAudienceRows = collect($perf['ad_group_audience'] ?? [])->values();

    $fmtMoney = fn ($v) => is_numeric($v) ? number_format((float) $v, 2, ',', '.').' '.$currency : '—';
    $fmtNumber = fn ($v, int $digits = 0) => is_numeric($v) ? number_format((float) $v, $digits, ',', '.') : '—';
    $fmtPct = fn ($v, int $digits = 1) => is_numeric($v) ? number_format((float) $v, $digits, ',', '.').'%' : '—';

    $deviceLabels = [
        'MOBILE' => $isTr ? 'Mobil' : 'Mobile',
        'DESKTOP' => $isTr ? 'Masaüstü' : 'Desktop',
        'TABLET' => $isTr ? 'Tablet' : 'Tablet',
        'CONNECTED_TV' => $isTr ? 'Bağlı TV' : 'Connected TV',
        'OTHER' => $isTr ? 'Diğer' : 'Other',
        'UNKNOWN' => $isTr ? 'Bilinmiyor' : 'Unknown',
    ];
    $networkLabels = [
        'SEARCH' => $isTr ? 'Google Arama' : 'Google Search',
        'SEARCH_PARTNERS' => $isTr ? 'Arama İş Ortakları' : 'Search Partners',
        'CONTENT' => $isTr ? 'Görüntülü Reklam Ağı' : 'Display Network',
        'YOUTUBE' => 'YouTube',
        'MIXED' => $isTr ? 'Karma' : 'Mixed',
    ];
    $dayLabels = [
        'MONDAY' => $isTr ? 'Pazartesi' : 'Monday',
        'TUESDAY' => $isTr ? 'Salı' : 'Tuesday',
        'WEDNESDAY' => $isTr ? 'Çarşamba' : 'Wednesday',
        'THURSDAY' => $isTr ? 'Perşembe' : 'Thursday',
        'FRIDAY' => $isTr ? 'Cuma' : 'Friday',
        'SATURDAY' => $isTr ? 'Cumartesi' : 'Saturday',
        'SUNDAY' => $isTr ? 'Pazar' : 'Sunday',
    ];
    $dayOrder = ['MONDAY','TUESDAY','WEDNESDAY','THURSDAY','FRIDAY','SATURDAY','SUNDAY'];

    // Network rows are the cleanest account-level segment total when available.
    $canonicalRows = $networkRows->isNotEmpty() ? $networkRows : $deviceRows;
    $totalSpend = (float) $canonicalRows->sum(fn ($row) => (float) ($row['cost'] ?? 0));
    $totalConversions = (float) $canonicalRows->sum(fn ($row) => (float) ($row['conversions'] ?? 0));
    $totalClicks = (int) $canonicalRows->sum(fn ($row) => (int) ($row['clicks'] ?? 0));
    $totalImpressions = (int) $canonicalRows->sum(fn ($row) => (int) ($row['impressions'] ?? 0));
    $totalConversionValue = (float) $canonicalRows->sum(fn ($row) => (float) ($row['conversion_value'] ?? 0));
    $blendedCpa = $totalConversions > 0 ? $totalSpend / $totalConversions : null;
    $accountCtr = $totalImpressions > 0 ? ($totalClicks / $totalImpressions) * 100 : null;
    $accountCvr = $totalClicks > 0 ? ($totalConversions / $totalClicks) * 100 : null;
    $accountRoas = $totalSpend > 0 && $totalConversionValue > 0 ? $totalConversionValue / $totalSpend : null;
    $hasValueTracking = $totalConversionValue > 0;

    $meaningfulSpend = max(1.0, $totalSpend * 0.01);

    $bestDevice = $deviceRows
        ->filter(fn ($row) => (float) ($row['conversions'] ?? 0) > 0 && (float) ($row['cost'] ?? 0) >= $meaningfulSpend)
        ->sortBy(fn ($row) => (float) ($row['cpa'] ?? PHP_FLOAT_MAX))
        ->first();
    $highestSpendDevice = $deviceRows->sortByDesc(fn ($row) => (float) ($row['cost'] ?? 0))->first();
    $wasteDevice = $deviceRows
        ->filter(fn ($row) => (float) ($row['cost'] ?? 0) > 0 && (float) ($row['conversions'] ?? 0) <= 0)
        ->sortByDesc(fn ($row) => (float) ($row['cost'] ?? 0))
        ->first();

    $dayRows = collect($dayOrder)->map(function ($day) use ($hourRows) {
        $rows = $hourRows->where('day_of_week', $day);
        $spend = (float) $rows->sum(fn ($row) => (float) ($row['cost'] ?? 0));
        $conversions = (float) $rows->sum(fn ($row) => (float) ($row['conversions'] ?? 0));
        $clicks = (int) $rows->sum(fn ($row) => (int) ($row['clicks'] ?? 0));
        $impressions = (int) $rows->sum(fn ($row) => (int) ($row['impressions'] ?? 0));
        return [
            'day' => $day,
            'cost' => $spend,
            'conversions' => $conversions,
            'clicks' => $clicks,
            'impressions' => $impressions,
            'cpa' => $conversions > 0 ? $spend / $conversions : null,
            'cvr' => $clicks > 0 ? ($conversions / $clicks) * 100 : null,
            'ctr' => $impressions > 0 ? ($clicks / $impressions) * 100 : null,
        ];
    })->filter(fn ($row) => $row['cost'] > 0 || $row['clicks'] > 0 || $row['impressions'] > 0)->values();

    $bestDay = $dayRows
        ->filter(fn ($row) => $row['conversions'] > 0)
        ->sortBy(fn ($row) => $row['cpa'] ?? PHP_FLOAT_MAX)
        ->first();
    $highestSpendDay = $dayRows->sortByDesc('cost')->first();

    $bestHour = $hourRows
        ->filter(fn ($row) => (float) ($row['conversions'] ?? 0) > 0 && (float) ($row['cost'] ?? 0) >= $meaningfulSpend)
        ->sortBy(fn ($row) => (float) ($row['cpa'] ?? PHP_FLOAT_MAX))
        ->first();
    $wasteHours = $hourRows
        ->filter(fn ($row) => (float) ($row['cost'] ?? 0) > 0 && (float) ($row['conversions'] ?? 0) <= 0)
        ->sortByDesc(fn ($row) => (float) ($row['cost'] ?? 0))
        ->take(6)
        ->values();
    $topHours = $hourRows
        ->filter(fn ($row) => (float) ($row['conversions'] ?? 0) > 0)
        ->sortBy(fn ($row) => (float) ($row['cpa'] ?? PHP_FLOAT_MAX))
        ->take(8)
        ->values();

    $maxDeviceSpend = (float) $deviceRows->max(fn ($row) => (float) ($row['cost'] ?? 0));
    $deviceConcentration = $totalSpend > 0 ? ($maxDeviceSpend / $totalSpend) * 100 : null;

    $locationSpend = (float) $locationRows->sum(fn ($row) => (float) ($row['cost'] ?? 0));
    $hasLocationLabels = $locationRows->contains(fn ($row) => filled($row['name'] ?? null) || filled($row['location_name'] ?? null) || filled($row['country_name'] ?? null));
    $hasAgeLabels = $ageRows->contains(fn ($row) => filled($row['label'] ?? null) || filled($row['age_range'] ?? null) || filled($row['age_range_type'] ?? null));
    $hasGenderLabels = $genderRows->contains(fn ($row) => filled($row['label'] ?? null) || filled($row['gender'] ?? null) || filled($row['gender_type'] ?? null));

    $signals = collect();
    if ($bestDevice) {
        $signals->push([
            'tone' => 'good',
            'title' => $isTr ? 'En verimli cihaz' : 'Most efficient device',
            'value' => $deviceLabels[strtoupper((string) ($bestDevice['device'] ?? ''))] ?? (string) ($bestDevice['device'] ?? '—'),
            'detail' => ($isTr ? 'CPA ' : 'CPA ').$fmtMoney($bestDevice['cpa'] ?? null).' · '.($isTr ? 'Dönüşüm ' : 'Conversions ').$fmtNumber($bestDevice['conversions'] ?? 0, 2),
        ]);
    }
    if ($bestHour) {
        $day = $dayLabels[strtoupper((string) ($bestHour['day_of_week'] ?? ''))] ?? (string) ($bestHour['day_of_week'] ?? '');
        $hour = isset($bestHour['hour']) ? str_pad((string) $bestHour['hour'], 2, '0', STR_PAD_LEFT).':00' : '—';
        $signals->push([
            'tone' => 'good',
            'title' => $isTr ? 'En verimli zaman penceresi' : 'Most efficient time window',
            'value' => trim($day.' '.$hour),
            'detail' => 'CPA '.$fmtMoney($bestHour['cpa'] ?? null).' · CVR '.$fmtPct($bestHour['cvr'] ?? null),
        ]);
    }
    if ($wasteDevice) {
        $signals->push([
            'tone' => 'risk',
            'title' => $isTr ? 'Dönüşümsüz cihaz harcaması' : 'Device spend without conversions',
            'value' => $deviceLabels[strtoupper((string) ($wasteDevice['device'] ?? ''))] ?? (string) ($wasteDevice['device'] ?? '—'),
            'detail' => $fmtMoney($wasteDevice['cost'] ?? null).' · '.($isTr ? 'dönüşüm yok' : 'no conversions'),
        ]);
    }
    if ($wasteHours->isNotEmpty()) {
        $wasteSpend = (float) $wasteHours->sum(fn ($row) => (float) ($row['cost'] ?? 0));
        $signals->push([
            'tone' => 'risk',
            'title' => $isTr ? 'İncelenecek saat dilimleri' : 'Time windows to review',
            'value' => $fmtMoney($wasteSpend),
            'detail' => ($isTr ? 'İlk ' : 'Top ').$wasteHours->count().($isTr ? ' dönüşümsüz saat dilimindeki harcama' : ' non-converting time windows'),
        ]);
    }
@endphp

<div class="space-y-5">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Performans Merkezi' : 'Performance Center' }}</h2>
                <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20">{{ $isTr ? 'Karar odaklı' : 'Decision-oriented' }}</span>
            </div>
            <p class="mt-1 max-w-4xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Ham kırılımlardan önce şu soruya cevap verir: Bütçe nerede sonuç üretiyor, nerede verimsizleşiyor ve neyi incelemek gerekiyor?' : 'Answers the operating question before showing raw breakdowns: where is spend producing results, where is efficiency deteriorating, and what deserves review?' }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="setTab('budget_bidding')" class="rounded-lg px-3 py-2 text-xs font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">{{ $isTr ? 'Bütçe & Teklif' : 'Budget & Bidding' }}</button>
            <button type="button" wire:click="setTab('search_demand')" class="rounded-lg px-3 py-2 text-xs font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">{{ $isTr ? 'Arama sinyalleri' : 'Search signals' }}</button>
            <button type="button" wire:click="setTab('measurement')" class="rounded-lg px-3 py-2 text-xs font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">{{ $isTr ? 'Dönüşüm ölçümü' : 'Conversion measurement' }}</button>
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <x-ta.metric-card :label="$isTr ? 'Harcama' : 'Spend'" :value="$fmtMoney($totalSpend)" />
        <x-ta.metric-card :label="$isTr ? 'Dönüşüm' : 'Conversions'" :value="$fmtNumber($totalConversions, 2)" />
        <x-ta.metric-card label="CPA" :value="$fmtMoney($blendedCpa)" />
        <x-ta.metric-card label="CVR" :value="$fmtPct($accountCvr)" />
        <x-ta.metric-card label="CTR" :value="$fmtPct($accountCtr)" />
    </div>

    <div class="grid gap-4 xl:grid-cols-[1.2fr_0.8fr]">
        <section class="rounded-2xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Performans nabzı' : 'Performance pulse' }}</h3>
                    <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Provider verisinden türetilen, otomatik işlem yapmayan öncelik sinyalleri.' : 'Priority signals derived from provider facts; no automatic changes are applied.' }}</p>
                </div>
                <span class="rounded-full bg-gray-100 px-2.5 py-1 text-[11px] font-medium text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ $signals->count() }} {{ $isTr ? 'sinyal' : 'signals' }}</span>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-2">
                @forelse ($signals as $signal)
                    <div @class([
                        'rounded-xl p-4 ring-1 ring-inset',
                        'bg-emerald-50/60 ring-emerald-200 dark:bg-emerald-500/10 dark:ring-emerald-500/20' => $signal['tone'] === 'good',
                        'bg-amber-50/70 ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/20' => $signal['tone'] === 'risk',
                    ])>
                        <p @class(['text-xs font-semibold','text-emerald-700 dark:text-emerald-300' => $signal['tone'] === 'good','text-amber-700 dark:text-amber-300' => $signal['tone'] === 'risk'])>{{ $signal['title'] }}</p>
                        <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $signal['value'] }}</p>
                        <p class="mt-1 text-xs leading-5 text-gray-600 dark:text-gray-300">{{ $signal['detail'] }}</p>
                    </div>
                @empty
                    <div class="col-span-2 rounded-xl bg-gray-50 p-5 text-sm text-gray-500 dark:bg-white/[0.02]">{{ $isTr ? 'Bu dönem için karar sinyali üretmeye yetecek segment verisi yok.' : 'Not enough segment data to produce decision signals for this period.' }}</div>
                @endforelse
            </div>
        </section>

        <section class="rounded-2xl bg-gray-950 p-4 text-white ring-1 ring-inset ring-white/10">
            <p class="text-xs font-semibold uppercase tracking-wide text-white/50">{{ $isTr ? 'Hesap bağlamı' : 'Account context' }}</p>
            <div class="mt-4 space-y-4">
                <div>
                    <div class="flex items-center justify-between text-sm"><span class="text-white/70">{{ $isTr ? 'Baskın cihaz payı' : 'Dominant device share' }}</span><strong>{{ $fmtPct($deviceConcentration) }}</strong></div>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-white/10"><div class="h-full rounded-full bg-white/70" style="width: {{ min(100, max(0, (float)($deviceConcentration ?? 0))) }}%"></div></div>
                    @if ($highestSpendDevice)<p class="mt-1.5 text-xs text-white/50">{{ $deviceLabels[strtoupper((string)($highestSpendDevice['device'] ?? ''))] ?? ($highestSpendDevice['device'] ?? '—') }} · {{ $fmtMoney($highestSpendDevice['cost'] ?? null) }}</p>@endif
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-xl bg-white/5 p-3"><p class="text-[11px] text-white/50">{{ $isTr ? 'En güçlü gün' : 'Best day' }}</p><p class="mt-1 font-semibold">{{ $bestDay ? ($dayLabels[$bestDay['day']] ?? $bestDay['day']) : '—' }}</p>@if($bestDay)<p class="mt-1 text-xs text-white/50">CPA {{ $fmtMoney($bestDay['cpa']) }}</p>@endif</div>
                    <div class="rounded-xl bg-white/5 p-3"><p class="text-[11px] text-white/50">{{ $isTr ? 'En çok harcayan gün' : 'Highest spend day' }}</p><p class="mt-1 font-semibold">{{ $highestSpendDay ? ($dayLabels[$highestSpendDay['day']] ?? $highestSpendDay['day']) : '—' }}</p>@if($highestSpendDay)<p class="mt-1 text-xs text-white/50">{{ $fmtMoney($highestSpendDay['cost']) }}</p>@endif</div>
                </div>

                <div class="rounded-xl border border-white/10 p-3">
                    @if ($hasValueTracking)
                        <div class="flex items-center justify-between"><span class="text-sm text-white/70">ROAS</span><strong>{{ number_format((float)$accountRoas, 2, ',', '.') }}x</strong></div>
                        <p class="mt-1 text-xs text-white/50">{{ $isTr ? 'Dönüşüm değeri provider tarafından mevcut.' : 'Provider conversion value is available.' }}</p>
                    @else
                        <p class="text-sm font-semibold">{{ $isTr ? 'ROAS bilinçli olarak gizlendi' : 'ROAS intentionally hidden' }}</p>
                        <p class="mt-1 text-xs leading-5 text-white/50">{{ $isTr ? 'Dönüşüm değeri yokken 0,00x göstermek yanlış karar üretebilir. Değer takibi gelene kadar CPA/CVR esas alınır.' : 'Showing 0.00x without conversion value can mislead decisions. CPA/CVR remain primary until value tracking is available.' }}</p>
                    @endif
                </div>
            </div>
        </section>
    </div>

    <section class="overflow-hidden rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Cihaz verimliliği' : 'Device efficiency' }}</h3>
            <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Harcama payı ile sonuç kalitesini birlikte oku; yüksek hacim tek başına iyi performans değildir.' : 'Read spend share together with outcome quality; volume alone is not performance.' }}</p>
        </div>
        <div class="grid gap-3 p-4 md:grid-cols-2 xl:grid-cols-4">
            @forelse ($deviceRows->sortByDesc(fn ($row) => (float)($row['cost'] ?? 0)) as $row)
                @php
                    $spend = (float) ($row['cost'] ?? 0);
                    $share = $totalSpend > 0 ? ($spend / $totalSpend) * 100 : 0;
                    $deviceKey = strtoupper((string) ($row['device'] ?? ''));
                @endphp
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                    <div class="flex items-start justify-between gap-2"><div><p class="font-semibold text-gray-900 dark:text-white">{{ $deviceLabels[$deviceKey] ?? ($row['device'] ?? '—') }}</p><p class="mt-1 text-xs text-gray-500">{{ $fmtPct($share) }} {{ $isTr ? 'harcama payı' : 'of spend' }}</p></div><span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ $fmtNumber($row['conversions'] ?? 0, 2) }} conv.</span></div>
                    <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5"><div class="h-full rounded-full bg-brand-500" style="width: {{ min(100, max(0, $share)) }}%"></div></div>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-xs"><div><dt class="text-gray-400">{{ $isTr ? 'Harcama' : 'Spend' }}</dt><dd class="mt-1 font-semibold text-gray-800 dark:text-gray-200">{{ $fmtMoney($spend) }}</dd></div><div><dt class="text-gray-400">CPA</dt><dd class="mt-1 font-semibold text-gray-800 dark:text-gray-200">{{ $fmtMoney($row['cpa'] ?? null) }}</dd></div><div><dt class="text-gray-400">CVR</dt><dd class="mt-1 font-semibold text-gray-800 dark:text-gray-200">{{ $fmtPct($row['cvr'] ?? null) }}</dd></div><div><dt class="text-gray-400">CTR</dt><dd class="mt-1 font-semibold text-gray-800 dark:text-gray-200">{{ $fmtPct($row['ctr'] ?? null) }}</dd></div></dl>
                </div>
            @empty
                <div class="col-span-full px-4 py-8 text-center text-sm text-gray-400">{{ $isTr ? 'Cihaz kırılımı için kullanılabilir provider verisi yok.' : 'No usable provider data for device performance.' }}</div>
            @endforelse
        </div>
    </section>

    @if ($networkRows->isNotEmpty())
        <section class="overflow-hidden rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800"><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Ağ dağılımı' : 'Network distribution' }}</h3><p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Ağlar arasında maliyet ve dönüşüm verimliliği.' : 'Cost and conversion efficiency across networks.' }}</p></div>
            <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/[0.02]"><tr><th class="px-4 py-2.5 text-left">{{ $isTr ? 'Ağ' : 'Network' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Harcama' : 'Spend' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Pay' : 'Share' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Dönüşüm' : 'Conversions' }}</th><th class="px-3 py-2.5 text-right">CVR</th><th class="px-4 py-2.5 text-right">CPA</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($networkRows->sortByDesc(fn ($row) => (float)($row['cost'] ?? 0)) as $row)
                    @php $networkSpend = (float)($row['cost'] ?? 0); $networkShare = $totalSpend > 0 ? ($networkSpend / $totalSpend) * 100 : null; $networkKey = strtoupper((string)($row['ad_network_type'] ?? '')); @endphp
                    <tr><td class="px-4 py-2.5 font-medium text-gray-800 dark:text-gray-200">{{ $networkLabels[$networkKey] ?? ($row['ad_network_type'] ?? '—') }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $fmtMoney($networkSpend) }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $fmtPct($networkShare) }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $fmtNumber($row['conversions'] ?? 0, 2) }}</td><td class="px-3 py-2.5 text-right tabular-nums">{{ $fmtPct($row['cvr'] ?? null) }}</td><td class="px-4 py-2.5 text-right tabular-nums font-medium">{{ $fmtMoney($row['cpa'] ?? null) }}</td></tr>
                @endforeach
            </tbody></table></div>
        </section>
    @endif

    <section class="rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Zaman performansı' : 'Time performance' }}</h3>
            <p class="mt-1 text-xs text-gray-500">{{ $isTr ? '168 satırlık bir döküm yerine önce günleri, sonra gerçekten karar gerektiren saatleri gösterir.' : 'Shows days first, then the time windows that actually deserve decisions instead of a long raw dump.' }}</p>
        </div>

        <div class="grid gap-3 border-b border-gray-100 p-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7 dark:border-gray-800">
            @forelse ($dayRows as $row)
                <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.02]"><p class="text-xs font-semibold text-gray-700 dark:text-gray-300">{{ $dayLabels[$row['day']] ?? $row['day'] }}</p><p class="mt-2 text-lg font-semibold text-gray-900 dark:text-white">{{ $fmtMoney($row['cost']) }}</p><div class="mt-2 flex items-center justify-between text-[11px] text-gray-500"><span>{{ $fmtNumber($row['conversions'], 2) }} conv.</span><span>CPA {{ $fmtMoney($row['cpa']) }}</span></div></div>
            @empty
                <div class="col-span-full py-6 text-center text-sm text-gray-400">{{ $isTr ? 'Gün/saat verisi yok.' : 'No day/hour data.' }}</div>
            @endforelse
        </div>

        <div class="grid gap-4 p-4 xl:grid-cols-2">
            <div>
                <div class="mb-3 flex items-center justify-between"><div><h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'En verimli saatler' : 'Most efficient hours' }}</h4><p class="mt-0.5 text-xs text-gray-500">{{ $isTr ? 'Dönüşüm üreten saatler CPA’ya göre.' : 'Converting windows ranked by CPA.' }}</p></div></div>
                <div class="space-y-2">
                    @forelse ($topHours as $row)
                        @php $dayKey = strtoupper((string)($row['day_of_week'] ?? '')); @endphp
                        <div class="grid grid-cols-[1fr_auto_auto] items-center gap-3 rounded-lg border border-gray-100 px-3 py-2.5 text-xs dark:border-gray-800"><div><span class="font-semibold text-gray-800 dark:text-gray-200">{{ $dayLabels[$dayKey] ?? ($row['day_of_week'] ?? '—') }}</span><span class="ml-1 text-gray-500">{{ isset($row['hour']) ? str_pad((string)$row['hour'],2,'0',STR_PAD_LEFT).':00' : '—' }}</span></div><span class="text-right text-gray-500">{{ $fmtNumber($row['conversions'] ?? 0, 2) }} conv.</span><span class="min-w-[88px] text-right font-semibold text-emerald-700 dark:text-emerald-300">CPA {{ $fmtMoney($row['cpa'] ?? null) }}</span></div>
                    @empty <div class="rounded-lg bg-gray-50 p-4 text-xs text-gray-500 dark:bg-white/[0.02]">{{ $isTr ? 'Dönüşüm üreten saat penceresi bulunamadı.' : 'No converting time window found.' }}</div> @endforelse
                </div>
            </div>

            <div>
                <div class="mb-3"><h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'İncelenecek saatler' : 'Hours to review' }}</h4><p class="mt-0.5 text-xs text-gray-500">{{ $isTr ? 'Harcama yapmış fakat provider dönüşümü üretmemiş en pahalı pencereler. Otomatik kapatma önerisi değildir.' : 'Highest-spend windows with no provider conversions. This is not an automatic pause recommendation.' }}</p></div>
                <div class="space-y-2">
                    @forelse ($wasteHours as $row)
                        @php $dayKey = strtoupper((string)($row['day_of_week'] ?? '')); @endphp
                        <div class="grid grid-cols-[1fr_auto_auto] items-center gap-3 rounded-lg border border-amber-100 bg-amber-50/40 px-3 py-2.5 text-xs dark:border-amber-500/20 dark:bg-amber-500/5"><div><span class="font-semibold text-gray-800 dark:text-gray-200">{{ $dayLabels[$dayKey] ?? ($row['day_of_week'] ?? '—') }}</span><span class="ml-1 text-gray-500">{{ isset($row['hour']) ? str_pad((string)$row['hour'],2,'0',STR_PAD_LEFT).':00' : '—' }}</span></div><span class="text-right text-gray-500">CTR {{ $fmtPct($row['ctr'] ?? null) }}</span><span class="min-w-[88px] text-right font-semibold text-amber-700 dark:text-amber-300">{{ $fmtMoney($row['cost'] ?? null) }}</span></div>
                    @empty <div class="rounded-lg bg-gray-50 p-4 text-xs text-gray-500 dark:bg-white/[0.02]">{{ $isTr ? 'Dönüşümsüz yüksek harcama penceresi bulunamadı.' : 'No high-spend non-converting window found.' }}</div> @endforelse
                </div>
            </div>
        </div>
    </section>

    <div class="grid gap-4 xl:grid-cols-2">
        <section class="rounded-2xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex items-start justify-between gap-3"><div><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Lokasyon performansı' : 'Location performance' }}</h3><p class="mt-1 text-xs leading-5 text-gray-500">{{ $isTr ? 'Fiziksel kullanıcı lokasyonu Google Ads user_location_view semantiğine dayanır.' : 'Physical user location follows Google Ads user_location_view semantics.' }}</p></div><span class="rounded-full bg-gray-100 px-2 py-1 text-[10px] font-semibold text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ $locationRows->count() }} {{ $isTr ? 'segment' : 'segments' }}</span></div>

            @if ($locationRows->isEmpty())
                <div class="mt-4 rounded-xl bg-gray-50 p-5 text-sm text-gray-500 dark:bg-white/[0.02]">{{ $isTr ? 'Bu dönem için lokasyon kırılımı yok.' : 'No location breakdown for this period.' }}</div>
            @elseif (! $hasLocationLabels)
                <div class="mt-4 rounded-xl bg-amber-50 p-4 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/20"><p class="text-sm font-semibold text-amber-900 dark:text-amber-100">{{ $isTr ? 'Lokasyon verisi var, isim çözümlemesi eksik' : 'Location facts exist, labels are unresolved' }}</p><p class="mt-1 text-xs leading-5 text-amber-800 dark:text-amber-200/90">{{ $isTr ? 'Criterion ID’leri müşteriye veya pazarlamacıya anlamlı lokasyon adıymış gibi göstermiyoruz. Geo target metadata çözümlenene kadar bu veri karar üretmek için kullanılmayacak.' : 'Criterion IDs are not presented as if they were meaningful place names. This section stays non-actionable until geo target metadata is resolved.' }}</p><div class="mt-3 flex items-center justify-between rounded-lg bg-white/60 px-3 py-2 text-xs dark:bg-black/10"><span>{{ $isTr ? 'Bu kırılımda gözlenen harcama' : 'Observed spend in this breakdown' }}</span><strong>{{ $fmtMoney($locationSpend) }}</strong></div></div>
            @else
                <div class="mt-4 overflow-x-auto"><table class="min-w-full text-sm"><thead class="text-xs text-gray-500"><tr><th class="py-2 text-left">{{ $isTr ? 'Lokasyon' : 'Location' }}</th><th class="py-2 text-right">{{ $isTr ? 'Harcama' : 'Spend' }}</th><th class="py-2 text-right">{{ $isTr ? 'Dönüşüm' : 'Conversions' }}</th><th class="py-2 text-right">CPA</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">@foreach($locationRows->take(12) as $row)<tr><td class="py-2.5 font-medium text-gray-800 dark:text-gray-200">{{ $row['name'] ?? $row['location_name'] ?? $row['country_name'] ?? '—' }}</td><td class="py-2.5 text-right">{{ $fmtMoney($row['cost'] ?? null) }}</td><td class="py-2.5 text-right">{{ $fmtNumber($row['conversions'] ?? 0,2) }}</td><td class="py-2.5 text-right">{{ $fmtMoney($row['cpa'] ?? null) }}</td></tr>@endforeach</tbody></table></div>
            @endif
        </section>

        <section class="rounded-2xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex items-start justify-between gap-3"><div><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Kitle & demografi' : 'Audience & demographics' }}</h3><p class="mt-1 text-xs leading-5 text-gray-500">{{ $isTr ? 'Sadece yorumlanabilir provider etiketleri varsa performans kararı üretir.' : 'Produces performance decisions only when provider labels are interpretable.' }}</p></div></div>

            @if (($ageRows->isEmpty() && $genderRows->isEmpty() && $campaignAudienceRows->isEmpty() && $adGroupAudienceRows->isEmpty()))
                <div class="mt-4 rounded-xl bg-gray-50 p-5 text-sm text-gray-500 dark:bg-white/[0.02]">{{ $isTr ? 'Bu dönem için kullanılabilir demografi/kitle kırılımı yok.' : 'No usable demographic or audience breakdown for this period.' }}</div>
            @elseif (! $hasAgeLabels && ! $hasGenderLabels)
                <div class="mt-4 rounded-xl bg-blue-50 p-4 ring-1 ring-inset ring-blue-200 dark:bg-blue-500/10 dark:ring-blue-500/20"><p class="text-sm font-semibold text-blue-900 dark:text-blue-100">{{ $isTr ? 'Provider kriterleri toplandı; insan-okur etiketler henüz çözülmedi' : 'Provider criteria collected; human-readable labels are unresolved' }}</p><p class="mt-1 text-xs leading-5 text-blue-800 dark:text-blue-200/90">{{ $isTr ? '“7 yaş kriteri / 3 cinsiyet kriteri” gibi sayılar performans içgörüsü değildir. Bu nedenle eski sayaçları kaldırdım. Etiket çözümlemesi tamamlanana kadar bu alan veri kalite durumu gösterir.' : 'Counts such as “7 age criteria / 3 gender criteria” are not performance insights. The old counters are removed; this section reports data quality until labels resolve.' }}</p><div class="mt-3 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4"><div class="rounded-lg bg-white/60 p-2.5 dark:bg-black/10"><span class="text-gray-500">Age</span><strong class="ml-2">{{ $ageRows->count() }}</strong></div><div class="rounded-lg bg-white/60 p-2.5 dark:bg-black/10"><span class="text-gray-500">Gender</span><strong class="ml-2">{{ $genderRows->count() }}</strong></div><div class="rounded-lg bg-white/60 p-2.5 dark:bg-black/10"><span class="text-gray-500">Campaign audience</span><strong class="ml-2">{{ $campaignAudienceRows->count() }}</strong></div><div class="rounded-lg bg-white/60 p-2.5 dark:bg-black/10"><span class="text-gray-500">Ad group audience</span><strong class="ml-2">{{ $adGroupAudienceRows->count() }}</strong></div></div></div>
            @else
                <div class="mt-4 grid gap-3 sm:grid-cols-2"><div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.02]"><p class="text-xs text-gray-400">{{ $isTr ? 'Yaş segmenti' : 'Age segments' }}</p><p class="mt-1 text-xl font-semibold">{{ $ageRows->count() }}</p></div><div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.02]"><p class="text-xs text-gray-400">{{ $isTr ? 'Cinsiyet segmenti' : 'Gender segments' }}</p><p class="mt-1 text-xl font-semibold">{{ $genderRows->count() }}</p></div></div>
            @endif
        </section>
    </div>

    <section class="rounded-2xl border border-dashed border-gray-300 bg-gray-50/70 p-4 dark:border-gray-700 dark:bg-white/[0.02]">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"><div><p class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $isTr ? 'Performans sayfasının kuralı' : 'Performance page rule' }}</p><p class="mt-1 max-w-4xl text-xs leading-5 text-gray-500">{{ $isTr ? 'Bu sayfa Google Ads verisini tekrar eden tablolar halinde sergilemek için değil, segmentler arasındaki farkı bulmak için var. Ham entity yönetimi Kampanyalar’da, sorgu analizi Arama’da, bütçe kararları Bütçe & Teklif’te kalır.' : 'This page exists to find differences between segments, not to repeat Google Ads tables. Entity management stays in Campaigns, query analysis in Search, and budget decisions in Budget & Bidding.' }}</p></div><button type="button" wire:click="setTab('data_connection')" class="shrink-0 rounded-lg px-3 py-2 text-xs font-semibold text-brand-700 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:text-brand-300 dark:ring-brand-500/30">{{ $isTr ? 'Veri kalitesini aç' : 'Open data quality' }}</button></div>
    </section>
</div>
