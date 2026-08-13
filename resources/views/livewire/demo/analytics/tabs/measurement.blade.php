@php
    $m = $data['measurement'] ?? [];
    $subNav = [
        'business_actions' => 'Business actions',
        'events' => 'Events',
        'streams' => 'Streams',
        'quality' => 'Data quality',
    ];
    $activeSub = $meas_sub ?? 'business_actions';
    $utm = $m['utm_hygiene'] ?? [];
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

    @if (! empty($m['interruptions']))
        @foreach ($m['interruptions'] as $interrupt)
            <x-ta.alert variant="warning" title="{{ $interrupt['title'] }}" :message="($interrupt['detail'] ?? '').(! empty($interrupt['window']) ? ' · '.$interrupt['window'] : '')" />
        @endforeach
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
                @foreach ($m['business_actions'] ?? [] as $row)
                    <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                        <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $row['action'] }}</td>
                        <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['ga4_event'] ?? 'Unavailable' }}</td>
                        <td class="px-4 py-2.5 text-xs">{{ $row['role'] ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-sm tabular-nums">
                            @if (array_key_exists('event_count', $row) && $row['event_count'] !== null)
                                {{ number_format($row['event_count']) }}
                            @else
                                <span class="text-slate-400">Unavailable</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            <x-ta.badge :color="match($row['state'] ?? '') { 'Healthy' => 'success', 'Review', 'Not mapped', 'Observed' => 'warning', 'Unavailable', 'Broken' => 'error', default => 'light' }" size="sm">{{ $row['state'] }}</x-ta.badge>
                        </td>
                        <td class="px-4 py-2.5">
                            <button type="button" wire:click="openAction('{{ $row['action'] }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Inspect</button>
                        </td>
                    </tr>
                @endforeach
            </x-ta.table>
        </section>

        @if (! empty($m['trust_chips']))
            <div class="flex flex-wrap gap-1.5">
                @foreach ($m['trust_chips'] as $chip)
                    <span @class([
                        'inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-medium',
                        'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400' => ($chip['state'] ?? '') === 'Healthy',
                        'bg-amber-50 text-amber-800 dark:bg-amber-500/15 dark:text-amber-400' => in_array($chip['state'] ?? '', ['Not mapped', 'Review', 'Needs review'], true),
                        'bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-400' => ($chip['state'] ?? '') === 'Unavailable',
                        'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-gray-300' => ! in_array($chip['state'] ?? '', ['Healthy', 'Not mapped', 'Review', 'Needs review', 'Unavailable'], true),
                    ])>{{ $chip['label'] }} · {{ $chip['state'] }}</span>
                @endforeach
            </div>
        @endif
    @endif

    @if ($activeSub === 'events')
        <section class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Events</h3>
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
                        <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $row['event'] }}</td>
                        <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['mapped_action'] }}</td>
                        <td class="px-4 py-2.5 text-sm tabular-nums">
                            @if (array_key_exists('count', $row) && $row['count'] !== null)
                                {{ number_format($row['count']) }}
                            @else
                                <span class="text-slate-400">Unavailable</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            <x-ta.badge :color="match($row['state'] ?? '') { 'Healthy' => 'success', 'Review', 'Observed', 'Not mapped', 'Funnel only' => 'warning', 'Unavailable' => 'error', default => 'light' }" size="sm">{{ $row['state'] }}</x-ta.badge>
                        </td>
                        <td class="px-4 py-2.5">
                            <button type="button" wire:click="openEvent('{{ $row['event'] }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Inspect</button>
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
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Status</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Last hit</th>
                </x-slot:head>
                @foreach ($m['streams'] ?? [] as $row)
                    <tr>
                        <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</td>
                        <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['type'] ?? 'Web' }}</td>
                        <td class="px-4 py-2.5 text-xs tabular-nums text-gray-500">{{ $row['measurement_id'] ?? '—' }}</td>
                        <td class="px-4 py-2.5">
                            <x-ta.badge :color="match($row['status'] ?? '') { 'Receiving', 'Healthy' => 'success', 'Interrupted', 'Stale' => 'warning', default => 'light' }" size="sm">{{ $row['status'] ?? '—' }}</x-ta.badge>
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
                    @foreach ($m['data_quality'] ?? [] as $row)
                        <li class="flex items-start justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/[0.03]">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-800 dark:text-white/90">{{ $row['check'] }}</p>
                                <p class="text-[11px] text-gray-500">{{ $row['detail'] ?? '' }}</p>
                            </div>
                            <x-ta.badge :color="match($row['state'] ?? '') { 'Healthy', 'Pass' => 'success', 'Not mapped', 'Review', 'Needs review', 'Review candidate' => 'warning', 'Unavailable', 'Fail' => 'error', default => 'light' }" size="sm">{{ $row['state'] }}</x-ta.badge>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Duplicate candidates</h3>
                <ul class="mt-3 space-y-2">
                    @foreach ($m['duplicates'] ?? [] as $row)
                        <li class="rounded-lg bg-amber-50/80 px-3 py-2 text-sm dark:bg-amber-500/10">
                            <p class="font-medium text-gray-900 dark:text-white">{{ $row['title'] }}</p>
                            <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-300">{{ $row['detail'] ?? '' }}</p>
                            <div class="mt-2">
                                <x-ta.badge :color="match($row['state'] ?? '') { 'Review' => 'warning', default => 'light' }" size="sm">{{ $row['state'] ?? 'Review' }}</x-ta.badge>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">UTM hygiene</h3>
                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-xs text-gray-400">Unavailable now</dt>
                        <dd class="font-semibold tabular-nums text-amber-700 dark:text-amber-400">{{ $utm['unavailable_pct'] ?? '—' }}%</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-400">Prior window</dt>
                        <dd class="font-semibold tabular-nums">{{ $utm['prior_unavailable_pct'] ?? '—' }}%</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-400">Sessions affected</dt>
                        <dd class="font-semibold tabular-nums">{{ isset($utm['unavailable_sessions']) ? number_format($utm['unavailable_sessions']) : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-400">Trend</dt>
                        <dd class="font-semibold">{{ $utm['trend'] ?? '—' }}</dd>
                    </div>
                </dl>
                <p class="mt-3 text-xs text-gray-600 dark:text-gray-300">{{ $utm['note'] ?? '' }}</p>
                @if (! empty($utm['finding_id']))
                    <button type="button" wire:click="openFinding('{{ $utm['finding_id'] }}')" class="mt-2 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open Finding →</button>
                @endif
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Referral review</h3>
                </div>
                <x-ta.table class="border-0 rounded-none">
                    <x-slot:head>
                        <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Source / medium</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Sessions</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Flag</th>
                        <th class="px-4 py-2.5"></th>
                    </x-slot:head>
                    @foreach ($m['referrals'] ?? [] as $row)
                        <tr>
                            <td class="px-4 py-2.5 text-sm text-gray-900 dark:text-white">{{ $row['source'] }} / {{ $row['medium'] }}</td>
                            <td class="px-4 py-2.5 text-sm tabular-nums">{{ number_format($row['sessions']) }}</td>
                            <td class="px-4 py-2.5 text-xs text-amber-700 dark:text-amber-400">{{ $row['state'] }}</td>
                            <td class="px-4 py-2.5">
                                @if (! empty($row['finding_id']))
                                    <button type="button" wire:click="openFinding('{{ $row['finding_id'] }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Finding</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-ta.table>
            </section>
        </div>
    @endif

    <x-ta.alert variant="info" title="Missing ≠ zero" :message="$m['missing_note'] ?? $data['missing_note'] ?? 'Not mapped / Unavailable means the signal is absent, not a measured 0.'" />
</div>
