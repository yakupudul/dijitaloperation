@php
    $navTabs = [
        ['key' => 'overview', 'label' => 'Overview', 'wire' => true],
        ['key' => 'technical', 'label' => 'Technical', 'wire' => true],
        ['key' => 'search', 'label' => 'Search', 'wire' => true],
        ['key' => 'pages', 'label' => 'Pages', 'wire' => true],
        ['key' => 'content', 'label' => 'Content', 'wire' => true],
        ['key' => 'conversions', 'label' => 'Conversions', 'wire' => true],
        ['key' => 'performance', 'label' => 'Performance', 'wire' => true],
        ['key' => 'lifecycle', 'label' => 'Lifecycle', 'wire' => true],
        ['key' => 'insights', 'label' => 'Insights', 'wire' => true],
    ];
    $vitalColor = function (string $rating): string {
        return match ($rating) {
            'good' => 'success',
            'needs_improvement' => 'warning',
            'poor' => 'error',
            default => 'info',
        };
    };
    $inventoryColor = function (string $state): string {
        return match ($state) {
            'Strong' => 'success',
            'Needs refresh' => 'warning',
            'Thin' => 'error',
            'Opportunity' => 'info',
            default => 'light',
        };
    };
@endphp

<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Website · '.($asset['name'] ?? 'atlasdental.example'),
        'title' => 'Workspace',
        'subtitle' => ($data['period_label'] ?? '').' · Glance → Explore → Decide',
        'badges' => ['Public + Detected'],
        'actions' => view('livewire.demo.partials._website-header-actions')->render(),
    ])

    @include('livewire.demo.partials.website-asset-nav', ['tabs' => $navTabs, 'active' => $tab])

    @if ($showPeriodBar)
        @include('livewire.demo.partials.period-bar')
    @endif

    @if ($tab === 'overview')
        @include('livewire.demo.partials.kpi-strip', ['kpis' => $data['kpis'], 'primaryCount' => 4])

        <div class="grid gap-4 lg:grid-cols-2">
            <div>
                @include('livewire.demo.partials.section-question', [
                    'question' => 'How is organic search demand moving?',
                    'hint' => 'Organic clicks trend for the selected period.',
                ])
                <x-ta.chart-card title="Organic clicks" :options="$organicChartOptions" />
            </div>
            <div>
                @include('livewire.demo.partials.section-question', [
                    'question' => 'How is overall site traffic moving?',
                    'hint' => 'Sessions across channels for the selected period.',
                ])
                <x-ta.chart-card title="Sessions" :options="$trafficChartOptions" />
            </div>
        </div>

        <div>
            @include('livewire.demo.partials.section-question', [
                'question' => 'Which pages carry sessions and leads?',
            ])
            <x-ta.table>
                <x-slot:head>
                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Path</th>
                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Sessions</th>
                    <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Leads</th>
                </x-slot:head>
                @foreach ($data['top_pages'] as $page)
                    <tr>
                        <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $page['path'] }}</td>
                        <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($page['sessions']) }}</td>
                        <td class="px-5 py-4 text-sm text-gray-500">{{ $page['leads'] }}</td>
                    </tr>
                @endforeach
            </x-ta.table>
        </div>

        <div>
            @include('livewire.demo.partials.section-question', [
                'question' => 'What needs attention on this website?',
            ])
            @include('livewire.demo.partials.attention-list', ['items' => $attention])
        </div>

        @if (! empty($data['seasonality']))
            <x-ta.alert variant="info" title="Seasonality note" :message="$data['seasonality']" />
        @endif
    @endif

    @if ($tab === 'technical')
        @include('livewire.demo.partials.section-question', [
            'question' => 'Which technical issues should we triage?',
            'hint' => 'Grouped by Critical / Warnings / Opportunities. Filter by severity.',
        ])

        <div class="flex flex-wrap gap-2">
            @foreach (['all' => 'All', 'high' => 'High', 'medium' => 'Medium', 'info' => 'Info'] as $key => $label)
                <button type="button" wire:click="setSeverity('{{ $key }}')"
                    @class([
                        'rounded-md px-2.5 py-1.5 text-xs font-medium transition',
                        'bg-brand-500 text-white' => $severity === $key,
                        'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $severity !== $key,
                    ])>{{ $label }}</button>
            @endforeach
        </div>

        @php
            $groups = [
                'critical' => ['label' => 'Critical', 'items' => $technicalGrouped['critical'] ?? []],
                'warnings' => ['label' => 'Warnings', 'items' => $technicalGrouped['warnings'] ?? []],
                'opportunities' => ['label' => 'Opportunities', 'items' => $technicalGrouped['opportunities'] ?? []],
            ];
        @endphp

        <div class="space-y-3">
            @foreach ($groups as $groupKey => $group)
                <details class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 open:shadow-sm dark:bg-gray-800 dark:ring-gray-700" @if ($groupKey === 'critical') open @endif>
                    <summary class="cursor-pointer list-none px-5 py-4 font-semibold text-gray-800 dark:text-white/90">
                        <span class="flex items-center justify-between gap-3">
                            <span>{{ $group['label'] }}</span>
                            <x-ta.badge color="light" size="sm">{{ count($group['items']) }}</x-ta.badge>
                        </span>
                    </summary>
                    <div class="space-y-3 border-t border-gray-100 px-5 py-4 dark:border-gray-700">
                        @forelse ($group['items'] as $item)
                            <div class="flex items-start justify-between gap-3 rounded-lg bg-gray-50 px-3 py-3 dark:bg-white/[0.02]">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-ta.badge :color="match($item['severity']) { 'high' => 'error', 'medium' => 'warning', default => 'info' }" size="sm">{{ $item['severity'] }}</x-ta.badge>
                                        @if (! empty($item['impact']))
                                            <span class="text-xs text-gray-400">{{ $item['impact'] }}</span>
                                        @endif
                                    </div>
                                    <h3 class="mt-2 font-semibold text-gray-800 dark:text-white/90">{{ $item['title'] }}</h3>
                                    <p class="text-sm text-gray-500">{{ $item['detail'] }}</p>
                                </div>
                                <x-ta.button href="{{ route('demo.findings') }}" size="sm" variant="outline">Findings</x-ta.button>
                            </div>
                        @empty
                            @include('livewire.demo.partials.empty-panel', [
                                'title' => 'No issues in this group',
                                'message' => 'Adjust the severity filter or check another accordion.',
                            ])
                        @endforelse
                    </div>
                </details>
            @endforeach
        </div>
    @endif

    @if ($tab === 'search')
        @include('livewire.demo.partials.section-question', [
            'question' => 'How is search presence looking from GSC signals?',
            'hint' => 'Mirrored from Search Console connected data source.',
        ])
        @include('livewire.demo.partials.kpi-strip', ['kpis' => $data['search']['kpis'], 'primaryCount' => 4])
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Query</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Clicks</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Position</th>
            </x-slot:head>
            @foreach ($data['search']['top_queries'] as $row)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['query'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($row['clicks']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['position'] }}</td>
                </tr>
            @endforeach
        </x-ta.table>
        <x-ta.button href="{{ route('demo.search-console') }}" size="sm">Open Search Console workspace</x-ta.button>
    @endif

    @if ($tab === 'pages')
        @include('livewire.demo.partials.section-question', [
            'question' => 'Which paths drive sessions and leads?',
        ])
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Path</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Sessions</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Leads</th>
            </x-slot:head>
            @foreach ($data['top_pages'] as $page)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $page['path'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($page['sessions']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $page['leads'] }}</td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif

    @if ($tab === 'content')
        @include('livewire.demo.partials.section-question', [
            'question' => 'What is the content inventory state?',
            'hint' => 'Strong / Needs refresh / Thin / Opportunity.',
        ])
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Page</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">State</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Words</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Updated</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Organic</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Issues</th>
            </x-slot:head>
            @foreach ($data['content']['pages'] as $page)
                <tr>
                    <td class="px-5 py-4 text-sm">
                        <p class="font-medium text-gray-800 dark:text-white/90">{{ $page['title'] }}</p>
                        <p class="text-xs text-gray-400">{{ $page['path'] }}</p>
                    </td>
                    <td class="px-5 py-4">
                        <x-ta.badge :color="$inventoryColor($page['inventory_state'])" size="sm">{{ $page['inventory_state'] }}</x-ta.badge>
                    </td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($page['word_count']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $page['last_updated'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($page['organic_clicks']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ count($page['issues']) ? implode(', ', $page['issues']) : '—' }}</td>
                </tr>
            @endforeach
        </x-ta.table>
        <x-ta.card>
            @include('livewire.demo.partials.section-question', ['question' => 'Content opportunities'])
            <ul class="mt-2 space-y-2 text-sm text-gray-600 dark:text-gray-300">
                @foreach ($data['content']['opportunities'] as $opp)
                    <li>• {{ $opp }}</li>
                @endforeach
            </ul>
        </x-ta.card>
    @endif

    @if ($tab === 'conversions')
        @include('livewire.demo.partials.section-question', [
            'question' => 'Which conversion events and landings matter?',
        ])
        <div class="grid gap-4 lg:grid-cols-2">
            <x-ta.card>
                <h3 class="font-semibold text-gray-800 dark:text-white/90">Key events</h3>
                <ul class="mt-3 space-y-2">
                    @foreach ($data['conversions']['events'] as $event)
                        <li class="flex items-center justify-between text-sm">
                            <span class="text-gray-700 dark:text-gray-300">{{ $event['event'] }}</span>
                            <span class="text-gray-500">{{ number_format($event['count']) }} · {{ $event['share'] }}%</span>
                        </li>
                    @endforeach
                </ul>
            </x-ta.card>
            <x-ta.card>
                <h3 class="font-semibold text-gray-800 dark:text-white/90">By landing page</h3>
                <ul class="mt-3 space-y-2">
                    @foreach ($data['conversions']['by_landing'] as $row)
                        <li class="flex items-center justify-between text-sm">
                            <span class="font-medium text-gray-800 dark:text-white/90">{{ $row['path'] }}</span>
                            <span class="text-gray-500">{{ $row['leads'] }} leads · {{ $row['rate'] }}%</span>
                        </li>
                    @endforeach
                </ul>
            </x-ta.card>
        </div>
    @endif

    @if ($tab === 'performance')
        @include('livewire.demo.partials.section-question', [
            'question' => 'How do field and lab vitals compare?',
            'hint' => 'Field (CrUX-style) vs Lab (Lighthouse-style) — never mixed.',
        ])
        <div class="grid gap-4 lg:grid-cols-2">
            <x-ta.card>
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h3 class="font-semibold text-gray-800 dark:text-white/90">FIELD vitals</h3>
                    @include('livewire.demo.partials.provenance-badge', ['label' => 'Field / CrUX-style'])
                </div>
                <div class="space-y-3">
                    @foreach ($data['performance']['field'] as $row)
                        <div class="flex items-center justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.02]">
                            <div>
                                <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['metric'] }}</p>
                                <p class="text-xs text-gray-400">Mobile {{ $row['mobile'] }} · Desktop {{ $row['desktop'] }}</p>
                            </div>
                            <x-ta.badge :color="$vitalColor($row['rating'])" size="sm">{{ str_replace('_', ' ', $row['rating']) }}</x-ta.badge>
                        </div>
                    @endforeach
                </div>
            </x-ta.card>
            <x-ta.card>
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h3 class="font-semibold text-gray-800 dark:text-white/90">LAB vitals</h3>
                    @include('livewire.demo.partials.provenance-badge', ['label' => 'Lab / Lighthouse-style'])
                </div>
                <div class="space-y-3">
                    @foreach ($data['performance']['lab'] as $row)
                        <div class="flex items-center justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.02]">
                            <div>
                                <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['metric'] }}</p>
                                <p class="text-xs text-gray-400">Mobile {{ $row['mobile'] }} · Desktop {{ $row['desktop'] }}@if (! empty($row['page'])) · {{ $row['page'] }}@endif</p>
                            </div>
                            <x-ta.badge :color="$vitalColor($row['rating'])" size="sm">{{ str_replace('_', ' ', $row['rating']) }}</x-ta.badge>
                        </div>
                    @endforeach
                </div>
            </x-ta.card>
        </div>
        <ul class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
            @foreach ($data['performance']['notes'] as $note)
                <li>• {{ $note }}</li>
            @endforeach
        </ul>
    @endif

    @if ($tab === 'lifecycle')
        @include('livewire.demo.partials.section-question', [
            'question' => 'What is the domain / SSL / hosting continuity picture?',
            'hint' => 'Each fact carries Detected / Manual / Provider provenance.',
        ])
        <div class="grid gap-3 md:grid-cols-2">
            @foreach ($data['lifecycle'] as $row)
                <x-ta.card padding="p-4">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-xs uppercase tracking-wide text-gray-400">{{ $row['label'] }}</p>
                        @include('livewire.demo.partials.provenance-badge', ['label' => $row['provenance']])
                    </div>
                    <p class="mt-1 font-semibold text-gray-800 dark:text-white/90">{{ $row['value'] }}</p>
                </x-ta.card>
            @endforeach
        </div>
        <div class="flex flex-wrap gap-2">
            <x-ta.button href="{{ route('demo.domain') }}" size="sm" variant="outline">Open Domain workspace</x-ta.button>
            <x-ta.button href="{{ route('demo.hosting') }}" size="sm" variant="outline">Open Hosting workspace</x-ta.button>
        </div>
    @endif

    @if ($tab === 'insights')
        @include('livewire.demo.partials.section-question', [
            'question' => 'What themes should guide next work?',
            'hint' => 'Demo Mode themed cards — no live model call.',
        ])
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($data['insights'] as $insight)
                <x-ta.card>
                    <x-ta.badge color="light" size="sm">{{ $insight['theme'] }}</x-ta.badge>
                    <h3 class="mt-2 font-semibold text-gray-800 dark:text-white/90">{{ $insight['title'] }}</h3>
                    <p class="mt-2 text-sm text-gray-500">{{ $insight['body'] }}</p>
                    <p class="mt-3 text-sm font-medium text-gray-700 dark:text-gray-300">{{ $insight['action'] }}</p>
                </x-ta.card>
            @endforeach
        </div>
    @endif
</div>
