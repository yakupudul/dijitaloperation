@php
    $statusFilters = [
        'all' => 'All',
        'open' => 'Open',
        'in_progress' => 'In progress',
        'blocked' => 'Blocked',
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
        'title' => 'Tasks',
        'subtitle' => 'Operational work linked to findings and recommendations — owners, due dates, outcomes.',
    ])

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-2">
            @foreach ($statusFilters as $key => $label)
                <button type="button" wire:click="setStatus('{{ $key }}')"
                    @class([
                        'rounded-lg px-3 py-2 text-sm font-medium transition',
                        'bg-brand-500 text-white' => $status === $key,
                        'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $status !== $key,
                    ])>{{ $label }}</button>
            @endforeach
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="setViewMode('list')"
                @class([
                    'rounded-lg px-3 py-2 text-sm font-medium transition',
                    'bg-brand-500 text-white' => $viewMode === 'list',
                    'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $viewMode !== 'list',
                ])>List</button>
            <button type="button" wire:click="setViewMode('board')"
                @class([
                    'rounded-lg px-3 py-2 text-sm font-medium transition',
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
                            <a href="{{ route('demo.task', ['taskId' => $task['id']]) }}" class="block rounded-xl bg-white p-3 ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-800 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <x-ta.badge :color="$task['priority'] === 'high' ? 'error' : 'warning'" size="sm">{{ $task['priority'] }}</x-ta.badge>
                                </div>
                                <p class="mt-2 text-sm font-semibold text-gray-800 dark:text-white/90">{{ $task['title'] }}</p>
                                <p class="mt-1 text-xs text-gray-500">{{ $task['brand'] }} · {{ $task['asset'] }}</p>
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
        <div class="space-y-3">
            @forelse ($tasks as $task)
                @php
                    $statusColor = match ($task['status']) {
                        'completed' => 'success',
                        'in_progress' => 'info',
                        'blocked' => 'error',
                        default => 'light',
                    };
                @endphp
                <x-ta.card>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-ta.badge :color="$task['priority'] === 'high' ? 'error' : 'warning'" size="sm">{{ $task['priority'] }}</x-ta.badge>
                                <x-ta.badge :color="$statusColor" size="sm">{{ str_replace('_', ' ', $task['status']) }}</x-ta.badge>
                            </div>
                            <h3 class="mt-2 text-base font-semibold text-gray-800 dark:text-white/90">{{ $task['title'] }}</h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $task['brand'] }} · {{ $task['asset'] }}</p>
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Owner {{ $task['owner'] }} · Due {{ $task['due'] }}</p>
                        </div>
                        <x-ta.button :href="route('demo.task', ['taskId' => $task['id']])" size="sm" variant="outline">Open</x-ta.button>
                    </div>
                </x-ta.card>
            @empty
                <x-ta.empty-state title="No tasks" message="Adjust the status filter." />
            @endforelse
        </div>
    @endif
</div>
