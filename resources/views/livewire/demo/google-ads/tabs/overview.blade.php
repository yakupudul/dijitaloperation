@php
    $isTr = app()->getLocale() === 'tr';
    $glance = $data['glance'] ?? [];
    $search = $data['search'] ?? [];
    $lp = $data['landing_pages'] ?? [];
    $measurement = $data['measurement'] ?? [];
    $campaigns = collect($data['campaigns'] ?? []);
    $health = collect($professional['data_health'] ?? []);
    $recommendationCount = count(data_get($professional, 'optimization.google_recommendations', []));
    $changeCount = count($professional['changes'] ?? []);
    $spendRaw = data_get($glance, 'spend.raw');
    $conversionRaw = data_get($glance, 'conversions.raw');
    $providerCpa = is_numeric($spendRaw) && is_numeric($conversionRaw) && (float) $conversionRaw > 0
        ? (float) $spendRaw / (float) $conversionRaw
        : null;
    $currency = (string) (data_get($identity, 'currency') ?: ($professional['currency'] ?? ''));
    $money = function ($value) use ($currency): string {
        if (! is_numeric($value)) {
            return '—';
        }

        return trim(number_format((float) $value, 2, ',', '.').' '.$currency);
    };
    $number = fn ($value, int $decimals = 0): string => is_numeric($value) ? number_format((float) $value, $decimals, ',', '.') : '—';
    $statusLabel = fn ($status): string => \App\Services\GoogleAds\Support\GoogleAdsDisplayFormat::status(is_scalar($status) ? (string) $status : null);
    $datasetPartial = $health->where('partial', true)->count();
    $termsObserved = data_get($search, 'terms_observed');
    $landingActive = data_get($lp, 'active');
    $conversionActionCount = count(data_get($measurement, 'matrix', []));

    $spendingWithoutConversions = $campaigns->filter(fn (array $c): bool => is_numeric($c['spend'] ?? null) && (float) $c['spend'] > 0 && is_numeric($c['leads'] ?? null) && (float) $c['leads'] <= 0)->count();
    $budgetLimited = $campaigns->filter(fn (array $c): bool => is_numeric($c['lost_is_budget'] ?? null) && (float) $c['lost_is_budget'] >= 20)->count();

    /** Short, deduplicated "needs attention" list: every count appears only here on the Overview. */
    $attentionItems = collect([
        $spendingWithoutConversions > 0 ? [
            'tone' => 'warning',
            'text' => $isTr ? $spendingWithoutConversions.' kampanya harcama yaptı ama dönüşüm getirmedi' : $spendingWithoutConversions.' campaigns spent without a conversion',
            'tab' => 'campaigns',
        ] : null,
        $budgetLimited > 0 ? [
            'tone' => 'warning',
            'text' => $isTr ? $budgetLimited.' kampanya bütçe yetersizliği nedeniyle gösterim kaybediyor' : $budgetLimited.' campaigns lose impressions to budget',
            'tab' => 'budget_bidding',
        ] : null,
        $conversionActionCount === 0 ? [
            'tone' => 'warning',
            'text' => $isTr ? 'Dönüşüm işlemleri okunamadı; ölçümü kontrol edin' : 'No conversion actions found; check measurement',
            'tab' => 'measurement',
        ] : null,
        $recommendationCount > 0 ? [
            'tone' => 'info',
            'text' => $isTr ? $recommendationCount.' Google önerisi incelenmeyi bekliyor' : $recommendationCount.' Google recommendations to review',
            'tab' => 'advisor',
        ] : null,
        $changeCount > 0 ? [
            'tone' => 'info',
            'text' => $isTr ? 'Yakın dönemde '.$changeCount.' hesap değişikliği yapıldı' : $changeCount.' recent account changes',
            'tab' => 'changes',
        ] : null,
        $datasetPartial > 0 ? [
            'tone' => 'warning',
            'text' => $isTr ? $datasetPartial.' veri setinde eksik gün var' : $datasetPartial.' datasets are incomplete',
            'tab' => 'data_connection',
        ] : null,
    ])->filter()->take(5)->values();

    $topCampaigns = $campaigns
        ->sortByDesc(fn (array $c): float => is_numeric($c['spend'] ?? null) ? (float) $c['spend'] : 0.0)
        ->take(10)
        ->values();

    $chartOptions = $performanceChartOptions;
    if ($isTr) {
        data_set($chartOptions, 'series.0.name', 'Harcama');
        data_set($chartOptions, 'series.1.name', 'Google Ads dönüşümleri');
        data_set($chartOptions, 'yaxis.0.title.text', 'Harcama');
        data_set($chartOptions, 'yaxis.1.title.text', 'Dönüşümler');
    }
@endphp

<div class="space-y-4">
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <x-ta.metric-card
            :label="$isTr ? 'Harcama' : 'Spend'"
            :value="data_get($glance, 'spend.value', '—')"
            :delta="data_get($glance, 'spend.secondary')"
            :tone="data_get($glance, 'spend.tone', 'neutral')"
        />
        <x-ta.metric-card
            :label="$isTr ? 'Google Ads dönüşümleri' : 'Google Ads conversions'"
            :value="data_get($glance, 'conversions.value', '—')"
            :delta="data_get($glance, 'conversions.secondary')"
            :tone="data_get($glance, 'conversions.tone', 'neutral')"
        />
        <x-ta.metric-card
            :label="$isTr ? 'Dönüşüm başı maliyet' : 'Cost / Google Ads conversion'"
            :value="$providerCpa !== null ? $money($providerCpa) : '—'"
            :delta="$providerCpa !== null ? ($isTr ? 'Google Ads dönüşümlerine göre' : 'Based on Google Ads conversions') : ($isTr ? 'Dönüşüm verisi gerekli' : 'Conversion signal required')"
        />
    </div>

    <div class="grid gap-4 xl:grid-cols-12">
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03] xl:col-span-8">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Performans eğilimi' : 'Performance trend' }}</h2>
                    <p class="mt-0.5 text-xs text-gray-500">{{ $data['period_label'] ?? '—' }} · {{ $isTr ? 'harcama ve Google Ads dönüşümleri' : 'spend and Google Ads conversions' }}</p>
                </div>
                <button type="button" wire:click="setTab('performance')" class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ $isTr ? 'Kırılımları incele' : 'Open breakdowns' }} →</button>
            </div>
            @if (! empty(data_get($data, 'performance_trend.labels')))
                <div data-chart='@json($chartOptions)' aria-label="{{ $isTr ? 'Harcama ve Google Ads dönüşümleri eğilimi' : 'Spend and Google Ads conversion trend' }}" class="mt-2 min-h-[230px]"></div>
                <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Google Ads dönüşümleri otomatik olarak nitelikli müşteri adayı veya gelir sayılmaz.' : 'Google Ads conversions are not automatically qualified leads or revenue.' }}</p>
            @else
                <div class="mt-4 rounded-xl bg-gray-50 px-4 py-10 text-center text-sm text-gray-500 dark:bg-white/[0.02]">{{ $isTr ? 'Seçili dönem için günlük hesap performansı henüz yok.' : 'Account-level daily performance is not yet available for the selected period.' }}</div>
            @endif
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03] xl:col-span-4">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Dikkat gerektirenler' : 'Needs attention' }}</h2>
            <ul class="mt-3 space-y-2 text-sm">
                @forelse ($attentionItems as $item)
                    <li>
                        <button type="button" wire:click="setTab('{{ $item['tab'] }}')" class="flex w-full items-start justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2 text-left hover:bg-gray-100 dark:bg-white/[0.02] dark:hover:bg-white/[0.05]">
                            <span class="flex items-start gap-2">
                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $item['tone'] === 'warning' ? 'bg-amber-500' : 'bg-blue-500' }}"></span>
                                <span class="text-gray-700 dark:text-gray-200">{{ $item['text'] }}</span>
                            </span>
                            <span class="shrink-0 text-xs font-semibold text-brand-600 dark:text-brand-400">→</span>
                        </button>
                    </li>
                @empty
                    <li class="rounded-lg bg-emerald-50 px-3 py-3 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200">{{ $isTr ? 'Şu an dikkat gerektiren bir konu yok.' : 'Nothing needs attention right now.' }}</li>
                @endforelse
            </ul>
        </section>
    </div>

    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
            <div>
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'En çok harcayan kampanyalar' : 'Top campaigns' }}</h2>
                <p class="mt-0.5 text-xs text-gray-500">{{ $isTr ? 'Seçili dönemde bütçenin ve dönüşümlerin kampanyalara dağılımı.' : 'How spend and conversions are distributed across campaigns.' }}</p>
            </div>
            <button type="button" wire:click="setTab('campaigns')" class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ $isTr ? 'Tüm kampanyalar' : 'All campaigns' }} →</button>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-400 dark:bg-white/[0.02]"><tr>
                    <th class="px-4 py-2.5 text-left">{{ $isTr ? 'Kampanya' : 'Campaign' }}</th>
                    <th class="px-3 py-2.5 text-left">{{ $isTr ? 'Durum' : 'Status' }}</th>
                    <th class="px-3 py-2.5 text-right">{{ $isTr ? 'Harcama' : 'Spend' }}</th>
                    <th class="px-3 py-2.5 text-right">{{ $isTr ? 'Dönüşüm' : 'Conversions' }}</th>
                    <th class="px-3 py-2.5 text-right">{{ $isTr ? 'Dönüşüm başı maliyet' : 'CPA' }}</th>
                    <th class="px-4 py-2.5 text-right" title="{{ $isTr ? 'Arama gösterim payı' : 'Search impression share' }}">{{ $isTr ? 'Gösterim payı' : 'Impr. share' }}</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($topCampaigns as $c)
                        @php
                            $campaignConversions = $c['leads'] ?? null;
                            $campaignSpend = $c['spend'] ?? null;
                            $campaignCpa = is_numeric($campaignSpend) && is_numeric($campaignConversions) && (float) $campaignConversions > 0 ? (float) $campaignSpend / (float) $campaignConversions : null;
                        @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                            <td class="px-4 py-2.5"><p class="font-medium text-gray-900 dark:text-white">{{ $c['name'] }}</p><p class="text-[11px] text-gray-400">{{ $c['type'] ?? '—' }}</p></td>
                            <td class="px-3 py-2.5"><x-ta.badge :color="strtoupper((string) ($c['status'] ?? '')) === 'ENABLED' ? 'success' : 'light'" size="sm">{{ $statusLabel($c['status'] ?? null) }}</x-ta.badge></td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ $money($campaignSpend) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ $number($campaignConversions, 2) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ $campaignCpa !== null ? $money($campaignCpa) : '—' }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ is_numeric($c['impr_share'] ?? null) ? number_format((float) $c['impr_share'], 1, ',', '.').'%' : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">{{ $isTr ? 'Seçili dönem için kampanya performansı yok.' : 'No usable campaign performance for the selected period.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="grid gap-4 lg:grid-cols-3">
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex items-center justify-between gap-2"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Arama' : 'Search' }}</h3><button type="button" wire:click="setTab('search_demand')" class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ $isTr ? 'İncele' : 'Inspect' }} →</button></div>
            <p class="mt-3 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ is_numeric($termsObserved) ? number_format((int) $termsObserved, 0, ',', '.') : '—' }}</p>
            <p class="text-xs text-gray-500">{{ $isTr ? 'seçili dönemde görülen arama terimi' : 'search terms observed in the selected period' }}</p>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex items-center justify-between gap-2"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Açılış Sayfaları' : 'Landing pages' }}</h3><button type="button" wire:click="setTab('landing_pages')" class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ $isTr ? 'İncele' : 'Inspect' }} →</button></div>
            <p class="mt-3 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ is_numeric($landingActive) ? number_format((int) $landingActive, 0, ',', '.') : '—' }}</p>
            <p class="text-xs text-gray-500">{{ $isTr ? 'reklam trafiği alan sayfa' : 'paid-traffic destination URLs' }}</p>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex items-center justify-between gap-2"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Dönüşüm ölçümü' : 'Conversion measurement' }}</h3><button type="button" wire:click="setTab('measurement')" class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ $isTr ? 'İncele' : 'Inspect' }} →</button></div>
            <p class="mt-3 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $conversionActionCount ?: '—' }}</p>
            <p class="text-xs text-gray-500">{{ $isTr ? 'Google Ads dönüşüm işlemi' : 'Google Ads conversion actions' }}</p>
        </section>
    </div>
</div>
