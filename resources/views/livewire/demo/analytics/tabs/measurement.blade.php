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

<div class="space-y-10">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
            <h2 class="section-title">Measurement</h2>
            <p class="mt-0.5 text-xs text-gray-500">{{ $m['subtitle'] ?? 'Configured mappings, streams, and data quality' }}</p>
        </div>
        <span class="text-xs text-gray-500">{{ $m['missing_note'] ?? 'Missing ≠ zero' }}</span>
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
            <x-ta.alert variant="warning" title="{{ $interrupt['title'] }}" :message="($interrupt['detail'] ?? '').(! empty($interrupt['window']) ? ', '.$interrupt['window'] : '')" />
        @endforeach
    @endif

    @if ($activeSub === 'business_actions')
        <section class="min-w-0">
            <div class="mb-1">
                <h3 class="section-title">Business actions and GA4 events</h3>
                <p class="text-xs text-gray-500">Operator-configured, not inferred</p>
            </div>
            <div class="max-w-full overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-gray-200 dark:border-gray-800">
                    <tr>
                    <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Business action</th>
                    <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">GA4 event</th>
                    <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Role</th>
                    <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Count</th>
                    <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">State</th>
                    <th class="px-4 py-2.5"></th>
                </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                @foreach ($m['business_actions'] ?? [] as $row)
                    <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                        <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $row['action'] }}</td>
                        <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['ga4_event'] ?? 'Unavailable' }}</td>
                        <td class="px-4 py-2.5 text-xs">{{ $row['role'] ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-sm tabular-nums">
                            @if (array_key_exists('event_count', $row) && $row['event_count'] !== null)
                                {{ number_format($row['event_count']) }}
                            @else
                                <span class="text-gray-400">Unavailable</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            <x-ta.pill :tone="['error' => 'critical', 'warning' => 'look', 'success' => 'ok'][match($row['state'] ?? '') { 'Healthy' => 'success', 'Review', 'Not mapped', 'Observed' => 'warning', 'Unavailable', 'Broken' => 'error', default => 'light' }] ?? 'info'">{{ $row['state'] }}</x-ta.pill>
                        </td>
                        <td class="px-4 py-2.5">
                            <button type="button" wire:click="openAction('{{ $row['action'] }}')" class="link-neutral text-xs">Inspect</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
            </table>
        </div>
        </section>

        @if (! empty($m['trust_chips']))
            <div class="flex flex-wrap gap-1.5">
                @foreach ($m['trust_chips'] as $chip)
                    <x-ta.pill :tone="match ($chip['state'] ?? '') { 'Healthy' => 'ok', 'Not mapped', 'Review', 'Needs review' => 'look', 'Unavailable' => 'critical', default => 'info' }">{{ $chip['label'] }}, {{ $chip['state'] }}</x-ta.pill>
                @endforeach
            </div>
        @endif
    @endif

    @if ($activeSub === 'events')
        <section class="min-w-0">
            <div class="mb-1">
                <h3 class="section-title">Events</h3>
            </div>
            <div class="max-w-full overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-gray-200 dark:border-gray-800">
                    <tr>
                    <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Event</th>
                    <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Mapped action</th>
                    <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Count</th>
                    <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">State</th>
                    <th class="px-4 py-2.5"></th>
                </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                @foreach ($m['events'] ?? [] as $row)
                    <tr>
                        <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $row['event'] }}</td>
                        <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['mapped_action'] }}</td>
                        <td class="px-4 py-2.5 text-sm tabular-nums">
                            @if (array_key_exists('count', $row) && $row['count'] !== null)
                                {{ number_format($row['count']) }}
                            @else
                                <span class="text-gray-400">Unavailable</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            <x-ta.pill :tone="['error' => 'critical', 'warning' => 'look', 'success' => 'ok'][match($row['state'] ?? '') { 'Healthy' => 'success', 'Review', 'Observed', 'Not mapped', 'Funnel only' => 'warning', 'Unavailable' => 'error', default => 'light' }] ?? 'info'">{{ $row['state'] }}</x-ta.pill>
                        </td>
                        <td class="px-4 py-2.5">
                            <button type="button" wire:click="openEvent('{{ $row['event'] }}')" class="link-neutral text-xs">Inspect</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
            </table>
        </div>
        </section>
    @endif

    @if ($activeSub === 'streams')
        <section class="min-w-0">
            <div class="mb-1">
                <h3 class="section-title">Data streams</h3>
            </div>
            <div class="max-w-full overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-gray-200 dark:border-gray-800">
                    <tr>
                    <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Stream</th>
                    <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Type</th>
                    <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Measurement ID</th>
                    <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Status</th>
                    <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Last hit</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                @foreach ($m['streams'] ?? [] as $row)
                    <tr>
                        <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</td>
                        <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['type'] ?? 'Web' }}</td>
                        <td class="px-4 py-2.5 text-xs tabular-nums text-gray-500">{{ $row['measurement_id'] ?? '—' }}</td>
                        <td class="px-4 py-2.5">
                            <x-ta.pill :tone="['error' => 'critical', 'warning' => 'look', 'success' => 'ok'][match($row['status'] ?? '') { 'Receiving', 'Healthy' => 'success', 'Interrupted', 'Stale' => 'warning', default => 'light' }] ?? 'info'">{{ $row['status'] ?? '—' }}</x-ta.pill>
                        </td>
                        <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['last_hit'] ?? 'No data' }}</td>
                    </tr>
                @endforeach
            </tbody>
            </table>
        </div>
        </section>
    @endif

    @if ($activeSub === 'quality')
        <div class="grid gap-10 lg:grid-cols-2">
            <section class="min-w-0">
                <h3 class="section-title">Data quality</h3>
                <ul class="mt-3 space-y-2">
                    @foreach ($m['data_quality'] ?? [] as $row)
                        <li class="flex items-start justify-between gap-2 border-b border-gray-200 py-2.5 text-sm dark:border-gray-800">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-800 dark:text-white/90">{{ $row['check'] }}</p>
                                <p class="text-xs text-gray-500">{{ $row['detail'] ?? '' }}</p>
                            </div>
                            <x-ta.pill :tone="['error' => 'critical', 'warning' => 'look', 'success' => 'ok'][match($row['state'] ?? '') { 'Healthy', 'Pass' => 'success', 'Not mapped', 'Review', 'Needs review', 'Review candidate' => 'warning', 'Unavailable', 'Fail' => 'error', default => 'light' }] ?? 'info'">{{ $row['state'] }}</x-ta.pill>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="min-w-0">
                <h3 class="section-title">Duplicate candidates</h3>
                <ul class="mt-3 space-y-2">
                    @foreach ($m['duplicates'] ?? [] as $row)
                        <li class="border-b border-gray-200 py-2.5 text-sm dark:border-gray-800">
                            <p class="font-medium text-gray-900 dark:text-white">{{ $row['title'] }}</p>
                            <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-300">{{ $row['detail'] ?? '' }}</p>
                            <div class="mt-2">
                                <x-ta.pill :tone="['error' => 'critical', 'warning' => 'look', 'success' => 'ok'][match($row['state'] ?? '') { 'Review' => 'warning', default => 'light' }] ?? 'info'">{{ $row['state'] ?? 'Review' }}</x-ta.pill>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="min-w-0">
                <h3 class="section-title">UTM hygiene</h3>
                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-xs text-gray-500">Unavailable now</dt>
                        <dd class="font-semibold tabular-nums text-warning-700 dark:text-warning-400">{{ $utm['unavailable_pct'] ?? '—' }}%</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Prior window</dt>
                        <dd class="font-semibold tabular-nums">{{ $utm['prior_unavailable_pct'] ?? '—' }}%</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Sessions affected</dt>
                        <dd class="font-semibold tabular-nums">{{ isset($utm['unavailable_sessions']) ? number_format($utm['unavailable_sessions']) : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Trend</dt>
                        <dd class="font-semibold">{{ $utm['trend'] ?? '—' }}</dd>
                    </div>
                </dl>
                <p class="mt-3 text-xs text-gray-600 dark:text-gray-300">{{ $utm['note'] ?? '' }}</p>
                @if (! empty($utm['finding_id']))
                    <button type="button" wire:click="openFinding('{{ $utm['finding_id'] }}')" class="mt-2 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open Finding</button>
                @endif
            </section>

            <section class="min-w-0">
                <div class="mb-1">
                    <h3 class="section-title">Referral review</h3>
                </div>
                <div class="max-w-full overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-gray-200 dark:border-gray-800">
                    <tr>
                        <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Source / medium</th>
                        <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Sessions</th>
                        <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Flag</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                    @foreach ($m['referrals'] ?? [] as $row)
                        <tr>
                            <td class="px-4 py-2.5 text-sm text-gray-900 dark:text-white">{{ $row['source'] }} / {{ $row['medium'] }}</td>
                            <td class="px-4 py-2.5 text-sm tabular-nums">{{ number_format($row['sessions']) }}</td>
                            <td class="px-4 py-2.5 text-xs text-warning-700 dark:text-warning-400">{{ $row['state'] }}</td>
                            <td class="px-4 py-2.5">
                                @if (! empty($row['finding_id']))
                                    <button type="button" wire:click="openFinding('{{ $row['finding_id'] }}')" class="link-neutral text-xs">Finding</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
            </section>
        </div>
    @endif

    <x-ta.alert variant="info" title="Missing ≠ zero" :message="$m['missing_note'] ?? $data['missing_note'] ?? 'Not mapped / Unavailable means the signal is absent, not a measured 0.'" />
</div>
