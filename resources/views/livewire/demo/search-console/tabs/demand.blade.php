@php
    $demand = $data['demand'] ?? [];
    $clusters = $demand['clusters'] ?? [];
    $momentum = $demand['momentum'] ?? [];
    $ownershipReviews = $demand['ownership_reviews'] ?? [];
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Queries & demand</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Query clusters, explorer, momentum heuristics and ownership reviews.</p>
        <p class="mt-1 text-xs text-gray-400">{{ $demand['observed_query_note'] ?? '' }}</p>
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
                <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <x-ta.badge :color="match($cluster['trend'] ?? '') { 'declining' => 'error', 'growing' => 'success', 'ctr_review' => 'warning', default => 'light' }" size="sm">{{ ucfirst(str_replace('_', ' ', $cluster['trend'] ?? 'stable')) }}</x-ta.badge>
                            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $cluster['name'] }}</p>
                            <p class="mt-0.5 text-xs text-gray-500">{{ $cluster['intent'] ?? '' }}</p>
                        </div>
                        <button type="button" wire:click="openCluster('{{ $cluster['id'] }}')" class="shrink-0 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Inspect</button>
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
                    <p class="mt-2 text-[11px] text-gray-500">Primary · {{ $cluster['primary_page'] }} · {{ $cluster['ownership_state'] ?? '' }}</p>
                </div>
            @endforeach
        </div>
    @elseif ($demand_sub === 'queries')
        <x-ta.table>
            <x-slot:head>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Query</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Clicks</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Impressions</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">CTR</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Position</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Page</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Trend</th>
            </x-slot:head>
            @foreach ($demand['queries'] ?? [] as $row)
                <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                    <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $row['query'] }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ number_format($row['clicks']) }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ number_format($row['impressions']) }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ $row['ctr'] }}%</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums" title="Average position ≠ global rank">{{ $row['position'] }}</td>
                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['page'] }}</td>
                    <td class="px-4 py-2.5">
                        <x-ta.badge :color="match($row['trend'] ?? '') { 'declining' => 'error', 'growing' => 'success', 'ctr_review' => 'warning', default => 'light' }" size="sm">{{ ucfirst(str_replace('_', ' ', $row['trend'] ?? 'stable')) }}</x-ta.badge>
                    </td>
                </tr>
            @endforeach
        </x-ta.table>
        <p class="text-[11px] text-gray-400">{{ $demand['observed_query_note'] ?? '' }}</p>
    @elseif ($demand_sub === 'momentum')
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach (['growing' => 'Growing', 'declining' => 'Declining', 'new' => 'New', 'lost' => 'Lost', 'ctr_review' => 'CTR review', 'striking_distance' => 'Striking distance'] as $key => $label)
                <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $label }}</h3>
                    <ul class="mt-2 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                        @forelse ($momentum[$key] ?? [] as $query)
                            <li class="truncate">{{ $query }}</li>
                        @empty
                            <li class="text-xs text-gray-400">No queries in this bucket</li>
                        @endforelse
                    </ul>
                </section>
            @endforeach
        </div>
        <p class="text-[11px] text-gray-400">{{ $data['search_momentum']['note'] ?? 'Heuristic momentum · relative to prior comparable window' }}</p>
    @else
        @foreach ($ownershipReviews as $review)
            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="text-xs text-gray-400">{{ $review['topic'] ?? '' }} · {{ $review['cluster'] ?? '' }}</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $review['state'] ?? '' }}</p>
                    </div>
                    <x-ta.badge color="warning" size="sm">{{ $review['language'] ?? 'Review' }}</x-ta.badge>
                </div>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <div>
                        <p class="text-xs font-medium uppercase text-gray-400">Intended</p>
                        <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $review['intended_page'] ?? '—' }}</p>
                        <p class="text-[11px] text-gray-500">{{ $review['intended_source'] ?? '' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-gray-400">Observed share</p>
                        <ul class="mt-2 space-y-2">
                            @foreach ($review['observed'] ?? [] as $obs)
                                <li>
                                    <div class="mb-1 flex justify-between text-xs">
                                        <span class="font-medium text-gray-800 dark:text-white/90">{{ $obs['path'] }}</span>
                                        <span class="tabular-nums text-gray-500">{{ $obs['share_pct'] }}%</span>
                                    </div>
                                    <div class="h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/5">
                                        <div class="h-full rounded-full bg-violet-500" style="width: {{ min(100, $obs['share_pct']) }}%"></div>
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
                    <p class="mt-2 text-[11px] text-gray-400">URLs · {{ implode(', ', $review['urls']) }}</p>
                @endif
            </section>
        @endforeach
        <p class="text-[11px] text-blue-700 dark:text-blue-300">{{ $demand['observed_query_note'] ?? '' }}</p>
    @endif
</div>
