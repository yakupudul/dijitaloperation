@php
    $projection = $pagesContent['projection'];
    $counts = $pagesContent['counts'];
    $pagination = $pagesContent['pagination'];
    $selectedPage = $pagesContent['selected'];
    $coverageBySource = collect($pagesContent['coverage'])->keyBy('key');
    $healthySourceCount = collect($pagesContent['coverage'])->where('state', 'collected')->count();
    $activeFilter = $pagesContent['filters']['filter'];
@endphp

<div class="space-y-5" data-website-pages-content>
    <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="flex flex-col gap-4 px-5 py-5 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator.website.pages_content.title') }}</h2>
                    @if ($projection['available'])
                        <span @class([
                            'inline-flex rounded-full border px-2.5 py-1 text-xs font-medium',
                            'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300' => $projection['status'] === 'completed',
                            'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300' => $projection['status'] === 'partial',
                        ])>{{ __('operator.website.pages_content.projection_status.'.$projection['status']) }}</span>
                    @endif
                </div>
                <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ __('operator.website.pages_content.subtitle') }}</p>
            </div>

            @if ($projection['available'])
                <details class="group w-full rounded-lg border border-gray-200 bg-gray-50/70 lg:w-80 dark:border-gray-700 dark:bg-white/[0.03]">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm">
                        <span>
                            <span class="font-medium text-gray-900 dark:text-white">{{ __('operator.website.pages_content.data_health') }}</span>
                            <span class="mt-0.5 block text-xs text-gray-500">{{ __('operator.website.pages_content.source_health', ['healthy' => $healthySourceCount, 'total' => count($pagesContent['coverage'])]) }}</span>
                        </span>
                        <span class="text-gray-400 transition group-open:rotate-180">⌄</span>
                    </summary>
                    <div class="space-y-3 border-t border-gray-200 px-4 py-3 dark:border-gray-700">
                        @foreach ($pagesContent['coverage'] as $source)
                            <div class="flex items-start justify-between gap-3 text-xs">
                                <div class="min-w-0">
                                    <p class="font-medium text-gray-700 dark:text-gray-200">{{ $source['label'] }}</p>
                                    <p class="mt-0.5 truncate text-gray-400">{{ $source['watermark'] ? \Carbon\CarbonImmutable::parse($source['watermark'])->diffForHumans() : __('operator.website.pages_content.no_collection_time') }}</p>
                                    @if ($source['state'] === 'projection_failed')
                                        <p class="mt-1 break-all font-mono text-[10px] text-amber-600 dark:text-amber-300">{{ __('operator.website.pages_content.projection_error_reference', ['run' => $source['projection_run_id'] ?? '—', 'code' => $source['error_code'] ?? 'UNKNOWN']) }}</p>
                                    @endif
                                </div>
                                <span @class([
                                    'shrink-0 rounded-full border px-2 py-0.5 text-[10px] font-medium',
                                    'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300' => $source['state'] === 'collected',
                                    'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300' => in_array($source['state'], ['not_collected', 'projection_failed'], true),
                                    'border-gray-200 bg-white text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300' => in_array($source['state'], ['not_configured', 'unavailable'], true),
                                ])>{{ $source['state_label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif
        </div>

        @if (! $projection['available'])
            <div class="border-t border-gray-100 px-5 py-10 text-center dark:border-gray-800">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('operator.website.pages_content.empty.title') }}</h3>
                <p class="mx-auto mt-2 max-w-xl text-sm text-gray-500 dark:text-gray-400">{{ __('operator.website.pages_content.empty.body') }}</p>
                <div class="mt-5 flex flex-wrap justify-center gap-2">
                    <button type="button" wire:click="refreshData" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600">{{ __('operator.website.actions.refresh_data') }}</button>
                    <a href="{{ route('operator.asset.sources', ['assetId' => $asset->id]) }}" wire:navigate class="rounded-lg bg-white px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700">{{ __('operator.website.actions.data_sources') }}</a>
                </div>
            </div>
        @else
            <div class="border-t border-gray-100 px-5 py-3 text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
                <span>{{ __('operator.website.pages_content.period', ['start' => data_get($projection, 'period.start'), 'end' => data_get($projection, 'period.end')]) }}</span>
                @if ($projection['completed_at'])
                    <span class="mx-2 text-gray-300 dark:text-gray-700">·</span>
                    <span>{{ __('operator.website.pages_content.projected_at', ['when' => \Carbon\CarbonImmutable::parse($projection['completed_at'])->diffForHumans()]) }}</span>
                @endif
            </div>
        @endif
    </section>

    @if ($projection['available'])
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('operator.website.pages_content.cards.public_pages') }}</p>
                <p class="mt-2 text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format((int) $counts['public_pages'], 0, ',', '.') }}</p>
                <p class="mt-1 text-xs text-gray-400">{{ __('operator.website.pages_content.cards.public_pages_hint') }}</p>
            </section>
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('operator.website.pages_content.cards.html_coverage') }}</p>
                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $counts['html_coverage_percent'] !== null ? number_format((float) $counts['html_coverage_percent'], 1, ',', '.').'%' : '—' }}</span>
                </div>
                <p class="mt-2 text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format((int) $counts['html_captured'], 0, ',', '.') }}<span class="text-sm font-normal text-gray-400"> / {{ number_format((int) $counts['public_pages'], 0, ',', '.') }}</span></p>
                <p class="mt-1 text-xs text-gray-400">{{ __('operator.website.pages_content.cards.html_coverage_hint') }}</p>
            </section>
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('operator.website.pages_content.cards.cms_matched') }}</p>
                <p class="mt-2 text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format((int) $counts['matched'], 0, ',', '.') }}</p>
                <p class="mt-1 text-xs text-gray-400">{{ __('operator.website.pages_content.cards.cms_matched_hint', ['count' => number_format((int) $counts['cms_review'], 0, ',', '.')]) }}</p>
            </section>
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('operator.website.pages_content.cards.meaningful_changes') }}</p>
                <p class="mt-2 text-2xl font-semibold text-gray-900 dark:text-white">{{ $counts['meaningful_changed'] === null ? '—' : number_format((int) $counts['meaningful_changed'], 0, ',', '.') }}</p>
                <p class="mt-1 text-xs text-gray-400">{{ $counts['meaningful_changed'] === null ? __('operator.website.pages_content.cards.baseline_pending') : __('operator.website.pages_content.cards.meaningful_changes_hint', ['count' => number_format((int) $counts['semantic_compared'], 0, ',', '.')]) }}</p>
            </section>
        </div>

        <details class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-3.5 text-sm font-medium text-gray-700 dark:text-gray-200">
                <span>{{ __('operator.website.pages_content.reconciliation.title') }}</span><span class="text-gray-400">⌄</span>
            </summary>
            <div class="grid gap-px border-t border-gray-100 bg-gray-100 sm:grid-cols-2 xl:grid-cols-6 dark:border-gray-800 dark:bg-gray-800">
                @foreach ([
                    ['matched', __('operator.website.pages_content.reconciliation.matched')],
                    ['cms_without_public', __('operator.website.pages_content.reconciliation.cms_only')],
                    ['public_without_cms', __('operator.website.pages_content.reconciliation.public_only')],
                    ['expected_family_members', __('operator.website.pages_content.reconciliation.expected_family')],
                    ['platform_only', __('operator.website.pages_content.reconciliation.platform_only')],
                    ['pages', __('operator.website.pages_content.reconciliation.total')],
                ] as [$key, $label])
                    <div class="bg-white px-5 py-4 dark:bg-gray-900"><p class="text-xs text-gray-400">{{ $label }}</p><p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ number_format((int) $counts[$key], 0, ',', '.') }}</p></div>
                @endforeach
            </div>
        </details>

        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="border-b border-gray-100 px-4 pt-4 dark:border-gray-800">
                <div class="flex gap-2 overflow-x-auto pb-4">
                    @foreach ([
                        'all' => __('operator.website.pages_content.views.all'),
                        'public' => __('operator.website.pages_content.views.public'),
                        'search_visible' => __('operator.website.pages_content.views.search_visible'),
                        'traffic' => __('operator.website.pages_content.views.traffic'),
                        'meaningful_changed' => __('operator.website.pages_content.views.meaningful_changed'),
                        'cms_mismatch' => __('operator.website.pages_content.views.cms_mismatch'),
                        'families' => __('operator.website.pages_content.views.families'),
                    ] as $key => $label)
                        <button type="button" wire:click="setContentFilter('{{ $key }}')" @class([
                            'shrink-0 rounded-full border px-3 py-1.5 text-xs font-medium transition',
                            'border-brand-200 bg-brand-50 text-brand-700 dark:border-brand-500/30 dark:bg-brand-500/10 dark:text-brand-300' => $activeFilter === $key,
                            'border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]' => $activeFilter !== $key,
                        ])>{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            <div class="flex flex-col gap-3 border-b border-gray-100 p-4 md:flex-row md:items-center dark:border-gray-800">
                <label class="min-w-0 flex-1">
                    <span class="sr-only">{{ __('operator.website.pages_content.search') }}</span>
                    <input wire:model.live.debounce.400ms="contentSearch" type="search" placeholder="{{ __('operator.website.pages_content.search') }}" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                </label>
                <select wire:model.live="contentSource" aria-label="{{ __('operator.website.pages_content.source_label') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                    <option value="all">{{ __('operator.website.pages_content.sources.all') }}</option>
                    <option value="website">{{ __('operator.website.pages_content.sources.website') }}</option>
                    <option value="wordpress">{{ __('operator.website.pages_content.sources.wordpress') }}</option>
                    <option value="gsc">{{ __('operator.website.pages_content.sources.gsc') }}</option>
                    <option value="ga4">{{ __('operator.website.pages_content.sources.ga4') }}</option>
                </select>
                <select wire:model.live="contentSort" aria-label="{{ __('operator.website.pages_content.sort.label') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                    <option value="recent">{{ __('operator.website.pages_content.sort.recent') }}</option>
                    <option value="url">{{ __('operator.website.pages_content.sort.url') }}</option>
                    <option value="oldest">{{ __('operator.website.pages_content.sort.oldest') }}</option>
                </select>
                <details class="relative">
                    <summary class="cursor-pointer list-none rounded-lg px-3 py-2.5 text-sm font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">{{ __('operator.website.pages_content.advanced') }}</summary>
                    <div class="absolute right-0 z-20 mt-2 w-64 rounded-xl bg-white p-3 shadow-xl ring-1 ring-black/5 dark:bg-gray-900 dark:ring-white/10">
                        <label class="text-xs font-medium text-gray-500">{{ __('operator.website.pages_content.filter_label') }}</label>
                        <select wire:model.live="contentFilter" class="mt-2 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                            <option value="all">{{ __('operator.website.pages_content.filters.all') }}</option>
                            <option value="public">{{ __('operator.website.pages_content.views.public') }}</option>
                            <option value="search_visible">{{ __('operator.website.pages_content.views.search_visible') }}</option>
                            <option value="traffic">{{ __('operator.website.pages_content.views.traffic') }}</option>
                            <option value="meaningful_changed">{{ __('operator.website.pages_content.views.meaningful_changed') }}</option>
                            <option value="cms_mismatch">{{ __('operator.website.pages_content.views.cms_mismatch') }}</option>
                            <option value="families">{{ __('operator.website.pages_content.views.families') }}</option>
                            <option value="matched">{{ __('operator.website.pages_content.filters.matched') }}</option>
                            <option value="published">{{ __('operator.website.pages_content.filters.published') }}</option>
                            <option value="draft">{{ __('operator.website.pages_content.filters.draft') }}</option>
                            <option value="raw_changed">{{ __('operator.website.pages_content.filters.raw_changed') }}</option>
                            <option value="cms_without_public">{{ __('operator.website.pages_content.filters.cms_without_public') }}</option>
                            <option value="public_without_cms">{{ __('operator.website.pages_content.filters.public_without_cms') }}</option>
                        </select>
                    </div>
                </details>
            </div>

            <div wire:loading.delay class="border-b border-gray-100 px-5 py-3 text-xs text-brand-600 dark:border-gray-800 dark:text-brand-400" wire:target="contentSearch,contentFilter,contentSource,contentSort,setContentFilter,setContentPage,selectPageProfile">
                {{ __('operator.website.pages_content.loading') }}
            </div>

            <div class="hidden overflow-x-auto lg:block">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-gray-50/80 text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-white/[0.02] dark:text-gray-400">
                        <tr>
                            <th class="px-5 py-3">{{ __('operator.website.pages_content.table.page') }}</th>
                            <th class="px-4 py-3">{{ __('operator.website.pages_content.table.type_family') }}</th>
                            <th class="px-4 py-3">{{ __('operator.website.pages_content.table.published') }}</th>
                            <th class="px-4 py-3">{{ __('operator.website.pages_content.table.search') }}</th>
                            <th class="px-4 py-3">{{ __('operator.website.pages_content.table.behavior') }}</th>
                            <th class="px-4 py-3">{{ __('operator.website.pages_content.table.updated') }}</th>
                            <th class="px-5 py-3 text-right"><span class="sr-only">{{ __('operator.website.pages_content.table.details') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($pagesContent['rows'] as $row)
                            <tr class="align-top hover:bg-gray-50/70 dark:hover:bg-white/[0.02]">
                                <td class="max-w-sm px-5 py-4">
                                    <p class="truncate font-medium text-gray-900 dark:text-white" title="{{ $row['title'] }}">{{ $row['title'] ?: __('operator.website.pages_content.untitled') }}</p>
                                    <a href="{{ $row['url'] }}" target="_blank" rel="noopener" class="mt-1 block truncate text-xs text-brand-600 hover:underline dark:text-brand-400" title="{{ $row['url'] }}">{{ $row['url'] }}</a>
                                    <div class="mt-2 flex flex-wrap gap-1">@foreach ($row['sources'] as $source)<span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-gray-500 dark:bg-gray-800 dark:text-gray-300">{{ __('operator.website.pages_content.source_short.'.$source) }}</span>@endforeach</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4">
                                    <p class="text-xs font-medium text-gray-700 dark:text-gray-200">{{ __('operator.website.pages_content.page_kinds.'.$row['family']['kind']) }}</p>
                                    @if ($row['family']['member_count'] > 1)
                                        <p class="mt-1 text-xs text-brand-600 dark:text-brand-400">{{ __('operator.website.pages_content.family.members', ['count' => $row['family']['member_count']]) }}</p>
                                    @endif
                                    @if ($row['family']['page_number'])<p class="mt-1 text-xs text-gray-400">{{ __('operator.website.pages_content.family.page_number', ['page' => $row['family']['page_number']]) }}</p>@endif
                                    @if ($row['wordpress']['status'])<p class="mt-1 text-xs text-gray-400">WP · {{ $row['wordpress']['status'] }}</p>@endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-4">
                                    @if ($row['public']['http_status'] !== null)
                                        <span @class([
                                            'inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold',
                                            'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300' => $row['public']['http_status'] >= 200 && $row['public']['http_status'] < 300,
                                            'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300' => $row['public']['http_status'] < 200 || $row['public']['http_status'] >= 400,
                                            'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300' => $row['public']['http_status'] >= 300 && $row['public']['http_status'] < 400,
                                        ])>HTTP {{ $row['public']['http_status'] }}</span>
                                    @else<span class="text-gray-400">—</span>@endif
                                    <p class="mt-2 text-xs text-gray-500">{{ $row['public']['html_captured'] ? __('operator.website.pages_content.html_available') : __('operator.website.pages_content.html_missing') }}</p>
                                    @if ($row['public']['semantic_change_state'])
                                        <p @class(['mt-1 text-xs', 'font-semibold text-amber-600 dark:text-amber-300' => $row['public']['semantic_change_state'] === 'meaningful_change', 'text-gray-400' => $row['public']['semantic_change_state'] !== 'meaningful_change'])>{{ __('operator.website.pages_content.semantic_states.'.$row['public']['semantic_change_state']) }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-4">
                                    @if ($row['search']['available'])
                                        <p class="font-medium text-gray-800 dark:text-gray-200">{{ $row['search']['clicks'] !== null ? number_format((float) $row['search']['clicks'], 0, ',', '.') : '—' }} {{ __('operator.website.pages_content.units.clicks') }}</p>
                                        <p class="mt-1 text-xs text-gray-500">{{ $row['search']['impressions'] !== null ? number_format((float) $row['search']['impressions'], 0, ',', '.') : '—' }} {{ __('operator.website.pages_content.units.impressions') }}</p>
                                    @else<p class="max-w-32 whitespace-normal text-xs text-gray-400">{{ ($coverageBySource['gsc']['state'] ?? null) === 'collected' ? __('operator.website.pages_content.row_missing.no_match') : ($coverageBySource['gsc']['state_label'] ?? '—') }}</p>@endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-4">
                                    @if ($row['behavior']['available'])
                                        <p class="font-medium text-gray-800 dark:text-gray-200">{{ $row['behavior']['sessions'] !== null ? number_format((float) $row['behavior']['sessions'], 0, ',', '.') : '—' }} {{ __('operator.website.pages_content.units.sessions') }}</p>
                                        <p class="mt-1 text-xs text-gray-500">{{ $row['behavior']['key_events'] !== null ? number_format((float) $row['behavior']['key_events'], 0, ',', '.') : '—' }} {{ __('operator.website.pages_content.units.key_events') }}</p>
                                    @else<p class="max-w-32 whitespace-normal text-xs text-gray-400">{{ ($coverageBySource['ga4']['state'] ?? null) === 'collected' ? __('operator.website.pages_content.row_missing.no_match') : ($coverageBySource['ga4']['state_label'] ?? '—') }}</p>@endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-xs text-gray-500">{{ $row['last_observed_human'] ?: '—' }}</td>
                                <td class="px-5 py-4 text-right"><button type="button" wire:click="selectPageProfile({{ $row['id'] }})" class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ __('operator.website.pages_content.table.details') }}</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-5 py-12 text-center text-sm text-gray-500">{{ __('operator.website.pages_content.no_results') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-gray-100 lg:hidden dark:divide-gray-800">
                @forelse ($pagesContent['rows'] as $row)
                    <article class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0"><p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $row['title'] ?: __('operator.website.pages_content.untitled') }}</p><p class="mt-1 truncate text-xs text-gray-500">{{ $row['url'] }}</p></div>
                            <button type="button" wire:click="selectPageProfile({{ $row['id'] }})" class="shrink-0 text-xs font-semibold text-brand-600 dark:text-brand-400">{{ __('operator.website.pages_content.table.details') }}</button>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2 text-xs"><span class="rounded bg-gray-100 px-2 py-1 text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ __('operator.website.pages_content.page_kinds.'.$row['family']['kind']) }}</span>@if($row['public']['http_status'])<span class="rounded bg-gray-100 px-2 py-1 text-gray-600 dark:bg-gray-800 dark:text-gray-300">HTTP {{ $row['public']['http_status'] }}</span>@endif @if($row['family']['member_count'] > 1)<span class="rounded bg-brand-50 px-2 py-1 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">{{ __('operator.website.pages_content.family.members', ['count' => $row['family']['member_count']]) }}</span>@endif</div>
                    </article>
                @empty<div class="px-5 py-12 text-center text-sm text-gray-500">{{ __('operator.website.pages_content.no_results') }}</div>@endforelse
            </div>

            <div class="flex flex-col gap-3 border-t border-gray-100 px-5 py-4 text-sm sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
                <p class="text-xs text-gray-500">{{ __('operator.website.pages_content.pagination', ['from' => $pagination['from'], 'to' => $pagination['to'], 'total' => number_format($pagination['total'], 0, ',', '.')]) }}</p>
                <div class="flex gap-2"><button type="button" wire:click="setContentPage({{ max(1, $pagination['page'] - 1) }})" @disabled($pagination['page'] <= 1) class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 disabled:opacity-40 dark:ring-gray-700">{{ __('operator.website.pages_content.previous') }}</button><button type="button" wire:click="setContentPage({{ min($pagination['last_page'], $pagination['page'] + 1) }})" @disabled($pagination['page'] >= $pagination['last_page']) class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 disabled:opacity-40 dark:ring-gray-700">{{ __('operator.website.pages_content.next') }}</button></div>
            </div>
        </section>

        @if ($selectedPage)
            <div class="fixed inset-0 z-[70]" role="dialog" aria-modal="true" aria-label="{{ __('operator.website.pages_content.detail.eyebrow') }}" wire:key="page-profile-drawer-{{ $selectedPage['id'] }}">
                <button type="button" wire:click="closePageProfile" class="absolute inset-0 bg-gray-950/35 backdrop-blur-[1px]" aria-label="{{ __('operator.website.pages_content.detail.close') }}"></button>
                <aside class="absolute inset-y-0 right-0 w-full max-w-3xl overflow-y-auto bg-white shadow-2xl dark:bg-gray-900" x-data="{ tab: 'overview' }">
                    <div class="sticky top-0 z-10 border-b border-gray-100 bg-white/95 px-5 py-4 backdrop-blur dark:border-gray-800 dark:bg-gray-900/95">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0"><p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.website.pages_content.detail.eyebrow') }}</p><h3 class="mt-1 truncate text-lg font-semibold text-gray-900 dark:text-white">{{ $selectedPage['title'] ?: __('operator.website.pages_content.untitled') }}</h3><a href="{{ $selectedPage['url'] }}" target="_blank" rel="noopener" class="mt-1 block truncate text-sm text-brand-600 hover:underline dark:text-brand-400">{{ $selectedPage['url'] }} ↗</a></div>
                            <button type="button" wire:click="closePageProfile" class="rounded-lg px-3 py-2 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">{{ __('operator.website.pages_content.detail.close') }}</button>
                        </div>
                        <nav class="mt-4 flex gap-5 overflow-x-auto text-sm">
                            @foreach (['overview', 'published_cms', 'search_behavior', 'versions'] as $detailTab)
                                <button type="button" @click="tab = '{{ $detailTab }}'" :class="tab === '{{ $detailTab }}' ? 'border-brand-500 text-brand-600 dark:text-brand-400' : 'border-transparent text-gray-500'" class="shrink-0 border-b-2 pb-2 font-medium">{{ __('operator.website.pages_content.detail.tabs.'.$detailTab) }}</button>
                            @endforeach
                        </nav>
                    </div>

                    <div class="p-5">
                        <div x-show="tab === 'overview'" class="space-y-5">
                            <div class="grid gap-3 sm:grid-cols-3">
                                <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ __('operator.website.pages_content.detail.page_type') }}</p><p class="mt-1 font-semibold text-gray-900 dark:text-white">{{ __('operator.website.pages_content.page_kinds.'.$selectedPage['family']['kind']) }}</p></div>
                                <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ __('operator.website.pages_content.detail.family_size') }}</p><p class="mt-1 font-semibold text-gray-900 dark:text-white">{{ number_format($selectedPage['family']['member_count'], 0, ',', '.') }}</p></div>
                                <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ __('operator.website.pages_content.table.updated') }}</p><p class="mt-1 font-semibold text-gray-900 dark:text-white">{{ $selectedPage['last_observed_human'] ?: '—' }}</p></div>
                            </div>
                            <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><h4 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.pages_content.detail.source_identity') }}</h4><div class="mt-3 flex flex-wrap gap-2">@foreach($selectedPage['sources'] as $source)<span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ __('operator.website.pages_content.sources.'.$source) }}</span>@endforeach</div><p class="mt-3 break-all text-xs text-gray-400">{{ $selectedPage['family']['base_url'] }}</p></section>
                            <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><h4 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.pages_content.detail.current_html') }}</h4><div class="mt-3 flex flex-wrap items-center gap-2">@if($selectedPage['public']['http_status'])<span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">HTTP {{ $selectedPage['public']['http_status'] }}</span>@endif @if($selectedPage['public']['semantic_change_state'])<span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">{{ __('operator.website.pages_content.semantic_states.'.$selectedPage['public']['semantic_change_state']) }}</span>@endif @if($selectedPage['public']['raw_ingestion_object_id'])<a href="{{ route('operator.website.html.show', ['assetId' => $asset->id, 'rawObjectId' => $selectedPage['public']['raw_ingestion_object_id']]) }}" target="_blank" rel="noopener" class="ml-auto text-xs font-semibold text-brand-600 dark:text-brand-400">{{ __('operator.website.pages_content.detail.view_html') }} ↗</a>@endif</div></section>
                        </div>

                        <div x-cloak x-show="tab === 'published_cms'" class="grid gap-5 lg:grid-cols-2">
                            <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><h4 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.pages_content.detail.published_title') }}</h4><p class="mt-1 text-xs text-gray-500">{{ __('operator.website.pages_content.detail.published_hint') }}</p><dl class="mt-4 space-y-3 text-sm"><div><dt class="text-xs text-gray-400">Title</dt><dd class="mt-1 text-gray-800 dark:text-gray-200">{{ $selectedPage['public']['title'] ?: '—' }}</dd></div><div><dt class="text-xs text-gray-400">Meta description</dt><dd class="mt-1 text-gray-800 dark:text-gray-200">{{ $selectedPage['public']['meta_description'] ?: '—' }}</dd></div><div><dt class="text-xs text-gray-400">H1</dt><dd class="mt-1 text-gray-800 dark:text-gray-200">{{ is_array($selectedPage['public']['h1']) ? implode(' · ', $selectedPage['public']['h1']) : ($selectedPage['public']['h1'] ?: '—') }}</dd></div><div><dt class="text-xs text-gray-400">Canonical</dt><dd class="mt-1 break-all text-gray-800 dark:text-gray-200">{{ implode(' · ', $selectedPage['public']['canonical_hrefs']) ?: '—' }}</dd></div><div class="grid grid-cols-2 gap-3"><div><dt class="text-xs text-gray-400">{{ __('operator.website.pages_content.detail.word_count') }}</dt><dd class="mt-1 text-gray-800 dark:text-gray-200">{{ $selectedPage['public']['word_count'] !== null ? number_format($selectedPage['public']['word_count'], 0, ',', '.') : '—' }}</dd></div><div><dt class="text-xs text-gray-400">{{ __('operator.website.pages_content.detail.language') }}</dt><dd class="mt-1 text-gray-800 dark:text-gray-200">{{ $selectedPage['public']['language'] ?: '—' }}</dd></div></div></dl></section>
                            <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><h4 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.pages_content.detail.wordpress_title') }}</h4><p class="mt-1 text-xs text-gray-500">{{ __('operator.website.pages_content.detail.wordpress_hint') }}</p>@if($selectedPage['wordpress']['available'])<dl class="mt-4 space-y-3 text-sm"><div class="grid grid-cols-2 gap-3"><div><dt class="text-xs text-gray-400">{{ __('operator.website.pages_content.detail.content_type') }}</dt><dd class="mt-1 text-gray-800 dark:text-gray-200">{{ $selectedPage['wordpress']['type'] ?: '—' }}</dd></div><div><dt class="text-xs text-gray-400">{{ __('operator.website.pages_content.detail.status') }}</dt><dd class="mt-1 text-gray-800 dark:text-gray-200">{{ $selectedPage['wordpress']['status'] ?: '—' }}</dd></div></div><div><dt class="text-xs text-gray-400">{{ __('operator.website.pages_content.detail.cms_title') }}</dt><dd class="mt-1 text-gray-800 dark:text-gray-200">{{ $selectedPage['wordpress']['title'] ?: '—' }}</dd></div><div><dt class="text-xs text-gray-400">Slug</dt><dd class="mt-1 break-all text-gray-800 dark:text-gray-200">{{ $selectedPage['wordpress']['slug'] ?: '—' }}</dd></div><div><dt class="text-xs text-gray-400">{{ __('operator.website.pages_content.detail.modified_at') }}</dt><dd class="mt-1 text-gray-800 dark:text-gray-200">{{ $selectedPage['wordpress']['modified_at'] ?: '—' }}</dd></div><div><dt class="text-xs text-gray-400">Builder</dt><dd class="mt-1 text-gray-800 dark:text-gray-200">{{ $selectedPage['wordpress']['builder_provider'] ?: '—' }}</dd></div></dl>@else<p class="mt-4 text-sm text-gray-500">{{ __('operator.website.pages_content.detail.no_wordpress') }}</p>@endif</section>
                        </div>

                        <div x-cloak x-show="tab === 'search_behavior'" class="grid gap-5 lg:grid-cols-2">
                            <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><h4 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.pages_content.detail.search_title') }}</h4><p class="mt-1 text-xs text-gray-500">{{ __('operator.website.pages_content.detail.search_hint') }}</p><div class="mt-4 grid grid-cols-3 gap-3">@foreach ([['clicks', __('operator.website.pages_content.units.clicks')], ['impressions', __('operator.website.pages_content.units.impressions')], ['average_position', __('operator.website.pages_content.units.position')]] as [$key, $label])<div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ $label }}</p><p class="mt-1 font-semibold text-gray-900 dark:text-white">{{ $selectedPage['search'][$key] !== null ? number_format((float) $selectedPage['search'][$key], $key === 'average_position' ? 1 : 0, ',', '.') : '—' }}</p></div>@endforeach</div></section>
                            <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><h4 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.pages_content.detail.behavior_title') }}</h4><p class="mt-1 text-xs text-gray-500">{{ __('operator.website.pages_content.detail.behavior_hint') }}</p><div class="mt-4 grid grid-cols-3 gap-3">@foreach ([['sessions', __('operator.website.pages_content.units.sessions')], ['engaged_sessions', __('operator.website.pages_content.units.engaged_sessions')], ['key_events', __('operator.website.pages_content.units.key_events')]] as [$key, $label])<div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ $label }}</p><p class="mt-1 font-semibold text-gray-900 dark:text-white">{{ $selectedPage['behavior'][$key] !== null ? number_format((float) $selectedPage['behavior'][$key], 0, ',', '.') : '—' }}</p></div>@endforeach</div><p class="mt-3 text-[11px] text-gray-400">{{ __('operator.website.pages_content.detail.platform_signal_note') }}</p></section>
                        </div>

                        <div x-cloak x-show="tab === 'versions'" class="space-y-3">
                            <div><h4 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.pages_content.detail.versions_title') }}</h4><p class="mt-1 text-xs text-gray-500">{{ __('operator.website.pages_content.detail.versions_hint') }}</p></div>
                            @forelse($selectedPage['versions'] as $version)
                                <article class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-sm font-medium text-gray-900 dark:text-white">{{ $version['observed_human'] ?: $version['observed_at'] }}</p><p class="mt-1 text-xs text-gray-400">{{ number_format($version['html_bytes'], 0, ',', '.') }} byte</p></div><div class="flex flex-wrap gap-2">@if($version['semantic_change_state'])<span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ __('operator.website.pages_content.semantic_states.'.$version['semantic_change_state']) }}</span>@endif @if($version['raw_ingestion_object_id'])<a href="{{ route('operator.website.html.show', ['assetId' => $asset->id, 'rawObjectId' => $version['raw_ingestion_object_id']]) }}" target="_blank" rel="noopener" class="text-xs font-semibold text-brand-600 dark:text-brand-400">{{ __('operator.website.pages_content.detail.view_html') }} ↗</a>@endif</div></div>@if($version['semantic_changed_fields'] !== [])<div class="mt-3 flex flex-wrap gap-1">@foreach($version['semantic_changed_fields'] as $field)<span class="rounded bg-amber-50 px-2 py-1 text-[11px] text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">{{ __('operator.website.pages_content.changed_fields.'.$field) }}</span>@endforeach</div>@endif<details class="mt-3 text-xs"><summary class="cursor-pointer text-gray-500">{{ __('operator.website.pages_content.detail.technical_hashes') }}</summary><p class="mt-2 break-all font-mono text-[10px] text-gray-400">SHA-256: {{ $version['html_hash'] }}</p></details></article>
                            @empty<p class="rounded-xl bg-gray-50 p-5 text-sm text-gray-500 dark:bg-white/[0.03]">{{ __('operator.website.pages_content.detail.no_versions') }}</p>@endforelse
                        </div>
                    </div>
                </aside>
            </div>
        @endif

        <p class="text-xs text-gray-400">{{ __('operator.website.pages_content.fact_note') }}</p>
    @endif
</div>
