<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Meta Ads',
        'title' => 'Ad Sets',
        'subtitle' => 'Explore delivery units across campaigns · Connected provider',
        'badges' => ['Connected provider'],
    ])

    @include('livewire.demo.partials.meta-asset-nav', ['assetId' => $assetId, 'active' => 'adsets'])
    @include('livewire.demo.partials.period-bar')

    <div class="flex flex-wrap gap-2">
        @foreach (['all' => 'All', 'ACTIVE' => 'Active', 'PAUSED' => 'Paused'] as $key => $label)
            <button type="button" wire:click="setStatusFilter('{{ $key }}')"
                @class([
                    'rounded-md px-2.5 py-1.5 text-xs font-medium transition',
                    'bg-brand-500 text-white' => $statusFilter === $key,
                    'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $statusFilter !== $key,
                ])>{{ $label }}</button>
        @endforeach
    </div>

    @if (count($adsets) === 0)
        @include('livewire.demo.partials.empty-panel', [
            'title' => 'No ad sets match filters',
            'message' => 'Adjust status filter or period to see ad sets.',
        ])
    @else
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Ad set</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Campaign</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Status</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Spend</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Results</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Cost / Result</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">CTR</th>
                <th class="px-5 py-3"></th>
            </x-slot:head>
            @foreach ($adsets as $adset)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $adset['name'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $adset['campaign_name'] }}</td>
                    <td class="px-5 py-4">
                        <x-ta.badge :color="$adset['status'] === 'ACTIVE' ? 'success' : 'light'" size="sm">{{ $adset['status'] }}</x-ta.badge>
                    </td>
                    <td class="px-5 py-4 text-sm text-gray-500">₺{{ number_format($adset['spend']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($adset['results']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $adset['cost_result'] !== null ? '₺'.number_format($adset['cost_result']) : '—' }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $adset['ctr'] }}%</td>
                    <td class="px-5 py-4 text-right">
                        <x-ta.button :href="route('demo.meta.adset', ['assetId' => $assetId, 'adSetId' => $adset['id']])" size="sm" variant="outline">Open</x-ta.button>
                    </td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif
</div>
