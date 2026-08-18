<div class="space-y-6">
    <div>
        <a href="{{ $backUrl }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-brand-600 dark:text-gray-400">← Back</a>
        <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">{{ $pageTitle }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $pageSubtitle }}</p>
            </div>
        </div>
    </div>

    <form wire:submit.prevent="save" class="space-y-5 pb-24">
        <x-ta.form.section title="Asset identity">
            @if ($brandLocked && $brandName)
                <div class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:bg-white/[0.03] dark:text-gray-300">
                    Brand: <span class="font-medium text-gray-900 dark:text-white">{{ $brandName }}</span>
                </div>
            @else
                <x-ta.form.field label="Brand" :required="true" :error="$errors->first('brand_id')">
                    <x-ta.form.select wire:model="brand_id" :options="$brandOptions" placeholder="Search brand…" :nullable="false" />
                </x-ta.form.field>
            @endif

            <x-ta.form.field label="Asset name" :required="true" :error="$errors->first('name')">
                <input wire:model="name" type="text" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
            </x-ta.form.field>

            <div class="grid gap-4 md:grid-cols-2">
                <x-ta.form.field label="Asset type" :required="true" :error="$errors->first('type')">
                    <x-ta.form.select wire:model.live="type" :options="$typeOptions" placeholder="Select type…" :nullable="false" />
                </x-ta.form.field>
                <x-ta.form.field label="Status" :required="true" :error="$errors->first('status')">
                    <x-ta.form.select wire:model="status" :options="$statusOptions" :searchable="false" :nullable="false" />
                </x-ta.form.field>
            </div>
        </x-ta.form.section>

        @if ($isWebsite)
            <x-ta.form.section title="Website details" subtitle="Shown only for Website assets. Provider binding happens in Integrations.">
                <div class="grid gap-4 md:grid-cols-2">
                    <x-ta.form.field label="Domain" helper="example.com" :error="$errors->first('domain')">
                        <input wire:model="domain" type="text" placeholder="example.com" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                    </x-ta.form.field>
                    <x-ta.form.field label="Primary URL" :error="$errors->first('primary_url')">
                        <input wire:model="primary_url" type="url" placeholder="https://www.example.com" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                    </x-ta.form.field>
                    <x-ta.form.field label="CMS" :error="$errors->first('cms')">
                        <x-ta.form.select wire:model="cms" :options="$cmsOptions" placeholder="Select CMS…" />
                    </x-ta.form.field>
                    <x-ta.form.field label="Website type" :error="$errors->first('site_type')">
                        <x-ta.form.select wire:model="site_type" :options="$websiteTypeOptions" placeholder="Select website type…" />
                    </x-ta.form.field>
                </div>

                <x-ta.form.field label="Languages" :error="$errors->first('languages')">
                    <x-ta.form.multi-select wire:model="languages" :options="$languageOptions" placeholder="Select languages…" />
                </x-ta.form.field>

                <x-ta.form.field label="Target countries" :error="$errors->first('target_countries')">
                    <x-ta.form.multi-select wire:model="target_countries" :options="$countryOptions" placeholder="Select countries…" />
                </x-ta.form.field>

                <div class="grid gap-4 md:grid-cols-2">
                    <x-ta.form.field label="SEO market country" :error="$errors->first('seo_market_country')">
                        <x-ta.form.select wire:model="seo_market_country" :options="$countryOptions" placeholder="Search country…" />
                    </x-ta.form.field>
                    <x-ta.form.field label="SEO market language" :error="$errors->first('seo_market_language')">
                        <x-ta.form.select wire:model="seo_market_language" :options="$languageOptions" placeholder="Search language…" />
                    </x-ta.form.field>
                </div>

                <x-ta.form.field label="Hosting context" :error="$errors->first('hosting_context')">
                    <textarea wire:model="hosting_context" rows="3" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"></textarea>
                </x-ta.form.field>
            </x-ta.form.section>
        @else
            <x-ta.form.section title="Connection">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Provider account binding is handled in Integrations after the asset is registered. Credentials are not collected here.
                </p>
            </x-ta.form.section>
        @endif

        <div class="fixed inset-x-0 bottom-0 z-30 border-t border-gray-200 bg-white/95 px-4 py-3 backdrop-blur dark:border-gray-800 dark:bg-gray-900/95 lg:left-[290px]">
            <div class="mx-auto flex max-w-screen-2xl justify-end gap-2">
                <a href="{{ $backUrl }}" wire:navigate class="inline-flex rounded-lg px-4 py-2.5 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Cancel</a>
                <button type="submit" wire:loading.attr="disabled" class="inline-flex rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-60">
                    <span wire:loading.remove>Save digital asset</span>
                    <span wire:loading>Saving…</span>
                </button>
            </div>
        </div>
    </form>
</div>
