<div class="mx-auto max-w-4xl space-y-6">
    <header>
        <a href="{{ $backUrl }}" wire:navigate class="inline-flex items-center gap-1 text-[13px] font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">
            {{ $backLabel }}
        </a>
        <h1 class="page-title mt-2">{{ $pageTitle }}</h1>
        <p class="page-sub max-w-2xl">{{ $pageSubtitle }}</p>
    </header>

    <form wire:submit.prevent="save" class="space-y-5">
        <section class="card p-5">
            <h2 class="section-title">{{ __('operator.forms.customer_identity') }}</h2>
            <div class="mt-4">
            <div class="grid gap-4 md:grid-cols-2">
                <x-ta.form.field :label="__('operator.forms.customer_name')" :required="true" :helper="__('operator.forms.customer_name_help')" :error="$errors->first('name')" class="md:col-span-2">
                    <input wire:model="name" type="text" placeholder="Northwind Clinics"
                        class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white" />
                </x-ta.form.field>

                <x-ta.form.field :label="__('operator.forms.legal_name')" :helper="__('operator.forms.legal_name_help')" :error="$errors->first('legal_name')" class="md:col-span-2">
                    <input wire:model="legal_name" type="text" placeholder="Northwind Clinics Ltd"
                        class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white" />
                </x-ta.form.field>

                <x-ta.form.field :label="__('operator.forms.customer_type')" :required="true" :error="$errors->first('type')">
                    <x-ta.form.select wire:model="type" :options="$typeOptions" :searchable="false" :nullable="false" :placeholder="__('operator.forms.select_type')" />
                </x-ta.form.field>

                <x-ta.form.field :label="__('operator.forms.status')" :required="true" :error="$errors->first('status')">
                    <x-ta.form.select wire:model="status" :options="$statusOptions" :searchable="false" :nullable="false" :placeholder="__('operator.forms.select_status')" />
                </x-ta.form.field>
            </div>
            </div>
        </section>

        <section class="card p-5">
            <h2 class="section-title">{{ __('operator.forms.business_profile') }}</h2>
            <div class="mt-4">
            <div class="grid gap-4 md:grid-cols-2">
                <div class="md:col-span-2" data-customer-sector>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Sektör</p>
                    <p class="mt-1 text-sm text-gray-900 dark:text-white">{{ ($brandSectorLabel ?? '') !== '' ? $brandSectorLabel : 'Henüz yok' }}</p>
                    <p class="mt-0.5 text-xs text-gray-500">Markalardan gelir: sektör her markanın kendi ayarında seçilir, müşteride ayrıca tutulmaz.</p>
                </div>

                <x-ta.form.field :label="__('operator.forms.hq_country')" :error="$errors->first('hq_country')">
                    <x-ta.form.select wire:model.live="hq_country" :options="$countryOptions" :placeholder="__('operator.forms.search_country')" />
                </x-ta.form.field>

                <x-ta.form.field :label="__('operator.forms.hq_city')" :helper="__('operator.forms.hq_city_help')" :error="$errors->first('hq_city') ?: $errors->first('hq_city_other')">
                    <div wire:key="hq-city-{{ $hq_country }}">
                        <x-ta.form.select wire:model.live="hq_city" :options="$cityOptions" :allow-custom="false" :disabled="$hq_country === ''" :placeholder="__('operator.forms.search_city')" />
                    </div>
                </x-ta.form.field>

                @if ($showCityOther)
                    <x-ta.form.field :label="__('operator.forms.city_other_label')" :error="$errors->first('hq_city_other')" class="md:col-span-2">
                        <input wire:model="hq_city_other" type="text"
                            class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white" />
                    </x-ta.form.field>
                @endif
            </div>
            </div>
        </section>

        <section class="card p-5">
            <h2 class="section-title">{{ __('operator.forms.agency_relationship') }}</h2>
            <div class="mt-4">
            <div class="grid gap-4 md:grid-cols-2">
                <x-ta.form.field :label="__('operator.forms.services_received')" :error="$errors->first('services')" class="md:col-span-2">
                    <x-ta.form.multi-select wire:model="services" :options="$serviceOptions" :placeholder="__('operator.forms.select_services')" />
                </x-ta.form.field>

                <x-ta.form.field :label="__('operator.forms.service_started')" :error="$errors->first('service_started_at')">
                    <input wire:model="service_started_at" type="date"
                        class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white" />
                </x-ta.form.field>
            </div>
            </div>
        </section>

        <section class="card p-5">
            <h2 class="section-title">{{ __('operator.forms.primary_communication') }}</h2>
            <div class="mt-4">
            <div class="grid gap-4 md:grid-cols-2">
                <x-ta.form.field :label="__('operator.forms.primary_email')" :error="$errors->first('primary_email')">
                    <input wire:model="primary_email" type="email" placeholder="ops@client.example"
                        class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white" />
                </x-ta.form.field>
                <x-ta.form.field :label="__('operator.forms.primary_phone')" :error="$errors->first('primary_phone')">
                    <input wire:model="primary_phone" type="tel" placeholder="+90 …"
                        class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white" />
                </x-ta.form.field>
            </div>
            </div>
        </section>

        <section class="card p-5">
            <h2 class="section-title">{{ __('operator.forms.responsible_team') }}</h2>
            <div class="mt-4">
            <x-ta.form.field :label="__('operator.forms.responsible_team')" :helper="__('operator.forms.responsible_team_help')" :error="$errors->first('responsible_user_ids')">
                <x-ta.form.multi-select wire:model="responsible_user_ids" :options="$teamOptions" :placeholder="__('operator.forms.search_team')" />
            </x-ta.form.field>
            </div>
        </section>

        <div class="card sticky bottom-3 z-30 px-4 py-3 shadow-theme-lg">
            <div class="flex flex-wrap items-center justify-end gap-2">
                <a href="{{ $backUrl }}" wire:navigate class="btn">
                    {{ __('operator.actions.cancel') }}
                </a>
                @if ($showSaveAndAddBrand)
                    <button type="button" wire:click="save(true)" wire:loading.attr="disabled" class="btn">
                        {{ __('operator.forms.save_and_add_brand') }}
                    </button>
                @endif
                <button type="submit" wire:loading.attr="disabled" class="btn-primary">
                    <span wire:loading.remove wire:target="save">{{ $primaryAction }}</span>
                    <span wire:loading wire:target="save">{{ __('operator.forms.saving') }}</span>
                </button>
            </div>
        </div>
    </form>
</div>
