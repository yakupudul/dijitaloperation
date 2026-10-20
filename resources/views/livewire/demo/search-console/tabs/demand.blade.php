@php
    $demand = $data['demand'] ?? [];
    $clusters = $demand['clusters'] ?? [];
    $momentum = $demand['momentum'] ?? [];
    $ownershipReviews = $demand['ownership_reviews'] ?? [];
@endphp

<div class="space-y-10">
    <div>
        <h2 class="section-title">Queries & demand</h2>
        <p class="mt-0.5 text-xs text-gray-500">Query clusters, explorer, momentum heuristics and ownership reviews.</p>
        <p class="mt-1 text-xs text-gray-500">{{ $demand['observed_query_note'] ?? '' }}</p>
    </div>

    <div class="inline-flex flex-wrap rounded-lg ring-1 ring-inset ring-gray-300 dark:ring-gray-700" role="tablist">
        @foreach (['clusters' => 'Clusters', 'queries' => 'Query explorer', 'momentum' => 'Momentum', 'ownership' => 'Ownership'] as $key => $label)
            <button type="button" wire:click="setDemandSub('{{ $key }}')" @class([
                'px-3 py-2 text-xs font-medium',
                'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' => $demand_sub === $key,
                'text-gray-600 dark:text-gray-300' => $demand_sub !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($demand_sub === 'clusters')
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($clusters as $cluster)
                <div class="min-w-0">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <x-ta.pill :tone="['error' => 'critical', 'warning' => 'look', 'success' => 'ok'][match($cluster['trend'] ?? '') { 'declining' => 'error', 'growing' => 'success', 'ctr_review' => 'warning', default => 'light' }] ?? 'info'">{{ ucfirst(str_replace('_', ' ', $cluster['trend'] ?? 'stable')) }}</x-ta.pill>
                            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $cluster['name'] }}</p>
                            <p class="mt-0.5 text-xs text-gray-500">{{ $cluster['intent'] ?? '' }}</p>
                        </div>
                        <button type="button" wire:click="openCluster('{{ $cluster['id'] }}')" class="link-neutral shrink-0 text-xs">Inspect</button>
                    </div>
                    <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <dt class="text-gray-400">Clicks</dt>
                            <dd class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($cluster['clicks']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-400">Impressions</dt>
                            <dd class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($cluster['impressions']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-400">CTR</dt>
                            <dd class="font-semibold tabular-nums">{{ $cluster['ctr'] }}%</dd>
                        </div>
                        <div>
                            <dt class="text-gray-400">Position</dt>
                            <dd class="font-semibold tabular-nums" title="Average position ≠ global rank">{{ $cluster['position'] }}</dd>
                        </div>
                    </dl>
                    <p class="mt-2 text-xs text-gray-500">Primary, {{ $cluster['primary_page'] }}, {{ $cluster['ownership_state'] ?? '' }}</p>
                </div>
            @endforeach
        </div>
    @elseif ($demand_sub === 'queries')
        <div class="max-w-full overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-gray-200 dark:border-gray-800">
                    <tr>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Query</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Clicks</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Impressions</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">CTR</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Position</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Page</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Trend</th>
            </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
            @foreach ($demand['queries'] ?? [] as $row)
                <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                    <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $row['query'] }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ number_format($row['clicks']) }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ number_format($row['impressions']) }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ $row['ctr'] }}%</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums" title="Average position ≠ global rank">{{ $row['position'] }}</td>
                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['page'] }}</td>
                    <td class="px-4 py-2.5">
                        <x-ta.pill :tone="['error' => 'critical', 'warning' => 'look', 'success' => 'ok'][match($row['trend'] ?? '') { 'declining' => 'error', 'growing' => 'success', 'ctr_review' => 'warning', default => 'light' }] ?? 'info'">{{ ucfirst(str_replace('_', ' ', $row['trend'] ?? 'stable')) }}</x-ta.pill>
                    </td>
                </tr>
            @endforeach
        </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-500">{{ $demand['observed_query_note'] ?? '' }}</p>
    @elseif ($demand_sub === 'momentum')
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach (['growing' => 'Growing', 'declining' => 'Declining', 'new' => 'New', 'lost' => 'Lost', 'ctr_review' => 'CTR review', 'striking_distance' => 'Striking distance'] as $key => $label)
                <section class="min-w-0">
                    <h3 class="section-title">{{ $label }}</h3>
                    <ul class="mt-2 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                        @forelse ($momentum[$key] ?? [] as $query)
                            <li class="truncate">{{ $query }}</li>
                        @empty
                            <li class="text-xs text-gray-500">No queries in this bucket</li>
                        @endforelse
                    </ul>
                </section>
            @endforeach
        </div>
        <p class="text-xs text-gray-500">{{ $data['search_momentum']['note'] ?? 'Heuristic momentum, relative to prior comparable window' }}</p>
    @else
        @foreach ($ownershipReviews as $review)
            <section class="min-w-0">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="text-xs text-gray-500">{{ $review['topic'] ?? '' }}, {{ $review['cluster'] ?? '' }}</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $review['state'] ?? '' }}</p>
                    </div>
                    <x-ta.pill tone="look">{{ $review['language'] ?? 'Review' }}</x-ta.pill>
                </div>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <div>
                        <p class="text-xs font-normal text-gray-500">Intended</p>
                        <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $review['intended_page'] ?? '—' }}</p>
                        <p class="text-xs text-gray-500">{{ $review['intended_source'] ?? '' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-normal text-gray-500">Observed share</p>
                        <ul class="mt-2 space-y-2">
                            @foreach ($review['observed'] ?? [] as $obs)
                                <li>
                                    <div class="mb-1 flex justify-between text-xs">
                                        <span class="font-medium text-gray-800 dark:text-white/90">{{ $obs['path'] }}</span>
                                        <span class="tabular-nums text-gray-500">{{ $obs['share_pct'] }}%</span>
                                    </div>
                                    <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5">
                                        <div class="h-full rounded-full bg-gray-400" style="width: {{ min(100, $obs['share_pct']) }}%"></div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
                @if (! empty($review['recommendation']))
                    <p class="mt-3 text-xs text-gray-600 dark:text-gray-300">{{ $review['recommendation'] }}</p>
                @endif
                @if (! empty($review['urls']))
                    <p class="mt-2 text-xs text-gray-500">URLs, {{ implode(', ', $review['urls']) }}</p>
                @endif
            </section>
        @endforeach
        <p class="text-xs text-gray-700 dark:text-gray-300">{{ $demand['observed_query_note'] ?? '' }}</p>
    @endif
</div>
