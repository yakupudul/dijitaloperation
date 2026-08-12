@php
    $navTabs = [
        ['key' => 'overview', 'label' => 'Overview', 'wire' => true],
        ['key' => 'performance', 'label' => 'Performance', 'wire' => true],
        ['key' => 'visibility', 'label' => 'Visibility', 'wire' => true],
        ['key' => 'queries', 'label' => 'Queries', 'wire' => true],
        ['key' => 'reviews', 'label' => 'Reviews', 'wire' => true],
        ['key' => 'profile', 'label' => 'Profile', 'wire' => true],
        ['key' => 'competitors', 'label' => 'Competitors', 'wire' => true],
        ['key' => 'insights', 'label' => 'Insights', 'wire' => true],
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

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Google Business Profile · '.($asset['name'] ?? 'Atlas Dental Ankara'),
        'title' => 'Workspace',
        'subtitle' => ($data['period_label'] ?? '').' · Local presence · Connected + external rank',
        'badges' => ['Connected provider'],
    ])

    @include('livewire.demo.partials.gbp-asset-nav', ['tabs' => $navTabs, 'active' => $tab])

    @if ($showPeriodBar)
        @include('livewire.demo.partials.period-bar')
    @endif

    @if (in_array($tab, ['visibility', 'queries'], true))
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-500">Keyword</label>
                <select wire:model.live="keyword" class="rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700">
                    @foreach ($keywords as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    @endif

    @if ($tab === 'overview')
        @include('livewire.demo.partials.kpi-strip', ['kpis' => $data['kpis'], 'primaryCount' => 4])

        @include('livewire.demo.partials.section-question', [
            'question' => 'What needs a decision on this local profile?',
        ])
        @include('livewire.demo.partials.attention-list', ['items' => $attention])

        <x-ta.alert
            variant="warning"
            title="Local visibility"
            :message="'“'.$map['keyword'].'” average rank '.$map['average_rank'].' (was '.$map['previous_average'].').'"
        />
        <x-ta.button wire:click="setTab('visibility')" size="sm">Open visibility map</x-ta.button>
    @endif

    @if ($tab === 'performance')
        @include('livewire.demo.partials.kpi-strip', [
            'kpis' => collect($data['kpis'])->whereIn('label', ['Calls', 'Website Clicks', 'Directions', 'Profile Views'])->values()->all(),
            'primaryCount' => 4,
        ])
        @include('livewire.demo.partials.section-question', [
            'question' => 'How are profile interactions trending?',
            'hint' => 'Calls + website clicks + directions combined proxy for the selected period.',
        ])
        <x-ta.chart-card title="Interaction trend" :options="$interactionChartOptions" />
    @endif

    @if ($tab === 'visibility')
        <x-ta.card>
            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    @include('livewire.demo.partials.section-question', [
                        'question' => 'Where does this location rank in the local pack grid?',
                        'hint' => 'Avg rank '.$map['average_rank'].' · Top 3 '.$map['top3'].'% · Top 10 '.$map['top10'].'%',
                    ])
                </div>
                @include('livewire.demo.partials.provenance-badge', ['label' => $map['provenance'] ?? 'External Local Rank Tracking'])
            </div>

            <div class="inline-grid gap-2" style="grid-template-columns: repeat(5, minmax(0, 3.5rem));">
                @foreach ($map['grid'] as $row)
                    @foreach ($row as $rank)
                        <div class="flex h-14 w-14 items-center justify-center rounded-lg text-sm font-bold {{ $rankColor((int) $rank) }}">
                            {{ $rank }}
                        </div>
                    @endforeach
                @endforeach
            </div>

            <div class="mt-4 flex flex-wrap gap-3 text-xs text-gray-500">
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded bg-success-500"></span> 1–3 Top</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded bg-warning-400"></span> 4–7 Strong</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded bg-orange-400"></span> 8–12 Watch</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded bg-error-500"></span> 13+ Weak</span>
            </div>
            <p class="mt-3 text-xs text-gray-400">Lower numbers = better local pack rank. Provenance: External Local Rank Tracking (not Google Business Profile API).</p>
        </x-ta.card>
    @endif

    @if ($tab === 'reviews')
        @include('livewire.demo.partials.section-question', [
            'question' => 'What do reviews say — and what is unanswered?',
        ])
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
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold text-gray-800 dark:text-white/90">AI review summary</h2>
                    @include('livewire.demo.partials.demo-badge', ['label' => 'Demo Mode'])
                </div>
                <p class="mt-2 text-xs text-gray-400">{{ $data['reviews']['disclaimer'] }}</p>
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ $data['reviews']['ai_summary'] }}</p>
                <div class="mt-4 grid gap-2 sm:grid-cols-2">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-400">Positive themes</p>
                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ implode(', ', $data['reviews']['themes_positive']) }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-400">Negative themes</p>
                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ implode(', ', $data['reviews']['themes_negative']) }}</p>
                    </div>
                </div>
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
        @include('livewire.demo.partials.section-question', [
            'question' => 'Which search queries drive local visibility?',
        ])
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Query</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Visibility</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Map rank</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Trend</th>
            </x-slot:head>
            @foreach ($queries as $row)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['query'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['visibility'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['map_rank'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $row['trend'] }}</td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif

    @if ($tab === 'profile')
        @include('livewire.demo.partials.section-question', [
            'question' => 'Is the public profile complete and consistent?',
        ])
        <div class="grid gap-3 md:grid-cols-2">
            @foreach ($data['profile'] as $row)
                <x-ta.card padding="p-4">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-xs uppercase tracking-wide text-gray-400">{{ $row['label'] }}</p>
                        @include('livewire.demo.partials.provenance-badge', ['label' => $row['provenance']])
                    </div>
                    <p class="mt-1 font-semibold text-gray-800 dark:text-white/90">{{ $row['value'] }}</p>
                </x-ta.card>
            @endforeach
        </div>
    @endif

    @if ($tab === 'competitors')
        @include('livewire.demo.partials.section-question', [
            'question' => 'How do nearby competitors compare on rating and pack rank?',
        ])
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($data['competitors'] as $competitor)
                <x-ta.card>
                    <h3 class="font-semibold text-gray-800 dark:text-white/90">{{ $competitor['name'] }}</h3>
                    <p class="mt-2 text-sm text-gray-500">{{ $competitor['rating'] }}★ · {{ number_format($competitor['reviews']) }} reviews</p>
                    <p class="mt-1 text-sm text-gray-500">Avg map rank {{ $competitor['map_rank'] ?? '—' }}</p>
                </x-ta.card>
            @endforeach
        </div>
    @endif

    @if ($tab === 'insights')
        @include('livewire.demo.partials.section-question', [
            'question' => 'What local themes should guide next work?',
            'hint' => 'Demo Mode themed cards — no live model call.',
        ])
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($data['insights'] as $insight)
                <x-ta.card>
                    <x-ta.badge color="light" size="sm">{{ $insight['theme'] }}</x-ta.badge>
                    <h3 class="mt-2 font-semibold text-gray-800 dark:text-white/90">{{ $insight['title'] }}</h3>
                    <p class="mt-2 text-sm text-gray-500">{{ $insight['body'] }}</p>
                </x-ta.card>
            @endforeach
        </div>
    @endif
</div>
