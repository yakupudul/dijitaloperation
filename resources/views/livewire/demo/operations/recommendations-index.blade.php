<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">Recommendations</h1>
            <p class="mt-1 text-sm text-gray-500">Approve, reject, or create tasks — Demo Mode session only.</p>
        </div>
        @include('livewire.demo.partials.demo-badge')
    </div>

    <div class="space-y-4">
        @foreach ($recommendations as $rec)
            <x-ta.card>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $rec['title'] }}</h3>
                            <x-ta.badge :color="match($rec['status']) { 'approved' => 'success', 'rejected' => 'error', default => 'warning' }" size="sm">{{ $rec['status'] }}</x-ta.badge>
                        </div>
                        <p class="mt-1 text-sm text-gray-500">{{ $rec['brand'] }} · {{ $rec['asset'] }}</p>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $rec['observation'] }}</p>

                        @if ($expandedId === $rec['id'])
                            <dl class="mt-4 space-y-2 rounded-xl bg-gray-50 p-4 text-sm dark:bg-white/[0.02]">
                                <div><dt class="text-gray-400">Why</dt><dd>{{ $rec['why'] }}</dd></div>
                                <div><dt class="text-gray-400">Evidence</dt><dd>{{ $rec['evidence'] }}</dd></div>
                                <div><dt class="text-gray-400">Action</dt><dd>{{ $rec['action'] }}</dd></div>
                                <div><dt class="text-gray-400">Success</dt><dd>{{ $rec['success'] }}</dd></div>
                                <div><dt class="text-gray-400">Failure</dt><dd>{{ $rec['failure'] }}</dd></div>
                            </dl>
                        @endif
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
