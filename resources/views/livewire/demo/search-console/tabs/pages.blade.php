@php
    $pages = $data['pages'] ?? [];
    $directory = $pages['directory'] ?? [];
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Pages</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $pages['subtitle'] ?? '' }}</p>
        <p class="mt-1 text-xs text-blue-700 dark:text-blue-300">{{ $pages['attribution_note'] ?? '' }}</p>
    </div>

    <x-ta.table>
        <x-slot:head>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Page</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Content role</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Offering</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Clicks</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">GA4 context</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Website attention</th>
            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400"></th>
        </x-slot:head>
        @foreach ($directory as $row)
            @php $ga4 = $row['ga4_context'] ?? []; @endphp
            <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                <td class="px-4 py-2.5">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['path'] }}</p>
                    <p class="text-[11px] text-gray-400">{{ $row['title'] ?? '' }}</p>
                </td>
                <td class="px-4 py-2.5 text-xs text-gray-600 dark:text-gray-300">{{ $row['content_role'] ?? '—' }}</td>
                <td class="px-4 py-2.5 text-xs text-gray-600 dark:text-gray-300">{{ $row['offering'] ?? '—' }}</td>
                <td class="px-4 py-2.5 text-sm tabular-nums font-medium">{{ number_format($row['clicks'] ?? 0) }}</td>
                <td class="px-4 py-2.5 text-xs text-gray-500">
                    {{ number_format($ga4['sessions'] ?? 0) }} sessions
                    · {{ $ga4['engagement_rate'] ?? '—' }}% engaged
                    · {{ number_format($ga4['mapped_actions'] ?? 0) }} actions
                    <span class="block text-[10px] text-gray-400">{{ $ga4['note'] ?? $pages['attribution_note'] ?? '' }}</span>
                </td>
                <td class="px-4 py-2.5 text-xs text-amber-700 dark:text-amber-400">{{ $row['website_attention'] ?? '—' }}</td>
                <td class="px-4 py-2.5">
                    <button type="button" wire:click="openPage('{{ $row['path'] }}')" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Inspect</button>
                </td>
            </tr>
        @endforeach
    </x-ta.table>
</div>
