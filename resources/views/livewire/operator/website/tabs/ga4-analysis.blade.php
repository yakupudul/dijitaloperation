@php
    $metricLabels = [
        'sessions' => __('website_ga4.sessions'),
        'new_users' => __('website_ga4.new_users'),
        'engagement_rate' => __('website_ga4.engagement_rate'),
        'views' => __('website_ga4.views'),
    ];
    $metricHints = [
        'sessions' => __('website_ga4.sessions_hint'),
        'new_users' => __('website_ga4.new_users_hint'),
        'engagement_rate' => __('website_ga4.engagement_rate_hint'),
        'views' => __('website_ga4.views_hint'),
    ];
    $metricIcons = [
        'sessions' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'new_users' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/></svg>',
        'engagement_rate' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3-8 4 16 3-8h4"/></svg>',
        'views' => '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>',
    ];

    $metricValue = static function (array $metric): string {
        if (($metric['value'] ?? null) === null) return '—';
        if (($metric['format'] ?? 'number') === 'percent') return number_format((float) $metric['value'], 1).'%';
        return number_format((float) $metric['value'], 0);
    };

    $metricDelta = static function (array $metric): ?string {
        if (($metric['delta'] ?? null) === null) return null;
        $arrow = (float) $metric['delta'] > 0 ? '↑' : ((float) $metric['delta'] < 0 ? '↓' : '→');
        $suffix = ($metric['delta_kind'] ?? 'percent') === 'pp' ? ' '.__('website_ga4.comparison_pp') : '%';
        return $arrow.' '.number_format(abs((float) $metric['delta']), 1).$suffix.' '.__('website_ga4.comparison_previous');
    };

    $channelNames = [
        'Organic Search' => __('website_ga4.channel_organic_search'),
        'Direct' => __('website_ga4.channel_direct'),
        'Referral' => __('website_ga4.channel_referral'),
        'Organic Social' => __('website_ga4.channel_organic_social'),
        'Paid Search' => __('website_ga4.channel_paid_search'),
        'Paid Social' => __('website_ga4.channel_paid_social'),
        'Display' => __('website_ga4.channel_display'),
        'Email' => __('website_ga4.channel_email'),
        'Unassigned' => __('website_ga4.channel_unassigned'),
        '(not set)' => __('website_ga4.channel_unassigned'),
    ];
    $channelLabel = static fn (?string $label): string => $channelNames[$label ?: ''] ?? ($label ?: __('website_ga4.channel_other'));

    $deviceNames = [
        'mobile' => __('website_ga4.device_mobile'),
        'desktop' => __('website_ga4.device_desktop'),
        'tablet' => __('website_ga4.device_tablet'),
    ];
    $deviceLabel = static fn (?string $label): string => $deviceNames[mb_strtolower((string) $label)] ?? ($label ?: '—');

    $eventNames = [
        'page_view' => __('website_ga4.event_page_view'),
        'session_start' => __('website_ga4.event_session_start'),
        'first_visit' => __('website_ga4.event_first_visit'),
        'user_engagement' => __('website_ga4.event_user_engagement'),
        'scroll' => __('website_ga4.event_scroll'),
        'click' => __('website_ga4.event_click'),
        'form_start' => __('website_ga4.event_form_start'),
        'form_submit' => __('website_ga4.event_form_submit'),
        'file_download' => __('website_ga4.event_file_download'),
    ];
    $eventLabel = static fn (?string $label): string => $eventNames[$label ?: ''] ?? ucfirst(str_replace('_', ' ', (string) ($label ?: '—')));

    $dayNames = [
        '0' => __('website_ga4.day_sunday'),
        '1' => __('website_ga4.day_monday'),
        '2' => __('website_ga4.day_tuesday'),
        '3' => __('website_ga4.day_wednesday'),
        '4' => __('website_ga4.day_thursday'),
        '5' => __('website_ga4.day_friday'),
        '6' => __('website_ga4.day_saturday'),
        'Sunday' => __('website_ga4.day_sunday'),
        'Monday' => __('website_ga4.day_monday'),
        'Tuesday' => __('website_ga4.day_tuesday'),
        'Wednesday' => __('website_ga4.day_wednesday'),
        'Thursday' => __('website_ga4.day_thursday'),
        'Friday' => __('website_ga4.day_friday'),
        'Saturday' => __('website_ga4.day_saturday'),
    ];
    $dayLabel = static fn ($day): string => $dayNames[(string) $day] ?? (string) $day;

    $secondary = $ga4Analysis['secondary_metrics'] ?? [];
    $channelRows = $ga4Analysis['channels'] ?? [];
    $firstUserRows = $ga4Analysis['first_user_acquisition'] ?? [];
    $landingRows = $ga4Analysis['landing_pages'] ?? [];
    $pageRows = $ga4Analysis['pages'] ?? [];
    $eventRows = $ga4Analysis['events'] ?? [];
    $keyEventRows = $ga4Analysis['key_events'] ?? [];
    $deviceRows = $ga4Analysis['devices'] ?? [];
    $countryRows = $ga4Analysis['countries'] ?? [];
    $cityRows = $ga4Analysis['cities'] ?? [];
    $maxFirstUsers = max(1, (int) collect($firstUserRows)->max('new_users'));
    $maxEvents = max(1, (float) collect($eventRows)->max('events'));
    $maxKeyEvents = max(1, (float) collect($keyEventRows)->max('events'));
@endphp

<div class="space-y-6" data-website-ga4-analysis>
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
                        <span class="mt-0.5 block font-medium text-gray-700 dark:text-gray-300">{{ $ga4Analysis['coverage']['start'] ?? '—' }} → {{ $ga4Analysis['coverage']['end'] ?? '—' }}</span>
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

        <section>
            <div class="mb-3">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.overview_section') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('website_ga4.overview_section_hint') }}</p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($ga4Analysis['metrics'] ?? [] as $metric)
                    @php
                        $delta = $metricDelta($metric);
                        $tone = ($metric['delta'] ?? null) === null ? 'neutral' : ((float) $metric['delta'] >= 0 ? 'positive' : 'warning');
                    @endphp
                    <x-ta.metric-card
                        :label="$metricLabels[$metric['key']] ?? $metric['key']"
                        :value="$metricValue($metric)"
                        :delta="$delta"
                        :tone="$tone"
                        :hint="$metricHints[$metric['key']] ?? null"
                        :icon="$metricIcons[$metric['key']] ?? null"
                    />
                @endforeach
            </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-12">
            <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] xl:col-span-8">
                <div class="mb-2 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.trend') }}</h3>
                        <p class="mt-1 text-xs text-gray-400">{{ __('website_ga4.trend_hint') }}</p>
                    </div>
                    <div class="text-right">
                        <x-ta.badge color="light" size="sm">{{ ($ga4Analysis['trend']['display_granularity'] ?? 'daily') === 'weekly' ? __('website_ga4.weekly') : __('website_ga4.daily') }}</x-ta.badge>
                        <p class="mt-1 text-[11px] text-gray-400">{{ $ga4Analysis['period']['start'] }} → {{ $ga4Analysis['period']['end'] }}</p>
                    </div>
                </div>
                @if (! empty($ga4Charts['trend']['series'][0]['data'] ?? []))
                    <div data-chart='@json($ga4Charts['trend'])' aria-label="Website visitor trend" class="min-h-[320px]"></div>
                @else
                    <div class="flex min-h-[260px] items-center justify-center text-sm text-gray-400">{{ __('website_ga4.no_rows') }}</div>
                @endif
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] xl:col-span-4">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.engagement_rate') }}</h3>
                    <p class="mt-1 text-xs leading-5 text-gray-400">{{ __('website_ga4.engagement_rate_hint') }}</p>
                </div>
                <div data-chart='@json($ga4Charts['engagement'] ?? [])' aria-label="Engaged visit rate" class="mx-auto min-h-[220px] max-w-[280px]"></div>
                <div class="grid grid-cols-2 gap-2">
                    @foreach ([
                        [__('website_ga4.engaged_sessions'), $secondary['engaged_sessions'] ?? null, 0],
                        [__('website_ga4.events'), $secondary['events'] ?? null, 0],
                        [__('website_ga4.key_events'), $secondary['key_events'] ?? null, 1],
                        [__('website_ga4.pages_per_visit'), $secondary['pages_per_visit'] ?? null, 2],
                    ] as [$label, $value, $precision])
                        <div class="rounded-xl bg-gray-50 px-3 py-3 dark:bg-white/[0.03]">
                            <p class="text-[11px] leading-4 text-gray-500">{{ $label }}</p>
                            <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-white">{{ $value === null ? '—' : number_format((float) $value, $precision) }}</p>
                        </div>
                    @endforeach
                </div>
            </section>
        </div>

        <section>
            <div class="mb-3">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.discovery_section') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('website_ga4.discovery_section_hint') }}</p>
            </div>
            <div class="grid gap-4 xl:grid-cols-12">
                <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] xl:col-span-7">
                    <div class="mb-2">
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.traffic_acquisition') }}</h4>
                        <p class="mt-1 text-xs text-gray-400">{{ __('website_ga4.traffic_acquisition_hint') }}</p>
                    </div>
                    <div class="grid items-center gap-4 md:grid-cols-2">
                        <div>
                            @if (! empty($channelRows))
                                <div data-chart='@json($ga4Charts['channels'] ?? [])' aria-label="Traffic channel share" class="min-h-[280px]"></div>
                            @else
                                <p class="py-8 text-sm text-gray-500">{{ __('website_ga4.no_rows') }}</p>
                            @endif
                        </div>
                        <div class="space-y-2.5">
                            @foreach (array_slice($channelRows, 0, 6) as $row)
                                <div>
                                    <div class="flex items-center justify-between gap-2 text-xs">
                                        <span class="truncate font-medium text-gray-700 dark:text-gray-300">{{ $channelLabel($row['label'] ?? null) }}</span>
                                        <span class="shrink-0 tabular-nums text-gray-500">{{ number_format($row['sessions']) }} · {{ number_format($row['share'], 1) }}%</span>
                                    </div>
                                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5"><div class="h-full rounded-full bg-brand-500" style="width: {{ min(100, $row['share']) }}%"></div></div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] xl:col-span-5">
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.user_acquisition') }}</h4>
                    <p class="mt-1 text-xs text-gray-400">{{ __('website_ga4.user_acquisition_hint') }}</p>
                    <div class="mt-5 space-y-3">
                        @forelse (array_slice($firstUserRows, 0, 7) as $row)
                            <div>
                                <div class="flex items-center justify-between gap-3 text-sm">
                                    <span class="truncate font-medium text-gray-700 dark:text-gray-300">{{ $channelLabel($row['label'] ?? null) }}</span>
                                    <span class="shrink-0 tabular-nums text-gray-900 dark:text-white">{{ number_format($row['new_users']) }}</span>
                                </div>
                                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5"><div class="h-full rounded-full bg-sky-500" style="width: {{ min(100, ((int) $row['new_users'] / $maxFirstUsers) * 100) }}%"></div></div>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">{{ __('website_ga4.no_rows') }}</p>
                        @endforelse
                    </div>
                </section>
            </div>
        </section>

        <section>
            <div class="mb-3">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.content_section') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('website_ga4.content_section_hint') }}</p>
            </div>
            <div class="grid gap-4 xl:grid-cols-2">
                <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.landing_pages') }}</h4></div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100 text-sm dark:divide-gray-800">
                            <thead><tr class="text-left text-[11px] uppercase tracking-wide text-gray-400"><th class="px-5 py-3 font-medium">{{ __('website_ga4.page_col') }}</th><th class="px-3 py-3 text-right font-medium">{{ __('website_ga4.sessions_col') }}</th><th class="px-5 py-3 text-right font-medium">{{ __('website_ga4.engagement_col') }}</th></tr></thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @forelse (array_slice($landingRows, 0, 8) as $row)
                                    <tr><td class="max-w-[360px] truncate px-5 py-3 font-medium text-gray-800 dark:text-white/90">{{ $row['label'] ?: '/' }}</td><td class="px-3 py-3 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($row['sessions']) }}</td><td class="px-5 py-3 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ $row['engagement_rate'] === null ? '—' : number_format($row['engagement_rate'], 1).'%' }}</td></tr>
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
                            <thead><tr class="text-left text-[11px] uppercase tracking-wide text-gray-400"><th class="px-5 py-3 font-medium">{{ __('website_ga4.page_col') }}</th><th class="px-3 py-3 text-right font-medium">{{ __('website_ga4.views_col') }}</th><th class="px-5 py-3 text-right font-medium">{{ __('website_ga4.events_col') }}</th></tr></thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @forelse (array_slice($pageRows, 0, 8) as $row)
                                    <tr><td class="px-5 py-3"><p class="max-w-[360px] truncate font-medium text-gray-800 dark:text-white/90">{{ $row['title'] ?: $row['path'] }}</p><p class="mt-0.5 max-w-[360px] truncate text-[11px] text-gray-400">{{ $row['path'] }}</p></td><td class="px-3 py-3 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($row['views']) }}</td><td class="px-5 py-3 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($row['events']) }}</td></tr>
                                @empty
                                    <tr><td colspan="3" class="px-5 py-6 text-gray-500">{{ __('website_ga4.no_rows') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </section>

        <section>
            <div class="mb-3">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.actions_section') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('website_ga4.actions_section_hint') }}</p>
            </div>
            <div class="grid gap-4 xl:grid-cols-2">
                <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.top_events') }}</h4>
                    <div class="mt-4 space-y-3">
                        @forelse (array_slice($eventRows, 0, 8) as $row)
                            <div>
                                <div class="flex items-center justify-between gap-3 text-sm"><span class="min-w-0 truncate font-medium text-gray-700 dark:text-gray-300">{{ $eventLabel($row['label'] ?? null) }}</span><span class="shrink-0 tabular-nums text-gray-900 dark:text-white">{{ number_format($row['events']) }}</span></div>
                                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5"><div class="h-full rounded-full bg-violet-500" style="width: {{ min(100, ((float) $row['events'] / $maxEvents) * 100) }}%"></div></div>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">{{ __('website_ga4.no_rows') }}</p>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.key_event_performance') }}</h4>
                    <div class="mt-4 space-y-3">
                        @forelse (array_slice($keyEventRows, 0, 8) as $row)
                            <div>
                                <div class="flex items-center justify-between gap-3 text-sm"><span class="min-w-0 truncate font-medium text-gray-700 dark:text-gray-300">{{ $eventLabel($row['label'] ?? null) }}</span><span class="shrink-0 tabular-nums text-gray-900 dark:text-white">{{ number_format((float) $row['events'], 1) }}</span></div>
                                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5"><div class="h-full rounded-full bg-emerald-500" style="width: {{ min(100, ((float) $row['events'] / $maxKeyEvents) * 100) }}%"></div></div>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">{{ __('website_ga4.no_rows') }}</p>
                        @endforelse
                    </div>
                </section>
            </div>
        </section>

        <section>
            <div class="mb-3">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.audience_section') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('website_ga4.audience_section_hint') }}</p>
            </div>
            <div class="grid gap-4 xl:grid-cols-12">
                <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] xl:col-span-4">
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.devices') }}</h4>
                    @if (! empty($deviceRows))
                        <div data-chart='@json($ga4Charts['devices'] ?? [])' aria-label="Visitor device distribution" class="min-h-[250px]"></div>
                        <div class="mt-1 flex flex-wrap justify-center gap-x-4 gap-y-2 text-xs text-gray-500">
                            @foreach (array_slice($deviceRows, 0, 3) as $row)
                                <span>{{ $deviceLabel($row['label'] ?? null) }} · {{ number_format($row['sessions']) }}</span>
                            @endforeach
                        </div>
                    @else
                        <p class="py-8 text-sm text-gray-500">{{ __('website_ga4.no_rows') }}</p>
                    @endif
                </section>

                <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] xl:col-span-8">
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.countries') }}</h4>
                    @if (! empty($countryRows))
                        <div data-chart='@json($ga4Charts['countries'] ?? [])' aria-label="Visitor countries" class="min-h-[280px]"></div>
                    @else
                        <p class="py-8 text-sm text-gray-500">{{ __('website_ga4.no_rows') }}</p>
                    @endif
                </section>
            </div>

            @if (! empty($cityRows))
                <div class="mt-4 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.cities') }}</h4>
                    <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach (array_slice($cityRows, 0, 8) as $row)
                            <div class="flex items-center justify-between rounded-xl bg-gray-50 px-3 py-2.5 dark:bg-white/[0.03]"><span class="truncate text-sm text-gray-600 dark:text-gray-300">{{ $row['label'] ?: '—' }}</span><strong class="ml-3 shrink-0 text-sm tabular-nums text-gray-900 dark:text-white">{{ number_format($row['sessions']) }}</strong></div>
                        @endforeach
                    </div>
                </div>
            @endif
        </section>

        <section>
            <div class="mb-3">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.timing_section') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('website_ga4.timing_section_hint') }}</p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                @forelse ($ga4Analysis['busy_hours'] ?? [] as $row)
                    <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                        </div>
                        <p class="mt-3 text-xs text-gray-500">{{ $dayLabel($row['day']) }} · {{ str_pad($row['hour'], 2, '0', STR_PAD_LEFT) }}:00</p>
                        <p class="mt-1 text-xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($row['sessions']) }}</p>
                        <p class="text-[11px] text-gray-400">{{ mb_strtolower(__('website_ga4.sessions_col')) }}</p>
                    </div>
                @empty
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 text-sm text-gray-500 dark:border-gray-800 dark:bg-white/[0.03] sm:col-span-2">{{ __('website_ga4.no_rows') }}</div>
                @endforelse
            </div>
        </section>

        <details class="group rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.technical_details') }}</h3>
                    <p class="mt-1 text-xs text-gray-400">{{ __('website_ga4.technical_details_hint') }}</p>
                </div>
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-gray-100 text-gray-500 transition group-open:rotate-180 dark:bg-white/5">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                </span>
            </summary>
            <div class="border-t border-gray-100 p-5 dark:border-gray-800">
                <div class="grid gap-4 xl:grid-cols-3">
                    @foreach ([
                        ['title' => __('website_ga4.source_medium'), 'rows' => $ga4Analysis['source_medium'] ?? []],
                        ['title' => __('website_ga4.campaigns'), 'rows' => $ga4Analysis['campaigns'] ?? []],
                        ['title' => __('website_ga4.browsers'), 'rows' => $ga4Analysis['browsers'] ?? []],
                    ] as $group)
                        <section class="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]">
                            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $group['title'] }}</h4>
                            <div class="mt-3 divide-y divide-gray-200/70 dark:divide-gray-700">
                                @forelse (array_slice($group['rows'], 0, 8) as $row)
                                    <div class="flex items-center justify-between gap-3 py-2.5"><span class="min-w-0 truncate text-sm text-gray-600 dark:text-gray-300">{{ $row['label'] ?: '(not set)' }}</span><span class="shrink-0 text-sm font-medium tabular-nums text-gray-900 dark:text-white">{{ number_format($row['sessions']) }}</span></div>
                                @empty
                                    <p class="py-3 text-sm text-gray-500">{{ __('website_ga4.no_rows') }}</p>
                                @endforelse
                            </div>
                        </section>
                    @endforeach
                </div>
            </div>
        </details>

        @if (($ga4Analysis['ecommerce']['has_data'] ?? false) === true)
            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                    <div><h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('website_ga4.ecommerce') }}</h3><p class="mt-0.5 text-xs text-gray-400">{{ __('website_ga4.products') }}</p></div>
                    <div class="flex gap-5 text-right text-xs"><div><span class="block text-gray-400">{{ __('website_ga4.purchases_col') }}</span><strong class="mt-0.5 block text-sm text-gray-900 dark:text-white">{{ number_format($ga4Analysis['ecommerce']['purchases'] ?? 0) }}</strong></div><div><span class="block text-gray-400">{{ __('website_ga4.revenue_col') }}</span><strong class="mt-0.5 block text-sm text-gray-900 dark:text-white">{{ $ga4Analysis['ecommerce']['revenue'] === null ? '—' : number_format((float) $ga4Analysis['ecommerce']['revenue'], 2) }}</strong></div></div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm"><thead><tr class="border-b border-gray-100 text-left text-[11px] uppercase tracking-wide text-gray-400 dark:border-gray-800"><th class="px-5 py-3 font-medium">{{ __('website_ga4.product_col') }}</th><th class="px-3 py-3 text-right font-medium">{{ __('website_ga4.views_col') }}</th><th class="px-3 py-3 text-right font-medium">{{ __('website_ga4.cart_col') }}</th><th class="px-5 py-3 text-right font-medium">{{ __('website_ga4.purchases_col') }}</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">@foreach ($ga4Analysis['ecommerce']['items'] ?? [] as $row)<tr><td class="px-5 py-3 font-medium text-gray-800 dark:text-white/90">{{ $row['item_name'] ?: $row['item_id'] }}</td><td class="px-3 py-3 text-right tabular-nums">{{ number_format($row['views']) }}</td><td class="px-3 py-3 text-right tabular-nums">{{ number_format($row['carts']) }}</td><td class="px-5 py-3 text-right tabular-nums">{{ number_format($row['purchases']) }}</td></tr>@endforeach</tbody></table>
                </div>
            </section>
        @endif

        <p class="text-[11px] text-gray-400">{{ __('website_ga4.central_note') }}</p>
    @endif
</div>
