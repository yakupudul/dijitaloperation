<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Meta Ads',
        'title' => 'Campaigns',
        'subtitle' => 'Explore campaign delivery and efficiency · Connected provider',
        'badges' => ['Connected provider'],
        'actions' => '<button type="button" wire:click="toggleExpert" class="rounded-md px-2.5 py-1.5 text-xs font-medium '.($expert ? 'bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-200' : 'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300').'">'.($expert ? 'Expert columns · on' : 'Expert columns').'</button>',
    ])

    @include('livewire.demo.partials.meta-asset-nav', ['assetId' => $assetId, 'active' => 'campaigns'])
    @include('livewire.demo.partials.period-bar')

    <div class="flex flex-wrap items-center gap-3">
        <div class="flex flex-wrap gap-2">
            <span class="self-center text-xs uppercase tracking-wide text-gray-400">Status</span>
            @foreach (['all' => 'All', 'ACTIVE' => 'Active', 'PAUSED' => 'Paused'] as $key => $label)
                <button type="button" wire:click="setStatusFilter('{{ $key }}')"
                    @class([
                        'rounded-md px-2.5 py-1.5 text-xs font-medium transition',
                        'bg-brand-500 text-white' => $statusFilter === $key,
                        'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $statusFilter !== $key,
                    ])>{{ $label }}</button>
            @endforeach
        </div>
        <div class="flex flex-wrap gap-2">
            <span class="self-center text-xs uppercase tracking-wide text-gray-400">Objective</span>
            @foreach ([
                'all' => 'All',
                'OUTCOME_LEADS' => 'Leads',
                'OUTCOME_ENGAGEMENT' => 'Engagement',
                'OUTCOME_AWARENESS' => 'Awareness',
            ] as $key => $label)
                <button type="button" wire:click="setObjectiveFilter('{{ $key }}')"
                    @class([
                        'rounded-md px-2.5 py-1.5 text-xs font-medium transition',
                        'bg-brand-500 text-white' => $objectiveFilter === $key,
                        'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $objectiveFilter !== $key,
                    ])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    @if (count($campaigns) === 0)
        @include('livewire.demo.partials.empty-panel', [
            'title' => 'No campaigns match filters',
            'message' => 'Clear status or objective filters to see campaigns again.',
        ])
    @else
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Campaign</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Objective</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Status</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Spend</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Results</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">CTR</th>
                @if ($expert)
                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Cost / Result</th>
                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Reach</th>
                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Frequency</th>
                @endif
                <th class="px-5 py-3"></th>
            </x-slot:head>
            @foreach ($campaigns as $campaign)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">
                        {{ $campaign['name'] }}
                        @if ($campaign['attention'] ?? null)
                            <x-ta.badge class="ml-1" color="error" size="sm">Attention</x-ta.badge>
                        @endif
                    </td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $campaign['objective'] }}</td>
                    <td class="px-5 py-4">
                        <x-ta.badge :color="$campaign['status'] === 'ACTIVE' ? 'success' : 'light'" size="sm">{{ $campaign['status'] }}</x-ta.badge>
                    </td>
                    <td class="px-5 py-4 text-sm text-gray-500">₺{{ number_format($campaign['spend']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($campaign['results']) }} {{ $campaign['result_label'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $campaign['ctr'] }}%</td>
                    @if ($expert)
                        <td class="px-5 py-4 text-sm text-gray-500">₺{{ number_format($campaign['cost_result']) }}</td>
                        <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($campaign['reach']) }}</td>
                        <td class="px-5 py-4 text-sm text-gray-500">{{ $campaign['frequency'] }}</td>
                    @endif
                    <td class="px-5 py-4 text-right">
                        <x-ta.button :href="route('demo.meta.campaign', ['assetId' => $assetId, 'campaignId' => $campaign['id']])" size="sm">Open</x-ta.button>
                    </td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif
</div>
