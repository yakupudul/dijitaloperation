@php
    $glance = $data['glance'] ?? [];
    $acqBars = $data['acquisition_bars'] ?? $data['acquisition']['bars'] ?? [];
    $landingPulse = $data['landing_pulse'] ?? [];
    $actionMatrix = $data['business_action_matrix'] ?? $data['business_actions']['matrix'] ?? [];
    $journeySnap = $data['journey_snapshot'] ?? [];
    $maxAcq = max(1, (float) collect($acqBars)->max(fn ($r) => $r['sessions'] ?? $r['value'] ?? 0));
@endphp

<div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <x-ta.metric-card
            label="{{ $glance['sessions']['label'] ?? 'Sessions' }}"
            :value="$glance['sessions']['value'] ?? '—'"
            :delta="$glance['sessions']['secondary'] ?? null"
            :tone="$glance['sessions']['tone'] ?? 'neutral'"
        />
        <x-ta.metric-card
            label="{{ $glance['engaged']['label'] ?? 'Engaged sessions' }}"
            :value="$glance['engaged']['value'] ?? '—'"
            :delta="$glance['engaged']['secondary'] ?? null"
            :tone="$glance['engaged']['tone'] ?? 'neutral'"
        />
        <x-ta.metric-card
            label="{{ $glance['key_events']['label'] ?? 'Key events' }}"
            :value="$glance['key_events']['value'] ?? '—'"
            :delta="$glance['key_events']['secondary'] ?? null"
            :tone="$glance['key_events']['tone'] ?? 'neutral'"
        />
        <x-ta.metric-card
            label="{{ $glance['business_actions']['label'] ?? 'Business actions' }}"
            :value="$glance['business_actions']['value'] ?? '—'"
            :delta="$glance['business_actions']['secondary'] ?? null"
            :tone="$glance['business_actions']['tone'] ?? 'neutral'"
        />
    </div>

    <div class="grid gap-3 lg:grid-cols-12">
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03] lg:col-span-7">
            <div class="mb-2 flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Needs attention</h2>
                <span class="text-xs text-gray-400">{{ count($data['needs_attention'] ?? []) }} signals</span>
            </div>
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($data['needs_attention'] ?? [] as $item)
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
                @empty
                    <li class="py-3 text-sm text-gray-500">No attention signals</li>
                @endforelse
            </ul>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03] lg:col-span-5">
            <div class="mb-2 flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Acquisition mix</h2>
                <button type="button" wire:click="setTab('acquisition')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open</button>
            </div>
            <ul class="space-y-2">
                @foreach ($acqBars as $row)
                    @php $val = (float) ($row['sessions'] ?? $row['value'] ?? 0); @endphp
                    <li>
                        <div class="mb-1 flex items-center justify-between gap-2 text-xs">
                            <span class="font-medium text-gray-800 dark:text-white/90">{{ $row['label'] ?? $row['channel'] }}</span>
                            <span class="tabular-nums text-gray-500">{{ number_format($val) }}@if (isset($row['share'])) · {{ $row['share'] }}%@endif</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/5">
                            <div class="h-full rounded-full bg-orange-500" style="width: {{ min(100, round(($val / $maxAcq) * 100)) }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="mb-2 flex items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Sessions trend</h2>
            <span class="text-xs text-gray-400">{{ $data['period_label'] ?? '' }} · {{ $data['performance_trend']['compare_label'] ?? '' }}</span>
        </div>
        <div data-chart='@json($performanceChartOptions)' aria-label="Sessions and key events trend" class="min-h-[220px]"></div>
    </section>

    <section>
        <div class="mb-2 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Landing pulse</h2>
            <button type="button" wire:click="setTab('behavior')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open behavior</button>
        </div>
        <x-ta.table>
            <x-slot:head>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Landing</th>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Sessions</th>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Engagement</th>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Actions</th>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Attention</th>
                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400"></th>
            </x-slot:head>
            @foreach ($landingPulse as $row)
                <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                    <td class="px-3 py-2">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['path'] }}</p>
                        <p class="text-[11px] text-gray-400">{{ $row['content_role'] ?? '' }}</p>
                    </td>
                    <td class="px-3 py-2 text-sm tabular-nums">{{ number_format($row['sessions'] ?? 0) }}</td>
                    <td class="px-3 py-2 text-sm">{{ $row['engagement'] ?? '—' }}</td>
                    <td class="px-3 py-2 text-sm tabular-nums">{{ $row['business_actions'] ?? $row['actions'] ?? '—' }}</td>
                    <td class="px-3 py-2 text-xs text-gray-500">{{ $row['website_attention'] ?? $row['attention'] ?? '—' }}</td>
                    <td class="px-3 py-2"><button type="button" wire:click="openLanding('{{ $row['id'] }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Inspect</button></td>
                </tr>
            @endforeach
        </x-ta.table>
    </section>

    <div class="grid gap-3 lg:grid-cols-2">
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Business action matrix</h2>
                <button type="button" wire:click="setMeasSub('business_actions')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Measurement</button>
            </div>
            <ul class="space-y-2">
                @foreach ($actionMatrix as $row)
                    <li class="flex items-center justify-between gap-2">
                        <button type="button" wire:click="openAction('{{ $row['id'] ?? $row['action'] }}')" class="min-w-0 text-left">
                            <span class="block truncate text-sm font-medium text-gray-900 dark:text-white">{{ $row['action'] ?? $row['business_action'] }}</span>
                            <span class="block truncate text-[11px] text-gray-400">{{ $row['ga4_event'] ?? $row['event'] ?? 'Not mapped' }}</span>
                        </button>
                        <x-ta.badge :color="match($row['state'] ?? '') { 'Healthy', 'Mapped' => 'success', 'Needs mapping', 'No recent signal', 'Partial', 'Incomplete' => 'warning', 'Broken', 'Missing' => 'error', default => 'light' }" size="sm">{{ $row['state'] ?? '—' }}</x-ta.badge>
                    </li>
                @endforeach
            </ul>
            <p class="mt-3 text-[11px] font-medium text-blue-700 dark:text-blue-300">Missing ≠ zero</p>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Journey snapshot</h2>
                <button type="button" wire:click="setTab('journeys')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open journeys</button>
            </div>
            <ul class="space-y-3">
                @foreach ($journeySnap as $journey)
                    <li class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-white/[0.03]">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $journey['name'] ?? $journey['label'] }}</span>
                            <x-ta.badge :color="match($journey['state'] ?? '') { 'Complete', 'Healthy' => 'success', 'Incomplete', 'Partial' => 'warning', default => 'light' }" size="sm">{{ $journey['state'] ?? '—' }}</x-ta.badge>
                        </div>
                        @if (! empty($journey['steps']))
                            <div class="mt-2 flex flex-wrap items-center gap-1.5 text-[11px] text-gray-500">
                                @foreach ($journey['steps'] as $i => $step)
                                    @if ($i > 0)<span class="text-gray-300 dark:text-gray-600">→</span>@endif
                                    <span @class(['font-medium', 'text-slate-400' => ($step['state'] ?? '') === 'Missing'])>{{ $step['label'] }}@if (isset($step['value'])) · {{ $step['value'] }}@endif</span>
                                @endforeach
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03] lg:col-span-2">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Recent outcomes</h2>
                <button type="button" wire:click="setOps('outcomes')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Operations</button>
            </div>
            <ul class="space-y-2">
                @foreach ($data['recent_outcomes'] ?? [] as $o)
                    <li class="flex items-center justify-between gap-2 text-sm">
                        <span class="text-gray-800 dark:text-white/90">{{ $o['title'] }}</span>
                        <span @class([
                            'shrink-0 text-xs font-semibold',
                            'text-emerald-700 dark:text-emerald-400' => ($o['state'] ?? '') === 'Improvement observed',
                            'text-amber-700 dark:text-amber-400' => ($o['state'] ?? '') !== 'Improvement observed',
                        ])>{{ $o['state'] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>
</div>
