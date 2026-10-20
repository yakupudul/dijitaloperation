@php
    $indexing = $data['indexing'] ?? [];
    $coverage = $indexing['coverage'] ?? [];
    $urls = $indexing['urls'] ?? [];
    $sitemaps = $indexing['sitemaps'] ?? [];
    $reconciliation = $indexing['reconciliation'] ?? [];
    $byRole = $indexing['discoverability_by_role'] ?? [];
@endphp

<div class="space-y-10">
    <div>
        <h2 class="section-title">Indexing</h2>
        <p class="mt-0.5 text-xs text-gray-500">{{ $indexing['subtitle'] ?? '' }}</p>
        <p class="mt-1 text-xs text-gray-500">{{ $indexing['inspection_note'] ?? '' }}</p>
    </div>

    <div class="inline-flex flex-wrap rounded-lg ring-1 ring-inset ring-gray-300 dark:ring-gray-700" role="tablist">
        @foreach (['coverage' => 'Coverage', 'inspection' => 'URL inspection', 'sitemaps' => 'Sitemaps', 'reconciliation' => 'Reconciliation'] as $key => $label)
            <button type="button" wire:click="setIndexSub('{{ $key }}')" @class([
                'px-3 py-2 text-xs font-medium',
                'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' => $index_sub === $key,
                'text-gray-600 dark:text-gray-300' => $index_sub !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($index_sub === 'coverage')
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach (['indexed' => 'Dizinde', 'not_indexed' => 'Dizinde değil', 'unknown' => 'Bilinmiyor', 'excluded' => 'Hariç tutulan'] as $key => $label)
                <x-ta.metric-card :label="$label" :value="number_format($coverage[$key] ?? 0, 0, ',', '.')" />
            @endforeach
        </div>

        <section class="min-w-0">
            <h3 class="section-title">Discoverability by role</h3>
            <div class="max-w-full overflow-x-auto mt-3">
            <table class="min-w-full text-sm">
                <thead class="border-b border-gray-200 dark:border-gray-800">
                    <tr>
                    <th class="px-3 py-2 text-left text-xs font-normal text-gray-500">Role</th>
                    <th class="px-3 py-2 text-left text-xs font-normal text-gray-500">With impressions</th>
                    <th class="px-3 py-2 text-left text-xs font-normal text-gray-500">Inventory</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                @foreach ($byRole as $row)
                    <tr>
                        <td class="px-3 py-2 text-sm font-medium text-gray-900 dark:text-white">{{ $row['role'] }}</td>
                        <td class="px-3 py-2 text-sm tabular-nums">{{ number_format($row['with_impressions']) }}</td>
                        <td class="px-3 py-2 text-sm tabular-nums">{{ number_format($row['inventory']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            </table>
        </div>
        </section>
    @elseif ($index_sub === 'inspection')
        <div class="max-w-full overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-gray-200 dark:border-gray-800">
                    <tr>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">URL</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Google index state</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Sitemap</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Canonical</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Search visibility</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500"></th>
            </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
            @foreach ($urls as $row)
                <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                    <td class="px-4 py-2.5">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['path'] }}</p>
                        <p class="text-xs text-gray-500">{{ $row['role'] ?? '' }}</p>
                        @if (! empty($row['attention']))
                            <p class="mt-0.5 text-xs text-warning-700 dark:text-warning-400">{{ $row['attention'] }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-2.5 text-xs">
                        <x-ta.pill :tone="['error' => 'critical', 'warning' => 'look', 'success' => 'ok'][match($row['index_state'] ?? '') { 'Indexed' => 'success', 'Unavailable' => 'error', default => 'warning' }] ?? 'info'">{{ $row['index_state'] }}</x-ta.pill>
                    </td>
                    <td class="px-4 py-2.5 text-xs">{{ $row['sitemap'] ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-xs">
                        <span @class(['font-medium text-warning-700 dark:text-warning-400' => ($row['canonical'] ?? '') === 'Mismatch'])>{{ $row['canonical'] ?? '—' }}</span>
                    </td>
                    <td class="px-4 py-2.5 text-xs text-gray-600 dark:text-gray-300">{{ $row['search_visibility'] ?? '—' }}</td>
                    <td class="px-4 py-2.5">
                        <button type="button" wire:click="openUrl('{{ $row['id'] ?? $row['path'] }}')" class="link-neutral text-xs">Inspect</button>
                    </td>
                </tr>
            @endforeach
        </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-500">{{ $indexing['inspection_note'] ?? '' }}</p>
    @elseif ($index_sub === 'sitemaps')
        <div class="max-w-full overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-gray-200 dark:border-gray-800">
                    <tr>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Sitemap</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Submitted</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Last downloaded</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Discovered</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Warnings</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Errors</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Status</th>
            </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
            @foreach ($sitemaps as $row)
                <tr>
                    <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $row['path'] }}</td>
                    <td class="px-4 py-2.5 text-xs">{{ $row['submitted'] }}</td>
                    <td class="px-4 py-2.5 text-xs">{{ $row['last_downloaded'] }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ number_format($row['discovered']) }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ $row['warnings'] }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ $row['errors'] }}</td>
                    <td class="px-4 py-2.5">
                        <x-ta.pill :tone="['error' => 'critical', 'warning' => 'look', 'success' => 'ok'][($row['status'] ?? '') === 'Success' ? 'success' : 'warning'] ?? 'info'">{{ $row['status'] }}</x-ta.pill>
                    </td>
                </tr>
            @endforeach
        </tbody>
            </table>
        </div>
    @else
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach (['website_urls' => 'Sitedeki URL', 'sitemap_urls' => 'Site haritasındaki URL', 'index_observed' => 'Dizinde görülen', 'priority_missing_sitemap' => 'Site haritasında olmayan öncelikli'] as $key => $label)
                <x-ta.metric-card :label="$label" :value="number_format($reconciliation[$key] ?? 0, 0, ',', '.')" />
            @endforeach
        </div>

        <section class="min-w-0">
            <h3 class="section-title">Reconciliation gaps</h3>
            <ul class="mt-3 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                @foreach ($reconciliation['gaps'] ?? [] as $gap)
                    <li class="flex gap-2">
                        <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>
                        <span>{{ $gap }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
