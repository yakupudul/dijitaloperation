@php
    $comp = $data['competitors'];
    $compMapPayload = [
        'mode' => 'rank',
        'business' => [
            'name' => $identity['title'],
            'lat' => $identity['lat'],
            'lng' => $identity['lng'],
            'address' => $identity['location_line'],
        ],
        'points' => collect($comp['rows'])->map(fn ($row, $i) => [
            'id' => 'c-'.$i,
            'lat' => $row['lat'],
            'lng' => $row['lng'],
            'rank' => null,
            'delta' => null,
            'label' => $row['name'],
        ])->values()->all(),
    ];
@endphp

<div class="space-y-5">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Local competitors</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $comp['subtitle'] }}</p>
        <p class="mt-1 text-xs text-gray-400">{{ $comp['note'] }}</p>
    </div>

    <div class="grid gap-4 lg:grid-cols-12">
        <div class="lg:col-span-7">
            <div wire:key="gbp-competitors-map">
                <div class="gbp-map-shell" data-gbp-rank-map='@json($compMapPayload)' role="region" aria-label="Observed competitors map"></div>
            </div>
        </div>
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 lg:col-span-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Observed Top 3 presence</h3>
            <p class="mt-1 text-xs text-gray-400">{{ $comp['presence_label'] }}</p>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($comp['presence'] as $row)
                    <li class="flex items-center justify-between gap-2">
                        <span @class(['font-medium text-gray-900 dark:text-white' => $row['name'] === 'Atlas Dental Ankara'])>{{ $row['name'] }}</span>
                        <span class="tabular-nums text-gray-700 dark:text-gray-300">{{ $row['top3'] }} / 25</span>
                    </li>
                @endforeach
            </ul>
            <p class="mt-3 text-xs text-gray-500">Not market share. Traceable observed presence only.</p>
        </section>
    </div>

    <section class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <table class="min-w-full text-left text-sm">
            <caption class="sr-only">Observed local competitors</caption>
            <thead class="border-b border-gray-100 text-xs uppercase text-gray-500 dark:border-gray-700">
                <tr>
                    <th class="px-4 py-2">Competitor</th>
                    <th class="px-4 py-2">Distance</th>
                    <th class="px-4 py-2">Observed categories</th>
                    <th class="px-4 py-2">Rating</th>
                    <th class="px-4 py-2">Reviews</th>
                    <th class="px-4 py-2">Grid presence</th>
                    <th class="px-4 py-2">Strongest tracked queries</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($comp['rows'] as $row)
                    <tr>
                        <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</td>
                        <td class="px-4 py-2 tabular-nums">{{ $row['distance_km'] }} km</td>
                        <td class="px-4 py-2 text-xs">{{ $row['category'] }}</td>
                        <td class="px-4 py-2 tabular-nums">{{ $row['rating'] }}</td>
                        <td class="px-4 py-2 tabular-nums">{{ number_format($row['reviews']) }}</td>
                        <td class="px-4 py-2 text-xs">Appeared in Top 3 at {{ $row['top3'] }} / 25 scan points</td>
                        <td class="px-4 py-2 text-xs text-gray-600 dark:text-gray-300">{{ $row['queries'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <p class="text-xs text-gray-500">Competitor has substantially more recent review activity in the Demo evidence — observation, not a causal ranking explanation.</p>
</div>
