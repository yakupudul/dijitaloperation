<div class="space-y-5">
    @include('livewire.demo.partials.flash')
    @include('livewire.demo.sales.partials.sales-subnav', ['current' => 'intent-radar'])

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('operator.intent-radar') }}" class="text-sm text-gray-500 hover:text-brand-600">← {{ __('operator.nav.intent_radar') }}</a>
            <h1 class="mt-2 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $signal->source_title ?: __('operator.sales_intent.signal') }}</h1>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ $signal->source_url }}" target="_blank" rel="noopener noreferrer" class="rounded-lg bg-white px-3 py-2 text-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">{{ __('operator.sales_intent.open_source') }}</a>
            @if ($signal->prospect_id)
                <a href="{{ route('operator.prospect', ['prospectId' => $signal->prospect_id]) }}" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white">{{ __('operator.nav.prospects') }}</a>
            @else
                <button type="button" wire:click="createProspect" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white">{{ __('operator.sales_intent.create_prospect') }}</button>
                <button type="button" wire:click="dismiss" class="rounded-lg bg-white px-3 py-2 text-sm ring-1 ring-gray-200 dark:bg-gray-800">{{ __('operator.sales_intent.dismiss') }}</button>
            @endif
        </div>
    </div>

    <div class="grid gap-5 lg:grid-cols-2">
        <x-ta.card>
            <h2 class="text-lg font-semibold">{{ __('operator.sales_intent.fields.snippet') }}</h2>
            <p class="mt-2 text-sm text-gray-700 dark:text-gray-200">{{ $signal->observed_snippet }}</p>
            <p class="mt-3 text-xs uppercase text-gray-400">{{ __('operator.sales_intent.fetched_source') }}</p>
            <p class="mt-1 text-sm text-gray-700 dark:text-gray-200">{{ $signal->fetched_source_excerpt ?: __('operator.sales_intent.verification.'.$signal->source_verification_state->value) }}</p>
        </x-ta.card>
        <x-ta.card>
            <dl class="space-y-2 text-sm">
                <div><dt class="text-gray-500">{{ __('operator.sales_intent.fields.service') }}</dt><dd>{{ $serviceLabel }}</dd></div>
                <div><dt class="text-gray-500">{{ __('operator.sales_intent.fields.intent_confidence') }}</dt><dd>{{ $signal->intent_confidence ?? '—' }} · {{ $signal->purchase_stage?->value ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">{{ __('operator.sales_intent.fields.identity_confidence') }}</dt><dd>{{ $signal->identity_status->value }} · {{ $signal->identity_confidence ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">{{ __('operator.sales_intent.why_lead') }}</dt><dd>{{ $signal->classification_reason ?: '—' }}</dd></div>
                <div><dt class="text-gray-500">{{ __('operator.prospects.fields.company_name') }}</dt><dd>{{ $signal->detected_company_name ?: __('operator.sales_intent.anonymous') }}</dd></div>
            </dl>
        </x-ta.card>
    </div>
</div>
