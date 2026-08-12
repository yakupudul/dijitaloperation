<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">Integrations</h1>
            <p class="mt-1 text-sm text-gray-500">Provider connections for Demo Mode — no live OAuth writes from this shell.</p>
        </div>
        @include('livewire.demo.partials.demo-badge')
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($integrations as $integration)
            <x-ta.card>
                <div class="flex items-start justify-between gap-2">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $integration['name'] }}</h3>
                    <x-ta.badge :color="$integration['status'] === 'connected' ? 'success' : 'info'" size="sm">{{ $integration['status'] }}</x-ta.badge>
                </div>
                <p class="mt-2 text-sm text-gray-500">{{ $integration['summary'] }}</p>
                <p class="mt-2 text-xs text-gray-400">Last sync {{ $integration['last_sync'] }} · problems {{ $integration['problems'] }}</p>
                <div class="mt-4">
                    <x-ta.button :href="route($integration['route'])" size="sm">
                        {{ $integration['id'] === 'meta' ? 'Open Meta import' : 'View' }}
                    </x-ta.button>
                </div>
            </x-ta.card>
        @endforeach
    </div>
</div>