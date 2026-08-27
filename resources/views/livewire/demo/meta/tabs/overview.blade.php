@php
    $isTr = app()->getLocale() === 'tr';
    $kpis = $professional['kpis'] ?? [];
    $metricCards = [
        ['key' => 'spend', 'label' => $isTr ? 'Harcama' : 'Spend'],
        ['key' => 'impressions', 'label' => $isTr ? 'Gösterim' : 'Impressions'],
        ['key' => 'clicks', 'label' => $isTr ? 'Tıklamalar' : 'Clicks'],
        ['key' => 'ctr', 'label' => 'CTR'],
    ];
    $secondaryMetrics = [
        ['key' => 'cpc', 'label' => 'CPC'],
        ['key' => 'cpm', 'label' => 'CPM'],
        ['key' => 'link_clicks', 'label' => $isTr ? 'Bağlantı Tıklamaları' : 'Link Clicks'],
        ['key' => 'outbound_clicks', 'label' => $isTr ? 'Giden Tıklamalar' : 'Outbound Clicks'],
    ];
    $topCampaigns = array_slice($professional['campaigns'] ?? [], 0, 5);
    $topCreatives = array_slice($professional['creatives'] ?? [], 0, 4);
    $actions = array_slice($professional['typed_actions'] ?? [], 0, 5);
    $healthIssues = array_slice($professional['health']['issues'] ?? [], 0, 5);
@endphp

<section class="space-y-5">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($metricCards as $card)
            @php $metric = $kpis[$card['key']] ?? []; @endphp
            <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
                <p class="mt-3 text-3xl font-bold tracking-tight text-gray-900 dark:text-white">{{ $metric['display'] ?? '—' }}</p>
                <div class="mt-3 flex items-center justify-between gap-2 text-xs">
                    @if (($metric['delta_pct'] ?? null) !== null)
                        <span class="font-semibold {{ $metric['delta_pct'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                            {{ $metric['delta_pct'] > 0 ? '+' : '' }}{{ number_format($metric['delta_pct'], 1) }}%
                        </span>
                        <span class="text-gray-400">{{ $isTr ? 'önceki döneme göre' : 'vs previous period' }}</span>
                    @else
                        <span class="text-gray-400">{{ $isTr ? 'Karşılaştırma yok' : 'No comparison' }}</span>
                    @endif
                </div>
            </article>
        @endforeach
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($secondaryMetrics as $card)
            @php $metric = $kpis[$card['key']] ?? []; @endphp
            <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-xs font-medium text-gray-400">{{ $card['label'] }}</p>
                <p class="mt-1 text-lg font-bold text-gray-900 dark:text-white">{{ $metric['display'] ?? '—' }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1.7fr)_minmax(320px,.8fr)]">
        @if (! empty($professional['trend']))
            <x-ta.chart-card
                :title="$isTr ? 'Performans Eğilimi' : 'Performance Trend'"
                :subtitle="$isTr ? 'Günlük harcama ve tıklama trendi · gerçek Meta Ads verisi' : 'Daily spend and clicks trend · real Meta Ads data'"
                :options="$performanceChartOptions"
                chart-id="meta-professional-overview-trend"
            />
        @else
            <article class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <h2 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Performans Eğilimi' : 'Performance Trend' }}</h2>
                <div class="mt-5 rounded-xl border border-dashed border-gray-300 px-5 py-12 text-center text-sm text-gray-400 dark:border-gray-700">{{ $isTr ? 'Seçili dönem için kullanılabilir günlük performans verisi yok.' : 'No usable daily performance data for the selected period.' }}</div>
            </article>
        @endif

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-6">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">MOXDOP</p>
                    <h2 class="mt-1 text-lg font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Dikkat Gerektirenler' : 'Needs Attention' }}</h2>
                </div>
                <span class="rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-500 dark:bg-white/[0.05]">{{ count($healthIssues) }}</span>
            </div>

            <div class="mt-5 space-y-3">
                @forelse ($healthIssues as $issue)
                    <div class="rounded-xl border border-amber-200 bg-amber-50/60 p-4 dark:border-amber-500/20 dark:bg-amber-500/[0.06]">
                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $issue['label'] }}</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $issue['freshness_state'] }} · {{ $issue['coverage_state'] }} · {{ $issue['integrity_status'] }}</p>
                    </div>
                @empty
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50/60 p-4 text-sm text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/[0.06] dark:text-emerald-300">
                        {{ $isTr ? 'Kullanılabilir datasetlerde veri sağlığı sorunu görünmüyor.' : 'No data-health issue is visible in usable datasets.' }}
                    </div>
                @endforelse
            </div>
            <p class="mt-4 text-xs leading-5 text-gray-400">{{ $isTr ? 'Performans bulguları ayrıca analiz motoru tarafından üretilecek; burada sahte teşhis oluşturulmaz.' : 'Performance findings will be produced by the analysis engine; no diagnosis is fabricated here.' }}</p>
        </article>
    </div>

    <div class="grid gap-5 xl:grid-cols-3">
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex items-center justify-between"><h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'En Yüksek Harcamalı Kampanyalar' : 'Top Campaigns by Spend' }}</h3><button type="button" wire:click="setTab('campaigns')" class="text-xs font-semibold text-brand-600 dark:text-brand-400">{{ $isTr ? 'Tümü' : 'View all' }}</button></div>
            <div class="mt-4 space-y-3">
                @forelse ($topCampaigns as $campaign)
                    <div class="flex items-center justify-between gap-4 border-b border-gray-100 pb-3 last:border-0 last:pb-0 dark:border-gray-800">
                        <div class="min-w-0"><p class="truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $campaign['name'] }}</p><p class="mt-0.5 text-xs text-gray-400">CTR {{ $campaign['ctr'] !== null ? number_format($campaign['ctr'], 2).'%' : '—' }} · {{ $campaign['status'] }}</p></div>
                        <span class="shrink-0 text-sm font-bold text-gray-900 dark:text-white">{{ $campaign['spend_display'] }}</span>
                    </div>
                @empty
                    <p class="py-8 text-center text-sm text-gray-400">{{ $isTr ? 'Kampanya performans verisi yok.' : 'No campaign performance data.' }}</p>
                @endforelse
            </div>
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex items-center justify-between"><h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Kreatif Nabzı' : 'Creative Pulse' }}</h3><button type="button" wire:click="setTab('creatives')" class="text-xs font-semibold text-brand-600 dark:text-brand-400">{{ $isTr ? 'Tümü' : 'View all' }}</button></div>
            <div class="mt-4 space-y-3">
                @forelse ($topCreatives as $creative)
                    <div class="flex items-center gap-3 border-b border-gray-100 pb-3 last:border-0 last:pb-0 dark:border-gray-800">
                        <div class="h-10 w-10 shrink-0 overflow-hidden rounded-lg bg-gray-100 dark:bg-white/[0.05]">
                            @if (! empty($creative['thumbnail_url']))<img src="{{ $creative['thumbnail_url'] }}" alt="" class="h-full w-full object-cover" loading="lazy">@endif
                        </div>
                        <div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $creative['name'] }}</p><p class="text-xs text-gray-400">{{ $creative['format'] }} · CTR {{ $creative['ctr'] !== null ? number_format($creative['ctr'], 2).'%' : '—' }}</p></div>
                        <span class="text-xs font-semibold text-gray-700 dark:text-gray-300">{{ $creative['spend_display'] }}</span>
                    </div>
                @empty
                    <p class="py-8 text-center text-sm text-gray-400">{{ $isTr ? 'Kreatif snapshot verisi yok.' : 'No creative snapshot data.' }}</p>
                @endforelse
            </div>
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex items-center justify-between"><h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Dönüşüm Sinyalleri' : 'Conversion Signals' }}</h3><button type="button" wire:click="setTab('measurement')" class="text-xs font-semibold text-brand-600 dark:text-brand-400">{{ $isTr ? 'Detay' : 'Details' }}</button></div>
            <div class="mt-4 space-y-3">
                @forelse ($actions as $action)
                    <div class="flex items-center justify-between gap-3 border-b border-gray-100 pb-3 last:border-0 last:pb-0 dark:border-gray-800"><span class="truncate text-sm text-gray-600 dark:text-gray-300">{{ $action['label'] }}</span><span class="font-bold tabular-nums text-gray-900 dark:text-white">{{ number_format($action['value'], 2) }}</span></div>
                @empty
                    <p class="py-8 text-center text-sm text-gray-400">{{ $isTr ? 'Typed action verisi yok.' : 'No typed-action data.' }}</p>
                @endforelse
            </div>
            <p class="mt-4 text-[11px] leading-5 text-gray-400">{{ $isTr ? 'Action türleri ayrı tutulur; genel “Results” toplamına dönüştürülmez.' : 'Action types stay separate and are not collapsed into a generic “Results” total.' }}</p>
        </article>
    </div>

    <div class="rounded-xl border border-blue-200 bg-blue-50/60 px-4 py-3 text-xs leading-5 text-blue-800 dark:border-blue-500/20 dark:bg-blue-500/[0.06] dark:text-blue-300">
        {{ $isTr ? 'Results, CPL ve ROAS; canonical Business Action eşlemesi kurulmadan hesaplanmaz. Bu alanlarda “0” göstermek yerine ölçüm bilinçli olarak boş bırakılır.' : 'Results, CPL and ROAS are not calculated until canonical Business Action mapping exists. Missing measurement is intentionally left unavailable rather than shown as 0.' }}
    </div>
</section>
