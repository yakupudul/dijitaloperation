@php
    $m = $data['measurement'] ?? [];
    $subNav = $m['sub'] ?? [
        'business_actions' => 'Business actions',
        'events' => 'Events',
        'streams' => 'Streams',
        'quality' => 'Data quality',
    ];
    $activeSub = $meas_sub ?? 'business_actions';
@endphp

<div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Measurement</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $m['subtitle'] ?? 'Configured mappings, streams, and data quality' }}</p>
        </div>
        <span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-1 text-[11px] font-medium text-blue-700 dark:bg-blue-500/15 dark:text-blue-300">{{ $m['missing_note'] ?? 'Missing ≠ zero' }}</span>
    </div>

    <div class="inline-flex flex-wrap rounded-lg ring-1 ring-inset ring-gray-300 dark:ring-gray-700" role="tablist" aria-label="Measurement">
        @foreach ($subNav as $key => $label)
            <button type="button" wire:click="setMeasSub('{{ $key }}')" @class([
                'px-3 py-2 text-xs font-medium',
                'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' => $activeSub === $key,
                'text-gray-600 dark:text-gray-300' => $activeSub !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @if (! empty($m['glance']))
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-ta.metric-card label="Mapped actions" :value="(string) ($m['glance']['mapped'] ?? $m['glance']['primary_mappings'] ?? '—')" />
            <x-ta.metric-card label="Healthy signals" :value="(string) ($m['glance']['healthy'] ?? '—')" tone="positive" />
            <x-ta.metric-card label="Needs mapping" :value="(string) ($m['glance']['needs_mapping'] ?? '—')" tone="warning" />
            <x-ta.metric-card label="Quality findings" :value="(string) ($m['glance']['findings'] ?? '—')" tone="warning" />
        </div>
    @endif

    @if (! empty($m['interruption']))
        <x-ta.alert variant="warning" title="{{ $m['interruption']['title'] }}" :message="$m['interruption']['detail'] ?? ''" />
    @endif

    @if ($activeSub === 'business_actions')
        <section class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Business action → GA4 event</h3>
                <p class="text-xs text-gray-400">Operator-configured · not inferred</p>
            </div>
            <x-ta.table class="border-0 rounded-none">
                <x-slot:head>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Business action</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">GA4 event</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Role</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Count</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">State</th>
                    <th class="px-4 py-2.5"></th>
                </x-slot:head>
                @foreach ($m['matrix'] ?? $m['business_actions'] ?? [] as $row)
                    <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                        <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $row['action'] ?? $row['business_action'] }}</td>
                        <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['ga4_event'] ?? $row['event'] ?? 'Not mapped' }}</td>
                        <td class="px-4 py-2.5 text-xs">{{ $row['role'] ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-sm tabular-nums">
                            @if (array_key_exists('count', $row) && $row['count'] !== null)
                                {{ number_format($row['count']) }}
                            @else
                                <span class="text-slate-400">No data</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            <x-ta.badge :color="match($row['state'] ?? '') { 'Healthy', 'Mapped' => 'success', 'Needs mapping', 'No recent signal', 'Partial' => 'warning', 'Broken', 'Missing' => 'error', default => 'light' }" size="sm">{{ $row['state'] ?? '—' }}</x-ta.badge>
                        </td>
                        <td class="px-4 py-2.5">
                            <button type="button" wire:click="openAction('{{ $row['id'] ?? ($row['action'] ?? $row['business_action']) }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Inspect</button>
                        </td>
                    </tr>
                @endforeach
            </x-ta.table>
        </section>
    @endif

    @if ($activeSub === 'events')
        <section class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Discovered / configured events</h3>
            </div>
            <x-ta.table class="border-0 rounded-none">
                <x-slot:head>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Event</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Mapped action</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Count</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">State</th>
                    <th class="px-4 py-2.5"></th>
                </x-slot:head>
                @foreach ($m['events'] ?? [] as $row)
                    <tr>
                        <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $row['name'] ?? $row['event'] }}</td>
                        <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['business_action'] ?? $row['mapped_action'] ?? 'Not mapped' }}</td>
                        <td class="px-4 py-2.5 text-sm tabular-nums">
                            @if (array_key_exists('count', $row) && $row['count'] !== null)
                                {{ number_format($row['count']) }}
                            @else
                                <span class="text-slate-400">No data</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            <x-ta.badge :color="match($row['state'] ?? '') { 'Healthy' => 'success', 'Needs mapping', 'No recent signal' => 'warning', 'Broken' => 'error', default => 'light' }" size="sm">{{ $row['state'] ?? '—' }}</x-ta.badge>
                        </td>
                        <td class="px-4 py-2.5">
                            <button type="button" wire:click="openEvent('{{ $row['id'] ?? ($row['name'] ?? $row['event']) }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Inspect</button>
                        </td>
                    </tr>
                @endforeach
            </x-ta.table>
        </section>
    @endif

    @if ($activeSub === 'streams')
        <section class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Data streams</h3>
            </div>
            <x-ta.table class="border-0 rounded-none">
                <x-slot:head>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Stream</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Type</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Measurement ID</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">State</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Last hit</th>
                </x-slot:head>
                @foreach ($m['streams'] ?? [] as $row)
                    <tr>
                        <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</td>
                        <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['type'] ?? 'Web' }}</td>
                        <td class="px-4 py-2.5 text-xs tabular-nums text-gray-500">{{ $row['measurement_id'] ?? '—' }}</td>
                        <td class="px-4 py-2.5">
                            <x-ta.badge :color="match($row['state'] ?? '') { 'Receiving', 'Healthy' => 'success', 'Interrupted', 'Stale' => 'warning', 'Missing' => 'error', default => 'light' }" size="sm">{{ $row['state'] ?? '—' }}</x-ta.badge>
                        </td>
                        <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['last_hit'] ?? 'No data' }}</td>
                    </tr>
                @endforeach
            </x-ta.table>
        </section>
    @endif

    @if ($activeSub === 'quality')
        <div class="grid gap-3 lg:grid-cols-2">
            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Data quality</h3>
                <ul class="mt-3 space-y-2">
                    @foreach ($m['quality'] ?? $m['quality_checks'] ?? [] as $row)
                        <li class="flex items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/[0.03]">
                            <span class="text-gray-800 dark:text-white/90">{{ $row['label'] ?? $row['check'] }}</span>
                            <x-ta.badge :color="match($row['state'] ?? '') { 'Pass', 'Healthy', 'OK' => 'success', 'Warn', 'Review' => 'warning', 'Fail', 'Broken' => 'error', default => 'light' }" size="sm">{{ $row['state'] ?? '—' }}</x-ta.badge>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Duplicate candidates</h3>
                <ul class="mt-3 space-y-2">
                    @forelse ($m['duplicates'] ?? $m['duplicate_candidates'] ?? [] as $row)
                        <li class="rounded-lg bg-amber-50/80 px-3 py-2 text-sm dark:bg-amber-500/10">
                            <p class="font-medium text-gray-900 dark:text-white">{{ $row['title'] ?? $row['label'] }}</p>
                            <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-300">{{ $row['detail'] ?? '' }}</p>
                            @if (! empty($row['finding_id']))
                                <button type="button" wire:click="openFinding('{{ $row['finding_id'] }}')" class="mt-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open Finding</button>
                            @endif
                        </li>
                    @empty
                        <li class="text-sm text-gray-500">No duplicate candidates flagged</li>
                    @endforelse
                </ul>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">UTM hygiene</h3>
                </div>
                <x-ta.table class="border-0 rounded-none">
                    <x-slot:head>
                        <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Issue</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Sessions</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">State</th>
                    </x-slot:head>
                    @foreach ($m['utm_hygiene'] ?? [] as $row)
                        <tr>
                            <td class="px-4 py-2.5 text-sm text-gray-900 dark:text-white">{{ $row['label'] ?? $row['issue'] }}</td>
                            <td class="px-4 py-2.5 text-sm tabular-nums">
                                @if (array_key_exists('sessions', $row) && $row['sessions'] !== null)
                                    {{ number_format($row['sessions']) }}
                                @else
                                    <span class="text-slate-400">No data</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5"><x-ta.badge :color="match($row['state'] ?? '') { 'Clean', 'OK' => 'success', 'Review' => 'warning', default => 'light' }" size="sm">{{ $row['state'] ?? '—' }}</x-ta.badge></td>
                        </tr>
                    @endforeach
                </x-ta.table>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Referral review</h3>
                </div>
                <x-ta.table class="border-0 rounded-none">
                    <x-slot:head>
                        <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Referrer</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Sessions</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Flag</th>
                    </x-slot:head>
                    @foreach ($m['referral_review'] ?? [] as $row)
                        <tr>
                            <td class="px-4 py-2.5 text-sm text-gray-900 dark:text-white">{{ $row['referrer'] ?? $row['label'] }}</td>
                            <td class="px-4 py-2.5 text-sm tabular-nums">
                                @if (array_key_exists('sessions', $row) && $row['sessions'] !== null)
                                    {{ number_format($row['sessions']) }}
                                @else
                                    <span class="text-slate-400">No data</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-xs text-amber-700 dark:text-amber-400">{{ $row['flag'] ?? $row['state'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </x-ta.table>
            </section>
        </div>
    @endif

    <x-ta.alert variant="info" title="Missing ≠ zero" :message="$m['interpretation_note'] ?? 'Absent mappings and empty event counts are not underperformance. Do not judge channel results until primary signals are trustworthy.'" />
</div>
