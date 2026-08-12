@php use App\Support\Demo\DemoCatalog; @endphp
<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Meta Ads · '.($asset['name'] ?? 'Atlas Dental — Meta'),
        'title' => 'Overview',
        'subtitle' => ($data['period_label'] ?? '').' · Glance → Explore → Decide',
        'badges' => ['Connected provider'],
    ])

    @include('livewire.demo.partials.meta-asset-nav', [
        'assetId' => $asset['id'] ?? DemoCatalog::META_ASSET_ID,
        'active' => 'overview',
    ])

    @include('livewire.demo.partials.period-bar')

    {{-- Glance --}}
    @include('livewire.demo.partials.kpi-strip', [
        'kpis' => $data['kpis'],
        'primaryCount' => 4,
    ])

    {{-- Explore --}}
    <x-ta.chart-card
        title="How is paid-media efficiency changing?"
        subtitle="Spend trend for the selected period · Demo series"
        :options="$chartOptions"
    />

    <div class="grid gap-4 lg:grid-cols-2">
        <x-ta.card>
            @include('livewire.demo.partials.section-question', [
                'question' => 'What result types is spend buying?',
                'hint' => 'Distinct result labels are never summed into one fake total.',
            ])
            <div class="space-y-3">
                @foreach ($resultMix as $mix)
                    @php $pct = min(100, round(($mix['results'] / $maxMixResults) * 100, 1)); @endphp
                    <div>
                        <div class="mb-1 flex items-center justify-between gap-2 text-sm">
                            <span class="font-medium text-gray-800 dark:text-white/90">{{ $mix['label'] }}</span>
                            <span class="text-gray-500">{{ number_format($mix['results']) }} · ₺{{ number_format($mix['spend']) }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5">
                            <div class="h-full rounded-full bg-[#ea580c]" style="width: {{ $pct }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ta.card>

        <x-ta.card>
            @include('livewire.demo.partials.section-question', [
                'question' => 'Which campaigns drive spend?',
                'hint' => 'Contribution by spend share in this period.',
            ])
            <div class="space-y-3">
                @foreach ($contribution as $row)
                    <div>
                        <div class="mb-1 flex items-center justify-between gap-2 text-sm">
                            <span class="font-medium text-gray-800 dark:text-white/90">
                                {{ $row['name'] }}
                                @if ($row['attention'] ?? null)
                                    <x-ta.badge color="error" size="sm" class="ml-1">Attention</x-ta.badge>
                                @endif
                            </span>
                            <span class="text-gray-500">{{ $row['share'] }}% · ₺{{ number_format($row['spend']) }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5">
                            <div class="h-full rounded-full bg-[#ea580c]" style="width: {{ min(100, $row['share']) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ta.card>
    </div>

    {{-- Decide --}}
    <div>
        @include('livewire.demo.partials.section-question', [
            'question' => 'What needs a decision now?',
            'hint' => 'Highest-severity attention items for this asset.',
        ])
        @include('livewire.demo.partials.attention-list', ['items' => $attention])
    </div>

    @if ($seasonality)
        <x-ta.alert variant="info" title="Seasonality note" :message="$seasonality" />
    @endif

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
