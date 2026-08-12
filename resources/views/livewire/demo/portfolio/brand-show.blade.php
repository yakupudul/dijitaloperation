@php
    $tabs = [
        'overview' => 'Overview',
        'assets' => 'Assets',
        'findings' => 'Findings',
        'recommendations' => 'Recommendations',
        'tasks' => 'Tasks',
        'history' => 'History',
        'research' => 'Research',
        'ai' => 'AI',
    ];
@endphp

<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => trim(($brandRow['industry'] ?? '').' · '.($brandRow['location'] ?? ''), ' ·'),
        'title' => $brandRow['name'],
        'subtitle' => 'Brand home — assets, attention, decisions, and analysis in one place.',
        'badges' => '<span class="inline-flex"><span class="inline-flex items-center rounded-full bg-warning-50 px-2.5 py-0.5 text-xs font-medium text-warning-700 dark:bg-warning-500/15 dark:text-warning-400">'.e($brandRow['health_label'] ?? 'Needs attention').'</span></span>',
        'actions' => view('livewire.demo.partials._brand-show-actions', ['brandId' => $brandRow['id']])->render(),
    ])

    <div class="flex flex-wrap gap-2 border-b border-gray-200 pb-3 dark:border-gray-800">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')"
                @class([
                    'rounded-lg px-3 py-2 text-sm font-medium transition',
                    'bg-brand-500 text-white' => $tab === $key,
                    'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/[0.03]' => $tab !== $key,
                ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'overview')
        <div class="space-y-6">
            <div>
                @include('livewire.demo.partials.section-question', [
                    'question' => 'Asset status',
                    'hint' => 'Taxonomy roles · primary managed, connected sources, infrastructure.',
                ])
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ($assets as $asset)
                        <a href="{{ route($asset['route']) }}" class="block rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-800 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="text-xs text-gray-400">{{ $asset['role_label'] ?? 'Asset' }}</p>
                                    <p class="mt-1 text-sm font-semibold text-gray-800 dark:text-white/90">{{ $asset['type_label'] }}</p>
                                </div>
                                <x-ta.badge :color="match($asset['health'] ?? '') { 'healthy' => 'success', 'needs_attention' => 'warning', 'warning' => 'warning', default => 'info' }" size="sm">
                                    {{ $asset['health_label'] }}
                                </x-ta.badge>
                            </div>
                            <p class="mt-2 truncate text-xs text-gray-500">{{ $asset['name'] }}</p>
                        </a>
                    @endforeach
                </div>
            </div>

            <div>
                @include('livewire.demo.partials.section-question', [
                    'question' => 'Cross-channel summary',
                    'hint' => 'Platform-attributed and site signals for the selected period context.',
                ])
                @include('livewire.demo.partials.kpi-strip', ['kpis' => $summaryKpis, 'primaryCount' => 4])
            </div>

            <div>
                @include('livewire.demo.partials.section-question', [
                    'question' => 'What needs attention on this brand?',
                    'hint' => 'Highest-leverage issues first.',
                ])
                @include('livewire.demo.partials.attention-list', ['items' => $attention])
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <x-ta.card>
                    @include('livewire.demo.partials.section-question', [
                        'question' => 'Priorities',
                        'hint' => 'Suggested next moves from current findings.',
                    ])
                    <ol class="mt-1 list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                        @foreach (array_slice($priorities, 0, 4) as $priority)
                            <li>{{ $priority }}</li>
                        @endforeach
                    </ol>
                    <div class="mt-4">
                        <x-ta.button wire:click="runAiBrief" size="sm" variant="outline">Open AI analysis</x-ta.button>
                    </div>
                </x-ta.card>

                <x-ta.card>
                    @include('livewire.demo.partials.section-question', [
                        'question' => 'Open tasks',
                        'hint' => 'Work still in flight for this brand.',
                    ])
                    @if (count($openTasks) === 0)
                        @include('livewire.demo.partials.empty-panel', [
                            'title' => 'No open tasks',
                            'message' => 'All brand tasks are complete.',
                        ])
                    @else
                        <ul class="space-y-3">
                            @foreach (collect($openTasks)->take(5) as $task)
                                <li class="flex items-center justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $task['title'] }}</p>
                                        <p class="text-xs text-gray-500">{{ $task['owner'] }} · due {{ $task['due'] }} · {{ $task['status'] }}</p>
                                    </div>
                                    <x-ta.button :href="route('demo.task', ['taskId' => $task['id']])" size="sm" variant="outline">Open</x-ta.button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ta.card>
            </div>

            <div>
                @include('livewire.demo.partials.section-question', [
                    'question' => 'Lifecycle',
                    'hint' => 'Domain and hosting reminders.',
                ])
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($lifecycleAssets as $life)
                        <div class="flex items-center justify-between rounded-xl bg-gray-50 px-4 py-3 dark:bg-white/[0.02]">
                            <div>
                                <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $life['type_label'] }} · {{ $life['name'] }}</p>
                                <p class="text-xs text-gray-500">{{ $life['detail'] ?? $life['health_label'] }}</p>
                            </div>
                            <x-ta.badge :color="($life['health'] ?? '') === 'warning' ? 'warning' : 'success'" size="sm">
                                {{ $life['health_label'] }}
                            </x-ta.badge>
                        </div>
                    @endforeach
                </div>
            </div>

            <x-ta.card>
                <div class="mb-3 flex items-center justify-between gap-2">
                    @include('livewire.demo.partials.section-question', [
                        'question' => 'Decision history',
                        'hint' => 'Recent finding → recommendation → task loop.',
                    ])
                    <x-ta.button wire:click="setTab('history')" size="sm" variant="outline">Full history</x-ta.button>
                </div>
                @include('livewire.demo.partials.decision-timeline', [
                    'events' => $timeline,
                    'title' => '',
                    'limit' => 4,
                ])
            </x-ta.card>
        </div>
    @endif

    @if ($tab === 'assets')
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Asset</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Type</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Role</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Provenance</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Health</th>
                <th class="px-5 py-3"></th>
            </x-slot:head>
            @foreach ($assets as $asset)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $asset['name'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $asset['type_label'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $asset['role_label'] ?? '—' }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $asset['provenance'] }}</td>
                    <td class="px-5 py-4"><x-ta.badge :color="($asset['health'] ?? '') === 'healthy' ? 'success' : 'warning'" size="sm">{{ $asset['health_label'] }}</x-ta.badge></td>
                    <td class="px-5 py-4 text-right"><x-ta.button :href="route($asset['route'])" size="sm" variant="outline">Open</x-ta.button></td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif

    @if ($tab === 'findings')
        <div class="space-y-3">
            @forelse ($findings as $finding)
                <x-ta.card>
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <x-ta.badge :color="match($finding['severity']) { 'critical', 'high' => 'error', 'medium' => 'warning', default => 'info' }" size="sm">{{ $finding['severity'] }}</x-ta.badge>
                            <h3 class="mt-2 font-semibold text-gray-800 dark:text-white/90">{{ $finding['title'] }}</h3>
                            <p class="text-sm text-gray-500">{{ $finding['evidence'] }}</p>
                        </div>
                        <x-ta.button href="{{ route('demo.findings') }}" size="sm" variant="outline">All findings</x-ta.button>
                    </div>
                </x-ta.card>
            @empty
                @include('livewire.demo.partials.empty-panel', [
                    'title' => 'No findings',
                    'message' => 'No open findings for this brand.',
                ])
            @endforelse
        </div>
    @endif

    @if ($tab === 'recommendations')
        <div class="space-y-3">
            @foreach ($recommendations as $rec)
                <x-ta.card>
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-gray-800 dark:text-white/90">{{ $rec['title'] }}</h3>
                            <p class="mt-1 text-sm text-gray-500">{{ $rec['observation'] }}</p>
                            <x-ta.badge class="mt-2" :color="match($rec['status']) { 'approved' => 'success', 'rejected' => 'error', default => 'warning' }" size="sm">{{ $rec['status'] }}</x-ta.badge>
                        </div>
                        <div class="flex flex-col gap-2">
                            <x-ta.button href="{{ route('demo.recommendations') }}" size="sm" variant="outline">Review</x-ta.button>
                            @if (($rec['status'] ?? '') === 'pending')
                                <x-ta.button wire:click="createTaskFromRecommendation('{{ $rec['id'] }}')" size="sm">Create task</x-ta.button>
                            @endif
                        </div>
                    </div>
                </x-ta.card>
            @endforeach
        </div>
    @endif

    @if ($tab === 'tasks')
        <div class="space-y-3">
            @foreach ($tasks as $task)
                <x-ta.card>
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-gray-800 dark:text-white/90">{{ $task['title'] }}</h3>
                            <p class="text-sm text-gray-500">{{ $task['owner'] }} · due {{ $task['due'] }} · {{ $task['status'] }}</p>
                        </div>
                        <x-ta.button :href="route('demo.task', ['taskId' => $task['id']])" size="sm">Open</x-ta.button>
                    </div>
                </x-ta.card>
            @endforeach
        </div>
    @endif

    @if ($tab === 'history')
        <x-ta.card>
            @include('livewire.demo.partials.decision-timeline', ['events' => $timeline])
        </x-ta.card>
    @endif

    @if ($tab === 'research')
        <div class="space-y-4">
            @include('livewire.demo.partials.section-question', [
                'question' => 'Public Discovery',
                'hint' => 'Read-only public signals — never treated as connected provider truth.',
            ])

            <div class="flex flex-wrap items-center gap-2">
                @include('livewire.demo.partials.provenance-badge', ['label' => 'PUBLIC DISCOVERY'])
                <x-ta.button wire:click="runPublicResearch" size="sm">
                    {{ ! empty($research['completed']) ? 'Re-run public research' : 'Start public research' }}
                </x-ta.button>
            </div>

            @if (empty($research['completed']))
                @include('livewire.demo.partials.empty-panel', [
                    'title' => 'No discovery run yet',
                    'message' => 'Start public research to populate discovery cards with PUBLIC DISCOVERY provenance.',
                ])
            @else
                <div class="grid gap-4 lg:grid-cols-2">
                    @foreach (($research['cards'] ?? []) as $step)
                        <x-ta.card>
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-semibold text-gray-800 dark:text-white/90">{{ $step['card']['title'] ?? $step['step'] }}</h3>
                                @include('livewire.demo.partials.provenance-badge', ['label' => $step['provenance'] ?? 'PUBLIC DISCOVERY'])
                            </div>
                            <p class="mt-1 text-xs text-gray-400">{{ $step['step'] }} · {{ $step['status'] ?? 'completed' }}</p>
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $step['card']['summary'] ?? '' }}</p>
                            @if (! empty($step['card']['signals']))
                                <div class="mt-3 flex flex-wrap gap-1.5">
                                    @foreach ($step['card']['signals'] as $signal)
                                        <x-ta.badge color="light" size="sm">{{ $signal }}</x-ta.badge>
                                    @endforeach
                                </div>
                            @endif
                        </x-ta.card>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    @if ($tab === 'ai')
        <div class="space-y-4">
            @include('livewire.demo.partials.section-question', [
                'question' => 'Brand AI analysis',
                'hint' => 'Structured guidance — demo simulation, no live model call, no external writes.',
            ])

            @if (! $aiBrief)
                <x-ta.card>
                    <p class="text-sm text-gray-600 dark:text-gray-300">Generate a structured brand analysis from current findings and channel signals.</p>
                    <div class="mt-4">
                        <x-ta.button wire:click="runAiBrief" size="sm">Generate analysis</x-ta.button>
                    </div>
                </x-ta.card>
            @else
                <x-ta.card>
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $aiBrief['headline'] }}</h3>
                        @include('livewire.demo.partials.provenance-badge', ['label' => 'AI simulated'])
                    </div>
                    @if (! empty($aiBrief['period_context']))
                        <p class="mt-2 text-sm text-gray-500">{{ $aiBrief['period_context'] }}</p>
                    @endif

                    <h4 class="mt-5 text-sm font-semibold text-gray-700 dark:text-gray-200">Key points</h4>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                        @foreach ($aiBrief['points'] as $point)
                            <li>{{ $point }}</li>
                        @endforeach
                    </ul>

                    <h4 class="mt-5 text-sm font-semibold text-gray-700 dark:text-gray-200">Priorities</h4>
                    <ul class="mt-2 space-y-3">
                        @foreach ($aiBrief['priorities'] as $index => $priority)
                            <li class="flex flex-wrap items-start justify-between gap-3 rounded-xl bg-gray-50 px-4 py-3 dark:bg-white/[0.02]">
                                <p class="text-sm text-gray-700 dark:text-gray-300">{{ $priority }}</p>
                                <x-ta.button wire:click="createRecommendationFromPriority({{ $index }})" size="sm" variant="outline">
                                    Create recommendation
                                </x-ta.button>
                            </li>
                        @endforeach
                    </ul>

                    <h4 class="mt-5 text-sm font-semibold text-gray-700 dark:text-gray-200">Risks</h4>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                        @foreach ($aiBrief['risks'] ?? [] as $risk)
                            <li>{{ $risk }}</li>
                        @endforeach
                    </ul>

                    <p class="mt-4 text-xs text-gray-400">{{ $aiBrief['disclaimer'] }}</p>

                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach ($aiBrief['evidence_links'] as $link)
                            <x-ta.button :href="route($link['route'])" size="sm" variant="outline">{{ $link['label'] }}</x-ta.button>
                        @endforeach
                        <x-ta.button wire:click="runAiBrief" size="sm" variant="outline">Refresh analysis</x-ta.button>
                    </div>
                </x-ta.card>
            @endif
        </div>
    @endif
</div>
