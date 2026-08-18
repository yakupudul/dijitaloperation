<div class="space-y-6">
    <div>
        <a href="{{ $backUrl }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-gray-500 hover:text-brand-600 dark:text-gray-400">
            ← {{ $backLabel }}
        </a>
        <div class="mt-3">
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">{{ $pageTitle }}</h1>
            <p class="mt-1 max-w-2xl text-sm text-gray-500 dark:text-gray-400">{{ $pageSubtitle }}</p>
        </div>
    </div>

    <form wire:submit.prevent="save" class="space-y-5 pb-24">
        <x-ta.form.section :title="__('operator.prospects.section_identity')">
            <div class="grid gap-4 md:grid-cols-2">
                <x-ta.form.field :label="__('operator.prospects.fields.company_name')" :required="true" :error="$errors->first('company_name')" class="md:col-span-2">
                    <input wire:model="company_name" type="text"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>

                <x-ta.form.field :label="__('operator.prospects.fields.website')" :error="$errors->first('website_url')" class="md:col-span-2">
                    <input wire:model="website_url" type="text" inputmode="url" placeholder="https://example.com"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>

                <x-ta.form.field :label="__('operator.prospects.fields.source')" :required="true" :error="$errors->first('source')">
                    <x-ta.form.select wire:model="source" :options="$sourceOptions" :searchable="false" :nullable="false" />
                </x-ta.form.field>

                <x-ta.form.field :label="__('operator.prospects.fields.owner')" :error="$errors->first('owner_user_id')">
                    <x-ta.form.select wire:model="owner_user_id" :options="$ownerOptions" :placeholder="__('operator.forms.unassigned')" />
                </x-ta.form.field>

                <x-ta.form.field :label="__('operator.prospects.fields.inquiry')" :error="$errors->first('inquiry')" class="md:col-span-2">
                    <textarea wire:model="inquiry" rows="4"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"></textarea>
                </x-ta.form.field>
            </div>
        </x-ta.form.section>

        <x-ta.form.section :title="__('operator.prospects.section_contact')">
            <div class="grid gap-4 md:grid-cols-2">
                <x-ta.form.field :label="__('operator.prospects.fields.contact_name')" :error="$errors->first('contact_name')">
                    <input wire:model="contact_name" type="text"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>

                <x-ta.form.field :label="__('operator.prospects.fields.contact_email')" :error="$errors->first('contact_email')">
                    <input wire:model="contact_email" type="email"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>

                <x-ta.form.field :label="__('operator.prospects.fields.contact_phone')" :error="$errors->first('contact_phone')">
                    <input wire:model="contact_phone" type="text"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>

                <x-ta.form.field :label="__('operator.prospects.fields.country')" :error="$errors->first('country')">
                    <x-ta.form.select wire:model="country" :options="$countryOptions" :placeholder="__('operator.forms.search_country')" />
                </x-ta.form.field>

                <x-ta.form.field :label="__('operator.prospects.fields.city')" :error="$errors->first('city')">
                    <input wire:model="city" type="text"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>
            </div>
        </x-ta.form.section>

        <div class="sticky bottom-0 z-10 -mx-4 border-t border-gray-200 bg-white/95 px-4 py-4 backdrop-blur dark:border-gray-700 dark:bg-gray-900/95 lg:-mx-6 lg:px-6">
            <div class="flex flex-wrap items-center justify-end gap-2">
                <a href="{{ $backUrl }}" wire:navigate class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">{{ __('operator.actions.cancel') }}</a>
                <button type="submit" wire:loading.attr="disabled" class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-60">
                    {{ __('operator.actions.save') }}
                </button>
            </div>
        </div>
    </form>
</div>
