@php
    $isTr = app()->getLocale() === 'tr';
    $glance = $data['glance'] ?? [];
    $search = $data['search'] ?? [];
    $lp = $data['landing_pages'] ?? [];
    $measurement = $data['measurement'] ?? [];
    $campaigns = collect($data['campaigns'] ?? []);
    $health = collect($professional['data_health'] ?? []);
    $recommendations = collect(data_get($professional, 'optimization.google_recommendations', []));
    $changes = collect($professional['changes'] ?? []);
    $spendRaw = data_get($glance, 'spend.raw');
    $conversionRaw = data_get($glance, 'conversions.raw');
    $providerCpa = is_numeric($spendRaw) && is_numeric($conversionRaw) && (float) $conversionRaw > 0
        ? (float) $spendRaw / (float) $conversionRaw
        : null;
    $currency = (string) (data_get($identity, 'currency') ?: ($professional['currency'] ?? ''));
    $money = function ($value) use ($currency): string {
        if (! is_numeric($value)) return '—';
        return trim(number_format((float) $value, 2, ',', '.').' '.$currency);
    };
    $number = fn ($value, int $decimals = 0): string => is_numeric($value) ? number_format((float) $value, $decimals, ',', '.') : '—';
    $datasetTotal = $health->count();
    $datasetHealthy = $health->where('partial', false)->count();
    $datasetPartial = $health->where('partial', true)->count();
    $termsObserved = data_get($search, 'terms_observed');
    $landingActive = data_get($lp, 'active');
    $chartOptions = $performanceChartOptions;
    if ($isTr) {
        data_set($chartOptions, 'series.0.name', 'Harcama');
        data_set($chartOptions, 'series.1.name', 'Google Ads dönüşümleri');
        data_set($chartOptions, 'yaxis.0.title.text', 'Harcama');
        data_set($chartOptions, 'yaxis.1.title.text', 'Dönüşümler');
    }
@endphp

<div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
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
            :delta="$providerCpa !== null ? ($isTr ? 'Google Ads conversions üzerinden' : 'Based on Google Ads conversions') : ($isTr ? 'Dönüşüm verisi gerekli' : 'Conversion signal required')"
        />
        <x-ta.metric-card
            :label="$isTr ? 'Veri sağlığı' : 'Data health'"
            :value="$datasetTotal > 0 ? $datasetHealthy.'/'.$datasetTotal : '—'"
            :delta="$datasetTotal > 0 ? ($datasetPartial.' '.($isTr ? 'dataset kısmi/eksik' : 'partial/incomplete datasets')) : ($isTr ? 'Materialization kaydı bekleniyor' : 'Waiting for materialization')"
            :tone="$datasetPartial > 0 ? 'warning' : 'neutral'"
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
                @if (! empty(data_get($data, 'performance_trend.note')))
                    <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Grafik Google Ads sağlayıcı dönüşümlerini gösterir; bunlar otomatik olarak nitelikli lead veya gelir değildir.' : data_get($data, 'performance_trend.note') }}</p>
                @endif
            @else
                <div class="mt-4 rounded-xl bg-gray-50 px-4 py-10 text-center text-sm text-gray-500 dark:bg-white/[0.02]">{{ $isTr ? 'Seçili dönem için hesap seviyesinde günlük performans henüz kullanılabilir değil.' : 'Account-level daily performance is not yet usable for the selected period.' }}</div>
            @endif
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03] xl:col-span-4">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Uzman kontrol listesi' : 'Specialist checklist' }}</h2>
                <button type="button" wire:click="setTab('optimization')" class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ $isTr ? 'Optimizasyon' : 'Optimization' }} →</button>
            </div>
            <div class="mt-3 space-y-2.5 text-sm">
                <div class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.02]"><span class="text-gray-600 dark:text-gray-300">{{ $isTr ? 'Google önerileri' : 'Google recommendations' }}</span><strong>{{ $recommendations->count() }}</strong></div>
                <div class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.02]"><span class="text-gray-600 dark:text-gray-300">{{ $isTr ? 'Yakın dönem değişiklikleri' : 'Recent changes' }}</span><strong>{{ $changes->count() }}</strong></div>
                <div class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.02]"><span class="text-gray-600 dark:text-gray-300">{{ $isTr ? 'Kısmi datasetler' : 'Partial datasets' }}</span><strong class="{{ $datasetPartial > 0 ? 'text-amber-700 dark:text-amber-300' : 'text-emerald-700 dark:text-emerald-300' }}">{{ $datasetPartial }}</strong></div>
                <div class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.02]"><span class="text-gray-600 dark:text-gray-300">{{ $isTr ? 'Arama terimi' : 'Search terms' }}</span><strong>{{ is_numeric($termsObserved) ? number_format((int) $termsObserved, 0, ',', '.') : '—' }}</strong></div>
            </div>
            <p class="mt-3 text-xs text-gray-500">{{ $isTr ? 'Google önerileri sağlayıcı önerisidir; MOXDOP önerisi değildir ve otomatik uygulanmaz.' : 'Google recommendations are provider suggestions, not MOXDOP recommendations, and are never auto-applied.' }}</p>
        </section>
    </div>

    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
            <div>
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Kampanya portföyü' : 'Campaign portfolio' }}</h2>
                <p class="mt-0.5 text-xs text-gray-500">{{ $isTr ? 'Bütçenin ve dönüşümlerin kampanyalar arasında nasıl dağıldığını görün.' : 'See how spend and conversions are distributed across campaigns.' }}</p>
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
                    <th class="px-3 py-2.5 text-right">CPA</th>
                    <th class="px-4 py-2.5 text-right">IS</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($campaigns->take(10) as $c)
                        @php
                            $campaignConversions = $c['leads'] ?? null;
                            $campaignSpend = $c['spend'] ?? null;
                            $campaignCpa = is_numeric($campaignSpend) && is_numeric($campaignConversions) && (float) $campaignConversions > 0 ? (float) $campaignSpend / (float) $campaignConversions : null;
                        @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                            <td class="px-4 py-2.5"><p class="font-medium text-gray-900 dark:text-white">{{ $c['name'] }}</p><p class="text-[11px] text-gray-400">{{ $c['type'] ?? '—' }}</p></td>
                            <td class="px-3 py-2.5"><x-ta.badge :color="strtoupper((string)($c['status'] ?? '')) === 'ENABLED' ? 'success' : 'light'" size="sm">{{ $c['status'] ?? '—' }}</x-ta.badge></td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ $money($campaignSpend) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ $number($campaignConversions, 2) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ $campaignCpa !== null ? $money($campaignCpa) : '—' }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ is_numeric($c['impr_share'] ?? null) ? number_format((float)$c['impr_share'], 1, ',', '.').'%' : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">{{ $isTr ? 'Seçili dönem için kullanılabilir kampanya performansı yok.' : 'No usable campaign performance for the selected period.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="grid gap-4 lg:grid-cols-3">
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex items-center justify-between gap-2"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Arama' : 'Search' }}</h3><button type="button" wire:click="setTab('search_demand')" class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ $isTr ? 'İncele' : 'Inspect' }} →</button></div>
            <p class="mt-3 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ is_numeric($termsObserved) ? number_format((int)$termsObserved, 0, ',', '.') : '—' }}</p>
            <p class="text-xs text-gray-500">{{ $isTr ? 'seçili dönemde gözlenen arama terimi' : 'search terms observed in the selected period' }}</p>
            <div class="mt-3 border-t border-gray-100 pt-3 text-xs text-gray-500 dark:border-gray-800">{{ $isTr ? 'Intent sınıflandırması hesaplanmadıysa yüzde ve “review spend” alanları sıfır gösterilmez.' : 'Intent percentages and review spend remain unavailable until classification is actually computed.' }}</div>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex items-center justify-between gap-2"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Landing Pages</h3><button type="button" wire:click="setTab('landing_pages')" class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ $isTr ? 'İncele' : 'Inspect' }} →</button></div>
            <p class="mt-3 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ is_numeric($landingActive) ? number_format((int)$landingActive, 0, ',', '.') : '—' }}</p>
            <p class="text-xs text-gray-500">{{ $isTr ? 'reklam trafiği alan hedef URL' : 'paid-traffic destination URLs' }}</p>
            <div class="mt-3 border-t border-gray-100 pt-3 text-xs text-gray-500 dark:border-gray-800">{{ $isTr ? 'Website asset bağlandığında teknik/mobil kalite ile reklam performansı çapraz analiz edilecek.' : 'When a Website asset is linked, technical/mobile quality can be cross-analyzed with paid performance.' }}</div>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex items-center justify-between gap-2"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Dönüşüm ölçümü' : 'Conversion measurement' }}</h3><button type="button" wire:click="setTab('measurement')" class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ $isTr ? 'İncele' : 'Inspect' }} →</button></div>
            <p class="mt-3 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ count(data_get($measurement, 'matrix', [])) ?: '—' }}</p>
            <p class="text-xs text-gray-500">{{ $isTr ? 'Google Ads conversion action' : 'Google Ads conversion actions' }}</p>
            <div class="mt-3 border-t border-gray-100 pt-3 text-xs text-gray-500 dark:border-gray-800">{{ $isTr ? 'Google Ads dönüşümü otomatik olarak nitelikli lead, satış veya doğrulanmış gelir kabul edilmez.' : 'A Google Ads conversion is not automatically treated as a qualified lead, sale, or verified revenue.' }}</div>
        </section>
    </div>

    @if ($recommendations->isNotEmpty() || $changes->isNotEmpty())
        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex items-center justify-between"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Google önerileri' : 'Google recommendations' }}</h3><button type="button" wire:click="setTab('optimization')" class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ $isTr ? 'Tümü' : 'All' }} →</button></div>
                <p class="mt-3 text-3xl font-bold">{{ $recommendations->count() }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Sağlayıcı önerileri ayrı tutulur; MOXDOP önerisi olarak sunulmaz.' : 'Provider recommendations remain separate from MOXDOP recommendations.' }}</p>
            </section>
            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex items-center justify-between"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Yakın dönem değişiklikleri' : 'Recent changes' }}</h3><button type="button" wire:click="setTab('changes')" class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ $isTr ? 'Zaman çizelgesi' : 'Timeline' }} →</button></div>
                <p class="mt-3 text-3xl font-bold">{{ $changes->count() }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Google’ın Change Event penceresi içinde saklanan değişiklik kayıtları.' : 'Change records retained within the Google Change Event window.' }}</p>
            </section>
        </div>
    @endif
</div>
