@php $m = $data['measurement']; @endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Measurement</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $m['subtitle'] }}</p>
        <p class="mt-1 text-xs text-blue-700 dark:text-blue-300">{{ $m['ga4_label'] }}</p>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-ta.metric-card label="Primary goals" :value="(string) $m['glance']['primary_goals']" />
        <x-ta.metric-card label="Healthy signals" :value="$m['glance']['healthy']" tone="positive" />
        <x-ta.metric-card label="Needs mapping" :value="(string) $m['glance']['needs_mapping']" tone="warning" />
        <x-ta.metric-card label="Measurement Findings" :value="(string) $m['glance']['findings']" tone="warning" />
    </div>

    <x-ta.alert variant="warning" title="{{ $m['interruption']['title'] }}" :message="$m['interruption']['detail']" />

    <section class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Conversion mapping</h3>
            <p class="text-xs text-gray-400">Primary vs secondary · missing ≠ zero performance</p>
        </div>
        <x-ta.table class="border-0 rounded-none">
            <x-slot:head>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Business action</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Source</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Google Ads role</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">State</th>
            </x-slot:head>
            @foreach ($m['matrix'] as $row)
                <tr>
                    <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $row['action'] }}</td>
                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['source'] }}</td>
                    <td class="px-4 py-2.5 text-xs">{{ $row['role'] }}</td>
                    <td class="px-4 py-2.5"><x-ta.badge :color="match($row['state']) { 'Healthy' => 'success', 'Needs mapping', 'No recent signal' => 'warning', default => 'light' }" size="sm">{{ $row['state'] }}</x-ta.badge></td>
                </tr>
            @endforeach
        </x-ta.table>
    </section>

    <div class="grid gap-3 lg:grid-cols-2">
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Measurement debt</h3>
            <ul class="mt-3 space-y-2">
                @foreach ($m['debt'] as $row)
                    <li class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/[0.03]">
                        <span>{{ $row['label'] }}</span>
                        <span class="font-semibold tabular-nums">{{ $row['count'] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $m['duplicate_risk']['title'] }}</h3>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $m['duplicate_risk']['detail'] }}</p>
            <p class="mt-3 text-xs text-gray-500">{{ $m['trust'] }}</p>
            <button type="button" wire:click="openFinding('gads-f-measurement-gap')" class="mt-3 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open measurement Finding →</button>
        </section>
    </div>

    <x-ta.alert variant="info" title="Performance interpretation limited when measurement is uncertain" message="Do not judge CPA or underperformance until the primary signal is trustworthy." />
</div>
