<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Agency Operations OS',
        'title' => 'Command Center',
        'subtitle' => 'What needs attention across the portfolio — action first, counts second.',
        'actions' => view('livewire.demo.partials._dashboard-actions')->render(),
    ])

    <div>
        @include('livewire.demo.partials.section-question', [
            'question' => 'What needs attention today?',
            'hint' => 'Critical findings, due-soon work, sync gaps, and renewals — ranked for agency operators.',
        ])
        @include('livewire.demo.partials.attention-list', ['items' => $dashboard['needs_attention']])
    </div>

    <div>
        @include('livewire.demo.partials.section-question', [
            'question' => 'Which brands need you first?',
            'hint' => 'Ranked by urgency · per-channel mini status.',
        ])
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach (collect($dashboard['brand_cards'])->sortBy('urgency') as $card)
                <x-ta.card>
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Brand</p>
                            <h3 class="mt-0.5 text-lg font-semibold text-gray-800 dark:text-white/90">{{ $card['name'] }}</h3>
                        </div>
                        <x-ta.badge :color="($card['health'] ?? '') === 'needs_attention' ? 'warning' : 'success'" size="sm">
                            {{ $card['health_label'] }}
                        </x-ta.badge>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-1.5">
                        @foreach ($card['channels'] ?? [] as $channel)
                            @php
                                $chColor = match ($channel['status'] ?? '') {
                                    'needs_attention' => 'warning',
                                    'healthy' => 'success',
                                    default => 'light',
                                };
                            @endphp
                            <x-ta.badge :color="$chColor" size="sm">
                                {{ $channel['label'] }} · {{ $channel['status_label'] }}
                            </x-ta.badge>
                        @endforeach
                    </div>

                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-gray-400">Media spend</dt>
                            <dd class="font-semibold text-gray-800 dark:text-white/90">₺{{ number_format($card['media_spend'] ?? 0) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-400">Platform leads</dt>
                            <dd class="font-semibold text-gray-800 dark:text-white/90">{{ number_format($card['platform_leads'] ?? 0) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-400">Website leads</dt>
                            <dd class="font-semibold text-gray-800 dark:text-white/90">{{ number_format($card['website_leads'] ?? 0) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-400">Open work</dt>
                            <dd class="font-semibold text-gray-800 dark:text-white/90">{{ ($card['open_findings'] ?? 0) }} findings · {{ ($card['open_tasks'] ?? 0) }} tasks</dd>
                        </div>
                    </dl>

                    <div class="mt-4">
                        <x-ta.button :href="route($card['route'], $card['route_params'] ?? [])" size="sm">Open brand</x-ta.button>
                    </div>
                </x-ta.card>
            @endforeach
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-ta.card>
            @include('livewire.demo.partials.section-question', [
                'question' => 'Recent movements',
                'hint' => 'Direction of key signals vs prior window.',
            ])
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($dashboard['movements'] as $move)
                    @php
                        $toneColor = match ($move['tone'] ?? 'neutral') {
                            'bad' => 'error',
                            'warn' => 'warning',
                            'good' => 'success',
                            default => 'light',
                        };
                    @endphp
                    <li class="flex items-start justify-between gap-3 py-3">
                        <div>
                            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $move['label'] }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $move['detail'] }}</p>
                        </div>
                        <div class="text-right">
                            <x-ta.badge :color="$toneColor" size="sm">{{ $move['value'] }}</x-ta.badge>
                            <div class="mt-2">
                                <x-ta.button :href="route($move['route'])" size="sm" variant="outline">Inspect</x-ta.button>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-ta.card>

        <x-ta.card>
            @include('livewire.demo.partials.section-question', [
                'question' => 'Upcoming renewals & deadlines',
                'hint' => 'Lifecycle and due-soon operator work.',
            ])
            <ul class="space-y-3">
                @foreach ($dashboard['upcoming'] as $item)
                    <li class="flex items-center justify-between gap-3 rounded-xl bg-gray-50 px-4 py-3 dark:bg-white/[0.02]">
                        <div>
                            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $item['label'] }}</p>
                            <p class="text-xs text-gray-500">{{ $item['detail'] }} · {{ $item['when'] }}</p>
                        </div>
                        <x-ta.button :href="route($item['route'], $item['route_params'] ?? [])" size="sm" variant="outline">Open</x-ta.button>
                    </li>
                @endforeach
            </ul>
        </x-ta.card>
    </div>

    <x-ta.card>
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            @include('livewire.demo.partials.section-question', [
                'question' => 'Recent operations',
                'hint' => 'Imports, scans, and analysis runs.',
            ])
            <x-ta.button href="{{ route('demo.activity') }}" size="sm" variant="outline">Activity center</x-ta.button>
        </div>
        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            @foreach (collect($dashboard['recent_operations'])->take(6) as $row)
                <li class="flex items-start justify-between gap-3 py-3">
                    <div>
                        <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['title'] }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $row['detail'] }}</p>
                    </div>
                    <div class="text-right">
                        <x-ta.badge :color="match($row['status']) { 'completed' => 'success', 'running' => 'info', 'partial' => 'warning', default => 'light' }" size="sm">
                            {{ $row['status'] }}
                        </x-ta.badge>
                        <p class="mt-1 text-xs text-gray-400">{{ $row['when'] }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </x-ta.card>

    <div>
        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-400">Portfolio counts (secondary)</p>
        <div class="flex flex-wrap gap-2">
            @foreach ($dashboard['secondary_counts'] ?? [] as $count)
                <a href="{{ route($count['route']) }}"
                    class="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    <span class="font-semibold text-gray-800 dark:text-white/90">{{ $count['value'] }}</span>
                    <span>{{ $count['label'] }}</span>
                </a>
            @endforeach
        </div>
    </div>
</div>
