@php
    $behavior = $data['behavior'] ?? [];
    $rows = $behavior['landing_pages'] ?? [];
@endphp

<div class="space-y-10">
    <div>
        <h2 class="section-title">Behavior</h2>
        <p class="mt-0.5 text-xs text-gray-500">{{ $behavior['subtitle'] ?? 'Landing pages, engagement, configured business actions' }}</p>
    </div>

    @if (! empty($behavior['engagement']))
        <div class="grid grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($behavior['engagement'] as $metric)
                <x-ta.metric-card :label="$metric['metric']" :value="$metric['value']" :hint="$metric['state'] ?? null" />
            @endforeach
        </div>
    @endif

    <div class="max-w-full overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-gray-200 dark:border-gray-800">
                    <tr>
            <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Landing page</th>
            <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Content role</th>
            <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Sessions</th>
            <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Engagement</th>
            <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Business actions</th>
            <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Website attention</th>
            <th class="px-4 py-2.5"></th>
        </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
        @foreach ($rows as $row)
            <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                <td class="px-4 py-2.5">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['path'] }}</p>
                    <p class="text-xs text-gray-500">{{ $row['title'] ?? '' }}</p>
                </td>
                <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['content_role'] }}</td>
                <td class="px-4 py-2.5 text-sm tabular-nums">{{ number_format($row['sessions']) }}</td>
                <td class="px-4 py-2.5 text-sm tabular-nums">{{ $row['engaged_rate'] }}%, {{ number_format($row['engaged_sessions']) }}</td>
                <td class="px-4 py-2.5 text-sm tabular-nums">{{ number_format($row['mapped_actions']) }}</td>
                <td class="px-4 py-2.5">
                    @if (! empty($row['attention']))
                        <span class="text-xs text-warning-700 dark:text-warning-400">{{ $row['attention'] }}</span>
                    @else
                        <span class="text-xs text-gray-500">—</span>
                    @endif
                </td>
                <td class="px-4 py-2.5">
                    <button type="button" wire:click="openLanding('{{ $row['path'] }}')" class="link-neutral text-xs">Inspect</button>
                </td>
            </tr>
        @endforeach
    </tbody>
            </table>
        </div>

    @if (! empty($behavior['devices']))
        <section class="min-w-0">
            <h3 class="section-title">Devices</h3>
            <ul class="mt-3 space-y-2">
                @foreach ($behavior['devices'] as $device)
                    <li>
                        <div class="mb-1 flex justify-between text-xs text-gray-500">
                            <span>{{ $device['device'] }}</span>
                            <span class="tabular-nums">{{ $device['share_pct'] }}%, {{ number_format($device['sessions']) }}</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5">
                            <div class="h-full rounded-full bg-gray-800 dark:bg-gray-300" style="width: {{ min(100, (int) $device['share_pct']) }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <p class="text-xs text-gray-700 dark:text-gray-300">{{ $data['missing_note'] ?? 'Missing ≠ zero — pages without mapped business actions are not failed conversions.' }}</p>
</div>
