@php
    $isTr = app()->getLocale() === 'tr';
    $kpis = $professional['kpis'] ?? [];
    $campaigns = array_slice($professional['campaigns'] ?? [], 0, 10);
    $destinations = $data['funnel']['destinations'] ?? [];
    $hourly = collect($professional['hourly'] ?? [])->sortByDesc('spend')->take(12)->values()->all();
@endphp

<section class="space-y-5">
    <div>
        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">{{ $isTr ? 'Performans' : 'Performance' }}</p>
        <h2 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Hesap performansını tek yerde oku' : 'Read account performance in one place' }}</h2>
        <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Günlük delivery trendi, verimlilik metrikleri, destination dağılımı ve saatlik yoğunluk gerçek Meta Ads Data Pool verisinden okunur.' : 'Daily delivery trend, efficiency metrics, destination distribution and hourly concentration are read from real Meta Ads Data Pool data.' }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['key' => 'ctr', 'label' => 'CTR'],
            ['key' => 'cpc', 'label' => 'CPC'],
            ['key' => 'cpm', 'label' => 'CPM'],
            ['key' => 'link_clicks', 'label' => $isTr ? 'Bağlantı Tıklamaları' : 'Link Clicks'],
        ] as $card)
            @php $metric = $kpis[$card['key']] ?? []; @endphp
            <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
                <p class="mt-3 text-3xl font-bold tracking-tight text-gray-900 dark:text-white">{{ $metric['display'] ?? '—' }}</p>
                @if (($metric['delta_pct'] ?? null) !== null)
                    <p class="mt-2 text-xs font-semibold {{ $metric['delta_pct'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">{{ $metric['delta_pct'] > 0 ? '+' : '' }}{{ number_format($metric['delta_pct'], 1) }}% {{ $isTr ? 'önceki döneme göre' : 'vs previous period' }}</p>
                @else
                    <p class="mt-2 text-xs text-gray-400">{{ $isTr ? 'Karşılaştırma mevcut değil' : 'Comparison unavailable' }}</p>
                @endif
            </article>
        @endforeach
    </div>

    @if (! empty($professional['trend']))
        <x-ta.chart-card
            :title="$isTr ? 'Günlük Performans Eğilimi' : 'Daily Performance Trend'"
            :subtitle="$isTr ? 'Harcama ve tıklamalar · seçili reklam hesabı' : 'Spend and clicks · selected ad account'"
            :options="$performanceChartOptions"
            chart-id="meta-professional-performance-trend"
        />
    @endif

    <div class="grid gap-5 xl:grid-cols-2">
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-6">
            <h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Destination Dağılımı' : 'Destination Distribution' }}</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Ad Set destination_type bazında gözlenen harcama dağılımı.' : 'Observed spend distribution by Ad Set destination_type.' }}</p>
            <div class="mt-5 space-y-4">
                @forelse ($destinations as $row)
                    <div>
                        <div class="flex items-center justify-between gap-4 text-sm"><span class="font-medium text-gray-700 dark:text-gray-300">{{ $row['label'] ?? $row['destination'] ?? '—' }}</span><span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $professional['currency'] ?? '' }} {{ number_format((float) ($row['spend'] ?? 0), 2) }} · {{ number_format((float) ($row['share'] ?? 0), 0) }}%</span></div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/[0.05]"><div class="h-full rounded-full bg-brand-500" style="width: {{ min(100, max(0, (float) ($row['share'] ?? 0))) }}%"></div></div>
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-gray-300 px-5 py-10 text-center text-sm text-gray-400 dark:border-gray-700">{{ $isTr ? 'Destination type verisi yok.' : 'No destination type data.' }}</div>
                @endforelse
            </div>
            <p class="mt-4 text-[11px] leading-5 text-gray-400">{{ $isTr ? 'Destination bazında typed action eşlemesi olmadığı için sonuç/CPA bu dağılımda gösterilmez.' : 'Results/CPA are not shown here because typed actions are not mapped by destination.' }}</p>
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-6">
            <h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Saatlik Delivery Yoğunluğu' : 'Hourly Delivery Concentration' }}</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Reklam hesabı saat dilimine göre en yüksek harcama görülen saat aralıkları.' : 'Hour buckets with the highest observed spend in the ad account timezone.' }}</p>
            <div class="mt-5 space-y-2">
                @forelse ($hourly as $row)
                    <div class="grid grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-4 rounded-xl border border-gray-100 px-3 py-2.5 text-sm dark:border-gray-800">
                        <span class="truncate font-medium text-gray-700 dark:text-gray-300">{{ $row['hour'] }}</span>
                        <span class="tabular-nums text-gray-500">CTR {{ $row['ctr'] !== null ? number_format($row['ctr'], 2).'%' : '—' }}</span>
                        <span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $professional['currency'] ?? '' }} {{ number_format($row['spend'], 2) }}</span>
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-gray-300 px-5 py-10 text-center text-sm text-gray-400 dark:border-gray-700">{{ $isTr ? 'Hourly dataset kullanıma hazır değil veya seçili dönem dışında.' : 'Hourly dataset is not ready or outside the selected period.' }}</div>
                @endforelse
            </div>
        </article>
    </div>

    <article class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Kampanya Performansı' : 'Campaign Performance' }}</h3><p class="mt-1 text-xs text-gray-400">{{ $isTr ? 'Harcama, delivery ve tıklama verimliliği.' : 'Spend, delivery and click efficiency.' }}</p></div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-left">
                <thead class="bg-gray-50/80 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:bg-white/[0.02]"><tr><th class="px-5 py-3">{{ $isTr ? 'Kampanya' : 'Campaign' }}</th><th class="px-4 py-3 text-right">{{ $isTr ? 'Harcama' : 'Spend' }}</th><th class="px-4 py-3 text-right">{{ $isTr ? 'Gösterim' : 'Impressions' }}</th><th class="px-4 py-3 text-right">{{ $isTr ? 'Tıklama' : 'Clicks' }}</th><th class="px-4 py-3 text-right">CTR</th><th class="px-4 py-3 text-right">CPC</th><th class="px-5 py-3 text-right">CPM</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($campaigns as $row)
                        <tr><td class="max-w-sm px-5 py-3.5"><p class="truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $row['name'] }}</p><p class="text-[11px] text-gray-400">{{ $row['objective'] ? str_replace('_', ' ', $row['objective']) : $row['status'] }}</p></td><td class="px-4 py-3.5 text-right text-sm font-semibold tabular-nums">{{ $row['spend_display'] }}</td><td class="px-4 py-3.5 text-right text-sm tabular-nums">{{ number_format($row['impressions']) }}</td><td class="px-4 py-3.5 text-right text-sm tabular-nums">{{ number_format($row['clicks']) }}</td><td class="px-4 py-3.5 text-right text-sm tabular-nums">{{ $row['ctr'] !== null ? number_format($row['ctr'], 2).'%' : '—' }}</td><td class="px-4 py-3.5 text-right text-sm tabular-nums">{{ $row['cpc'] !== null ? ($row['currency'].' '.number_format($row['cpc'], 2)) : '—' }}</td><td class="px-5 py-3.5 text-right text-sm tabular-nums">{{ $row['cpm'] !== null ? ($row['currency'].' '.number_format($row['cpm'], 2)) : '—' }}</td></tr>
                    @empty
                        <tr><td colspan="7" class="px-5 py-10 text-center text-sm text-gray-400">{{ $isTr ? 'Kampanya verisi yok.' : 'No campaign data.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>
</section>
