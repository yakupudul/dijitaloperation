<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.forms.portfolio') }}</p>
            <h1 class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ __('operator.nav.brands') }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.forms.brands_subtitle') }}</p>
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
                <label class="mb-1 block text-xs font-medium text-gray-500" for="brand-search">{{ __('operator.forms.search') }}</label>
                <input id="brand-search" wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('operator.forms.search_brands') }}"
                    class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
            </div>
            <div class="flex flex-wrap gap-2">
                <select wire:model.live="customer" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="{{ __('operator.forms.customer') }}">
                    <option value="">{{ __('operator.forms.customer') }}</option>
                    @foreach ($customerOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="sector" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="{{ __('operator.forms.sector') }}">
                    <option value="">{{ __('operator.forms.sector') }}</option>
                    @foreach ($sectorOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="primary_market" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="{{ __('operator.forms.primary_market') }}">
                    <option value="">{{ __('operator.forms.primary_market') }}</option>
                    @foreach ($countryOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="asset_type" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="{{ __('operator.forms.digital_asset_type') }}">
                    <option value="">{{ __('operator.forms.digital_asset_type') }}</option>
                    @foreach ($assetTypeOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="responsible" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="{{ __('operator.forms.responsible_team') }}">
                    <option value="">{{ __('operator.forms.responsible') }}</option>
                    @foreach ($teamOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="attention" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="{{ __('operator.forms.attention') }}">
                    <option value="">{{ __('operator.forms.attention') }}</option>
                    <option value="needs_attention">{{ __('operator.forms.needs_attention') }}</option>
                    <option value="clear">{{ __('operator.forms.no_immediate_attention') }}</option>
                </select>
                <select wire:model.live="context" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="{{ __('operator.forms.business_context') }}">
                    <option value="">{{ __('operator.forms.business_context') }}</option>
                    <option value="complete">{{ __('operator.forms.complete_enough') }}</option>
                    <option value="incomplete">{{ __('operator.forms.incomplete') }}</option>
                    <option value="not_started">{{ __('operator.forms.not_started') }}</option>
                </select>
            </div>
        </div>

        @if ($hasFilters)
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <span class="text-xs text-gray-500">{{ __('operator.forms.active_filters') }}</span>
                <button type="button" wire:click="clearFilters" class="text-xs font-medium text-brand-600 hover:underline">{{ __('operator.forms.clear_all') }}</button>
            </div>
        @endif

        <div class="mt-3">
            <label class="inline-flex items-center gap-2 text-xs text-gray-500">
                <input type="checkbox" wire:model.live="showOptionalColumns" class="rounded border-gray-300 text-brand-500 focus:ring-brand-500" />
                {{ __('operator.forms.show_optional_columns') }}
            </label>
        </div>
    </div>

    @if ($allCount === 0)
        <div class="rounded-xl bg-white p-8 text-center ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ __('operator.forms.no_brands_yet') }}</h2>
            <p class="mx-auto mt-2 max-w-md text-sm text-gray-500 dark:text-gray-400">{{ __('operator.forms.no_brands_yet_help') }}</p>
            <a href="{{ route('operator.brand.create') }}" wire:navigate class="mt-5 inline-flex rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">{{ __('operator.forms.add_brand') }}</a>
        </div>
    @elseif (count($brands) === 0)
        <div class="rounded-xl bg-white p-8 text-center ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ __('operator.forms.no_brands_match') }}</h2>
            <button type="button" wire:click="clearFilters" class="mt-5 inline-flex rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">{{ __('operator.forms.clear_filters') }}</button>
        </div>
    @else
        <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-white/[0.02]">
                    <tr>
                        <th class="px-4 py-3 text-left"><button type="button" wire:click="sortBy('name')" class="text-xs font-medium uppercase text-gray-400">{{ __('operator.directory.brand') }}</button></th>
                        <th class="hidden px-4 py-3 text-left md:table-cell"><button type="button" wire:click="sortBy('sector')" class="text-xs font-medium uppercase text-gray-400">{{ __('operator.directory.sector') }}</button></th>
                        <th class="hidden px-4 py-3 text-left lg:table-cell"><span class="text-xs font-medium uppercase text-gray-400">{{ __('operator.directory.primary_market') }}</span></th>
                        <th class="px-4 py-3 text-left"><button type="button" wire:click="sortBy('assets')" class="text-xs font-medium uppercase text-gray-400">{{ __('operator.directory.digital_estate') }}</button></th>
                        <th class="px-4 py-3 text-left"><button type="button" wire:click="sortBy('findings')" class="text-xs font-medium uppercase text-gray-400">{{ __('operator.directory.open_findings') }}</button></th>
                        <th class="px-4 py-3 text-left"><button type="button" wire:click="sortBy('tasks')" class="text-xs font-medium uppercase text-gray-400">{{ __('operator.directory.open_tasks') }}</button></th>
                        @if ($showOptionalColumns)
                            <th class="hidden px-4 py-3 text-left xl:table-cell"><span class="text-xs font-medium uppercase text-gray-400">{{ __('operator.directory.context') }}</span></th>
                            <th class="hidden px-4 py-3 text-left xl:table-cell"><span class="text-xs font-medium uppercase text-gray-400">{{ __('operator.forms.open_recs') }}</span></th>
                        @endif
                        <th class="hidden px-4 py-3 text-left xl:table-cell"><span class="text-xs font-medium uppercase text-gray-400">{{ __('operator.directory.team') }}</span></th>
                        <th class="px-4 py-3 text-left"><span class="text-xs font-medium uppercase text-gray-400">{{ __('operator.directory.attention') }}</span></th>
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
                                    <span class="text-xs text-gray-400">{{ __('operator.forms.markets_extra', ['count' => $brand['extra_markets']]) }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                                <span>{{ __('operator.forms.assets_count', ['count' => $brand['assets_count']]) }}</span>
                                <span class="block text-xs text-gray-500">{{ __('operator.forms.connected_count', ['count' => $brand['connected_assets']]) }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm tabular-nums text-gray-700 dark:text-gray-300">{{ $brand['open_findings'] }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                                <span class="tabular-nums">{{ $brand['open_tasks'] }}</span>
                                @if (($brand['overdue_tasks'] ?? 0) > 0)
                                    <span class="text-xs text-error-600 dark:text-error-400">· {{ $brand['overdue_tasks'] }} {{ __('operator.forms.overdue') }}</span>
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
                                    <span class="inline-flex rounded-full bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700 dark:bg-warning-500/15 dark:text-warning-400">{{ __('operator.forms.needs_attention') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ $href }}" wire:navigate onclick="event.stopPropagation()"
                                    class="inline-flex rounded-lg px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">{{ __('operator.actions.open') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
