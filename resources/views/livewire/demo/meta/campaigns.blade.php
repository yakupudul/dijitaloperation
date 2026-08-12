<div class="space-y-6">
    @include('livewire.demo.partials.flash')
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <x-ta.button href="{{ route('demo.meta.overview', ['assetId' => $assetId]) }}" size="sm" variant="outline">← Overview</x-ta.button>
            <h1 class="mt-3 text-2xl font-bold text-gray-800 dark:text-white/90">Campaigns</h1>
        </div>
        @include('livewire.demo.partials.period-bar')
    </div>

    <x-ta.table>
        <x-slot:head>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Campaign</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Objective</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Spend</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Results</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">CTR</th>
            <th class="px-5 py-3"></th>
        </x-slot:head>
        @foreach ($campaigns as $campaign)
            <tr>
                <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $campaign['name'] }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ $campaign['objective'] }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">₺{{ number_format($campaign['spend']) }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($campaign['results']) }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ $campaign['ctr'] }}%</td>
                <td class="px-5 py-4 text-right">
                    <x-ta.button :href="route('demo.meta.campaign', ['assetId' => $assetId, 'campaignId' => $campaign['id']])" size="sm">Open</x-ta.button>
                </td>
            </tr>
        @endforeach
    </x-ta.table>
</div>