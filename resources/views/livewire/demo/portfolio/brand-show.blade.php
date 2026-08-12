@php
    $tabs = [
        'overview' => 'Overview',
        'assets' => 'Assets',
        'findings' => 'Findings',
        'recommendations' => 'Recommendations',
        'tasks' => 'Tasks',
        'history' => 'History',
    ];
@endphp

<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <x-ta.button href="{{ route('demo.brands') }}" size="sm" variant="outline">← Brands</x-ta.button>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">{{ $brandRow['name'] }}</h1>
                <x-ta.badge color="warning" size="sm">{{ $brandRow['health_label'] ?? 'Needs attention' }}</x-ta.badge>
                @include('livewire.demo.partials.demo-badge')
            </div>
            <p class="mt-1 text-sm text-gray-500">{{ $brandRow['industry'] ?? '' }} · {{ $brandRow['location'] ?? '' }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-ta.button wire:click="runPublicResearch" size="sm" variant="outline">Run public research</x-ta.button>
            <x-ta.button wire:click="runAiBrief" size="sm">Generate AI brief</x-ta.button>
        </div>
    </div>

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
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-400">Needs attention</h2>
                @foreach ($attention as $item)
                    <x-ta.card>
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <x-ta.badge :color="match($item['severity']) { 'high' => 'error', 'medium' => 'warning', default => 'info' }" size="sm">{{ $item['severity'] }}</x-ta.badge>
                                <h3 class="mt-2 font-semibold text-gray-800 dark:text-white/90">{{ $item['asset'] }} — {{ $item['issue'] }}</h3>
                                <p class="mt-1 text-sm text-gray-500">{{ $item['evidence'] }}</p>
                            </div>
                            <x-ta.button :href="route($item['route'])" size="sm" variant="outline">Inspect</x-ta.button>
                        </div>
                    </x-ta.card>
                @endforeach
            </div>
            <div class="space-y-4">
                <x-ta.card>
                    <h3 class="font-semibold text-gray-800 dark:text-white/90">Summary</h3>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-gray-400">Media spend</dt><dd class="font-medium">₺{{ number_format($brandRow['summary']['media_spend'] ?? 0) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-400">Platform leads</dt><dd class="font-medium">{{ number_format($brandRow['summary']['platform_leads'] ?? 0) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-400">Website leads</dt><dd class="font-medium">{{ number_format($brandRow['summary']['website_leads'] ?? 0) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-400">Calls / messages</dt><dd class="font-medium">{{ number_format($brandRow['summary']['calls_messages'] ?? 0) }}</dd></div>
                    </dl>
                </x-ta.card>

                @if (! empty($research['completed']))
                    <x-ta.card>
                        <h3 class="font-semibold text-gray-800 dark:text-white/90">Public research</h3>
                        <p class="mt-1 text-xs text-gray-400">PUBLIC DISCOVERY provenance · Demo Mode</p>
                        <ul class="mt-3 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                            @foreach ($research['steps'] as $step)
                                <li>✓ {{ $step }}</li>
                            @endforeach
                        </ul>
                    </x-ta.card>
                @endif

                @if ($aiBrief)
                    <x-ta.card>
                        <div class="flex items-center gap-2">
                            <h3 class="font-semibold text-gray-800 dark:text-white/90">{{ $aiBrief['headline'] }}</h3>
                            @include('livewire.demo.partials.demo-badge', ['label' => 'AI simulated'])
                        </div>
                        <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                            @foreach ($aiBrief['points'] as $point)
                                <li>{{ $point }}</li>
                            @endforeach
                        </ul>
                        <p class="mt-3 text-xs text-gray-400">{{ $aiBrief['disclaimer'] }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($aiBrief['evidence_links'] as $link)
                                <x-ta.button :href="route($link['route'])" size="sm" variant="outline">{{ $link['label'] }}</x-ta.button>
                            @endforeach
                        </div>
                    </x-ta.card>
                @endif
            </div>
        </div>
    @endif

    @if ($tab === 'assets')
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Asset</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Type</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Provenance</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Health</th>
                <th class="px-5 py-3"></th>
            </x-slot:head>
            @foreach ($assets as $asset)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $asset['name'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $asset['type_label'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $asset['provenance'] }}</td>
                    <td class="px-5 py-4"><x-ta.badge :color="($asset['health'] ?? '') === 'healthy' ? 'success' : 'warning'" size="sm">{{ $asset['health_label'] }}</x-ta.badge></td>
                    <td class="px-5 py-4 text-right"><x-ta.button :href="route($asset['route'])" size="sm" variant="outline">Open</x-ta.button></td>
                </tr>
            @endforeach
        </x-ta.table>
    @endif

    @if ($tab === 'findings')
        <div class="space-y-3">
            @foreach ($findings as $finding)
                <x-ta.card>
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <x-ta.badge :color="match($finding['severity']) { 'high' => 'error', 'medium' => 'warning', default => 'info' }" size="sm">{{ $finding['severity'] }}</x-ta.badge>
                            <h3 class="mt-2 font-semibold text-gray-800 dark:text-white/90">{{ $finding['title'] }}</h3>
                            <p class="text-sm text-gray-500">{{ $finding['evidence'] }}</p>
                        </div>
                        <x-ta.button href="{{ route('demo.findings') }}" size="sm" variant="outline">All findings</x-ta.button>
                    </div>
                </x-ta.card>
            @endforeach
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
                        <x-ta.button href="{{ route('demo.recommendations') }}" size="sm">Review</x-ta.button>
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
            <h2 class="mb-4 font-semibold text-gray-800 dark:text-white/90">Decision timeline</h2>
            <ol class="relative space-y-4 border-l border-gray-200 pl-6 dark:border-gray-800">
                @foreach ($timeline as $event)
                    <li>
                        <span class="absolute -left-1.5 mt-1.5 h-3 w-3 rounded-full bg-brand-500"></span>
                        <p class="text-xs text-gray-400">{{ $event['date'] }}</p>
                        <p class="font-medium text-gray-800 dark:text-white/90">{{ $event['event'] }}</p>
                        <p class="text-sm text-gray-500">{{ $event['detail'] }}</p>
                    </li>
                @endforeach
            </ol>
        </x-ta.card>
    @endif
</div>
