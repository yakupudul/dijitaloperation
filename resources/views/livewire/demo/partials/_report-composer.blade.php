@php($preview = $preview ?? [])
@php($sections = $preview['sections'] ?? [])
@php($story = $preview['story'] ?? [])
<div class="space-y-4">
    <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-600">{{ $preview['demo_label'] ?? __('operator.reports.demo_preview') }}</p>
                <h3 class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator.reports.composer') }}</h3>
            </div>
            <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('operator.reports.language') }}">
                <button type="button" wire:click="setReportLanguage('en')" @class(['rounded-lg px-2.5 py-1 text-xs font-semibold', 'bg-brand-500 text-white' => $reportLanguage === 'en', 'ring-1 ring-inset ring-gray-300 dark:ring-gray-700' => $reportLanguage !== 'en'])>EN</button>
                <button type="button" wire:click="setReportLanguage('tr')" @class(['rounded-lg px-2.5 py-1 text-xs font-semibold', 'bg-brand-500 text-white' => $reportLanguage === 'tr', 'ring-1 ring-inset ring-gray-300 dark:ring-gray-700' => $reportLanguage !== 'tr'])>TR</button>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap gap-2" role="group" aria-label="{{ __('operator.reports.tone') }}">
            <button type="button" wire:click="setReportTone('client')" @class(['rounded-lg px-3 py-1.5 text-xs font-medium', 'bg-brand-500 text-white' => $reportTone === 'client', 'ring-1 ring-inset ring-gray-300' => $reportTone !== 'client'])>{{ __('operator.reports.tone_client') }}</button>
            <button type="button" wire:click="setReportTone('internal')" @class(['rounded-lg px-3 py-1.5 text-xs font-medium', 'bg-brand-500 text-white' => $reportTone === 'internal', 'ring-1 ring-inset ring-gray-300' => $reportTone !== 'internal'])>{{ __('operator.reports.tone_internal') }}</button>
        </div>

        <fieldset class="mt-4">
            <legend class="text-xs font-semibold uppercase text-gray-400">{{ __('operator.reports.sections') }}</legend>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($reportSections as $key => $enabled)
                    <button type="button" wire:click="toggleReportSection('{{ $key }}')"
                        @class([
                            'rounded-lg px-2.5 py-1 text-xs font-medium',
                            'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-400' => $enabled,
                            'bg-gray-100 text-gray-500 line-through dark:bg-gray-800' => ! $enabled,
                        ])>{{ __('operator.reports.section.'.$key) }}</button>
                @endforeach
            </div>
        </fieldset>

        <label class="mt-4 block text-sm">
            <span class="text-gray-500">{{ __('operator.reports.operator_note') }}</span>
            <input type="text" wire:model.blur="reportOperatorNote" wire:change="refreshReportPreview" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" />
        </label>

        <button type="button" wire:click="refreshReportPreview" class="mt-3 rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white">{{ __('operator.reports.refresh') }}</button>
        <p class="mt-2 text-xs text-gray-400">{{ $preview['future_delivery_note'] ?? '' }}</p>
    </div>

    <article class="rounded-xl bg-white p-6 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700" aria-label="{{ __('operator.reports.preview') }}">
        <header class="border-b border-gray-100 pb-4 dark:border-gray-700">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ $preview['demo_label'] }}</p>
            <h3 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $preview['brand_name'] }}</h3>
            <p class="text-sm text-gray-500">{{ $preview['customer_name'] }} · {{ $preview['period_label'] }}</p>
            @if (! empty($preview['operator_note']))
                <p class="mt-2 text-sm italic text-gray-600 dark:text-gray-300">{{ $preview['operator_note'] }}</p>
            @endif
        </header>

        @if ($sections['executive_summary'] ?? true)
            <section class="mt-5">
                <h4 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('operator.reports.section.executive_summary') }}</h4>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                    @foreach ($preview['executive_summary'] ?? [] as $bullet)
                        <li>{{ $bullet }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($sections['observations'] ?? true)
            <section class="mt-5">
                <h4 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('operator.value.what_observed') }}</h4>
                <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                    @foreach ($story['observations'] ?? [] as $item)
                        <li>· {{ $item['text'] }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($sections['completed_work'] ?? true)
            <section class="mt-5">
                <h4 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('operator.value.what_did') }}</h4>
                <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                    @foreach ($story['completed_work'] ?? [] as $item)
                        <li>· {{ $item['text'] }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($sections['operational_outcomes'] ?? true)
            <section class="mt-5">
                <h4 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('operator.value.what_changed') }}</h4>
                <p class="mt-1 text-xs text-gray-400">{{ $story['causation_disclaimer'] ?? '' }}</p>
                <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                    @foreach ($story['operational_changes'] ?? [] as $item)
                        <li>· {{ $item['text'] }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($sections['business_outcomes'] ?? true)
            <section class="mt-5">
                <h4 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('operator.value.business_outcome') }}</h4>
                @if (($story['business_outcomes']['available'] ?? false))
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                        {{ __('operator.outcomes.qualified_leads') }}: {{ $story['business_outcomes']['qualified_leads'] }}
                        · {{ __('operator.outcomes.consultations') }}: {{ $story['business_outcomes']['consultations'] }}
                        · {{ __('operator.outcomes.patients') }}: {{ $story['business_outcomes']['patients'] }}
                    </p>
                    <p class="mt-1 text-xs text-gray-400">{{ $story['business_outcomes']['provenance'] }}</p>
                @else
                    <p class="mt-2 text-sm text-gray-500">{{ __('operator.value.business_unavailable') }}</p>
                @endif
            </section>
        @endif

        @if ($sections['opportunities'] ?? true)
            <section class="mt-5">
                <h4 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('operator.value.open_opportunities') }}</h4>
                <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                    @foreach ($story['opportunities'] ?? [] as $opp)
                        <li>· {{ $opp['title'] }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($sections['next_actions'] ?? true)
            <section class="mt-5">
                <h4 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('operator.value.what_next') }}</h4>
                <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                    @foreach ($story['next_actions'] ?? [] as $item)
                        <li>· {{ $item['text'] }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($sections['supporting_metrics'] ?? true)
            <section class="mt-5 border-t border-gray-100 pt-5 dark:border-gray-700">
                <h4 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('operator.reports.section.supporting_metrics') }}</h4>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($preview['supporting_metrics'] ?? [] as $block)
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $block['channel'] }}</p>
                            <p class="text-[11px] text-gray-400">{{ $block['provenance'] }}</p>
                            <ul class="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-300">
                                @foreach ($block['metrics'] as $metric)
                                    <li>{{ $metric['label'] }}: <strong>{{ $metric['value'] }}</strong></li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </article>
</div>
