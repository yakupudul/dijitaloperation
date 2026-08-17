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
        <x-ta.form.section title="Customer identity">
            <div class="grid gap-4 md:grid-cols-2">
                <x-ta.form.field label="Customer name" :required="true" helper="The name your team uses internally." :error="$errors->first('name')" class="md:col-span-2">
                    <input wire:model="name" type="text" placeholder="Northwind Clinics"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-800 shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>

                <x-ta.form.field label="Legal name" helper="Optional commercial / legal title." :error="$errors->first('legal_name')" class="md:col-span-2">
                    <input wire:model="legal_name" type="text" placeholder="Northwind Clinics Ltd"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-800 shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>

                <x-ta.form.field label="Customer type" :required="true" :error="$errors->first('type')">
                    <x-ta.form.select wire:model="type" :options="$typeOptions" :searchable="false" :nullable="false" placeholder="Select type" />
                </x-ta.form.field>

                <x-ta.form.field label="Status" :required="true" :error="$errors->first('status')">
                    <x-ta.form.select wire:model="status" :options="$statusOptions" :searchable="false" :nullable="false" placeholder="Select status" />
                </x-ta.form.field>
            </div>
        </x-ta.form.section>

        <x-ta.form.section title="Business profile">
            <div class="grid gap-4 md:grid-cols-2">
                <x-ta.form.field label="Industry" :error="$errors->first('industry')">
                    <x-ta.form.select wire:model.live="industry" :options="$industryOptions" placeholder="Search industry…" />
                </x-ta.form.field>

                @if ($showIndustryOther)
                    <x-ta.form.field label="Custom industry" :error="$errors->first('industry_other')">
                        <input wire:model="industry_other" type="text"
                            class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                    </x-ta.form.field>
                @endif

                <x-ta.form.field label="HQ country" :error="$errors->first('hq_country')">
                    <x-ta.form.select wire:model.live="hq_country" :options="$countryOptions" placeholder="Search country…" />
                </x-ta.form.field>

                <x-ta.form.field label="HQ city" helper="Search suggestions or enter a city." :error="$errors->first('hq_city')">
                    <x-ta.form.select wire:model="hq_city" :options="$cityOptions" :allow-custom="true" placeholder="Search or enter city…" />
                </x-ta.form.field>
            </div>
        </x-ta.form.section>

        <x-ta.form.section title="Agency relationship">
            <div class="grid gap-4 md:grid-cols-2">
                <x-ta.form.field label="Services received" :error="$errors->first('services')" class="md:col-span-2">
                    <x-ta.form.multi-select wire:model="services" :options="$serviceOptions" placeholder="Select services…" />
                </x-ta.form.field>

                <x-ta.form.field label="Service started" :error="$errors->first('service_started_at')">
                    <input wire:model="service_started_at" type="date"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>
            </div>
        </x-ta.form.section>

        <x-ta.form.section title="Primary communication">
            <div class="grid gap-4 md:grid-cols-2">
                <x-ta.form.field label="Primary email" :error="$errors->first('primary_email')">
                    <input wire:model="primary_email" type="email" placeholder="ops@client.example"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>
                <x-ta.form.field label="Primary phone" :error="$errors->first('primary_phone')">
                    <input wire:model="primary_phone" type="tel" placeholder="+90 …"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>
            </div>
        </x-ta.form.section>

        <x-ta.form.section title="Responsible team" subtitle="Moximu team members responsible for this customer.">
            <x-ta.form.field label="Responsible team" helper="Moximu team members responsible for this customer." :error="$errors->first('responsible_user_ids')">
                <x-ta.form.multi-select wire:model="responsible_user_ids" :options="$teamOptions" placeholder="Search team members…" />
            </x-ta.form.field>
        </x-ta.form.section>

        <div class="fixed inset-x-0 bottom-0 z-30 border-t border-gray-200 bg-white/95 px-4 py-3 backdrop-blur dark:border-gray-800 dark:bg-gray-900/95 lg:left-[290px]">
            <div class="mx-auto flex max-w-screen-2xl flex-wrap items-center justify-end gap-2">
                <a href="{{ $backUrl }}" wire:navigate
                    class="inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/5">
                    Cancel
                </a>
                @if ($showSaveAndAddBrand)
                    <button type="button" wire:click="save(true)" wire:loading.attr="disabled"
                        class="inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-60 dark:text-gray-300 dark:ring-gray-700">
                        Save &amp; add brand
                    </button>
                @endif
                <button type="submit" wire:loading.attr="disabled"
                    class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-60">
                    <span wire:loading.remove wire:target="save">{{ $primaryAction }}</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>
            </div>
        </div>
    </form>
</div>
