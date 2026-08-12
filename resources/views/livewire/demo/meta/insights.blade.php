<div class="space-y-6">
    @include('livewire.demo.partials.flash')
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <x-ta.button href="{{ route('demo.meta.overview', ['assetId' => $assetId]) }}" size="sm" variant="outline">← Overview</x-ta.button>
            <h1 class="mt-3 text-2xl font-bold text-gray-800 dark:text-white/90">Insights</h1>
            <p class="text-sm text-gray-500">Cross-campaign delivery & efficiency · Demo Mode</p>
        </div>
        @include('livewire.demo.partials.period-bar')
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach (array_slice($kpis, 0, 4) as $kpi)
            @include('livewire.demo.partials.kpi', ['kpi' => $kpi])
        @endforeach
    </div>

    <x-ta.chart-card title="Spend vs leads (scaled)" subtitle="Deterministic demo series" :options="$chartOptions" />

    <x-ta.table>
        <x-slot:head>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Campaign</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Reach</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Frequency</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">CTR</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Cost / Result</th>
        </x-slot:head>
        @foreach ($campaigns as $campaign)
            <tr>
                <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $campaign['name'] }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($campaign['reach']) }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ $campaign['frequency'] }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ $campaign['ctr'] }}%</td>
                <td class="px-5 py-4 text-sm text-gray-500">₺{{ number_format($campaign['cost_result']) }}</td>
            </tr>
        @endforeach
    </x-ta.table>
</div>