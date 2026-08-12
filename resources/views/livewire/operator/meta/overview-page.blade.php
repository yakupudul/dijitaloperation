<div>
    @php
        $identity = $workspace['account_identity'] ?? [];
        $history = $workspace['history'] ?? [];
        $trend = $workspace['trend'] ?? [];
        $resultMix = $workspace['result_mix'] ?? ['items' => []];
        $primary = $workspace['primary_result'] ?? null;
        $campaigns = $workspace['campaign_snapshot'] ?? [];
    @endphp

    <x-ta.page-breadcrumb pageTitle="Meta Overview" :crumbs="[
        ['label' => 'Digital Assets', 'url' => route('operator.digital-assets')],
    ]" />

    {{-- Account identity + period --}}
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h1 class="text-title-sm font-bold text-gray-800 dark:text-white/90">{{ $identity['name'] ?? $asset->name }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                @if ($identity['external_id'] ?? null) ID {{ $identity['external_id'] }} @endif
                @if ($identity['business_name'] ?? null) · {{ $identity['business_name'] }} @endif
                @if ($identity['currency'] ?? null) · {{ $identity['currency'] }} @endif
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('operator.meta.campaigns', $asset) }}"><x-ta.button variant="outline" size="sm">Campaigns</x-ta.button></a>
        </div>
    </div>

    <div class="mb-6">
        @include('livewire.operator.meta.partials.period-bar')
        <p class="mt-2 text-xs text-gray-400">{{ $workspace['period_label'] ?? '' }}</p>
    </div>

    {{-- History readiness banner --}}
    @if (($history['message'] ?? null))
        <div class="mb-6">
            <x-ta.alert :variant="($history['state'] ?? '') === 'unavailable' ? 'warning' : 'info'" :message="$history['message']" />
        </div>
    @endif

    @if (empty($workspace['kpis']) && empty($campaigns))
        <x-ta.empty-state title="No performance data for this period yet"
            message="Bind a Meta Ad Account and import history from the Meta Integration page. Covered periods appear here automatically.">
            <x-ta.button href="{{ route('operator.meta') }}" size="sm">Go to Meta Integration</x-ta.button>
        </x-ta.empty-state>
    @else
        {{-- Priority KPIs --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4 md:gap-6">
            @foreach ($workspace['kpis'] ?? [] as $kpi)
                @include('livewire.operator.meta.partials.kpi', ['kpi' => $kpi])
            @endforeach
        </div>

        {{-- Secondary KPIs --}}
        @if (! empty($workspace['kpis_secondary']))
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4 md:gap-6">
                @foreach ($workspace['kpis_secondary'] as $kpi)
                    @include('livewire.operator.meta.partials.kpi', ['kpi' => $kpi])
                @endforeach
            </div>
        @endif

        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Spend trend --}}
            <div class="lg:col-span-2">
                @php
                    $labels = $trend['labels'] ?? [];
                    $values = array_map(fn ($v) => $v === null ? null : (float) $v, $trend['values'] ?? []);
                    $chartOptions = [
                        'chart' => ['type' => 'area', 'height' => 300, 'fontFamily' => 'Outfit, sans-serif', 'toolbar' => ['show' => false]],
                        'colors' => ['#ea580c'],
                        'stroke' => ['curve' => 'smooth', 'width' => 2],
                        'fill' => ['type' => 'gradient', 'gradient' => ['opacityFrom' => 0.35, 'opacityTo' => 0.02]],
                        'dataLabels' => ['enabled' => false],
                        'series' => [['name' => $trend['label'] ?? 'Spend', 'data' => $values]],
                        'xaxis' => ['categories' => $labels, 'labels' => ['rotate' => -45, 'hideOverlappingLabels' => true]],
                        'grid' => ['borderColor' => '#e4e7ec', 'strokeDashArray' => 4],
                    ];
                @endphp
                @if (($trend['available'] ?? false))
                    <x-ta.chart-card :title="($trend['label'] ?? 'Trend').' trend'" :subtitle="$workspace['period_label'] ?? null" :options="$chartOptions" chartId="meta-overview-trend" />
                @else
                    <x-ta.section-card :title="($trend['label'] ?? 'Trend').' trend'">
                        <x-ta.empty-state title="Not enough daily points" :message="$trend['note'] ?? 'A trend needs at least two daily data points in this period.'" />
                    </x-ta.section-card>
                @endif
            </div>

            {{-- Result summary --}}
            <x-ta.section-card title="Result summary" subtitle="Platform-attributed Meta results.">
                @if ($primary && ($primary['human_label'] ?? null))
                    <div class="mb-4 rounded-xl bg-brand-50 p-4 dark:bg-brand-500/10">
                        <span class="text-xs uppercase text-brand-600 dark:text-brand-400">Primary result</span>
                        <div class="mt-1 flex items-baseline justify-between">
                            <span class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $primary['human_label'] }}</span>
                            <span class="text-lg font-bold text-gray-800 dark:text-white/90">{{ is_numeric($primary['count'] ?? null) ? number_format((float) $primary['count']) : '—' }}</span>
                        </div>
                    </div>
                @endif

                @if (! empty($resultMix['items']))
                    <ul class="flex flex-col gap-2">
                        @foreach (array_slice($resultMix['items'], 0, 6) as $item)
                            <li class="flex items-center justify-between text-sm">
                                <span class="text-gray-600 dark:text-gray-400">{{ $item['human_label'] ?? ($item['raw_action_type'] ?? '—') }}</span>
                                <span class="font-medium text-gray-800 dark:text-white/90">{{ is_numeric($item['count'] ?? null) ? number_format((float) $item['count']) : '—' }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $resultMix['note'] ?? 'No Meta actions observed for this period.' }}</p>
                @endif
            </x-ta.section-card>
        </div>

        {{-- Campaign snapshot --}}
        <div class="mt-6">
            <x-ta.section-card title="Campaign snapshot" subtitle="Top campaigns by spend in this period.">
                <x-slot:actions>
                    <a href="{{ route('operator.meta.campaigns', $asset) }}" class="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">All campaigns &rarr;</a>
                </x-slot:actions>

                @if (empty($campaigns))
                    <p class="text-sm text-gray-500 dark:text-gray-400">No delivered campaigns for this period.</p>
                @else
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        @foreach ($campaigns as $campaign)
                            <a href="{{ route('operator.meta.campaign', ['digitalAsset' => $asset, 'campaignId' => $campaign['entity_id'] ?? '']) }}"
                                class="rounded-xl border border-gray-100 p-4 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                                <span class="block truncate text-sm font-medium text-gray-800 dark:text-white/90">{{ $campaign['name'] ?? '—' }}</span>
                                <span class="mt-1 block text-xs text-gray-400">{{ $campaign['status'] ?? '' }}</span>
                                <div class="mt-3 flex items-baseline justify-between">
                                    <span class="text-xs text-gray-500 dark:text-gray-400">Spend</span>
                                    <span class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ is_numeric($campaign['spend'] ?? null) ? '$'.number_format((float) $campaign['spend'], 2) : '—' }}</span>
                                </div>
                                @if ($campaign['primary_result_human_label'] ?? null)
                                    <div class="mt-1 flex items-baseline justify-between">
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $campaign['primary_result_human_label'] }}</span>
                                        <span class="text-sm text-gray-700 dark:text-gray-300">{{ is_numeric($campaign['primary_result_count'] ?? null) ? number_format((float) $campaign['primary_result_count']) : '—' }}</span>
                                    </div>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endif
            </x-ta.section-card>
        </div>
    @endif
</div>
