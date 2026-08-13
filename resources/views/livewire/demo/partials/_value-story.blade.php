@php($story = $story ?? [])
<div class="space-y-4">
    <p class="text-xs text-gray-400">{{ __('operator.value.story_deterministic') }} · {{ $story['period_label'] ?? '' }}</p>

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('operator.value.what_observed') }}</h3>
        <ul class="mt-3 space-y-2">
            @foreach ($story['observations'] ?? [] as $item)
                <li class="flex flex-wrap items-start justify-between gap-2 text-sm">
                    <span class="text-gray-800 dark:text-white/90">{{ $item['text'] }}</span>
                    <a href="{{ $item['source_url'] }}" wire:navigate class="shrink-0 text-xs font-medium text-brand-600 hover:underline">{{ $item['source_label'] }}</a>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('operator.value.what_decided') }}</h3>
        <ul class="mt-3 space-y-2">
            @foreach ($story['decisions'] ?? [] as $item)
                <li class="text-sm text-gray-800 dark:text-white/90">
                    {{ $item['title'] }}
                    <span class="text-xs text-gray-500">· {{ $item['status'] }}</span>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('operator.value.what_did') }}</h3>
        <ul class="mt-3 space-y-2">
            @foreach ($story['completed_work'] ?? [] as $item)
                <li class="flex flex-wrap items-start justify-between gap-2 text-sm">
                    <span class="text-gray-800 dark:text-white/90">{{ $item['text'] }}</span>
                    <a href="{{ $item['source_url'] }}" wire:navigate class="shrink-0 text-xs font-medium text-brand-600 hover:underline">{{ __('operator.actions.open') }}</a>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('operator.value.what_changed') }}</h3>
        <p class="mt-1 text-xs text-gray-400">{{ $story['causation_disclaimer'] ?? __('operator.value.observed_after') }}</p>
        <ul class="mt-3 space-y-2">
            @foreach ($story['operational_changes'] ?? [] as $item)
                <li class="text-sm">
                    <p class="font-medium text-gray-800 dark:text-white/90">{{ $item['text'] }}</p>
                    <p class="text-xs text-gray-500">{{ $item['note'] }}</p>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('operator.value.business_outcome') }}</h3>
        @if (($story['business_outcomes']['available'] ?? false))
            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div><p class="text-xs text-gray-500">{{ __('operator.outcomes.platform_results') }}</p><p class="text-xl font-bold tabular-nums">{{ $story['business_outcomes']['platform_leads'] }}</p></div>
                <div><p class="text-xs text-gray-500">{{ __('operator.outcomes.qualified_leads') }}</p><p class="text-xl font-bold tabular-nums">{{ $story['business_outcomes']['qualified_leads'] }}</p></div>
                <div><p class="text-xs text-gray-500">{{ __('operator.outcomes.consultations') }}</p><p class="text-xl font-bold tabular-nums">{{ $story['business_outcomes']['consultations'] }}</p></div>
                <div><p class="text-xs text-gray-500">{{ __('operator.outcomes.patients') }}</p><p class="text-xl font-bold tabular-nums">{{ $story['business_outcomes']['patients'] }}</p></div>
            </div>
            <p class="mt-2 text-xs text-gray-400">{{ __('operator.outcomes.provenance') }}: {{ $story['business_outcomes']['provenance'] }}</p>
        @else
            <p class="mt-2 text-sm text-gray-500">{{ $story['business_outcomes']['unavailable_message'] ?? __('operator.value.business_unavailable') }}</p>
        @endif
    </section>

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('operator.value.open_opportunities') }}</h3>
        <ul class="mt-3 space-y-2">
            @foreach ($story['opportunities'] ?? [] as $opp)
                <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                    <div>
                        <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $opp['title'] }}</p>
                        <p class="text-xs text-gray-500">{{ $opp['goal'] }} · {{ $opp['service'] }}</p>
                    </div>
                    <a href="{{ $opp['source_url'] }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">{{ __('operator.actions.review') }}</a>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('operator.value.what_next') }}</h3>
        <ul class="mt-3 space-y-2">
            @foreach ($story['next_actions'] ?? [] as $item)
                <li class="flex flex-wrap items-start justify-between gap-2 text-sm">
                    <span class="text-gray-800 dark:text-white/90">{{ $item['text'] }}</span>
                    <a href="{{ $item['source_url'] }}" wire:navigate class="shrink-0 text-xs font-medium text-brand-600 hover:underline">{{ __('operator.actions.open') }}</a>
                </li>
            @endforeach
        </ul>
    </section>
</div>
