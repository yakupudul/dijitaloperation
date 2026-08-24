@php
    $isTr = app()->getLocale() === 'tr';
    $currency = (string) (data_get($identity, 'currency') ?: ($professional['currency'] ?? ''));
    $money = fn ($v) => is_numeric($v) ? trim(number_format((float)$v, 2, ',', '.').' '.$currency) : '—';
    $pct = fn ($v) => is_numeric($v) ? number_format((float)$v, 1, ',', '.').'%' : '—';
    $number = fn ($v, int $d = 0) => is_numeric($v) ? number_format((float)$v, $d, ',', '.') : '—';
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Kampanyalar' : 'Campaigns' }}</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Kampanya bütçesi, harcama, Google Ads dönüşümleri, impression share ve teklif stratejisini tek görünümde inceleyin.' : 'Review campaign budget, spend, Google Ads conversions, impression share and bidding context in one view.' }}</p>
    </div>

    <div class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-400 dark:bg-white/[0.02]"><tr>
                    <th class="px-4 py-2.5 text-left">{{ $isTr ? 'Kampanya' : 'Campaign' }}</th>
                    <th class="px-3 py-2.5 text-left">{{ $isTr ? 'Durum' : 'Status' }}</th>
                    <th class="px-3 py-2.5 text-right">{{ $isTr ? 'Bütçe' : 'Budget' }}</th>
                    <th class="px-3 py-2.5 text-right">{{ $isTr ? 'Harcama' : 'Spend' }}</th>
                    <th class="px-3 py-2.5 text-right">{{ $isTr ? 'Dönüşüm' : 'Conversions' }}</th>
                    <th class="px-3 py-2.5 text-right">CPA</th>
                    <th class="px-3 py-2.5 text-right">Search IS</th>
                    <th class="px-3 py-2.5 text-right">{{ $isTr ? 'Kayıp IS · bütçe' : 'Lost IS · budget' }}</th>
                    <th class="px-3 py-2.5 text-right">{{ $isTr ? 'Kayıp IS · sıralama' : 'Lost IS · rank' }}</th>
                    <th class="px-4 py-2.5"></th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($campaignRows as $c)
                        @php
                            $cpa = is_numeric($c['spend'] ?? null) && is_numeric($c['leads'] ?? null) && (float)$c['leads'] > 0 ? (float)$c['spend']/(float)$c['leads'] : null;
                        @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                            <td class="px-4 py-2.5"><p class="font-medium text-gray-900 dark:text-white">{{ $c['name'] }}</p><p class="mt-0.5 text-[11px] text-gray-400">{{ $c['type'] ?? '—' }} @if(!empty($c['bidding_strategy_type'])) · {{ $c['bidding_strategy_type'] }} @endif</p></td>
                            <td class="px-3 py-2.5"><x-ta.badge :color="strtoupper((string)($c['status'] ?? '')) === 'ENABLED' ? 'success' : 'light'" size="sm">{{ $c['status'] ?? '—' }}</x-ta.badge></td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ $money($c['budget'] ?? null) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ $money($c['spend'] ?? null) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ $number($c['leads'] ?? null, 2) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ $cpa !== null ? $money($cpa) : '—' }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ $pct($c['impr_share'] ?? null) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ $pct($c['lost_is_budget'] ?? null) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ $pct($c['lost_is_rank'] ?? null) }}</td>
                            <td class="px-4 py-2.5 text-right"><button type="button" wire:click="openCampaign('{{ $c['id'] }}')" class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ $isTr ? 'Detay' : 'Open' }}</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="px-4 py-10 text-center text-gray-400">{{ $isTr ? 'Seçili dönem için kullanılabilir kampanya performansı yok.' : 'No usable campaign performance for the selected period.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="rounded-xl bg-blue-50 px-4 py-3 text-xs text-blue-800 ring-1 ring-inset ring-blue-100 dark:bg-blue-500/10 dark:text-blue-200 dark:ring-blue-500/20">
        {{ $isTr ? 'Dönüşüm sütunu Google Ads sağlayıcı dönüşüm metriğidir. MOXDOP Business Action eşlemesi yapılmadan “lead” veya “satış” olarak adlandırılmaz. Impression Share metrikleri yalnız Google’ın sağladığı kampanya türlerinde görünür.' : 'Conversions are Google Ads provider conversions. They are not labeled as leads or sales until a MOXDOP Business Action mapping exists. Impression Share appears only where Google provides it.' }}
    </div>
</div>

@if ($selectedCampaign)
    @php
        $drawerCpa = is_numeric($selectedCampaign['spend'] ?? null) && is_numeric($selectedCampaign['leads'] ?? null) && (float)$selectedCampaign['leads'] > 0 ? (float)$selectedCampaign['spend']/(float)$selectedCampaign['leads'] : null;
    @endphp
    <x-demo.gads-drawer :title="$selectedCampaign['name']" :subtitle="($selectedCampaign['type'] ?? 'Google Ads').' · '.($selectedCampaign['status'] ?? '—')">
        <div class="grid grid-cols-2 gap-3">
            <div><p class="text-xs text-gray-400">{{ $isTr ? 'Harcama' : 'Spend' }}</p><p class="font-semibold tabular-nums">{{ $money($selectedCampaign['spend'] ?? null) }}</p></div>
            <div><p class="text-xs text-gray-400">{{ $isTr ? 'Dönüşüm' : 'Conversions' }}</p><p class="font-semibold tabular-nums">{{ $number($selectedCampaign['leads'] ?? null, 2) }}</p></div>
            <div><p class="text-xs text-gray-400">CPA</p><p class="font-semibold tabular-nums">{{ $drawerCpa !== null ? $money($drawerCpa) : '—' }}</p></div>
            <div><p class="text-xs text-gray-400">{{ $isTr ? 'Günlük bütçe' : 'Daily budget' }}</p><p class="font-semibold tabular-nums">{{ $money($selectedCampaign['budget'] ?? null) }}</p></div>
        </div>

        <div>
            <h3 class="text-xs font-semibold uppercase text-gray-400">{{ $isTr ? 'Auction / görünürlük bağlamı' : 'Auction / visibility context' }}</h3>
            <ul class="mt-2 space-y-1 text-sm">
                <li class="flex justify-between"><span>Search IS</span><span class="tabular-nums">{{ $pct($selectedCampaign['impr_share'] ?? null) }}</span></li>
                <li class="flex justify-between"><span>{{ $isTr ? 'Lost IS · bütçe' : 'Lost IS · budget' }}</span><span class="tabular-nums text-amber-700 dark:text-amber-400">{{ $pct($selectedCampaign['lost_is_budget'] ?? null) }}</span></li>
                <li class="flex justify-between"><span>{{ $isTr ? 'Lost IS · sıralama' : 'Lost IS · rank' }}</span><span class="tabular-nums">{{ $pct($selectedCampaign['lost_is_rank'] ?? null) }}</span></li>
            </ul>
            <p class="mt-2 text-[11px] text-gray-400">{{ $isTr ? 'Bu değerler Google Ads kampanya performans verisidir; Auction Insights rakip domain tablosu değildir.' : 'These are Google Ads campaign performance fields, not an Auction Insights competitor-domain table.' }}</p>
        </div>

        <div>
            <h3 class="text-xs font-semibold uppercase text-gray-400">{{ $isTr ? 'Uzman kırılımları' : 'Specialist breakdowns' }}</h3>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $isTr ? 'Cihaz, lokasyon, gün/saat, ağ, demografi ve kitle analizleri Performans sekmesinde gerçek provider verisinden gösterilir.' : 'Device, location, day/hour, network, demographic and audience analysis lives under Performance and uses real provider data.' }}</p>
            <button type="button" wire:click="setTab('performance')" class="mt-2 text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ $isTr ? 'Performans kırılımlarını aç' : 'Open performance breakdowns' }} →</button>
        </div>
    </x-demo.gads-drawer>
@endif
