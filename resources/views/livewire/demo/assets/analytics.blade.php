@php
    $navTabs = [
        ['key' => 'overview', 'label' => 'Overview', 'wire' => true],
        ['key' => 'acquisition', 'label' => 'Acquisition', 'wire' => true],
        ['key' => 'landing_pages', 'label' => 'Landing pages', 'wire' => true],
        ['key' => 'engagement', 'label' => 'Engagement', 'wire' => true],
        ['key' => 'key_events', 'label' => 'Key events', 'wire' => true],
        ['key' => 'devices', 'label' => 'Devices', 'wire' => true],
    ];
@endphp

<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Google Analytics · '.($asset['name'] ?? 'Atlas Dental GA4'),
        'title' => 'Workspace',
        'subtitle' => ($data['period_label'] ?? '').' · Bound to Website evidence workflows',
        'badges' => [$data['provenance'] ?? 'Connected data source'],
        'actions' => '<a href="'.e(route('demo.website')).'" class="inline-flex"><span class="inline-flex items-center justify-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Website workspace</span></a>',
    ])

    @include('livewire.demo.partials.asset-nav', ['tabs' => $navTabs, 'active' => $tab])
    @include('livewire.demo.partials.period-bar')

    @if ($tab === 'overview')
        @include('livewire.demo.partials.kpi-strip', ['kpis' => $data['kpis'], 'primaryCount' => 4])
        @include('livewire.demo.partials.section-question', [
            'question' => 'How are sessions moving this period?',
        ])
        <x-ta.chart-card title="Sessions trend" :options="$sessionsChartOptions" />
    @endif

    @if ($tab === 'acquisition')
        @include('livewire.demo.partials.section-question', [
            'question' => 'Which channels acquire sessions?',
        ])
        <x-ta.chart-card title="Sessions by source" :options="$sourceChartOptions" />
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Source</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Sessions</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Engaged %</th>
            </x-slot:head>
            @foreach ($data['sources'] as $row)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['source'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($row['sessions']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['engaged'] }}%</td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif

    @if ($tab === 'landing_pages')
        @include('livewire.demo.partials.section-question', [
            'question' => 'Which landing pages convert?',
        ])
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Path</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Sessions</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Engaged rate</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Conversions</th>
            </x-slot:head>
            @foreach ($data['landing_pages'] as $row)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['path'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($row['sessions']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['engaged_rate'] }}%</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($row['conversions']) }}</td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif

    @if ($tab === 'engagement')
        @include('livewire.demo.partials.section-question', [
            'question' => 'How engaged are sessions?',
        ])
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($data['engagement'] as $row)
                <x-ta.metric-card :label="$row['metric']" :value="$row['value']" />
            @endforeach
        </div>
    @endif

    @if ($tab === 'key_events')
        @include('livewire.demo.partials.section-question', [
            'question' => 'Which key events fired?',
            'hint' => 'Event names from configured GA4 mapping — Demo Mode fixtures.',
        ])
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Event</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Count</th>
            </x-slot:head>
            @foreach ($data['key_events'] as $row)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['event'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($row['count']) }}</td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif

    @if ($tab === 'devices')
        @include('livewire.demo.partials.section-question', [
            'question' => 'How does traffic split by device?',
        ])
        <div class="grid gap-4 md:grid-cols-3">
            @foreach ($data['devices'] as $device => $share)
                <x-ta.metric-card :label="ucfirst($device)" :value="$share.'%'" />
            @endforeach
        </div>
    @endif
</div>
