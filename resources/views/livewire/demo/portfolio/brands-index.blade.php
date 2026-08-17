<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.forms.portfolio') }}</p>
            <h1 class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ __('operator.nav.brands') }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Brands managed across customer accounts and their digital operations.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('operator.setup', ['entry' => 'brand']) }}" wire:navigate
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">
                {{ __('operator.portfolio.add_brand_wizard') }}
            </a>
            <a href="{{ route('operator.brand.create') }}" wire:navigate
                class="inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">
                {{ __('operator.portfolio.quick_add') }}
            </a>
        </div>
    </div>

    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $summaryLine }}</p>

    <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div class="min-w-0 flex-1">
                <label class="mb-1 block text-xs font-medium text-gray-500" for="brand-search">Search</label>
                <input id="brand-search" wire:model.live.debounce.300ms="search" type="search" placeholder="Search brands..."
                    class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
            </div>
            <div class="flex flex-wrap gap-2">
                <select wire:model.live="customer" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="Customer">
                    <option value="">Customer</option>
                    @foreach ($customerOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="sector" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="Sector">
                    <option value="">Sector</option>
                    @foreach ($sectorOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="primary_market" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="Primary market">
                    <option value="">Primary market</option>
                    @foreach ($countryOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="asset_type" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="Digital asset type">
                    <option value="">Digital asset type</option>
                    @foreach ($assetTypeOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="responsible" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="Responsible team member">
                    <option value="">Responsible</option>
                    @foreach ($teamOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="attention" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="Attention">
                    <option value="">Attention</option>
                    <option value="needs_attention">Needs attention</option>
                    <option value="clear">No immediate attention</option>
                </select>
                <select wire:model.live="context" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="Business context">
                    <option value="">Business context</option>
                    <option value="complete">Complete enough</option>
                    <option value="incomplete">Incomplete</option>
                    <option value="not_started">Not started</option>
                </select>
            </div>
        </div>

        @if ($hasFilters)
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <span class="text-xs text-gray-500">Active filters</span>
                <button type="button" wire:click="clearFilters" class="text-xs font-medium text-brand-600 hover:underline">Clear all</button>
            </div>
        @endif

        <div class="mt-3">
            <label class="inline-flex items-center gap-2 text-xs text-gray-500">
                <input type="checkbox" wire:model.live="showOptionalColumns" class="rounded border-gray-300 text-brand-500 focus:ring-brand-500" />
                Show optional columns
            </label>
        </div>
    </div>

    @if ($allCount === 0)
        <div class="rounded-xl bg-white p-8 text-center ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">No brands yet</h2>
            <p class="mx-auto mt-2 max-w-md text-sm text-gray-500 dark:text-gray-400">Add a brand under a customer to start organizing digital assets and operations.</p>
            <a href="{{ route('operator.brand.create') }}" wire:navigate class="mt-5 inline-flex rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">Add brand</a>
        </div>
    @elseif (count($brands) === 0)
        <div class="rounded-xl bg-white p-8 text-center ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">No brands match these filters.</h2>
            <button type="button" wire:click="clearFilters" class="mt-5 inline-flex rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">Clear filters</button>
        </div>
    @else
        <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-white/[0.02]">
                    <tr>
                        <th class="px-4 py-3 text-left"><button type="button" wire:click="sortBy('name')" class="text-xs font-medium uppercase text-gray-400">Brand</button></th>
                        <th class="hidden px-4 py-3 text-left md:table-cell"><button type="button" wire:click="sortBy('sector')" class="text-xs font-medium uppercase text-gray-400">Sector</button></th>
                        <th class="hidden px-4 py-3 text-left lg:table-cell"><span class="text-xs font-medium uppercase text-gray-400">Primary market</span></th>
                        <th class="px-4 py-3 text-left"><button type="button" wire:click="sortBy('assets')" class="text-xs font-medium uppercase text-gray-400">Digital estate</button></th>
                        <th class="px-4 py-3 text-left"><button type="button" wire:click="sortBy('findings')" class="text-xs font-medium uppercase text-gray-400">Open findings</button></th>
                        <th class="px-4 py-3 text-left"><button type="button" wire:click="sortBy('tasks')" class="text-xs font-medium uppercase text-gray-400">Open tasks</button></th>
                        @if ($showOptionalColumns)
                            <th class="hidden px-4 py-3 text-left xl:table-cell"><span class="text-xs font-medium uppercase text-gray-400">Context</span></th>
                            <th class="hidden px-4 py-3 text-left xl:table-cell"><span class="text-xs font-medium uppercase text-gray-400">Open recs</span></th>
                        @endif
                        <th class="hidden px-4 py-3 text-left xl:table-cell"><span class="text-xs font-medium uppercase text-gray-400">Team</span></th>
                        <th class="px-4 py-3 text-left"><span class="text-xs font-medium uppercase text-gray-400">Attention</span></th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($brands as $brand)
                        @php
                            $href = route('operator.brand', ['brand' => $brand['id']]);
                        @endphp
                        <tr
                            wire:key="brand-{{ $brand['id'] }}"
                            class="cursor-pointer hover:bg-gray-50 focus-visible:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-500/40 dark:hover:bg-white/[0.02]"
                            tabindex="0"
                            role="link"
                            data-href="{{ $href }}"
                            onclick="if (event.target.closest('a,button')) return; window.Livewire.navigate(this.dataset.href)"
                            onkeydown="if ((event.key === 'Enter' || event.key === ' ') && !event.target.closest('a,button')) { event.preventDefault(); window.Livewire.navigate(this.dataset.href); }"
                        >
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-500/10 text-xs font-semibold text-brand-600 dark:text-brand-400" aria-hidden="true">{{ $brand['initials'] }}</span>
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium text-gray-800 dark:text-white/90">{{ $brand['name'] }}</p>
                                        <p class="truncate text-xs text-gray-500">{{ $brand['customer_name'] }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="hidden px-4 py-3 text-sm text-gray-500 md:table-cell">{{ $brand['sector_label'] }}</td>
                            <td class="hidden px-4 py-3 text-sm text-gray-500 lg:table-cell">
                                {{ $brand['primary_market_label'] !== '' ? $brand['primary_market_label'] : '—' }}
                                @if (($brand['extra_markets'] ?? 0) > 0)
                                    <span class="text-xs text-gray-400">+{{ $brand['extra_markets'] }} markets</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                                <span>{{ $brand['assets_count'] }} assets</span>
                                <span class="block text-xs text-gray-500">{{ $brand['connected_assets'] }} connected</span>
                            </td>
                            <td class="px-4 py-3 text-sm tabular-nums text-gray-700 dark:text-gray-300">{{ $brand['open_findings'] }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                                <span class="tabular-nums">{{ $brand['open_tasks'] }}</span>
                                @if (($brand['overdue_tasks'] ?? 0) > 0)
                                    <span class="text-xs text-error-600 dark:text-error-400">· {{ $brand['overdue_tasks'] }} overdue</span>
                                @endif
                            </td>
                            @if ($showOptionalColumns)
                                <td class="hidden px-4 py-3 text-sm text-gray-500 xl:table-cell">{{ $brand['context_completed'] }}/{{ $brand['context_total'] }}</td>
                                <td class="hidden px-4 py-3 text-sm text-gray-500 xl:table-cell">{{ $brand['open_recommendations'] ?? '—' }}</td>
                            @endif
                            <td class="hidden px-4 py-3 xl:table-cell">
                                <div class="flex -space-x-1.5" title="{{ collect($brand['responsible'] ?? [])->pluck('name')->implode(', ') }}">
                                    @forelse (collect($brand['responsible'] ?? [])->take(3) as $user)
                                        <span class="flex h-7 w-7 items-center justify-center rounded-full border-2 border-white bg-gray-100 text-[10px] font-semibold text-gray-700 dark:border-gray-900 dark:bg-gray-800 dark:text-gray-200">{{ $user['initials'] }}</span>
                                    @empty
                                        <span class="text-xs text-gray-400">—</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                @if ($brand['needs_attention'])
                                    <span class="inline-flex rounded-full bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700 dark:bg-warning-500/15 dark:text-warning-400">Needs attention</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ $href }}" wire:navigate onclick="event.stopPropagation()"
                                    class="inline-flex rounded-lg px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Open</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
