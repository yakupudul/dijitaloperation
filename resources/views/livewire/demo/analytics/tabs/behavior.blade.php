@php
    $behavior = $data['behavior'] ?? [];
    $rows = $behavior['rows'] ?? $behavior['landing_pages'] ?? [];
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Behavior</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $behavior['subtitle'] ?? 'Landing pages · engagement · configured business actions' }}</p>
    </div>

    @if (! empty($behavior['glance']))
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-ta.metric-card label="Landing pages" :value="(string) ($behavior['glance']['landings'] ?? '—')" />
            <x-ta.metric-card label="Need Website review" :value="(string) ($behavior['glance']['need_review'] ?? '—')" tone="warning" />
            <x-ta.metric-card label="With business actions" :value="(string) ($behavior['glance']['with_actions'] ?? '—')" />
            <x-ta.metric-card label="Engaged rate" :value="(string) ($behavior['glance']['engaged_rate'] ?? '—')" />
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
                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['path'] ?? $row['url'] }}</p>
                    @if (! empty($row['title']))
                        <p class="text-[11px] text-gray-400">{{ $row['title'] }}</p>
                    @endif
                </td>
                <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['content_role'] ?? $row['role'] ?? '—' }}</td>
                <td class="px-4 py-2.5 text-sm tabular-nums">
                    @if (array_key_exists('sessions', $row) && $row['sessions'] !== null)
                        {{ number_format($row['sessions']) }}
                    @else
                        <span class="text-slate-400">No data</span>
                    @endif
                </td>
                <td class="px-4 py-2.5 text-sm">{{ $row['engagement'] ?? '—' }}</td>
                <td class="px-4 py-2.5 text-sm tabular-nums">
                    @if (array_key_exists('business_actions', $row) && $row['business_actions'] !== null)
                        {{ is_numeric($row['business_actions']) ? number_format($row['business_actions']) : $row['business_actions'] }}
                    @elseif (array_key_exists('actions', $row) && $row['actions'] !== null)
                        {{ is_numeric($row['actions']) ? number_format($row['actions']) : $row['actions'] }}
                    @else
                        <span class="text-slate-400">No data</span>
                    @endif
                </td>
                <td class="px-4 py-2.5">
                    @if (! empty($row['website_attention']) || ! empty($row['attention']))
                        <x-ta.badge :color="match($row['website_attention'] ?? $row['attention'] ?? '') { 'Critical', 'High' => 'error', 'Medium', 'Needs review', 'Weak CTA' => 'warning', 'Good', 'Healthy' => 'success', default => 'light' }" size="sm">{{ $row['website_attention'] ?? $row['attention'] }}</x-ta.badge>
                    @else
                        <span class="text-xs text-gray-400">—</span>
                    @endif
                </td>
                <td class="px-4 py-2.5">
                    <button type="button" wire:click="openLanding('{{ $row['id'] }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Inspect</button>
                </td>
            </tr>
        @endforeach
    </x-ta.table>

    <p class="text-[11px] text-blue-700 dark:text-blue-300">Missing ≠ zero — pages without mapped business actions are not failed conversions.</p>
</div>
