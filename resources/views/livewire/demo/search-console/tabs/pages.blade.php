@php
    $pages = $data['pages'] ?? [];
    $directory = $pages['directory'] ?? [];
@endphp

<div class="space-y-10">
    <div>
        <h2 class="section-title">Pages</h2>
        <p class="mt-0.5 text-xs text-gray-500">{{ $pages['subtitle'] ?? '' }}</p>
        <p class="mt-1 text-xs text-gray-700 dark:text-gray-300">{{ $pages['attribution_note'] ?? '' }}</p>
    </div>

    <div class="max-w-full overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-gray-200 dark:border-gray-800">
                    <tr>
            <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Page</th>
            <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Content role</th>
            <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Offering</th>
            <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Clicks</th>
            <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">GA4 context</th>
            <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Website attention</th>
            <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500"></th>
        </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
        @foreach ($directory as $row)
            @php $ga4 = $row['ga4_context'] ?? []; @endphp
            <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                <td class="px-4 py-2.5">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['path'] }}</p>
                    <p class="text-xs text-gray-500">{{ $row['title'] ?? '' }}</p>
                </td>
                <td class="px-4 py-2.5 text-xs text-gray-600 dark:text-gray-300">{{ $row['content_role'] ?? '—' }}</td>
                <td class="px-4 py-2.5 text-xs text-gray-600 dark:text-gray-300">{{ $row['offering'] ?? '—' }}</td>
                <td class="px-4 py-2.5 text-sm tabular-nums font-medium">{{ number_format($row['clicks'] ?? 0) }}</td>
                <td class="px-4 py-2.5 text-xs text-gray-500">
                    {{ number_format($ga4['sessions'] ?? 0) }} sessions, {{ $ga4['engagement_rate'] ?? '—' }}% engaged, {{ number_format($ga4['mapped_actions'] ?? 0) }} actions
                    <span class="block text-xs text-gray-500">{{ $ga4['note'] ?? $pages['attribution_note'] ?? '' }}</span>
                </td>
                <td class="px-4 py-2.5 text-xs text-warning-700 dark:text-warning-400">{{ $row['website_attention'] ?? '—' }}</td>
                <td class="px-4 py-2.5">
                    <button type="button" wire:click="openPage('{{ $row['path'] }}')" class="link-neutral text-xs">Inspect</button>
                </td>
            </tr>
        @endforeach
    </tbody>
            </table>
        </div>
</div>
