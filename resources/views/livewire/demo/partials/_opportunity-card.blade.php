@php
    $opp = $opportunity ?? null;
@endphp

@if ($opp)
    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('operator.opportunities.card.title') }}</h2>
            @if ($opp['is_new'] ?? false)
                <x-ta.badge color="info" size="sm">{{ __('operator.opportunities.badges.new') }}</x-ta.badge>
            @endif
        </div>
        <p class="mt-2 text-sm font-medium text-gray-800 dark:text-white/90">{{ $opp['title'] }}</p>
        <p class="mt-1 text-xs text-gray-500">{{ $opp['goal_title'] ?? '' }} · {{ $opp['service_label'] ?? '' }}</p>
        <x-demo.commercial-context
            class="mt-2"
            :service="$opp['service_label'] ?? null"
            :goal="$opp['goal_title'] ?? null"
            :offering="$opp['offering'] ?? null"
        />
        <div class="mt-3 flex flex-wrap gap-2">
            <a href="{{ route('demo.opportunities', ['brand' => $opp['brand_id'] ?? '', 'view' => 'open']) }}" wire:navigate
                class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                {{ __('operator.opportunities.card.view') }}
            </a>
        </div>
    </section>
@endif
