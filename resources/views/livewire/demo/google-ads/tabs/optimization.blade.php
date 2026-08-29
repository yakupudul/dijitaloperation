@php
    $isTr = app()->getLocale() === 'tr';
    $currency = (string) (data_get($identity, 'currency') ?: data_get($professional, 'currency', ''));
    $rawRecommendations = data_get($professional, 'optimization.google_recommendations', []);
    $boundary = (string) (data_get($professional, 'optimization.boundary') ?? '');

    $number = fn ($value, int $decimals = 0) => is_numeric($value)
        ? number_format((float) $value, $decimals, $isTr ? ',' : '.', $isTr ? '.' : ',')
        : '—';
    $moneyMicros = function ($value) use ($currency, $isTr) {
        if (! is_numeric($value)) return '—';
        $amount = (float) $value / 1000000;
        $formatted = number_format($amount, 2, $isTr ? ',' : '.', $isTr ? '.' : ',');
        return trim($formatted.' '.$currency);
    };

    $campaignNames = collect();
    foreach (collect($campaignRows ?? []) as $campaignRow) {
        $row = is_array($campaignRow) ? $campaignRow : (is_object($campaignRow) ? (array) $campaignRow : []);
        $id = isset($row['id']) && is_scalar($row['id']) ? (string) $row['id'] : '';
        $name = isset($row['name']) && is_scalar($row['name']) ? (string) $row['name'] : '';
        if ($id !== '' && $name !== '') $campaignNames->put($id, $name);
    }
    foreach (collect(data_get($professional, 'campaign_options', [])) as $campaignOption) {
        $row = is_array($campaignOption) ? $campaignOption : (is_object($campaignOption) ? (array) $campaignOption : []);
        $id = isset($row['id']) && is_scalar($row['id']) ? (string) $row['id'] : '';
        $name = isset($row['name']) && is_scalar($row['name']) ? (string) $row['name'] : '';
        if ($id !== '' && $name !== '' && ! $campaignNames->has($id)) $campaignNames->put($id, $name);
    }

    $recommendationLabelsTr = [
        'PERFORMANCE_MAX_OPT_IN' => 'Performance Max’a geçişi değerlendirin',
        'DYNAMIC_IMAGE_EXTENSION_OPT_IN' => 'Dinamik görsel öğelerini etkinleştirin',
        'SET_TARGET_CPA' => 'Hedef EBM belirleyin',
        'TARGET_CPA_OPT_IN' => 'Hedef EBM teklif stratejisini değerlendirin',
        'DISPLAY_EXPANSION_OPT_IN' => 'Görüntülü Reklam Ağı genişletmesini değerlendirin',
        'SEARCH_PARTNERS_OPT_IN' => 'Arama Ağı iş ortaklarını değerlendirin',
        'CALL_ASSET' => 'Telefon araması öğesi ekleyin',
        'CALLOUT_ASSET' => 'Açıklama metni öğeleri ekleyin',
        'SITELINK_ASSET' => 'Site bağlantısı öğeleri ekleyin',
        'IMAGE_ASSET' => 'Görsel öğeleri ekleyin',
        'RESPONSIVE_SEARCH_AD' => 'Duyarlı arama reklamlarını iyileştirin',
        'IMPROVE_PERFORMANCE_MAX_AD_STRENGTH' => 'Performance Max reklam gücünü iyileştirin',
        'MAXIMIZE_CONVERSIONS_OPT_IN' => 'Dönüşümleri artırma teklif stratejisini değerlendirin',
        'MAXIMIZE_CONVERSION_VALUE_OPT_IN' => 'Dönüşüm değerini artırma teklif stratejisini değerlendirin',
        'TARGET_ROAS_OPT_IN' => 'Hedef ROAS teklif stratejisini değerlendirin',
        'KEYWORD' => 'Yeni anahtar kelime fırsatlarını değerlendirin',
        'USE_BROAD_MATCH_KEYWORD' => 'Geniş eşlemeli anahtar kelimeleri değerlendirin',
        'REMOVE_REDUNDANT_KEYWORDS' => 'Gereksiz anahtar kelimeleri kaldırmayı değerlendirin',
        'FORECASTING_CAMPAIGN_BUDGET' => 'Kampanya bütçesini yeniden değerlendirin',
        'CAMPAIGN_BUDGET' => 'Kampanya bütçesini yeniden değerlendirin',
    ];
    $recommendationLabelsEn = [
        'PERFORMANCE_MAX_OPT_IN' => 'Consider moving to Performance Max',
        'DYNAMIC_IMAGE_EXTENSION_OPT_IN' => 'Enable dynamic image assets',
        'SET_TARGET_CPA' => 'Set a target CPA',
        'TARGET_CPA_OPT_IN' => 'Consider Target CPA bidding',
        'DISPLAY_EXPANSION_OPT_IN' => 'Consider Display Network expansion',
        'SEARCH_PARTNERS_OPT_IN' => 'Consider Search Partners',
        'CALL_ASSET' => 'Add call assets',
        'CALLOUT_ASSET' => 'Add callout assets',
        'SITELINK_ASSET' => 'Add sitelink assets',
        'IMAGE_ASSET' => 'Add image assets',
        'RESPONSIVE_SEARCH_AD' => 'Improve responsive search ads',
        'IMPROVE_PERFORMANCE_MAX_AD_STRENGTH' => 'Improve Performance Max ad strength',
        'MAXIMIZE_CONVERSIONS_OPT_IN' => 'Consider Maximize Conversions bidding',
        'MAXIMIZE_CONVERSION_VALUE_OPT_IN' => 'Consider Maximize Conversion Value bidding',
        'TARGET_ROAS_OPT_IN' => 'Consider Target ROAS bidding',
        'KEYWORD' => 'Review new keyword opportunities',
        'USE_BROAD_MATCH_KEYWORD' => 'Consider broad match keywords',
        'REMOVE_REDUNDANT_KEYWORDS' => 'Consider removing redundant keywords',
        'FORECASTING_CAMPAIGN_BUDGET' => 'Review campaign budget',
        'CAMPAIGN_BUDGET' => 'Review campaign budget',
    ];

    $recommendationLabel = function (string $type) use ($isTr, $recommendationLabelsTr, $recommendationLabelsEn) {
        $known = $isTr ? $recommendationLabelsTr : $recommendationLabelsEn;
        if (isset($known[$type])) return $known[$type];
        $human = ucwords(strtolower(str_replace('_', ' ', $type)));
        return $human !== '' ? $human : ($isTr ? 'Google önerisi' : 'Google recommendation');
    };

    $normalizedRecommendations = collect(is_iterable($rawRecommendations) ? $rawRecommendations : [])
        ->map(function ($row) {
            $data = is_array($row) ? $row : (is_object($row) ? (array) $row : []);
            $metadata = $data['metadata'] ?? [];
            if (is_object($metadata)) $metadata = (array) $metadata;
            if (! is_array($metadata)) $metadata = [];

            return [
                'recommendation_type' => isset($data['recommendation_type']) && is_scalar($data['recommendation_type']) ? (string) $data['recommendation_type'] : '',
                'campaign_resource_name' => isset($data['campaign_resource_name']) && is_scalar($data['campaign_resource_name']) ? (string) $data['campaign_resource_name'] : '',
                'observed_date' => isset($data['observed_date']) && is_scalar($data['observed_date']) ? (string) $data['observed_date'] : '',
                'metadata' => $metadata,
            ];
        })
        ->filter(fn ($row) => $row['recommendation_type'] !== '');

    $latestObservedDate = $normalizedRecommendations->pluck('observed_date')->filter()->max();
    $currentRecommendations = ($latestObservedDate
            ? $normalizedRecommendations->where('observed_date', $latestObservedDate)
            : $normalizedRecommendations)
        ->unique(fn ($row) => $row['recommendation_type'].'|'.($row['campaign_resource_name'] ?: 'account'))
        ->sortBy(fn ($row) => $row['recommendation_type'].'|'.$row['campaign_resource_name'])
        ->values();

    $campaignLevelCount = $currentRecommendations->filter(fn ($row) => $row['campaign_resource_name'] !== '')->count();
    $accountLevelCount = $currentRecommendations->count() - $campaignLevelCount;
@endphp

<div class="space-y-5">
    <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-600 dark:text-brand-400">{{ $isTr ? 'OPTİMİZASYON MERKEZİ' : 'OPTIMIZATION CENTER' }}</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Ne değiştirilmeli, ne sadece izlenmeli?' : 'What should change, and what should only be monitored?' }}</h2>
            <p class="mt-1 max-w-4xl text-sm text-gray-500 dark:text-gray-400">
                {{ $isTr
                    ? 'Google’ın güncel provider önerilerini görün, etkisini okuyun ve MOXDOP karar zincirinden ayrı değerlendirin. Bu ekran Google Ads hesabında otomatik değişiklik yapmaz.'
                    : 'Review Google’s current provider recommendations and their evidence separately from the MOXDOP decision chain. This screen never makes automatic changes in Google Ads.' }}
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ url('/findings') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">{{ $isTr ? 'Bulgular' : 'Findings' }}</a>
            <a href="{{ url('/recommendations') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">{{ $isTr ? 'Öneriler' : 'Recommendations' }}</a>
            <a href="{{ url('/tasks') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-semibold text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:text-brand-400 dark:ring-brand-800 dark:hover:bg-brand-500/10">{{ $isTr ? 'İşler' : 'Tasks' }}</a>
        </div>
    </div>

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $isTr ? 'Güncel öneri' : 'Current recommendations' }}</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $currentRecommendations->count() }}</p>
            <p class="mt-1 text-xs text-gray-400">{{ $isTr ? 'Tekilleştirilmiş provider önerileri' : 'Deduplicated provider recommendations' }}</p>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $isTr ? 'Snapshot' : 'Snapshot' }}</p>
            <p class="mt-2 text-lg font-semibold text-gray-900 dark:text-white">{{ $latestObservedDate ?: '—' }}</p>
            <p class="mt-1 text-xs text-gray-400">{{ $isTr ? 'Yalnız en güncel gözlem' : 'Latest observation only' }}</p>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $isTr ? 'Kampanya düzeyi' : 'Campaign level' }}</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $campaignLevelCount }}</p>
            <p class="mt-1 text-xs text-gray-400">{{ $isTr ? 'Belirli kampanyaya bağlı' : 'Attached to a campaign' }}</p>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $isTr ? 'Hesap düzeyi' : 'Account level' }}</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $accountLevelCount }}</p>
            <p class="mt-1 text-xs text-gray-400">{{ $isTr ? 'Tüm hesaba ilişkin' : 'Applies to the account' }}</p>
        </div>
    </section>

    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-4 py-4 dark:border-gray-800">
            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Google provider önerileri' : 'Google provider recommendations' }}</h3>
                @if ($boundary !== '')
                    <p class="mt-1 max-w-3xl text-xs text-gray-500 dark:text-gray-400">{{ $boundary }}</p>
                @endif
            </div>
            <span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700 dark:bg-blue-500/10 dark:text-blue-300">
                {{ $currentRecommendations->count() }} {{ $isTr ? 'güncel öneri' : 'current recommendations' }}
            </span>
        </div>

        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($currentRecommendations as $row)
                @php
                    $type = $row['recommendation_type'];
                    $resource = $row['campaign_resource_name'];
                    $metadata = $row['metadata'];
                    $campaignId = null;
                    if ($resource !== '' && preg_match('~/campaigns/(\d+)$~', $resource, $matches)) $campaignId = $matches[1];
                    $campaignLabel = $campaignId
                        ? ($campaignNames->get($campaignId) ?: (($isTr ? 'Kampanya #' : 'Campaign #').$campaignId))
                        : ($isTr ? 'Hesap düzeyi' : 'Account level');

                    $impact = $metadata['impact'] ?? [];
                    if (is_object($impact)) $impact = (array) $impact;
                    if (! is_array($impact)) $impact = [];
                    $baseMetrics = data_get($impact, 'baseMetrics', []);
                    $potentialMetrics = data_get($impact, 'potentialMetrics', []);
                    if (is_object($baseMetrics)) $baseMetrics = (array) $baseMetrics;
                    if (is_object($potentialMetrics)) $potentialMetrics = (array) $potentialMetrics;
                    if (! is_array($baseMetrics)) $baseMetrics = [];
                    if (! is_array($potentialMetrics)) $potentialMetrics = [];

                    $metricRows = [
                        ['key' => 'impressions', 'label' => $isTr ? 'Gösterim' : 'Impressions', 'format' => fn ($v) => $number($v)],
                        ['key' => 'clicks', 'label' => $isTr ? 'Tıklama' : 'Clicks', 'format' => fn ($v) => $number($v)],
                        ['key' => 'costMicros', 'label' => $isTr ? 'Maliyet' : 'Cost', 'format' => fn ($v) => $moneyMicros($v)],
                        ['key' => 'conversions', 'label' => $isTr ? 'Dönüşüm' : 'Conversions', 'format' => fn ($v) => $number($v, 2)],
                        ['key' => 'conversionsValue', 'label' => $isTr ? 'Dönüşüm değeri' : 'Conversion value', 'format' => fn ($v) => $number($v, 2)],
                    ];
                    $visibleBaseMetrics = collect($metricRows)->filter(fn ($metric) => array_key_exists($metric['key'], $baseMetrics) && is_numeric($baseMetrics[$metric['key']]));
                    $visiblePotentialMetrics = collect($metricRows)->filter(fn ($metric) => array_key_exists($metric['key'], $potentialMetrics) && is_numeric($potentialMetrics[$metric['key']]));
                    $hasDistinctPotential = $visiblePotentialMetrics->isNotEmpty() && $potentialMetrics != $baseMetrics;
                    $autoApplied = filter_var($metadata['automatically_applied'] ?? false, FILTER_VALIDATE_BOOLEAN);
                    $moxdopRecommendation = filter_var($metadata['moxdop_recommendation'] ?? false, FILTER_VALIDATE_BOOLEAN);
                @endphp

                <article class="px-4 py-5">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $recommendationLabel($type) }}</h4>
                                <span class="rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700 dark:bg-blue-500/10 dark:text-blue-300">Google</span>
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ $autoApplied ? ($isTr ? 'Otomatik uygulanıyor' : 'Auto-applied') : ($isTr ? 'Otomatik uygulanmıyor' : 'Not auto-applied') }}</span>
                                <span class="rounded-full {{ $moxdopRecommendation ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' }} px-2 py-0.5 text-[11px] font-medium">{{ $moxdopRecommendation ? ($isTr ? 'MOXDOP tarafından doğrulandı' : 'Validated by MOXDOP') : ($isTr ? 'MOXDOP doğrulaması bekliyor' : 'Awaiting MOXDOP validation') }}</span>
                            </div>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ $campaignLabel }}
                                @if ($campaignId)<span class="text-gray-400">· ID {{ $campaignId }}</span>@endif
                                @if ($row['observed_date'] !== '')<span class="text-gray-400">· {{ $row['observed_date'] }}</span>@endif
                            </p>

                            @if ($visibleBaseMetrics->isNotEmpty())
                                <div class="mt-3 rounded-xl bg-gray-50 p-3 dark:bg-white/[0.025]">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ $isTr ? 'Google etki tahmini · mevcut' : 'Google impact estimate · current' }}</p>
                                    <div class="mt-2 flex flex-wrap gap-x-5 gap-y-2">
                                        @foreach ($visibleBaseMetrics as $metric)
                                            <div>
                                                <p class="text-[11px] text-gray-400">{{ $metric['label'] }}</p>
                                                <p class="mt-0.5 text-sm font-semibold tabular-nums text-gray-800 dark:text-gray-200">{{ $metric['format']($baseMetrics[$metric['key']]) }}</p>
                                            </div>
                                        @endforeach
                                    </div>
                                    @if ($hasDistinctPotential)
                                        <div class="mt-3 border-t border-gray-200 pt-3 dark:border-gray-800">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ $isTr ? 'Google etki tahmini · potansiyel' : 'Google impact estimate · potential' }}</p>
                                            <div class="mt-2 flex flex-wrap gap-x-5 gap-y-2">
                                                @foreach ($visiblePotentialMetrics as $metric)
                                                    <div>
                                                        <p class="text-[11px] text-gray-400">{{ $metric['label'] }}</p>
                                                        <p class="mt-0.5 text-sm font-semibold tabular-nums text-gray-800 dark:text-gray-200">{{ $metric['format']($potentialMetrics[$metric['key']]) }}</p>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @else
                                <p class="mt-3 text-xs text-gray-400">{{ $isTr ? 'Google bu öneri için sayısal etki tahmini sağlamadı.' : 'Google did not provide a numeric impact estimate for this recommendation.' }}</p>
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <div class="px-6 py-10 text-center">
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Aktif Google recommendation snapshotı yok' : 'No active Google recommendation snapshot' }}</h4>
                    <p class="mx-auto mt-2 max-w-2xl text-sm text-gray-500 dark:text-gray-400">
                        {{ $isTr
                            ? 'Bu durum sıfır öneri olduğu anlamına gelebilir veya recommendation datasetinin henüz materialize edilmemiş olabileceğini gösterebilir. MOXDOP burada öneri uydurmaz.'
                            : 'This can mean there are no current provider recommendations or that the recommendation dataset has not been materialized yet. MOXDOP does not fabricate suggestions here.' }}
                    </p>
                </div>
            @endforelse
        </div>
    </section>

    <section class="grid gap-3 lg:grid-cols-3">
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">1 · {{ $isTr ? 'Gözlemle' : 'Observe' }}</p>
            <h3 class="mt-2 font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Provider gerçeği' : 'Provider truth' }}</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Google recommendation yalnızca Google’ın önerisidir; MOXDOP bulgusu değildir.' : 'A Google recommendation is only a provider suggestion; it is not a MOXDOP finding.' }}</p>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">2 · {{ $isTr ? 'Teşhis et' : 'Diagnose' }}</p>
            <h3 class="mt-2 font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'MOXDOP kararı' : 'MOXDOP decision' }}</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'İş hedefi ve kanıt yeterliyse Bulgu → Öneri zincirine taşınır.' : 'When business context and evidence are sufficient, the issue moves into the Finding → Recommendation chain.' }}</p>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">3 · {{ $isTr ? 'Uygula' : 'Execute' }}</p>
            <h3 class="mt-2 font-semibold text-gray-900 dark:text-white">Google Ads</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Değişiklik Google Ads tarafında uygulanır; MOXDOP şimdilik gözlemler, teşhis eder ve işi yönetir.' : 'The change is executed in Google Ads; MOXDOP currently observes, diagnoses and manages the work.' }}</p>
        </div>
    </section>

    <div class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20">
        <strong>{{ $isTr ? 'Karar sınırı:' : 'Decision boundary:' }}</strong>
        {{ $isTr
            ? 'Bütçe artırma, teklif stratejisi değiştirme veya başka bir Google Ads mutasyonu bu ekrandan otomatik uygulanmaz.'
            : 'Budget increases, bidding-strategy changes and other Google Ads mutations are never auto-applied from this screen.' }}
    </div>
</div>
