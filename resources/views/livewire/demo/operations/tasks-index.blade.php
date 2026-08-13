@php
    $viewFilters = [
        'my' => 'My Tasks',
        'all' => 'All Tasks',
        'overdue' => 'Overdue',
        'due_today' => 'Due Today',
        'blocked' => 'Blocked',
        'awaiting_outcome' => 'Awaiting Outcome',
        'completed' => 'Completed',
    ];
    $boardColumns = [
        'open' => 'Open',
        'in_progress' => 'In progress',
        'blocked' => 'Blocked',
        'completed' => 'Completed',
    ];
@endphp

<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Operations',
        'title' => __('operator.work.title'),
        'subtitle' => __('operator.work.subtitle'),
    ])

    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs uppercase tracking-wide text-gray-400">Overdue</p>
            <p class="mt-1 text-2xl font-bold text-error-600 dark:text-error-400">{{ $glance['overdue'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs uppercase tracking-wide text-gray-400">Due Today</p>
            <p class="mt-1 text-2xl font-bold text-warning-600 dark:text-warning-400">{{ $glance['due_today'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs uppercase tracking-wide text-gray-400">Blocked</p>
            <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $glance['blocked'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs uppercase tracking-wide text-gray-400">Awaiting Follow-up</p>
            <p class="mt-1 text-2xl font-bold text-brand-600 dark:text-brand-400">{{ $glance['awaiting'] }}</p>
        </div>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-2" role="tablist" aria-label="Task views">
            @foreach ($viewFilters as $key => $label)
                <button type="button" wire:click="setView('{{ $key }}')"
                    @class([
                        'rounded-lg px-3 py-2 text-sm font-medium transition',
                        'bg-brand-500 text-white' => $view === $key,
                        'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $view !== $key,
                    ])>{{ $label }}</button>
            @endforeach
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="setViewMode('list')"
                @class([
                    'rounded-lg px-3 py-2 text-sm font-medium',
                    'bg-brand-500 text-white' => $viewMode === 'list',
                    'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $viewMode !== 'list',
                ])>List</button>
            <button type="button" wire:click="setViewMode('board')"
                @class([
                    'rounded-lg px-3 py-2 text-sm font-medium',
                    'bg-brand-500 text-white' => $viewMode === 'board',
                    'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $viewMode !== 'board',
                ])>Board</button>
        </div>
    </div>

    @if ($viewMode === 'board')
        <div class="grid gap-4 xl:grid-cols-4">
            @foreach ($boardColumns as $columnKey => $columnLabel)
                <div class="rounded-2xl bg-gray-50 p-3 dark:bg-white/[0.02]">
                    <div class="mb-3 flex items-center justify-between gap-2 px-1">
                        <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ $columnLabel }}</h2>
                        <span class="text-xs text-gray-400">{{ count($board[$columnKey] ?? []) }}</span>
                    </div>
                    <div class="space-y-3">
                        @forelse ($board[$columnKey] ?? [] as $task)
                            <a href="{{ route('demo.task', ['taskId' => $task['id']]) }}" wire:navigate class="block rounded-xl bg-white p-3 ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-800 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                <x-ta.badge :color="in_array($task['priority'] ?? '', ['high', 'critical'], true) ? 'error' : 'warning'" size="sm">{{ $task['priority'] }}</x-ta.badge>
                                <p class="mt-2 text-sm font-semibold text-gray-800 dark:text-white/90">{{ $task['title'] }}</p>
                                <p class="mt-1 text-xs text-gray-500">{{ $task['brand'] }} · {{ $task['asset'] ?? 'Brand scope' }}</p>
                                <p class="mt-2 text-xs text-gray-400">{{ $task['owner'] }} · due {{ $task['due'] }}</p>
                            </a>
                        @empty
                            <p class="px-1 py-6 text-center text-xs text-gray-400">No tasks</p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <table class="min-w-full text-sm">
                <caption class="sr-only">Tasks</caption>
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <th scope="col" class="px-4 py-3 text-left text-xs uppercase text-gray-400">Task</th>
                        <th scope="col" class="hidden px-4 py-3 text-left text-xs uppercase text-gray-400 md:table-cell">Brand / Asset</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs uppercase text-gray-400">Priority</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs uppercase text-gray-400">Assignee</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs uppercase text-gray-400">Due</th>
                        <th scope="col" class="hidden px-4 py-3 text-left text-xs uppercase text-gray-400 lg:table-cell">Status</th>
                        <th scope="col" class="hidden px-4 py-3 text-left text-xs uppercase text-gray-400 xl:table-cell">Origin</th>
                        <th scope="col" class="hidden px-4 py-3 text-left text-xs uppercase text-gray-400 xl:table-cell">Outcome</th>
                        <th scope="col" class="px-4 py-3"><span class="sr-only">Open</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tasks as $task)
                        <tr class="border-b border-gray-50 dark:border-gray-800/60">
                            <td class="px-4 py-3 font-medium text-gray-800 dark:text-white/90">{{ $task['title'] }}</td>
                            <td class="hidden px-4 py-3 text-gray-500 md:table-cell">
                                {{ $task['brand'] }}
                                <span class="block text-xs">{{ $task['asset'] ?? ($task['scope_level'] ?? 'brand') }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <x-ta.badge :color="in_array($task['priority'] ?? '', ['high', 'critical'], true) ? 'error' : 'warning'" size="sm">{{ $task['priority'] }}</x-ta.badge>
                            </td>
                            <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $task['owner'] }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $task['due'] }}</td>
                            <td class="hidden px-4 py-3 lg:table-cell">
                                <x-ta.badge color="light" size="sm">{{ $task['status'] }}</x-ta.badge>
                            </td>
                            <td class="hidden px-4 py-3 text-xs text-gray-500 xl:table-cell">{{ $task['origin'] ?? '—' }}</td>
                            <td class="hidden px-4 py-3 text-xs text-gray-500 xl:table-cell">{{ $task['outcome']['label'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <x-ta.button :href="route('demo.task', ['taskId' => $task['id']])" size="sm" variant="outline">Open</x-ta.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center text-sm text-gray-500">No tasks match this view.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
