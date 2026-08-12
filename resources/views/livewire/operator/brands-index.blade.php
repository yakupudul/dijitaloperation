<div>
    <x-ta.page-breadcrumb pageTitle="Brands" />

    <div class="mb-4 flex items-center justify-between gap-3">
        <div class="relative w-full max-w-xs">
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search brands…"
                class="h-11 w-full rounded-lg border border-gray-200 bg-transparent py-2.5 pl-4 pr-4 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-800 dark:text-white/90" />
        </div>
        <a href="/admin/customers" class="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Manage in back-office &rarr;</a>
    </div>

    @if ($brands->isEmpty())
        <x-ta.empty-state title="No brands found" message="Create brands under a customer in the back-office." />
    @else
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Brand</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Customer</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Assets</th>
                <th class="px-5 py-3 text-right text-xs font-medium uppercase text-gray-400"></th>
            </x-slot:head>
            @foreach ($brands as $brand)
                <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                    <td class="px-5 py-4">
                        <span class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $brand->name }}</span>
                    </td>
                    <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $brand->customer?->name ?? '—' }}</td>
                    <td class="px-5 py-4">
                        <x-ta.badge color="light">{{ $brand->digital_assets_count }}</x-ta.badge>
                    </td>
                    <td class="px-5 py-4 text-right">
                        <a href="{{ route('operator.digital-assets', ['brand' => $brand->id]) }}"
                            class="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">View assets &rarr;</a>
                    </td>
                </tr>
            @endforeach
        </x-ta.table>

        <div class="mt-4">{{ $brands->links() }}</div>
    @endif
</div>
