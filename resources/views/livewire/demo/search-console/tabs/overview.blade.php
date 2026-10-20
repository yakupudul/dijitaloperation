@php
    $glance = $data['glance'] ?? [];
    $momentum = $data['search_momentum'] ?? [];
    $discoverability = $data['discoverability'] ?? [];
    $maxStage = max(1, (int) collect($discoverability['stages'] ?? [])->max('count'));
@endphp

<div class="space-y-10">
    <div class="grid grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-3 xl:grid-cols-4">
        <x-ta.metric-card label="Tıklama" :value="$glance['clicks']['value'] ?? '—'" :delta="$glance['clicks']['secondary'] ?? null" :tone="$glance['clicks']['tone'] ?? 'neutral'" />
        <x-ta.metric-card label="Gösterim" :value="$glance['impressions']['value'] ?? '—'" :delta="$glance['impressions']['secondary'] ?? null" :tone="$glance['impressions']['tone'] ?? 'neutral'" />
        <x-ta.metric-card label="TO (CTR)" :value="$glance['ctr']['value'] ?? '—'" :delta="$glance['ctr']['secondary'] ?? null" :tone="$glance['ctr']['tone'] ?? 'neutral'" />
        <x-ta.metric-card label="Dikkat isteyen" :value="$glance['search_attention']['value'] ?? '—'" :delta="$glance['search_attention']['secondary'] ?? null" :tone="$glance['search_attention']['tone'] ?? 'neutral'" />
    </div>

    <div class="grid gap-10 lg:grid-cols-12">
        <section class="min-w-0 lg:col-span-7">
            <div class="mb-2 flex items-center justify-between gap-2">
                <h2 class="section-title">Needs attention</h2>
                <span class="text-xs text-gray-500">{{ count($data['needs_attention'] ?? []) }} signals</span>
            </div>
            <ul class="divide-y divide-gray-200 dark:divide-gray-800">
                @foreach (array_slice($data['needs_attention'] ?? [], 0, 5) as $item)
                    <li class="flex items-center justify-between gap-3 py-2">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <x-ta.pill :tone="['error' => 'critical', 'warning' => 'look', 'success' => 'ok'][match($item['severity'] ?? '') { 'Critical', 'critical', 'High', 'high' => 'error', 'Medium', 'medium' => 'warning', default => 'light' }] ?? 'info'">{{ $item['severity'] }}</x-ta.pill>
                                <span class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $item['title'] }}</span>
                            </div>
                            <p class="mt-0.5 truncate text-xs text-gray-600 dark:text-gray-300">{{ $item['metric'] }}</p>
                            <p class="truncate text-xs text-gray-500">{{ $item['scope'] ?? '' }}</p>
                        </div>
                        <button type="button" wire:click="openAttention('{{ $item['id'] }}')" class="link-neutral shrink-0 text-xs">{{ $item['action'] ?? 'Inspect' }}</button>
                    </li>
                @endforeach
            </ul>
            @if (count($data['needs_attention'] ?? []) > 5)
                <details class="mt-2">
                    <summary class="cursor-pointer text-xs font-medium text-brand-600 dark:text-brand-400">Show all signals</summary>
                    <ul class="mt-2 divide-y divide-gray-200 dark:divide-gray-800">
                        @foreach (array_slice($data['needs_attention'] ?? [], 5) as $item)
                            <li class="flex items-center justify-between gap-3 py-2">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <x-ta.pill :tone="['error' => 'critical', 'warning' => 'look', 'success' => 'ok'][match($item['severity'] ?? '') { 'Critical', 'critical', 'High', 'high' => 'error', 'Medium', 'medium' => 'warning', default => 'light' }] ?? 'info'">{{ $item['severity'] }}</x-ta.pill>
                                        <span class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $item['title'] }}</span>
                                    </div>
                                    <p class="mt-0.5 truncate text-xs text-gray-600 dark:text-gray-300">{{ $item['metric'] }}</p>
                                </div>
                                <button type="button" wire:click="openAttention('{{ $item['id'] }}')" class="link-neutral shrink-0 text-xs">{{ $item['action'] ?? 'Inspect' }}</button>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </section>

        <section class="min-w-0 lg:col-span-5">
            <div class="mb-2 flex items-center justify-between gap-2">
                <h2 class="section-title">Performance trend</h2>
                <button type="button" wire:click="setTab('performance')" class="link-neutral text-xs">Open</button>
            </div>
            <div class="mb-2 flex flex-wrap gap-1">
                @foreach (['clicks' => 'Clicks', 'impressions' => 'Impressions', 'ctr' => 'CTR', 'position' => 'Position'] as $key => $label)
                    <button type="button" wire:click="setMetric('{{ $key }}')" @class([
                        'rounded-md px-2 py-1 text-xs font-medium',
                        'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' => $metric === $key,
                        'text-gray-500 hover:text-gray-800 dark:text-gray-400' => $metric !== $key,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
            <div data-chart='@json($performanceChartOptions)' aria-label="Search performance trend" class="min-h-[200px]"></div>
            <p class="mt-1 text-xs text-gray-500">{{ $data['period_label'] ?? '' }}, {{ $data['compare_label'] ?? '' }}</p>
        </section>
    </div>

    <section class="min-w-0">
        <div class="mb-3 flex items-center justify-between gap-2">
            <div>
                <h2 class="section-title">Search momentum</h2>
                <p class="text-xs text-gray-500">{{ $momentum['note'] ?? 'Heuristic cluster momentum' }}</p>
            </div>
            <button type="button" wire:click="setDemandSub('momentum')" class="link-neutral text-xs">Open demand</button>
        </div>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
            @foreach (['growing' => 'Growing', 'new' => 'New', 'declining' => 'Declining', 'lost' => 'Lost', 'ctr_review' => 'CTR review', 'striking_distance' => 'Striking distance'] as $key => $label)
                <div class="border-b border-gray-200 py-2.5 dark:border-gray-800">
                    <p class="text-xs text-gray-500">{{ $label }}</p>
                    <p class="text-xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $momentum[$key] ?? 0 }}</p>
                </div>
            @endforeach
        </div>
    </section>

    <section class="min-w-0">
        <div class="mb-2 flex items-center justify-between gap-2">
            <div>
                <h2 class="section-title">Discoverability funnel</h2>
                <p class="text-xs text-gray-500">{{ $discoverability['subtitle'] ?? '' }}</p>
            </div>
            <button type="button" wire:click="setTab('indexing')" class="link-neutral text-xs">Indexing</button>
        </div>
        <ul class="space-y-2">
            @foreach ($discoverability['stages'] ?? [] as $stage)
                <li>
                    <div class="mb-1 flex items-center justify-between gap-2 text-xs">
                        <span class="font-medium text-gray-800 dark:text-white/90">{{ $stage['stage'] }}</span>
                        <span class="tabular-nums text-gray-500">{{ number_format($stage['count']) }}, {{ $stage['provenance'] }}</span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5">
                        <div class="h-full rounded-full bg-gray-500" style="width: {{ min(100, round(($stage['count'] / $maxStage) * 100)) }}%"></div>
                    </div>
                </li>
            @endforeach
        </ul>
        <p class="mt-2 text-xs text-gray-700 dark:text-gray-300">{{ $discoverability['note'] ?? '' }}</p>
    </section>

    <section>
        <div class="mb-2 flex items-center justify-between">
            <h2 class="section-title">Page pulse</h2>
            <button type="button" wire:click="setTab('pages')" class="link-neutral text-xs">Open pages</button>
        </div>
        <div class="max-w-full overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-gray-200 dark:border-gray-800">
                    <tr>
                <th class="px-3 py-2 text-left text-xs font-normal text-gray-500">Page</th>
                <th class="px-3 py-2 text-left text-xs font-normal text-gray-500">Clicks</th>
                <th class="px-3 py-2 text-left text-xs font-normal text-gray-500">Trend</th>
                <th class="px-3 py-2 text-left text-xs font-normal text-gray-500">Clusters</th>
                <th class="px-3 py-2 text-left text-xs font-normal text-gray-500">Attention</th>
                <th class="px-3 py-2 text-left text-xs font-normal text-gray-500"></th>
            </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
            @foreach ($data['page_pulse'] ?? [] as $row)
                <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                    <td class="px-3 py-2">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['path'] }}</p>
                        <p class="text-xs text-gray-500">{{ $row['content_role'] }}, {{ $row['title'] ?? '' }}</p>
                    </td>
                    <td class="px-3 py-2 text-sm tabular-nums">{{ number_format($row['clicks']) }}</td>
                    <td class="px-3 py-2">
                        <x-ta.pill :tone="['error' => 'critical', 'warning' => 'look', 'success' => 'ok'][match($row['state'] ?? '') { 'Growing' => 'success', 'Review' => 'warning', default => 'light' }] ?? 'info'">{{ $row['trend'] ?? $row['state'] }}</x-ta.pill>
                        @if (isset($row['trend_pct']))
                            <span class="ml-1 text-xs tabular-nums text-gray-400">{{ $row['trend_pct'] > 0 ? '+' : '' }}{{ $row['trend_pct'] }}%</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-xs text-gray-500">{{ $row['cluster_count'] ?? 0 }}</td>
                    <td class="px-3 py-2 text-xs text-gray-500">{{ $row['website_attention'] ?? '—' }}</td>
                    <td class="px-3 py-2"><button type="button" wire:click="openPage('{{ $row['id'] ?? $row['path'] }}')" class="link-neutral text-xs">Inspect</button></td>
                </tr>
            @endforeach
        </tbody>
            </table>
        </div>
    </section>

    <div class="grid gap-10 lg:grid-cols-2">
        <section class="min-w-0">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="section-title">Opportunities</h2>
            </div>
            <ul class="space-y-2">
                @foreach (array_slice($data['opportunities'] ?? [], 0, 4) as $opp)
                    <li class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="flex items-center gap-1.5">
                                <x-ta.pill :tone="['error' => 'critical', 'warning' => 'look', 'success' => 'ok'][match($opp['priority'] ?? '') { 'High' => 'error', 'Medium' => 'warning', default => 'info' }] ?? 'info'">{{ $opp['priority'] }}</x-ta.pill>
                                <span class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $opp['title'] }}</span>
                            </div>
                            <p class="mt-0.5 text-xs text-gray-500">{{ $opp['metric'] }}</p>
                        </div>
                        @if (! empty($opp['tab']))
                            <button type="button" wire:click="setTab('{{ $opp['tab'] }}')" class="link-neutral shrink-0 text-xs">{{ $opp['cta'] ?? 'Open' }}</button>
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
                                    <x-ta.pill :tone="['error' => 'critical', 'warning' => 'look', 'success' => 'ok'][match($opp['priority'] ?? '') { 'High' => 'error', 'Medium' => 'warning', default => 'info' }] ?? 'info'">{{ $opp['priority'] }}</x-ta.pill>
                                    <span class="ml-1 text-sm text-gray-800 dark:text-white/90">{{ $opp['title'] }}</span>
                                </div>
                                @if (! empty($opp['tab']))
                                    <button type="button" wire:click="setTab('{{ $opp['tab'] }}')" class="link-neutral shrink-0 text-xs">{{ $opp['cta'] ?? 'Open' }}</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </section>

        <section class="min-w-0">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="section-title">Recent outcomes</h2>
                <button type="button" wire:click="setOps('outcomes')" class="link-neutral text-xs">Operations</button>
            </div>
            <ul class="space-y-2">
                @foreach ($data['recent_outcomes'] ?? [] as $o)
                    <li class="border-b border-gray-200 py-2.5 dark:border-gray-800">
                        <div class="flex items-start justify-between gap-2">
                            <span class="text-sm text-gray-800 dark:text-white/90">{{ $o['title'] }}</span>
                            <span @class([
                                'shrink-0 text-xs font-semibold',
                                'text-success-700 dark:text-success-400' => ($o['state'] ?? '') === 'Improvement observed',
                                'text-warning-700 dark:text-warning-400' => ($o['state'] ?? '') !== 'Improvement observed',
                            ])>{{ $o['state'] }}</span>
                        </div>
                        @if (! empty($o['note']))
                            <p class="mt-1 text-xs text-gray-500">{{ $o['note'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    @include('livewire.demo.search-console.tabs.relationships')

</div>
