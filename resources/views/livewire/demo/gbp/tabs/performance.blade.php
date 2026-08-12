@php
    $perf = $data['performance'];
    $discovery = $perf['discovery'];
    $actions = $perf['actions'];
    $queries = $perf['queries'];
@endphp

<div class="space-y-5">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Performance</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $perf['subtitle'] }}</p>
        <p class="mt-1 text-xs text-gray-400">{{ $perf['period'] }} · {{ $discovery['source'] }}</p>
    </div>

    <div class="inline-flex rounded-lg ring-1 ring-inset ring-gray-300 dark:ring-gray-700" role="tablist" aria-label="Performance sections">
        @foreach (['discovery' => 'Discovery', 'actions' => 'Actions', 'queries' => 'Search queries'] as $key => $label)
            <button type="button" role="tab" wire:click="setPerfSub('{{ $key }}')" @class([
                'px-3 py-2 text-xs font-medium',
                'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' => $perf_sub === $key,
                'text-gray-600 dark:text-gray-300' => $perf_sub !== $key,
            ]) aria-selected="{{ $perf_sub === $key ? 'true' : 'false' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if ($perf_sub === 'discovery')
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <p class="text-xs text-gray-500">Search impressions</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($discovery['search_impressions']) }}</p>
                <p class="mt-1 text-xs text-emerald-700 dark:text-emerald-400">+{{ $discovery['search_delta'] }}% {{ $perf['previous_label'] }}</p>
            </div>
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <p class="text-xs text-gray-500">Maps impressions</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($discovery['maps_impressions']) }}</p>
                <p class="mt-1 text-xs text-emerald-700 dark:text-emerald-400">+{{ $discovery['maps_delta'] }}% {{ $perf['previous_label'] }}</p>
            </div>
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <p class="text-xs text-gray-500">Total observed profile impressions</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($discovery['total_impressions']) }}</p>
                <p class="mt-1 text-xs text-gray-400">Search + Maps · not Reach</p>
            </div>
        </div>
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Discovery trend</h3>
            <p class="mt-1 text-xs text-gray-400">Search vs Maps impressions · {{ $perf['period'] }}</p>
            <div class="mt-3" data-chart='@json($discoveryChartOptions)' aria-label="Search and Maps impressions chart"></div>
            <p class="mt-2 text-xs text-gray-500">Chart summary: Search impressions lead Maps; both show modest positive period comparison in Demo fixtures.</p>
        </section>
    @elseif ($perf_sub === 'actions')
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <p class="text-xs text-gray-500">Website clicks</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($actions['website_clicks']) }}</p>
                <p class="mt-1 text-xs text-emerald-700 dark:text-emerald-400">+{{ $actions['website_delta'] }}% {{ $perf['previous_label'] }}</p>
            </div>
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <p class="text-xs text-gray-500">Call clicks</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($actions['call_clicks']) }}</p>
                <p class="mt-1 text-xs text-emerald-700 dark:text-emerald-400">+{{ $actions['call_delta'] }}% {{ $perf['previous_label'] }}</p>
            </div>
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <p class="text-xs text-gray-500">Direction requests</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($actions['direction_requests']) }}</p>
                <p class="mt-1 text-xs text-emerald-700 dark:text-emerald-400">+{{ $actions['direction_delta'] }}% {{ $perf['previous_label'] }}</p>
            </div>
        </div>
        <p class="text-xs text-amber-700 dark:text-amber-400">{{ $actions['note'] }}</p>
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Customer actions trend</h3>
            <div class="mt-3" data-chart='@json($actionsChartOptions)' aria-label="Customer actions trend chart"></div>
            <p class="mt-2 text-xs text-gray-500">Source · {{ $actions['source'] }}</p>
        </section>
    @else
        <div>
            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Search queries</h3>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Search terms through which this location was discovered.</p>
            <p class="mt-1 text-xs text-gray-400">Month-oriented window · {{ $queries['period'] }} · Intent · {{ $queries['intent_provenance'] }}</p>
        </div>

        <div class="flex flex-wrap gap-2">
            <label class="text-xs text-gray-500">
                Filter
                <select wire:model.live="query_filter" class="mt-1 block rounded-lg border-gray-200 text-sm dark:border-gray-700 dark:bg-gray-900">
                    @foreach (['all' => 'All', 'Brand' => 'Brand', 'Service' => 'Service', 'Local service' => 'Local', 'Discovery' => 'Discovery', 'Growing' => 'Growing', 'Declining' => 'Declining', 'Website gap' => 'Website gap', 'Tracked' => 'Tracked on map'] as $val => $label)
                        <option value="{{ $val }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-gray-100 text-xs uppercase text-gray-500 dark:border-gray-700">
                    <tr>
                        <th class="px-4 py-2">Query</th>
                        <th class="px-4 py-2">Impressions</th>
                        <th class="px-4 py-2">Change</th>
                        <th class="px-4 py-2">Intent</th>
                        <th class="px-4 py-2">Related offering</th>
                        <th class="px-4 py-2">Website coverage</th>
                        <th class="px-4 py-2">Local rank</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($queryRows as $row)
                        <tr>
                            <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">{{ $row['query'] }}</td>
                            <td class="px-4 py-2 tabular-nums">{{ number_format($row['impressions']) }}</td>
                            <td class="px-4 py-2">{{ $row['change'] }}</td>
                            <td class="px-4 py-2"><span class="text-xs">{{ $row['intent'] }}</span> <span class="text-[10px] text-gray-400">{{ $queries['intent_provenance'] }}</span></td>
                            <td class="px-4 py-2 text-xs text-gray-600 dark:text-gray-300">{{ $row['offering'] }}</td>
                            <td class="px-4 py-2 text-xs">{{ $row['website'] }}</td>
                            <td class="px-4 py-2">
                                @if ($row['tracked'])
                                    <button type="button" wire:click="setKeyword('{{ $row['query'] }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Tracked · Inspect</button>
                                @else
                                    <span class="text-xs text-gray-400">Not tracked</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-6 text-sm text-gray-500">No queries match this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
