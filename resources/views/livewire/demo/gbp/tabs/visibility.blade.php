@php
    $legend = [
        ['band' => '1–3', 'label' => 'Top local pack', 'color' => 'bg-emerald-600'],
        ['band' => '4–7', 'label' => 'Strong', 'color' => 'bg-amber-600'],
        ['band' => '8–12', 'label' => 'Watch', 'color' => 'bg-orange-600'],
        ['band' => '13+', 'label' => 'Weak', 'color' => 'bg-rose-600'],
        ['band' => '—', 'label' => 'Unavailable', 'color' => 'bg-slate-500'],
    ];
@endphp

<div class="space-y-5">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Local visibility</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $visibility['subtitle'] }}</p>
    </div>

    <div class="flex flex-col gap-3 rounded-xl bg-white p-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 lg:flex-row lg:flex-wrap lg:items-end">
        <label class="block min-w-[12rem] flex-1 text-xs text-gray-500">
            Keyword
            <select wire:model.live="keyword" class="mt-1 w-full rounded-lg border-gray-200 text-sm dark:border-gray-700 dark:bg-gray-900">
                @foreach ($visibility['keywords'] as $kw)
                    <option value="{{ $kw }}">{{ $kw }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-xs text-gray-500">
            Scan
            <select wire:model.live="scan" class="mt-1 w-full rounded-lg border-gray-200 text-sm dark:border-gray-700 dark:bg-gray-900">
                <option value="latest">Latest · {{ $currentScan['scanned_at'] }}</option>
                <option value="previous">Previous · {{ $previousScan['scanned_at'] }}</option>
            </select>
        </label>
        <div class="text-xs text-gray-500">
            Grid
            <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">{{ $currentScan['grid'] }} · {{ $currentScan['radius'] }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="toggleScanCompare" @class([
                'rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset',
                'bg-brand-500 text-white ring-brand-500' => $scan_compare,
                'text-gray-700 ring-gray-300 dark:text-gray-300 dark:ring-gray-700' => ! $scan_compare,
            ])>Compare previous scan</button>
            <div class="inline-flex rounded-lg ring-1 ring-inset ring-gray-300 dark:ring-gray-700" role="group" aria-label="Map mode">
                <button type="button" wire:click="setVisMode('rank')" @class(['px-3 py-2 text-xs font-medium', 'bg-gray-100 dark:bg-white/10' => $vis_mode === 'rank'])>Rank</button>
                <button type="button" wire:click="setVisMode('change')" @class(['px-3 py-2 text-xs font-medium', 'bg-gray-100 dark:bg-white/10' => $vis_mode === 'change'])>Change</button>
            </div>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-12">
        <div class="lg:col-span-8 xl:col-span-9">
            <div wire:key="gbp-vis-map-{{ md5($keyword.'|'.$vis_mode.'|'.($scan_compare ? '1' : '0').'|'.$scan) }}">
                <div class="gbp-map-shell" data-gbp-rank-map='@json($mapPayload)' role="region" aria-label="Interactive local rank map"></div>
            </div>
            <ul class="mt-2 flex flex-wrap gap-3 text-xs text-gray-500" aria-label="Rank legend">
                @foreach ($legend as $item)
                    <li class="inline-flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-full {{ $item['color'] }}"></span> {{ $item['band'] }} {{ $item['label'] }}</li>
                @endforeach
            </ul>
            <p class="mt-1 text-xs text-gray-400">Map visualizes geographically referenced rank observations. MapLibre/OpenFreeMap do not calculate Google rankings. Rank observations appear only after a real visibility scan.</p>
        </div>

        <aside class="space-y-3 lg:col-span-4 xl:col-span-3">
            <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Summary</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-2"><dt class="text-gray-500">Average observed rank</dt><dd class="font-semibold tabular-nums">{{ $currentScan['average_rank'] }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-gray-500">Top 3 coverage</dt><dd class="font-semibold tabular-nums">{{ $currentScan['top3_count'] }} / 25</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-gray-500">Top 10 coverage</dt><dd class="font-semibold tabular-nums">{{ $currentScan['top10_count'] }} / 25</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-gray-500">Best / weakest</dt><dd class="font-semibold tabular-nums">{{ $currentScan['best'] }} / {{ $currentScan['worst'] }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-gray-500">Scan</dt><dd class="text-right text-xs">{{ $currentScan['scanned_at'] }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-gray-500">Source</dt><dd class="text-right text-xs">{{ $currentScan['source'] }}</dd></div>
                </dl>
            </section>

            <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Selected point</h3>
                    @if ($selectedPoint)
                        <button type="button" wire:click="clearPoint" class="text-xs text-gray-500 hover:underline">Clear</button>
                    @endif
                </div>
                @if ($selectedPoint)
                    <dl class="mt-3 space-y-2 text-sm">
                        <div><dt class="text-xs text-gray-400">Observed rank</dt><dd class="text-lg font-semibold tabular-nums">#{{ $selectedPoint['rank'] }}</dd></div>
                        <div><dt class="text-xs text-gray-400">Location</dt><dd>{{ $selectedPoint['distance_km'] }} km {{ $selectedPoint['direction'] }} of business</dd></div>
                        <div><dt class="text-xs text-gray-400">Previous scan</dt><dd>#{{ $selectedPoint['previous_rank'] }}</dd></div>
                        <div>
                            <dt class="text-xs text-gray-400">Change</dt>
                            <dd>
                                @if ($selectedPoint['delta'] > 0)
                                    Improved by {{ $selectedPoint['delta'] }} positions
                                @elseif ($selectedPoint['delta'] < 0)
                                    Declined by {{ abs($selectedPoint['delta']) }} positions
                                @else
                                    Stable
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-400">Observed top results</dt>
                            <ol class="mt-1 list-decimal space-y-0.5 pl-4 text-xs text-gray-700 dark:text-gray-300">
                                @foreach ($selectedPoint['top_results'] as $res)
                                    <li>{{ $res['name'] }}</li>
                                @endforeach
                            </ol>
                        </div>
                    </dl>
                @else
                    <p class="mt-2 text-sm text-gray-500">Select a scan point on the map or in the point data table.</p>
                @endif
            </section>

            <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Geographic weakness</h3>
                <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">{{ $currentScan['weakness'] }}</p>
                <p class="mt-2 text-xs text-gray-500">Inspect competitors and local relevance signals. No ranking cause claimed.</p>
            </section>
        </aside>
    </div>

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Geographic coverage</h3>
        <ul class="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-5">
            @foreach ($visibility['coverage_regions'] as $region)
                <li class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                    <p class="font-medium text-gray-900 dark:text-white">{{ $region['region'] }}</p>
                    <p class="text-xs text-gray-500">{{ $region['state'] }}</p>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Keyword comparison</h3>
            <p class="text-xs text-gray-400">Query-level evidence — no combined Local Visibility Score.</p>
        </div>
        <table class="min-w-full text-left text-sm">
            <thead class="text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-2">Keyword</th>
                    <th class="px-4 py-2">Avg rank</th>
                    <th class="px-4 py-2">Top 3</th>
                    <th class="px-4 py-2">Top 10</th>
                    <th class="px-4 py-2">Change</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($visibility['comparison'] as $row)
                    <tr @class(['bg-brand-50/40 dark:bg-brand-500/5' => $row['keyword'] === $keyword])>
                        <td class="px-4 py-2">
                            <button type="button" wire:click="setKeyword('{{ $row['keyword'] }}')" class="font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $row['keyword'] }}</button>
                        </td>
                        <td class="px-4 py-2 tabular-nums">{{ $row['avg_rank'] }}</td>
                        <td class="px-4 py-2 tabular-nums">{{ $row['top3_pct'] }}%</td>
                        <td class="px-4 py-2 tabular-nums">{{ $row['top10_pct'] }}%</td>
                        <td class="px-4 py-2">{{ $row['change_label'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Local visibility opportunities</h3>
        <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
            @foreach ($visibility['opportunities'] ?? [] as $opp)
                <li>{{ $opp }}</li>
            @endforeach
        </ul>
        <p class="mt-2 text-xs text-gray-400">Evidence · collected local rank observations + Brand Context + Website</p>
    </section>

    <details class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <summary class="cursor-pointer text-sm font-semibold text-gray-900 dark:text-white">View point data</summary>
        <p class="mt-1 text-xs text-gray-400">Accessible fallback if the map cannot load. Coordinates are fixture geography — not calculated from a spreadsheet matrix.</p>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-3 py-2">Point</th>
                        <th class="px-3 py-2">Distance / direction</th>
                        <th class="px-3 py-2">Current rank</th>
                        <th class="px-3 py-2">Previous</th>
                        <th class="px-3 py-2">Change</th>
                        <th class="px-3 py-2">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($points as $p)
                        <tr>
                            <td class="px-3 py-1.5 tabular-nums text-gray-500">{{ $p['id'] }}</td>
                            <td class="px-3 py-1.5">{{ $p['distance_km'] }} km {{ $p['direction'] }}</td>
                            <td class="px-3 py-1.5 font-semibold tabular-nums">{{ $p['rank'] }}</td>
                            <td class="px-3 py-1.5 tabular-nums">{{ $p['previous_rank'] }}</td>
                            <td class="px-3 py-1.5 tabular-nums">
                                @if ($p['delta'] > 0) ↑ {{ $p['delta'] }}
                                @elseif ($p['delta'] < 0) ↓ {{ abs($p['delta']) }}
                                @else —
                                @endif
                            </td>
                            <td class="px-3 py-1.5">
                                <button type="button" wire:click="selectPoint('{{ $p['id'] }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Inspect</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
</div>
