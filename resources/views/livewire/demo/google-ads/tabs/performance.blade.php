@php
    $isTr = app()->getLocale() === 'tr';
    $currency = $professional['currency'] ?? '';
    $perf = $professional['performance'] ?? [];
    $fmtMoney = fn ($v) => is_numeric($v) ? number_format((float) $v, 2, ',', '.').' '.$currency : '—';
    $fmtPct = fn ($v) => is_numeric($v) ? number_format((float) $v, 2, ',', '.').'%' : '—';
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Performans kırılımları' : 'Performance breakdowns' }}</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Bütçe nerede çalışıyor, nerede kaybediliyor? Cihaz, ağ, saat, lokasyon, demografi ve kitle düzeyinde provider verisi.' : 'See where budget works or leaks across device, network, time, location, demographics and audiences.' }}</p>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
        @foreach ([
            ['key' => 'device', 'title' => $isTr ? 'Cihaz' : 'Device', 'dim' => 'device'],
            ['key' => 'network', 'title' => $isTr ? 'Reklam ağı' : 'Ad network', 'dim' => 'ad_network_type'],
        ] as $block)
            @php $rows = collect($perf[$block['key']] ?? []); @endphp
            <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800"><h3 class="font-semibold text-gray-900 dark:text-white">{{ $block['title'] }}</h3></div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/[0.02]"><tr><th class="px-4 py-2 text-left">{{ $block['title'] }}</th><th class="px-3 py-2 text-right">{{ $isTr ? 'Harcama' : 'Spend' }}</th><th class="px-3 py-2 text-right">Conv.</th><th class="px-3 py-2 text-right">CPA</th><th class="px-3 py-2 text-right">ROAS</th></tr></thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($rows as $row)
                                <tr><td class="px-4 py-2 font-medium text-gray-800 dark:text-gray-200">{{ $row[$block['dim']] ?? '—' }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $fmtMoney($row['cost'] ?? null) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ number_format((float) ($row['conversions'] ?? 0), 2, ',', '.') }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $fmtMoney($row['cpa'] ?? null) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ is_numeric($row['roas'] ?? null) ? number_format((float) $row['roas'], 2, ',', '.').'x' : '—' }}</td></tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">{{ $isTr ? 'Bu kırılım için kullanılabilir provider verisi yok.' : 'No usable provider data for this breakdown.' }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endforeach
    </div>

    <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800"><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Gün & saat' : 'Day & hour' }}</h3><p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'En yüksek harcamalı saat dilimleri önce gösterilir.' : 'Highest-spend day/hour combinations first.' }}</p></div>
        <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/[0.02]"><tr><th class="px-4 py-2 text-left">{{ $isTr ? 'Gün' : 'Day' }}</th><th class="px-3 py-2 text-left">{{ $isTr ? 'Saat' : 'Hour' }}</th><th class="px-3 py-2 text-right">{{ $isTr ? 'Harcama' : 'Spend' }}</th><th class="px-3 py-2 text-right">CTR</th><th class="px-3 py-2 text-right">CVR</th><th class="px-3 py-2 text-right">CPA</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse (collect($perf['hour'] ?? [])->take(48) as $row)
                <tr><td class="px-4 py-2">{{ $row['day_of_week'] ?? '—' }}</td><td class="px-3 py-2">{{ isset($row['hour']) ? str_pad((string) $row['hour'], 2, '0', STR_PAD_LEFT).':00' : '—' }}</td><td class="px-3 py-2 text-right">{{ $fmtMoney($row['cost'] ?? null) }}</td><td class="px-3 py-2 text-right">{{ $fmtPct($row['ctr'] ?? null) }}</td><td class="px-3 py-2 text-right">{{ $fmtPct($row['cvr'] ?? null) }}</td><td class="px-3 py-2 text-right">{{ $fmtMoney($row['cpa'] ?? null) }}</td></tr>
            @empty <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">{{ $isTr ? 'Saatlik veri yok.' : 'No hourly data.' }}</td></tr> @endforelse
        </tbody></table></div>
    </section>

    <div class="grid gap-4 xl:grid-cols-2">
        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800"><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Kullanıcı lokasyonu' : 'User location' }}</h3><p class="mt-1 text-xs text-gray-500">{{ data_get($perf, 'location_note') }}</p></div>
            <div class="max-h-[420px] overflow-auto"><table class="min-w-full text-sm"><thead class="sticky top-0 bg-gray-50 text-xs text-gray-500 dark:bg-gray-900"><tr><th class="px-4 py-2 text-left">Criterion</th><th class="px-3 py-2 text-right">{{ $isTr ? 'Harcama' : 'Spend' }}</th><th class="px-3 py-2 text-right">Conv.</th><th class="px-3 py-2 text-right">CPA</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">@forelse ($perf['location'] ?? [] as $row)<tr><td class="px-4 py-2">{{ $row['country_criterion_id'] ?? '—' }} @if($row['targeting_location'] ?? false)<span class="ml-1 text-[10px] text-gray-400">targeted</span>@endif</td><td class="px-3 py-2 text-right">{{ $fmtMoney($row['cost'] ?? null) }}</td><td class="px-3 py-2 text-right">{{ number_format((float)($row['conversions'] ?? 0),2,',','.') }}</td><td class="px-3 py-2 text-right">{{ $fmtMoney($row['cpa'] ?? null) }}</td></tr>@empty<tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">{{ $isTr ? 'Lokasyon verisi yok.' : 'No location data.' }}</td></tr>@endforelse</tbody></table></div>
        </section>
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Demografi & kitle' : 'Demographics & audiences' }}</h3>
            <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">{{ data_get($perf, 'demographic_note') }}</p>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.02]"><p class="text-xs text-gray-400">{{ $isTr ? 'Yaş kriterleri' : 'Age criteria' }}</p><p class="mt-1 text-xl font-semibold">{{ count($perf['age'] ?? []) }}</p></div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.02]"><p class="text-xs text-gray-400">{{ $isTr ? 'Cinsiyet kriterleri' : 'Gender criteria' }}</p><p class="mt-1 text-xl font-semibold">{{ count($perf['gender'] ?? []) }}</p></div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.02]"><p class="text-xs text-gray-400">{{ $isTr ? 'Kampanya kitle satırı' : 'Campaign audience rows' }}</p><p class="mt-1 text-xl font-semibold">{{ count($perf['campaign_audience'] ?? []) }}</p></div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.02]"><p class="text-xs text-gray-400">{{ $isTr ? 'Reklam grubu kitle satırı' : 'Ad group audience rows' }}</p><p class="mt-1 text-xl font-semibold">{{ count($perf['ad_group_audience'] ?? []) }}</p></div>
            </div>
        </section>
    </div>
</div>
