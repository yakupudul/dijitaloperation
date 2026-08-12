@php
    $why = is_array($task['why'] ?? null) ? $task['why'] : [];
@endphp

<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <x-ta.button href="{{ route('demo.tasks') }}" size="sm" variant="outline">← Tasks</x-ta.button>
            <h1 class="mt-3 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $task['title'] }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $task['brand'] }} · {{ $task['asset'] }} · {{ $task['owner'] }}</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <x-ta.badge :color="match($task['status']) { 'completed' => 'success', 'in_progress' => 'info', 'blocked' => 'error', default => 'warning' }" size="sm">
                    {{ str_replace('_', ' ', $task['status']) }}
                </x-ta.badge>
                <x-ta.badge :color="$task['priority'] === 'high' ? 'error' : 'warning'" size="sm">{{ $task['priority'] }}</x-ta.badge>
                <span class="text-sm text-gray-400">Due {{ $task['due'] }}</span>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-ta.button wire:click="setStatus('open')" size="sm" variant="outline">Mark open</x-ta.button>
            <x-ta.button wire:click="setStatus('in_progress')" size="sm" variant="outline">In progress</x-ta.button>
            <x-ta.button wire:click="setStatus('blocked')" size="sm" variant="outline">Blocked</x-ta.button>
            <x-ta.button wire:click="setStatus('completed')" size="sm">Complete</x-ta.button>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ta.card>
                @include('livewire.demo.partials.section-question', [
                    'question' => 'WHY',
                    'hint' => 'Why this task exists — finding, recommendation, and evidence.',
                ])
                <dl class="mt-1 space-y-3 text-sm">
                    <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.02]">
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-400">Finding</dt>
                        <dd class="mt-1 text-gray-700 dark:text-gray-300">{{ $why['finding'] ?? '—' }}</dd>
                    </div>
                    <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.02]">
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-400">Recommendation</dt>
                        <dd class="mt-1 text-gray-700 dark:text-gray-300">{{ $why['recommendation'] ?? '—' }}</dd>
                    </div>
                    <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.02]">
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-400">Evidence</dt>
                        <dd class="mt-1 text-gray-700 dark:text-gray-300">{{ $why['evidence'] ?? '—' }}</dd>
                    </div>
                </dl>
            </x-ta.card>

            <x-ta.card>
                @include('livewire.demo.partials.section-question', [
                    'question' => 'DO',
                    'hint' => 'Internal work to perform outside MoxDOP — no external write from here.',
                ])
                <p class="text-sm text-gray-700 dark:text-gray-300">{{ $task['do'] ?? $task['description'] }}</p>
            </x-ta.card>

            <x-ta.card>
                @include('livewire.demo.partials.section-question', [
                    'question' => 'MEASURE',
                    'hint' => 'Success signal to watch after the work lands.',
                ])
                <p class="text-sm text-gray-700 dark:text-gray-300">{{ $task['measure'] ?? $task['success_signal'] }}</p>
            </x-ta.card>

            <x-ta.card>
                @include('livewire.demo.partials.section-question', [
                    'question' => 'FOLLOW-UP',
                    'hint' => 'What to re-check after the next comparable collection.',
                ])
                <p class="text-sm text-gray-700 dark:text-gray-300">
                    {{ $task['follow_up'] ?? 'Re-evaluate the linked finding after the next comparable collection window.' }}
                </p>
            </x-ta.card>
        </div>

        <div class="space-y-4">
            <x-ta.card>
                <p class="text-sm text-gray-400">Status</p>
                <x-ta.badge class="mt-2" :color="match($task['status']) { 'completed' => 'success', 'in_progress' => 'info', 'blocked' => 'error', default => 'warning' }" size="sm">
                    {{ str_replace('_', ' ', $task['status']) }}
                </x-ta.badge>
                <p class="mt-3 text-sm text-gray-400">Priority</p>
                <p class="font-medium text-gray-800 dark:text-white/90">{{ $task['priority'] }}</p>
                <p class="mt-3 text-sm text-gray-400">Due</p>
                <p class="font-medium text-gray-800 dark:text-white/90">{{ $task['due'] }}</p>
                <p class="mt-3 text-sm text-gray-400">Owner</p>
                <p class="font-medium text-gray-800 dark:text-white/90">{{ $task['owner'] }}</p>
            </x-ta.card>

            @if (! empty($task['outcome']))
                <x-ta.card>
                    <h3 class="font-semibold text-gray-800 dark:text-white/90">Outcome</h3>
                    <x-ta.badge class="mt-2" color="success" size="sm">{{ $task['outcome']['label'] }}</x-ta.badge>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $task['outcome']['before'] }} → {{ $task['outcome']['after'] }} ({{ $task['outcome']['period'] }})</p>
                    <p class="mt-2 text-xs text-gray-400">{{ $task['outcome']['note'] }}</p>
                </x-ta.card>
            @endif
        </div>
    </div>

    <x-ta.card>
        @include('livewire.demo.partials.decision-timeline', ['events' => $timeline])
    </x-ta.card>
</div>
