<div class="space-y-5">
    @include('livewire.demo.partials.flash')
    @include('livewire.demo.sales.partials.sales-subnav', ['current' => 'search-profiles'])

    <div>
        <a href="{{ route('operator.search-profiles') }}" class="text-sm text-gray-500 hover:text-brand-600">← {{ __('operator.nav.search_profiles') }}</a>
        <h1 class="mt-2 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $profileId ? __('operator.sales_intent.edit_profile') : __('operator.sales_intent.new_profile') }}</h1>
    </div>

    <form wire:submit.prevent="save" class="space-y-4 pb-20">
        <x-ta.card>
            <div class="grid gap-4 md:grid-cols-2">
                <x-ta.form.field :label="__('operator.sales_intent.fields.name')" :required="true" :error="$errors->first('name')" class="md:col-span-2">
                    <input wire:model="name" type="text" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>
                <x-ta.form.field :label="__('operator.sales_intent.fields.service')">
                    <select wire:model="service_definition_code" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900">
                        @foreach ($serviceOptions as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </x-ta.form.field>
                <x-ta.form.field :label="__('operator.sales_intent.fields.country')">
                    <select wire:model="country" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900">
                        <option value="">—</option>
                        @foreach ($countryOptions as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </x-ta.form.field>
                <x-ta.form.field :label="__('operator.sales_intent.fields.language')">
                    <input wire:model="language" type="text" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900" />
                </x-ta.form.field>
                <x-ta.form.field :label="__('operator.sales_intent.fields.location')">
                    <input wire:model="location" type="text" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900" />
                </x-ta.form.field>
                <x-ta.form.field :label="__('operator.sales_intent.fields.include')" :error="$errors->first('include_concepts')" class="md:col-span-2">
                    <textarea wire:model="include_concepts" rows="5" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"></textarea>
                </x-ta.form.field>
                <x-ta.form.field :label="__('operator.sales_intent.fields.exclude')" class="md:col-span-2">
                    <textarea wire:model="exclude_concepts" rows="4" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"></textarea>
                </x-ta.form.field>
                <x-ta.form.field :label="__('operator.sales_intent.fields.min_confidence')">
                    <input wire:model="minimum_intent_confidence" type="number" min="0" max="100" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900" />
                </x-ta.form.field>
                <x-ta.form.field :label="__('operator.prospects.fields.owner')">
                    <select wire:model="owner_user_id" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900">
                        <option value="">{{ __('operator.forms.unassigned') }}</option>
                        @foreach ($ownerOptions as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </x-ta.form.field>
                <label class="flex items-center gap-2 text-sm md:col-span-2">
                    <input type="checkbox" wire:model="active" /> {{ __('operator.sales_intent.fields.active') }}
                </label>
            </div>
        </x-ta.card>
        <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white">{{ __('operator.actions.save') }}</button>
    </form>
</div>
