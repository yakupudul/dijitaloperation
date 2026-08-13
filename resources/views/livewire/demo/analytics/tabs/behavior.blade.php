@php
    $behavior = $data['behavior'] ?? [];
    $rows = $behavior['landing_pages'] ?? [];
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Behavior</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $behavior['subtitle'] ?? 'Landing pages · engagement · configured business actions' }}</p>
    </div>

    @if (! empty($behavior['engagement']))
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ($behavior['engagement'] as $metric)
                <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="text-xs text-gray-500">{{ $metric['metric'] }}</p>
                    <p @class([
                        'mt-1 text-xl font-semibold tabular-nums',
                        'text-slate-400' => ($metric['state'] ?? '') === 'Not mapped' || ($metric['value'] ?? '') === 'Unavailable',
                        'text-gray-900 dark:text-white' => ($metric['state'] ?? '') !== 'Not mapped' && ($metric['value'] ?? '') !== 'Unavailable',
                    ])>{{ $metric['value'] }}</p>
                    <p class="mt-1 text-[11px] text-gray-400">{{ $metric['state'] ?? '' }}</p>
                </div>
            @endforeach
        </div>
    @endif

    <x-ta.table>
        <x-slot:head>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Landing page</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Content role</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Sessions</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Engagement</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Business actions</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Website attention</th>
            <th class="px-4 py-2.5"></th>
        </x-slot:head>
        @foreach ($rows as $row)
            <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                <td class="px-4 py-2.5">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['path'] }}</p>
                    <p class="text-[11px] text-gray-400">{{ $row['title'] ?? '' }}</p>
                </td>
                <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['content_role'] }}</td>
                <td class="px-4 py-2.5 text-sm tabular-nums">{{ number_format($row['sessions']) }}</td>
                <td class="px-4 py-2.5 text-sm tabular-nums">{{ $row['engaged_rate'] }}% · {{ number_format($row['engaged_sessions']) }}</td>
                <td class="px-4 py-2.5 text-sm tabular-nums">{{ number_format($row['mapped_actions']) }}</td>
                <td class="px-4 py-2.5">
                    @if (! empty($row['attention']))
                        <span class="text-xs text-amber-700 dark:text-amber-400">{{ $row['attention'] }}</span>
                    @else
                        <span class="text-xs text-gray-400">—</span>
                    @endif
                </td>
                <td class="px-4 py-2.5">
                    <button type="button" wire:click="openLanding('{{ $row['path'] }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Inspect</button>
                </td>
            </tr>
        @endforeach
    </x-ta.table>

    @if (! empty($behavior['devices']))
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Devices</h3>
            <ul class="mt-3 space-y-2">
                @foreach ($behavior['devices'] as $device)
                    <li>
                        <div class="mb-1 flex justify-between text-xs text-gray-500">
                            <span>{{ $device['device'] }}</span>
                            <span class="tabular-nums">{{ $device['share_pct'] }}% · {{ number_format($device['sessions']) }}</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/5">
                            <div class="h-full rounded-full bg-sky-500" style="width: {{ min(100, (int) $device['share_pct']) }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <p class="text-[11px] text-blue-700 dark:text-blue-300">{{ $data['missing_note'] ?? 'Missing ≠ zero — pages without mapped business actions are not failed conversions.' }}</p>
</div>
