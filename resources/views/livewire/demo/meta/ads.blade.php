<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Meta Ads',
        'title' => 'Ads',
        'subtitle' => 'Explore ad delivery tied to creatives · Connected provider',
        'badges' => ['Connected provider'],
    ])

    @include('livewire.demo.partials.meta-asset-nav', ['assetId' => $assetId, 'active' => 'ads'])
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

    @if (count($ads) === 0)
        @include('livewire.demo.partials.empty-panel', [
            'title' => 'No ads match filters',
            'message' => 'Adjust status filter or period to see ads.',
        ])
    @else
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Ad</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Campaign / Ad set</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Format</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Status</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Spend</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Results</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">CTR</th>
                <th class="px-5 py-3"></th>
            </x-slot:head>
            @foreach ($ads as $ad)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">
                        {{ $ad['name'] }}
                        @if ($ad['attention'] ?? null)
                            <x-ta.badge class="ml-1" color="error" size="sm">Attention</x-ta.badge>
                        @endif
                    </td>
                    <td class="px-5 py-4 text-sm text-gray-500">
                        <div>{{ $ad['campaign_name'] }}</div>
                        <div class="text-xs text-gray-400">{{ $ad['adset_name'] }}</div>
                    </td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $ad['format'] }}</td>
                    <td class="px-5 py-4">
                        <x-ta.badge :color="$ad['status'] === 'ACTIVE' ? 'success' : 'light'" size="sm">{{ $ad['status'] }}</x-ta.badge>
                    </td>
                    <td class="px-5 py-4 text-sm text-gray-500">₺{{ number_format($ad['spend']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($ad['results']) }} {{ $ad['result_label'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $ad['ctr'] }}%</td>
                    <td class="px-5 py-4 text-right">
                        <x-ta.button :href="route('demo.meta.ad', ['assetId' => $assetId, 'adId' => $ad['id']])" size="sm" variant="outline">Open</x-ta.button>
                    </td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif
</div>
