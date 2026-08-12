<div>
    <x-ta.page-breadcrumb pageTitle="Campaign" :crumbs="[
        ['label' => 'Overview', 'url' => route('operator.meta.overview', $asset)],
        ['label' => 'Campaigns', 'url' => route('operator.meta.campaigns', $asset)],
    ]" />

    @if (! $campaign)
        <x-ta.empty-state title="Campaign not found for this period"
            message="This campaign has no delivery in the selected period, or is not covered by local history yet.">
            <x-ta.button href="{{ route('operator.meta.campaigns', $asset) }}" variant="outline" size="sm">Back to campaigns</x-ta.button>
        </x-ta.empty-state>
    @else
        <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 class="text-title-sm font-bold text-gray-800 dark:text-white/90">{{ $campaign['name'] ?? '—' }}</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ $campaign['objective'] ?? '' }}
                    @if ($campaign['status'] ?? null) · {{ $campaign['status'] }} @endif
                    · {{ $workspace['period_label'] ?? '' }}
                </p>
            </div>
            <a href="{{ route('operator.meta.campaigns', $asset) }}"><x-ta.button variant="outline" size="sm">All campaigns</x-ta.button></a>
        </div>

        {{-- Campaign KPIs --}}
        @php
            $kpiDefs = [
                ['label' => 'Spend', 'key' => 'spend', 'type' => 'currency'],
                ['label' => 'Impressions', 'key' => 'impressions', 'type' => 'count'],
                ['label' => 'Link clicks', 'key' => 'inline_link_clicks', 'type' => 'count'],
                ['label' => 'Link CTR', 'key' => 'inline_link_click_ctr', 'type' => 'percentage_point'],
                ['label' => 'CPC', 'key' => 'cpc', 'type' => 'currency'],
                ['label' => 'CPM', 'key' => 'cpm', 'type' => 'currency'],
            ];
        @endphp
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 md:gap-6">
            @foreach ($kpiDefs as $def)
                @include('livewire.operator.meta.partials.kpi', ['kpi' => [
                    'label' => $def['label'],
                    'value' => $campaign[$def['key']] ?? null,
                    'type' => $def['type'],
                ]])
            @endforeach
        </div>

        @if ($campaign['primary_result_human_label'] ?? null)
            <div class="mt-4">
                <x-ta.card>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-500 dark:text-gray-400">Primary result · {{ $campaign['primary_result_human_label'] }}</span>
                        <span class="text-lg font-bold text-gray-800 dark:text-white/90">{{ is_numeric($campaign['primary_result_count'] ?? null) ? number_format((float) $campaign['primary_result_count']) : '—' }}</span>
                    </div>
                </x-ta.card>
            </div>
        @endif

        {{-- Ad Sets --}}
        <div class="mt-6">
            <h2 class="mb-3 text-base font-semibold text-gray-800 dark:text-white/90">Ad Sets</h2>
            @if (empty($adsets))
                <x-ta.empty-state title="No Ad Sets for this period" message="This campaign has no delivered Ad Sets in the selected period." />
            @else
                <x-ta.table>
                    <x-slot:head>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Ad Set</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Status</th>
                        <th class="px-5 py-3 text-right text-xs font-medium uppercase text-gray-400">Spend</th>
                        <th class="px-5 py-3 text-right text-xs font-medium uppercase text-gray-400">Impressions</th>
                        <th class="px-5 py-3 text-right text-xs font-medium uppercase text-gray-400">Link CTR</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Primary result</th>
                    </x-slot:head>
                    @foreach ($adsets as $adset)
                        @php
                            $status = strtoupper((string) ($adset['effective_status'] ?? $adset['status'] ?? ''));
                            $statusColor = match (true) {
                                in_array($status, ['ACTIVE', 'WITH_ISSUES'], true) => 'success',
                                str_contains($status, 'PAUSED') => 'warning',
                                default => 'light',
                            };
                        @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                            <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $adset['name'] ?? '—' }}</td>
                            <td class="px-5 py-4"><x-ta.badge :color="$statusColor">{{ $status ?: '—' }}</x-ta.badge></td>
                            <td class="px-5 py-4 text-right text-sm text-gray-700 dark:text-gray-300">{{ is_numeric($adset['spend'] ?? null) ? '$'.number_format((float) $adset['spend'], 2) : '—' }}</td>
                            <td class="px-5 py-4 text-right text-sm text-gray-700 dark:text-gray-300">{{ is_numeric($adset['impressions'] ?? null) ? number_format((float) $adset['impressions']) : '—' }}</td>
                            <td class="px-5 py-4 text-right text-sm text-gray-700 dark:text-gray-300">{{ is_numeric($adset['inline_link_click_ctr'] ?? null) ? number_format((float) $adset['inline_link_click_ctr'], 2).'%' : '—' }}</td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">
                                @if ($adset['primary_result_human_label'] ?? null)
                                    {{ $adset['primary_result_human_label'] }}
                                    @if (is_numeric($adset['primary_result_count'] ?? null)) · {{ number_format((float) $adset['primary_result_count']) }} @endif
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-ta.table>
            @endif
        </div>
    @endif
</div>
