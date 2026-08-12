@php
    $dimensionLabels = [
        'placement' => 'Placement',
        'device' => 'Device',
        'age' => 'Age',
        'gender' => 'Gender',
        'region' => 'Region',
    ];
@endphp

<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Meta Ads',
        'title' => 'Breakdowns',
        'subtitle' => 'Explore where spend and results concentrate · Connected provider',
        'badges' => ['Connected provider'],
    ])

    @include('livewire.demo.partials.meta-asset-nav', ['assetId' => $assetId, 'active' => 'breakdowns'])
    @include('livewire.demo.partials.period-bar')

    @include('livewire.demo.partials.section-question', [
        'question' => 'Where is delivery concentrating?',
        'hint' => 'Switch dimension to inspect placement, device, demographics, and region.',
    ])

    <div class="flex flex-wrap gap-2">
        @foreach ($dimensions as $key)
            <button type="button" wire:click="setDimension('{{ $key }}')"
                @class([
                    'rounded-md px-2.5 py-1.5 text-xs font-medium transition',
                    'bg-brand-500 text-white' => $dimension === $key,
                    'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $dimension !== $key,
                ])>{{ $dimensionLabels[$key] ?? ucfirst($key) }}</button>
        @endforeach
    </div>

    @if (count($rows) === 0)
        @include('livewire.demo.partials.empty-panel', [
            'title' => 'No breakdown rows',
            'message' => 'No breakdown data for this dimension in the selected period.',
        ])
    @else
        <x-ta.card>
            <div class="space-y-4">
                @foreach ($rows as $row)
                    @php $pct = min(100, round(((float) $row['spend'] / $maxSpend) * 100, 1)); @endphp
                    <div>
                        <div class="mb-1 flex items-center justify-between gap-3 text-sm">
                            <span class="font-medium text-gray-800 dark:text-white/90">{{ $row['dimension'] }}</span>
                            <span class="text-gray-500">
                                ₺{{ number_format($row['spend']) }}
                                · {{ number_format($row['results']) }} results
                                @if ($row['efficiency'] !== null)
                                    · ₺{{ number_format($row['efficiency']) }}/result
                                @endif
                            </span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5">
                            <div class="h-full rounded-full bg-brand-500" style="width: {{ $pct }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ta.card>

        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">{{ $dimensionLabels[$dimension] ?? 'Dimension' }}</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Spend</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Results</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Efficiency</th>
            </x-slot:head>
            @foreach ($rows as $row)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['dimension'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">₺{{ number_format($row['spend']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($row['results']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['efficiency'] !== null ? '₺'.number_format($row['efficiency']) : '—' }}</td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif
</div>
