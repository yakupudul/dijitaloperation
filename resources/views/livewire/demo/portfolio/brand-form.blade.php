<div class="mx-auto max-w-4xl space-y-6">
    <header>
        <a href="{{ $backUrl }}" wire:navigate class="text-[13px] font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">{{ __('brand-form.back') }}</a>
        <h1 class="page-title mt-2">{{ $pageTitle }}</h1>
        <p class="page-sub">{{ __('brand-form.subtitle') }}</p>
    </header>

    <form wire:submit="save" class="space-y-6">
        @if ($errors->any())
            <div role="alert" class="card border-error-200! px-4 py-3 text-[13px] text-error-700 dark:border-error-500/30! dark:text-error-300">
                <p class="font-medium">{{ __('brand-form.errors') }}</p>
                <ul class="mt-2 list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <section class="card p-5">
            <h2 class="section-title mb-4">{{ __('brand-form.identity') }}</h2>
            <div class="grid gap-5 md:grid-cols-2">
                <x-ta.form.field :label="__('brand-form.customer')" :required="true" :error="$errors->first('customer_id')">
                    @if ($customerLocked && $customerName)
                        <div class="flex h-9 items-center rounded-lg bg-gray-50 px-3 text-sm text-gray-700 dark:bg-white/5 dark:text-gray-200">{{ $customerName }}</div>
                    @else
                        <x-ta.form.select wire:model="customer_id" :options="$customerOptions" :placeholder="__('brand-form.choose_customer')" :nullable="false" />
                    @endif
                </x-ta.form.field>
                <x-ta.form.field :label="__('brand-form.name')" :required="true" :error="$errors->first('name')">
                    <input aria-label="{{ __('brand-form.name') }}" wire:model="name" type="text" maxlength="120" autocomplete="organization" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none disabled:bg-gray-50 disabled:text-gray-500 dark:border-gray-700 dark:bg-transparent dark:text-white" />
                </x-ta.form.field>
                @if ($mode === 'create')
                    <x-ta.form.field label="Web sitesi" :error="$errors->first('website_url')">
                        @if (($unassignedWebsites ?? []) !== [])
                            <select aria-label="Eklenmiş web sitesi" wire:model.live="existing_website_id" class="mb-2 h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none disabled:bg-gray-50 disabled:text-gray-500 dark:border-gray-700 dark:bg-transparent dark:text-white">
                                <option value="">Eklenmiş sitelerden seç (markaya bağlı olmayanlar)…</option>
                                @foreach ($unassignedWebsites as $siteId => $siteDomain)<option value="{{ $siteId }}">{{ $siteDomain }}</option>@endforeach
                            </select>
                        @endif
                        @if (($existing_website_id ?? null) === null)
                            <input aria-label="Web sitesi" wire:model="website_url" type="text" maxlength="255" placeholder="ya da elle yaz: ornek.com.tr" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none disabled:bg-gray-50 disabled:text-gray-500 dark:border-gray-700 dark:bg-transparent dark:text-white" />
                        @endif
                        <p class="mt-1.5 text-xs text-gray-500">Girersen kaydettikten sonra "Otomatik kur" Search Console, GA4, İşletme Profili, Ads ve Meta hesaplarını ve sitedeki hizmetleri bulur; sen onaylarsın. Hizmetleri aşağıda elle seçmen gerekmez.</p>
                    </x-ta.form.field>
                @endif
            </div>
        </section>

        <section class="card p-5">
            <h2 class="section-title mb-4">{{ __('brand-form.sectors_services') }}</h2>
            <p class="-mt-2 mb-4 text-[13px] text-gray-500 dark:text-gray-400">{{ __('brand-form.sectors_help') }}</p>
            <x-ta.form.field :label="__('brand-form.sectors')" :error="$errors->first('sector') ?: $errors->first('selected_sector_codes')">
                <x-ta.form.select id="brand-sector" wire:model.live="sector" :options="$industryOptions" :placeholder="__('brand-form.choose_sector')" />
            </x-ta.form.field>

            @if ($mode === 'edit')
                {{-- Services are edited in one place: Marka › Ayarlar › Marka bilgileri. --}}
                <div class="mt-6 border-t border-gray-100 pt-5 dark:border-gray-800" data-brand-form-services-readonly>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Hizmetler <span class="ml-1 font-normal text-gray-500">{{ count($currentServices) }}</span></h2>
                        <a href="{{ route('operator.brand', ['brand' => $brandId, 'tab' => 'ayarlar']) }}" wire:navigate class="btn-quiet h-7! px-2!">Düzenle</a>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">Hizmetler, ★ ana hizmet ve eşleşen sayfalar Marka › Ayarlar › Marka bilgileri'nde düzenlenir.</p>
                    <ul class="mt-3 flex flex-wrap gap-2">
                        @forelse ($currentServices as $service)
                            <li class="rounded-md border border-gray-200 px-2 py-0.5 text-[13px] text-gray-700 dark:border-gray-700 dark:text-gray-300">@if ($service['main'])<span aria-label="Ana hizmet">★</span> @endif{{ $service['name'] }}</li>
                        @empty
                            <li class="text-sm text-gray-500">Hizmet yok.</li>
                        @endforelse
                    </ul>
                </div>
            @else
            <div class="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-5 dark:border-gray-800">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('brand-form.services') }} <span class="ml-1 text-sm font-normal text-gray-500">{{ count($selected_service_catalog_ids) }} {{ __('brand-form.selected') }}</span></h2>
                <a href="{{ route('operator.library.services') }}" target="_blank" rel="noopener" class="link-neutral text-[13px]">{{ __('brand-form.manage_library') }} ↗</a>
            </div>
            <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                <input aria-label="{{ __('brand-form.search_services') }}" wire:model.live.debounce.300ms="service_search" type="search" placeholder="{{ __('brand-form.search_services') }}" class="min-w-0 flex-1 h-9 rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none disabled:bg-gray-50 disabled:text-gray-500 dark:border-gray-700 dark:bg-transparent dark:text-white" />
                <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300"><input wire:model.live="only_selected_services" type="checkbox" class="rounded border-gray-300" /> {{ __('brand-form.only_selected') }}</label>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <button type="button" wire:click="selectVisibleServices" wire:loading.attr="disabled" @disabled(count(array_diff(array_keys($serviceOptions), $selected_service_catalog_ids)) === 0) class="btn">{{ __('brand-form.select_visible') }}</button>
                <button type="button" wire:click="deselectVisibleServices" wire:loading.attr="disabled" @disabled(count(array_intersect(array_keys($serviceOptions), $selected_service_catalog_ids)) === 0) class="btn-quiet disabled:cursor-not-allowed disabled:opacity-40">{{ __('brand-form.deselect_visible') }}</button>
                <span class="text-xs text-gray-500">{{ __('brand-form.visible_count', ['count' => count($serviceOptions)]) }}</span>
            </div>
            <p class="mt-3 text-xs text-gray-500">{{ __('brand-form.priority_help') }}</p>

            @if ($outOfScopeServices !== [])
                <div role="alert" class="mt-4 rounded-lg border border-warning-200 bg-warning-50/70 p-4 dark:border-warning-500/30 dark:bg-warning-500/10">
                    <p class="text-sm font-semibold text-warning-900 dark:text-warning-200">{{ __('brand-form.out_of_scope') }}</p>
                    <p class="mt-1 text-sm text-warning-800 dark:text-warning-300">{{ __('brand-form.scope_help') }}</p>
                    <div class="mt-3 space-y-2">
                        @foreach ($outOfScopeServices as $id => $service)
                            <div wire:key="out-service-{{ $id }}" class="flex items-center justify-between gap-3 text-sm">
                                <span class="text-warning-900 dark:text-warning-200">{{ $service['label'] }} @if ($service['sector'])<span class="text-xs">, {{ $industryOptions[$service['sector']] ?? $service['sector'] }}</span>@endif</span>
                                <button type="button" wire:click="removeSelectedService('{{ $id }}')" class="btn shrink-0">{{ __('brand-form.remove') }}</button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="mt-4 max-h-[480px] overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700">
                @forelse ($serviceOptions as $id => $service)
                    <div wire:key="catalog-service-{{ $id }}" class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/5">
                        <label class="flex min-w-0 flex-1 cursor-pointer items-center gap-3">
                            <input wire:model.live="selected_service_catalog_ids" value="{{ $id }}" type="checkbox" class="rounded border-gray-300" />
                            <span class="min-w-0"><span class="block text-sm font-medium text-gray-900 dark:text-white">{{ $service['label'] }}</span><span class="block text-xs text-gray-500">{{ $industryOptions[$service['sector']] ?? $service['sector'] }}</span></span>
                        </label>
                        <label class="flex items-center gap-2 text-xs text-gray-500">
                            <input wire:model.live="priority_service_catalog_ids" value="{{ $id }}" type="checkbox" @disabled(! in_array((string) $id, $selected_service_catalog_ids, true)) class="rounded border-gray-300 disabled:opacity-30" /> {{ __('brand-form.priority') }}
                        </label>
                    </div>
                @empty
                    <div class="px-6 py-10 text-center text-sm text-gray-500">{{ __($selected_sector_codes === [] ? 'brand-form.select_sector_first' : 'brand-form.no_services') }}</div>
                @endforelse
            </div>

            <details class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-800">
                <summary class="cursor-pointer text-[13px] font-medium text-gray-700 hover:text-gray-900 dark:text-gray-300">{{ __('brand-form.add_service') }}</summary>
                <p class="mt-3 text-xs text-gray-500">{{ __('brand-form.new_service_help') }}</p>
                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <x-ta.form.field :label="__('brand-form.service_name')" :error="$errors->first('new_service_name')"><input wire:model="new_service_name" aria-label="{{ __('brand-form.service_name') }}" type="text" maxlength="255" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none disabled:bg-gray-50 disabled:text-gray-500 dark:border-gray-700 dark:bg-transparent dark:text-white" /></x-ta.form.field>
                    <x-ta.form.field :label="__('brand-form.service_sector')" :error="$errors->first('new_service_sector')">
                        <select wire:model="new_service_sector" aria-label="{{ __('brand-form.service_sector') }}" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none disabled:bg-gray-50 disabled:text-gray-500 dark:border-gray-700 dark:bg-transparent dark:text-white">
                            <option value="">{{ __('brand-form.choose_sector') }}</option>
                            @foreach ($selectedIndustryOptions as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                        </select>
                    </x-ta.form.field>
                </div>
                <label class="mt-3 flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300"><input wire:model="new_service_is_priority" type="checkbox" class="rounded border-gray-300" /> {{ __('brand-form.priority') }}</label>
            </details>
            @endif
        </section>
        <section class="card p-5">
            <h2 class="section-title mb-4">{{ __('brand-form.areas') }}</h2>
            @error('service_areas')<p class="mb-3 text-sm text-error-600">{{ $message }}</p>@enderror
            <p class="-mt-2 mb-4 text-[13px] text-gray-500">{{ __('brand-form.areas_help') }}</p>
            <div class="space-y-3">
                @foreach ($service_areas as $index => $area)
                    <div wire:key="service-area-{{ $index }}" class="grid gap-2 md:grid-cols-[1fr_1fr_1fr_auto]">
                        <select aria-label="{{ __('brand-form.country') }}" wire:model.live="service_areas.{{ $index }}.country_code" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none disabled:bg-gray-50 disabled:text-gray-500 dark:border-gray-700 dark:bg-transparent dark:text-white">
                            @foreach ($countryOptions as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                        </select>
                        @if (($area['country_code'] ?? '') === 'TR')
                            <select wire:model.live="service_areas.{{ $index }}.city_name" aria-label="{{ __('brand-form.city') }}" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none disabled:bg-gray-50 disabled:text-gray-500 dark:border-gray-700 dark:bg-transparent dark:text-white"><option value="">{{ __('brand-form.all_turkey') }}</option>@foreach (\App\Support\Options\LocationOptions::cities() as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach</select>
                            <select wire:model="service_areas.{{ $index }}.district_name" aria-label="{{ __('brand-form.district') }}" @disabled(empty($area['city_name'])) class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none disabled:bg-gray-50 disabled:text-gray-500 dark:border-gray-700 dark:bg-transparent dark:text-white"><option value="">{{ __('brand-form.all_city') }}</option>@foreach (\App\Support\Options\LocationOptions::districts($area['city_name'] ?? '') as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach</select>
                        @else
                            <input wire:model="service_areas.{{ $index }}.city_name" aria-label="{{ __('brand-form.city') }}" placeholder="{{ __('brand-form.optional_city') }}" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none disabled:bg-gray-50 disabled:text-gray-500 dark:border-gray-700 dark:bg-transparent dark:text-white" />
                            <input wire:model="service_areas.{{ $index }}.district_name" aria-label="{{ __('brand-form.district') }}" placeholder="{{ __('brand-form.optional_district') }}" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none disabled:bg-gray-50 disabled:text-gray-500 dark:border-gray-700 dark:bg-transparent dark:text-white" />
                        @endif
                        <button type="button" wire:click="removeServiceArea({{ $index }})" class="btn-quiet h-9! hover:text-error-600!">{{ __('brand-form.remove') }}</button>
                        @error("service_areas.$index.country_code") <p class="text-xs text-error-600 md:col-span-4">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
            <button type="button" wire:click="addServiceArea" class="btn mt-3">+ {{ __('brand-form.add_area') }}</button>
        </section>

        <details class="card p-5">
            <summary class="cursor-pointer text-[15px] font-semibold text-gray-900 dark:text-white">{{ __('brand-form.optional_details') }}</summary>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <x-ta.form.field :label="__('brand-form.languages')" :error="$errors->first('languages')"><x-ta.form.multi-select wire:model="languages" :options="$languageOptions" :placeholder="__('brand-form.choose_language')" /></x-ta.form.field>
                <x-ta.form.field :label="__('brand-form.team')" :error="$errors->first('responsible_user_ids')"><x-ta.form.multi-select wire:model="responsible_user_ids" :options="$teamOptions" :placeholder="__('brand-form.choose_team')" /></x-ta.form.field>
                <x-ta.form.field label="Logo URL" :error="$errors->first('logo_url')"><input wire:model="logo_url" type="url" placeholder="https://…" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none disabled:bg-gray-50 disabled:text-gray-500 dark:border-gray-700 dark:bg-transparent dark:text-white" /></x-ta.form.field>
            </div>
        </details>


        <div class="card sticky bottom-3 z-30 flex flex-wrap items-center justify-between gap-3 px-4 py-3 shadow-theme-lg">
            <p class="text-xs text-gray-500" wire:dirty>{{ __('brand-form.unsaved') }}</p>
            <div class="ml-auto flex gap-2">
                <a href="{{ $backUrl }}" wire:navigate class="btn">{{ __('brand-form.cancel') }}</a>
                <button type="submit" wire:loading.attr="disabled" class="btn-primary">
                    <span wire:loading.remove wire:target="save">{{ $primaryAction }}</span><span wire:loading wire:target="save">{{ __('brand-form.saving') }}</span>
                </button>
            </div>
        </div>
    </form>
</div>
