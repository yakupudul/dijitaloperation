<div class="mx-auto max-w-6xl space-y-6">
    <header>
        <a href="{{ $backUrl }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-brand-600 dark:text-gray-400">← {{ __('brand-form.back') }}</a>
        <h1 class="mt-3 text-2xl font-bold text-gray-900 dark:text-white">{{ $pageTitle }}</h1>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('brand-form.subtitle') }}</p>
    </header>

    <form wire:submit="save" class="space-y-6">
        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
                <p class="font-semibold">{{ __('brand-form.errors') }}</p>
                <ul class="mt-2 list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <x-ta.form.section :title="__('brand-form.identity')">
            <div class="grid gap-5 md:grid-cols-2">
                <x-ta.form.field :label="__('brand-form.customer')" :required="true" :error="$errors->first('customer_id')">
                    @if ($customerLocked && $customerName)
                        <div class="rounded-lg bg-gray-50 px-3 py-2.5 text-sm text-gray-700 dark:bg-white/5 dark:text-gray-200">{{ $customerName }}</div>
                    @else
                        <x-ta.form.select wire:model="customer_id" :options="$customerOptions" :placeholder="__('brand-form.choose_customer')" :nullable="false" />
                    @endif
                </x-ta.form.field>
                <x-ta.form.field :label="__('brand-form.name')" :required="true" :error="$errors->first('name')">
                    <input aria-label="{{ __('brand-form.name') }}" wire:model="name" type="text" maxlength="120" autocomplete="organization" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white" />
                </x-ta.form.field>
            </div>
        </x-ta.form.section>

        <x-ta.form.section :title="__('brand-form.sectors_services')">
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">{{ __('brand-form.sectors_help') }}</p>
            <x-ta.form.field :label="__('brand-form.sectors')" :error="$errors->first('selected_sector_codes')">
                <x-ta.form.multi-select id="brand-sectors" wire:model.live="selected_sector_codes" :options="$industryOptions" :placeholder="__('brand-form.choose_sectors')" />
            </x-ta.form.field>

            <div class="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-5 dark:border-gray-800">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('brand-form.services') }} <span class="ml-2 rounded-full bg-brand-50 px-2 py-1 text-xs text-brand-600 dark:bg-brand-500/10">{{ count($selected_service_catalog_ids) }} {{ __('brand-form.selected') }}</span></h2>
                <a href="{{ route('operator.library.services') }}" target="_blank" rel="noopener" class="text-sm font-medium text-brand-600">{{ __('brand-form.manage_library') }} ↗</a>
            </div>
            <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                <input aria-label="{{ __('brand-form.search_services') }}" wire:model.live.debounce.300ms="service_search" type="search" placeholder="{{ __('brand-form.search_services') }}" class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white" />
                <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300"><input wire:model.live="only_selected_services" type="checkbox" class="rounded border-gray-300 text-brand-500" /> {{ __('brand-form.only_selected') }}</label>
            </div>
            <p class="mt-3 text-xs text-gray-500">{{ __('brand-form.priority_help') }}</p>

            @if ($outOfScopeServices !== [])
                <div role="alert" class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-500/10">
                    <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">{{ __('brand-form.out_of_scope') }}</p>
                    <p class="mt-1 text-sm text-amber-800 dark:text-amber-300">{{ __('brand-form.scope_help') }}</p>
                    <div class="mt-3 space-y-2">
                        @foreach ($outOfScopeServices as $id => $service)
                            <div wire:key="out-service-{{ $id }}" class="flex items-center justify-between gap-3 text-sm">
                                <span class="text-amber-900 dark:text-amber-200">{{ $service['label'] }} @if ($service['sector'])<span class="text-xs">· {{ $industryOptions[$service['sector']] ?? $service['sector'] }}</span>@endif</span>
                                <button type="button" wire:click="removeSelectedService('{{ $id }}')" class="shrink-0 rounded-lg px-3 py-1.5 font-medium text-amber-900 ring-1 ring-amber-300 dark:text-amber-200">{{ __('brand-form.remove') }}</button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="mt-4 max-h-[480px] overflow-y-auto rounded-xl border border-gray-200 dark:border-gray-700">
                @forelse ($serviceOptions as $id => $service)
                    <div wire:key="catalog-service-{{ $id }}" class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/5">
                        <label class="flex min-w-0 flex-1 cursor-pointer items-center gap-3">
                            <input wire:model.live="selected_service_catalog_ids" value="{{ $id }}" type="checkbox" class="rounded border-gray-300 text-brand-500" />
                            <span class="min-w-0"><span class="block text-sm font-medium text-gray-900 dark:text-white">{{ $service['label'] }}</span><span class="block text-xs text-gray-500">{{ $industryOptions[$service['sector']] ?? $service['sector'] }}</span></span>
                        </label>
                        <label class="flex items-center gap-2 text-xs text-gray-500">
                            <input wire:model.live="priority_service_catalog_ids" value="{{ $id }}" type="checkbox" @disabled(! in_array((string) $id, $selected_service_catalog_ids, true)) class="rounded border-gray-300 text-brand-500 disabled:opacity-30" /> {{ __('brand-form.priority') }}
                        </label>
                    </div>
                @empty
                    <div class="px-6 py-10 text-center text-sm text-gray-500">{{ __($selected_sector_codes === [] ? 'brand-form.select_sector_first' : 'brand-form.no_services') }}</div>
                @endforelse
            </div>

            <details class="mt-4 rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <summary class="cursor-pointer text-sm font-medium text-brand-600">{{ __('brand-form.add_service') }}</summary>
                <p class="mt-3 text-xs text-gray-500">{{ __('brand-form.new_service_help') }}</p>
                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <x-ta.form.field :label="__('brand-form.service_name')" :error="$errors->first('new_service_name')"><input wire:model="new_service_name" aria-label="{{ __('brand-form.service_name') }}" type="text" maxlength="255" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white" /></x-ta.form.field>
                    <x-ta.form.field :label="__('brand-form.service_sector')" :error="$errors->first('new_service_sector')">
                        <select wire:model="new_service_sector" aria-label="{{ __('brand-form.service_sector') }}" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                            <option value="">{{ __('brand-form.choose_sector') }}</option>
                            @foreach ($selectedIndustryOptions as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                        </select>
                    </x-ta.form.field>
                </div>
                <label class="mt-3 flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300"><input wire:model="new_service_is_priority" type="checkbox" class="rounded border-gray-300 text-brand-500" /> {{ __('brand-form.priority') }}</label>
            </details>
        </x-ta.form.section>
        <x-ta.form.section :title="__('brand-form.areas')">
            @error('service_areas')<p class="mb-3 text-sm text-red-600">{{ $message }}</p>@enderror
            <p class="mb-4 text-sm text-gray-500">{{ __('brand-form.areas_help') }}</p>
            <div class="space-y-3">
                @foreach ($service_areas as $index => $area)
                    <div wire:key="service-area-{{ $index }}" class="grid gap-3 rounded-lg border border-gray-200 p-3 md:grid-cols-[1fr_1fr_1fr_auto] dark:border-gray-700">
                        <select aria-label="{{ __('brand-form.country') }}" wire:model.live="service_areas.{{ $index }}.country_code" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                            @foreach ($countryOptions as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                        </select>
                        @if (($area['country_code'] ?? '') === 'TR')
                            <select wire:model.live="service_areas.{{ $index }}.city_name" aria-label="{{ __('brand-form.city') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">{{ __('brand-form.all_turkey') }}</option>@foreach (\App\Support\Options\LocationOptions::cities() as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach</select>
                            <select wire:model="service_areas.{{ $index }}.district_name" aria-label="{{ __('brand-form.district') }}" @disabled(empty($area['city_name'])) class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">{{ __('brand-form.all_city') }}</option>@foreach (\App\Support\Options\LocationOptions::districts($area['city_name'] ?? '') as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach</select>
                        @else
                            <input wire:model="service_areas.{{ $index }}.city_name" aria-label="{{ __('brand-form.city') }}" placeholder="{{ __('brand-form.optional_city') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                            <input wire:model="service_areas.{{ $index }}.district_name" aria-label="{{ __('brand-form.district') }}" placeholder="{{ __('brand-form.optional_district') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                        @endif
                        <button type="button" wire:click="removeServiceArea({{ $index }})" class="rounded-lg px-3 py-2 text-sm text-red-600 ring-1 ring-inset ring-red-200">{{ __('brand-form.remove') }}</button>
                        @error("service_areas.$index.country_code") <p class="text-xs text-red-600 md:col-span-4">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
            <button type="button" wire:click="addServiceArea" class="mt-3 rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">+ {{ __('brand-form.add_area') }}</button>
        </x-ta.form.section>

        <details class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <summary class="cursor-pointer text-sm font-semibold text-gray-900 dark:text-white">{{ __('brand-form.optional_details') }}</summary>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <x-ta.form.field :label="__('brand-form.languages')" :error="$errors->first('languages')"><x-ta.form.multi-select wire:model="languages" :options="$languageOptions" :placeholder="__('brand-form.choose_language')" /></x-ta.form.field>
                <x-ta.form.field :label="__('brand-form.team')" :error="$errors->first('responsible_user_ids')"><x-ta.form.multi-select wire:model="responsible_user_ids" :options="$teamOptions" :placeholder="__('brand-form.choose_team')" /></x-ta.form.field>
                <x-ta.form.field label="Logo URL" :error="$errors->first('logo_url')"><input wire:model="logo_url" type="url" placeholder="https://…" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" /></x-ta.form.field>
            </div>
        </details>


        <div class="sticky bottom-0 z-30 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white/95 px-5 py-4 shadow-lg backdrop-blur dark:border-gray-700 dark:bg-gray-900/95">
            <p class="text-xs text-gray-500" wire:dirty>{{ __('brand-form.unsaved') }}</p>
            <div class="ml-auto flex gap-3">
                <a href="{{ $backUrl }}" wire:navigate class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 dark:text-gray-200 dark:ring-gray-700">{{ __('brand-form.cancel') }}</a>
                <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-5 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-60">
                    <span wire:loading.remove wire:target="save">{{ $primaryAction }}</span><span wire:loading wire:target="save">{{ __('brand-form.saving') }}</span>
                </button>
            </div>
        </div>
    </form>
</div>
