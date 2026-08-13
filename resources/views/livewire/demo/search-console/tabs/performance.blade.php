@php
    $glance = $data['glance'] ?? [];
    $perf = $data['performance'] ?? [];
    $devices = $perf['devices'] ?? [];
    $maxDeviceClicks = max(1, (int) collect($devices)->max('clicks'));
    $brandNonbrand = $perf['brand_nonbrand'] ?? [];
    $diagnosis = $perf['diagnosis'] ?? [];
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Search performance</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Property-level clicks, impressions, CTR and position aggregates.</p>
        <p class="mt-1 text-xs text-gray-400">{{ $data['period_label'] ?? '' }} · {{ $data['compare_label'] ?? '' }}</p>
    </div>

    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <x-ta.metric-card label="Clicks" :value="$glance['clicks']['value'] ?? '—'" :delta="$glance['clicks']['secondary'] ?? null" :tone="$glance['clicks']['tone'] ?? 'neutral'" />
        <x-ta.metric-card label="Impressions" :value="$glance['impressions']['value'] ?? '—'" :delta="$glance['impressions']['secondary'] ?? null" :tone="$glance['impressions']['tone'] ?? 'neutral'" />
        <x-ta.metric-card label="CTR" :value="$glance['ctr']['value'] ?? '—'" :delta="$glance['ctr']['secondary'] ?? null" :tone="$glance['ctr']['tone'] ?? 'neutral'" />
        <x-ta.metric-card label="Avg position" :value="isset($glance['clicks']['avg_position']) ? number_format($glance['clicks']['avg_position'], 1) : '—'" delta="Impression-weighted · not global rank" :tone="'neutral'" />
    </div>

    <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Trend</h3>
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
        <p class="mt-2 text-[11px] text-gray-400">{{ $data['performance_trend']['note'] ?? '' }}</p>
        @if ($metric === 'position')
            <p class="mt-1 text-[11px] text-amber-700 dark:text-amber-400" title="Average position is impression-weighted across queries — not a single global rank.">Average position ≠ global rank — impression-weighted aggregate across queries.</p>
        @endif
    </section>

    <div class="grid gap-3 lg:grid-cols-2">
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Device</h3>
            <ul class="mt-3 space-y-3">
                @foreach ($devices as $row)
                    <li>
                        <div class="mb-1 flex items-center justify-between gap-2 text-xs">
                            <span class="font-medium text-gray-800 dark:text-white/90">{{ $row['device'] }}</span>
                            <span class="tabular-nums text-gray-500">
                                {{ number_format($row['clicks']) }} clicks · {{ number_format($row['impressions']) }} impr
                                · {{ $row['ctr'] }}% CTR · pos {{ $row['position'] }}
                            </span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/5">
                            <div class="h-full rounded-full bg-orange-500" style="width: {{ min(100, round(($row['clicks'] / $maxDeviceClicks) * 100)) }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Country</h3>
            <x-ta.table class="mt-3">
                <x-slot:head>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Country</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Clicks</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Impressions</th>
                </x-slot:head>
                @foreach ($perf['countries'] ?? [] as $row)
                    <tr>
                        <td class="px-3 py-2 text-sm font-medium text-gray-900 dark:text-white">{{ $row['country'] }}</td>
                        <td class="px-3 py-2 text-sm tabular-nums">{{ number_format($row['clicks']) }}</td>
                        <td class="px-3 py-2 text-sm tabular-nums">{{ number_format($row['impressions']) }}</td>
                    </tr>
                @endforeach
            </x-ta.table>
        </section>
    </div>

    <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="mb-3 flex items-center justify-between gap-2">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Brand vs non-brand</h3>
            <x-ta.badge color="light" size="sm">{{ $brandNonbrand['source'] ?? 'Derived' }}</x-ta.badge>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach (['brand' => 'Brand', 'nonbrand' => 'Non-brand'] as $key => $label)
                @php $row = $brandNonbrand[$key] ?? []; @endphp
                <div class="rounded-lg bg-slate-50 px-3 py-3 dark:bg-white/[0.03]">
                    <p class="text-xs font-medium text-gray-500">{{ $label }}</p>
                    <p class="mt-1 text-sm tabular-nums text-gray-800 dark:text-white/90">
                        {{ number_format($row['clicks'] ?? 0) }} clicks · {{ number_format($row['impressions'] ?? 0) }} impr
                        · {{ $row['ctr'] ?? '—' }}% CTR · pos {{ $row['position'] ?? '—' }}
                    </p>
                </div>
            @endforeach
        </div>
        @if (! empty($brandNonbrand['note']))
            <p class="mt-2 text-[11px] text-gray-400">{{ $brandNonbrand['note'] }}</p>
        @endif
    </section>

    <section class="rounded-2xl border border-amber-200 bg-amber-50/50 p-4 dark:border-amber-900/40 dark:bg-amber-950/20">
        <div class="mb-2 flex items-center gap-2">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Diagnosis</h3>
            <x-ta.badge color="warning" size="sm">{{ $diagnosis['source'] ?? 'Derived' }}</x-ta.badge>
        </div>
        <p class="text-sm text-gray-700 dark:text-gray-300">{{ $diagnosis['interpretation'] ?? '' }}</p>
        @if (! empty($diagnosis['disclaimer']))
            <p class="mt-2 text-[11px] text-gray-500">{{ $diagnosis['disclaimer'] }}</p>
        @endif
    </section>
</div>
