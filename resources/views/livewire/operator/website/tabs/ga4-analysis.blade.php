@php
    $metricLabels = [
        'sessions' => __('website_ga4.sessions'),
        'new_users' => __('website_ga4.new_users'),
        'engagement_rate' => __('website_ga4.engagement_rate'),
        'views' => __('website_ga4.views'),
    ];

    $metricValue = static function (array $metric): string {
        if ($metric['value'] === null) {
            return '—';
        }

        if (($metric['format'] ?? 'number') === 'percent') {
            return number_format((float) $metric['value'], 1).'%';
        }

        return number_format((float) $metric['value'], 0);
    };

    $metricDelta = static function (array $metric): ?string {
        if ($metric['delta'] === null) {
            return null;
        }

        $arrow = (float) $metric['delta'] > 0 ? '↑' : ((float) $metric['delta'] < 0 ? '↓' : '→');
        $suffix = ($metric['delta_kind'] ?? 'percent') === 'pp'
            ? ' '.__('website_ga4.comparison_pp')
            : '%';

        return $arrow.' '.number_format(abs((float) $metric['delta']), 1).$suffix.' '.__('website_ga4.comparison_previous');
    };

    $secondary = $ga4Analysis['secondary_metrics'] ?? [];
@endphp

<div class="space-y-4" data-website-ga4-analysis>
    <section class="rounded-2xl border border-gray-200 bg-white px-5 py-4 dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex min-w-0 items-center gap-3">
                <x-demo.digital-asset-mark type="ga4" size="md" />
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.title') }}</h2>
                        @if ($ga4Analysis['connected'] ?? false)
                            <x-ta.badge color="success" size="sm">{{ __('operator.website.panels.connected') }}</x-ta.badge>
                        @endif
                    </div>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('website_ga4.subtitle') }}</p>
                </div>
            </div>

            @if ($ga4Analysis['connected'] ?? false)
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-gray-500 dark:text-gray-400">
                    <div>
                        <span class="block text-[11px] uppercase tracking-wide text-gray-400">{{ __('website_ga4.property') }}</span>
                        <span class="mt-0.5 block font-medium text-gray-700 dark:text-gray-300">{{ $ga4Analysis['property_name'] }} · {{ $ga4Analysis['property_id'] }}</span>
                    </div>
                    <div>
                        <span class="block text-[11px] uppercase tracking-wide text-gray-400">{{ __('website_ga4.data_range') }}</span>
                        <span class="mt-0.5 block font-medium text-gray-700 dark:text-gray-300">
                            {{ $ga4Analysis['coverage']['start'] ?? '—' }} → {{ $ga4Analysis['coverage']['end'] ?? '—' }}
                        </span>
                    </div>
                </div>
            @endif
        </div>
    </section>

    @if (! ($ga4Analysis['connected'] ?? false))
        <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-500/20 dark:bg-amber-500/10">
            <h3 class="font-semibold text-amber-900 dark:text-amber-200">{{ __('website_ga4.no_binding_title') }}</h3>
            <p class="mt-1 text-sm text-amber-800/80 dark:text-amber-300/80">{{ __('website_ga4.no_binding_body') }}</p>
            <a href="{{ route('operator.asset.sources', ['assetId' => $asset->id]) }}" wire:navigate class="mt-4 inline-flex rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">{{ __('operator.website.actions.data_sources') }}</a>
        </section>
    @else
        @if (! ($ga4Analysis['has_data'] ?? false))
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-500/20 dark:bg-amber-500/10">
                <h3 class="font-semibold text-amber-900 dark:text-amber-200">{{ __('website_ga4.no_data_title') }}</h3>
                <p class="mt-1 text-sm text-amber-800/80 dark:text-amber-300/80">{{ __('website_ga4.no_data_body') }}</p>
            </section>
        @endif

        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            @foreach ($ga4Analysis['metrics'] ?? [] as $metric)
                @php
                    $delta = $metricDelta($metric);
                    $tone = $metric['delta'] === null ? 'neutral' : ((float) $metric['delta'] >= 0 ? 'success' : 'warning');
                @endphp
                <x-ta.metric-card
                    :label="$metricLabels[$metric['key']] ?? $metric['key']"
                    :value="$metricValue($metric)"
                    :delta="$delta"
                    :tone="$tone"
                />
            @endforeach
        </div>

        <section class="grid overflow-hidden rounded-2xl border border-gray-200 bg-white sm:grid-cols-2 xl:grid-cols-4 dark:border-gray-800 dark:bg-white/[0.03]">
            @foreach ([
                ['label' => __('website_ga4.engaged_sessions'), 'value' => $secondary['engaged_sessions'] ?? null],
                ['label' => __('website_ga4.events'), 'value' => $secondary['events'] ?? null],
                ['label' => __('website_ga4.key_events'), 'value' => $secondary['key_events'] ?? null],
                ['label' => __('website_ga4.revenue'), 'value' => $secondary['revenue'] ?? null],
            ] as $item)
                <div class="border-b border-gray-100 px-5 py-4 last:border-b-0 sm:border-r sm:odd:border-r xl:border-b-0 dark:border-gray-800">
                    <p class="text-xs font-medium text-gray-500">{{ $item['label'] }}</p>
                    <p class="mt-1.5 text-xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $item['value'] === null ? '—' : number_format((float) $item['value'], is_float($item['value']) ? 1 : 0) }}</p>
                </div>
            @endforeach
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.trend') }}</h3>
                    <p class="mt-0.5 text-xs text-gray-400">{{ __('website_ga4.trend_hint') }}</p>
                </div>
                <span class="text-xs text-gray-400">{{ $ga4Analysis['period']['start'] }} → {{ $ga4Analysis['period']['end'] }}</span>
            </div>
            <div data-chart='@json($ga4ChartOptions)' aria-label="GA4 traffic trend" class="min-h-[280px]"></div>
        </section>

        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="mb-4">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.traffic_acquisition') }}</h3>
                    <p class="mt-0.5 text-xs text-gray-400">{{ __('website_ga4.traffic_acquisition_hint') }}</p>
                </div>
                <div class="space-y-3">
                    @forelse ($ga4Analysis['channels'] ?? [] as $row)
                        <div>
                            <div class="mb-1 flex items-center justify-between gap-3 text-xs">
                                <span class="truncate font-medium text-gray-800 dark:text-white/90">{{ $row['label'] ?: '(not set)' }}</span>
                                <span class="shrink-0 tabular-nums text-gray-500">{{ number_format($row['sessions']) }} · {{ number_format($row['share'], 1) }}%</span>
                            </div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5"><div class="h-full rounded-full bg-brand-500" style="width: {{ min(100, $row['share']) }}%"></div></div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">{{ __('website_ga4.no_rows') }}</p>
                    @endforelse
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="mb-4">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.user_acquisition') }}</h3>
                    <p class="mt-0.5 text-xs text-gray-400">{{ __('website_ga4.user_acquisition_hint') }}</p>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($ga4Analysis['first_user_acquisition'] ?? [] as $row)
                        <div class="flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0">
                            <span class="min-w-0 truncate text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['label'] ?: '(not set)' }}</span>
                            <span class="shrink-0 text-sm tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($row['new_users']) }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">{{ __('website_ga4.no_rows') }}</p>
                    @endforelse
                </div>
            </section>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            @foreach ([
                ['title' => __('website_ga4.source_medium'), 'rows' => $ga4Analysis['source_medium'] ?? []],
                ['title' => __('website_ga4.campaigns'), 'rows' => $ga4Analysis['campaigns'] ?? []],
            ] as $group)
                <section class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $group['title'] }}</h3></div>
                    <div class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($group['rows'] as $row)
                            <div class="flex items-center justify-between gap-4 px-5 py-3">
                                <span class="min-w-0 truncate text-sm text-gray-700 dark:text-gray-300">{{ $row['label'] ?: '(not set)' }}</span>
                                <span class="shrink-0 text-sm font-medium tabular-nums text-gray-900 dark:text-white">{{ number_format($row['sessions']) }}</span>
                            </div>
                        @empty
                            <div class="px-5 py-6 text-sm text-gray-500">{{ __('website_ga4.no_rows') }}</div>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>

        <div>
            <div class="mb-2"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.content') }}</h3></div>
            <div class="grid gap-4 xl:grid-cols-2">
                <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.landing_pages') }}</h4></div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100 text-sm dark:divide-gray-800">
                            <thead><tr class="text-left text-[11px] uppercase tracking-wide text-gray-400"><th class="px-5 py-3 font-medium">Landing</th><th class="px-3 py-3 text-right font-medium">{{ __('website_ga4.sessions_col') }}</th><th class="px-5 py-3 text-right font-medium">{{ __('website_ga4.engagement_col') }}</th></tr></thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @forelse ($ga4Analysis['landing_pages'] ?? [] as $row)
                                    <tr><td class="max-w-[320px] truncate px-5 py-3 font-medium text-gray-800 dark:text-white/90">{{ $row['label'] ?: '/' }}</td><td class="px-3 py-3 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($row['sessions']) }}</td><td class="px-5 py-3 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ $row['engagement_rate'] === null ? '—' : number_format($row['engagement_rate'], 1).'%' }}</td></tr>
                                @empty
                                    <tr><td colspan="3" class="px-5 py-6 text-gray-500">{{ __('website_ga4.no_rows') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.pages_screens') }}</h4></div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100 text-sm dark:divide-gray-800">
                            <thead><tr class="text-left text-[11px] uppercase tracking-wide text-gray-400"><th class="px-5 py-3 font-medium">Page</th><th class="px-3 py-3 text-right font-medium">{{ __('website_ga4.views_col') }}</th><th class="px-5 py-3 text-right font-medium">{{ __('website_ga4.events_col') }}</th></tr></thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @forelse ($ga4Analysis['pages'] ?? [] as $row)
                                    <tr><td class="px-5 py-3"><p class="max-w-[320px] truncate font-medium text-gray-800 dark:text-white/90">{{ $row['title'] ?: $row['path'] }}</p><p class="mt-0.5 max-w-[320px] truncate text-[11px] text-gray-400">{{ $row['path'] }}</p></td><td class="px-3 py-3 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($row['views']) }}</td><td class="px-5 py-3 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($row['events']) }}</td></tr>
                                @empty
                                    <tr><td colspan="3" class="px-5 py-6 text-gray-500">{{ __('website_ga4.no_rows') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>

        <div>
            <div class="mb-2"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.behavior') }}</h3></div>
            <div class="grid gap-4 xl:grid-cols-2">
                @foreach ([
                    ['title' => __('website_ga4.top_events'), 'rows' => $ga4Analysis['events'] ?? []],
                    ['title' => __('website_ga4.key_event_performance'), 'rows' => $ga4Analysis['key_events'] ?? []],
                ] as $group)
                    <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $group['title'] }}</h4>
                        <div class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($group['rows'] as $row)
                                <div class="flex items-center justify-between gap-3 py-2.5"><span class="min-w-0 truncate text-sm text-gray-700 dark:text-gray-300">{{ $row['label'] }}</span><span class="shrink-0 font-medium tabular-nums text-gray-900 dark:text-white">{{ number_format((float) $row['events'], is_float($row['events']) ? 1 : 0) }}</span></div>
                            @empty
                                <p class="py-3 text-sm text-gray-500">{{ __('website_ga4.no_rows') }}</p>
                            @endforelse
                        </div>
                    </section>
                @endforeach
            </div>
        </div>

        <div>
            <div class="mb-2"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.audience') }}</h3></div>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ([
                    ['title' => __('website_ga4.devices'), 'rows' => $ga4Analysis['devices'] ?? [], 'value' => 'sessions'],
                    ['title' => __('website_ga4.browsers'), 'rows' => $ga4Analysis['browsers'] ?? [], 'value' => 'sessions'],
                    ['title' => __('website_ga4.countries'), 'rows' => $ga4Analysis['countries'] ?? [], 'value' => 'sessions'],
                    ['title' => __('website_ga4.cities'), 'rows' => $ga4Analysis['cities'] ?? [], 'value' => 'sessions'],
                ] as $group)
                    <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $group['title'] }}</h4>
                        <div class="mt-3 space-y-2.5">
                            @forelse ($group['rows'] as $row)
                                <div class="flex items-center justify-between gap-3"><span class="min-w-0 truncate text-sm text-gray-600 dark:text-gray-300">{{ $row['label'] ?: '(not set)' }}</span><span class="shrink-0 text-sm font-medium tabular-nums text-gray-900 dark:text-white">{{ number_format($row[$group['value']]) }}</span></div>
                            @empty
                                <p class="text-sm text-gray-500">{{ __('website_ga4.no_rows') }}</p>
                            @endforelse
                        </div>
                    </section>
                @endforeach

                <section class="rounded-2xl border border-gray-200 bg-white p-5 md:col-span-2 xl:col-span-2 dark:border-gray-800 dark:bg-white/[0.03]">
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.busy_hours') }}</h4>
                    <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                        @forelse ($ga4Analysis['busy_hours'] ?? [] as $row)
                            <div class="rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-white/[0.03]"><p class="text-xs text-gray-500">{{ $row['day'] }} · {{ str_pad($row['hour'], 2, '0', STR_PAD_LEFT) }}:00</p><p class="mt-1 font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($row['sessions']) }} {{ mb_strtolower(__('website_ga4.sessions_col')) }}</p></div>
                        @empty
                            <p class="text-sm text-gray-500">{{ __('website_ga4.no_rows') }}</p>
                        @endforelse
                    </div>
                </section>
            </div>
        </div>

        @if (($ga4Analysis['ecommerce']['has_data'] ?? false) === true)
            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                    <div><h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.ecommerce') }}</h3><p class="mt-0.5 text-xs text-gray-400">{{ __('website_ga4.products') }}</p></div>
                    <div class="flex gap-5 text-right text-xs"><div><span class="block text-gray-400">{{ __('website_ga4.purchases_col') }}</span><strong class="mt-0.5 block text-sm text-gray-900 dark:text-white">{{ number_format($ga4Analysis['ecommerce']['purchases'] ?? 0) }}</strong></div><div><span class="block text-gray-400">{{ __('website_ga4.revenue_col') }}</span><strong class="mt-0.5 block text-sm text-gray-900 dark:text-white">{{ $ga4Analysis['ecommerce']['revenue'] === null ? '—' : number_format((float) $ga4Analysis['ecommerce']['revenue'], 2) }}</strong></div></div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm"><thead><tr class="border-b border-gray-100 text-left text-[11px] uppercase tracking-wide text-gray-400 dark:border-gray-800"><th class="px-5 py-3 font-medium">Product</th><th class="px-3 py-3 text-right font-medium">{{ __('website_ga4.views_col') }}</th><th class="px-3 py-3 text-right font-medium">Cart</th><th class="px-5 py-3 text-right font-medium">{{ __('website_ga4.purchases_col') }}</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">@foreach ($ga4Analysis['ecommerce']['items'] ?? [] as $row)<tr><td class="px-5 py-3 font-medium text-gray-800 dark:text-white/90">{{ $row['item_name'] ?: $row['item_id'] }}</td><td class="px-3 py-3 text-right tabular-nums">{{ number_format($row['views']) }}</td><td class="px-3 py-3 text-right tabular-nums">{{ number_format($row['carts']) }}</td><td class="px-5 py-3 text-right tabular-nums">{{ number_format($row['purchases']) }}</td></tr>@endforeach</tbody></table>
                </div>
            </section>
        @endif

        <p class="text-[11px] text-gray-400">{{ __('website_ga4.central_note') }}</p>
    @endif
</div>
