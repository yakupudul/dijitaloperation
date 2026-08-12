<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-gray-500">Search Console · {{ $asset['name'] ?? 'atlasdental.example' }}</p>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">Overview</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $data['period_label'] }} · Connected provider · Demo Mode</p>
        </div>
        <x-ta.button href="{{ route('demo.website', ['tab' => 'search']) }}" size="sm" variant="outline">Website search tab</x-ta.button>
    </div>

    @include('livewire.demo.partials.period-bar')

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($data['kpis'] as $kpi)
            @include('livewire.demo.partials.kpi', ['kpi' => $kpi])
        @endforeach
    </div>

    <x-ta.chart-card title="Top queries by clicks" :options="$chartOptions" />

    <x-ta.table>
        <x-slot:head>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Query</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Clicks</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Impressions</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">CTR</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Position</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Trend</th>
        </x-slot:head>
        @foreach ($data['queries'] as $row)
            <tr>
                <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['query'] }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($row['clicks']) }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($row['impressions']) }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ $row['ctr'] }}%</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ $row['position'] }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ $row['trend'] }}</td>
            </tr>
        @endforeach
    </x-ta.table>

    <x-ta.table>
        <x-slot:head>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Page</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Clicks</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Impressions</th>
        </x-slot:head>
        @foreach ($data['pages'] as $row)
            <tr>
                <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['page'] }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($row['clicks']) }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($row['impressions']) }}</td>
            </tr>
        @endforeach
    </x-ta.table>
</div>