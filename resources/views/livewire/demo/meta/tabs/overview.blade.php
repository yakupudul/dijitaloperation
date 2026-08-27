@php
    $isTr = app()->getLocale() === 'tr';
    $kpis = $professional['kpis'] ?? [];
    $metricCards = [
        ['key' => 'spend', 'label' => $isTr ? 'Reklam Harcaması' : 'Ad Spend', 'short' => null, 'help' => $isTr ? 'Seçili dönemde reklamlara harcanan toplam tutar.' : 'Total amount spent on ads in the selected period.'],
        ['key' => 'impressions', 'label' => $isTr ? 'Reklam Gösterimleri' : 'Ad Impressions', 'short' => null, 'help' => $isTr ? 'Reklamların ekranda toplam kaç kez gösterildiği.' : 'How many times ads were shown.'],
        ['key' => 'clicks', 'label' => $isTr ? 'Toplam Tıklamalar' : 'Total Clicks', 'short' => null, 'help' => $isTr ? 'Meta’nın reklam için kaydettiği tüm tıklamalar.' : 'All clicks Meta recorded for the ads.'],
        ['key' => 'ctr', 'label' => $isTr ? 'Tıklama Oranı' : 'Click-through Rate', 'short' => 'CTR', 'help' => $isTr ? 'Her 100 gösterimin kaçının tıklamayla sonuçlandığını gösterir.' : 'Share of impressions that produced a click.'],
    ];
    $secondaryMetrics = [
        ['key' => 'cpc', 'label' => $isTr ? 'Tıklama Başına Maliyet' : 'Cost per Click', 'short' => 'CPC', 'help' => $isTr ? 'Bir tıklama almak için ortalama ne kadar ödendiği.' : 'Average amount paid for one click.'],
        ['key' => 'cpm', 'label' => $isTr ? '1.000 Gösterim Maliyeti' : 'Cost per 1,000 Impressions', 'short' => 'CPM', 'help' => $isTr ? 'Reklamın 1.000 kez gösterilmesinin ortalama maliyeti.' : 'Average cost to serve 1,000 impressions.'],
        ['key' => 'link_clicks', 'label' => $isTr ? 'Bağlantı Tıklamaları' : 'Link Clicks', 'short' => null, 'help' => $isTr ? 'Reklamdaki bağlantıya yapılan tıklamalar.' : 'Clicks on links inside the ad.'],
        ['key' => 'outbound_clicks', 'label' => $isTr ? 'Meta Dışına Giden Tıklamalar' : 'Outbound Clicks', 'short' => null, 'help' => $isTr ? 'Facebook veya Instagram dışındaki bir hedefe giden tıklamalar.' : 'Clicks that led outside Meta surfaces.'],
    ];
    $deltaClass = static function (string $key, ?float $delta): string {
        if ($delta === null) return 'text-gray-500 dark:text-gray-400';
        if (in_array($key, ['cpc', 'cpm'], true)) return $delta <= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400';
        if ($key === 'ctr') return $delta >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400';
        return 'text-gray-600 dark:text-gray-300';
    };
    $deltaMeaning = static function (string $key, float $delta) use ($isTr): string {
        if (!$isTr) return 'vs previous period';
        if (in_array($key, ['cpc', 'cpm'], true)) return $delta > 0 ? 'daha pahalı' : ($delta < 0 ? 'daha ucuz' : 'değişmedi');
        if ($key === 'ctr') return $delta > 0 ? 'daha iyi' : ($delta < 0 ? 'daha düşük' : 'değişmedi');
        return 'önceki döneme göre';
    };
    $allCampaigns = $professional['campaigns'] ?? [];
    $topCampaigns = array_slice(array_values(array_filter($allCampaigns, static fn (array $row): bool => ($row['spend'] ?? 0) > 0)), 0, 5);
    $topCreatives = array_slice($professional['creatives'] ?? [], 0, 4);
    $actions = array_slice($professional['typed_actions'] ?? [], 0, 6);
    $healthIssues = array_slice($professional['health']['issues'] ?? [], 0, 5);
    $inventory = $professional['campaign_inventory'] ?? ['total' => count($allCampaigns), 'with_period_activity' => count($allCampaigns)];
@endphp

<section class="space-y-5">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($metricCards as $card)
            @php $metric = $kpis[$card['key']] ?? []; $delta = $metric['delta_pct'] ?? null; @endphp
            <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-start justify-between gap-2">
                    <div><p class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ $card['label'] }}</p>@if($card['short'])<p class="mt-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ $card['short'] }}</p>@endif</div>
                    <span class="cursor-help text-xs text-gray-300" title="{{ $card['help'] }}">ⓘ</span>
                </div>
                <p class="mt-3 text-3xl font-bold tracking-tight text-gray-900 dark:text-white">{{ $metric['display'] ?? '—' }}</p>
                <div class="mt-3 text-xs">
                    @if ($delta !== null)
                        <span class="font-semibold {{ $deltaClass($card['key'], (float) $delta) }}">{{ $delta > 0 ? '+' : '' }}{{ number_format($delta, 1) }}% · {{ $deltaMeaning($card['key'], (float) $delta) }}</span>
                    @else
                        <span class="text-gray-400">{{ $isTr ? 'Önceki dönem karşılaştırması yok' : 'No previous-period comparison' }}</span>
                    @endif
                </div>
            </article>
        @endforeach
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($secondaryMetrics as $card)
            @php $metric = $kpis[$card['key']] ?? []; $delta = $metric['delta_pct'] ?? null; @endphp
            <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between gap-2"><p class="text-xs font-medium text-gray-500">{{ $card['label'] }} @if($card['short'])<span class="text-gray-300">({{ $card['short'] }})</span>@endif</p><span class="cursor-help text-[11px] text-gray-300" title="{{ $card['help'] }}">ⓘ</span></div>
                <p class="mt-1 text-lg font-bold text-gray-900 dark:text-white">{{ $metric['display'] ?? '—' }}</p>
                @if ($delta !== null)<p class="mt-1 text-[11px] font-semibold {{ $deltaClass($card['key'], (float) $delta) }}">{{ $delta > 0 ? '+' : '' }}{{ number_format($delta, 1) }}% · {{ $deltaMeaning($card['key'], (float) $delta) }}</p>@endif
            </div>
        @endforeach
    </div>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1.7fr)_minmax(320px,.8fr)]">
        @if (! empty($professional['trend']))
            <x-ta.chart-card :title="$isTr ? 'Harcama ve Tıklamaların Zaman İçindeki Değişimi' : 'Spend and Click Trend'" :subtitle="$isTr ? 'Seçili dönemde günlük reklam harcaması ve toplam tıklamalar' : 'Daily ad spend and total clicks in the selected period'" :options="$performanceChartOptions" chart-id="meta-professional-overview-trend" />
        @else
            <article class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900"><h2 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Performans Eğilimi' : 'Performance Trend' }}</h2><div class="mt-5 rounded-xl border border-dashed border-gray-300 px-5 py-12 text-center text-sm text-gray-400 dark:border-gray-700">{{ $isTr ? 'Seçili dönem için günlük performans verisi yok.' : 'No daily performance data for this period.' }}</div></article>
        @endif

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-6">
            <div class="flex items-center justify-between gap-3"><div><p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">MOXDOP</p><h2 class="mt-1 text-lg font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Veriyle İlgili Dikkat Noktaları' : 'Data Attention' }}</h2></div><span class="rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-500 dark:bg-white/[0.05]">{{ count($healthIssues) }}</span></div>
            <div class="mt-5 space-y-3">
                @forelse ($healthIssues as $issue)
                    <details class="rounded-xl border border-amber-200 bg-amber-50/60 p-4 dark:border-amber-500/20 dark:bg-amber-500/[0.06]">
                        <summary class="cursor-pointer text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $isTr ? 'Bu veri kaynağında kapsam veya güncellik sınırlaması var' : 'This data source has a coverage or freshness limitation' }}</summary>
                        <div class="mt-2 text-xs text-gray-500 dark:text-gray-400"><p>{{ $issue['label'] }}</p><p class="mt-1 font-mono text-[10px]">{{ $issue['freshness_state'] }} · {{ $issue['coverage_state'] }} · {{ $issue['integrity_status'] }}</p></div>
                    </details>
                @empty
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50/60 p-4 text-sm text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/[0.06] dark:text-emerald-300">{{ $isTr ? 'Gösterilen verilerde belirgin bir veri sağlığı sorunu görünmüyor.' : 'No clear data-health issue is visible.' }}</div>
                @endforelse
            </div>
        </article>
    </div>

    <div class="grid gap-5 xl:grid-cols-3">
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex items-start justify-between gap-3"><div><h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'En Çok Harcama Yapan Kampanyalar' : 'Top Campaigns by Spend' }}</h3><p class="mt-1 text-[11px] text-gray-400">{{ $isTr ? 'Hesaptaki '.number_format((int)($inventory['total'] ?? 0)).' kampanyanın tamamı dikkate alınır.' : 'All '.number_format((int)($inventory['total'] ?? 0)).' campaigns are considered.' }}</p></div><button type="button" wire:click="setTab('campaigns')" class="text-xs font-semibold text-brand-600 dark:text-brand-400">{{ $isTr ? 'Tümünü gör' : 'View all' }}</button></div>
            <div class="mt-4 space-y-3">
                @forelse ($topCampaigns as $campaign)
                    @php $action = $campaign['actions'][0] ?? null; @endphp
                    <div class="flex items-center justify-between gap-4 border-b border-gray-100 pb-3 last:border-0 last:pb-0 dark:border-gray-800"><div class="min-w-0"><p class="truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $campaign['name'] }}</p><p class="mt-0.5 text-xs text-gray-400">{{ $isTr ? 'Tıklama oranı' : 'Click rate' }} {{ $campaign['ctr'] !== null ? number_format($campaign['ctr'], 2).'%' : '—' }} · {{ $campaign['status'] }}</p>@if($action)<p class="mt-1 text-[11px] font-medium text-brand-600 dark:text-brand-400">{{ $isTr ? $action['label_tr'] : $action['label_en'] }}: {{ number_format((float)$action['value'], 2) }}</p>@endif</div><span class="shrink-0 text-sm font-bold text-gray-900 dark:text-white">{{ $campaign['spend_display'] }}</span></div>
                @empty
                    <p class="py-8 text-center text-sm text-gray-400">{{ $isTr ? 'Seçili dönemde harcama yapan kampanya yok.' : 'No campaign spend in the selected period.' }}</p>
                @endforelse
            </div>
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex items-center justify-between"><h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Kreatif Performansı' : 'Creative Performance' }}</h3><button type="button" wire:click="setTab('creatives')" class="text-xs font-semibold text-brand-600 dark:text-brand-400">{{ $isTr ? 'Tümünü gör' : 'View all' }}</button></div>
            <div class="mt-4 space-y-3">
                @forelse ($topCreatives as $creative)
                    @php $action = $creative['actions'][0] ?? null; @endphp
                    <div class="flex items-center gap-3 border-b border-gray-100 pb-3 last:border-0 last:pb-0 dark:border-gray-800"><div class="h-10 w-10 shrink-0 overflow-hidden rounded-lg bg-gray-100 dark:bg-white/[0.05]">@if (! empty($creative['thumbnail_url']))<img src="{{ $creative['thumbnail_url'] }}" alt="" class="h-full w-full object-cover" loading="lazy">@endif</div><div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $creative['name'] }}</p><p class="text-xs text-gray-400">{{ $isTr ? 'Tıklama oranı' : 'Click rate' }} {{ $creative['ctr'] !== null ? number_format($creative['ctr'], 2).'%' : '—' }}</p>@if($action)<p class="mt-1 text-[11px] font-medium text-brand-600 dark:text-brand-400">{{ $isTr ? $action['label_tr'] : $action['label_en'] }}: {{ number_format((float)$action['value'], 2) }}</p>@endif</div><span class="text-xs font-semibold text-gray-700 dark:text-gray-300">{{ $creative['spend_display'] }}</span></div>
                @empty
                    <p class="py-8 text-center text-sm text-gray-400">{{ $isTr ? 'Kreatif verisi yok.' : 'No creative data.' }}</p>
                @endforelse
            </div>
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex items-center justify-between"><div><h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Reklamların Ürettiği Sonuçlar' : 'Ad Outcomes' }}</h3><p class="mt-1 text-[11px] text-gray-400">{{ $isTr ? 'Meta’nın ölçtüğü sonuç türleri birbirinden ayrı gösterilir.' : 'Meta-observed action types are kept separate.' }}</p></div><button type="button" wire:click="setTab('measurement')" class="text-xs font-semibold text-brand-600 dark:text-brand-400">{{ $isTr ? 'Tüm sonuçlar' : 'All outcomes' }}</button></div>
            <div class="mt-4 space-y-3">
                @forelse ($actions as $action)
                    <div class="flex items-center justify-between gap-3 border-b border-gray-100 pb-3 last:border-0 last:pb-0 dark:border-gray-800"><div class="min-w-0"><span class="truncate text-sm text-gray-600 dark:text-gray-300">{{ $isTr ? ($action['label_tr'] ?? $action['label']) : ($action['label_en'] ?? $action['label']) }}</span><details class="mt-0.5"><summary class="cursor-pointer text-[10px] text-gray-300">{{ $isTr ? 'Teknik adı' : 'Technical name' }}</summary><code class="text-[10px] text-gray-400">{{ $action['action_type'] }}</code></details></div><span class="font-bold tabular-nums text-gray-900 dark:text-white">{{ number_format($action['value'], 2) }}</span></div>
                @empty
                    <p class="py-8 text-center text-sm text-gray-400">{{ $isTr ? 'Meta tarafından ölçülmüş action verisi yok.' : 'No Meta-observed action data.' }}</p>
                @endforelse
            </div>
        </article>
    </div>

    <div class="rounded-xl border border-blue-200 bg-blue-50/60 px-4 py-3 text-xs leading-5 text-blue-800 dark:border-blue-500/20 dark:bg-blue-500/[0.06] dark:text-blue-300">{{ $isTr ? 'MOXDOP farklı sonuç türlerini tek bir sayı altında toplamaz. Lead, mesaj, satın alma veya diğer aksiyonlar kendi türüyle gösterilir; böylece yüksek hacimli bir etkileşim gerçek iş sonucuyla karıştırılmaz.' : 'MOXDOP does not collapse different outcome types into one number. Leads, messages, purchases and other actions stay separate.' }}</div>
</section>
