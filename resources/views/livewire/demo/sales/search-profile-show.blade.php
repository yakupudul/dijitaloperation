<div class="space-y-5">
    @include('livewire.demo.partials.flash')
    @include('livewire.demo.sales.partials.sales-subnav', ['current' => 'search-profiles'])

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('operator.search-profiles') }}" class="text-sm text-gray-500 hover:text-brand-600">← {{ __('operator.nav.search_profiles') }}</a>
            <h1 class="mt-2 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $profile->name }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $serviceLabel }}</p>
        </div>
        <a href="{{ route('operator.search-profile.edit', ['profileId' => $profile->id]) }}" class="text-sm text-brand-700 hover:underline">{{ __('operator.sales_intent.edit_profile') }}</a>
    </div>

    <x-ta.card>
        <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ __('operator.sales_intent.query_plan') }}</h2>
        <ul class="mt-2 list-disc pl-5 text-sm text-gray-700 dark:text-gray-200">
            @foreach ($queryPlan as $query)
                <li>{{ $query }}</li>
            @endforeach
        </ul>
        <p class="mt-3 text-sm text-gray-500">
            {{ __('operator.sales_intent.provider') }}: DataForSEO ·
            {{ $fixturesEnabled ? __('operator.sales_intent.reality_partial') : ($paidCallsEnabled ? __('operator.sales_intent.reality_real') : __('operator.sales_intent.reality_unavailable')) }}
        </p>

        @if (! $fixturesEnabled && ! $paidCallsEnabled)
            <div class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20">
                Live Sales Intent araması kapalı. Önce DataForSEO entegrasyonunda “Sales Intent live discovery” ayarını etkinleştirin.
                <a href="{{ route('operator.integrations.dataforseo') }}" wire:navigate class="ml-1 font-semibold underline">DataForSEO ayarları →</a>
            </div>
        @endif

        @if ($paidCallsEnabled && ! $fixturesEnabled)
            <p class="mt-2 text-sm text-amber-700">{{ __('operator.sales_intent.paid_warning') }}</p>
            <label class="mt-2 flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="paid_consent" /> {{ __('operator.sales_intent.paid_consent') }}
            </label>
        @endif

        <button type="button" wire:click="runSearch" wire:loading.attr="disabled"
            @disabled(! $fixturesEnabled && ! $paidCallsEnabled)
            class="mt-4 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-40">{{ __('operator.sales_intent.run_search') }}</button>
    </x-ta.card>

    <x-ta.card>
        <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ __('operator.sales_intent.runs') }}</h2>
        @forelse ($runs as $run)
            <p class="mt-2 text-sm text-gray-700 dark:text-gray-200">
                {{ $run->status->value }} · {{ __('operator.sales_intent.query_count') }}: {{ $run->query_count }} ·
                {{ __('operator.sales_intent.signal_count') }}: {{ $run->signal_count }}
                @if ($run->paid_call)
                    · {{ __('operator.sales_intent.paid') }}
                    @if ($run->reported_cost_usd !== null)
                        · ${{ number_format((float) $run->reported_cost_usd, 4) }}
                    @endif
                @endif
            </p>
        @empty
            <p class="mt-2 text-sm text-gray-500">{{ __('operator.sales_intent.no_runs') }}</p>
        @endforelse
    </x-ta.card>
</div>
