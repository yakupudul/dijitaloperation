<div class="space-y-4">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-col gap-2 border-b border-gray-200 pb-4 dark:border-gray-800 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Meta Ads</p>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('operator.meta_ads.tabs.campaigns') }}</h1>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Campaign Context + typed results</p>
        </div>
        <a href="{{ route('operator.meta.overview', ['assetId' => $assetId, 'tab' => 'campaigns']) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open full workspace →</a>
    </div>

    @include('livewire.demo.partials.meta-asset-nav', ['assetId' => $assetId, 'active' => 'campaigns'])
    @include('livewire.demo.partials.period-bar')

    <div class="flex flex-wrap items-center gap-3">
        <div class="flex flex-wrap gap-2">
            <span class="self-center text-xs uppercase tracking-wide text-gray-400">Status</span>
            @foreach (['all' => 'All', 'ACTIVE' => 'Active', 'PAUSED' => 'Paused'] as $key => $label)
                <button type="button" wire:click="setStatusFilter('{{ $key }}')"
                    @class([
                        'rounded-md px-2.5 py-1.5 text-xs font-medium transition',
                        'bg-brand-500 text-white' => $statusFilter === $key,
                        'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $statusFilter !== $key,
                    ])>{{ $label }}</button>
            @endforeach
        </div>
        <div class="flex flex-wrap gap-2">
            <span class="self-center text-xs uppercase tracking-wide text-gray-400">Objective</span>
            @foreach ([
                'all' => 'All',
                'LEADS' => 'Leads',
                'MESSAGING' => 'Messaging',
                'AWARENESS' => 'Awareness',
            ] as $key => $label)
                <button type="button" wire:click="setObjectiveFilter('{{ $key }}')"
                    @class([
                        'rounded-md px-2.5 py-1.5 text-xs font-medium transition',
                        'bg-brand-500 text-white' => $objectiveFilter === $key,
                        'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $objectiveFilter !== $key,
                    ])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <x-ta.table>
        <x-slot:head>
            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Campaign</th>
            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Status</th>
            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Destination</th>
            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Spend</th>
            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Result</th>
            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400">Cost / result</th>
            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-400"></th>
        </x-slot:head>
        @foreach ($campaigns as $c)
            <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                <td class="px-3 py-2">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $c['name'] }}</p>
                    <p class="text-[11px] text-gray-400">{{ $c['offering'] }} · {{ $c['market'] }}</p>
                </td>
                <td class="px-3 py-2"><x-ta.badge :color="$c['status'] === 'ACTIVE' ? 'success' : 'light'" size="sm">{{ $c['status'] }}</x-ta.badge></td>
                <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-300">{{ $c['destination'] }}</td>
                <td class="px-3 py-2 text-sm tabular-nums">₺{{ number_format($c['spend']) }}</td>
                <td class="px-3 py-2 text-sm tabular-nums">{{ number_format($c['results']) }} <span class="text-[11px] text-gray-400">{{ $c['result_label'] }}</span></td>
                <td class="px-3 py-2 text-sm tabular-nums">₺{{ number_format($c['cost_result'] ?? 0) }}</td>
                <td class="px-3 py-2">
                    <a href="{{ route('operator.meta.campaign', ['assetId' => $assetId, 'campaignId' => $c['id']]) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open</a>
                </td>
            </tr>
        @endforeach
    </x-ta.table>
</div>
