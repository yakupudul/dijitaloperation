<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Portfolio',
        'title' => 'Customers',
        'subtitle' => 'Agency client accounts — brands and open work roll up here.',
        'actions' => '<button type="button" wire:click="openAdd" class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">Add customer</button>',
    ])

    <x-ta.table>
        <x-slot:head>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Customer</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Industry</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">HQ</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Brands</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Open issues</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Open tasks</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Status</th>
            <th class="px-5 py-3"></th>
        </x-slot:head>
        @forelse ($customers as $customer)
            <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                <td class="px-5 py-4">
                    <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $customer['name'] }}</p>
                </td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ $customer['industry'] }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ $customer['hq'] }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ $customer['brands_count'] }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ $customer['open_issues'] }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ $customer['open_tasks'] ?? 0 }}</td>
                <td class="px-5 py-4">
                    <x-ta.badge :color="($customer['status'] ?? '') === 'active' ? 'success' : 'light'" size="sm">
                        {{ $customer['status'] }}
                    </x-ta.badge>
                </td>
                <td class="px-5 py-4 text-right">
                    <x-ta.button :href="route('demo.customer', ['customerId' => $customer['id']])" size="sm" variant="outline">Open</x-ta.button>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="8" class="px-5 py-8">
                    @include('livewire.demo.partials.empty-panel', [
                        'title' => 'No customers yet',
                        'message' => 'Add a customer to start the portfolio hierarchy.',
                    ])
                </td>
            </tr>
        @endforelse
    </x-ta.table>

    <div x-data="{ open: @entangle('showAdd') }">
        <x-ta.modal>
            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Add customer</h3>
            <p class="mt-1 text-sm text-gray-500">Demo Mode — stored in session only, not written to the operator database.</p>
            <div class="mt-4 space-y-3">
                <div>
                    <label class="mb-1 block text-sm text-gray-600 dark:text-gray-300">Name</label>
                    <input wire:model="name" type="text" placeholder="e.g. Horizon Clinics"
                        class="w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700" />
                    @error('name') <p class="mt-1 text-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm text-gray-600 dark:text-gray-300">Industry</label>
                    <input wire:model="industry" type="text" placeholder="Healthcare"
                        class="w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700" />
                    @error('industry') <p class="mt-1 text-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm text-gray-600 dark:text-gray-300">HQ</label>
                    <input wire:model="hq" type="text" placeholder="Ankara, Türkiye"
                        class="w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700" />
                    @error('hq') <p class="mt-1 text-xs text-error-500">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <x-ta.button wire:click="closeAdd" variant="outline" size="sm">Cancel</x-ta.button>
                <x-ta.button wire:click="saveCustomer" size="sm">Save customer</x-ta.button>
            </div>
        </x-ta.modal>
    </div>
</div>
