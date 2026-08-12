<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">Brands</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Brand health and open work across the portfolio.</p>
        </div>
        <x-ta.button wire:click="openAdd" size="sm">Add brand</x-ta.button>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($brands as $brand)
            <x-ta.card>
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $brand['name'] }}</h3>
                        <p class="text-sm text-gray-500">{{ $brand['location'] ?? '—' }}</p>
                    </div>
                    <x-ta.badge :color="($brand['health'] ?? '') === 'needs_attention' ? 'warning' : 'success'" size="sm">
                        {{ $brand['health_label'] ?? 'Healthy' }}
                    </x-ta.badge>
                </div>
                <dl class="mt-4 grid grid-cols-2 gap-2 text-sm">
                    <div>
                        <dt class="text-gray-400">Assets</dt>
                        <dd class="font-medium text-gray-800 dark:text-white/90">{{ $brand['assets_count'] ?? 0 }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-400">Open tasks</dt>
                        <dd class="font-medium text-gray-800 dark:text-white/90">{{ $brand['open_tasks'] ?? 0 }}</dd>
                    </div>
                </dl>
                <div class="mt-4">
                    <x-ta.button :href="route('demo.brand', ['brand' => $brand['id']])" size="sm">Open brand home</x-ta.button>
                </div>
            </x-ta.card>
        @endforeach
    </div>

    <div x-data="{ open: @entangle('showAdd') }">
        <x-ta.modal>
            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Add brand</h3>
            <p class="mt-1 text-sm text-gray-500">Demo Mode — session only.</p>
            <div class="mt-4 space-y-3">
                <div>
                    <label class="mb-1 block text-sm text-gray-600 dark:text-gray-300">Name</label>
                    <input wire:model="name" type="text" class="w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700" />
                    @error('name') <p class="mt-1 text-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm text-gray-600 dark:text-gray-300">Location</label>
                    <input wire:model="location" type="text" class="w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700" />
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <x-ta.button wire:click="closeAdd" variant="outline" size="sm">Cancel</x-ta.button>
                <x-ta.button wire:click="saveBrand" size="sm">Save</x-ta.button>
            </div>
        </x-ta.modal>
    </div>
</div>
