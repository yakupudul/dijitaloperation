@php
    $glance = $data['glance'] ?? [];
    $perf = $data['performance'] ?? [];
    $devices = $perf['devices'] ?? [];
    $maxDeviceClicks = max(1, (int) collect($devices)->max('clicks'));
    $brandNonbrand = $perf['brand_nonbrand'] ?? [];
    $diagnosis = $perf['diagnosis'] ?? [];
@endphp

<div class="space-y-10">
    <div>
        <h2 class="section-title">Search performance</h2>
        <p class="mt-0.5 text-xs text-gray-500">Property-level clicks, impressions, CTR and position aggregates.</p>
        <p class="mt-1 text-xs text-gray-500">{{ $data['period_label'] ?? '' }}, {{ $data['compare_label'] ?? '' }}</p>
    </div>

    <div class="grid grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-3 xl:grid-cols-4">
        <x-ta.metric-card label="Tıklama" :value="$glance['clicks']['value'] ?? '—'" :delta="$glance['clicks']['secondary'] ?? null" :tone="$glance['clicks']['tone'] ?? 'neutral'" />
        <x-ta.metric-card label="Gösterim" :value="$glance['impressions']['value'] ?? '—'" :delta="$glance['impressions']['secondary'] ?? null" :tone="$glance['impressions']['tone'] ?? 'neutral'" />
        <x-ta.metric-card label="TO (CTR)" :value="$glance['ctr']['value'] ?? '—'" :delta="$glance['ctr']['secondary'] ?? null" :tone="$glance['ctr']['tone'] ?? 'neutral'" />
        <x-ta.metric-card label="Ort. sıra" :value="isset($glance['clicks']['avg_position']) ? number_format($glance['clicks']['avg_position'], 1) : '—'" delta="Impression-weighted, not global rank" :tone="'neutral'" />
    </div>

    <section class="min-w-0">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h3 class="section-title">Trend</h3>
            <div class="inline-flex rounded-lg ring-1 ring-inset ring-gray-300 dark:ring-gray-700" role="tablist">
                @foreach (['clicks' => 'Clicks', 'impressions' => 'Impressions', 'ctr' => 'CTR', 'position' => 'Position'] as $key => $label)
                    <button type="button" wire:click="setMetric('{{ $key }}')" @class([
                        'px-3 py-1.5 text-xs font-medium',
                        'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' => $metric === $key,
                        'text-gray-600 dark:text-gray-300' => $metric !== $key,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
        </div>
        <div data-chart='@json($performanceChartOptions)' aria-label="Search performance trend chart" class="min-h-[240px]"></div>
        <p class="mt-2 text-xs text-gray-500">{{ $data['performance_trend']['note'] ?? '' }}</p>
        @if ($metric === 'position')
            <p class="mt-1 text-xs text-warning-700 dark:text-warning-400" title="Average position is impression-weighted across queries — not a single global rank.">Average position ≠ global rank — impression-weighted aggregate across queries.</p>
        @endif
    </section>

    <div class="grid gap-10 lg:grid-cols-2">
        <section class="min-w-0">
            <h3 class="section-title">Device</h3>
            <ul class="mt-3 space-y-3">
                @foreach ($devices as $row)
                    <li>
                        <div class="mb-1 flex items-center justify-between gap-2 text-xs">
                            <span class="font-medium text-gray-800 dark:text-white/90">{{ $row['device'] }}</span>
                            <span class="tabular-nums text-gray-500">
                                {{ number_format($row['clicks']) }} clicks, {{ number_format($row['impressions']) }} impr, {{ $row['ctr'] }}% CTR, pos {{ $row['position'] }}
                            </span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5">
                            <div class="h-full rounded-full bg-gray-400" style="width: {{ min(100, round(($row['clicks'] / $maxDeviceClicks) * 100)) }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="min-w-0">
            <h3 class="section-title">Country</h3>
            <div class="max-w-full overflow-x-auto mt-3">
            <table class="min-w-full text-sm">
                <thead class="border-b border-gray-200 dark:border-gray-800">
                    <tr>
                    <th class="px-3 py-2 text-left text-xs font-normal text-gray-500">Country</th>
                    <th class="px-3 py-2 text-left text-xs font-normal text-gray-500">Clicks</th>
                    <th class="px-3 py-2 text-left text-xs font-normal text-gray-500">Impressions</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                @foreach ($perf['countries'] ?? [] as $row)
                    <tr>
                        <td class="px-3 py-2 text-sm font-medium text-gray-900 dark:text-white">{{ $row['country'] }}</td>
                        <td class="px-3 py-2 text-sm tabular-nums">{{ number_format($row['clicks']) }}</td>
                        <td class="px-3 py-2 text-sm tabular-nums">{{ number_format($row['impressions']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            </table>
        </div>
        </section>
    </div>

    <section class="min-w-0">
        <div class="mb-3 flex items-center justify-between gap-2">
            <h3 class="section-title">Brand vs non-brand</h3>
            <x-ta.pill tone="info">{{ $brandNonbrand['source'] ?? 'Derived' }}</x-ta.pill>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach (['brand' => 'Brand', 'nonbrand' => 'Non-brand'] as $key => $label)
                @php $row = $brandNonbrand[$key] ?? []; @endphp
                <div class="border-b border-gray-200 py-3 dark:border-gray-800">
                    <p class="text-xs font-medium text-gray-500">{{ $label }}</p>
                    <p class="mt-1 text-sm tabular-nums text-gray-800 dark:text-white/90">
                        {{ number_format($row['clicks'] ?? 0) }} clicks, {{ number_format($row['impressions'] ?? 0) }} impr, {{ $row['ctr'] ?? '—' }}% CTR, pos {{ $row['position'] ?? '—' }}
                    </p>
                </div>
            @endforeach
        </div>
        @if (! empty($brandNonbrand['note']))
            <p class="mt-2 text-xs text-gray-500">{{ $brandNonbrand['note'] }}</p>
        @endif
    </section>

    <section class="tone-look rounded-lg p-4">
        <div class="mb-2 flex items-center gap-2">
            <h3 class="section-title">Diagnosis</h3>
            <x-ta.pill tone="look">{{ $diagnosis['source'] ?? 'Derived' }}</x-ta.pill>
        </div>
        <p class="text-sm text-gray-700 dark:text-gray-300">{{ $diagnosis['interpretation'] ?? '' }}</p>
        @if (! empty($diagnosis['disclaimer']))
            <p class="mt-2 text-xs text-gray-500">{{ $diagnosis['disclaimer'] }}</p>
        @endif
    </section>
</div>
