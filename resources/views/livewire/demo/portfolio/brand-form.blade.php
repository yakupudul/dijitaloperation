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
        <x-ta.form.section title="Brand context">
            @if ($customerLocked && $customerName)
                <div class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:bg-white/[0.03] dark:text-gray-300">
                    Customer: <span class="font-medium text-gray-900 dark:text-white">{{ $customerName }}</span>
                </div>
            @else
                <x-ta.form.field label="Customer" :required="true" :error="$errors->first('customer_id')">
                    <x-ta.form.select wire:model="customer_id" :options="$customerOptions" placeholder="Search customer…" :nullable="false" />
                </x-ta.form.field>
            @endif

            <x-ta.form.field label="Brand name" :required="true" :error="$errors->first('name')">
                <input wire:model="name" type="text" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
            </x-ta.form.field>

            <div class="grid gap-4 md:grid-cols-2">
                <x-ta.form.field label="Sector" :error="$errors->first('sector')">
                    <x-ta.form.select wire:model="sector" :options="$industryOptions" placeholder="Search sector…" />
                </x-ta.form.field>
                <x-ta.form.field label="Primary country" :error="$errors->first('primary_country')">
                    <x-ta.form.select wire:model="primary_country" :options="$countryOptions" placeholder="Search country…" />
                </x-ta.form.field>
            </div>

            <x-ta.form.field label="Target markets" :error="$errors->first('target_markets')">
                <x-ta.form.multi-select wire:model="target_markets" :options="$countryOptions" placeholder="Select countries…" />
            </x-ta.form.field>

            <x-ta.form.field label="Languages" :error="$errors->first('languages')">
                <x-ta.form.multi-select wire:model="languages" :options="$languageOptions" placeholder="Select languages…" />
            </x-ta.form.field>
        </x-ta.form.section>

        <x-ta.form.section title="Legacy free-text fields (compatibility)">
            <div class="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30">
                <strong>Canonical strategic truth</strong> is Brand Intelligence Context (Business Context tab on the Brand workspace).
                Audience, offerings, markets, competitors, goals, and positioning should be maintained there — not duplicated here.
                These legacy fields remain for backward compatibility and are not a second source of truth.
            </div>
            <x-ta.form.field label="Description (legacy)" :error="$errors->first('description')">
                <textarea wire:model="description" rows="2" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"></textarea>
            </x-ta.form.field>
            <x-ta.form.field label="Target audience (legacy — prefer Business Context)" :error="$errors->first('audience')">
                <textarea wire:model="audience" rows="2" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"></textarea>
            </x-ta.form.field>
            <x-ta.form.field label="Offerings (legacy — prefer Business Context)" helper="Prefer Brand Context products/services with stable future IDs." :error="$errors->first('offerings')">
                <textarea wire:model="offerings" rows="2" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"></textarea>
            </x-ta.form.field>
            <x-ta.form.field label="Competitors (legacy — prefer Business Context)" :error="$errors->first('competitors')">
                <textarea wire:model="competitors" rows="2" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"></textarea>
            </x-ta.form.field>
        </x-ta.form.section>

        <x-ta.form.section title="Ownership">
            <x-ta.form.field label="Responsible users" :error="$errors->first('responsible_user_ids')">
                <x-ta.form.multi-select wire:model="responsible_user_ids" :options="$teamOptions" placeholder="Search team…" />
            </x-ta.form.field>
            <x-ta.form.field label="Logo URL" helper="Optional lightweight logo reference." :error="$errors->first('logo_url')">
                <input wire:model="logo_url" type="url" placeholder="https://…" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
            </x-ta.form.field>
        </x-ta.form.section>

        <div class="fixed inset-x-0 bottom-0 z-30 border-t border-gray-200 bg-white/95 px-4 py-3 backdrop-blur dark:border-gray-800 dark:bg-gray-900/95 lg:left-[290px]">
            <div class="mx-auto flex max-w-screen-2xl justify-end gap-2">
                <a href="{{ $backUrl }}" wire:navigate class="inline-flex rounded-lg px-4 py-2.5 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Cancel</a>
                <button type="submit" wire:loading.attr="disabled" class="inline-flex rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-60">
                    <span wire:loading.remove>{{ $primaryAction }}</span>
                    <span wire:loading>Saving…</span>
                </button>
            </div>
        </div>
    </form>
</div>
