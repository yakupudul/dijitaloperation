@php
    $tabs = [
        'overview' => 'Overview',
        'technical' => 'Technical',
        'search' => 'Search',
        'pages' => 'Pages',
        'lifecycle' => 'Lifecycle',
    ];
@endphp
<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-gray-500">Website · {{ $asset['name'] ?? 'atlasdental.example' }}</p>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">Workspace</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $data['period_label'] }} · Public + Detected · Demo Mode</p>
        </div>
        <div class="flex gap-2">
            <x-ta.button href="{{ route('demo.search-console') }}" size="sm" variant="outline">Search Console</x-ta.button>
            <x-ta.button href="{{ route('demo.analytics') }}" size="sm" variant="outline">Analytics</x-ta.button>
        </div>
    </div>

    @include('livewire.demo.partials.period-bar')

    <div class="flex flex-wrap gap-2 border-b border-gray-200 pb-3 dark:border-gray-800">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')"
                @class([
                    'rounded-lg px-3 py-2 text-sm font-medium',
                    'bg-brand-500 text-white' => $tab === $key,
                    'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/[0.03]' => $tab !== $key,
                ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'overview')
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($data['kpis'] as $kpi)
                @include('livewire.demo.partials.kpi', ['kpi' => $kpi])
            @endforeach
        </div>
    @endif

    @if ($tab === 'technical')
        <div class="space-y-3">
            @foreach ($data['technical'] as $item)
                <x-ta.card>
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <x-ta.badge :color="match($item['severity']) { 'high' => 'error', 'medium' => 'warning', default => 'info' }" size="sm">{{ $item['severity'] }}</x-ta.badge>
                            <h3 class="mt-2 font-semibold text-gray-800 dark:text-white/90">{{ $item['title'] }}</h3>
                            <p class="text-sm text-gray-500">{{ $item['detail'] }}</p>
                        </div>
                        <x-ta.button href="{{ route('demo.findings') }}" size="sm" variant="outline">Findings</x-ta.button>
                    </div>
                </x-ta.card>
            @endforeach
        </div>
    @endif

    @if ($tab === 'search')
        <x-ta.alert variant="info" title="Search presence" message="Organic clicks and indexing signals are mirrored from Search Console demo data." />
        <x-ta.button href="{{ route('demo.search-console') }}" size="sm">Open Search Console workspace</x-ta.button>
    @endif

    @if ($tab === 'pages')
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

    @if ($tab === 'lifecycle')
        <div class="grid gap-3 md:grid-cols-2">
            @foreach ($data['lifecycle'] as $row)
                <x-ta.card padding="p-4">
                    <p class="text-xs uppercase tracking-wide text-gray-400">{{ $row['label'] }}</p>
                    <p class="mt-1 font-semibold text-gray-800 dark:text-white/90">{{ $row['value'] }}</p>
                    <p class="mt-1 text-xs text-gray-400">{{ $row['provenance'] }}</p>
                </x-ta.card>
            @endforeach
        </div>
    @endif
</div>