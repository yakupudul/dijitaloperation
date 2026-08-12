<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Meta Ads',
        'title' => 'Insights',
        'subtitle' => 'Cross-campaign delivery & efficiency · Connected provider',
        'badges' => ['Connected provider'],
    ])

    @include('livewire.demo.partials.meta-asset-nav', ['assetId' => $assetId, 'active' => 'insights'])
    @include('livewire.demo.partials.period-bar')

    @include('livewire.demo.partials.kpi-strip', [
        'kpis' => array_slice($kpis, 0, 4),
        'primaryCount' => 4,
    ])

    <x-ta.chart-card
        title="Is spend translating into leads?"
        subtitle="Spend vs leads (scaled) · Deterministic demo series"
        :options="$chartOptions"
    />

    @if ($seasonality)
        <x-ta.alert variant="info" title="Seasonality note" :message="$seasonality" />
    @endif

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
