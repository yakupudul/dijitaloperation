@php
    $isTr = app()->getLocale() === 'tr';
    $kpis = $professional['kpis'] ?? [];
    $campaigns = array_slice(array_values(array_filter($professional['campaigns'] ?? [], static fn (array $row): bool => ($row['spend'] ?? 0) > 0)), 0, 10);
    $actions = array_slice($professional['typed_actions'] ?? [], 0, 8);
    $hourly = collect($professional['hourly'] ?? [])->sortByDesc('spend')->take(12)->values()->all();
    $cards = [
        ['key' => 'ctr', 'label' => $isTr ? 'Tıklama Oranı' : 'Click-through Rate', 'short' => 'CTR', 'help' => $isTr ? 'Her 100 reklam gösteriminin kaçının tıklamayla sonuçlandığını gösterir.' : 'Share of impressions that produced a click.', 'lower_better' => false],
        ['key' => 'cpc', 'label' => $isTr ? 'Tıklama Başına Maliyet' : 'Cost per Click', 'short' => 'CPC', 'help' => $isTr ? 'Bir tıklama almak için ortalama ne kadar ödendiği.' : 'Average amount paid for one click.', 'lower_better' => true],
        ['key' => 'cpm', 'label' => $isTr ? '1.000 Gösterim Maliyeti' : 'Cost per 1,000 Impressions', 'short' => 'CPM', 'help' => $isTr ? 'Reklamı 1.000 kez göstermek için ortalama ne kadar ödendiği.' : 'Average cost for 1,000 impressions.', 'lower_better' => true],
        ['key' => 'link_clicks', 'label' => $isTr ? 'Bağlantı Tıklamaları' : 'Link Clicks', 'short' => null, 'help' => $isTr ? 'Reklamdaki bağlantılara yapılan tıklamalar.' : 'Clicks on links inside the ad.', 'lower_better' => false],
    ];
@endphp

<section class="space-y-5">
    <div>
        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">{{ $isTr ? 'Performans' : 'Performance' }}</p>
        <h2 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Reklam hesabı daha verimli mi, daha pahalı mı çalışıyor?' : 'Is the ad account becoming more efficient or more expensive?' }}</h2>
        <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Tıklama verimliliğini, maliyet değişimini, günlük eğilimi, reklamların ürettiği sonuçları ve en yoğun saatleri birlikte okuyun.' : 'Read click efficiency, cost changes, daily trend, ad outcomes and peak hours together.' }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($cards as $card)
            @php
                $metric = $kpis[$card['key']] ?? [];
                $delta = $metric['delta_pct'] ?? null;
                $isGood = $delta !== null ? ($card['lower_better'] ? $delta <= 0 : $delta >= 0) : null;
            @endphp
            <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-start justify-between gap-2"><div><p class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ $card['label'] }}</p>@if($card['short'])<p class="mt-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ $card['short'] }}</p>@endif</div><span class="cursor-help text-xs text-gray-300" title="{{ $card['help'] }}">ⓘ</span></div>
                <p class="mt-3 text-3xl font-bold tracking-tight text-gray-900 dark:text-white">{{ $metric['display'] ?? '—' }}</p>
                @if ($delta !== null)
                    <p class="mt-2 text-xs font-semibold {{ $isGood ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                        {{ $delta > 0 ? '+' : '' }}{{ number_format($delta, 1) }}%
                        @if($isTr && in_array($card['key'], ['cpc','cpm'], true)) · {{ $delta > 0 ? 'daha pahalı' : 'daha ucuz' }} @elseif($isTr) · {{ $isGood ? 'olumlu yönde' : 'olumsuz yönde' }} @endif
                    </p>
                @else
                    <p class="mt-2 text-xs text-gray-400">{{ $isTr ? 'Karşılaştırma mevcut değil' : 'Comparison unavailable' }}</p>
                @endif
            </article>
        @endforeach
    </div>

    @if (! empty($professional['trend']))
        <x-ta.chart-card :title="$isTr ? 'Günlük Harcama ve Tıklama Eğilimi' : 'Daily Spend and Click Trend'" :subtitle="$isTr ? 'Gün gün ne kadar harcandı ve kaç tıklama alındı?' : 'Daily spend and total clicks'" :options="$performanceChartOptions" chart-id="meta-professional-performance-trend" />
    @endif

    <div class="grid gap-5 xl:grid-cols-2">
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-6">
            <div class="flex items-start justify-between gap-3"><div><h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Reklamların Ürettiği Sonuçlar' : 'Ad Outcomes' }}</h3><p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Lead, mesaj, satın alma ve diğer Meta aksiyonları kendi türüyle gösterilir.' : 'Leads, messages, purchases and other Meta actions stay typed.' }}</p></div><button type="button" wire:click="setTab('measurement')" class="text-xs font-semibold text-brand-600 dark:text-brand-400">{{ $isTr ? 'Detay' : 'Details' }}</button></div>
            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                @forelse ($actions as $action)
                    <div class="rounded-xl border border-gray-100 p-4 dark:border-gray-800"><p class="text-xs text-gray-500 dark:text-gray-400">{{ $isTr ? ($action['label_tr'] ?? $action['label']) : ($action['label_en'] ?? $action['label']) }}</p><p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ number_format((float)$action['value'], 2) }}</p><details class="mt-2"><summary class="cursor-pointer text-[10px] text-gray-300">{{ $isTr ? 'Teknik ayrıntı' : 'Technical detail' }}</summary><code class="text-[10px] text-gray-400">{{ $action['action_type'] }}</code></details></div>
                @empty
                    <div class="col-span-full rounded-xl border border-dashed border-gray-300 px-5 py-10 text-center text-sm text-gray-400 dark:border-gray-700">{{ $isTr ? 'Seçili dönemde Meta tarafından ölçülmüş action verisi yok.' : 'No Meta-observed actions in this period.' }}</div>
                @endforelse
            </div>
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-6">
            <h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'En Yoğun Reklam Saatleri' : 'Peak Ad Hours' }}</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Hesap saat dilimine göre en fazla reklam harcaması gerçekleşen saatler.' : 'Hours with the highest ad spend in the account timezone.' }}</p>
            <div class="mt-5 space-y-2">
                @forelse ($hourly as $row)
                    @php $hourLabel = preg_replace('/:\d\d:\d\d\s*-\s*(\d\d):\d\d:\d\d/', ':00–$1:00', (string)$row['hour']); @endphp
                    <div class="grid grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-4 rounded-xl border border-gray-100 px-3 py-2.5 text-sm dark:border-gray-800"><span class="truncate font-medium text-gray-700 dark:text-gray-300">{{ $hourLabel }}</span><span class="tabular-nums text-gray-500">{{ $isTr ? 'Tıklama oranı' : 'Click rate' }} {{ $row['ctr'] !== null ? number_format($row['ctr'], 2).'%' : '—' }}</span><span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $professional['currency'] ?? '' }} {{ number_format($row['spend'], 2) }}</span></div>
                @empty
                    <div class="rounded-xl border border-dashed border-gray-300 px-5 py-10 text-center text-sm text-gray-400 dark:border-gray-700">{{ $isTr ? 'Saatlik performans verisi yok.' : 'No hourly performance data.' }}</div>
                @endforelse
            </div>
        </article>
    </div>

    <article class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'En Çok Harcama Yapan Kampanyalar' : 'Top Campaigns by Spend' }}</h3><p class="mt-1 text-xs text-gray-400">{{ $isTr ? 'Bu özet ilk 10 kampanyayı gösterir; Kampanyalar sekmesinde hesap envanterinin tamamı bulunur.' : 'This summary shows the top 10; the Campaigns tab contains the full account inventory.' }}</p></div>
        <div class="overflow-x-auto"><table class="min-w-full text-left"><thead class="bg-gray-50/80 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:bg-white/[0.02]"><tr><th class="px-5 py-3">{{ $isTr ? 'Kampanya' : 'Campaign' }}</th><th class="px-4 py-3 text-right">{{ $isTr ? 'Harcama' : 'Spend' }}</th><th class="px-4 py-3 text-right">{{ $isTr ? 'Tıklama Oranı' : 'Click Rate' }}</th><th class="px-4 py-3 text-right">{{ $isTr ? 'Tıklama Maliyeti' : 'Click Cost' }}</th><th class="px-5 py-3">{{ $isTr ? 'Öne Çıkan Sonuç' : 'Top Outcome' }}</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($campaigns as $row)
                @php $action = $row['actions'][0] ?? null; @endphp
                <tr><td class="max-w-sm px-5 py-3.5"><p class="truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $row['name'] }}</p><p class="text-[11px] text-gray-400">{{ $row['objective'] ? str_replace('_', ' ', $row['objective']) : $row['status'] }}</p></td><td class="px-4 py-3.5 text-right text-sm font-semibold tabular-nums">{{ $row['spend_display'] }}</td><td class="px-4 py-3.5 text-right text-sm tabular-nums">{{ $row['ctr'] !== null ? number_format($row['ctr'], 2).'%' : '—' }}</td><td class="px-4 py-3.5 text-right text-sm tabular-nums">{{ $row['cpc'] !== null ? ($row['currency'].' '.number_format($row['cpc'], 2)) : '—' }}</td><td class="px-5 py-3.5 text-sm">@if($action)<span class="font-semibold text-gray-800 dark:text-gray-200">{{ $isTr ? $action['label_tr'] : $action['label_en'] }}</span><span class="ml-2 tabular-nums text-gray-500">{{ number_format((float)$action['value'], 2) }}</span>@else<span class="text-gray-300">—</span>@endif</td></tr>
            @empty
                <tr><td colspan="5" class="px-5 py-10 text-center text-sm text-gray-400">{{ $isTr ? 'Seçili dönemde kampanya performansı yok.' : 'No campaign performance in this period.' }}</td></tr>
            @endforelse
        </tbody></table></div>
    </article>
</section>
