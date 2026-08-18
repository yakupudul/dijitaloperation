<div class="space-y-5">
    @include('livewire.demo.partials.flash')
    @include('livewire.demo.sales.partials.sales-subnav', ['current' => 'prospects'])

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.nav.groups.sales') }}</p>
            <h1 class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ __('operator.nav.prospects') }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.prospects.list_subtitle') }}</p>
        </div>
        <a href="{{ route('operator.prospect.create') }}" wire:navigate
            class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">
            {{ __('operator.prospects.new_prospect') }}
        </a>
    </div>

    <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div class="min-w-0 flex-1">
                <label class="mb-1 block text-xs font-medium text-gray-500" for="prospect-search">{{ __('operator.forms.search') }}</label>
                <input id="prospect-search" wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('operator.prospects.search_placeholder') }}"
                    class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
            </div>
            <div class="flex flex-wrap gap-2">
                <select wire:model.live="status" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="{{ __('operator.forms.status') }}">
                    <option value="">{{ __('operator.forms.status') }}</option>
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="source" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="{{ __('operator.prospects.fields.source') }}">
                    <option value="">{{ __('operator.prospects.fields.source') }}</option>
                    @foreach ($sourceOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="identity" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="{{ __('operator.prospects.identity_label') }}">
                    <option value="">{{ __('operator.prospects.identity_label') }}</option>
                    @foreach ($identityOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @if ($ownerOptions !== [])
                    <select wire:model.live="owner" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" aria-label="{{ __('operator.prospects.fields.owner') }}">
                        <option value="">{{ __('operator.prospects.fields.owner') }}</option>
                        @foreach ($ownerOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                @endif
            </div>
        </div>

        @if ($hasFilters)
            <div class="mt-3">
                <button type="button" wire:click="clearFilters" class="text-xs font-medium text-brand-600 hover:underline">{{ __('operator.forms.clear_all') }}</button>
            </div>
        @endif
    </div>

    @if ($allCount === 0)
        <x-ta.card>
            @include('livewire.demo.partials.empty-panel', [
                'title' => __('operator.prospects.empty_title'),
                'body' => __('operator.prospects.empty_body'),
                'actionLabel' => __('operator.prospects.new_prospect'),
                'actionUrl' => route('operator.prospect.create'),
            ])
        </x-ta.card>
    @else
        <div class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-white/5">
                        <tr>
                            <th class="px-4 py-3">{{ __('operator.prospects.fields.company_name') }}</th>
                            <th class="px-4 py-3">{{ __('operator.forms.status') }}</th>
                            <th class="px-4 py-3">{{ __('operator.prospects.identity_label') }}</th>
                            <th class="px-4 py-3">{{ __('operator.prospects.fields.source') }}</th>
                            <th class="px-4 py-3">{{ __('operator.prospects.fields.owner') }}</th>
                            <th class="px-4 py-3">{{ __('operator.prospects.research_label') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($rows as $row)
                            <tr wire:key="prospect-{{ $row['id'] }}">
                                <td class="px-4 py-3 font-medium text-gray-800 dark:text-white/90">{{ $row['company_name'] }}</td>
                                <td class="px-4 py-3"><x-ta.badge color="light" size="sm">{{ $row['status_label'] }}</x-ta.badge></td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $row['identity_status_label'] }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $row['source_label'] }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $row['owner_name'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $row['research_status_label'] }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('operator.prospect', ['prospectId' => $row['id']]) }}" wire:navigate class="text-sm font-medium text-brand-600 hover:underline">{{ __('operator.actions.open') }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-500">{{ __('operator.forms.no_results') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
