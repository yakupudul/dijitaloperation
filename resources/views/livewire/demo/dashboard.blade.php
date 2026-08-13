<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $dashboard['date_label'] }}</p>
            <h1 class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $dashboard['greeting'] }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $dashboard['subtitle'] }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Dashboard mode">
            <button type="button" wire:click="setMode('my_work')"
                @class([
                    'rounded-lg px-3 py-2 text-sm font-medium transition',
                    'bg-brand-500 text-white' => $mode === 'my_work',
                    'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $mode !== 'my_work',
                ])>My Work</button>
            <button type="button" wire:click="setMode('agency')"
                @class([
                    'rounded-lg px-3 py-2 text-sm font-medium transition',
                    'bg-brand-500 text-white' => $mode === 'agency',
                    'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $mode !== 'agency',
                ])>Agency</button>
            @include('livewire.demo.partials._dashboard-actions')
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        @foreach ($dashboard['glance'] as $metric)
            <a href="{{ route($metric['route']) }}" wire:navigate
                class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-900 dark:ring-gray-800 dark:hover:bg-white/[0.03]">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ $metric['label'] }}</p>
                <p @class([
                    'mt-2 text-3xl font-bold',
                    'text-error-600 dark:text-error-400' => ($metric['tone'] ?? '') === 'error',
                    'text-warning-600 dark:text-warning-400' => ($metric['tone'] ?? '') === 'warning',
                    'text-brand-600 dark:text-brand-400' => ($metric['tone'] ?? '') === 'info',
                    'text-gray-800 dark:text-white/90' => ! in_array($metric['tone'] ?? '', ['error', 'warning', 'info'], true),
                ])>{{ $metric['value'] }}</p>
            </a>
        @endforeach
    </div>

    <div class="grid gap-4 xl:grid-cols-5">
        <section class="space-y-3 xl:col-span-3" aria-labelledby="needs-attention-heading">
            <div class="flex items-center justify-between gap-2">
                <h2 id="needs-attention-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Needs your attention</h2>
            </div>
            <ul class="space-y-3">
                @foreach ($dashboard['needs_attention'] as $item)
                    @php
                        $badgeColor = match ($item['severity'] ?? '') {
                            'critical', 'high' => 'error',
                            'medium' => 'warning',
                            default => 'info',
                        };
                    @endphp
                    <li class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-ta.badge :color="$badgeColor" size="sm">{{ strtoupper($item['severity'] ?? '') }}</x-ta.badge>
                                    @if (! empty($item['asset_type']))
                                        <x-demo.digital-asset-mark :type="$item['asset_type']" size="sm" />
                                    @endif
                                    <span class="text-xs text-gray-400">{{ $item['source'] ?? '' }}</span>
                                </div>
                                <h3 class="mt-2 text-base font-semibold text-gray-800 dark:text-white/90">{{ $item['title'] }}</h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $item['evidence'] ?? '' }}</p>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $item['body'] ?? '' }}</p>
                            </div>
                            <x-ta.button :href="route($item['route'], $item['route_params'] ?? [])" size="sm" variant="outline">
                                {{ $item['action_label'] ?? 'Open' }}
                            </x-ta.button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="space-y-3 xl:col-span-2" aria-labelledby="my-work-heading">
            <div class="flex items-center justify-between gap-2">
                <h2 id="my-work-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">My work</h2>
                <x-ta.button href="{{ route('demo.tasks') }}" size="sm" variant="outline">View all tasks</x-ta.button>
            </div>
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                @foreach ([
                    'due_today' => 'Due today',
                    'overdue' => 'Overdue',
                    'blocked' => 'Blocked',
                    'awaiting_follow_up' => 'Awaiting follow-up',
                ] as $key => $label)
                    <div @class(['mt-4' => ! $loop->first])>
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ $label }}</p>
                        @php $rows = $dashboard['my_work'][$key] ?? []; @endphp
                        @if (count($rows) === 0)
                            <p class="mt-1 text-sm text-gray-500">None</p>
                        @else
                            <ul class="mt-2 space-y-2">
                                @foreach ($rows as $task)
                                    <li>
                                        <a href="{{ route('demo.task', ['taskId' => $task['id']]) }}" wire:navigate
                                            class="block rounded-lg bg-gray-50 px-3 py-2 text-sm hover:bg-gray-100 dark:bg-white/[0.03] dark:hover:bg-white/[0.06]">
                                            <span class="font-medium text-gray-800 dark:text-white/90">{{ $task['title'] }}</span>
                                            <span class="mt-0.5 block text-xs text-gray-500">{{ $task['brand'] ?? '' }} · {{ $task['due'] ?? '' }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" aria-labelledby="portfolio-attention-heading">
            <h2 id="portfolio-attention-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Portfolio attention</h2>
            <ul class="mt-3 space-y-3">
                @foreach ($dashboard['portfolio_attention'] as $row)
                    <li class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-semibold text-gray-800 dark:text-white/90">{{ $row['brand'] }}</p>
                                <p class="text-xs text-gray-500">{{ $row['customer'] }}</p>
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                                    {{ $row['high_findings'] }} High Findings · {{ $row['open_tasks'] }} Open Tasks · {{ $row['awaiting_decision'] }} awaiting decision
                                </p>
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    @foreach ($row['asset_types'] as $type)
                                        <x-demo.digital-asset-mark :type="$type" size="sm" />
                                    @endforeach
                                </div>
                            </div>
                            <x-ta.button :href="route($row['route'], $row['route_params'] ?? [])" size="sm">Open Brand</x-ta.button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" aria-labelledby="data-integrations-heading">
            <h2 id="data-integrations-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Data &amp; integrations</h2>
            <ul class="mt-3 space-y-3">
                @foreach ($dashboard['integrations'] as $integration)
                    <li class="flex items-center justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-white/[0.03]">
                        <div>
                            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $integration['name'] }}</p>
                            <p class="text-xs text-gray-500">{{ $integration['detail'] }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-ta.badge :color="match($integration['state']) { 'connected' => 'success', 'needs_attention' => 'warning', default => 'info' }" size="sm">
                                {{ $integration['state_label'] }}
                            </x-ta.badge>
                            <x-ta.button :href="route($integration['route'])" size="sm" variant="outline">Open</x-ta.button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" aria-labelledby="ops-heading">
            <h2 id="ops-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Running / failed operations</h2>
            @foreach ([
                'running' => 'Running',
                'failed' => 'Failed recently',
                'queued' => 'Queued',
            ] as $key => $label)
                <div class="mt-3">
                    <p class="text-xs font-medium text-gray-400">{{ $label }}</p>
                    <ul class="mt-1 space-y-1">
                        @foreach ($dashboard['operations'][$key] ?? [] as $op)
                            <li class="text-sm text-gray-700 dark:text-gray-300">
                                <span class="font-medium text-gray-800 dark:text-white/90">{{ $op['title'] }}</span>
                                <span class="text-gray-500"> · {{ $op['detail'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
            <div class="mt-4">
                <x-ta.button href="{{ route('demo.activity') }}" size="sm" variant="outline">Activity</x-ta.button>
            </div>
        </section>

        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" aria-labelledby="outcomes-heading">
            <h2 id="outcomes-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Recent outcomes</h2>
            <ul class="mt-3 space-y-3">
                @foreach ($dashboard['recent_outcomes'] as $outcome)
                    <li class="flex items-start gap-3 rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-white/[0.03]">
                        @if (! empty($outcome['asset_type']))
                            <x-demo.digital-asset-mark :type="$outcome['asset_type']" size="sm" />
                        @endif
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $outcome['title'] }}</p>
                            <p class="text-xs text-gray-500">{{ $outcome['scope'] }}</p>
                        </div>
                        <x-ta.badge :color="match($outcome['tone'] ?? '') { 'good' => 'success', 'warn' => 'warning', default => 'light' }" size="sm">
                            {{ $outcome['outcome'] }}
                        </x-ta.badge>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>
</div>
