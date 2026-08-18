<div class="space-y-5">
    @include('livewire.demo.partials.flash')
    @include('livewire.demo.sales.partials.sales-subnav', ['current' => 'prospects'])

    <div>
        <a href="{{ route('operator.prospect', ['prospectId' => $prospect->id]) }}" wire:navigate class="text-sm text-gray-500 hover:text-brand-600">← {{ $prospect->company_name }}</a>
        <h1 class="mt-2 text-2xl font-bold text-gray-800 dark:text-white/90">{{ __('operator.prospects.conversion.convert') }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ __('operator.prospects.conversion.subtitle') }}</p>
    </div>

    @if ($preview['already_converted'] ?? false)
        <x-ta.card>
            <p class="text-sm">{{ __('operator.prospects.conversion.already_converted') }}</p>
            <div class="mt-3 flex gap-2">
                <a href="{{ route('operator.customer', ['customerId' => $preview['converted_customer_id']]) }}" class="text-brand-600 hover:underline">{{ __('operator.prospects.conversion.open_customer') }}</a>
                <a href="{{ route('operator.brand', ['brand' => $preview['converted_brand_id']]) }}" class="text-brand-600 hover:underline">{{ __('operator.prospects.conversion.open_brand') }}</a>
            </div>
        </x-ta.card>
    @else
        <form wire:submit.prevent="convert" class="space-y-5">
            <x-ta.card>
                <div class="grid gap-4 md:grid-cols-2">
                    <x-ta.form.field :label="__('operator.prospects.conversion.customer_name')" :error="$errors->first('customer_name')">
                        <input wire:model="customer_name" type="text" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                    </x-ta.form.field>
                    <x-ta.form.field :label="__('operator.prospects.conversion.brand_name')" :error="$errors->first('brand_name')">
                        <input wire:model="brand_name" type="text" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                    </x-ta.form.field>
                </div>
                <p class="mt-3 text-sm text-gray-500">{{ __('operator.prospects.fields.website') }}: {{ $preview['website_url'] ?: '—' }}</p>
                <p class="mt-1 text-sm text-gray-500">{{ __('operator.prospects.fields.country') }}: {{ $preview['country'] ?: '—' }} {{ $preview['city'] ?? '' }}</p>
            </x-ta.card>

            @if (($preview['duplicates']['customers'] ?? []) !== [] || ($preview['duplicates']['brands'] ?? []) !== [] || ($preview['duplicates']['digital_assets'] ?? []) !== [])
                <x-ta.card data-testid="potential-duplicate">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ __('operator.prospects.conversion.potential_duplicate') }}</h2>
                    <p class="mt-1 text-sm text-gray-500">{{ __('operator.prospects.conversion.duplicate_help') }}</p>
                    @error('duplicates') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    @if (($preview['duplicates']['customers'] ?? []) !== [])
                        <label class="mt-3 block text-xs font-medium text-gray-500">{{ __('operator.nav.customers') }}</label>
                        <select wire:model.live="existing_customer_id" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900">
                            <option value="">{{ __('operator.prospects.conversion.create_new_customer') }}</option>
                            @foreach ($preview['duplicates']['customers'] as $customer)
                                <option value="{{ $customer['id'] }}">{{ $customer['name'] }}</option>
                            @endforeach
                        </select>
                    @endif
                    @if (($preview['duplicates']['brands'] ?? []) !== [])
                        <label class="mt-3 block text-xs font-medium text-gray-500">{{ __('operator.nav.brands') }}</label>
                        <select wire:model.live="existing_brand_id" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900">
                            <option value="">{{ __('operator.prospects.conversion.create_new_brand') }}</option>
                            @foreach ($preview['duplicates']['brands'] as $brand)
                                <option value="{{ $brand['id'] }}">{{ $brand['name'] }}</option>
                            @endforeach
                        </select>
                    @endif
                    @if (($preview['duplicates']['digital_assets'] ?? []) !== [])
                        <p class="mt-3 text-xs font-medium text-gray-500">{{ __('operator.nav.digital_assets') }}</p>
                        <ul class="mt-1 list-disc pl-5 text-sm text-gray-700 dark:text-gray-200">
                            @foreach ($preview['duplicates']['digital_assets'] as $asset)
                                <li>{{ $asset['name'] }} — {{ $asset['primary_url'] ?? $asset['domain'] }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <label class="mt-3 flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model.live="confirm_create_despite_duplicates" data-testid="confirm-create-despite-duplicates" />
                        {{ __('operator.prospects.conversion.confirm_new_anyway') }}
                    </label>
                </x-ta.card>
            @endif

            <x-ta.card>
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ __('operator.prospects.conversion.assets') }}</h2>
                @foreach ($preview['promotable_assets'] ?? [] as $asset)
                    <label class="mt-2 flex items-center gap-2 text-sm {{ $asset['supported'] ? '' : 'opacity-60' }}">
                        <input type="checkbox" wire:model.live="selected_assets" value="{{ $asset['key'] }}" data-testid="promotable-asset" @disabled(! $asset['supported']) />
                        {{ $asset['label'] }} — {{ $asset['url'] }}
                        @unless ($asset['supported'])
                            <span class="text-xs text-gray-500">{{ __('operator.prospects.conversion.unsupported_asset') }}</span>
                        @endunless
                    </label>
                @endforeach
                <label class="mt-3 flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model.live="promote_observed_summary" />
                    {{ __('operator.prospects.conversion.promote_summary') }}
                </label>
            </x-ta.card>

            <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white">{{ __('operator.prospects.conversion.confirm') }}</button>
        </form>
    @endif
</div>
