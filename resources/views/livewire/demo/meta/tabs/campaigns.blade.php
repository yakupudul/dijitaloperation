@php
    $isTr = app()->getLocale() === 'tr';
    $campaigns = $professional['campaigns'] ?? [];
    $adsets = $professional['adsets'] ?? [];
    $ads = $professional['ads'] ?? [];
@endphp

<section class="space-y-5" x-data="{ level: 'campaigns' }">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">{{ $isTr ? 'Kampanya Hiyerarşisi' : 'Campaign Hierarchy' }}</p>
            <h2 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Nerede para harcanıyor, nerede etkileşim geliyor?' : 'Where is money spent and engagement generated?' }}</h2>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Campaign, Ad Set ve Ad performansı aynı hesap ve seçili tarih aralığında gerçek Data Pool verisinden okunur.' : 'Campaign, Ad Set and Ad performance are read from real Data Pool data for the same account and selected date range.' }}</p>
        </div>
        <div class="inline-flex w-fit rounded-xl bg-gray-100 p-1 dark:bg-white/[0.05]">
            <button type="button" @click="level='campaigns'" :class="level==='campaigns' ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-white' : 'text-gray-500 dark:text-gray-400'" class="rounded-lg px-3 py-2 text-sm font-semibold">{{ $isTr ? 'Kampanyalar' : 'Campaigns' }} <span class="ml-1 text-xs opacity-60">{{ count($campaigns) }}</span></button>
            <button type="button" @click="level='adsets'" :class="level==='adsets' ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-white' : 'text-gray-500 dark:text-gray-400'" class="rounded-lg px-3 py-2 text-sm font-semibold">{{ $isTr ? 'Reklam Setleri' : 'Ad Sets' }} <span class="ml-1 text-xs opacity-60">{{ count($adsets) }}</span></button>
            <button type="button" @click="level='ads'" :class="level==='ads' ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-white' : 'text-gray-500 dark:text-gray-400'" class="rounded-lg px-3 py-2 text-sm font-semibold">{{ $isTr ? 'Reklamlar' : 'Ads' }} <span class="ml-1 text-xs opacity-60">{{ count($ads) }}</span></button>
        </div>
    </div>

    <article class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="flex flex-col gap-2 border-b border-gray-100 px-5 py-4 dark:border-gray-800 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 class="font-bold text-gray-900 dark:text-white" x-text="level === 'campaigns' ? '{{ $isTr ? 'Kampanyalar' : 'Campaigns' }}' : (level === 'adsets' ? '{{ $isTr ? 'Reklam Setleri' : 'Ad Sets' }}' : '{{ $isTr ? 'Reklamlar' : 'Ads' }}')"></h3>
                <p class="mt-0.5 text-xs text-gray-400">{{ $isTr ? 'Results / CPA yerine provider tarafından gerçekten ölçülen performans metrikleri gösterilir.' : 'Provider-measured performance metrics are shown instead of unverified Results / CPA.' }}</p>
            </div>
            <span class="text-xs font-medium text-gray-400">{{ $professional['period_start'] ?? '—' }} → {{ $professional['period_end'] ?? '—' }}</span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-left dark:divide-gray-800">
                <thead class="bg-gray-50/80 dark:bg-white/[0.02]">
                    <tr class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">
                        <th class="px-5 py-3">{{ $isTr ? 'Ad' : 'Name' }}</th>
                        <th class="px-4 py-3">{{ $isTr ? 'Bağlam' : 'Context' }}</th>
                        <th class="px-4 py-3">{{ $isTr ? 'Durum' : 'Status' }}</th>
                        <th class="px-4 py-3 text-right">{{ $isTr ? 'Harcama' : 'Spend' }}</th>
                        <th class="px-4 py-3 text-right">{{ $isTr ? 'Gösterim' : 'Impressions' }}</th>
                        <th class="px-4 py-3 text-right">{{ $isTr ? 'Tıklama' : 'Clicks' }}</th>
                        <th class="px-4 py-3 text-right">CTR</th>
                        <th class="px-4 py-3 text-right">CPC</th>
                        <th class="px-5 py-3 text-right">CPM</th>
                    </tr>
                </thead>

                <tbody x-show="level === 'campaigns'" class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($campaigns as $row)
                        <tr class="hover:bg-gray-50/70 dark:hover:bg-white/[0.02]">
                            <td class="max-w-xs px-5 py-3.5"><p class="truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $row['name'] }}</p><p class="mt-0.5 text-[11px] text-gray-400">ID {{ $row['id'] }}</p></td>
                            <td class="px-4 py-3.5 text-xs text-gray-500 dark:text-gray-400">{{ $row['objective'] ? str_replace('_', ' ', $row['objective']) : '—' }}</td>
                            <td class="px-4 py-3.5"><span class="rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-600 dark:bg-white/[0.05] dark:text-gray-300">{{ $row['effective_status'] ?? $row['status'] }}</span></td>
                            <td class="px-4 py-3.5 text-right text-sm font-semibold tabular-nums text-gray-800 dark:text-gray-200">{{ $row['spend_display'] }}</td>
                            <td class="px-4 py-3.5 text-right text-sm tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($row['impressions']) }}</td>
                            <td class="px-4 py-3.5 text-right text-sm tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($row['clicks']) }}</td>
                            <td class="px-4 py-3.5 text-right text-sm tabular-nums">{{ $row['ctr'] !== null ? number_format($row['ctr'], 2).'%' : '—' }}</td>
                            <td class="px-4 py-3.5 text-right text-sm tabular-nums">{{ $row['cpc'] !== null ? ($row['currency'].' '.number_format($row['cpc'], 2)) : '—' }}</td>
                            <td class="px-5 py-3.5 text-right text-sm tabular-nums">{{ $row['cpm'] !== null ? ($row['currency'].' '.number_format($row['cpm'], 2)) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-5 py-12 text-center text-sm text-gray-400">{{ $isTr ? 'Seçili dönem için kampanya günlük performans verisi yok.' : 'No campaign daily performance data for the selected period.' }}</td></tr>
                    @endforelse
                </tbody>

                <tbody x-show="level === 'adsets'" x-cloak class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($adsets as $row)
                        <tr class="hover:bg-gray-50/70 dark:hover:bg-white/[0.02]">
                            <td class="max-w-xs px-5 py-3.5"><p class="truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $row['name'] }}</p><p class="mt-0.5 text-[11px] text-gray-400">ID {{ $row['id'] }}</p></td>
                            <td class="max-w-xs px-4 py-3.5"><p class="truncate text-xs font-medium text-gray-600 dark:text-gray-300">{{ $row['campaign_name'] ?? '—' }}</p><p class="mt-0.5 text-[11px] text-gray-400">{{ $row['optimization_goal'] ? str_replace('_', ' ', $row['optimization_goal']) : ($row['destination_type'] ? str_replace('_', ' ', $row['destination_type']) : '—') }}</p></td>
                            <td class="px-4 py-3.5"><span class="rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-600 dark:bg-white/[0.05] dark:text-gray-300">{{ $row['effective_status'] ?? $row['status'] }}</span></td>
                            <td class="px-4 py-3.5 text-right text-sm font-semibold tabular-nums">{{ $row['spend_display'] }}</td>
                            <td class="px-4 py-3.5 text-right text-sm tabular-nums">{{ number_format($row['impressions']) }}</td>
                            <td class="px-4 py-3.5 text-right text-sm tabular-nums">{{ number_format($row['clicks']) }}</td>
                            <td class="px-4 py-3.5 text-right text-sm tabular-nums">{{ $row['ctr'] !== null ? number_format($row['ctr'], 2).'%' : '—' }}</td>
                            <td class="px-4 py-3.5 text-right text-sm tabular-nums">{{ $row['cpc'] !== null ? ($row['currency'].' '.number_format($row['cpc'], 2)) : '—' }}</td>
                            <td class="px-5 py-3.5 text-right text-sm tabular-nums">{{ $row['cpm'] !== null ? ($row['currency'].' '.number_format($row['cpm'], 2)) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-5 py-12 text-center text-sm text-gray-400">{{ $isTr ? 'Seçili dönem için reklam seti performans verisi yok.' : 'No ad-set performance data for the selected period.' }}</td></tr>
                    @endforelse
                </tbody>

                <tbody x-show="level === 'ads'" x-cloak class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($ads as $row)
                        <tr class="hover:bg-gray-50/70 dark:hover:bg-white/[0.02]">
                            <td class="max-w-xs px-5 py-3.5"><p class="truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $row['name'] }}</p><p class="mt-0.5 text-[11px] text-gray-400">ID {{ $row['id'] }}{{ $row['creative_id'] ? ' · Creative '.$row['creative_id'] : '' }}</p></td>
                            <td class="max-w-xs px-4 py-3.5"><p class="truncate text-xs font-medium text-gray-600 dark:text-gray-300">{{ $row['campaign_name'] ?? '—' }}</p><p class="mt-0.5 truncate text-[11px] text-gray-400">{{ $row['adset_name'] ?? '—' }}</p></td>
                            <td class="px-4 py-3.5"><span class="rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-600 dark:bg-white/[0.05] dark:text-gray-300">{{ $row['effective_status'] ?? $row['status'] }}</span></td>
                            <td class="px-4 py-3.5 text-right text-sm font-semibold tabular-nums">{{ $row['spend_display'] }}</td>
                            <td class="px-4 py-3.5 text-right text-sm tabular-nums">{{ number_format($row['impressions']) }}</td>
                            <td class="px-4 py-3.5 text-right text-sm tabular-nums">{{ number_format($row['clicks']) }}</td>
                            <td class="px-4 py-3.5 text-right text-sm tabular-nums">{{ $row['ctr'] !== null ? number_format($row['ctr'], 2).'%' : '—' }}</td>
                            <td class="px-4 py-3.5 text-right text-sm tabular-nums">{{ $row['cpc'] !== null ? ($row['currency'].' '.number_format($row['cpc'], 2)) : '—' }}</td>
                            <td class="px-5 py-3.5 text-right text-sm tabular-nums">{{ $row['cpm'] !== null ? ($row['currency'].' '.number_format($row['cpm'], 2)) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-5 py-12 text-center text-sm text-gray-400">{{ $isTr ? 'Seçili dönem için reklam performansı / snapshot verisi yok.' : 'No ad performance / snapshot data for the selected period.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>

    <div class="rounded-xl border border-blue-200 bg-blue-50/60 px-4 py-3 text-xs leading-5 text-blue-800 dark:border-blue-500/20 dark:bg-blue-500/[0.06] dark:text-blue-300">
        {{ $isTr ? 'Reach ve Frequency dönem toplamında gösterilmez; Meta reach de-duplicate edilir ve frequency non-additive bir metriktir. Link Clicks ile tüm Clicks de birbirinden ayrı tutulur.' : 'Period Reach and Frequency are not shown; Meta reach is de-duplicated and frequency is non-additive. Link Clicks and all Clicks are also kept distinct.' }}
    </div>
</section>
