@php
    $navTabs = [
        ['key' => 'overview', 'label' => 'Overview', 'wire' => true],
        ['key' => 'queries', 'label' => 'Queries', 'wire' => true],
        ['key' => 'pages', 'label' => 'Pages', 'wire' => true],
        ['key' => 'countries', 'label' => 'Countries', 'wire' => true],
        ['key' => 'devices', 'label' => 'Devices', 'wire' => true],
        ['key' => 'indexing', 'label' => 'Indexing', 'wire' => true],
        ['key' => 'sitemaps', 'label' => 'Sitemaps', 'wire' => true],
        ['key' => 'url_inspection', 'label' => 'URL inspection', 'wire' => true],
    ];
@endphp

<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Search Console · '.($asset['name'] ?? 'Search Console'),
        'title' => 'Workspace',
        'subtitle' => ($data['period_label'] ?? '').' · Search visibility evidence for Website',
        'badges' => [$data['provenance'] ?? 'Connected data source'],
        'actions' => '<a href="'.e(route('operator.website', ['tab' => 'search'])).'" class="inline-flex"><span class="inline-flex items-center justify-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Website search tab</span></a>',
    ])

    @include('livewire.demo.partials.asset-nav', ['tabs' => $navTabs, 'active' => $tab])

    @if ($showPeriodBar)
        @include('livewire.demo.partials.period-bar')
    @endif

    @if ($tab === 'overview')
        @include('livewire.demo.partials.kpi-strip', ['kpis' => $data['kpis'], 'primaryCount' => 4])
        @include('livewire.demo.partials.section-question', [
            'question' => 'Which queries drive the most clicks?',
        ])
        <x-ta.chart-card title="Top queries by clicks" :options="$chartOptions" />
    @endif

    @if ($tab === 'queries')
        @include('livewire.demo.partials.section-question', [
            'question' => 'How do queries perform on clicks, CTR, and position?',
        ])
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
    @endif

    @if ($tab === 'pages')
        @include('livewire.demo.partials.section-question', [
            'question' => 'Which pages earn search clicks?',
        ])
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Page</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Clicks</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Impressions</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">CTR</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Position</th>
            </x-slot:head>
            @foreach ($data['pages'] as $row)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['page'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($row['clicks']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($row['impressions']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['ctr'] ?? '—' }}{{ isset($row['ctr']) ? '%' : '' }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['position'] ?? '—' }}</td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif

    @if ($tab === 'countries')
        @include('livewire.demo.partials.section-question', [
            'question' => 'Where do search clicks come from?',
        ])
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Country</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Clicks</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Impressions</th>
            </x-slot:head>
            @foreach ($data['countries'] as $row)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['country'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($row['clicks']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($row['impressions']) }}</td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif

    @if ($tab === 'devices')
        @include('livewire.demo.partials.section-question', [
            'question' => 'How does search performance differ by device?',
        ])
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Device</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Clicks</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">CTR</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Position</th>
            </x-slot:head>
            @foreach ($data['devices'] as $row)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['device'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($row['clicks']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['ctr'] }}%</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['position'] }}</td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif

    @if ($tab === 'indexing')
        @include('livewire.demo.partials.section-question', [
            'question' => 'What is the index coverage picture?',
        ])
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ta.metric-card label="Indexed" :value="(string) $data['indexing']['indexed']" />
            <x-ta.metric-card label="Discovered not indexed" :value="(string) $data['indexing']['discovered_not_indexed']" />
            <x-ta.metric-card label="Crawled not indexed" :value="(string) $data['indexing']['crawled_not_indexed']" />
            <x-ta.metric-card label="Excluded" :value="(string) $data['indexing']['excluded']" />
        </div>
        <div class="space-y-3">
            @foreach ($data['indexing']['issues'] as $issue)
                <x-ta.card padding="p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <x-ta.badge :color="match($issue['severity']) { 'medium' => 'warning', default => 'info' }" size="sm">{{ $issue['severity'] }}</x-ta.badge>
                            <p class="mt-2 font-semibold text-gray-800 dark:text-white/90">{{ $issue['title'] }}</p>
                        </div>
                        <span class="text-sm text-gray-500">{{ $issue['count'] }}</span>
                    </div>
                </x-ta.card>
            @endforeach
        </div>
    @endif

    @if ($tab === 'sitemaps')
        @include('livewire.demo.partials.section-question', [
            'question' => 'Are sitemaps submitted and healthy?',
        ])
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Sitemap</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Submitted</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Discovered</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Status</th>
            </x-slot:head>
            @foreach ($data['sitemaps'] as $row)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['path'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['submitted'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['discovered'] }}</td>
                    <td class="px-5 py-4"><x-ta.badge color="success" size="sm">{{ $row['status'] }}</x-ta.badge></td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif

    @if ($tab === 'url_inspection')
        @include('livewire.demo.partials.section-question', [
            'question' => 'What does URL inspection say for key pages?',
        ])
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">URL</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Coverage</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Last crawl</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Mobile</th>
            </x-slot:head>
            @foreach ($data['url_inspection'] as $row)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['url'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['coverage'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['last_crawl'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['mobile'] }}</td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif
</div>
