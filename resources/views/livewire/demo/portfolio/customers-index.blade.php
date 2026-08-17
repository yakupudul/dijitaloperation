<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.forms.portfolio') }}</p>
            <h1 class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ __('operator.nav.customers') }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.forms.customers_subtitle') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('operator.setup', ['entry' => 'customer']) }}" wire:navigate
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">
                {{ __('operator.portfolio.new_customer_setup') }}
            </a>
            <a href="{{ route('operator.customer.create') }}" wire:navigate
                class="inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">
                {{ __('operator.portfolio.quick_add') }}
            </a>
        </div>
    </div>

    <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div class="min-w-0 flex-1">
                <label class="mb-1 block text-xs font-medium text-gray-500" for="customer-search">{{ __('operator.forms.search') }}</label>
                <input id="customer-search" wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('operator.forms.search_customers') }}"
                    class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
            </div>
            <div class="flex flex-wrap gap-2">
                <select wire:model.live="status" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="{{ __('operator.forms.status') }}">
                    <option value="">{{ __('operator.forms.status') }}</option>
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="type" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="{{ __('operator.forms.type') }}">
                    <option value="">{{ __('operator.forms.type') }}</option>
                    @foreach ($typeOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="industry" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="{{ __('operator.forms.industry') }}">
                    <option value="">{{ __('operator.forms.industry') }}</option>
                    @foreach ($industryOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="hq_country" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="{{ __('operator.forms.hq_country') }}">
                    <option value="">{{ __('operator.forms.hq_country') }}</option>
                    @foreach ($countryOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="responsible" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="{{ __('operator.forms.responsible_team') }}">
                    <option value="">{{ __('operator.forms.responsible') }}</option>
                    @foreach ($teamOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="service" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="{{ __('operator.forms.service') }}">
                    <option value="">{{ __('operator.forms.service') }}</option>
                    @foreach ($serviceOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="attention" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="{{ __('operator.forms.attention') }}">
                    <option value="">{{ __('operator.forms.attention') }}</option>
                    <option value="needs_attention">{{ __('operator.forms.needs_attention') }}</option>
                    <option value="clear">{{ __('operator.forms.clear') }}</option>
                </select>
            </div>
        </div>

        @if ($hasFilters)
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <span class="text-xs text-gray-500">{{ __('operator.forms.active_filters') }}</span>
                @if ($search !== '') <x-ta.badge color="light" size="sm">{{ __('operator.forms.search') }}: {{ $search }}</x-ta.badge> @endif
                @if ($status !== '') <x-ta.badge color="light" size="sm">{{ $statusOptions[$status] ?? $status }}</x-ta.badge> @endif
                @if ($type !== '') <x-ta.badge color="light" size="sm">{{ $typeOptions[$type] ?? $type }}</x-ta.badge> @endif
                @if ($industry !== '') <x-ta.badge color="light" size="sm">{{ $industryOptions[$industry] ?? $industry }}</x-ta.badge> @endif
                @if ($hq_country !== '') <x-ta.badge color="light" size="sm">{{ $countryOptions[$hq_country] ?? $hq_country }}</x-ta.badge> @endif
                @if ($responsible !== '') <x-ta.badge color="light" size="sm">{{ $teamOptions[$responsible] ?? $responsible }}</x-ta.badge> @endif
                @if ($service !== '') <x-ta.badge color="light" size="sm">{{ $serviceOptions[$service] ?? $service }}</x-ta.badge> @endif
                @if ($attention !== '') <x-ta.badge color="light" size="sm">{{ $attention === 'needs_attention' ? __('operator.forms.needs_attention') : __('operator.forms.clear') }}</x-ta.badge> @endif
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
        <x-ta.card>
            @include('livewire.demo.partials.empty-panel', [
                'title' => __('operator.forms.empty_customers_title'),
                'message' => __('operator.forms.empty_customers'),
            ])
            <div class="mt-4">
                <a href="{{ route('operator.customer.create') }}" wire:navigate class="inline-flex rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">{{ __('operator.forms.empty_customers') }}</a>
            </div>
        </x-ta.card>
    @elseif (count($customers) === 0)
        <x-ta.card>
            @include('livewire.demo.partials.empty-panel', [
                'title' => __('operator.forms.no_match_filters'),
                'message' => __('operator.forms.adjust_filters'),
            ])
            <div class="mt-4">
                <button type="button" wire:click="clearFilters" class="inline-flex rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">{{ __('operator.forms.clear_filters') }}</button>
            </div>
        </x-ta.card>
    @else
        <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-white/[0.02]">
                    <tr>
                        <th class="px-4 py-3 text-left"><button type="button" wire:click="sortBy('name')" class="text-xs font-medium uppercase text-gray-400">{{ __('operator.directory.customer') }}</button></th>
                        <th class="hidden px-4 py-3 text-left md:table-cell"><button type="button" wire:click="sortBy('industry')" class="text-xs font-medium uppercase text-gray-400">{{ __('operator.directory.industry') }}</button></th>
                        <th class="hidden px-4 py-3 text-left lg:table-cell"> <span class="text-xs font-medium uppercase text-gray-400">{{ __('operator.directory.hq') }}</span></th>
                        <th class="px-4 py-3 text-left"><button type="button" wire:click="sortBy('brands')" class="text-xs font-medium uppercase text-gray-400">{{ __('operator.directory.brands') }}</button></th>
                        <th class="px-4 py-3 text-left"><button type="button" wire:click="sortBy('findings')" class="text-xs font-medium uppercase text-gray-400">{{ __('operator.directory.open_findings') }}</button></th>
                        <th class="px-4 py-3 text-left"><button type="button" wire:click="sortBy('tasks')" class="text-xs font-medium uppercase text-gray-400">{{ __('operator.directory.open_tasks') }}</button></th>
                        @if ($showOptionalColumns)
                            <th class="hidden px-4 py-3 text-left xl:table-cell"><span class="text-xs font-medium uppercase text-gray-400">{{ __('operator.directory.team') }}</span></th>
                            <th class="hidden px-4 py-3 text-left xl:table-cell"><button type="button" wire:click="sortBy('service_started')" class="text-xs font-medium uppercase text-gray-400">{{ __('operator.directory.service_start') }}</button></th>
                            <th class="hidden px-4 py-3 text-left xl:table-cell"><span class="text-xs font-medium uppercase text-gray-400">{{ __('operator.directory.assets') }}</span></th>
                        @endif
                        <th class="px-4 py-3 text-left"><span class="text-xs font-medium uppercase text-gray-400">{{ __('operator.directory.status') }}</span></th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($customers as $customer)
                        <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]" wire:key="customer-{{ $customer['id'] }}">
                            <td class="px-4 py-3">
                                <a href="{{ route('operator.customer', ['customerId' => $customer['id']]) }}" wire:navigate class="block">
                                    <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $customer['name'] }}</p>
                                    <p class="text-xs text-gray-500">
                                        {{ collect([($customer['legal_name'] ?? null), $customer['type_label'] ?? null])->filter()->implode(' · ') }}
                                    </p>
                                </a>
                            </td>
                            <td class="hidden px-4 py-3 text-sm text-gray-500 md:table-cell">{{ $customer['industry_label'] }}</td>
                            <td class="hidden px-4 py-3 text-sm text-gray-500 lg:table-cell">{{ $customer['hq_display'] }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{{ $customer['brands_count'] }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{{ $customer['open_findings'] }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                                {{ $customer['open_tasks'] }}
                                @if (($customer['overdue_tasks'] ?? 0) > 0)
                                    <span class="text-xs text-amber-600 dark:text-amber-400">· {{ $customer['overdue_tasks'] }} {{ __('operator.forms.overdue') }}</span>
                                @endif
                            </td>
                            @if ($showOptionalColumns)
                                <td class="hidden px-4 py-3 text-sm text-gray-500 xl:table-cell">{{ implode(', ', $customer['responsible_labels'] ?? []) ?: '—' }}</td>
                                <td class="hidden px-4 py-3 text-sm text-gray-500 xl:table-cell">{{ $customer['service_started_at'] ?? '—' }}</td>
                                <td class="hidden px-4 py-3 text-sm text-gray-500 xl:table-cell">{{ $customer['digital_assets_count'] ?? 0 }}</td>
                            @endif
                            <td class="px-4 py-3">
                                <x-ta.badge :color="match($customer['status'] ?? '') { 'active' => 'success', 'inactive' => 'warning', 'archived' => 'light', default => 'light' }" size="sm">
                                    {{ $customer['status_label'] }}
                                </x-ta.badge>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('operator.customer', ['customerId' => $customer['id']]) }}" wire:navigate
                                    class="inline-flex rounded-lg px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">{{ __('operator.actions.open') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
