<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Integrations',
        'title' => 'Integrations',
        'subtitle' => 'Provider connections for Demo Mode — no live OAuth writes from this shell.',
    ])

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($integrations as $integration)
            @php
                $statusColor = match ($integration['status']) {
                    'connected' => 'success',
                    'configured' => 'info',
                    default => 'warning',
                };
            @endphp
            <x-ta.card>
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Provider</p>
                        <h3 class="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{{ $integration['name'] }}</h3>
                    </div>
                    <x-ta.badge :color="$statusColor" size="sm">{{ $integration['status'] }}</x-ta.badge>
                </div>
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ $integration['summary'] }}</p>
                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-gray-400">Last sync</dt>
                        <dd class="font-medium text-gray-800 dark:text-white/90">{{ $integration['last_sync'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-400">Problems</dt>
                        <dd class="font-medium text-gray-800 dark:text-white/90">{{ $integration['problems'] }}</dd>
                    </div>
                </dl>
                <div class="mt-4">
                    <x-ta.button :href="route($integration['route'])" size="sm">
                        {{ $integration['id'] === 'meta' ? 'Open Meta import' : 'View' }}
                    </x-ta.button>
                </div>
            </x-ta.card>
        @endforeach
    </div>
</div>
