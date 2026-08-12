<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Portfolio',
        'title' => 'Brands',
        'subtitle' => 'Brand health and open work — open a brand home to operate.',
        'actions' => '<button type="button" wire:click="openAdd" class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">Add brand</button>',
    ])

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($brands as $brand)
            <x-ta.card>
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $brand['name'] }}</h3>
                        <p class="text-sm text-gray-500">{{ $brand['location'] ?? '—' }} · {{ $brand['industry'] ?? '' }}</p>
                    </div>
                    <x-ta.badge :color="($brand['health'] ?? '') === 'needs_attention' ? 'warning' : 'success'" size="sm">
                        {{ $brand['health_label'] ?? 'Healthy' }}
                    </x-ta.badge>
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-gray-400">Assets</dt>
                        <dd class="font-medium text-gray-800 dark:text-white/90">{{ $brand['assets_count'] ?? 0 }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-400">Open tasks</dt>
                        <dd class="font-medium text-gray-800 dark:text-white/90">{{ $brand['open_tasks'] ?? 0 }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-400">Media spend</dt>
                        <dd class="font-medium text-gray-800 dark:text-white/90">₺{{ number_format($brand['summary']['media_spend'] ?? 0) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-400">Platform leads</dt>
                        <dd class="font-medium text-gray-800 dark:text-white/90">{{ number_format($brand['summary']['platform_leads'] ?? 0) }}</dd>
                    </div>
                </dl>

                <div class="mt-4 flex flex-wrap gap-2">
                    <x-ta.button :href="route('demo.brand', ['brand' => $brand['id']])" size="sm">Open brand</x-ta.button>
                    <x-ta.button :href="route('demo.brand', ['brand' => $brand['id'], 'tab' => 'assets'])" size="sm" variant="outline">Assets</x-ta.button>
                </div>
            </x-ta.card>
        @empty
            @include('livewire.demo.partials.empty-panel', [
                'title' => 'No brands yet',
                'message' => 'Add a brand under a customer to begin.',
            ])
        @endforelse
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
