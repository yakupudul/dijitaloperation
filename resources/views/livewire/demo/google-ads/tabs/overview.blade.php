@php
    $glance = $data['glance'];
    $pacing = $data['pacing'];
    $search = $data['search'];
    $lp = $data['landing_pages'];
    $meas = $data['measurement'];
    $maxOffering = max(1, collect($data['spend_by_offering'])->max('spend'));
@endphp

<div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <x-ta.metric-card label="Spend" :value="$glance['spend']['value']" :delta="$glance['spend']['secondary']" :tone="$glance['spend']['tone']" />
        <x-ta.metric-card label="Primary conversions" :value="$glance['conversions']['value']" :delta="$glance['conversions']['secondary']" :tone="$glance['conversions']['tone']" />
        <x-ta.metric-card label="Cost / primary conversion" :value="$glance['cpa']['value']" :delta="$glance['cpa']['secondary']" :tone="$glance['cpa']['tone']" />
        <x-ta.metric-card label="Budget pacing" :value="$glance['pacing']['value']" :delta="$glance['pacing']['secondary']" :tone="$glance['pacing']['tone']" />
    </div>

    <p class="text-xs text-gray-400">{{ $data['business_goal']['goal'] }} · {{ $data['business_goal']['primary_conversion'] }} · {{ $data['conversion_lag_note'] }}</p>

    <div class="grid gap-3 lg:grid-cols-12">
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03] lg:col-span-7">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Needs attention</h2>
                <span class="text-xs text-gray-400">{{ count($data['needs_attention']) }} signals</span>
            </div>
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($data['needs_attention'] as $item)
                    <li class="flex items-center justify-between gap-3 py-2.5">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-ta.badge :color="match($item['severity']) { 'Critical' => 'error', 'High' => 'error', 'Medium' => 'warning', default => 'light' }" size="sm">{{ $item['severity'] }}</x-ta.badge>
                                <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ $item['title'] }}</span>
                            </div>
                            <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-300">{{ $item['metric'] }}</p>
                            <p class="text-[11px] text-gray-400">{{ $item['scope'] }}</p>
                        </div>
                        <button type="button" wire:click="openAttention('{{ $item['id'] }}')" class="shrink-0 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $item['action'] }} →</button>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03] lg:col-span-5">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Budget pacing</h2>
            <p class="mt-0.5 text-[11px] text-gray-400">{{ $pacing['source'] }}</p>
            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                <div><dt class="text-xs text-gray-400">Monthly budget</dt><dd class="font-semibold tabular-nums">₺{{ number_format($pacing['monthly_budget']) }}</dd></div>
                <div><dt class="text-xs text-gray-400">State</dt><dd class="font-semibold text-amber-700 dark:text-amber-400">{{ $pacing['state'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Expected by today</dt><dd class="tabular-nums">₺{{ number_format($pacing['expected_spend']) }}</dd></div>
                <div><dt class="text-xs text-gray-400">Actual</dt><dd class="tabular-nums">₺{{ number_format($pacing['actual_spend']) }}</dd></div>
            </dl>
            <div class="mt-3 space-y-2">
                <x-ta.progress-bar :value="$pacing['elapsed_pct']" :max="100" tone="primary" label="Expected by today" />
                <x-ta.progress-bar :value="$pacing['spend_pct']" :max="100" tone="warning" label="Actual spend" />
            </div>
            <p class="mt-2 text-xs text-gray-500">Ahead by ₺{{ number_format($pacing['ahead_by']) }} · Remaining ₺{{ number_format($pacing['remaining']) }} · Projected ₺{{ number_format($pacing['projected']) }}</p>
        </section>
    </div>

    <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="mb-2 flex items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Performance trend</h2>
            <span class="text-xs text-gray-400">{{ $data['period_label'] }} · {{ $data['performance_trend']['compare_label'] }}</span>
        </div>
        <div data-chart='@json($performanceChartOptions)' aria-label="Spend and primary conversions trend" class="min-h-[220px]"></div>
        <p class="mt-1 text-xs text-gray-500">Chart summary: spend and primary lead conversions over the selected window.</p>
    </section>

    <section>
        <div class="mb-2 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Campaign portfolio</h2>
            <button type="button" wire:click="setTab('campaigns')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open campaigns</button>
        </div>
        <x-ta.table>
            <x-slot:head>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Campaign</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Status</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Spend</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Leads</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">CPA</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Pacing</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Attention</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400"></th>
            </x-slot:head>
            @foreach ($data['campaigns'] as $c)
                <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                    <td class="px-4 py-2.5">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $c['name'] }}</p>
                        <p class="text-[11px] text-gray-400">{{ $c['type'] }} · {{ $c['offering'] }} · {{ $c['market'] === 'United Kingdom' ? 'UK' : $c['market'] }}</p>
                    </td>
                    <td class="px-4 py-2.5"><x-ta.badge color="success" size="sm">{{ $c['status'] }}</x-ta.badge></td>
                    <td class="px-4 py-2.5 text-sm tabular-nums text-gray-700 dark:text-gray-300">₺{{ number_format($c['spend']) }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">{{ $c['leads'] }}</td>
                    <td class="px-4 py-2.5 text-sm tabular-nums">₺{{ number_format($c['cpa']) }}</td>
                    <td class="px-4 py-2.5"><x-ta.badge :color="match($c['pacing']) { 'Ahead', 'Constrained' => 'warning', 'Behind' => 'info', default => 'success' }" size="sm">{{ $c['pacing'] }}</x-ta.badge></td>
                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $c['attention_primary'] ?? '—' }}@if (count($c['attention']) > 1) <span class="text-gray-400">+{{ count($c['attention']) - 1 }}</span>@endif</td>
                    <td class="px-4 py-2.5"><button type="button" wire:click="openCampaign('{{ $c['id'] }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open</button></td>
                </tr>
            @endforeach
        </x-ta.table>
    </section>

    <div class="grid gap-3 lg:grid-cols-2">
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Search demand</h2>
                <button type="button" wire:click="setTab('search_demand')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open Search & demand</button>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-3 text-sm">
                <div><p class="text-xs text-gray-400">Search terms observed</p><p class="text-xl font-semibold tabular-nums">{{ number_format($search['terms_observed']) }}</p></div>
                <div><p class="text-xs text-gray-400">Aligned high-intent</p><p class="text-xl font-semibold tabular-nums">{{ $search['aligned_high_intent_pct'] }}%</p></div>
                <div><p class="text-xs text-gray-400">Spend requiring review</p><p class="text-xl font-semibold tabular-nums text-amber-700 dark:text-amber-400">₺{{ number_format($search['review_spend']) }}</p></div>
                <div><p class="text-xs text-gray-400">Decision Inbox</p><p class="text-xl font-semibold tabular-nums">{{ $search['inbox_count'] }}</p></div>
            </div>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Landing pages</h2>
                <button type="button" wire:click="setTab('landing_pages')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Inspect landing pages</button>
            </div>
            <div class="mt-3 grid grid-cols-3 gap-3 text-sm">
                <div><p class="text-xs text-gray-400">Active destinations</p><p class="text-xl font-semibold tabular-nums">{{ $lp['active'] }}</p></div>
                <div><p class="text-xs text-gray-400">Need Website review</p><p class="text-xl font-semibold tabular-nums text-amber-700 dark:text-amber-400">{{ $lp['need_review'] }}</p></div>
                <div><p class="text-xs text-gray-400">Spend exposed</p><p class="text-xl font-semibold tabular-nums">₺{{ number_format($lp['exposure_attention'] / 1000, 1) }}K</p></div>
            </div>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Measurement</h2>
                <button type="button" wire:click="setTab('measurement')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open measurement</button>
            </div>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($meas['matrix'] as $row)
                    <li class="flex items-center justify-between gap-2">
                        <span class="text-gray-700 dark:text-gray-300">{{ $row['action'] }}</span>
                        <x-ta.badge :color="match($row['state']) { 'Healthy' => 'success', 'Needs mapping', 'No recent signal' => 'warning', default => 'light' }" size="sm">{{ $row['state'] }}</x-ta.badge>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Recent outcomes</h2>
                <button type="button" wire:click="setOps('outcomes')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Operations</button>
            </div>
            <ul class="mt-3 space-y-2">
                @foreach ($data['recent_outcomes'] as $o)
                    <li class="flex items-center justify-between gap-2 text-sm">
                        <span class="text-gray-800 dark:text-white/90">{{ $o['title'] }}</span>
                        <span @class([
                            'text-xs font-semibold',
                            'text-emerald-700 dark:text-emerald-400' => $o['state'] === 'Improvement observed',
                            'text-amber-700 dark:text-amber-400' => $o['state'] !== 'Improvement observed',
                        ])>{{ $o['state'] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($data['opportunities'] as $opp)
            <button type="button" wire:click="setTab('{{ $opp['tab'] }}')" class="rounded-2xl border border-gray-200 bg-white p-4 text-left transition hover:bg-gray-50 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:bg-white/[0.05]">
                <x-ta.badge :color="match($opp['priority']) { 'High' => 'error', 'Medium' => 'warning', default => 'info' }" size="sm">{{ $opp['priority'] }}</x-ta.badge>
                <p class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">{{ $opp['title'] }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ $opp['metric'] }}</p>
                <p class="mt-2 text-xs font-medium text-brand-600 dark:text-brand-400">{{ $opp['cta'] }} →</p>
            </button>
        @endforeach
    </div>

    <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Spend by offering</h2>
        <ul class="mt-3 space-y-2">
            @foreach ($data['spend_by_offering'] as $row)
                <li>
                    <div class="mb-1 flex justify-between text-xs text-gray-500">
                        <span>{{ $row['offering'] }}</span>
                        <span class="tabular-nums">₺{{ number_format($row['spend'] / 1000, 1) }}K</span>
                    </div>
                    <x-ta.progress-bar :value="$row['spend']" :max="$maxOffering" tone="primary" />
                </li>
            @endforeach
        </ul>
    </section>
</div>

@if ($selectedAttention)
    <x-demo.gads-drawer :title="$selectedAttention['title']" :subtitle="$selectedAttention['scope']" :severity="$selectedAttention['severity']">
        <div>
            <p class="text-xs text-gray-400">What happened</p>
            <p class="font-medium text-gray-900 dark:text-white">{{ $selectedAttention['metric'] }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-400">Why this matters</p>
            <p class="text-gray-700 dark:text-gray-300">Operational signal requiring agency review — open the related Finding for evidence.</p>
        </div>
        <div>
            <p class="text-xs text-gray-400">Recommended next action</p>
            <p class="text-gray-700 dark:text-gray-300">{{ $selectedAttention['action'] }} in {{ str_replace('_', ' ', $selectedAttention['tab']) }}.</p>
        </div>
        @if (! empty($selectedAttention['finding_id']))
            <button type="button" wire:click="openFinding('{{ $selectedAttention['finding_id'] }}')" class="rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white">Open related Finding</button>
        @endif
        <button type="button" wire:click="setTab('{{ $selectedAttention['tab'] }}')" class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Go to workspace</button>
    </x-demo.gads-drawer>
@endif

@include('livewire.demo.partials._opportunity-card', ['opportunity' => null])
