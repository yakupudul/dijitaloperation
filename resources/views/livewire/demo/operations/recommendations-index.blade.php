<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Operations',
        'title' => 'Recommendations',
        'subtitle' => 'Approve, reject, or create tasks — Demo Mode session only. No external writes.',
    ])

    <div class="space-y-4">
        @foreach ($recommendations as $rec)
            <x-ta.card>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $rec['title'] }}</h3>
                            <x-ta.badge :color="match($rec['status']) { 'approved' => 'success', 'rejected' => 'error', default => 'warning' }" size="sm">{{ $rec['status'] }}</x-ta.badge>
                        </div>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $rec['brand'] }} · {{ $rec['asset'] }}</p>

                        <div class="mt-4 space-y-3">
                            <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.02]">
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Observation</p>
                                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $rec['observation'] }}</p>
                            </div>

                            @if ($expandedId === $rec['id'])
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.02]">
                                        <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Why</p>
                                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $rec['why'] }}</p>
                                    </div>
                                    <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.02]">
                                        <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Evidence</p>
                                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $rec['evidence'] }}</p>
                                    </div>
                                    <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.02] sm:col-span-2">
                                        <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Action</p>
                                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $rec['action'] }}</p>
                                    </div>
                                    <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.02]">
                                        <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Success</p>
                                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $rec['success'] }}</p>
                                    </div>
                                    <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.02]">
                                        <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Failure</p>
                                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $rec['failure'] }}</p>
                                    </div>
                                    @if (! empty($rec['watch']))
                                        <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.02] sm:col-span-2">
                                            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Watch</p>
                                            <div class="mt-2 flex flex-wrap gap-1.5">
                                                @foreach ($rec['watch'] as $metric)
                                                    <x-ta.badge color="light" size="sm">{{ $metric }}</x-ta.badge>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-ta.button wire:click="expand('{{ $rec['id'] }}')" size="sm" variant="outline">
                            {{ $expandedId === $rec['id'] ? 'Hide' : 'Details' }}
                        </x-ta.button>
                        @if ($rec['status'] === 'pending')
                            <x-ta.button wire:click="approve('{{ $rec['id'] }}')" size="sm">Approve</x-ta.button>
                            <x-ta.button wire:click="reject('{{ $rec['id'] }}')" size="sm" variant="danger">Reject</x-ta.button>
                        @endif
                        <x-ta.button wire:click="createTask('{{ $rec['id'] }}')" size="sm" variant="outline">Create task</x-ta.button>
                    </div>
                </div>
            </x-ta.card>
        @endforeach
    </div>
</div>
