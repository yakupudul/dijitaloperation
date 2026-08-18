<div class="space-y-6">
    <div>
        <a href="{{ $backUrl }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-gray-500 hover:text-brand-600 dark:text-gray-400">
            ← {{ $backLabel }}
        </a>
        <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">{{ $pageTitle }}</h1>
                <p class="mt-1 max-w-2xl text-sm text-gray-500 dark:text-gray-400">{{ $pageSubtitle }}</p>
            </div>
        </div>
    </div>

    <form wire:submit.prevent="save" class="space-y-5 pb-24">
        <x-ta.form.section :title="__('operator.forms.customer_identity')">
            <div class="grid gap-4 md:grid-cols-2">
                <x-ta.form.field :label="__('operator.forms.customer_name')" :required="true" :helper="__('operator.forms.customer_name_help')" :error="$errors->first('name')" class="md:col-span-2">
                    <input wire:model="name" type="text" placeholder="Northwind Clinics"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-800 shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>

                <x-ta.form.field :label="__('operator.forms.legal_name')" :helper="__('operator.forms.legal_name_help')" :error="$errors->first('legal_name')" class="md:col-span-2">
                    <input wire:model="legal_name" type="text" placeholder="Northwind Clinics Ltd"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-800 shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>

                <x-ta.form.field :label="__('operator.forms.customer_type')" :required="true" :error="$errors->first('type')">
                    <x-ta.form.select wire:model="type" :options="$typeOptions" :searchable="false" :nullable="false" :placeholder="__('operator.forms.select_type')" />
                </x-ta.form.field>

                <x-ta.form.field :label="__('operator.forms.status')" :required="true" :error="$errors->first('status')">
                    <x-ta.form.select wire:model="status" :options="$statusOptions" :searchable="false" :nullable="false" :placeholder="__('operator.forms.select_status')" />
                </x-ta.form.field>
            </div>
        </x-ta.form.section>

        <x-ta.form.section :title="__('operator.forms.business_profile')">
            <div class="grid gap-4 md:grid-cols-2">
                <x-ta.form.field :label="__('operator.forms.industry')" :error="$errors->first('industry')">
                    <x-ta.form.select wire:model.live="industry" :options="$industryOptions" :placeholder="__('operator.forms.search_industry')" />
                </x-ta.form.field>

                @if ($showIndustryOther)
                    <x-ta.form.field :label="__('operator.forms.custom_industry')" :error="$errors->first('industry_other')">
                        <input wire:model="industry_other" type="text"
                            class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                    </x-ta.form.field>
                @endif

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
                            class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                    </x-ta.form.field>
                @endif
            </div>
        </x-ta.form.section>

        <x-ta.form.section :title="__('operator.forms.agency_relationship')">
            <div class="grid gap-4 md:grid-cols-2">
                <x-ta.form.field :label="__('operator.forms.services_received')" :error="$errors->first('services')" class="md:col-span-2">
                    <x-ta.form.multi-select wire:model="services" :options="$serviceOptions" :placeholder="__('operator.forms.select_services')" />
                </x-ta.form.field>

                <x-ta.form.field :label="__('operator.forms.service_started')" :error="$errors->first('service_started_at')">
                    <input wire:model="service_started_at" type="date"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>
            </div>
        </x-ta.form.section>

        <x-ta.form.section :title="__('operator.forms.primary_communication')">
            <div class="grid gap-4 md:grid-cols-2">
                <x-ta.form.field :label="__('operator.forms.primary_email')" :error="$errors->first('primary_email')">
                    <input wire:model="primary_email" type="email" placeholder="ops@client.example"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>
                <x-ta.form.field :label="__('operator.forms.primary_phone')" :error="$errors->first('primary_phone')">
                    <input wire:model="primary_phone" type="tel" placeholder="+90 …"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>
            </div>
        </x-ta.form.section>

        <x-ta.form.section :title="__('operator.forms.responsible_team')" :subtitle="__('operator.forms.responsible_team_help')">
            <x-ta.form.field :label="__('operator.forms.responsible_team')" :helper="__('operator.forms.responsible_team_help')" :error="$errors->first('responsible_user_ids')">
                <x-ta.form.multi-select wire:model="responsible_user_ids" :options="$teamOptions" :placeholder="__('operator.forms.search_team')" />
            </x-ta.form.field>
        </x-ta.form.section>

        <div class="fixed inset-x-0 bottom-0 z-30 border-t border-gray-200 bg-white/95 px-4 py-3 backdrop-blur dark:border-gray-800 dark:bg-gray-900/95 lg:left-[290px]">
            <div class="mx-auto flex max-w-screen-2xl flex-wrap items-center justify-end gap-2">
                <a href="{{ $backUrl }}" wire:navigate
                    class="inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/5">
                    {{ __('operator.actions.cancel') }}
                </a>
                @if ($showSaveAndAddBrand)
                    <button type="button" wire:click="save(true)" wire:loading.attr="disabled"
                        class="inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-60 dark:text-gray-300 dark:ring-gray-700">
                        {{ __('operator.forms.save_and_add_brand') }}
                    </button>
                @endif
                <button type="submit" wire:loading.attr="disabled"
                    class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-60">
                    <span wire:loading.remove wire:target="save">{{ $primaryAction }}</span>
                    <span wire:loading wire:target="save">{{ __('operator.forms.saving') }}</span>
                </button>
            </div>
        </div>
    </form>
</div>
