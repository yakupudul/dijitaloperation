<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">Tasks</h1>
            <p class="mt-1 text-sm text-gray-500">Operational work linked to findings and recommendations.</p>
        </div>
        @include('livewire.demo.partials.demo-badge')
    </div>

    <div class="flex flex-wrap gap-2">
        @foreach (['all' => 'All', 'open' => 'Open', 'in_progress' => 'In progress', 'completed' => 'Completed'] as $key => $label)
            <button type="button" wire:click="setStatus('{{ $key }}')"
                @class([
                    'rounded-lg px-3 py-2 text-sm font-medium',
                    'bg-brand-500 text-white' => $status === $key,
                    'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $status !== $key,
                ])>{{ $label }}</button>
        @endforeach
    </div>

    <x-ta.table>
        <x-slot:head>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Task</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Asset</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Owner</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Priority</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Due</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Status</th>
            <th class="px-5 py-3"></th>
        </x-slot:head>
        @forelse ($tasks as $task)
            <tr>
                <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $task['title'] }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ $task['asset'] }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ $task['owner'] }}</td>
                <td class="px-5 py-4"><x-ta.badge :color="$task['priority'] === 'high' ? 'error' : 'warning'" size="sm">{{ $task['priority'] }}</x-ta.badge></td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ $task['due'] }}</td>
                <td class="px-5 py-4"><x-ta.badge :color="match($task['status']) { 'completed' => 'success', 'in_progress' => 'info', default => 'light' }" size="sm">{{ $task['status'] }}</x-ta.badge></td>
                <td class="px-5 py-4 text-right"><x-ta.button :href="route('demo.task', ['taskId' => $task['id']])" size="sm" variant="outline">Open</x-ta.button></td>
            </tr>
        @empty
            <tr><td colspan="7" class="px-5 py-8"><x-ta.empty-state title="No tasks" message="Adjust the status filter." /></td></tr>
        @endforelse
    </x-ta.table>
</div>