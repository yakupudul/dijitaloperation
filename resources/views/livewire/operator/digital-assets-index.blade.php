<div>
    <x-ta.page-breadcrumb pageTitle="Digital Assets" />

    <div class="mb-4 flex items-center justify-between gap-3">
        <div class="relative w-full max-w-xs">
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search assets…"
                class="h-11 w-full rounded-lg border border-gray-200 bg-transparent py-2.5 pl-4 pr-4 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-800 dark:text-white/90" />
        </div>
        <a href="/admin/customers" class="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Manage in back-office &rarr;</a>
    </div>

    @if ($assets->isEmpty())
        <x-ta.empty-state title="No digital assets found" message="Digital assets are created under a brand in the back-office." />
    @else
        <x-ta.table>
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Asset</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Brand</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Type</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Status</th>
                <th class="px-5 py-3 text-right text-xs font-medium uppercase text-gray-400"></th>
            </x-slot:head>
            @foreach ($assets as $asset)
                <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                    <td class="px-5 py-4">
                        <span class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $asset->name }}</span>
                        @if ($asset->primary_url)
                            <span class="block text-xs text-gray-400">{{ $asset->primary_url }}</span>
                        @endif
                    </td>
                    <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $asset->brand?->name ?? '—' }}</td>
                    <td class="px-5 py-4">
                        <x-ta.badge color="light">{{ str($asset->type)->replace('_', ' ')->title() }}</x-ta.badge>
                    </td>
                    <td class="px-5 py-4">
                        @php
                            $statusValue = $asset->status instanceof \BackedEnum ? $asset->status->value : (string) $asset->status;
                            $statusColor = match ($statusValue) {
                                'active' => 'success',
                                'archived' => 'error',
                                default => 'light',
                            };
                        @endphp
                        <x-ta.badge :color="$statusColor">{{ ucfirst($statusValue) }}</x-ta.badge>
                    </td>
                    <td class="px-5 py-4 text-right">
                        @if ($asset->type === 'meta_ads')
                            <a href="{{ route('operator.meta.overview', $asset) }}"
                                class="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Meta Overview &rarr;</a>
                        @else
                            <a href="/admin/customers" class="text-sm text-gray-400 hover:text-gray-600">Open in back-office &rarr;</a>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ta.table>

        <div class="mt-4">{{ $assets->links() }}</div>
    @endif
</div>
