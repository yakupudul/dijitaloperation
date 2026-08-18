@php
    $glance = $data['glance'] ?? [];
    $momentum = $data['search_momentum'] ?? [];
    $discoverability = $data['discoverability'] ?? [];
    $maxStage = max(1, (int) collect($discoverability['stages'] ?? [])->max('count'));
@endphp

<div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <x-ta.metric-card label="Clicks" :value="$glance['clicks']['value'] ?? '—'" :delta="$glance['clicks']['secondary'] ?? null" :tone="$glance['clicks']['tone'] ?? 'neutral'" />
        <x-ta.metric-card label="Impressions" :value="$glance['impressions']['value'] ?? '—'" :delta="$glance['impressions']['secondary'] ?? null" :tone="$glance['impressions']['tone'] ?? 'neutral'" />
        <x-ta.metric-card label="CTR" :value="$glance['ctr']['value'] ?? '—'" :delta="$glance['ctr']['secondary'] ?? null" :tone="$glance['ctr']['tone'] ?? 'neutral'" />
        <x-ta.metric-card label="Search attention" :value="$glance['search_attention']['value'] ?? '—'" :delta="$glance['search_attention']['secondary'] ?? null" :tone="$glance['search_attention']['tone'] ?? 'neutral'" />
    </div>

    <div class="grid gap-3 lg:grid-cols-12">
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03] lg:col-span-7">
            <div class="mb-2 flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Needs attention</h2>
                <span class="text-xs text-gray-400">{{ count($data['needs_attention'] ?? []) }} signals</span>
            </div>
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach (array_slice($data['needs_attention'] ?? [], 0, 5) as $item)
                    <li class="flex items-center justify-between gap-3 py-2">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <x-ta.badge :color="match($item['severity'] ?? '') { 'Critical', 'critical', 'High', 'high' => 'error', 'Medium', 'medium' => 'warning', default => 'light' }" size="sm">{{ $item['severity'] }}</x-ta.badge>
                                <span class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $item['title'] }}</span>
                            </div>
                            <p class="mt-0.5 truncate text-xs text-gray-600 dark:text-gray-300">{{ $item['metric'] }}</p>
                            <p class="truncate text-[11px] text-gray-400">{{ $item['scope'] ?? '' }}</p>
                        </div>
                        <button type="button" wire:click="openAttention('{{ $item['id'] }}')" class="shrink-0 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $item['action'] ?? 'Inspect' }} →</button>
                    </li>
                @endforeach
            </ul>
            @if (count($data['needs_attention'] ?? []) > 5)
                <details class="mt-2">
                    <summary class="cursor-pointer text-xs font-medium text-brand-600 dark:text-brand-400">Show all signals</summary>
                    <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach (array_slice($data['needs_attention'] ?? [], 5) as $item)
                            <li class="flex items-center justify-between gap-3 py-2">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <x-ta.badge :color="match($item['severity'] ?? '') { 'Critical', 'critical', 'High', 'high' => 'error', 'Medium', 'medium' => 'warning', default => 'light' }" size="sm">{{ $item['severity'] }}</x-ta.badge>
                                        <span class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $item['title'] }}</span>
                                    </div>
                                    <p class="mt-0.5 truncate text-xs text-gray-600 dark:text-gray-300">{{ $item['metric'] }}</p>
                                </div>
                                <button type="button" wire:click="openAttention('{{ $item['id'] }}')" class="shrink-0 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $item['action'] ?? 'Inspect' }} →</button>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03] lg:col-span-5">
            <div class="mb-2 flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Performance trend</h2>
                <button type="button" wire:click="setTab('performance')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open</button>
            </div>
            <div class="mb-2 flex flex-wrap gap-1">
                @foreach (['clicks' => 'Clicks', 'impressions' => 'Impressions', 'ctr' => 'CTR', 'position' => 'Position'] as $key => $label)
                    <button type="button" wire:click="setMetric('{{ $key }}')" @class([
                        'rounded-md px-2 py-1 text-[11px] font-medium',
                        'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300' => $metric === $key,
                        'text-gray-500 hover:text-gray-800 dark:text-gray-400' => $metric !== $key,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
            <div data-chart='@json($performanceChartOptions)' aria-label="Search performance trend" class="min-h-[200px]"></div>
            <p class="mt-1 text-[11px] text-gray-400">{{ $data['period_label'] ?? '' }} · {{ $data['compare_label'] ?? '' }}</p>
        </section>
    </div>

    <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="mb-3 flex items-center justify-between gap-2">
            <div>
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Search momentum</h2>
                <p class="text-[11px] text-gray-400">{{ $momentum['note'] ?? 'Heuristic cluster momentum' }}</p>
            </div>
            <button type="button" wire:click="setDemandSub('momentum')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open demand</button>
        </div>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
            @foreach (['growing' => 'Growing', 'new' => 'New', 'declining' => 'Declining', 'lost' => 'Lost', 'ctr_review' => 'CTR review', 'striking_distance' => 'Striking distance'] as $key => $label)
                <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-white/[0.03]">
                    <p class="text-[11px] text-gray-500">{{ $label }}</p>
                    <p class="text-xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $momentum[$key] ?? 0 }}</p>
                </div>
            @endforeach
        </div>
    </section>

    <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="mb-2 flex items-center justify-between gap-2">
            <div>
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Discoverability funnel</h2>
                <p class="text-[11px] text-gray-400">{{ $discoverability['subtitle'] ?? '' }}</p>
            </div>
            <button type="button" wire:click="setTab('indexing')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Indexing</button>
        </div>
        <ul class="space-y-2">
            @foreach ($discoverability['stages'] ?? [] as $stage)
                <li>
                    <div class="mb-1 flex items-center justify-between gap-2 text-xs">
                        <span class="font-medium text-gray-800 dark:text-white/90">{{ $stage['stage'] }}</span>
                        <span class="tabular-nums text-gray-500">{{ number_format($stage['count']) }} · {{ $stage['provenance'] }}</span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/5">
                        <div class="h-full rounded-full bg-blue-500" style="width: {{ min(100, round(($stage['count'] / $maxStage) * 100)) }}%"></div>
                    </div>
                </li>
            @endforeach
        </ul>
        <p class="mt-2 text-[11px] text-blue-700 dark:text-blue-300">{{ $discoverability['note'] ?? '' }}</p>
    </section>

    <section>
        <div class="mb-2 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Page pulse</h2>
            <button type="button" wire:click="setTab('pages')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open pages</button>
        </div>
        <x-ta.table>
            <x-slot:head>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Page</th>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Clicks</th>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Trend</th>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Clusters</th>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Attention</th>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400"></th>
            </x-slot:head>
            @foreach ($data['page_pulse'] ?? [] as $row)
                <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                    <td class="px-3 py-2">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['path'] }}</p>
                        <p class="text-[11px] text-gray-400">{{ $row['content_role'] }} · {{ $row['title'] ?? '' }}</p>
                    </td>
                    <td class="px-3 py-2 text-sm tabular-nums">{{ number_format($row['clicks']) }}</td>
                    <td class="px-3 py-2">
                        <x-ta.badge :color="match($row['state'] ?? '') { 'Growing' => 'success', 'Review' => 'warning', default => 'light' }" size="sm">{{ $row['trend'] ?? $row['state'] }}</x-ta.badge>
                        @if (isset($row['trend_pct']))
                            <span class="ml-1 text-[11px] tabular-nums text-gray-400">{{ $row['trend_pct'] > 0 ? '+' : '' }}{{ $row['trend_pct'] }}%</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-xs text-gray-500">{{ $row['cluster_count'] ?? 0 }}</td>
                    <td class="px-3 py-2 text-xs text-gray-500">{{ $row['website_attention'] ?? '—' }}</td>
                    <td class="px-3 py-2"><button type="button" wire:click="openPage('{{ $row['id'] ?? $row['path'] }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Inspect</button></td>
                </tr>
            @endforeach
        </x-ta.table>
    </section>

    <div class="grid gap-3 lg:grid-cols-2">
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Opportunities</h2>
            </div>
            <ul class="space-y-2">
                @foreach (array_slice($data['opportunities'] ?? [], 0, 4) as $opp)
                    <li class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="flex items-center gap-1.5">
                                <x-ta.badge :color="match($opp['priority'] ?? '') { 'High' => 'error', 'Medium' => 'warning', default => 'info' }" size="sm">{{ $opp['priority'] }}</x-ta.badge>
                                <span class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $opp['title'] }}</span>
                            </div>
                            <p class="mt-0.5 text-xs text-gray-500">{{ $opp['metric'] }}</p>
                        </div>
                        @if (! empty($opp['tab']))
                            <button type="button" wire:click="setTab('{{ $opp['tab'] }}')" class="shrink-0 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $opp['cta'] ?? 'Open' }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if (count($data['opportunities'] ?? []) > 4)
                <details class="mt-2">
                    <summary class="cursor-pointer text-xs font-medium text-brand-600 dark:text-brand-400">More opportunities</summary>
                    <ul class="mt-2 space-y-2">
                        @foreach (array_slice($data['opportunities'] ?? [], 4) as $opp)
                            <li class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <x-ta.badge :color="match($opp['priority'] ?? '') { 'High' => 'error', 'Medium' => 'warning', default => 'info' }" size="sm">{{ $opp['priority'] }}</x-ta.badge>
                                    <span class="ml-1 text-sm text-gray-800 dark:text-white/90">{{ $opp['title'] }}</span>
                                </div>
                                @if (! empty($opp['tab']))
                                    <button type="button" wire:click="setTab('{{ $opp['tab'] }}')" class="shrink-0 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $opp['cta'] ?? 'Open' }}</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Recent outcomes</h2>
                <button type="button" wire:click="setOps('outcomes')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Operations</button>
            </div>
            <ul class="space-y-2">
                @foreach ($data['recent_outcomes'] ?? [] as $o)
                    <li class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-white/[0.03]">
                        <div class="flex items-start justify-between gap-2">
                            <span class="text-sm text-gray-800 dark:text-white/90">{{ $o['title'] }}</span>
                            <span @class([
                                'shrink-0 text-xs font-semibold',
                                'text-emerald-700 dark:text-emerald-400' => ($o['state'] ?? '') === 'Improvement observed',
                                'text-amber-700 dark:text-amber-400' => ($o['state'] ?? '') !== 'Improvement observed',
                            ])>{{ $o['state'] }}</span>
                        </div>
                        @if (! empty($o['note']))
                            <p class="mt-1 text-[11px] text-gray-500">{{ $o['note'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    @include('livewire.demo.search-console.tabs.relationships')

    @include('livewire.demo.partials._opportunity-card', ['opportunity' => null])
</div>
