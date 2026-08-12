@php
    $tabs = [
        'overview' => 'Overview',
        'campaigns' => 'Campaigns',
        'keywords' => 'Keywords',
        'search_terms' => 'Search terms',
        'landing' => 'Landing pages',
    ];
@endphp
<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-gray-500">Google Ads · {{ $asset['name'] ?? 'Atlas Dental — Google Ads' }}</p>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">Workspace</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $data['period_label'] }} · Connected provider · Demo Mode</p>
        </div>
        @include('livewire.demo.partials.demo-badge')
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
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($data['kpis'] as $kpi)
                @include('livewire.demo.partials.kpi', ['kpi' => $kpi])
            @endforeach
        </div>
        @foreach ($data['attention'] as $item)
            <x-ta.alert variant="{{ $item['severity'] === 'high' ? 'error' : 'warning' }}" :title="$item['title']" :message="$item['body']" />
        @endforeach
    @endif

    @if ($tab === 'campaigns')
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Campaign</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Status</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Spend</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Conv.</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">CPA</th>
            </x-slot:head>
            @foreach ($data['campaigns'] as $campaign)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $campaign['name'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $campaign['status'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">₺{{ number_format($campaign['spend']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $campaign['conv'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">₺{{ number_format($campaign['cpa']) }}</td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif

    @if ($tab === 'keywords')
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Keyword</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Match</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Spend</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Clicks</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Conv.</th>
            </x-slot:head>
            @foreach ($data['keywords'] as $row)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['keyword'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['match'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">₺{{ number_format($row['spend']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['clicks'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['conv'] }}</td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif

    @if ($tab === 'search_terms')
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Search term</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Campaign</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Spend</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Conv.</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Relevance</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Action</th>
            </x-slot:head>
            @foreach ($data['search_terms'] as $row)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['term'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['campaign'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">₺{{ number_format($row['spend']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['conversions'] }}</td>
                    <td class="px-5 py-4"><x-ta.badge :color="match($row['relevance']) { 'high' => 'success', 'low' => 'error', default => 'warning' }" size="sm">{{ $row['relevance'] }}</x-ta.badge></td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['action'] }}</td>
                </tr>
            @endforeach
        </x-ta.table>
        <x-ta.button href="{{ route('demo.recommendations') }}" size="sm">Open related recommendation</x-ta.button>
    @endif

    @if ($tab === 'landing')
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">URL</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Sessions</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Conv rate</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Mobile LCP</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Note</th>
            </x-slot:head>
            @foreach ($data['landing_pages'] as $row)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['url'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ number_format($row['sessions']) }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['conv_rate'] }}%</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['mobile_lcp'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['note'] }}</td>
                </tr>
            @endforeach
        </x-ta.table>
        <x-ta.button href="{{ route('demo.website', ['tab' => 'technical']) }}" size="sm" variant="outline">Open website technical</x-ta.button>
    @endif
</div>