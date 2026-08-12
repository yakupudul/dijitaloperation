@php use App\Support\Demo\DemoCatalog; @endphp
<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-gray-500">Meta Ads · {{ $asset['name'] ?? 'Atlas Dental — Meta' }}</p>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">Overview</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $data['period_label'] }} · Connected provider · Demo Mode</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-ta.button href="{{ route('demo.meta.campaigns', ['assetId' => $asset['id'] ?? DemoCatalog::META_ASSET_ID]) }}" size="sm" variant="outline">Campaigns</x-ta.button>
            <x-ta.button href="{{ route('demo.meta.creatives', ['assetId' => $asset['id'] ?? DemoCatalog::META_ASSET_ID]) }}" size="sm" variant="outline">Creatives</x-ta.button>
            <x-ta.button href="{{ route('demo.meta.insights', ['assetId' => $asset['id'] ?? DemoCatalog::META_ASSET_ID]) }}" size="sm">Insights</x-ta.button>
        </div>
    </div>

    @include('livewire.demo.partials.period-bar')

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($data['kpis'] as $kpi)
            @include('livewire.demo.partials.kpi', ['kpi' => $kpi])
        @endforeach
    </div>

    @foreach ($data['attention'] as $item)
        <x-ta.alert variant="warning" :title="$item['title']" :message="$item['body']">
            <div class="mt-3">
                <x-ta.button :href="route($item['route'], array_merge(['assetId' => $asset['id'] ?? DemoCatalog::META_ASSET_ID], $item['route_params'] ?? []))" size="sm">{{ $item['action'] }}</x-ta.button>
            </div>
        </x-ta.alert>
    @endforeach

    <x-ta.chart-card title="Spend trend" subtitle="Demo series · period-aware" :options="$chartOptions" />

    <x-ta.table>
        <x-slot:head>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Campaign</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Status</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Spend</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Results</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Cost / Result</th>
            <th class="px-5 py-3"></th>
        </x-slot:head>
        @foreach ($data['campaigns'] as $campaign)
            <tr>
                <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">
                    {{ $campaign['name'] }}
                    @if ($campaign['attention'] ?? null)
                        <x-ta.badge class="ml-2" color="error" size="sm">Attention</x-ta.badge>
                    @endif
                </td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ $campaign['status'] }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">₺{{ number_format($campaign['spend']) }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($campaign['results']) }} {{ $campaign['result_label'] }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">₺{{ number_format($campaign['cost_result']) }}</td>
                <td class="px-5 py-4 text-right">
                    <x-ta.button :href="route('demo.meta.campaign', ['assetId' => $asset['id'] ?? DemoCatalog::META_ASSET_ID, 'campaignId' => $campaign['id']])" size="sm" variant="outline">Open</x-ta.button>
                </td>
            </tr>
        @endforeach
    </x-ta.table>
</div>