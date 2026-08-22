@php
    $gsc = is_array($gscAnalysis ?? null) ? $gscAnalysis : [];
    $formatNumber = static fn ($value): string => $value === null ? '—' : number_format((float) $value, 0, ',', '.');
    $formatPercent = static fn ($value, int $precision = 1): string => $value === null ? '—' : number_format((float) $value, $precision, ',', '.').'%';
    $formatPosition = static fn ($value): string => $value === null ? '—' : number_format((float) $value, 1, ',', '.');
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
@endphp

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
    <div class="space-y-5">
        <section class="overflow-hidden rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex flex-col gap-4 px-5 py-5 xl:flex-row xl:items-start xl:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 18V9"></path><path d="M10 18V5"></path><path d="M16 18v-7"></path><path d="M22 18V2"></path></svg>
                        </span>
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.title') }}</h2>
                            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ __('website_gsc.subtitle') }}</p>
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="rounded-full bg-emerald-50 px-2.5 py-1 font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ ($gsc['property_type'] ?? '') === 'domain' ? 'Domain Property' : 'URL Prefix' }}</span>
                    <span class="max-w-[360px] truncate rounded-full bg-gray-50 px-2.5 py-1 font-medium text-gray-600 dark:bg-white/[0.04] dark:text-gray-300">{{ $gsc['property_id'] }}</span>
                </div>
            </div>
            <div class="grid border-t border-gray-100 dark:border-gray-800 sm:grid-cols-3 sm:divide-x sm:divide-gray-100 dark:sm:divide-gray-800">
                <div class="px-5 py-3.5"><p class="text-[11px] uppercase tracking-wide text-gray-400">{{ __('website_gsc.coverage') }}</p><p class="mt-1 text-sm font-medium text-gray-800 dark:text-gray-200">{{ $gsc['coverage']['start'] ?? '—' }} → {{ $gsc['coverage']['end'] ?? '—' }}</p></div>
                <div class="border-t border-gray-100 px-5 py-3.5 dark:border-gray-800 sm:border-t-0"><p class="text-[11px] uppercase tracking-wide text-gray-400">{{ __('website_gsc.latest_data') }}</p><p class="mt-1 text-sm font-medium text-gray-800 dark:text-gray-200">{{ $gsc['coverage']['end'] ?? '—' }}</p></div>
                <div class="border-t border-gray-100 px-5 py-3.5 dark:border-gray-800 sm:border-t-0"><p class="text-[11px] uppercase tracking-wide text-gray-400">{{ __('website_gsc.last_collected') }}</p><p class="mt-1 text-sm font-medium text-gray-800 dark:text-gray-200">{{ $gsc['coverage']['last_collected_at'] ? \Carbon\CarbonImmutable::parse($gsc['coverage']['last_collected_at'])->diffForHumans() : '—' }}</p></div>
            </div>
        </section>

        @if ($gsc['period']['truncated_to_available_data'] ?? false)
            <div class="rounded-xl bg-blue-50 px-4 py-3 text-sm text-blue-800 ring-1 ring-inset ring-blue-100 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20">
                Search Console kesin verisi {{ $gsc['period']['end'] }} tarihine kadar mevcut. Seçili dönem bu tarihe kadar analiz edildi.
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($gsc['metrics'] as $metric)
                @php
                    $key = $metric['key'];
                    $value = $metric['value'];
                    $delta = $metric['delta'];
                    $isPosition = $key === 'position';
                    $display = $key === 'ctr' ? $formatPercent($value) : ($isPosition ? $formatPosition($value) : $formatNumber($value));
                    $deltaGood = $delta !== null && $delta > 0;
                    $deltaBad = $delta !== null && $delta < 0;
                    $yoy = $gsc['yoy_metrics'][$key === 'position' ? 'position_improvement' : ($key === 'ctr' ? 'ctr_pp' : $key)] ?? null;
                @endphp
                <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $metricLabels[$key] ?? $key }}</p>
                    <div class="mt-3 flex items-end justify-between gap-3">
                        <p class="text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ $display }}</p>
                        @if ($delta !== null)
                            <span @class([
                                'rounded-full px-2 py-1 text-xs font-semibold tabular-nums',
                                'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $deltaGood,
                                'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => $deltaBad,
                                'bg-gray-50 text-gray-600 dark:bg-white/[0.04] dark:text-gray-300' => ! $deltaGood && ! $deltaBad,
                            ])>
                                @if ($metric['delta_kind'] === 'percent'){{ $delta > 0 ? '+' : '' }}{{ number_format($delta, 1, ',', '.') }}%
                                @elseif ($metric['delta_kind'] === 'pp'){{ $delta > 0 ? '+' : '' }}{{ number_format($delta, 1, ',', '.') }} pp
                                @else{{ $delta > 0 ? '+' : '' }}{{ number_format($delta, 1, ',', '.') }}
                                @endif
                            </span>
                        @endif
                    </div>
                    <div class="mt-3 flex items-center justify-between gap-2 text-xs text-gray-400">
                        <span>{{ $gsc['period']['comparison_label'] ?? __('website_gsc.change') }}</span>
                        <span class="tabular-nums">{{ __('website_gsc.yoy') }}: @if ($yoy === null) — @elseif ($key === 'ctr') {{ $yoy > 0 ? '+' : '' }}{{ number_format($yoy, 1, ',', '.') }} pp @elseif ($key === 'position') {{ $yoy > 0 ? '+' : '' }}{{ number_format($yoy, 1, ',', '.') }} @else {{ $yoy > 0 ? '+' : '' }}{{ number_format($yoy, 1, ',', '.') }}% @endif</span>
                    </div>
                </section>
            @endforeach
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
            @php
                $summaryCards = [
                    ['label' => __('website_gsc.rising'), 'value' => $gsc['health_summary']['rising_queries'] ?? 0, 'hint' => __('website_gsc.query_movements')],
                    ['label' => __('website_gsc.new'), 'value' => $gsc['health_summary']['new_queries'] ?? 0, 'hint' => __('website_gsc.query_movements')],
                    ['label' => __('website_gsc.falling'), 'value' => $gsc['health_summary']['falling_queries'] ?? 0, 'hint' => __('website_gsc.query_movements')],
                    ['label' => __('website_gsc.opportunities'), 'value' => $gsc['health_summary']['opportunity_candidates'] ?? 0, 'hint' => 'İnceleme adayı'],
                    ['label' => __('website_gsc.cannibalization'), 'value' => $gsc['health_summary']['cannibalization_candidates'] ?? 0, 'hint' => 'İnceleme adayı'],
                    ['label' => __('website_gsc.risks'), 'value' => $gsc['health_summary']['risk_signals'] ?? 0, 'hint' => 'Otomatik sinyal'],
                ];
            @endphp
            @foreach ($summaryCards as $card)
                <div class="rounded-xl bg-white px-4 py-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <p class="text-xs font-medium text-gray-500">{{ $card['label'] }}</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($card['value'], 0, ',', '.') }}</p>
                    <p class="mt-1 text-[11px] text-gray-400">{{ $card['hint'] }}</p>
                </div>
            @endforeach
        </div>

        <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.trend') }}</h3><p class="mt-1 text-sm text-gray-500">{{ __('website_gsc.trend_hint') }}</p></div>
                <span class="text-xs font-medium text-gray-400">{{ ($gsc['trend']['display_granularity'] ?? 'daily') === 'weekly' ? 'Haftalık görünüm' : 'Günlük görünüm' }}</span>
            </div>
            @if (! empty($gsc['trend']['labels']))
                <div class="mt-4 min-h-[330px]" data-chart='@json($gscCharts['trend'] ?? [])' aria-label="Search Console organic performance trend"></div>
            @else
                <p class="mt-6 text-sm text-gray-500">Bu dönem için trend verisi yok.</p>
            @endif
        </section>

        <section class="overflow-hidden rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.opportunities') }}</h3><p class="mt-1 text-sm text-gray-500">{{ __('website_gsc.opportunities_hint') }}</p></div><span class="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">{{ count($gsc['opportunities']['all'] ?? []) }} aday</span></div>
            </div>
            <div class="grid gap-0 lg:grid-cols-4 lg:divide-x lg:divide-gray-100 dark:lg:divide-gray-800">
                @php
                    $opportunityBuckets = [
                        ['key' => 'low_ctr', 'label' => __('website_gsc.low_ctr')],
                        ['key' => 'top_10', 'label' => __('website_gsc.top_10_opportunity')],
                        ['key' => 'page_two', 'label' => __('website_gsc.page_two')],
                        ['key' => 'zero_click', 'label' => __('website_gsc.zero_click')],
                    ];
                @endphp
                @foreach ($opportunityBuckets as $bucket)
                    <div class="border-t border-gray-100 p-4 first:border-t-0 dark:border-gray-800 lg:border-t-0">
                        <div class="flex items-center justify-between gap-2"><h4 class="text-xs font-semibold text-gray-700 dark:text-gray-300">{{ $bucket['label'] }}</h4><span class="text-xs tabular-nums text-gray-400">{{ count($gsc['opportunities'][$bucket['key']] ?? []) }}</span></div>
                        <div class="mt-3 space-y-2">
                            @forelse (array_slice($gsc['opportunities'][$bucket['key']] ?? [], 0, 5) as $row)
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                                    <p class="truncate text-xs font-semibold text-gray-800 dark:text-gray-200" title="{{ $row['query'] }}">{{ $row['query'] }}</p>
                                    <p class="mt-1 text-[11px] text-gray-500">{{ $formatNumber($row['impressions']) }} gösterim · {{ $formatPercent($row['ctr']) }} CTR · {{ $formatPosition($row['position']) }} konum</p>
                                </div>
                            @empty
                                <p class="text-xs text-gray-400">Bu sinyalde aday yok.</p>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-3">
            <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800 xl:col-span-2">
                <div><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.position_visibility') }}</h3><p class="mt-1 text-sm text-gray-500">{{ __('website_gsc.position_visibility_hint') }}</p></div>
                @php $bands = $gsc['queries']['position_bands'] ?? []; @endphp
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
                    @foreach ([
                        'top_3' => __('website_gsc.top_3'),
                        'positions_4_10' => __('website_gsc.positions_4_10'),
                        'positions_11_20' => __('website_gsc.positions_11_20'),
                        'positions_21_50' => __('website_gsc.positions_21_50'),
                        'positions_51_plus' => __('website_gsc.positions_51_plus'),
                    ] as $key => $label)
                        <div class="rounded-xl bg-gray-50 px-4 py-3 text-center dark:bg-white/[0.03]"><p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($bands[$key] ?? 0, 0, ',', '.') }}</p><p class="mt-1 text-xs text-gray-500">{{ $label }}</p></div>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-gray-400">{{ __('website_gsc.rows_note') }}</p>
            </section>

            <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.brand_nonbrand') }}</h3>
                <p class="mt-1 text-xs text-gray-500">{{ __('website_gsc.brand_hint') }}</p>
                @php $brandSplit = $gsc['queries']['brand_split'] ?? []; @endphp
                @if (($brandSplit['classification'] ?? 'unavailable') !== 'unavailable')
                    <div class="mt-4 space-y-3">
                        @foreach (['brand' => __('website_gsc.brand'), 'non_brand' => __('website_gsc.non_brand')] as $key => $label)
                            @php $row = $brandSplit[$key] ?? []; @endphp
                            <div class="rounded-xl bg-gray-50 px-4 py-3 dark:bg-white/[0.03]"><div class="flex items-center justify-between"><p class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $label }}</p><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $formatNumber($row['clicks'] ?? 0) }}</p></div><p class="mt-1 text-xs text-gray-500">{{ $formatNumber($row['impressions'] ?? 0) }} gösterim · {{ $formatPercent($row['ctr'] ?? null) }}</p></div>
                        @endforeach
                        <p class="text-[11px] text-gray-400">Terimler: {{ implode(', ', $brandSplit['terms'] ?? []) }}</p>
                    </div>
                @else
                    <p class="mt-4 text-sm text-gray-500">Marka sınıflandırması için yeterli marka/domain bilgisi yok.</p>
                @endif
            </section>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            @foreach ([['key' => 'queries', 'title' => __('website_gsc.top_queries'), 'dimension' => 'query'], ['key' => 'pages', 'title' => __('website_gsc.top_pages'), 'dimension' => 'page']] as $table)
                <section class="overflow-hidden rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $table['title'] }}</h3></div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-xs">
                            <thead class="bg-gray-50 text-gray-500 dark:bg-white/[0.03]"><tr><th class="px-4 py-2.5 font-medium">{{ $table['dimension'] === 'query' ? __('website_gsc.query') : __('website_gsc.page') }}</th><th class="px-3 py-2.5 text-right font-medium">{{ __('website_gsc.clicks_col') }}</th><th class="px-3 py-2.5 text-right font-medium">{{ __('website_gsc.impressions_col') }}</th><th class="px-3 py-2.5 text-right font-medium">{{ __('website_gsc.ctr_col') }}</th><th class="px-4 py-2.5 text-right font-medium">{{ __('website_gsc.position_col') }}</th></tr></thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @forelse (array_slice($gsc[$table['key']]['top'] ?? [], 0, 12) as $row)
                                    <tr><td class="max-w-[330px] truncate px-4 py-3 font-medium text-gray-800 dark:text-gray-200" title="{{ $row[$table['dimension']] }}">{{ $row[$table['dimension']] }}</td><td class="px-3 py-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ $formatNumber($row['clicks']) }}</td><td class="px-3 py-3 text-right tabular-nums text-gray-500">{{ $formatNumber($row['impressions']) }}</td><td class="px-3 py-3 text-right tabular-nums text-gray-500">{{ $formatPercent($row['ctr']) }}</td><td class="px-4 py-3 text-right tabular-nums text-gray-500">{{ $formatPosition($row['position']) }}</td></tr>
                                @empty
                                    <tr><td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">Bu dönem için veri yok.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach
        </div>

        <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.query_movements') }}</h3><p class="mt-1 text-sm text-gray-500">Seçili dönem ile karşılaştırma döneminin erişilebilir Search Console sorgu satırları karşılaştırılır.</p></div>
            <div class="mt-4 grid gap-4 lg:grid-cols-4">
                @foreach ([
                    ['key' => 'rising', 'label' => __('website_gsc.rising')],
                    ['key' => 'falling', 'label' => __('website_gsc.falling')],
                    ['key' => 'new', 'label' => __('website_gsc.new')],
                    ['key' => 'lost', 'label' => __('website_gsc.lost')],
                ] as $movement)
                    <div>
                        <div class="flex items-center justify-between"><h4 class="text-xs font-semibold text-gray-700 dark:text-gray-300">{{ $movement['label'] }}</h4><span class="text-xs text-gray-400">{{ count($gsc['queries'][$movement['key']] ?? []) }}</span></div>
                        <div class="mt-2 space-y-2">
                            @forelse (array_slice($gsc['queries'][$movement['key']] ?? [], 0, 6) as $row)
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="truncate text-xs font-medium text-gray-800 dark:text-gray-200" title="{{ $row['query'] }}">{{ $row['query'] }}</p><p class="mt-1 text-[11px] text-gray-500">{{ $formatNumber($row['clicks'] ?? 0) }} tıklama · Δ {{ ($row['click_delta'] ?? 0) > 0 ? '+' : '' }}{{ number_format($row['click_delta'] ?? 0, 0, ',', '.') }}</p></div>
                            @empty
                                <p class="text-xs text-gray-400">Kayıt yok.</p>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="flex items-center justify-between"><div><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.content_decay') }}</h3><p class="mt-1 text-xs text-gray-500">Talep tamamen kaybolmadan tıklaması belirgin düşen içerikler.</p></div><span class="text-xs font-semibold text-gray-400">{{ count($gsc['pages']['content_decay'] ?? []) }}</span></div>
                <div class="mt-4 space-y-2">
                    @forelse (array_slice($gsc['pages']['content_decay'] ?? [], 0, 8) as $row)
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="truncate text-xs font-semibold text-gray-800 dark:text-gray-200" title="{{ $row['page'] }}">{{ $row['page'] }}</p><p class="mt-1 text-[11px] text-gray-500">Tıklama {{ number_format($row['click_delta_percent'], 1, ',', '.') }}% · Gösterim {{ $row['impression_delta_percent'] === null ? '—' : number_format($row['impression_delta_percent'], 1, ',', '.').'%' }}</p></div>
                    @empty
                        <p class="text-sm text-gray-500">Belirgin içerik eskimesi adayı yok.</p>
                    @endforelse
                </div>
            </section>

            <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="flex items-center justify-between"><div><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.cannibalization') }}</h3><p class="mt-1 text-xs text-gray-500">{{ __('website_gsc.cannibalization_hint') }}</p></div><span class="text-xs font-semibold text-gray-400">{{ count($gsc['cannibalization'] ?? []) }}</span></div>
                <div class="mt-4 space-y-3">
                    @forelse (array_slice($gsc['cannibalization'] ?? [], 0, 6) as $row)
                        <details class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><summary class="cursor-pointer text-xs font-semibold text-gray-800 dark:text-gray-200">{{ $row['query'] }} · {{ $row['page_count'] }} URL</summary><div class="mt-2 space-y-1.5">@foreach ($row['pages'] as $page)<div class="flex gap-2 text-[11px] text-gray-500"><span class="min-w-0 flex-1 truncate" title="{{ $page['page'] }}">{{ $page['page'] }}</span><span class="shrink-0 tabular-nums">{{ $formatNumber($page['impressions']) }} gösterim</span></div>@endforeach</div></details>
                    @empty
                        <p class="text-sm text-gray-500">İnceleme gerektiren çoklu URL sinyali yok.</p>
                    @endforelse
                </div>
            </section>
        </div>

        <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.audience_surfaces') }}</h3><p class="mt-1 text-sm text-gray-500">Cihaz, ülke ve Google yüzeyi performansını ayrı okuyun; birbirine karıştırılmış toplam üretmeyin.</p></div>
            <div class="mt-4 grid gap-4 xl:grid-cols-3">
                <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]"><h4 class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ __('website_gsc.devices') }}</h4>@if (array_sum(array_map(fn($r) => (int)($r['clicks'] ?? 0), $gsc['devices'] ?? [])) > 0)<div class="mt-2 min-h-[270px]" data-chart='@json($gscCharts['devices'] ?? [])' aria-label="Search Console visitor device distribution"></div>@else<p class="mt-4 text-sm text-gray-500">Veri yok.</p>@endif</div>
                <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]"><h4 class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ __('website_gsc.countries') }}</h4>@if (! empty($gsc['countries']))<div class="mt-2 min-h-[290px]" data-chart='@json($gscCharts['countries'] ?? [])' aria-label="Search Console visitor countries"></div>@else<p class="mt-4 text-sm text-gray-500">Veri yok.</p>@endif</div>
                <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]"><h4 class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ __('website_gsc.surfaces') }}</h4>@if (array_sum(array_map(fn($r) => (int)($r['clicks'] ?? 0), $gsc['surfaces'] ?? [])) > 0)<div class="mt-2 min-h-[270px]" data-chart='@json($gscCharts['surfaces'] ?? [])' aria-label="Search Console search surfaces"></div>@else<div class="mt-4 space-y-2">@foreach ($gsc['surfaces'] ?? [] as $row)<div class="flex items-center justify-between text-xs"><span class="text-gray-600 dark:text-gray-300">{{ $surfaceLabels[$row['search_type']] ?? $row['search_type'] }}</span><span class="tabular-nums text-gray-500">{{ $formatNumber($row['impressions']) }} gösterim</span></div>@endforeach</div>@endif</div>
            </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-2">
            <section class="overflow-hidden rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.search_appearance') }}</h3><p class="mt-1 text-xs text-gray-500">{{ __('website_gsc.search_appearance_hint') }}</p></div>
                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse (array_slice($gsc['search_appearances'] ?? [], 0, 12) as $row)
                        <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-3 px-5 py-3"><div class="min-w-0"><p class="truncate text-sm font-medium text-gray-800 dark:text-gray-200">{{ $row['appearance'] }}</p><p class="mt-0.5 text-xs text-gray-500">{{ $formatPercent($row['ctr']) }} CTR</p></div><div class="text-right"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $formatNumber($row['clicks']) }}</p><p class="text-xs text-gray-400">{{ $formatNumber($row['impressions']) }} gösterim</p></div></div>
                    @empty
                        <div class="px-5 py-8 text-sm text-gray-500">Bu dönemde Search Appearance verisi yok.</div>
                    @endforelse
                </div>
            </section>

            <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.topic_clusters') }}</h3><p class="mt-1 text-xs text-gray-500">{{ __('website_gsc.topic_clusters_hint') }}</p>
                <div class="mt-4 grid gap-2 sm:grid-cols-2">
                    @forelse ($gsc['queries']['topic_clusters'] ?? [] as $row)
                        <div class="rounded-xl bg-gray-50 px-4 py-3 dark:bg-white/[0.03]"><div class="flex items-center justify-between gap-2"><p class="truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $row['label'] }}</p><span class="text-xs text-gray-400">{{ $row['query_count'] }} sorgu</span></div><p class="mt-1 text-xs text-gray-500">{{ $formatNumber($row['clicks']) }} tıklama · {{ $formatNumber($row['impressions']) }} gösterim</p></div>
                    @empty
                        <p class="text-sm text-gray-500">Konu kümesi üretmek için yeterli sorgu yok.</p>
                    @endforelse
                </div>
            </section>
        </div>

        <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div><h3 class="text-base font-semibold text-gray-900 dark:text-white">Pazar ve cihaz sinyalleri</h3><p class="mt-1 text-sm text-gray-500">Sorgu×ülke ve sayfa×cihaz kırılımları, hangi pazar veya cihazda içerik fırsatı oluştuğunu incelemek içindir.</p></div>
            <div class="mt-4 grid gap-4 xl:grid-cols-2">
                <div>
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400">Sorgu × Ülke</h4>
                    <div class="mt-2 space-y-2">@forelse (array_slice($gsc['cross_dimensions']['query_country'] ?? [], 0, 8) as $row)<div class="flex items-center gap-3 rounded-lg bg-gray-50 px-3 py-2.5 text-xs dark:bg-white/[0.03]"><span class="min-w-0 flex-1 truncate font-medium text-gray-800 dark:text-gray-200" title="{{ $row['query'] }}">{{ $row['query'] }}</span><span class="shrink-0 uppercase text-gray-400">{{ $row['country'] }}</span><span class="shrink-0 tabular-nums text-gray-500">{{ $formatNumber($row['impressions']) }}</span></div>@empty<p class="text-sm text-gray-500">Veri yok.</p>@endforelse</div>
                </div>
                <div>
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400">Sayfa × Cihaz</h4>
                    <div class="mt-2 space-y-2">@forelse (array_slice($gsc['cross_dimensions']['page_device'] ?? [], 0, 8) as $row)<div class="flex items-center gap-3 rounded-lg bg-gray-50 px-3 py-2.5 text-xs dark:bg-white/[0.03]"><span class="min-w-0 flex-1 truncate font-medium text-gray-800 dark:text-gray-200" title="{{ $row['page'] }}">{{ $row['page'] }}</span><span class="shrink-0 text-gray-400">{{ ucfirst($row['device']) }}</span><span class="shrink-0 tabular-nums text-gray-500">{{ $formatNumber($row['clicks']) }}</span></div>@empty<p class="text-sm text-gray-500">Veri yok.</p>@endforelse</div>
                </div>
            </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-2">
            <section class="overflow-hidden rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><div class="flex items-center justify-between"><div><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.sitemaps') }}</h3><p class="mt-1 text-xs text-gray-500">Search Console sitemap snapshot bilgileri; deprecated indexed alanından oran üretilmez.</p></div><span class="text-xs font-semibold text-gray-400">{{ count($gsc['sitemaps'] ?? []) }}</span></div></div>
                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($gsc['sitemaps'] ?? [] as $row)
                        <div class="px-5 py-3.5"><div class="flex flex-wrap items-start justify-between gap-2"><div class="min-w-0"><p class="truncate text-sm font-medium text-gray-800 dark:text-gray-200" title="{{ $row['path'] }}">{{ $row['path'] }}</p><p class="mt-1 text-xs text-gray-500">{{ $row['submitted_urls'] }} gönderilen URL · Son okuma {{ $row['last_downloaded'] ?? '—' }}</p></div><div class="flex gap-2 text-xs"><span @class(['rounded-full px-2 py-0.5 font-medium','bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => ($row['errors'] + $row['warnings']) === 0,'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => $row['errors'] > 0,'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => $row['errors'] === 0 && $row['warnings'] > 0])>{{ $row['errors'] }} hata · {{ $row['warnings'] }} uyarı</span></div></div></div>
                    @empty
                        <div class="px-5 py-8 text-sm text-gray-500">Sitemap snapshot verisi yok.</div>
                    @endforelse
                </div>
            </section>

            <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.index_health') }}</h3>
                @if ($gsc['index_health']['available'] ?? false)
                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4"><div class="rounded-xl bg-gray-50 p-3 text-center dark:bg-white/[0.03]"><p class="text-xl font-semibold text-gray-900 dark:text-white">{{ $gsc['index_health']['total'] }}</p><p class="text-[11px] text-gray-500">İncelenen</p></div><div class="rounded-xl bg-gray-50 p-3 text-center dark:bg-white/[0.03]"><p class="text-xl font-semibold text-emerald-600">{{ $gsc['index_health']['indexable'] }}</p><p class="text-[11px] text-gray-500">PASS</p></div><div class="rounded-xl bg-gray-50 p-3 text-center dark:bg-white/[0.03]"><p class="text-xl font-semibold text-rose-600">{{ $gsc['index_health']['issues'] }}</p><p class="text-[11px] text-gray-500">Sorun</p></div><div class="rounded-xl bg-gray-50 p-3 text-center dark:bg-white/[0.03]"><p class="text-xl font-semibold text-amber-600">{{ $gsc['index_health']['canonical_mismatches'] }}</p><p class="text-[11px] text-gray-500">Canonical farkı</p></div></div>
                    <div class="mt-4 space-y-2">@foreach (array_slice($gsc['url_inspection'] ?? [], 0, 6) as $row)<div class="rounded-lg bg-gray-50 p-3 text-xs dark:bg-white/[0.03]"><p class="truncate font-medium text-gray-800 dark:text-gray-200">{{ $row['page'] }}</p><p class="mt-1 text-gray-500">{{ $row['verdict'] ?? '—' }} · {{ $row['coverage_state'] ?? '—' }} · Son crawl {{ $row['last_crawl_time'] ?? '—' }}</p>@if (! empty($row['google_canonical']) && ! empty($row['user_canonical']) && $row['google_canonical'] !== $row['user_canonical'])<p class="mt-1 text-amber-700 dark:text-amber-300">{{ __('website_gsc.canonical_mismatch') }}: Google → {{ $row['google_canonical'] }}</p>@endif</div>@endforeach</div>
                @else
                    <div class="mt-4 rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]"><p class="text-sm text-gray-600 dark:text-gray-300">{{ __('website_gsc.inspection_unavailable') }}</p></div>
                @endif
            </section>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="flex items-center justify-between"><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.risks') }}</h3><span class="text-xs font-semibold text-gray-400">{{ count($gsc['risks'] ?? []) }}</span></div>
                <div class="mt-4 space-y-2">
                    @forelse ($gsc['risks'] ?? [] as $risk)
                        <div @class(['rounded-xl px-4 py-3 ring-1 ring-inset','bg-rose-50 text-rose-800 ring-rose-100 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20' => ($risk['severity'] ?? '') === 'high','bg-amber-50 text-amber-800 ring-amber-100 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20' => ($risk['severity'] ?? '') !== 'high'])><div class="flex items-center justify-between gap-3"><p class="text-sm font-semibold">{{ $riskLabels[$risk['type']] ?? $risk['type'] }}</p><span class="text-xs tabular-nums opacity-70">{{ is_numeric($risk['value'] ?? null) ? number_format((float) $risk['value'], 1, ',', '.') : $risk['value'] }}</span></div></div>
                    @empty
                        <p class="text-sm text-gray-500">{{ __('website_gsc.no_risks') }}</p>
                    @endforelse
                </div>
            </section>

            <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.ai_search') }}</h3>
                <div class="mt-4 rounded-xl bg-violet-50 p-4 ring-1 ring-inset ring-violet-100 dark:bg-violet-500/10 dark:ring-violet-500/20"><p class="text-sm font-medium text-violet-900 dark:text-violet-200">Google AI görünürlüğü</p><p class="mt-1 text-sm text-violet-800/80 dark:text-violet-300/80">{{ __('website_gsc.ai_unavailable') }}</p></div>
                <p class="mt-3 text-xs text-gray-500">Search Appearance verisi yukarıda gerçek provider verisinden gösterilir. AI Overview / AI Mode için provider erişimi oluşmadan sayı tahmin edilmez.</p>
            </section>
        </div>

        <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div><h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_gsc.data_quality') }}</h3><p class="mt-1 max-w-3xl text-sm text-gray-500">{{ __('website_gsc.central_only') }}</p></div>
                <div class="flex flex-wrap gap-2"><a href="{{ route('operator.findings', ['asset' => $asset->id]) }}" wire:navigate class="rounded-lg px-3 py-2 text-xs font-semibold text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Bulguları aç</a><a href="{{ route('operator.recommendations', ['asset' => $asset->id]) }}" wire:navigate class="rounded-lg bg-brand-500 px-3 py-2 text-xs font-semibold text-white hover:bg-brand-600">Önerileri aç</a></div>
            </div>
            <dl class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div><dt class="text-[11px] uppercase tracking-wide text-gray-400">{{ __('website_gsc.property') }}</dt><dd class="mt-1 break-all text-sm font-medium text-gray-800 dark:text-gray-200">{{ $gsc['property_id'] }}</dd></div>
                <div><dt class="text-[11px] uppercase tracking-wide text-gray-400">External Resource</dt><dd class="mt-1 text-sm font-medium text-gray-800 dark:text-gray-200">#{{ $gsc['external_resource_id'] }}</dd></div>
                <div><dt class="text-[11px] uppercase tracking-wide text-gray-400">Search types</dt><dd class="mt-1 text-sm font-medium text-gray-800 dark:text-gray-200">{{ implode(', ', $gsc['data_quality']['available_search_types'] ?? ['web']) }}</dd></div>
                <div><dt class="text-[11px] uppercase tracking-wide text-gray-400">Site totals</dt><dd class="mt-1 text-sm font-medium text-gray-800 dark:text-gray-200">gsc_property_daily</dd></div>
            </dl>
            <div class="mt-4 grid gap-2 text-xs text-gray-500 lg:grid-cols-2"><p class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">{{ __('website_gsc.query_limits') }}</p><p class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">{{ __('website_gsc.position_visibility_hint') }}</p></div>
        </section>
    </div>
@endif
