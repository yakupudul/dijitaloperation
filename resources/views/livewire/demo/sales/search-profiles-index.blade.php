<div class="space-y-5">
    @include('livewire.demo.partials.flash')
    @include('livewire.demo.sales.partials.sales-subnav', ['current' => 'search-profiles'])

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.nav.groups.sales') }}</p>
            <h1 class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ __('operator.nav.search_profiles') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ __('operator.sales_intent.profiles_subtitle') }}</p>
        </div>
        <a href="{{ route('operator.search-profile.create') }}" wire:navigate
            class="inline-flex items-center rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white">{{ __('operator.sales_intent.new_profile') }}</a>
    </div>

    <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('operator.sales_intent.search_profiles_placeholder') }}"
        class="w-full max-w-md rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900" />

    @if ($rows === [])
        <x-ta.card>
            <p class="text-sm text-gray-600">{{ __('operator.sales_intent.profiles_empty') }}</p>
        </x-ta.card>
    @else
        <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <table class="min-w-full text-left text-sm">
                <thead>
                    <tr class="text-xs uppercase text-gray-400">
                        <th class="px-4 py-3">{{ __('operator.sales_intent.fields.name') }}</th>
                        <th class="px-4 py-3">{{ __('operator.sales_intent.fields.service') }}</th>
                        <th class="px-4 py-3">{{ __('operator.sales_intent.fields.active') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-t border-gray-100 dark:border-gray-700">
                            <td class="px-4 py-3"><a href="{{ route('operator.search-profile', ['profileId' => $row['id']]) }}" class="font-medium text-brand-700 hover:underline">{{ $row['name'] }}</a></td>
                            <td class="px-4 py-3">{{ $row['service'] }}</td>
                            <td class="px-4 py-3">{{ $row['active'] ? __('operator.sales_intent.active') : __('operator.sales_intent.inactive') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
