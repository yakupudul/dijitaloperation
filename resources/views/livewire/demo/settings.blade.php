<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div>
        <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">Settings</h1>
        <p class="mt-1 text-sm text-gray-500">Demo Mode controls for the interactive product shell.</p>
    </div>

    <x-ta.card>
        <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Demo Mode</h2>
        <p class="mt-2 text-sm text-gray-500">
            Resets recommendations, tasks, activity, import simulation, period selection, and session-only customers/brands
            back to the DemoCatalog seed. Does not touch the operator database.
        </p>
        <div class="mt-4 flex flex-wrap gap-2">
            <x-ta.button wire:click="resetDemo" size="sm" variant="danger">Reset Demo Mode</x-ta.button>
            <x-ta.button href="{{ route('demo.dashboard') }}" size="sm" variant="outline">Back to dashboard</x-ta.button>
            <x-ta.button href="/system" size="sm" variant="outline">Open system panel</x-ta.button>
        </div>
    </x-ta.card>
</div>