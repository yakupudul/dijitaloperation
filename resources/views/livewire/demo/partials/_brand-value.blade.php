{{-- Brand → Value (Milestone 4) — Overview / Story / Outcomes / Decisions / Reports --}}
@if ($tab === 'value')
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator.value.title') }}</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.value.subtitle') }}</p>
            </div>
            @include('livewire.demo.partials.period-bar')
        </div>

        <div class="flex flex-wrap gap-2" role="tablist" aria-label="{{ __('operator.value.title') }}">
            @foreach ([
                'overview' => __('operator.value.sections.overview'),
                'story' => __('operator.value.sections.story'),
                'outcomes' => __('operator.value.sections.outcomes'),
                'decisions' => __('operator.value.sections.decisions'),
                'reports' => __('operator.value.sections.reports'),
            ] as $key => $label)
                <button type="button" wire:click="setValueSection('{{ $key }}')"
                    @class([
                        'rounded-lg px-3 py-1.5 text-sm font-medium transition',
                        'bg-brand-500 text-white' => $valueSection === $key,
                        'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $valueSection !== $key,
                    ])>{{ $label }}</button>
            @endforeach
        </div>

        @if ($valueSection === 'overview' && $valueSummary)
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700" aria-labelledby="value-summary-heading">
                <h3 id="value-summary-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('operator.value.summary') }} · {{ $valueSummary['period_label'] }}</h3>
                <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                        <p class="text-xs text-gray-500">{{ __('operator.value.observed') }}</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $valueSummary['observed'] }}</p>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                        <p class="text-xs text-gray-500">{{ __('operator.value.decided') }}</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $valueSummary['decided'] }}</p>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                        <p class="text-xs text-gray-500">{{ __('operator.value.delivered') }}</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $valueSummary['delivered'] }}</p>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                        <p class="text-xs text-gray-500">{{ __('operator.value.operational_count') }}</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $valueSummary['operational_outcomes'] }}</p>
                    </div>
                </div>
                @if (($valueSummary['business']['available'] ?? false))
                    <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">
                        {{ __('operator.outcomes.qualified_leads') }}: <strong>{{ $valueSummary['business']['qualified_leads'] }}</strong>
                        · {{ __('operator.outcomes.consultations') }}: <strong>{{ $valueSummary['business']['consultations'] }}</strong>
                        · {{ __('operator.value.open_growth') }}: <strong>{{ $valueSummary['open_opportunities'] }}</strong>
                        · {{ __('operator.value.next_focus') }}: <strong>{{ $valueSummary['next'] }}</strong>
                    </p>
                @else
                    <p class="mt-4 text-sm text-gray-500">{{ __('operator.value.business_unavailable') }}</p>
                @endif
                <p class="mt-2 text-xs text-gray-400">{{ __('operator.value.no_magic_score') }}</p>
            </section>

            @if ($operationalOutcomes)
                <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('operator.outcomes.operational') }}</h3>
                    <ul class="mt-3 space-y-2">
                        @foreach ($operationalOutcomes as $row)
                            <li class="rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/[0.03]">
                                <p class="font-medium text-gray-800 dark:text-white/90">{{ $row['label'] }}</p>
                                <p class="text-xs text-gray-500">{{ $row['detail'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($valueStory)
                <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('operator.value.work_delivered') }}</h3>
                        <button type="button" wire:click="setValueSection('story')" class="text-xs font-medium text-brand-600 hover:underline">{{ __('operator.value.open_story') }}</button>
                    </div>
                    <ul class="mt-3 space-y-1.5 text-sm text-gray-600 dark:text-gray-300">
                        @foreach (array_slice($valueStory['completed_work'], 0, 5) as $item)
                            <li>· {{ $item['text'] }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @endif

        @if ($valueSection === 'story' && $valueStory)
            @include('livewire.demo.partials._value-story', ['story' => $valueStory])
        @endif

        @if ($valueSection === 'outcomes')
            @if ($businessOutcomes)
                <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator.outcomes.title') }}</h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.outcomes.subtitle') }} · {{ $businessOutcomes['period_label'] ?? $outcomePeriod }}</p>
                        </div>
                        <button type="button" wire:click="openOutcomeForm" class="rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">{{ __('operator.outcomes.update') }}</button>
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                            <p class="text-xs text-gray-500">{{ $businessOutcomes['platform_leads_label'] }}</p>
                            <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $businessOutcomes['platform_leads'] }}</p>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                            <p class="text-xs text-gray-500">{{ $businessOutcomes['qualified_leads_label'] }}</p>
                            <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $businessOutcomes['qualified_leads'] }}</p>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                            <p class="text-xs text-gray-500">{{ $businessOutcomes['consultations_label'] }}</p>
                            <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $businessOutcomes['consultations'] }}</p>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                            <p class="text-xs text-gray-500">{{ $businessOutcomes['patients_label'] }}</p>
                            <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $businessOutcomes['patients'] }}</p>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                            <p class="text-xs text-gray-500">{{ $businessOutcomes['revenue_label'] }}</p>
                            <p class="mt-1 text-lg font-semibold text-gray-500 dark:text-gray-400">{{ $businessOutcomes['revenue_display'] }}</p>
                        </div>
                    </div>
                    <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('operator.outcomes.qualified_rate') }}: {{ $businessOutcomes['qualified_rate'] }}</p>
                    <p class="mt-1 text-xs text-gray-400">{{ $businessOutcomes['note'] }} · {{ $businessOutcomes['provenance'] ?? 'Demo' }}</p>
                </section>

                @if ($showOutcomeForm)
                    <form wire:submit="saveBusinessOutcomes" class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <label class="block text-sm"><span class="text-gray-500">{{ __('operator.outcomes.platform_results') }}</span>
                                <input wire:model="outcome_platform_leads" type="number" min="0" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" /></label>
                            <label class="block text-sm"><span class="text-gray-500">{{ __('operator.outcomes.qualified_leads') }}</span>
                                <input wire:model="outcome_qualified_leads" type="number" min="0" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" /></label>
                            <label class="block text-sm"><span class="text-gray-500">{{ __('operator.outcomes.consultations') }}</span>
                                <input wire:model="outcome_consultations" type="number" min="0" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" /></label>
                            <label class="block text-sm"><span class="text-gray-500">{{ __('operator.outcomes.patients') }}</span>
                                <input wire:model="outcome_patients" type="number" min="0" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" /></label>
                            <label class="block text-sm sm:col-span-2"><span class="text-gray-500">{{ __('operator.outcomes.note') }}</span>
                                <input wire:model="outcome_note" type="text" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" /></label>
                        </div>
                        <div class="mt-3 flex gap-2">
                            <x-ta.button type="submit" size="sm">{{ __('operator.actions.save') }}</x-ta.button>
                            <x-ta.button type="button" wire:click="cancelOutcomeForm" size="sm" variant="outline">{{ __('operator.actions.cancel') }}</x-ta.button>
                        </div>
                    </form>
                @endif
            @else
                <p class="text-sm text-gray-500">{{ __('operator.value.business_unavailable') }}</p>
            @endif

            <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('operator.outcomes.operational') }}</h3>
                <p class="mt-1 text-xs text-gray-400">{{ __('operator.value.observed_after') }}</p>
                <ul class="mt-3 space-y-2">
                    @forelse ($operationalOutcomes as $row)
                        <li class="rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/[0.03]">
                            <p class="font-medium text-gray-800 dark:text-white/90">{{ $row['label'] }}</p>
                            <p class="text-xs text-gray-500">{{ $row['detail'] }}</p>
                        </li>
                    @empty
                        <li class="text-sm text-gray-500">{{ __('operator.value.no_evidence') }}</li>
                    @endforelse
                </ul>
            </section>
        @endif

        @if ($valueSection === 'decisions')
            <div>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator.value.decision_history') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.value.decision_subtitle') }}</p>
            </div>
            <div class="space-y-3">
                @forelse ($valueDecisions as $decision)
                    <article class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $decision['title'] }}</p>
                                <p class="mt-1 text-xs text-gray-500">{{ $decision['status'] }} · {{ $decision['by'] }} · {{ $decision['date'] }}</p>
                            </div>
                            <a href="{{ $decision['source_url'] }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">{{ $decision['source'] }}</a>
                        </div>
                        @if (! empty($decision['why']))
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('operator.value.why') }}: {{ $decision['why'] }}</p>
                        @endif
                    </article>
                @empty
                    <p class="text-sm text-gray-500">{{ __('operator.value.no_decisions') }}</p>
                @endforelse
            </div>
            <p class="text-xs text-gray-400">{{ __('operator.value.decision_vs_activity') }}</p>
        @endif

        @if ($valueSection === 'reports' && $reportPreview)
            @include('livewire.demo.partials._report-composer', ['preview' => $reportPreview])
        @endif
    </div>
@endif
