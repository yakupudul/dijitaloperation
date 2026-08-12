<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <x-ta.button href="{{ route('demo.tasks') }}" size="sm" variant="outline">← Tasks</x-ta.button>
            <h1 class="mt-3 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $task['title'] }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $task['brand'] }} · {{ $task['asset'] }} · {{ $task['owner'] }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-ta.button wire:click="setStatus('open')" size="sm" variant="outline">Mark open</x-ta.button>
            <x-ta.button wire:click="setStatus('in_progress')" size="sm" variant="outline">In progress</x-ta.button>
            <x-ta.button wire:click="setStatus('completed')" size="sm">Complete</x-ta.button>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <x-ta.card class="lg:col-span-2">
            <h2 class="font-semibold text-gray-800 dark:text-white/90">Description</h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $task['description'] }}</p>
            <h3 class="mt-4 text-sm font-semibold text-gray-700 dark:text-gray-200">Success signal</h3>
            <p class="text-sm text-gray-500">{{ $task['success_signal'] }}</p>
            <h3 class="mt-4 text-sm font-semibold text-gray-700 dark:text-gray-200">Why this task exists</h3>
            <ul class="mt-2 space-y-1 text-sm text-gray-500">
                <li>Finding: {{ $task['why']['finding'] ?? '—' }}</li>
                <li>Recommendation: {{ $task['why']['recommendation'] ?? '—' }}</li>
                <li>Evidence: {{ $task['why']['evidence'] ?? '—' }}</li>
            </ul>
        </x-ta.card>

        <div class="space-y-4">
            <x-ta.card>
                <p class="text-sm text-gray-400">Status</p>
                <x-ta.badge class="mt-2" :color="match($task['status']) { 'completed' => 'success', 'in_progress' => 'info', default => 'warning' }" size="sm">{{ $task['status'] }}</x-ta.badge>
                <p class="mt-3 text-sm text-gray-400">Priority</p>
                <p class="font-medium text-gray-800 dark:text-white/90">{{ $task['priority'] }}</p>
                <p class="mt-3 text-sm text-gray-400">Due</p>
                <p class="font-medium text-gray-800 dark:text-white/90">{{ $task['due'] }}</p>
            </x-ta.card>

            @if (! empty($task['outcome']))
                <x-ta.card>
                    <h3 class="font-semibold text-gray-800 dark:text-white/90">Outcome</h3>
                    <x-ta.badge class="mt-2" color="success" size="sm">{{ $task['outcome']['label'] }}</x-ta.badge>
                    <p class="mt-2 text-sm text-gray-500">{{ $task['outcome']['before'] }} → {{ $task['outcome']['after'] }} ({{ $task['outcome']['period'] }})</p>
                    <p class="mt-2 text-xs text-gray-400">{{ $task['outcome']['note'] }}</p>
                </x-ta.card>
            @endif
        </div>
    </div>

    <x-ta.card>
        <h2 class="mb-4 font-semibold text-gray-800 dark:text-white/90">Decision timeline</h2>
        <ol class="space-y-3">
            @foreach ($timeline as $event)
                <li class="rounded-xl bg-gray-50 px-4 py-3 dark:bg-white/[0.02]">
                    <p class="text-xs text-gray-400">{{ $event['date'] }}</p>
                    <p class="font-medium text-gray-800 dark:text-white/90">{{ $event['event'] }}</p>
                    <p class="text-sm text-gray-500">{{ $event['detail'] }}</p>
                </li>
            @endforeach
        </ol>
    </x-ta.card>
</div>