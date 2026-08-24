@php
    $isTr = app()->getLocale() === 'tr';
    $currency = $professional['currency'] ?? '';
    $strategies = collect(data_get($professional, 'budget_bidding.strategies', []));
    $campaigns = collect($campaignRows ?? []);
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Bütçe & teklif stratejileri' : 'Budget & bidding strategies' }}</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Bütçenin dağılımını, kayıp gösterim payını ve Google Ads teklif stratejilerini tek alanda değerlendirin.' : 'Review budget allocation, lost impression share and Google Ads bidding strategies in one place.' }}</p>
    </div>

    <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800"><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Kampanya bütçe görünümü' : 'Campaign budget view' }}</h3></div>
        <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/[0.02]"><tr><th class="px-4 py-2 text-left">{{ $isTr ? 'Kampanya' : 'Campaign' }}</th><th class="px-3 py-2 text-right">{{ $isTr ? 'Harcama' : 'Spend' }}</th><th class="px-3 py-2 text-right">Conv.</th><th class="px-3 py-2 text-right">Provider CPA</th><th class="px-3 py-2 text-right">{{ $isTr ? 'Gösterim payı' : 'Impr. share' }}</th><th class="px-3 py-2 text-right">{{ $isTr ? 'Bütçe kaybı' : 'Lost to budget' }}</th><th class="px-3 py-2 text-right">{{ $isTr ? 'Sıralama kaybı' : 'Lost to rank' }}</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($campaigns as $row)
                @php
                    $providerConversions = is_numeric($row['leads'] ?? null) ? (float) $row['leads'] : null;
                    $providerCpa = $providerConversions && $providerConversions > 0 && is_numeric($row['spend'] ?? null) ? (float) $row['spend'] / $providerConversions : null;
                @endphp
                <tr>
                    <td class="px-4 py-2 font-medium text-gray-800 dark:text-gray-200">{{ $row['name'] ?? '—' }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ is_numeric($row['spend'] ?? null) ? number_format((float)$row['spend'],2,',','.').' '.$currency : '—' }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ $providerConversions !== null ? number_format($providerConversions,2,',','.') : '—' }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ $providerCpa !== null ? number_format($providerCpa,2,',','.').' '.$currency : '—' }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ is_numeric($row['impr_share'] ?? null) ? number_format((float)$row['impr_share'],1,',','.').'%' : '—' }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ is_numeric($row['lost_is_budget'] ?? null) ? number_format((float)$row['lost_is_budget'],1,',','.').'%' : '—' }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ is_numeric($row['lost_is_rank'] ?? null) ? number_format((float)$row['lost_is_rank'],1,',','.').'%' : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">{{ $isTr ? 'Kampanya performans verisi yok.' : 'No campaign performance data.' }}</td></tr>
            @endforelse
        </tbody></table></div>
    </section>

    <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800"><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Portfolio teklif stratejileri' : 'Portfolio bidding strategies' }}</h3><p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Google Ads sağlayıcısından çekilen güncel strateji snapshotı.' : 'Current strategy snapshot from Google Ads.' }}</p></div>
        <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/[0.02]"><tr><th class="px-4 py-2 text-left">{{ $isTr ? 'Strateji' : 'Strategy' }}</th><th class="px-3 py-2 text-left">{{ $isTr ? 'Tür' : 'Type' }}</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-right">{{ $isTr ? 'Kampanya' : 'Campaigns' }}</th><th class="px-4 py-2 text-left">{{ $isTr ? 'Hedefler' : 'Targets' }}</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($strategies as $row)
                @php $m = is_array($row['metadata'] ?? null) ? $row['metadata'] : []; @endphp
                <tr><td class="px-4 py-2 font-medium text-gray-800 dark:text-gray-200">{{ $row['name'] ?? $row['bidding_strategy_id'] ?? '—' }}</td><td class="px-3 py-2">{{ $row['strategy_type'] ?? '—' }}</td><td class="px-3 py-2">{{ $row['status'] ?? '—' }}</td><td class="px-3 py-2 text-right">{{ $row['campaign_count'] ?? '—' }}</td><td class="px-4 py-2 text-xs text-gray-500">@if($m){{ collect($m)->except(['provider','api_version','collector_layer','provider_fact','derived_rates_stored'])->map(fn($v,$k) => $k.': '.(is_scalar($v)?$v:json_encode($v)))->take(3)->implode(' · ') }}@else—@endif</td></tr>
            @empty <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">{{ $isTr ? 'Portfolio teklif stratejisi verisi yok.' : 'No portfolio bidding strategy data.' }}</td></tr> @endforelse
        </tbody></table></div>
    </section>

    <div class="rounded-xl bg-blue-50 px-4 py-3 text-sm text-blue-800 ring-1 ring-inset ring-blue-100 dark:bg-blue-500/10 dark:text-blue-200 dark:ring-blue-500/20">
        {{ $isTr ? 'Not: Buradaki CPA, Google Ads provider conversion sayısını paydaya alır; MOXDOP business outcome veya qualified lead değildir. Ajans plan bütçesi kanonik olarak tanımlı değilse pacing hedefi de uydurulmaz.' : 'Note: CPA here uses Google Ads provider conversions as the denominator; it is not a MOXDOP business outcome or qualified lead. Pacing is not fabricated without a canonical agency budget plan.' }}
    </div>
</div>
