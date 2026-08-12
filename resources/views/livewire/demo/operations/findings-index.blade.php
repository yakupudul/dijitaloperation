<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">Findings</h1>
            <p class="mt-1 text-sm text-gray-500">Detected issues across connected and public assets.</p>
        </div>
        @include('livewire.demo.partials.demo-badge')
    </div>

    <div class="flex flex-wrap gap-2">
        @foreach (['all' => 'All', 'high' => 'High', 'medium' => 'Medium', 'info' => 'Info'] as $key => $label)
            <button type="button" wire:click="setSeverity('{{ $key }}')"
                @class([
                    'rounded-lg px-3 py-2 text-sm font-medium',
                    'bg-brand-500 text-white' => $severity === $key,
                    'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $severity !== $key,
                ])>{{ $label }}</button>
        @endforeach
    </div>

    <div class="space-y-3">
        @forelse ($findings as $finding)
            <x-ta.card>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <x-ta.badge :color="match($finding['severity']) { 'high' => 'error', 'medium' => 'warning', default => 'info' }" size="sm">{{ $finding['severity'] }}</x-ta.badge>
                            <span class="text-sm text-gray-500">{{ $finding['asset'] }} · {{ $finding['brand'] }}</span>
                        </div>
                        <h3 class="mt-2 text-base font-semibold text-gray-800 dark:text-white/90">{{ $finding['title'] }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ $finding['evidence'] }}</p>
                        <p class="mt-2 text-xs text-gray-400">Detected {{ $finding['detected'] }} · {{ $finding['type'] }} · {{ $finding['status'] }}</p>
                    </div>
                    <x-ta.button href="{{ route('demo.recommendations') }}" size="sm" variant="outline">Related recommendations</x-ta.button>
                </div>
            </x-ta.card>
        @empty
            <x-ta.empty-state title="No findings for this filter" message="Try another severity." />
        @endforelse
    </div>
</div>
