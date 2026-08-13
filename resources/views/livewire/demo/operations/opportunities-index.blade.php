<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => __('operator.nav.groups.operations'),
        'title' => __('operator.nav.opportunities'),
        'subtitle' => __('operator.opportunities.subtitle'),
    ])

    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.opportunities.glance.open') }}</p>
            <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $glance['open'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.opportunities.glance.new') }}</p>
            <p class="mt-1 text-2xl font-bold text-brand-600 dark:text-brand-400">{{ $glance['new'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.opportunities.glance.linked_primary') }}</p>
            <p class="mt-1 text-2xl font-bold text-warning-600 dark:text-warning-400">{{ $glance['linked_primary'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.opportunities.glance.converted') }}</p>
            <p class="mt-1 text-2xl font-bold text-success-600 dark:text-success-400">{{ $glance['converted'] }}</p>
        </div>
    </div>

    <div class="flex flex-wrap gap-2" role="group" aria-label="Quick views">
        @foreach ([
            'open' => __('operator.opportunities.views.open'),
            'new' => __('operator.opportunities.views.new'),
            'reviewing' => __('operator.opportunities.views.reviewing'),
            'deferred' => __('operator.opportunities.views.deferred'),
            'converted' => __('operator.opportunities.views.converted'),
            'dismissed' => __('operator.opportunities.views.dismissed'),
            'all' => __('operator.opportunities.views.all'),
        ] as $key => $label)
            <button type="button" wire:click="setView('{{ $key }}')"
                @class([
                    'rounded-lg px-3 py-2 text-sm font-medium transition',
                    'bg-brand-500 text-white' => $view === $key,
                    'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $view !== $key,
                ])>{{ $label }}</button>
        @endforeach
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <label class="block text-sm">
            <span class="text-gray-500">{{ __('operator.opportunities.filters.brand') }}</span>
            <select wire:model.live="brand" class="mt-1 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                <option value="">{{ __('operator.opportunities.filters.all_brands') }}</option>
                @foreach ($brandOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-sm">
            <span class="text-gray-500">{{ __('operator.opportunities.filters.customer') }}</span>
            <select wire:model.live="customer" class="mt-1 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                <option value="">{{ __('operator.opportunities.filters.all_customers') }}</option>
                @foreach ($customerOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-sm">
            <span class="text-gray-500">{{ __('operator.opportunities.filters.asset_type') }}</span>
            <select wire:model.live="asset_type" class="mt-1 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                <option value="">{{ __('operator.opportunities.filters.all_assets') }}</option>
                @foreach (['google_ads', 'meta_ads', 'website', 'gbp', 'ga4', 'gsc'] as $type)
                    <option value="{{ $type }}">{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-sm">
            <span class="text-gray-500">{{ __('operator.opportunities.filters.category') }}</span>
            <select wire:model.live="category" class="mt-1 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                <option value="">{{ __('operator.opportunities.filters.all_categories') }}</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat }}">{{ ucfirst(str_replace('_', ' ', $cat)) }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-sm">
            <span class="text-gray-500">{{ __('operator.opportunities.filters.service') }}</span>
            <select wire:model.live="service" class="mt-1 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                <option value="">{{ __('operator.opportunities.filters.all_services') }}</option>
                @foreach ($serviceOptions as $code => $label)
                    <option value="{{ $code }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-sm min-w-[12rem]">
            <span class="text-gray-500">{{ __('operator.opportunities.filters.search') }}</span>
            <input wire:model.live.debounce.300ms="q" type="search" placeholder="{{ __('operator.opportunities.filters.search_placeholder') }}"
                class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
        </label>
        @if ($brand !== '' || $customer !== '' || $asset_type !== '' || $category !== '' || $service !== '' || $q !== '')
            <button type="button" wire:click="clearFilters" class="rounded-lg px-3 py-2 text-sm font-medium text-gray-600 ring-1 ring-inset ring-gray-200 dark:text-gray-300 dark:ring-gray-700">
                {{ __('operator.opportunities.filters.clear') }}
            </button>
        @endif
    </div>

    <div class="grid gap-4 lg:grid-cols-12">
        <div class="space-y-2 lg:col-span-5">
            @forelse ($opportunities as $opp)
                <button type="button" wire:click="selectOpportunity('{{ $opp['id'] }}')"
                    @class([
                        'w-full rounded-xl bg-white p-3 text-left ring-1 ring-inset transition dark:bg-gray-900',
                        'ring-brand-400 dark:ring-brand-500/50' => ($selected['id'] ?? '') === $opp['id'],
                        'ring-gray-200 dark:ring-gray-800 hover:bg-gray-50 dark:hover:bg-white/[0.03]' => ($selected['id'] ?? '') !== $opp['id'],
                    ])>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($opp['is_new'] ?? false)
                            <x-ta.badge color="info" size="sm">{{ __('operator.opportunities.badges.new') }}</x-ta.badge>
                        @endif
                        <x-ta.badge color="light" size="sm">{{ ucfirst(str_replace('_', ' ', $opp['category'] ?? '')) }}</x-ta.badge>
                        <span class="text-xs text-gray-400">{{ $opp['status'] }}</span>
                    </div>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $opp['title'] }}</p>
                    <p class="mt-0.5 text-xs text-gray-500">{{ $opp['brand_name'] }} · {{ $opp['service_label'] }} · {{ $opp['goal_title'] }}</p>
                </button>
            @empty
                <p class="text-sm text-gray-500">{{ __('operator.opportunities.empty') }}</p>
            @endforelse
        </div>

        <div class="lg:col-span-7">
            @if ($selected)
                <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $selected['title'] }}</h2>
                            <p class="mt-1 text-sm text-gray-500">{{ $selected['brand_name'] }} · {{ $selected['observed_at'] }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if (($selected['status'] ?? '') === 'open')
                                <button type="button" wire:click="review('{{ $selected['id'] }}')" class="rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">{{ __('operator.opportunities.actions.review') }}</button>
                                <button type="button" wire:click="defer('{{ $selected['id'] }}')" class="rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">{{ __('operator.opportunities.actions.defer') }}</button>
                                <button type="button" wire:click="dismiss('{{ $selected['id'] }}')" class="rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">{{ __('operator.opportunities.actions.dismiss') }}</button>
                            @endif
                            @if (! in_array($selected['status'] ?? '', ['converted', 'dismissed'], true))
                                <button type="button" wire:click="createRecommendation('{{ $selected['id'] }}')" class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-600">{{ __('operator.opportunities.actions.create_recommendation') }}</button>
                            @endif
                        </div>
                    </div>

                    <x-demo.commercial-context
                        class="mt-4"
                        :service="$selected['service_label'] ?? null"
                        :goal="$selected['goal_title'] ?? null"
                        :offering="$selected['offering'] ?? null"
                    />

                    <section class="mt-4">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('operator.opportunities.detail.what') }}</h3>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $selected['what'] }}</p>
                    </section>
                    <section class="mt-4">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('operator.opportunities.detail.why') }}</h3>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $selected['why'] }}</p>
                    </section>
                    <section class="mt-4">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('operator.opportunities.detail.why_matters') }}</h3>
                        <ul class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($selected['why_matters'] ?? [] as $reason)
                                <li><x-ta.badge color="light" size="sm">{{ $reason }}</x-ta.badge></li>
                            @endforeach
                        </ul>
                    </section>
                    <section class="mt-4">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('operator.opportunities.detail.evidence') }}</h3>
                        <ul class="mt-2 space-y-2">
                            @foreach ($selected['evidence'] ?? [] as $ev)
                                <li class="rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/[0.03]">
                                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $ev['source'] }}</span>
                                    <span class="text-xs text-gray-400"> · {{ $ev['provenance'] }}</span>
                                    <p class="mt-0.5 text-gray-600 dark:text-gray-300">{{ $ev['summary'] }}</p>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                    <section class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('operator.opportunities.detail.known') }}</h3>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $selected['known'] }}</p>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('operator.opportunities.detail.unknown') }}</h3>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $selected['unknown'] }}</p>
                        </div>
                    </section>
                    <section class="mt-4">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('operator.opportunities.detail.assets') }}</h3>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($selected['asset_types'] ?? [] as $type)
                                <x-demo.digital-asset-mark :type="$type" size="sm" />
                            @endforeach
                        </div>
                    </section>
                </div>
            @else
                <div class="rounded-xl bg-white p-6 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <p class="text-sm text-gray-500">{{ __('operator.opportunities.select_prompt') }}</p>
                </div>
            @endif
        </div>
    </div>
</div>
