<x-ta.card>
    <div class="grid gap-6 lg:grid-cols-2">
        <div class="space-y-4">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.prospects.identity_label') }}</p>
                <form wire:submit.prevent="updateStatus" class="mt-2 space-y-3">
                    <x-ta.form.field :label="__('operator.prospects.identity_label')">
                        <select wire:model.live="identity_status" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            @foreach ($identityOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-ta.form.field>
                    <x-ta.form.field :label="__('operator.forms.status')">
                        <select wire:model.live="status" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            @foreach ($statusOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-ta.form.field>
                    <button type="submit" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white hover:bg-brand-600">{{ __('operator.actions.save') }}</button>
                </form>
            </div>

            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.prospects.section_contact') }}</p>
                <dl class="mt-2 space-y-2 text-sm">
                    <div><dt class="text-gray-500">{{ __('operator.prospects.fields.contact_name') }}</dt><dd class="text-gray-800 dark:text-white/90">{{ $prospect['contact_name'] ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('operator.prospects.fields.contact_email') }}</dt><dd class="text-gray-800 dark:text-white/90">{{ $prospect['contact_email'] ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('operator.prospects.fields.contact_phone') }}</dt><dd class="text-gray-800 dark:text-white/90">{{ $prospect['contact_phone'] ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('operator.prospects.fields.country') }}</dt><dd class="text-gray-800 dark:text-white/90">{{ $prospect['country'] ?? '—' }} {{ $prospect['city'] ?? '' }}</dd></div>
                </dl>
            </div>
        </div>

        <div class="space-y-4">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.prospects.fields.inquiry') }}</p>
                <p class="mt-2 text-sm text-gray-700 dark:text-gray-200">{{ $prospect['inquiry'] ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.prospects.fields.website') }}</p>
                <p class="mt-2 text-sm">
                    @if (!empty($prospect['website_url']))
                        <a href="{{ $prospect['website_url'] }}" target="_blank" rel="noopener noreferrer" class="text-brand-600 hover:underline">{{ $prospect['website_url'] }}</a>
                    @else
                        <span class="text-gray-500">—</span>
                    @endif
                </p>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.prospects.research_label') }}</p>
                <p class="mt-2 text-sm text-gray-700 dark:text-gray-200">{{ $prospect['research_status_label'] }}</p>
                @if (!empty($prospect['research_message']))
                    <p class="mt-1 text-xs text-gray-500">{{ $prospect['research_message'] }}</p>
                @endif
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.prospects.fields.owner') }}</p>
                <p class="mt-2 text-sm text-gray-700 dark:text-gray-200">{{ $prospect['owner_name'] ?? '—' }}</p>
            </div>
        </div>
    </div>
</x-ta.card>
