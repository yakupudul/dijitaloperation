@php
    $glance = $data['glance'];
    $pacing = $data['pacing'];
    $mix = $data['result_mix'];
    $audience = $data['audience'];
    $funnel = $data['funnel'];
    $meas = $data['measurement'];
    $maxMixSpend = max(1, (float) collect($mix['items'])->max('spend'));
    $maxPlacement = max(1, (float) collect($audience['placements'] ?? [])->max('spend'));
    $maxDest = max(1, (float) collect($funnel['destinations'] ?? [])->max('spend'));
@endphp

<div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <x-ta.metric-card label="Spend" :value="$glance['spend']['value']" :delta="$glance['spend']['secondary']" :tone="$glance['spend']['tone']" />
        <x-ta.metric-card label="Result Mix" :value="$glance['result_mix']['value']" :delta="$glance['result_mix']['secondary']" :tone="$glance['result_mix']['tone']" />
        <x-ta.metric-card label="Cost / primary" :value="$glance['cost_primary']['value']" :delta="$glance['cost_primary']['secondary']" :tone="$glance['cost_primary']['tone']" />
        <x-ta.metric-card label="Budget pacing" :value="$glance['pacing']['value']" :delta="$glance['pacing']['secondary']" :tone="$glance['pacing']['tone']" />
    </div>

    <p class="text-xs text-gray-400">{{ $data['business_goal']['goal'] ?? '' }} · {{ $mix['note'] ?? 'Result types are not summed' }} · {{ $data['conversion_lag_note'] ?? '' }}</p>

    <div class="grid gap-3 lg:grid-cols-12">
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03] lg:col-span-4">
            <div class="mb-2 flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Result Mix</h2>
                <span class="text-[11px] text-violet-700 dark:text-violet-300">Do not sum types</span>
            </div>
            <ul class="space-y-2">
                @foreach ($mix['items'] as $row)
                    <li>
                        <div class="mb-1 flex items-center justify-between gap-2 text-xs">
                            <span class="font-medium text-gray-800 dark:text-white/90">{{ $row['label'] }}</span>
                            <span class="tabular-nums text-gray-500">{{ number_format($row['count']) }} · ₺{{ number_format($row['spend']) }}</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/5">
                            <div @class([
                                'h-full rounded-full',
                                'bg-emerald-500' => ($row['tone'] ?? '') === 'emerald',
                                'bg-blue-500' => ($row['tone'] ?? '') === 'blue',
                                'bg-amber-500' => ($row['tone'] ?? '') === 'amber',
                                'bg-violet-500' => ($row['tone'] ?? '') === 'violet',
                                'bg-rose-500' => ($row['tone'] ?? '') === 'rose',
                                'bg-slate-400' => ! in_array($row['tone'] ?? '', ['emerald', 'blue', 'amber', 'violet', 'rose'], true),
                            ]) style="width: {{ min(100, round(($row['spend'] / $maxMixSpend) * 100)) }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03] lg:col-span-5">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Needs attention</h2>
                <span class="text-xs text-gray-400">{{ count($data['needs_attention']) }} signals</span>
            </div>
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($data['needs_attention'] as $item)
                    <li class="flex items-center justify-between gap-3 py-2">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <x-ta.badge :color="match($item['severity']) { 'Critical' => 'error', 'High' => 'error', 'Medium' => 'warning', default => 'light' }" size="sm">{{ $item['severity'] }}</x-ta.badge>
                                <span class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $item['title'] }}</span>
                            </div>
                            <p class="mt-0.5 truncate text-xs text-gray-600 dark:text-gray-300">{{ $item['metric'] }}</p>
                            <p class="truncate text-[11px] text-gray-400">{{ $item['scope'] }}</p>
                        </div>
                        <button type="button" wire:click="openAttention('{{ $item['id'] }}')" class="shrink-0 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $item['action'] }} →</button>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03] lg:col-span-3">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Budget pacing</h2>
            <p class="mt-0.5 text-[11px] text-gray-400">{{ $pacing['source'] }}</p>
            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                <div><dt class="text-xs text-gray-400">Monthly</dt><dd class="font-semibold tabular-nums">₺{{ number_format($pacing['monthly_budget']) }}</dd></div>
                <div><dt class="text-xs text-gray-400">State</dt><dd class="font-semibold text-amber-700 dark:text-amber-400">{{ $pacing['state'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Expected</dt><dd class="tabular-nums">₺{{ number_format($pacing['expected_spend']) }}</dd></div>
                <div><dt class="text-xs text-gray-400">Actual</dt><dd class="tabular-nums">₺{{ number_format($pacing['actual_spend']) }}</dd></div>
            </dl>
            <div class="mt-3 space-y-2">
                <x-ta.progress-bar :value="$pacing['elapsed_pct']" :max="100" tone="primary" label="Month elapsed" />
                <x-ta.progress-bar :value="$pacing['spend_pct']" :max="100" tone="warning" label="Actual spend" />
            </div>
            <p class="mt-2 text-[11px] text-gray-500">Ahead ₺{{ number_format($pacing['ahead_by']) }} · Left ₺{{ number_format($pacing['remaining']) }}</p>
        </section>
    </div>

    <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="mb-2 flex items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Performance trend</h2>
            <span class="text-xs text-gray-400">{{ $data['period_label'] }} · {{ $data['performance_trend']['compare_label'] ?? '' }}</span>
        </div>
        <div data-chart='@json($performanceChartOptions)' aria-label="Spend and primary results trend" class="min-h-[220px]"></div>
        <p class="mt-1 text-xs text-gray-500">Spend vs primary result type for the selected window — types stay separate.</p>
    </section>

    <section>
        <div class="mb-2 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Campaign portfolio</h2>
            <button type="button" wire:click="setTab('campaigns')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open campaigns</button>
        </div>
        <x-ta.table>
            <x-slot:head>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Campaign</th>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Status</th>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Spend</th>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Result</th>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Cost</th>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Pacing</th>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Attention</th>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400"></th>
            </x-slot:head>
            @foreach ($data['campaigns'] as $c)
                <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                    <td class="px-3 py-2">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $c['name'] }}</p>
                        <p class="text-[11px] text-gray-400">{{ $c['offering'] }} · {{ $c['market'] === 'United Kingdom' ? 'UK' : $c['market'] }} · {{ $c['destination'] ?? $c['funnel'] }}</p>
                    </td>
                    <td class="px-3 py-2"><x-ta.badge color="success" size="sm">{{ $c['status'] }}</x-ta.badge></td>
                    <td class="px-3 py-2 text-sm tabular-nums text-gray-700 dark:text-gray-300">₺{{ number_format($c['spend']) }}</td>
                    <td class="px-3 py-2 text-sm tabular-nums">{{ number_format($c['results']) }} <span class="text-[11px] text-gray-400">{{ $c['result_label'] }}</span></td>
                    <td class="px-3 py-2 text-sm tabular-nums">₺{{ number_format($c['cost_result']) }}</td>
                    <td class="px-3 py-2"><x-ta.badge :color="match($c['pacing']) { 'Ahead', 'Constrained' => 'warning', 'Behind' => 'info', default => 'success' }" size="sm">{{ $c['pacing'] }}</x-ta.badge></td>
                    <td class="px-3 py-2 text-xs text-gray-500">{{ $c['attention_primary'] ?? '—' }}@if (count($c['attention'] ?? []) > 1) <span class="text-gray-400">+{{ count($c['attention']) - 1 }}</span>@endif</td>
                    <td class="px-3 py-2"><button type="button" wire:click="openCampaign('{{ $c['id'] }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open</button></td>
                </tr>
            @endforeach
        </x-ta.table>
    </section>

    <section>
        <div class="mb-2 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Creative pulse</h2>
            <button type="button" wire:click="setTab('creatives')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open creatives</button>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($data['creative_pulse'] as $cr)
                <button type="button" wire:click="openCreative('{{ $cr['id'] }}')" class="overflow-hidden rounded-2xl border border-gray-200 bg-white text-left transition hover:bg-gray-50 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:bg-white/[0.05]">
                    <x-demo.meta-creative-thumb :gradient="$cr['thumb']" :name="$cr['name']" class="h-28 !aspect-auto" />
                    <div class="space-y-1 p-3">
                        <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $cr['name'] }}</p>
                        <p class="text-[11px] text-gray-400">{{ $cr['format'] }} · ₺{{ number_format($cr['spend']) }}</p>
                        <p class="text-xs tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($cr['result']) }} {{ $cr['result_label'] }}</p>
                        @if (! empty($cr['signal']))
                            <p class="text-[11px] font-medium text-amber-700 dark:text-amber-400">{{ $cr['signal'] }}</p>
                        @endif
                    </div>
                </button>
            @endforeach
        </div>
    </section>

    <div class="grid gap-3 lg:grid-cols-2">
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Audience</h2>
                <button type="button" wire:click="setTab('audience')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open audience</button>
            </div>
            <p class="mt-1 text-[11px] text-gray-400">{{ $audience['concentration_note'] ?? 'Observed delivery · not causal' }}</p>
            <ul class="mt-3 space-y-2">
                @foreach (array_slice($audience['placements'] ?? [], 0, 4) as $row)
                    <li>
                        <div class="mb-1 flex justify-between text-xs text-gray-500">
                            <span>{{ $row['label'] }}</span>
                            <span class="tabular-nums">₺{{ number_format($row['spend']) }}</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/5">
                            <div class="h-full rounded-full bg-blue-500" style="width: {{ min(100, round(($row['spend'] / $maxPlacement) * 100)) }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Funnel destinations</h2>
                <button type="button" wire:click="setTab('funnel')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open funnel</button>
            </div>
            <ul class="mt-3 space-y-2">
                @foreach ($funnel['destinations'] ?? [] as $row)
                    <li>
                        <div class="mb-1 flex justify-between text-xs text-gray-500">
                            <span>{{ $row['label'] }}</span>
                            <span class="tabular-nums">₺{{ number_format($row['spend']) }} · {{ $row['share'] }}%</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/5">
                            <div class="h-full rounded-full bg-violet-500" style="width: {{ min(100, round(($row['spend'] / $maxDest) * 100)) }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Measurement trust</h2>
                <button type="button" wire:click="setTab('measurement')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open measurement</button>
            </div>
            <div class="mt-3 flex flex-wrap gap-1.5">
                @foreach ($meas['trust_chips'] ?? $meas['chips'] ?? [] as $chip)
                    <span @class([
                        'inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-medium',
                        'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400' => ($chip['state'] ?? '') === 'Healthy',
                        'bg-amber-50 text-amber-800 dark:bg-amber-500/15 dark:text-amber-400' => in_array($chip['state'] ?? '', ['Needs mapping', 'Partial', 'No recent signal'], true),
                        'bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-400' => ($chip['state'] ?? '') === 'Broken',
                        'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-gray-300' => ! in_array($chip['state'] ?? '', ['Healthy', 'Needs mapping', 'Partial', 'No recent signal', 'Broken'], true),
                    ])>{{ $chip['label'] }} · {{ $chip['state'] }}</span>
                @endforeach
            </div>
            <p class="mt-3 text-xs text-blue-700 dark:text-blue-300">{{ $meas['missing_note'] ?? 'Missing ≠ zero performance' }}</p>
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
                            'shrink-0 text-xs font-semibold',
                            'text-emerald-700 dark:text-emerald-400' => $o['state'] === 'Improvement observed',
                            'text-amber-700 dark:text-amber-400' => $o['state'] !== 'Improvement observed',
                        ])>{{ $o['state'] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    <div class="flex flex-wrap gap-2">
        @foreach ($data['opportunities'] as $opp)
            <button type="button" wire:click="setTab('{{ $opp['tab'] }}')" class="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-3 py-1.5 text-left text-xs transition hover:bg-gray-50 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:bg-white/[0.05]">
                <x-ta.badge :color="match($opp['priority']) { 'High' => 'error', 'Medium' => 'warning', default => 'info' }" size="sm">{{ $opp['priority'] }}</x-ta.badge>
                <span class="font-medium text-gray-900 dark:text-white">{{ $opp['title'] }}</span>
                <span class="text-gray-400">{{ $opp['metric'] }}</span>
            </button>
        @endforeach
    </div>
</div>
