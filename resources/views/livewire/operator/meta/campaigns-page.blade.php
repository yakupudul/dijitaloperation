<div>
    @php $campaigns = $workspace['campaigns'] ?? []; @endphp

    <x-ta.page-breadcrumb pageTitle="Campaigns" :crumbs="[
        ['label' => 'Digital Assets', 'url' => route('operator.digital-assets')],
        ['label' => 'Overview', 'url' => route('operator.meta.overview', $asset)],
    ]" />

    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h1 class="text-title-sm font-bold text-gray-800 dark:text-white/90">{{ $workspace['account_identity']['name'] ?? $asset->name }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $workspace['period_label'] ?? '' }}</p>
        </div>
        <a href="{{ route('operator.meta.overview', $asset) }}"><x-ta.button variant="outline" size="sm">Back to overview</x-ta.button></a>
    </div>

    <div class="mb-6">
        @include('livewire.operator.meta.partials.period-bar')
    </div>

    @if (empty($campaigns))
        <x-ta.empty-state title="No delivered campaigns for this period"
            message="Only campaigns with spend or impressions in the selected period are shown by default." />
    @else
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Campaign</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Status</th>
                <th class="px-5 py-3 text-right text-xs font-medium uppercase text-gray-400">Spend</th>
                <th class="px-5 py-3 text-right text-xs font-medium uppercase text-gray-400">Impressions</th>
                <th class="px-5 py-3 text-right text-xs font-medium uppercase text-gray-400">Link CTR</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Primary result</th>
                <th class="px-5 py-3 text-right text-xs font-medium uppercase text-gray-400"></th>
            </x-slot:head>
            @foreach ($campaigns as $campaign)
                @php
                    $status = strtoupper((string) ($campaign['effective_status'] ?? $campaign['status'] ?? ''));
                    $statusColor = match (true) {
                        in_array($status, ['ACTIVE', 'WITH_ISSUES'], true) => 'success',
                        str_contains($status, 'PAUSED') => 'warning',
                        $status === '' => 'light',
                        default => 'light',
                    };
                @endphp
                <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                    <td class="px-5 py-4">
                        <a href="{{ route('operator.meta.campaign', ['digitalAsset' => $asset, 'campaignId' => $campaign['entity_id'] ?? '']) }}"
                            class="text-sm font-medium text-gray-800 hover:text-brand-600 dark:text-white/90 dark:hover:text-brand-400">
                            {{ $campaign['name'] ?? '—' }}
                        </a>
                        <span class="block text-xs text-gray-400">{{ $campaign['objective'] ?? '' }}</span>
                    </td>
                    <td class="px-5 py-4"><x-ta.badge :color="$statusColor">{{ $status ?: '—' }}</x-ta.badge></td>
                    <td class="px-5 py-4 text-right text-sm text-gray-700 dark:text-gray-300">{{ is_numeric($campaign['spend'] ?? null) ? '$'.number_format((float) $campaign['spend'], 2) : '—' }}</td>
                    <td class="px-5 py-4 text-right text-sm text-gray-700 dark:text-gray-300">{{ is_numeric($campaign['impressions'] ?? null) ? number_format((float) $campaign['impressions']) : '—' }}</td>
                    <td class="px-5 py-4 text-right text-sm text-gray-700 dark:text-gray-300">{{ is_numeric($campaign['inline_link_click_ctr'] ?? null) ? number_format((float) $campaign['inline_link_click_ctr'], 2).'%' : '—' }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">
                        @if ($campaign['primary_result_human_label'] ?? null)
                            {{ $campaign['primary_result_human_label'] }}
                            @if (is_numeric($campaign['primary_result_count'] ?? null)) · {{ number_format((float) $campaign['primary_result_count']) }} @endif
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-5 py-4 text-right">
                        <a href="{{ route('operator.meta.campaign', ['digitalAsset' => $asset, 'campaignId' => $campaign['entity_id'] ?? '']) }}"
                            class="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Open &rarr;</a>
                    </td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif
</div>
