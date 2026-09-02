@php
    $projection = $technicalHealth['projection'];
    $summary = $technicalHealth['summary'];
    $severityCounts = $technicalHealth['severity_counts'];
    $infrastructure = $technicalHealth['infrastructure'];
    $pagination = $technicalHealth['pagination'];
    $selectedHealthPage = $technicalHealth['selected'];
    $severityTones = [
        'critical' => 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300',
        'high' => 'border-orange-200 bg-orange-50 text-orange-700 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-300',
        'medium' => 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300',
        'low' => 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-300',
        'info' => 'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300',
    ];
@endphp

<div class="space-y-5" data-website-technical-health>
    <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="flex flex-col gap-4 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator.website.technical_health.title') }}</h2>
                    <span @class([
                        'inline-flex rounded-full border px-2.5 py-1 text-xs font-medium',
                        'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300' => $technicalHealth['coverage']['state'] === 'collected',
                        'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300' => $technicalHealth['coverage']['state'] === 'not_collected',
                        'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300' => $technicalHealth['coverage']['state'] === 'projection_failed',
                        'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300' => in_array($technicalHealth['coverage']['state'], ['not_configured', 'unavailable'], true),
                    ])>{{ __('operator.website.pages_content.states.'.$technicalHealth['coverage']['state']) }}</span>
                </div>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.website.technical_health.subtitle') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                @if ($technicalHealth['coverage']['watermark'])
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('operator.website.technical_health.last_scan', ['when' => \Carbon\CarbonImmutable::parse($technicalHealth['coverage']['watermark'])->diffForHumans()]) }}</p>
                @endif
                <button type="button" wire:click="refreshData" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600">{{ __('operator.website.actions.refresh_data') }}</button>
            </div>
        </div>
    </section>

    @if (! $technicalHealth['available'])
        <section class="rounded-xl bg-white px-5 py-12 text-center ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('operator.website.technical_health.empty.title') }}</h3>
            <p class="mx-auto mt-2 max-w-2xl text-sm text-gray-500 dark:text-gray-400">{{ __('operator.website.technical_health.empty.body') }}</p>
            <button type="button" wire:click="refreshData" class="mt-5 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600">{{ __('operator.website.actions.refresh_data') }}</button>
        </section>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['key' => 'observed_pages', 'label' => __('operator.website.technical_health.cards.observed'), 'hint' => __('operator.website.technical_health.cards.observed_hint')],
                ['key' => 'reachable_pages', 'label' => __('operator.website.technical_health.cards.reachable'), 'hint' => __('operator.website.technical_health.cards.reachable_hint')],
                ['key' => 'affected_pages', 'label' => __('operator.website.technical_health.cards.affected'), 'hint' => __('operator.website.technical_health.cards.affected_hint')],
                ['key' => 'pagespeed_measured', 'label' => __('operator.website.technical_health.cards.pagespeed'), 'hint' => __('operator.website.technical_health.cards.pagespeed_hint')],
            ] as $card)
                <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
                    <p class="mt-3 text-2xl font-semibold text-gray-900 dark:text-white">{{ $summary[$card['key']] === null ? '—' : number_format($summary[$card['key']], 0, ',', '.') }}</p>
                    <p class="mt-1 text-xs text-gray-400">{{ $card['hint'] }}</p>
                </section>
            @endforeach
        </div>

        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('operator.website.technical_health.severity_title') }}</h3>
                <p class="mt-1 text-xs text-gray-500">{{ __('operator.website.technical_health.severity_hint') }}</p>
            </div>
            <div class="grid gap-px bg-gray-100 sm:grid-cols-5 dark:bg-gray-800">
                @foreach (['critical', 'high', 'medium', 'low', 'info'] as $severity)
                    <div class="flex items-center justify-between gap-3 bg-white px-5 py-4 dark:bg-gray-900">
                        <span class="inline-flex rounded-full border px-2 py-0.5 text-xs font-medium {{ $severityTones[$severity] }}">{{ __('operator.website.technical_health.severity.'.$severity) }}</span>
                        <span class="text-lg font-semibold text-gray-900 dark:text-white">{{ $severityCounts[$severity] === null ? '—' : number_format($severityCounts[$severity], 0, ',', '.') }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.technical_health.tls.title') }}</h3>
                        <p class="mt-1 text-xs text-gray-500">{{ __('operator.website.technical_health.tls.hint') }}</p>
                    </div>
                    @if ($infrastructure['available'])
                        <span @class([
                            'rounded-full border px-2.5 py-1 text-xs font-medium',
                            'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300' => $infrastructure['tls_present'] === true && ($infrastructure['expires_in_days'] === null || $infrastructure['expires_in_days'] >= 30),
                            'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300' => $infrastructure['tls_present'] === true && $infrastructure['expires_in_days'] !== null && $infrastructure['expires_in_days'] >= 0 && $infrastructure['expires_in_days'] < 30,
                            'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300' => $infrastructure['tls_present'] === false || ($infrastructure['expires_in_days'] !== null && $infrastructure['expires_in_days'] < 0),
                            'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300' => $infrastructure['tls_present'] === null,
                        ])>{{ $infrastructure['tls_present'] === true ? __('operator.website.technical_health.tls.present') : ($infrastructure['tls_present'] === false ? __('operator.website.technical_health.tls.missing') : __('operator.website.technical_health.not_measured')) }}</span>
                    @endif
                </div>
                @if ($infrastructure['available'])
                    <dl class="mt-5 grid gap-4 sm:grid-cols-2 text-sm">
                        <div><dt class="text-xs text-gray-400">{{ __('operator.website.technical_health.tls.host') }}</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $infrastructure['host'] ?: '—' }}</dd></div>
                        <div><dt class="text-xs text-gray-400">{{ __('operator.website.technical_health.tls.issuer') }}</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $infrastructure['issuer'] ?: '—' }}</dd></div>
                        <div><dt class="text-xs text-gray-400">{{ __('operator.website.technical_health.tls.valid_to') }}</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $infrastructure['valid_to'] ?: '—' }}</dd></div>
                        <div><dt class="text-xs text-gray-400">{{ __('operator.website.technical_health.tls.remaining') }}</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $infrastructure['expires_in_days'] !== null ? __('operator.website.technical_health.tls.days', ['count' => $infrastructure['expires_in_days']]) : '—' }}</dd></div>
                    </dl>
                    @if ($infrastructure['error'])<p class="mt-4 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{{ __('operator.website.technical_health.tls.error', ['error' => $infrastructure['error']]) }}</p>@endif
                @else
                    <p class="mt-5 text-sm text-gray-500">{{ __('operator.website.technical_health.tls.no_data') }}</p>
                @endif
            </section>

            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.technical_health.pagespeed.title') }}</h3>
                <p class="mt-1 text-xs text-gray-500">{{ __('operator.website.technical_health.pagespeed.hint') }}</p>
                <div class="mt-5 flex items-end gap-3">
                    <p class="text-3xl font-semibold text-gray-900 dark:text-white">{{ $summary['pagespeed_measured'] === null ? '—' : number_format($summary['pagespeed_measured'], 0, ',', '.') }}</p>
                    <p class="pb-1 text-sm text-gray-500">{{ __('operator.website.technical_health.pagespeed.coverage', ['total' => $summary['observed_pages'] === null ? '—' : number_format($summary['observed_pages'], 0, ',', '.')]) }}</p>
                </div>
                <p class="mt-4 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500 dark:bg-white/[0.03] dark:text-gray-400">{{ __('operator.website.technical_health.pagespeed.lab_note') }}</p>
            </section>
        </div>

        @if ($technicalHealth['page_data_available'])
            <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.technical_health.observations.title') }}</h3>
                    <p class="mt-1 text-xs text-gray-500">{{ __('operator.website.technical_health.observations.hint') }}</p>
                </div>
                @forelse (array_slice($technicalHealth['issue_groups'], 0, 10) as $group)
                    <div class="flex flex-col gap-2 border-b border-gray-100 px-5 py-3 last:border-0 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $severityTones[$group['severity']] }}">{{ __('operator.website.technical_health.severity.'.$group['severity']) }}</span>
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $group['label'] }}</p>
                            </div>
                            <p class="mt-1 font-mono text-[11px] text-gray-400">{{ $group['code'] }}</p>
                        </div>
                        <p class="shrink-0 text-xs text-gray-500">{{ __('operator.website.technical_health.observations.page_count', ['pages' => $group['pages'], 'observations' => $group['observations']]) }}</p>
                    </div>
                @empty
                    <p class="px-5 py-10 text-center text-sm text-gray-500">{{ __('operator.website.technical_health.observations.none') }}</p>
                @endforelse
            </section>

            <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="border-b border-gray-100 p-4 dark:border-gray-800">
                    <div class="grid gap-3 lg:grid-cols-[minmax(260px,1fr)_260px]">
                        <input wire:model.live.debounce.400ms="healthSearch" type="search" placeholder="{{ __('operator.website.technical_health.search') }}" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                        <select wire:model.live="healthFilter" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                            @foreach (['all', 'issues', 'critical_high', 'accessibility', 'redirects', 'application', 'metadata', 'indexability', 'schema', 'performance', 'clean'] as $filter)
                                <option value="{{ $filter }}">{{ __('operator.website.technical_health.filters.'.$filter) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div wire:loading.delay class="border-b border-gray-100 px-5 py-3 text-xs text-brand-600 dark:border-gray-800 dark:text-brand-400" wire:target="healthSearch,healthFilter,setHealthPage,selectHealthProfile">{{ __('operator.website.technical_health.loading') }}</div>

                <div class="hidden overflow-x-auto lg:block">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-gray-50/80 text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-white/[0.02] dark:text-gray-400">
                            <tr>
                                <th class="px-5 py-3">{{ __('operator.website.technical_health.table.page') }}</th>
                                <th class="px-4 py-3">HTTP</th>
                                <th class="px-4 py-3">{{ __('operator.website.technical_health.table.observations') }}</th>
                                <th class="px-4 py-3">{{ __('operator.website.technical_health.table.document') }}</th>
                                <th class="px-4 py-3">Schema</th>
                                <th class="px-4 py-3">PageSpeed</th>
                                <th class="px-5 py-3 text-right"><span class="sr-only">{{ __('operator.website.technical_health.table.details') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($technicalHealth['rows'] as $row)
                                @php($lcp = data_get($row, 'performance.primary_lcp_ms'))
                                <tr class="align-top hover:bg-gray-50/70 dark:hover:bg-white/[0.02]">
                                    <td class="max-w-sm px-5 py-4"><p class="truncate font-medium text-gray-900 dark:text-white">{{ $row['title'] ?: __('operator.website.pages_content.untitled') }}</p><a href="{{ $row['url'] }}" target="_blank" rel="noopener" class="mt-1 block truncate text-xs text-brand-600 hover:underline dark:text-brand-400">{{ $row['url'] }}</a><p class="mt-2 text-[11px] text-gray-400">{{ $row['last_observed_human'] ?: '—' }}</p></td>
                                    <td class="whitespace-nowrap px-4 py-4"><p class="font-medium text-gray-900 dark:text-white">{{ $row['http']['status_code'] !== null ? 'HTTP '.$row['http']['status_code'] : '—' }}</p><p class="mt-1 text-xs text-gray-500">{{ $row['http']['redirect_count'] !== null ? __('operator.website.technical_health.redirects', ['count' => $row['http']['redirect_count']]) : __('operator.website.technical_health.not_measured') }}</p></td>
                                    <td class="whitespace-nowrap px-4 py-4">
                                        @if ($row['highest_severity'])
                                            <span class="inline-flex rounded-full border px-2 py-0.5 text-xs font-medium {{ $severityTones[$row['highest_severity']] }}">{{ __('operator.website.technical_health.severity.'.$row['highest_severity']) }}</span><p class="mt-2 text-xs text-gray-500">{{ trans_choice('operator.website.technical_health.observation_count', count($row['issues']), ['count' => count($row['issues'])]) }}</p>
                                        @else
                                            <span class="text-xs font-medium text-emerald-600 dark:text-emerald-300">{{ __('operator.website.technical_health.clean') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-4 text-xs">
                                        <div class="grid grid-cols-2 gap-x-3 gap-y-1">
                                            @foreach ([['title_present', 'Title'], ['meta_description_present', 'Meta'], ['h1_present', 'H1'], ['canonical_count', 'Canonical']] as [$key, $label])
                                                @php($value = $row['metadata'][$key])
                                                @php($passed = $key === 'canonical_count' ? $value === 1 : $value === true)
                                                <span class="{{ $value === null ? 'text-gray-400' : ($passed ? 'text-emerald-600 dark:text-emerald-300' : 'text-rose-600 dark:text-rose-300') }}">{{ $label }} {{ $value === null ? '?' : ($passed ? '✓' : '×') }}</span>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-4"><p class="font-medium text-gray-900 dark:text-white">{{ $row['schema']['block_count'] !== null ? __('operator.website.technical_health.schema_block_count', ['count' => $row['schema']['block_count']]) : '—' }}</p>@if (($row['schema']['malformed_blocks'] ?? 0) > 0)<p class="mt-1 text-xs text-rose-600">{{ __('operator.website.technical_health.schema_malformed', ['count' => $row['schema']['malformed_blocks']]) }}</p>@endif</td>
                                    <td class="whitespace-nowrap px-4 py-4"><p class="font-medium text-gray-900 dark:text-white">{{ $lcp !== null ? number_format((float) $lcp, 0, ',', '.').' ms' : '—' }}</p><p class="mt-1 text-xs text-gray-400">{{ $lcp !== null ? __('operator.website.technical_health.lab') : __('operator.website.technical_health.not_measured') }}</p></td>
                                    <td class="px-5 py-4 text-right"><button type="button" wire:click="selectHealthProfile({{ $row['id'] }})" class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ __('operator.website.technical_health.table.details') }}</button></td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-5 py-12 text-center text-sm text-gray-500">{{ __('operator.website.technical_health.no_results') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="divide-y divide-gray-100 lg:hidden dark:divide-gray-800">
                    @forelse ($technicalHealth['rows'] as $row)
                        <article class="p-4"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $row['title'] ?: __('operator.website.pages_content.untitled') }}</p><p class="mt-1 truncate text-xs text-gray-500">{{ $row['url'] }}</p></div><button type="button" wire:click="selectHealthProfile({{ $row['id'] }})" class="shrink-0 text-xs font-semibold text-brand-600 dark:text-brand-400">{{ __('operator.website.technical_health.table.details') }}</button></div><div class="mt-3 flex flex-wrap items-center gap-2"><span class="rounded bg-gray-100 px-2 py-1 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-300">{{ $row['http']['status_code'] !== null ? 'HTTP '.$row['http']['status_code'] : 'HTTP —' }}</span>@if($row['highest_severity'])<span class="rounded-full border px-2 py-0.5 text-xs {{ $severityTones[$row['highest_severity']] }}">{{ __('operator.website.technical_health.severity.'.$row['highest_severity']) }} · {{ count($row['issues']) }}</span>@else<span class="text-xs text-emerald-600">{{ __('operator.website.technical_health.clean') }}</span>@endif</div></article>
                    @empty
                        <p class="px-5 py-12 text-center text-sm text-gray-500">{{ __('operator.website.technical_health.no_results') }}</p>
                    @endforelse
                </div>

                <div class="flex flex-col gap-3 border-t border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
                    <p class="text-xs text-gray-500">{{ __('operator.website.pages_content.pagination', ['from' => $pagination['from'], 'to' => $pagination['to'], 'total' => number_format($pagination['total'], 0, ',', '.')]) }}</p>
                    <div class="flex gap-2"><button type="button" wire:click="setHealthPage({{ max(1, $pagination['page'] - 1) }})" @disabled($pagination['page'] <= 1) class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 disabled:cursor-not-allowed disabled:opacity-40 dark:ring-gray-700">{{ __('operator.website.pages_content.previous') }}</button><button type="button" wire:click="setHealthPage({{ min($pagination['last_page'], $pagination['page'] + 1) }})" @disabled($pagination['page'] >= $pagination['last_page']) class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 disabled:cursor-not-allowed disabled:opacity-40 dark:ring-gray-700">{{ __('operator.website.pages_content.next') }}</button></div>
                </div>
            </section>

            @if ($selectedHealthPage)
                <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" id="technical-health-detail">
                    <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-start sm:justify-between dark:border-gray-800"><div class="min-w-0"><p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.website.technical_health.detail.eyebrow') }}</p><h3 class="mt-1 truncate text-lg font-semibold text-gray-900 dark:text-white">{{ $selectedHealthPage['title'] ?: __('operator.website.pages_content.untitled') }}</h3><a href="{{ $selectedHealthPage['url'] }}" target="_blank" rel="noopener" class="mt-1 block truncate text-sm text-brand-600 hover:underline dark:text-brand-400">{{ $selectedHealthPage['url'] }} ↗</a></div><button type="button" wire:click="closeHealthProfile" class="rounded-lg px-3 py-2 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">{{ __('operator.website.pages_content.detail.close') }}</button></div>
                    <div class="grid gap-px bg-gray-100 xl:grid-cols-2 dark:bg-gray-800">
                        <div class="bg-white p-5 dark:bg-gray-900"><h4 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.technical_health.detail.http_title') }}</h4><dl class="mt-4 grid gap-4 sm:grid-cols-2 text-sm"><div><dt class="text-xs text-gray-400">HTTP</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $selectedHealthPage['http']['status_code'] ?? '—' }}</dd></div><div><dt class="text-xs text-gray-400">{{ __('operator.website.technical_health.detail.reachable') }}</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $selectedHealthPage['http']['reachable'] === null ? '—' : ($selectedHealthPage['http']['reachable'] ? __('operator.website.technical_health.yes') : __('operator.website.technical_health.no')) }}</dd></div><div><dt class="text-xs text-gray-400">{{ __('operator.website.technical_health.detail.redirect_count') }}</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $selectedHealthPage['http']['redirect_count'] ?? '—' }}</dd></div><div><dt class="text-xs text-gray-400">{{ __('operator.website.technical_health.detail.content_type') }}</dt><dd class="mt-1 break-all font-medium text-gray-900 dark:text-white">{{ $selectedHealthPage['http']['content_type'] ?: '—' }}</dd></div></dl>@if($selectedHealthPage['http']['final_url'])<p class="mt-4 break-all text-xs text-gray-500">{{ __('operator.website.technical_health.detail.final_url') }}: {{ $selectedHealthPage['http']['final_url'] }}</p>@endif</div>
                        <div class="bg-white p-5 dark:bg-gray-900">
                            <h4 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.technical_health.detail.observations_title') }}</h4>
                            <p class="mt-1 text-xs text-gray-500">{{ __('operator.website.technical_health.verification.hint') }}</p>
                            <div class="mt-4 space-y-2">
                                @forelse($selectedHealthPage['issues'] as $issue)
                                    <div class="rounded-lg bg-gray-50 px-3 py-3 dark:bg-white/[0.03]">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $issue['label'] }}</p>
                                                <p class="mt-1 font-mono text-[11px] text-gray-400">{{ $issue['code'] }}</p>
                                            </div>
                                            <span class="shrink-0 rounded-full border px-2 py-0.5 text-xs {{ $severityTones[$issue['severity']] }}">{{ __('operator.website.technical_health.severity.'.$issue['severity']) }}</span>
                                        </div>
                                        <button
                                            type="button"
                                            wire:click="verifyTechnicalIssue({{ $selectedHealthPage['id'] }}, '{{ $issue['code'] }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="verifyTechnicalIssue({{ $selectedHealthPage['id'] }}, '{{ $issue['code'] }}')"
                                            class="mt-3 inline-flex items-center rounded-lg bg-white px-3 py-2 text-xs font-semibold text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-gray-900 dark:text-brand-300 dark:ring-brand-500/30"
                                        >
                                            <span wire:loading.remove wire:target="verifyTechnicalIssue({{ $selectedHealthPage['id'] }}, '{{ $issue['code'] }}')">{{ __('operator.website.technical_health.verification.action') }}</span>
                                            <span wire:loading wire:target="verifyTechnicalIssue({{ $selectedHealthPage['id'] }}, '{{ $issue['code'] }}')">{{ __('operator.website.technical_health.verification.starting') }}</span>
                                        </button>
                                    </div>
                                @empty
                                    <p class="text-sm text-emerald-600 dark:text-emerald-300">{{ __('operator.website.technical_health.clean') }}</p>
                                @endforelse
                            </div>
                        </div>
                    </div>
                    <div class="grid gap-px border-t border-gray-100 bg-gray-100 xl:grid-cols-3 dark:border-gray-800 dark:bg-gray-800">
                        <div class="bg-white p-5 dark:bg-gray-900"><h4 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.technical_health.detail.document_title') }}</h4><dl class="mt-4 space-y-3 text-sm">@foreach ([['title_present', 'Title'], ['meta_description_present', 'Meta description'], ['h1_present', 'H1']] as [$key, $label])<div class="flex items-center justify-between gap-3"><dt class="text-gray-500">{{ $label }}</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $selectedHealthPage['metadata'][$key] === null ? '—' : ($selectedHealthPage['metadata'][$key] ? __('operator.website.technical_health.present') : __('operator.website.technical_health.missing')) }}</dd></div>@endforeach<div class="flex items-center justify-between gap-3"><dt class="text-gray-500">Canonical</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $selectedHealthPage['metadata']['canonical_count'] ?? '—' }}</dd></div><div class="flex items-center justify-between gap-3"><dt class="text-gray-500">Robots</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $selectedHealthPage['metadata']['robots'] ?: '—' }}</dd></div></dl></div>
                        <div class="bg-white p-5 dark:bg-gray-900"><h4 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.technical_health.detail.schema_content_title') }}</h4><dl class="mt-4 space-y-3 text-sm"><div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('operator.website.technical_health.detail.schema_blocks') }}</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $selectedHealthPage['schema']['block_count'] ?? '—' }}</dd></div><div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('operator.website.technical_health.detail.valid_schema') }}</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $selectedHealthPage['schema']['valid_blocks'] ?? '—' }}</dd></div><div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('operator.website.technical_health.detail.malformed_schema') }}</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $selectedHealthPage['schema']['malformed_blocks'] ?? '—' }}</dd></div><div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('operator.website.pages_content.detail.word_count') }}</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $selectedHealthPage['content']['word_count'] ?? '—' }}</dd></div><div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('operator.website.technical_health.detail.internal_links') }}</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $selectedHealthPage['links']['internal'] ?? '—' }}</dd></div></dl>@if($selectedHealthPage['schema']['types'] !== [])<div class="mt-4 flex flex-wrap gap-1">@foreach($selectedHealthPage['schema']['types'] as $type)<span class="rounded bg-gray-100 px-2 py-1 text-[11px] text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ $type }}</span>@endforeach</div>@endif</div>
                        <div class="bg-white p-5 dark:bg-gray-900"><h4 class="font-semibold text-gray-900 dark:text-white">PageSpeed</h4><p class="mt-1 text-xs text-gray-500">{{ __('operator.website.technical_health.pagespeed.lab_note') }}</p><div class="mt-4 space-y-3">@forelse($selectedHealthPage['performance']['measurements'] as $measurement)<div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><div class="flex justify-between gap-3"><p class="text-xs font-medium uppercase text-gray-500">{{ $measurement['strategy'] ?: '—' }}</p><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $measurement['lcp_ms'] !== null ? number_format($measurement['lcp_ms'], 0, ',', '.').' ms' : '—' }}</p></div><p class="mt-2 text-[11px] text-gray-400">{{ $measurement['fetch_time'] ?: $measurement['observed_at'] ?: '—' }}</p></div>@empty<p class="text-sm text-gray-500">{{ __('operator.website.technical_health.pagespeed.no_data') }}</p>@endforelse</div></div>
                    </div>
                    <details class="border-t border-gray-100 px-5 py-4 text-xs dark:border-gray-800"><summary class="cursor-pointer font-medium text-gray-600 dark:text-gray-300">{{ __('operator.website.technical_health.detail.provenance') }}</summary><div class="mt-3 flex flex-wrap gap-2">@foreach($selectedHealthPage['source_records'] as $dataset => $record)<span class="rounded bg-gray-100 px-2 py-1 font-mono text-gray-500 dark:bg-gray-800 dark:text-gray-300">{{ $dataset }} #{{ data_get($record, 'id') }}</span>@endforeach</div></details>
                </section>
            @endif
        @endif

        <p class="text-xs text-gray-400">{{ __('operator.website.technical_health.fact_note') }}</p>
    @endif
</div>
