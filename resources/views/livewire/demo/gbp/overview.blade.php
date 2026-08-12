@php
    $tabs = [
        'overview' => 'Overview',
        'visibility' => 'Visibility map',
        'reviews' => 'Reviews',
        'queries' => 'Queries',
        'competitors' => 'Competitors',
    ];
    $rankColor = function (int $rank): string {
        return match (true) {
            $rank <= 3 => 'bg-success-500 text-white',
            $rank <= 7 => 'bg-warning-400 text-gray-900',
            $rank <= 12 => 'bg-orange-400 text-gray-900',
            default => 'bg-error-500 text-white',
        };
    };
@endphp
<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-gray-500">Google Business Profile · {{ $asset['name'] ?? 'Atlas Dental Ankara' }}</p>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">Workspace</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $data['period_label'] }} · Connected + external search intelligence · Demo Mode</p>
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
        <x-ta.alert variant="warning" title="Local visibility" :message="'“'.$data['map']['keyword'].'” average rank '.$data['map']['average_rank'].' (was '.$data['map']['previous_average'].').'" />
        <x-ta.button wire:click="setTab('visibility')" size="sm">Open visibility map</x-ta.button>
    @endif

    @if ($tab === 'visibility')
        <x-ta.card>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="font-semibold text-gray-800 dark:text-white/90">Map grid · {{ $data['map']['keyword'] }}</h2>
                    <p class="text-sm text-gray-500">Avg rank {{ $data['map']['average_rank'] }} · Top 3 {{ $data['map']['top3'] }}% · Top 10 {{ $data['map']['top10'] }}% · {{ $data['map']['provenance'] }}</p>
                </div>
                @include('livewire.demo.partials.demo-badge', ['label' => 'External search intelligence'])
            </div>
            <div class="inline-grid gap-2" style="grid-template-columns: repeat(5, minmax(0, 3.5rem));">
                @foreach ($data['map']['grid'] as $row)
                    @foreach ($row as $rank)
                        <div class="flex h-14 w-14 items-center justify-center rounded-lg text-sm font-bold {{ $rankColor((int) $rank) }}">
                            {{ $rank }}
                        </div>
                    @endforeach
                @endforeach
            </div>
            <p class="mt-4 text-xs text-gray-400">Lower numbers = better local pack rank. North-east cells show the demo deterioration.</p>
        </x-ta.card>
    @endif

    @if ($tab === 'reviews')
        <div class="grid gap-4 lg:grid-cols-2">
            <x-ta.card>
                <h2 class="font-semibold text-gray-800 dark:text-white/90">Distribution</h2>
                <ul class="mt-3 space-y-2">
                    @foreach ($data['reviews']['distribution'] as $stars => $count)
                        <li>
                            <x-ta.progress-bar :value="$count" :max="920" :label="$stars.'★ · '.$count" />
                        </li>
                    @endforeach
                </ul>
            </x-ta.card>
            <x-ta.card>
                <h2 class="font-semibold text-gray-800 dark:text-white/90">AI review summary</h2>
                <p class="mt-1 text-xs text-gray-400">Demo Mode — no live model call</p>
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ $data['reviews']['ai_summary'] }}</p>
                <p class="mt-3 text-sm"><span class="text-gray-400">Positive:</span> {{ implode(', ', $data['reviews']['themes_positive']) }}</p>
                <p class="text-sm"><span class="text-gray-400">Negative:</span> {{ implode(', ', $data['reviews']['themes_negative']) }}</p>
                <ul class="mt-4 space-y-2">
                    @foreach ($data['reviews']['recent'] as $review)
                        <li class="rounded-xl bg-gray-50 px-3 py-2 text-sm dark:bg-white/[0.02]">
                            {{ $review['rating'] }}★ — {{ $review['text'] }}
                            <span class="text-xs text-gray-400"> · {{ $review['when'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-ta.card>
        </div>
    @endif

    @if ($tab === 'queries')
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Query</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Visibility</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Map rank</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Trend</th>
            </x-slot:head>
            @foreach ($data['queries'] as $row)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['query'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['visibility'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['map_rank'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['trend'] }}</td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif

    @if ($tab === 'competitors')
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($data['competitors'] as $competitor)
                <x-ta.card>
                    <h3 class="font-semibold text-gray-800 dark:text-white/90">{{ $competitor['name'] }}</h3>
                    <p class="mt-2 text-sm text-gray-500">{{ $competitor['rating'] }}★ · {{ number_format($competitor['reviews']) }} reviews</p>
                </x-ta.card>
            @endforeach
        </div>
    @endif
</div>