@php
    $indexing = $data['indexing'] ?? [];
    $coverage = $indexing['coverage'] ?? [];
    $urls = $indexing['urls'] ?? [];
    $sitemaps = $indexing['sitemaps'] ?? [];
    $reconciliation = $indexing['reconciliation'] ?? [];
    $byRole = $indexing['discoverability_by_role'] ?? [];
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Indexing</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $indexing['subtitle'] ?? '' }}</p>
        <p class="mt-1 text-xs text-gray-400">{{ $indexing['inspection_note'] ?? '' }}</p>
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

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Discoverability by role</h3>
            <x-ta.table class="mt-3">
                <x-slot:head>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Role</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">With impressions</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Inventory</th>
                </x-slot:head>
                @foreach ($byRole as $row)
                    <tr>
                        <td class="px-3 py-2 text-sm font-medium text-gray-900 dark:text-white">{{ $row['role'] }}</td>
                        <td class="px-3 py-2 text-sm tabular-nums">{{ number_format($row['with_impressions']) }}</td>
                        <td class="px-3 py-2 text-sm tabular-nums">{{ number_format($row['inventory']) }}</td>
                    </tr>
                @endforeach
            </x-ta.table>
        </section>
    @elseif ($index_sub === 'inspection')
        <x-ta.table>
            <x-slot:head>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">URL</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Google index state</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Sitemap</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Canonical</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Search visibility</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400"></th>
            </x-slot:head>
            @foreach ($urls as $row)
                <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                    <td class="px-4 py-2.5">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['path'] }}</p>
                        <p class="text-[11px] text-gray-400">{{ $row['role'] ?? '' }}</p>
                        @if (! empty($row['attention']))
                            <p class="mt-0.5 text-[11px] text-warning-700 dark:text-warning-400">{{ $row['attention'] }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-2.5 text-xs">
                        <x-ta.badge :color="match($row['index_state'] ?? '') { 'Indexed' => 'success', 'Unavailable' => 'error', default => 'warning' }" size="sm">{{ $row['index_state'] }}</x-ta.badge>
                    </td>
                    <td class="px-4 py-2.5 text-xs">{{ $row['sitemap'] ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-xs">
                        <span @class(['font-medium text-warning-700 dark:text-warning-400' => ($row['canonical'] ?? '') === 'Mismatch'])>{{ $row['canonical'] ?? '—' }}</span>
                    </td>
                    <td class="px-4 py-2.5 text-xs text-gray-600 dark:text-gray-300">{{ $row['search_visibility'] ?? '—' }}</td>
                    <td class="px-4 py-2.5">
                        <button type="button" wire:click="openUrl('{{ $row['id'] ?? $row['path'] }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Inspect</button>
                    </td>
                </tr>
            @endforeach
        </x-ta.table>
        <p class="text-[11px] text-gray-400">{{ $indexing['inspection_note'] ?? '' }}</p>
    @elseif ($index_sub === 'sitemaps')
        <x-ta.table>
            <x-slot:head>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Sitemap</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Submitted</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Last downloaded</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Discovered</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Warnings</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Errors</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Status</th>
            </x-slot:head>
            @foreach ($sitemaps as $row)
                <tr>
                    <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $row['path'] }}</td>
                    <td class="px-4 py-2.5 text-xs">{{ $row['submitted'] }}</td>
                    <td class="px-4 py-2.5 text-xs">{{ $row['last_downloaded'] }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ number_format($row['discovered']) }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ $row['warnings'] }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ $row['errors'] }}</td>
                    <td class="px-4 py-2.5">
                        <x-ta.badge :color="($row['status'] ?? '') === 'Success' ? 'success' : 'warning'" size="sm">{{ $row['status'] }}</x-ta.badge>
                    </td>
                </tr>
            @endforeach
        </x-ta.table>
    @else
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach (['website_urls' => 'Sitedeki URL', 'sitemap_urls' => 'Site haritasındaki URL', 'index_observed' => 'Dizinde görülen', 'priority_missing_sitemap' => 'Site haritasında olmayan öncelikli'] as $key => $label)
                <x-ta.metric-card :label="$label" :value="number_format($reconciliation[$key] ?? 0, 0, ',', '.')" />
            @endforeach
        </div>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Reconciliation gaps</h3>
            <ul class="mt-3 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                @foreach ($reconciliation['gaps'] ?? [] as $gap)
                    <li class="flex gap-2">
                        <span class="text-warning-500">·</span>
                        <span>{{ $gap }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
