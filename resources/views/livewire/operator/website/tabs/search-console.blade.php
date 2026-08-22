@php
    $gsc = is_array($gscAnalysis ?? null) ? $gscAnalysis : [];
    $formatNumber = static fn ($value): string => $value === null ? '—' : number_format((float) $value, 0, ',', '.');
    $formatPercent = static fn ($value, int $precision = 1): string => $value === null ? '—' : number_format((float) $value, $precision, ',', '.').'%';
    $formatPosition = static fn ($value): string => $value === null ? '—' : number_format((float) $value, 1, ',', '.');
    $localeTr = app()->getLocale() === 'tr';
    $sections = [
        'overview' => $localeTr ? 'Genel Bakış' : 'Overview',
        'queries' => $localeTr ? 'Sorgular' : 'Queries',
        'pages' => $localeTr ? 'Sayfalar' : 'Pages',
        'opportunities' => $localeTr ? 'Fırsatlar' : 'Opportunities',
        'audience' => $localeTr ? 'Kitle & Google Yüzeyleri' : 'Audience & Google Surfaces',
        'index' => $localeTr ? 'Google Index Sağlığı' : 'Google Index Health',
        'ai' => 'AI & Search Features',
        'actions' => $localeTr ? 'Bulgular & Aksiyonlar' : 'Findings & Actions',
    ];
    $metricLabels = [
        'clicks' => __('website_gsc.clicks'),
        'impressions' => __('website_gsc.impressions'),
        'ctr' => __('website_gsc.ctr'),
        'position' => __('website_gsc.position'),
    ];
    $surfaceLabels = [
        'web' => __('website_gsc.surface_web'),
        'image' => __('website_gsc.surface_image'),
        'video' => __('website_gsc.surface_video'),
        'news' => __('website_gsc.surface_news'),
        'discover' => __('website_gsc.surface_discover'),
        'googleNews' => __('website_gsc.surface_google_news'),
    ];
    $riskLabels = [
        'traffic_drop' => __('website_gsc.risk_traffic_drop'),
        'visibility_drop' => __('website_gsc.risk_visibility_drop'),
        'ctr_drop' => __('website_gsc.risk_ctr_drop'),
        'position_deterioration' => __('website_gsc.risk_position_deterioration'),
        'multiple_page_decline' => __('website_gsc.risk_multiple_page_decline'),
        'sitemap_errors' => __('website_gsc.risk_sitemap_errors'),
        'canonical_mismatch' => __('website_gsc.risk_canonical_mismatch'),
    ];
    $coverageDays = null;
    if (! empty($gsc['coverage']['start']) && ! empty($gsc['coverage']['end'])) {
        try {
            $coverageDays = \Carbon\CarbonImmutable::parse($gsc['coverage']['start'])
                ->diffInDays(\Carbon\CarbonImmutable::parse($gsc['coverage']['end'])) + 1;
        } catch (\Throwable) {
            $coverageDays = null;
        }
    }
    $openFindings = $data['findings']['open'] ?? collect();
    $recommendations = $data['recommendations'] ?? collect();
@endphp

<style>
    [wire\:click="refreshData"],
    [wire\:click="runDiagnosis"] { display: none !important; }
    [x-cloak] { display: none !important; }
</style>

@if (! ($gsc['connected'] ?? false))
    <section class="rounded-2xl bg-white p-6 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.connect_title') }}</h2>
                <p class="mt-1 max-w-2xl text-sm text-gray-500 dark:text-gray-400">{{ __('website_gsc.connect_body') }}</p>
            </div>
            <a href="{{ route('operator.asset.sources', ['assetId' => $asset->id]) }}" wire:navigate class="inline-flex shrink-0 items-center justify-center rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600">{{ __('operator.website.actions.data_sources') }}</a>
        </div>
    </section>
@elseif (! ($gsc['has_data'] ?? false))
    <section class="rounded-2xl bg-white p-6 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.no_data_title') }}</h2>
                <p class="mt-1 max-w-2xl text-sm text-gray-500 dark:text-gray-400">{{ __('website_gsc.no_data_body') }}</p>
            </div>
            <div class="text-right text-xs text-gray-400">
                <p>{{ $gsc['property_id'] ?? '—' }}</p>
                <p class="mt-1">{{ __('website_gsc.coverage') }} · {{ $gsc['coverage']['start'] ?? '—' }} → {{ $gsc['coverage']['end'] ?? '—' }}</p>
            </div>
        </div>
    </section>
@else
    <div x-data="{ section: 'overview', show(name) { this.section = name; this.$nextTick(() => { window.dispatchEvent(new Event('resize')); setTimeout(() => window.dispatchEvent(new Event('resize')), 80); }); } }" class="space-y-5">
        <section class="overflow-hidden rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex flex-col gap-4 px-5 py-5 xl:flex-row xl:items-start xl:justify-between">
                <div class="min-w-0">
                    <div class="flex items-start gap-3">
                        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 18V9"></path><path d="M10 18V5"></path><path d="M16 18v-7"></path><path d="M22 18V2"></path></svg>
                        </span>
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.title') }}</h2>
                            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ __('website_gsc.subtitle') }}</p>
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="rounded-full bg-emerald-50 px-2.5 py-1 font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ ($gsc['property_type'] ?? '') === 'domain' ? 'Domain Property' : 'URL Prefix' }}</span>
                    <span class="max-w-[360px] truncate rounded-full bg-gray-50 px-2.5 py-1 font-medium text-gray-600 dark:bg-white/[0.04] dark:text-gray-300">{{ $gsc['property_id'] }}</span>
                </div>
            </div>
            <div class="grid border-t border-gray-100 dark:border-gray-800 sm:grid-cols-2 xl:grid-cols-4 xl:divide-x xl:divide-gray-100 dark:xl:divide-gray-800">
                <div class="px-5 py-3.5"><p class="text-[11px] uppercase tracking-wide text-gray-400">{{ __('website_gsc.coverage') }}</p><p class="mt-1 text-sm font-medium text-gray-800 dark:text-gray-200">{{ $gsc['coverage']['start'] ?? '—' }} → {{ $gsc['coverage']['end'] ?? '—' }}</p></div>
                <div class="border-t border-gray-100 px-5 py-3.5 dark:border-gray-800 sm:border-t-0"><p class="text-[11px] uppercase tracking-wide text-gray-400">{{ $localeTr ? 'Toplanan gün' : 'Collected days' }}</p><p class="mt-1 text-sm font-medium text-gray-800 dark:text-gray-200">{{ $coverageDays !== null ? number_format($coverageDays, 0, ',', '.') : '—' }}</p></div>
                <div class="border-t border-gray-100 px-5 py-3.5 dark:border-gray-800 xl:border-t-0"><p class="text-[11px] uppercase tracking-wide text-gray-400">{{ __('website_gsc.latest_data') }}</p><p class="mt-1 text-sm font-medium text-gray-800 dark:text-gray-200">{{ $gsc['coverage']['end'] ?? '—' }}</p></div>
                <div class="border-t border-gray-100 px-5 py-3.5 dark:border-gray-800 xl:border-t-0"><p class="text-[11px] uppercase tracking-wide text-gray-400">{{ $localeTr ? 'Veri durumu' : 'Data status' }}</p><p class="mt-1 text-sm font-medium {{ ($gsc['period']['truncated_to_available_data'] ?? false) ? 'text-amber-600 dark:text-amber-300' : 'text-emerald-600 dark:text-emerald-300' }}">{{ ($gsc['period']['truncated_to_available_data'] ?? false) ? ($localeTr ? 'Kısmi dönem' : 'Partial period') : ($localeTr ? 'Hazır' : 'Ready') }}</p></div>
            </div>
        </section>

        <nav class="overflow-x-auto rounded-2xl bg-white p-2 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" aria-label="Search Console decision center">
            <div class="flex min-w-max gap-1">
                @foreach ($sections as $key => $label)
                    <button type="button" @click="show('{{ $key }}')" :class="section === '{{ $key }}' ? 'bg-brand-50 text-brand-700 ring-brand-100 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/20' : 'text-gray-500 ring-transparent hover:bg-gray-50 hover:text-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.04] dark:hover:text-gray-200'" class="rounded-xl px-3.5 py-2 text-sm font-medium ring-1 ring-inset transition">{{ $label }}</button>
                @endforeach
            </div>
        </nav>

        @if ($gsc['period']['truncated_to_available_data'] ?? false)
            <div class="rounded-xl bg-blue-50 px-4 py-3 text-sm text-blue-800 ring-1 ring-inset ring-blue-100 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20">
                {{ $localeTr ? 'Search Console kesin verisi' : 'Final Search Console data is available through' }} {{ $gsc['period']['end'] }}{{ $localeTr ? ' tarihine kadar mevcut. Seçili dönem bu tarihe kadar analiz edildi.' : '. The selected period was analyzed only through this date.' }}
            </div>
        @endif

        {{-- 1. Genel Bakış --}}
        <div x-show="section === 'overview'" x-cloak class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($gsc['metrics'] as $metric)
                    @php
                        $key = $metric['key'];
                        $value = $metric['value'];
                        $delta = $metric['delta'];
                        $display = $key === 'ctr' ? $formatPercent($value) : ($key === 'position' ? $formatPosition($value) : $formatNumber($value));
                        $yoyKey = $key === 'position' ? 'position_improvement' : ($key === 'ctr' ? 'ctr_pp' : $key);
                        $yoy = $gsc['yoy_metrics'][$yoyKey] ?? null;
                    @endphp
                    <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $metricLabels[$key] ?? $key }}</p>
                        <div class="mt-3 flex items-end justify-between gap-3">
                            <p class="text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ $display }}</p>
                            @if ($delta !== null)
                                <span @class(['rounded-full px-2 py-1 text-xs font-semibold tabular-nums','bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $delta > 0,'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => $delta < 0,'bg-gray-50 text-gray-600 dark:bg-white/[0.04] dark:text-gray-300' => $delta == 0])>{{ $delta > 0 ? '+' : '' }}{{ number_format($delta, 1, ',', '.') }}{{ $metric['delta_kind'] === 'percent' ? '%' : ($metric['delta_kind'] === 'pp' ? ' pp' : '') }}</span>
                            @endif
                        </div>
                        <p class="mt-3 text-xs text-gray-400">{{ __('website_gsc.yoy') }}: @if ($yoy === null) — @else {{ $yoy > 0 ? '+' : '' }}{{ number_format($yoy, 1, ',', '.') }}{{ in_array($key, ['clicks', 'impressions'], true) ? '%' : ($key === 'ctr' ? ' pp' : '') }} @endif</p>
                    </section>
                @endforeach
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                @foreach ([
                    [__('website_gsc.rising'), $gsc['health_summary']['rising_queries'] ?? 0],
                    [__('website_gsc.new'), $gsc['health_summary']['new_queries'] ?? 0],
                    [__('website_gsc.falling'), $gsc['health_summary']['falling_queries'] ?? 0],
                    [__('website_gsc.opportunities'), $gsc['health_summary']['opportunity_candidates'] ?? 0],
                    [__('website_gsc.cannibalization'), $gsc['health_summary']['cannibalization_candidates'] ?? 0],
                    [__('website_gsc.risks'), $gsc['health_summary']['risk_signals'] ?? 0],
                ] as [$label, $value])
                    <div class="rounded-xl bg-white px-4 py-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><p class="text-xs font-medium text-gray-500">{{ $label }}</p><p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($value, 0, ',', '.') }}</p></div>
                @endforeach
            </div>

            <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.trend') }}</h3><p class="mt-1 text-sm text-gray-500">{{ __('website_gsc.trend_hint') }}</p></div><span class="text-xs font-medium text-gray-400">{{ ($gsc['trend']['display_granularity'] ?? 'daily') === 'weekly' ? ($localeTr ? 'Haftalık görünüm' : 'Weekly view') : ($localeTr ? 'Günlük görünüm' : 'Daily view') }}</span></div>
                @if (! empty($gsc['trend']['labels']))<div class="mt-4 min-h-[330px]" data-chart='@json($gscCharts['trend'] ?? [])' aria-label="Search Console organic performance trend"></div>@else<p class="mt-6 text-sm text-gray-500">{{ $localeTr ? 'Bu dönem için trend verisi yok.' : 'No trend data is available for this period.' }}</p>@endif
            </section>

            <div class="grid gap-4 xl:grid-cols-3">
                <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800 xl:col-span-2">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.position_visibility') }}</h3><p class="mt-1 text-sm text-gray-500">{{ __('website_gsc.position_visibility_hint') }}</p>
                    @php $bands = $gsc['queries']['position_bands'] ?? []; @endphp
                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
                        @foreach (['top_3' => __('website_gsc.top_3'),'positions_4_10' => __('website_gsc.positions_4_10'),'positions_11_20' => __('website_gsc.positions_11_20'),'positions_21_50' => __('website_gsc.positions_21_50'),'positions_51_plus' => __('website_gsc.positions_51_plus')] as $bandKey => $label)
                            <div class="rounded-xl bg-gray-50 px-4 py-3 text-center dark:bg-white/[0.03]"><p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format((int)($bands[$bandKey] ?? 0), 0, ',', '.') }}</p><p class="mt-1 text-xs text-gray-500">{{ $label }}</p></div>
                        @endforeach
                    </div>
                </section>
                <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $localeTr ? 'Dönem özeti' : 'Period summary' }}</h3>
                    <div class="mt-4 space-y-3 text-sm"><div class="flex justify-between gap-4"><span class="text-gray-500">{{ $localeTr ? 'Yükselen sorgu' : 'Rising queries' }}</span><strong class="text-emerald-600">{{ $gsc['health_summary']['rising_queries'] ?? 0 }}</strong></div><div class="flex justify-between gap-4"><span class="text-gray-500">{{ $localeTr ? 'Düşen sorgu' : 'Falling queries' }}</span><strong class="text-rose-600">{{ $gsc['health_summary']['falling_queries'] ?? 0 }}</strong></div><div class="flex justify-between gap-4"><span class="text-gray-500">{{ $localeTr ? 'Yükselen sayfa' : 'Rising pages' }}</span><strong class="text-emerald-600">{{ $gsc['health_summary']['rising_pages'] ?? 0 }}</strong></div><div class="flex justify-between gap-4"><span class="text-gray-500">{{ $localeTr ? 'Düşen sayfa' : 'Falling pages' }}</span><strong class="text-rose-600">{{ $gsc['health_summary']['falling_pages'] ?? 0 }}</strong></div></div>
                </section>
            </div>
        </div>

        {{-- 2. Sorgular --}}
        <div x-show="section === 'queries'" x-cloak class="space-y-5">
            <section class="overflow-hidden rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.top_queries') }}</h3><p class="mt-1 text-sm text-gray-500">{{ $localeTr ? 'Google’da talep oluşturan sorguları tıklama, gösterim, CTR ve ortalama konum ile okuyun.' : 'Read demand-driving Google queries by clicks, impressions, CTR and average position.' }}</p></div>
                <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-400 dark:bg-white/[0.03]"><tr><th class="px-5 py-3 text-left">{{ __('website_gsc.query') }}</th><th class="px-3 py-3 text-right">{{ __('website_gsc.clicks_col') }}</th><th class="px-3 py-3 text-right">{{ __('website_gsc.impressions_col') }}</th><th class="px-3 py-3 text-right">{{ __('website_gsc.ctr_col') }}</th><th class="px-5 py-3 text-right">{{ __('website_gsc.position_col') }}</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">@forelse (array_slice($gsc['queries']['top'] ?? [], 0, 25) as $row)<tr><td class="max-w-[520px] truncate px-5 py-3 font-medium text-gray-800 dark:text-gray-200" title="{{ $row['query'] }}">{{ $row['query'] }}</td><td class="px-3 py-3 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ $formatNumber($row['clicks']) }}</td><td class="px-3 py-3 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ $formatNumber($row['impressions']) }}</td><td class="px-3 py-3 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ $formatPercent($row['ctr']) }}</td><td class="px-5 py-3 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ $formatPosition($row['position']) }}</td></tr>@empty<tr><td colspan="5" class="px-5 py-8 text-center text-gray-500">{{ $localeTr ? 'Sorgu verisi yok.' : 'No query data.' }}</td></tr>@endforelse</tbody></table></div>
            </section>

            <div class="grid gap-4 xl:grid-cols-4">
                @foreach ([['rising', __('website_gsc.rising'), 'text-emerald-600'],['falling', __('website_gsc.falling'), 'text-rose-600'],['new', __('website_gsc.new'), 'text-brand-600'],['lost', __('website_gsc.lost'), 'text-gray-600']] as [$movementKey, $movementLabel, $tone])
                    <section class="rounded-2xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><div class="flex items-center justify-between"><h3 class="text-sm font-semibold {{ $tone }}">{{ $movementLabel }}</h3><span class="text-xs text-gray-400">{{ count($gsc['queries'][$movementKey] ?? []) }}</span></div><div class="mt-3 space-y-2">@forelse (array_slice($gsc['queries'][$movementKey] ?? [], 0, 7) as $row)<div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]"><p class="truncate text-xs font-medium text-gray-800 dark:text-gray-200" title="{{ $row['query'] ?? '' }}">{{ $row['query'] ?? '—' }}</p><p class="mt-1 text-[11px] text-gray-500">{{ $formatNumber($row['clicks'] ?? 0) }} {{ $localeTr ? 'tıklama' : 'clicks' }} · {{ $formatNumber($row['impressions'] ?? 0) }} {{ $localeTr ? 'gösterim' : 'impressions' }}</p></div>@empty<p class="text-sm text-gray-500">—</p>@endforelse</div></section>
                @endforeach
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.brand_nonbrand') }}</h3><p class="mt-1 text-xs text-gray-500">{{ __('website_gsc.brand_hint') }}</p>@php $brand = $gsc['queries']['brand_split'] ?? []; @endphp<div class="mt-4 grid grid-cols-2 gap-3"><div class="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]"><p class="text-xs text-gray-500">{{ __('website_gsc.brand') }}</p><p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $formatNumber($brand['brand']['clicks'] ?? null) }}</p><p class="mt-1 text-xs text-gray-400">{{ $formatNumber($brand['brand']['impressions'] ?? null) }} {{ $localeTr ? 'gösterim' : 'impressions' }}</p></div><div class="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]"><p class="text-xs text-gray-500">{{ __('website_gsc.non_brand') }}</p><p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $formatNumber($brand['non_brand']['clicks'] ?? null) }}</p><p class="mt-1 text-xs text-gray-400">{{ $formatNumber($brand['non_brand']['impressions'] ?? null) }} {{ $localeTr ? 'gösterim' : 'impressions' }}</p></div></div></section>
                <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.topic_clusters') }}</h3><p class="mt-1 text-xs text-gray-500">{{ __('website_gsc.topic_clusters_hint') }}</p><div class="mt-4 grid gap-2 sm:grid-cols-2">@forelse ($gsc['queries']['topic_clusters'] ?? [] as $row)<div class="rounded-xl bg-gray-50 px-4 py-3 dark:bg-white/[0.03]"><div class="flex items-center justify-between gap-2"><p class="truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $row['label'] }}</p><span class="text-xs text-gray-400">{{ $row['query_count'] }}</span></div><p class="mt-1 text-xs text-gray-500">{{ $formatNumber($row['clicks']) }} · {{ $formatNumber($row['impressions']) }}</p></div>@empty<p class="text-sm text-gray-500">{{ $localeTr ? 'Konu kümesi üretmek için yeterli veri yok.' : 'Not enough data to produce topic clusters.' }}</p>@endforelse</div></section>
            </div>
        </div>

        {{-- 3. Sayfalar --}}
        <div x-show="section === 'pages'" x-cloak class="space-y-5">
            <section class="overflow-hidden rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.top_pages') }}</h3><p class="mt-1 text-sm text-gray-500">{{ $localeTr ? 'Google organik görünürlüğünü taşıyan sayfaları ve değişim yönünü izleyin.' : 'Track the pages carrying organic Google visibility and their direction of change.' }}</p></div><div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-400 dark:bg-white/[0.03]"><tr><th class="px-5 py-3 text-left">{{ __('website_gsc.page') }}</th><th class="px-3 py-3 text-right">{{ __('website_gsc.clicks_col') }}</th><th class="px-3 py-3 text-right">{{ __('website_gsc.impressions_col') }}</th><th class="px-3 py-3 text-right">{{ __('website_gsc.ctr_col') }}</th><th class="px-5 py-3 text-right">{{ __('website_gsc.position_col') }}</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">@forelse (array_slice($gsc['pages']['top'] ?? [], 0, 25) as $row)<tr><td class="max-w-[600px] truncate px-5 py-3 font-medium text-gray-800 dark:text-gray-200" title="{{ $row['page'] }}">{{ $row['page'] }}</td><td class="px-3 py-3 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ $formatNumber($row['clicks']) }}</td><td class="px-3 py-3 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ $formatNumber($row['impressions']) }}</td><td class="px-3 py-3 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ $formatPercent($row['ctr']) }}</td><td class="px-5 py-3 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ $formatPosition($row['position']) }}</td></tr>@empty<tr><td colspan="5" class="px-5 py-8 text-center text-gray-500">{{ $localeTr ? 'Sayfa verisi yok.' : 'No page data.' }}</td></tr>@endforelse</tbody></table></div></section>
            <div class="grid gap-4 xl:grid-cols-3">
                @foreach ([['rising', __('website_gsc.rising'), 'text-emerald-600'],['falling', __('website_gsc.falling'), 'text-rose-600']] as [$movementKey, $movementLabel, $tone])
                    <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><div class="flex items-center justify-between"><h3 class="text-sm font-semibold {{ $tone }}">{{ $movementLabel }}</h3><span class="text-xs text-gray-400">{{ count($gsc['pages'][$movementKey] ?? []) }}</span></div><div class="mt-3 space-y-2">@forelse (array_slice($gsc['pages'][$movementKey] ?? [], 0, 8) as $row)<div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]"><p class="truncate text-xs font-medium text-gray-800 dark:text-gray-200" title="{{ $row['page'] ?? '' }}">{{ $row['page'] ?? '—' }}</p><p class="mt-1 text-[11px] text-gray-500">{{ $formatNumber($row['clicks'] ?? 0) }} · {{ $formatNumber($row['impressions'] ?? 0) }}</p></div>@empty<p class="text-sm text-gray-500">—</p>@endforelse</div></section>
                @endforeach
                <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><div class="flex items-center justify-between"><h3 class="text-sm font-semibold text-amber-600">{{ __('website_gsc.content_decay') }}</h3><span class="text-xs text-gray-400">{{ count($gsc['pages']['content_decay'] ?? []) }}</span></div><div class="mt-3 space-y-2">@forelse (array_slice($gsc['pages']['content_decay'] ?? [], 0, 8) as $row)<div class="rounded-lg bg-amber-50/60 px-3 py-2 dark:bg-amber-500/5"><p class="truncate text-xs font-medium text-gray-800 dark:text-gray-200" title="{{ $row['page'] ?? '' }}">{{ $row['page'] ?? '—' }}</p><p class="mt-1 text-[11px] text-gray-500">{{ $localeTr ? 'Tıklama değişimi' : 'Click change' }}: {{ $row['click_delta'] ?? '—' }}</p></div>@empty<p class="text-sm text-gray-500">{{ $localeTr ? 'Belirgin içerik eskimesi sinyali yok.' : 'No material content decay signal.' }}</p>@endforelse</div></section>
            </div>
        </div>

        {{-- 4. Fırsatlar --}}
        <div x-show="section === 'opportunities'" x-cloak class="space-y-5">
            <section class="overflow-hidden rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.opportunities') }}</h3><p class="mt-1 text-sm text-gray-500">{{ __('website_gsc.opportunities_hint') }}</p></div><span class="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">{{ count($gsc['opportunities']['all'] ?? []) }} {{ $localeTr ? 'aday' : 'candidates' }}</span></div></div><div class="grid gap-0 lg:grid-cols-4 lg:divide-x lg:divide-gray-100 dark:lg:divide-gray-800">@foreach ([['low_ctr', __('website_gsc.low_ctr')],['top_10', __('website_gsc.top_10_opportunity')],['page_two', __('website_gsc.page_two')],['zero_click', __('website_gsc.zero_click')]] as [$bucketKey, $bucketLabel])<div class="border-t border-gray-100 p-4 first:border-t-0 dark:border-gray-800 lg:border-t-0"><div class="flex items-center justify-between gap-2"><h4 class="text-xs font-semibold text-gray-700 dark:text-gray-300">{{ $bucketLabel }}</h4><span class="text-xs text-gray-400">{{ count($gsc['opportunities'][$bucketKey] ?? []) }}</span></div><div class="mt-3 space-y-2">@forelse (array_slice($gsc['opportunities'][$bucketKey] ?? [], 0, 7) as $row)<div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="truncate text-xs font-semibold text-gray-800 dark:text-gray-200" title="{{ $row['query'] }}">{{ $row['query'] }}</p><p class="mt-1 text-[11px] text-gray-500">{{ $formatNumber($row['impressions']) }} · {{ $formatPercent($row['ctr']) }} · {{ $formatPosition($row['position']) }}</p></div>@empty<p class="text-sm text-gray-500">—</p>@endforelse</div></div>@endforeach</div></section>
            <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><div class="flex items-center justify-between"><div><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.cannibalization') }}</h3><p class="mt-1 text-xs text-gray-500">{{ __('website_gsc.cannibalization_hint') }}</p></div><span class="text-xs font-semibold text-gray-400">{{ count($gsc['cannibalization'] ?? []) }}</span></div><div class="mt-4 grid gap-3 xl:grid-cols-2">@forelse (array_slice($gsc['cannibalization'] ?? [], 0, 10) as $row)<details class="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]"><summary class="cursor-pointer text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $row['query'] }} · {{ $row['page_count'] }} URL</summary><div class="mt-3 space-y-2">@foreach ($row['pages'] as $page)<div class="flex gap-3 text-xs"><span class="min-w-0 flex-1 truncate text-gray-600 dark:text-gray-300" title="{{ $page['page'] }}">{{ $page['page'] }}</span><span class="shrink-0 tabular-nums text-gray-400">{{ $formatNumber($page['impressions']) }}</span></div>@endforeach</div></details>@empty<p class="text-sm text-gray-500">{{ $localeTr ? 'İnceleme gerektiren çoklu URL sinyali yok.' : 'No multi-URL review candidate.' }}</p>@endforelse</div></section>
        </div>

        {{-- 5. Kitle & Google Yüzeyleri --}}
        <div x-show="section === 'audience'" x-cloak class="space-y-5">
            <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><div><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.audience_surfaces') }}</h3><p class="mt-1 text-sm text-gray-500">{{ $localeTr ? 'Cihaz, ülke ve Google yüzeyi performansını ayrı sinyaller olarak okuyun.' : 'Read device, country and Google surface performance as separate signals.' }}</p></div><div class="mt-4 grid gap-4 xl:grid-cols-3"><div class="min-w-0 rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]"><h4 class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ __('website_gsc.devices') }}</h4>@if (array_sum(array_map(fn($r) => (int)($r['clicks'] ?? 0), $gsc['devices'] ?? [])) > 0)<div class="mt-2 min-h-[270px] w-full min-w-0" data-chart='@json($gscCharts['devices'] ?? [])' aria-label="Search Console visitor device distribution"></div>@else<p class="mt-4 text-sm text-gray-500">{{ $localeTr ? 'Veri yok.' : 'No data.' }}</p>@endif</div><div class="min-w-0 rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]"><h4 class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ __('website_gsc.countries') }}</h4>@if (! empty($gsc['countries']))<div class="mt-2 min-h-[290px] w-full min-w-0" data-chart='@json($gscCharts['countries'] ?? [])' aria-label="Search Console visitor countries"></div>@else<p class="mt-4 text-sm text-gray-500">{{ $localeTr ? 'Veri yok.' : 'No data.' }}</p>@endif</div><div class="min-w-0 rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]"><h4 class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ __('website_gsc.surfaces') }}</h4>@if (array_sum(array_map(fn($r) => (int)($r['clicks'] ?? 0), $gsc['surfaces'] ?? [])) > 0)<div class="mt-2 min-h-[270px] w-full min-w-0" data-chart='@json($gscCharts['surfaces'] ?? [])' aria-label="Search Console search surfaces"></div>@else<div class="mt-4 space-y-2">@foreach ($gsc['surfaces'] ?? [] as $row)<div class="flex items-center justify-between text-xs"><span class="text-gray-600 dark:text-gray-300">{{ $surfaceLabels[$row['search_type']] ?? $row['search_type'] }}</span><span class="tabular-nums text-gray-500">{{ $formatNumber($row['impressions']) }}</span></div>@endforeach</div>@endif</div></div></section>
            <div class="grid gap-4 xl:grid-cols-2"><section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $localeTr ? 'Sorgu × Ülke' : 'Query × Country' }}</h3><div class="mt-4 space-y-2">@forelse (array_slice($gsc['cross_dimensions']['query_country'] ?? [], 0, 12) as $row)<div class="flex items-center gap-3 rounded-lg bg-gray-50 px-3 py-2.5 text-xs dark:bg-white/[0.03]"><span class="min-w-0 flex-1 truncate font-medium text-gray-800 dark:text-gray-200" title="{{ $row['query'] }}">{{ $row['query'] }}</span><span class="shrink-0 uppercase text-gray-400">{{ $row['country'] }}</span><span class="shrink-0 tabular-nums text-gray-500">{{ $formatNumber($row['impressions']) }}</span></div>@empty<p class="text-sm text-gray-500">—</p>@endforelse</div></section><section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $localeTr ? 'Sayfa × Cihaz' : 'Page × Device' }}</h3><div class="mt-4 space-y-2">@forelse (array_slice($gsc['cross_dimensions']['page_device'] ?? [], 0, 12) as $row)<div class="flex items-center gap-3 rounded-lg bg-gray-50 px-3 py-2.5 text-xs dark:bg-white/[0.03]"><span class="min-w-0 flex-1 truncate font-medium text-gray-800 dark:text-gray-200" title="{{ $row['page'] }}">{{ $row['page'] }}</span><span class="shrink-0 text-gray-400">{{ ucfirst($row['device']) }}</span><span class="shrink-0 tabular-nums text-gray-500">{{ $formatNumber($row['clicks']) }}</span></div>@empty<p class="text-sm text-gray-500">—</p>@endforelse</div></section></div>
            <section class="overflow-hidden rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.search_appearance') }}</h3><p class="mt-1 text-xs text-gray-500">{{ __('website_gsc.search_appearance_hint') }}</p></div><div class="grid gap-0 md:grid-cols-2 xl:grid-cols-3">@forelse (array_slice($gsc['search_appearances'] ?? [], 0, 12) as $row)<div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800 md:border-r"><p class="truncate text-sm font-medium text-gray-800 dark:text-gray-200">{{ $row['appearance'] }}</p><p class="mt-2 text-xl font-semibold text-gray-900 dark:text-white">{{ $formatNumber($row['clicks']) }}</p><p class="mt-1 text-xs text-gray-500">{{ $formatNumber($row['impressions']) }} · {{ $formatPercent($row['ctr']) }}</p></div>@empty<div class="px-5 py-8 text-sm text-gray-500">{{ $localeTr ? 'Bu dönemde Search Appearance verisi yok.' : 'No Search Appearance data for this period.' }}</div>@endforelse</div></section>
        </div>

        {{-- 6. Google Index Sağlığı --}}
        <div x-show="section === 'index'" x-cloak class="space-y-5">
            <div class="grid gap-4 xl:grid-cols-2">
                <section class="overflow-hidden rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><div class="flex items-center justify-between"><div><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.sitemaps') }}</h3><p class="mt-1 text-xs text-gray-500">{{ $localeTr ? 'Merkezi Search Console sitemap snapshot verisi.' : 'Central Search Console sitemap snapshot data.' }}</p></div><span class="text-xs font-semibold text-gray-400">{{ count($gsc['sitemaps'] ?? []) }}</span></div></div><div class="divide-y divide-gray-100 dark:divide-gray-800">@forelse ($gsc['sitemaps'] ?? [] as $row)<div class="px-5 py-3.5"><div class="flex flex-wrap items-start justify-between gap-2"><div class="min-w-0"><p class="truncate text-sm font-medium text-gray-800 dark:text-gray-200" title="{{ $row['path'] }}">{{ $row['path'] }}</p><p class="mt-1 text-xs text-gray-500">{{ $row['submitted_urls'] }} {{ $localeTr ? 'gönderilen URL' : 'submitted URLs' }} · {{ $row['last_downloaded'] ?? '—' }}</p></div><span @class(['rounded-full px-2 py-0.5 text-xs font-medium','bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => ($row['errors'] + $row['warnings']) === 0,'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => $row['errors'] > 0,'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => $row['errors'] === 0 && $row['warnings'] > 0])>{{ $row['errors'] }} {{ $localeTr ? 'hata' : 'errors' }} · {{ $row['warnings'] }} {{ $localeTr ? 'uyarı' : 'warnings' }}</span></div></div>@empty<div class="px-5 py-8 text-sm text-gray-500">{{ $localeTr ? 'Sitemap snapshot verisi yok.' : 'No sitemap snapshot data.' }}</div>@endforelse</div></section>
                <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.index_health') }}</h3>@if ($gsc['index_health']['available'] ?? false)<div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4"><div class="rounded-xl bg-gray-50 p-3 text-center dark:bg-white/[0.03]"><p class="text-xl font-semibold text-gray-900 dark:text-white">{{ $gsc['index_health']['total'] }}</p><p class="text-[11px] text-gray-500">{{ $localeTr ? 'İncelenen' : 'Inspected' }}</p></div><div class="rounded-xl bg-gray-50 p-3 text-center dark:bg-white/[0.03]"><p class="text-xl font-semibold text-emerald-600">{{ $gsc['index_health']['indexable'] }}</p><p class="text-[11px] text-gray-500">PASS</p></div><div class="rounded-xl bg-gray-50 p-3 text-center dark:bg-white/[0.03]"><p class="text-xl font-semibold text-rose-600">{{ $gsc['index_health']['issues'] }}</p><p class="text-[11px] text-gray-500">{{ $localeTr ? 'Sorun' : 'Issues' }}</p></div><div class="rounded-xl bg-gray-50 p-3 text-center dark:bg-white/[0.03]"><p class="text-xl font-semibold text-amber-600">{{ $gsc['index_health']['canonical_mismatches'] }}</p><p class="text-[11px] text-gray-500">Canonical</p></div></div><div class="mt-4 space-y-2">@foreach (array_slice($gsc['url_inspection'] ?? [], 0, 10) as $row)<div class="rounded-lg bg-gray-50 p-3 text-xs dark:bg-white/[0.03]"><p class="truncate font-medium text-gray-800 dark:text-gray-200">{{ $row['page'] }}</p><p class="mt-1 text-gray-500">{{ $row['verdict'] ?? '—' }} · {{ $row['coverage_state'] ?? '—' }} · {{ $row['last_crawl_time'] ?? '—' }}</p>@if (! empty($row['google_canonical']) && ! empty($row['user_canonical']) && $row['google_canonical'] !== $row['user_canonical'])<p class="mt-1 text-amber-700 dark:text-amber-300">{{ __('website_gsc.canonical_mismatch') }}: Google → {{ $row['google_canonical'] }}</p>@endif</div>@endforeach</div>@else<div class="mt-4 rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]"><p class="text-sm text-gray-600 dark:text-gray-300">{{ __('website_gsc.inspection_unavailable') }}</p></div>@endif</section>
            </div>
            <div class="rounded-xl bg-blue-50 px-4 py-3 text-sm text-blue-800 ring-1 ring-inset ring-blue-100 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20">{{ $localeTr ? 'Bu alan Search Console’daki tam Page Indexing raporunu taklit etmez. Yalnızca merkezi havuzda gerçekten bulunan sitemap ve kontrollü URL Inspection snapshot verisini gösterir.' : 'This area does not imitate the full Search Console Page Indexing report. It only shows sitemap and controlled URL Inspection snapshots actually present in the central pool.' }}</div>
        </div>

        {{-- 7. AI & Search Features --}}
        <div x-show="section === 'ai'" x-cloak class="space-y-5">
            <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.ai_search') }}</h3><div class="mt-4 rounded-xl bg-violet-50 p-4 ring-1 ring-inset ring-violet-100 dark:bg-violet-500/10 dark:ring-violet-500/20"><p class="text-sm font-semibold text-violet-900 dark:text-violet-200">Google AI visibility</p><p class="mt-1 text-sm text-violet-800/80 dark:text-violet-300/80">{{ __('website_gsc.ai_unavailable') }}</p></div><p class="mt-3 text-xs text-gray-500">{{ $localeTr ? 'AI Overview / AI Mode için ayrı ve güvenilir provider dataset oluşmadan sayı üretilmez. Bu bölüm hazırdır; provider verisi geldiğinde merkezi veri havuzundan beslenecektir.' : 'No AI Overview / AI Mode number is generated without a separate reliable provider dataset. This section is ready to consume a future central provider dataset.' }}</p></section>
            <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><div class="flex items-start justify-between gap-4"><div><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $localeTr ? 'Zengin sonuç sinyalleri' : 'Rich-result signals' }}</h3><p class="mt-1 text-sm text-gray-500">{{ $localeTr ? 'Search Appearance, AI verisi değildir; ancak özel Google sonuç türlerinin gerçek performansını gösterir.' : 'Search Appearance is not AI data; it shows real performance of special Google result types.' }}</p></div><span class="rounded-full bg-gray-50 px-2.5 py-1 text-xs font-medium text-gray-500 dark:bg-white/[0.04]">{{ count($gsc['search_appearances'] ?? []) }}</span></div><div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">@forelse (array_slice($gsc['search_appearances'] ?? [], 0, 9) as $row)<div class="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]"><p class="truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $row['appearance'] }}</p><p class="mt-2 text-xl font-semibold text-gray-900 dark:text-white">{{ $formatNumber($row['clicks']) }}</p><p class="mt-1 text-xs text-gray-500">{{ $formatNumber($row['impressions']) }} · {{ $formatPercent($row['ctr']) }}</p></div>@empty<p class="text-sm text-gray-500">{{ $localeTr ? 'Search Appearance verisi yok.' : 'No Search Appearance data.' }}</p>@endforelse</div></section>
        </div>

        {{-- 8. Bulgular & Aksiyonlar --}}
        <div x-show="section === 'actions'" x-cloak class="space-y-5">
            <div class="grid gap-4 xl:grid-cols-2">
                <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><div class="flex items-center justify-between"><div><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.risks') }}</h3><p class="mt-1 text-sm text-gray-500">{{ $localeTr ? 'Merkezi Search Console verisinden üretilen inceleme sinyalleri.' : 'Review signals generated from central Search Console data.' }}</p></div><span class="text-xs font-semibold text-gray-400">{{ count($gsc['risks'] ?? []) }}</span></div><div class="mt-4 space-y-2">@forelse ($gsc['risks'] ?? [] as $risk)<div @class(['rounded-xl px-4 py-3 ring-1 ring-inset','bg-rose-50 text-rose-800 ring-rose-100 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20' => ($risk['severity'] ?? '') === 'high','bg-amber-50 text-amber-800 ring-amber-100 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20' => ($risk['severity'] ?? '') !== 'high'])><div class="flex items-center justify-between gap-3"><p class="text-sm font-semibold">{{ $riskLabels[$risk['type']] ?? $risk['type'] }}</p><span class="text-xs tabular-nums opacity-70">{{ is_numeric($risk['value'] ?? null) ? number_format((float) $risk['value'], 1, ',', '.') : ($risk['value'] ?? '—') }}</span></div></div>@empty<p class="text-sm text-gray-500">{{ __('website_gsc.no_risks') }}</p>@endforelse</div></section>
                <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $localeTr ? 'Findings → Recommendations → Tasks' : 'Findings → Recommendations → Tasks' }}</h3><p class="mt-1 text-sm text-gray-500">{{ $localeTr ? 'Search Console sinyali burada karar girdisidir. Doğrulanan sinyali bulguya, uygulanabilir öneriye ve takip edilebilir göreve taşıyın.' : 'A Search Console signal is a decision input here. Move validated signals into findings, actionable recommendations and trackable tasks.' }}</p><div class="mt-5 grid gap-3 sm:grid-cols-3"><a href="{{ route('operator.findings', ['asset' => $asset->id]) }}" wire:navigate class="rounded-xl bg-gray-50 p-4 ring-1 ring-inset ring-gray-100 transition hover:bg-gray-100 dark:bg-white/[0.03] dark:ring-gray-800"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $localeTr ? '1 · Bulgular' : '1 · Findings' }}</p><p class="mt-1 text-xs text-gray-500">{{ $localeTr ? 'Problemi kanıtla.' : 'Validate the problem.' }}</p></a><a href="{{ route('operator.recommendations', ['asset' => $asset->id]) }}" wire:navigate class="rounded-xl bg-brand-50 p-4 ring-1 ring-inset ring-brand-100 transition hover:bg-brand-100 dark:bg-brand-500/10 dark:ring-brand-500/20"><p class="text-sm font-semibold text-brand-800 dark:text-brand-200">{{ $localeTr ? '2 · Öneriler' : '2 · Recommendations' }}</p><p class="mt-1 text-xs text-brand-700/70 dark:text-brand-300/70">{{ $localeTr ? 'Ne yapılacağını belirle.' : 'Define what should be done.' }}</p></a><a href="{{ route('operator.tasks', ['asset' => $asset->id]) }}" wire:navigate class="rounded-xl bg-emerald-50 p-4 ring-1 ring-inset ring-emerald-100 transition hover:bg-emerald-100 dark:bg-emerald-500/10 dark:ring-emerald-500/20"><p class="text-sm font-semibold text-emerald-800 dark:text-emerald-200">{{ $localeTr ? '3 · Görevler' : '3 · Tasks' }}</p><p class="mt-1 text-xs text-emerald-700/70 dark:text-emerald-300/70">{{ $localeTr ? 'Uygula ve sonucu takip et.' : 'Execute and track the result.' }}</p></a></div></section>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                <section class="overflow-hidden rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><div class="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-gray-800"><div><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $localeTr ? 'Açık bulgular' : 'Open findings' }}</h3><p class="mt-1 text-xs text-gray-500">{{ $localeTr ? 'Bu Website varlığı için mevcut operasyon kayıtları.' : 'Current operational records for this Website asset.' }}</p></div><a href="{{ route('operator.findings', ['asset' => $asset->id]) }}" wire:navigate class="text-xs font-semibold text-brand-600">{{ $localeTr ? 'Tümü' : 'All' }}</a></div><div class="divide-y divide-gray-100 dark:divide-gray-800">@forelse ($openFindings->take(5) as $finding)<div class="px-5 py-4"><div class="flex items-start justify-between gap-3"><div><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $finding->title }}</p><p class="mt-1 text-xs text-gray-500">{{ $finding->summary }}</p></div><span class="shrink-0 text-[11px] font-semibold uppercase text-rose-600">{{ $finding->severity }}</span></div></div>@empty<div class="px-5 py-8 text-sm text-gray-500">{{ $localeTr ? 'Açık bulgu yok.' : 'No open findings.' }}</div>@endforelse</div></section>
                <section class="overflow-hidden rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><div class="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-gray-800"><div><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $localeTr ? 'Aktif öneriler' : 'Active recommendations' }}</h3><p class="mt-1 text-xs text-gray-500">{{ $localeTr ? 'Doğrulanmış bulgulardan aksiyona dönüşen öneriler.' : 'Recommendations that turn validated findings into action.' }}</p></div><a href="{{ route('operator.recommendations', ['asset' => $asset->id]) }}" wire:navigate class="text-xs font-semibold text-brand-600">{{ $localeTr ? 'Tümü' : 'All' }}</a></div><div class="divide-y divide-gray-100 dark:divide-gray-800">@forelse ($recommendations->take(5) as $recommendation)<div class="px-5 py-4"><div class="flex items-start justify-between gap-3"><div><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $recommendation->title }}</p><p class="mt-1 text-xs text-gray-500">{{ $recommendation->action }}</p></div><span class="shrink-0 text-[11px] font-semibold uppercase text-brand-600">{{ $recommendation->priority }}</span></div></div>@empty<div class="px-5 py-8 text-sm text-gray-500">{{ $localeTr ? 'Aktif öneri yok.' : 'No active recommendations.' }}</div>@endforelse</div></section>
            </div>

            <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"><div><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.data_quality') }}</h3><p class="mt-1 max-w-3xl text-sm text-gray-500">{{ __('website_gsc.central_only') }}</p></div><span class="rounded-full bg-gray-50 px-2.5 py-1 text-xs font-medium text-gray-500 dark:bg-white/[0.04]">{{ $localeTr ? 'Toplama yönetimi entegrasyonlarda' : 'Collection management lives in integrations' }}</span></div><dl class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><div><dt class="text-[11px] uppercase tracking-wide text-gray-400">{{ __('website_gsc.property') }}</dt><dd class="mt-1 break-all text-sm font-medium text-gray-800 dark:text-gray-200">{{ $gsc['property_id'] }}</dd></div><div><dt class="text-[11px] uppercase tracking-wide text-gray-400">External Resource</dt><dd class="mt-1 text-sm font-medium text-gray-800 dark:text-gray-200">#{{ $gsc['external_resource_id'] }}</dd></div><div><dt class="text-[11px] uppercase tracking-wide text-gray-400">Search types</dt><dd class="mt-1 text-sm font-medium text-gray-800 dark:text-gray-200">{{ implode(', ', $gsc['data_quality']['available_search_types'] ?? ['web']) }}</dd></div><div><dt class="text-[11px] uppercase tracking-wide text-gray-400">Site totals</dt><dd class="mt-1 text-sm font-medium text-gray-800 dark:text-gray-200">gsc_property_daily</dd></div></dl><div class="mt-4 grid gap-2 text-xs text-gray-500 lg:grid-cols-2"><p class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">{{ __('website_gsc.query_limits') }}</p><p class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">{{ __('website_gsc.position_visibility_hint') }}</p></div></section>
        </div>
    </div>
@endif
