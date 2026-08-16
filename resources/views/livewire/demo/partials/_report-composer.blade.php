@php($preview = $preview ?? null)
@php($reportSnapshots = $reportSnapshots ?? ['items' => [], 'empty' => true])
@php($reportSnapshotDetail = $reportSnapshotDetail ?? null)
@php($sections = $preview['sections'] ?? [])
@php($story = $preview['story'] ?? [])

<div class="space-y-4">
    {{-- Production Brand: create immutable Report Snapshot (Prompt 59). Demo catalog keeps preview. --}}
    @if ($preview === null)
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-600">{{ __('operator.reports.snapshot_label') }}</p>
                    <h3 class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator.reports.create_snapshot') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('operator.reports.create_snapshot_help') }}</p>
                </div>
                <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('operator.reports.language') }}">
                    <button type="button" wire:click="setReportLanguage('en')" @class(['rounded-lg px-2.5 py-1 text-xs font-semibold', 'bg-brand-500 text-white' => $reportLanguage === 'en', 'ring-1 ring-inset ring-gray-300 dark:ring-gray-700' => $reportLanguage !== 'en'])>EN</button>
                    <button type="button" wire:click="setReportLanguage('tr')" @class(['rounded-lg px-2.5 py-1 text-xs font-semibold', 'bg-brand-500 text-white' => $reportLanguage === 'tr', 'ring-1 ring-inset ring-gray-300 dark:ring-gray-700' => $reportLanguage !== 'tr'])>TR</button>
                </div>
            </div>

            <label class="mt-4 block text-sm">
                <span class="text-gray-500">{{ __('operator.reports.optional_title') }}</span>
                <input type="text" wire:model="snapshotTitle" maxlength="200" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" />
            </label>

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <button type="button"
                    wire:click="createReportSnapshot"
                    wire:loading.attr="disabled"
                    class="rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white disabled:opacity-60">
                    <span wire:loading.remove wire:target="createReportSnapshot">{{ __('operator.reports.generate_snapshot') }}</span>
                    <span wire:loading wire:target="createReportSnapshot">{{ __('operator.reports.generating') }}</span>
                </button>
                <p class="text-xs text-gray-400">{{ __('operator.reports.delivery_unavailable') }}</p>
            </div>

            @if ($snapshotStatusMessage !== '')
                <p @class([
                    'mt-2 text-sm',
                    'text-emerald-600' => $snapshotStatusTone === 'success',
                    'text-rose-600' => $snapshotStatusTone === 'error',
                    'text-gray-500' => $snapshotStatusTone === 'info',
                ])>{{ $snapshotStatusMessage }}</p>
            @endif
        </div>

        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('operator.reports.history_title') }}</h3>
            <p class="mt-1 text-xs text-gray-400">{{ __('operator.reports.history_subtitle') }}</p>
            <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($reportSnapshots['items'] ?? [] as $row)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <div>
                            <p class="font-medium text-gray-800 dark:text-white/90">{{ $row['title'] }}</p>
                            <p class="text-xs text-gray-500">
                                {{ $row['period_start'] }} → {{ $row['period_end'] }}
                                · {{ __('operator.reports.generated_at') }} {{ $row['generated_at'] }}
                            </p>
                        </div>
                        <button type="button" wire:click="$set('snapshotId', '{{ $row['id'] }}')" class="rounded px-2 py-1 text-xs font-medium text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50">
                            {{ __('operator.reports.view_snapshot') }}
                        </button>
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-gray-500">{{ __('operator.reports.empty_snapshots') }}</li>
                @endforelse
            </ul>
        </div>

        @if ($reportSnapshotDetail)
            <article class="rounded-xl bg-white p-6 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700" aria-label="{{ __('operator.reports.snapshot_detail') }}">
                <header class="border-b border-gray-100 pb-4 dark:border-gray-700">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('operator.reports.historical_snapshot') }}</p>
                    <h3 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $reportSnapshotDetail['title'] }}</h3>
                    <p class="text-sm text-gray-500">
                        {{ $reportSnapshotDetail['brand_name'] }} · {{ $reportSnapshotDetail['customer_name'] }}
                        · {{ __('operator.reports.reporting_period') }} {{ $reportSnapshotDetail['period_start'] }} → {{ $reportSnapshotDetail['period_end'] }}
                    </p>
                    <p class="mt-1 text-xs text-gray-400">
                        {{ __('operator.reports.generated_at') }} {{ $reportSnapshotDetail['generated_at'] }}
                        · {{ __('operator.reports.locale') }} {{ $reportSnapshotDetail['locale'] }}
                    </p>
                    <button type="button" wire:click="clearReportSnapshotView" class="mt-2 text-xs text-brand-600 hover:underline">{{ __('operator.reports.close_detail') }}</button>
                </header>

                @php($content = $reportSnapshotDetail['content'] ?? [])
                @php($frozenStory = $content['story'] ?? [])
                @php($outcomes = $frozenStory['business_outcomes'] ?? [])

                <section class="mt-5">
                    <h4 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('operator.value.what_observed') }}</h4>
                    <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                        @forelse ($frozenStory['observations'] ?? [] as $item)
                            <li>· {{ $item['text'] ?? '' }}</li>
                        @empty
                            <li class="text-gray-400">{{ __('operator.reports.section_empty') }}</li>
                        @endforelse
                    </ul>
                </section>

                <section class="mt-5">
                    <h4 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('operator.value.open_opportunities') }}</h4>
                    <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                        @forelse ($frozenStory['opportunities'] ?? [] as $opp)
                            <li>· {{ $opp['title'] ?? '' }}</li>
                        @empty
                            <li class="text-gray-400">{{ __('operator.reports.section_empty') }}</li>
                        @endforelse
                    </ul>
                </section>

                <section class="mt-5">
                    <h4 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('operator.value.what_did') }}</h4>
                    <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                        @forelse ($frozenStory['completed_work'] ?? [] as $item)
                            <li>· {{ $item['text'] ?? '' }}</li>
                        @empty
                            <li class="text-gray-400">{{ __('operator.reports.section_empty') }}</li>
                        @endforelse
                    </ul>
                </section>

                <section class="mt-5">
                    <h4 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('operator.value.business_outcome') }}</h4>
                    @if ($outcomes['available'] ?? false)
                        <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                            {{ __('operator.outcomes.qualified_leads') }}: {{ $outcomes['qualified_leads'] ?? '—' }}
                            · {{ __('operator.outcomes.consultations') }}: {{ $outcomes['consultations'] ?? '—' }}
                            · {{ __('operator.outcomes.patients') }}: {{ $outcomes['patients'] ?? '—' }}
                            · {{ __('operator.outcomes.revenue') }}: {{ $outcomes['revenue_display'] ?? ($outcomes['revenue'] ?? '—') }}
                        </p>
                    @else
                        <p class="mt-2 text-sm text-gray-500">{{ $outcomes['unavailable_message'] ?? __('operator.value.business_unavailable') }}</p>
                    @endif
                    <p class="mt-2 text-xs text-gray-400">{{ $frozenStory['causation_disclaimer'] ?? '' }}</p>
                </section>

                <p class="mt-5 text-xs text-gray-400">{{ __('operator.reports.delivery_unavailable') }}</p>
            </article>
        @endif
    @else
        {{-- Demo catalog preview only — not production history --}}
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
            <p class="mt-1 text-xs text-amber-600">{{ __('operator.reports.demo_not_persisted') }}</p>
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

            @if ($sections['business_outcomes'] ?? true)
                <section class="mt-5">
                    <h4 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('operator.value.business_outcome') }}</h4>
                    @if (($story['business_outcomes']['available'] ?? false))
                        <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                            {{ __('operator.outcomes.qualified_leads') }}: {{ $story['business_outcomes']['qualified_leads'] }}
                            · {{ __('operator.outcomes.consultations') }}: {{ $story['business_outcomes']['consultations'] }}
                            · {{ __('operator.outcomes.patients') }}: {{ $story['business_outcomes']['patients'] }}
                        </p>
                    @else
                        <p class="mt-2 text-sm text-gray-500">{{ __('operator.value.business_unavailable') }}</p>
                    @endif
                </section>
            @endif

            <p class="mt-5 text-xs text-gray-400">{{ __('operator.reports.delivery_unavailable') }}</p>
        </article>
    @endif
</div>
