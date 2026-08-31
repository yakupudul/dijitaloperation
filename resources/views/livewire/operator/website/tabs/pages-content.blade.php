@php
    $projection = $pagesContent['projection'];
    $counts = $pagesContent['counts'];
    $pagination = $pagesContent['pagination'];
    $selectedPage = $pagesContent['selected'];
@endphp

<div class="space-y-5" data-website-pages-content>
    <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="flex flex-col gap-4 border-b border-gray-100 px-5 py-4 dark:border-gray-800 lg:flex-row lg:items-center lg:justify-between">
            <div>
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
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.website.pages_content.subtitle') }}</p>
            </div>
            @if ($projection['available'])
                <div class="text-left text-xs text-gray-500 lg:text-right dark:text-gray-400">
                    <p>{{ __('operator.website.pages_content.period', ['start' => data_get($projection, 'period.start'), 'end' => data_get($projection, 'period.end')]) }}</p>
                    @if ($projection['completed_at'])
                        <p class="mt-1">{{ __('operator.website.pages_content.projected_at', ['when' => \Carbon\CarbonImmutable::parse($projection['completed_at'])->diffForHumans()]) }}</p>
                    @endif
                </div>
            @endif
        </div>

        @if (! $projection['available'])
            <div class="px-5 py-10 text-center">
                <div class="mx-auto max-w-xl">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('operator.website.pages_content.empty.title') }}</h3>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.website.pages_content.empty.body') }}</p>
                    <div class="mt-5 flex flex-wrap justify-center gap-2">
                        <button type="button" wire:click="refreshData" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600">{{ __('operator.website.actions.refresh_data') }}</button>
                        <a href="{{ route('operator.asset.sources', ['assetId' => $asset->id]) }}" wire:navigate class="rounded-lg bg-white px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700">{{ __('operator.website.actions.data_sources') }}</a>
                    </div>
                </div>
            </div>
        @else
            <div class="grid gap-px bg-gray-100 sm:grid-cols-2 xl:grid-cols-4 dark:bg-gray-800">
                @foreach ($pagesContent['coverage'] as $source)
                    <div class="bg-white px-5 py-4 dark:bg-gray-900">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $source['label'] }}</p>
                            <span @class([
                                'inline-flex rounded-full border px-2 py-0.5 text-[11px] font-medium',
                                'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300' => $source['state'] === 'collected',
                                'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300' => in_array($source['state'], ['not_collected', 'projection_failed'], true),
                                'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300' => in_array($source['state'], ['not_configured', 'unavailable'], true),
                            ])>{{ $source['state_label'] }}</span>
                        </div>
                        <p class="mt-2 text-xs text-gray-400">
                            {{ $source['watermark'] ? \Carbon\CarbonImmutable::parse($source['watermark'])->diffForHumans() : __('operator.website.pages_content.no_collection_time') }}
                        </p>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    @if ($projection['available'])
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['key' => 'pages', 'label' => __('operator.website.pages_content.cards.pages'), 'hint' => __('operator.website.pages_content.cards.pages_hint')],
                ['key' => 'html_captured', 'label' => __('operator.website.pages_content.cards.html'), 'hint' => __('operator.website.pages_content.cards.html_hint')],
                ['key' => 'wordpress_objects', 'label' => __('operator.website.pages_content.cards.wordpress'), 'hint' => __('operator.website.pages_content.cards.wordpress_hint')],
                ['key' => 'changed', 'label' => __('operator.website.pages_content.cards.changed'), 'hint' => __('operator.website.pages_content.cards.changed_hint')],
            ] as $card)
                <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
                    <p class="mt-3 text-2xl font-semibold text-gray-900 dark:text-white">{{ $counts[$card['key']] === null ? '—' : number_format($counts[$card['key']], 0, ',', '.') }}</p>
                    <p class="mt-1 text-xs text-gray-400">{{ $card['hint'] }}</p>
                </section>
            @endforeach
        </div>

        <section class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="grid gap-px bg-gray-100 sm:grid-cols-3 dark:bg-gray-800">
                @foreach ([
                    ['key' => 'matched', 'label' => __('operator.website.pages_content.coverage.matched'), 'tone' => 'text-emerald-700 dark:text-emerald-300'],
                    ['key' => 'cms_without_html', 'label' => __('operator.website.pages_content.coverage.cms_without_html'), 'tone' => 'text-amber-700 dark:text-amber-300'],
                    ['key' => 'public_without_cms', 'label' => __('operator.website.pages_content.coverage.public_without_cms'), 'tone' => 'text-gray-700 dark:text-gray-300'],
                ] as $item)
                    <div class="flex items-center justify-between gap-3 bg-white px-5 py-3.5 dark:bg-gray-900">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $item['label'] }}</p>
                        <p class="text-sm font-semibold {{ $item['tone'] }}">{{ number_format((int) $counts[$item['key']], 0, ',', '.') }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="border-b border-gray-100 p-4 dark:border-gray-800">
                <div class="grid gap-3 lg:grid-cols-[minmax(240px,1fr)_220px_220px]">
                    <label class="block">
                        <span class="sr-only">{{ __('operator.website.pages_content.search') }}</span>
                        <input wire:model.live.debounce.400ms="contentSearch" type="search" placeholder="{{ __('operator.website.pages_content.search') }}" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                    </label>
                    <label class="block">
                        <span class="sr-only">{{ __('operator.website.pages_content.filter_label') }}</span>
                        <select wire:model.live="contentFilter" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                            <option value="all">{{ __('operator.website.pages_content.filters.all') }}</option>
                            <option value="matched">{{ __('operator.website.pages_content.filters.matched') }}</option>
                            <option value="changed">{{ __('operator.website.pages_content.filters.changed') }}</option>
                            <option value="published">{{ __('operator.website.pages_content.filters.published') }}</option>
                            <option value="draft">{{ __('operator.website.pages_content.filters.draft') }}</option>
                            <option value="cms_without_html">{{ __('operator.website.pages_content.filters.cms_without_html') }}</option>
                            <option value="public_without_cms">{{ __('operator.website.pages_content.filters.public_without_cms') }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="sr-only">{{ __('operator.website.pages_content.source_label') }}</span>
                        <select wire:model.live="contentSource" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                            <option value="all">{{ __('operator.website.pages_content.sources.all') }}</option>
                            <option value="website">{{ __('operator.website.pages_content.sources.website') }}</option>
                            <option value="wordpress">{{ __('operator.website.pages_content.sources.wordpress') }}</option>
                            <option value="gsc">{{ __('operator.website.pages_content.sources.gsc') }}</option>
                            <option value="ga4">{{ __('operator.website.pages_content.sources.ga4') }}</option>
                        </select>
                    </label>
                </div>
            </div>

            <div wire:loading.delay class="border-b border-gray-100 px-5 py-3 text-xs text-brand-600 dark:border-gray-800 dark:text-brand-400" wire:target="contentSearch,contentFilter,contentSource,setContentPage,selectPageProfile">
                {{ __('operator.website.pages_content.loading') }}
            </div>

            <div class="hidden overflow-x-auto lg:block">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-gray-50/80 text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-white/[0.02] dark:text-gray-400">
                        <tr>
                            <th class="px-5 py-3">{{ __('operator.website.pages_content.table.page') }}</th>
                            <th class="px-4 py-3">{{ __('operator.website.pages_content.table.published') }}</th>
                            <th class="px-4 py-3">{{ __('operator.website.pages_content.table.wordpress') }}</th>
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
                                    <div class="mt-2 flex flex-wrap gap-1">
                                        @foreach ($row['sources'] as $source)
                                            <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-gray-500 dark:bg-gray-800 dark:text-gray-300">{{ __('operator.website.pages_content.source_short.'.$source) }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4">
                                    @if ($row['public']['http_status'] !== null)
                                        <span @class([
                                            'inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold',
                                            'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300' => $row['public']['http_status'] >= 200 && $row['public']['http_status'] < 300,
                                            'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300' => $row['public']['http_status'] < 200 || $row['public']['http_status'] >= 400,
                                            'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300' => $row['public']['http_status'] >= 300 && $row['public']['http_status'] < 400,
                                        ])>HTTP {{ $row['public']['http_status'] }}</span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                        {{ $row['public']['html_captured'] ? __('operator.website.pages_content.html_available') : __('operator.website.pages_content.html_missing') }}
                                    </p>
                                    @if ($row['public']['change_state'])
                                        <p class="mt-1 text-xs {{ $row['public']['change_state'] === 'changed' ? 'font-semibold text-amber-600 dark:text-amber-300' : 'text-gray-400' }}">{{ __('operator.website.pages_content.change_states.'.$row['public']['change_state']) }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-4">
                                    @if ($row['wordpress']['available'])
                                        <p class="font-medium text-gray-800 dark:text-gray-200">{{ $row['wordpress']['type'] ?: '—' }}</p>
                                        <p class="mt-1 text-xs text-gray-500">{{ $row['wordpress']['status'] ?: '—' }}@if($row['wordpress']['language']) · {{ $row['wordpress']['language'] }}@endif</p>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-4">
                                    @if ($row['search']['available'])
                                        <p class="font-medium text-gray-800 dark:text-gray-200">{{ $row['search']['clicks'] !== null ? number_format((float) $row['search']['clicks'], 0, ',', '.') : '—' }} {{ __('operator.website.pages_content.units.clicks') }}</p>
                                        <p class="mt-1 text-xs text-gray-500">{{ $row['search']['impressions'] !== null ? number_format((float) $row['search']['impressions'], 0, ',', '.') : '—' }} {{ __('operator.website.pages_content.units.impressions') }}</p>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-4">
                                    @if ($row['behavior']['available'])
                                        <p class="font-medium text-gray-800 dark:text-gray-200">{{ $row['behavior']['sessions'] !== null ? number_format((float) $row['behavior']['sessions'], 0, ',', '.') : '—' }} {{ __('operator.website.pages_content.units.sessions') }}</p>
                                        <p class="mt-1 text-xs text-gray-500">{{ $row['behavior']['key_events'] !== null ? number_format((float) $row['behavior']['key_events'], 0, ',', '.') : '—' }} {{ __('operator.website.pages_content.units.key_events') }}</p>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
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
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $row['title'] ?: __('operator.website.pages_content.untitled') }}</p>
                                <p class="mt-1 truncate text-xs text-gray-500">{{ $row['url'] }}</p>
                            </div>
                            <button type="button" wire:click="selectPageProfile({{ $row['id'] }})" class="shrink-0 text-xs font-semibold text-brand-600 dark:text-brand-400">{{ __('operator.website.pages_content.table.details') }}</button>
                        </div>
                        <dl class="mt-3 grid grid-cols-2 gap-3 text-xs">
                            <div><dt class="text-gray-400">{{ __('operator.website.pages_content.table.published') }}</dt><dd class="mt-1 text-gray-700 dark:text-gray-300">{{ $row['public']['http_status'] !== null ? 'HTTP '.$row['public']['http_status'] : '—' }}</dd></div>
                            <div><dt class="text-gray-400">{{ __('operator.website.pages_content.table.wordpress') }}</dt><dd class="mt-1 text-gray-700 dark:text-gray-300">{{ $row['wordpress']['type'] ?: '—' }}@if($row['wordpress']['status']) · {{ $row['wordpress']['status'] }}@endif</dd></div>
                            <div><dt class="text-gray-400">{{ __('operator.website.pages_content.table.search') }}</dt><dd class="mt-1 text-gray-700 dark:text-gray-300">{{ $row['search']['clicks'] !== null ? number_format((float) $row['search']['clicks'], 0, ',', '.') : '—' }} {{ __('operator.website.pages_content.units.clicks') }}</dd></div>
                            <div><dt class="text-gray-400">{{ __('operator.website.pages_content.table.behavior') }}</dt><dd class="mt-1 text-gray-700 dark:text-gray-300">{{ $row['behavior']['sessions'] !== null ? number_format((float) $row['behavior']['sessions'], 0, ',', '.') : '—' }} {{ __('operator.website.pages_content.units.sessions') }}</dd></div>
                        </dl>
                    </article>
                @empty
                    <div class="px-5 py-12 text-center text-sm text-gray-500">{{ __('operator.website.pages_content.no_results') }}</div>
                @endforelse
            </div>

            <div class="flex flex-col gap-3 border-t border-gray-100 px-5 py-4 text-sm sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
                <p class="text-xs text-gray-500">{{ __('operator.website.pages_content.pagination', ['from' => $pagination['from'], 'to' => $pagination['to'], 'total' => number_format($pagination['total'], 0, ',', '.')]) }}</p>
                <div class="flex gap-2">
                    <button type="button" wire:click="setContentPage({{ max(1, $pagination['page'] - 1) }})" @disabled($pagination['page'] <= 1) class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 disabled:cursor-not-allowed disabled:opacity-40 dark:ring-gray-700">{{ __('operator.website.pages_content.previous') }}</button>
                    <button type="button" wire:click="setContentPage({{ min($pagination['last_page'], $pagination['page'] + 1) }})" @disabled($pagination['page'] >= $pagination['last_page']) class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 disabled:cursor-not-allowed disabled:opacity-40 dark:ring-gray-700">{{ __('operator.website.pages_content.next') }}</button>
                </div>
            </div>
        </section>

        @if ($selectedPage)
            <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" id="page-profile-detail">
                <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.website.pages_content.detail.eyebrow') }}</p>
                        <h3 class="mt-1 truncate text-lg font-semibold text-gray-900 dark:text-white">{{ $selectedPage['title'] ?: __('operator.website.pages_content.untitled') }}</h3>
                        <a href="{{ $selectedPage['url'] }}" target="_blank" rel="noopener" class="mt-1 block truncate text-sm text-brand-600 hover:underline dark:text-brand-400">{{ $selectedPage['url'] }} ↗</a>
                    </div>
                    <button type="button" wire:click="closePageProfile" class="shrink-0 rounded-lg px-3 py-2 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">{{ __('operator.website.pages_content.detail.close') }}</button>
                </div>

                <div class="grid gap-px bg-gray-100 xl:grid-cols-2 dark:bg-gray-800">
                    <div class="bg-white p-5 dark:bg-gray-900">
                        <div class="flex items-center justify-between gap-3">
                            <div><h4 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.pages_content.detail.published_title') }}</h4><p class="mt-1 text-xs text-gray-500">{{ __('operator.website.pages_content.detail.published_hint') }}</p></div>
                            @if ($selectedPage['public']['http_status'] !== null)<span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-300">HTTP {{ $selectedPage['public']['http_status'] }}</span>@endif
                        </div>
                        <dl class="mt-5 space-y-3 text-sm">
                            <div><dt class="text-xs text-gray-400">Title</dt><dd class="mt-1 break-words text-gray-800 dark:text-gray-200">{{ $selectedPage['public']['title'] ?: '—' }}</dd></div>
                            <div><dt class="text-xs text-gray-400">Meta description</dt><dd class="mt-1 break-words text-gray-800 dark:text-gray-200">{{ $selectedPage['public']['meta_description'] ?: '—' }}</dd></div>
                            <div><dt class="text-xs text-gray-400">H1</dt><dd class="mt-1 break-words text-gray-800 dark:text-gray-200">{{ is_array($selectedPage['public']['h1']) ? implode(' · ', $selectedPage['public']['h1']) : ($selectedPage['public']['h1'] ?: '—') }}</dd></div>
                            <div class="grid grid-cols-2 gap-3"><div><dt class="text-xs text-gray-400">{{ __('operator.website.pages_content.detail.word_count') }}</dt><dd class="mt-1 font-medium text-gray-800 dark:text-gray-200">{{ $selectedPage['public']['word_count'] !== null ? number_format($selectedPage['public']['word_count'], 0, ',', '.') : '—' }}</dd></div><div><dt class="text-xs text-gray-400">{{ __('operator.website.pages_content.detail.language') }}</dt><dd class="mt-1 font-medium text-gray-800 dark:text-gray-200">{{ $selectedPage['public']['language'] ?: '—' }}</dd></div></div>
                            <div><dt class="text-xs text-gray-400">Canonical</dt><dd class="mt-1 break-all text-gray-800 dark:text-gray-200">{{ implode(' · ', $selectedPage['public']['canonical_hrefs']) ?: '—' }}</dd></div>
                        </dl>
                        <div class="mt-5 rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]">
                            <div class="flex flex-wrap items-center justify-between gap-3"><div><p class="text-sm font-medium text-gray-900 dark:text-white">{{ __('operator.website.pages_content.detail.html_version') }}</p><p class="mt-1 text-xs text-gray-500">{{ $selectedPage['public']['html_bytes'] !== null ? number_format($selectedPage['public']['html_bytes'], 0, ',', '.').' byte' : __('operator.website.pages_content.html_missing') }}</p></div>
                                @if ($selectedPage['public']['raw_ingestion_object_id'])
                                    <a href="{{ route('operator.website.html.show', ['assetId' => $asset->id, 'rawObjectId' => $selectedPage['public']['raw_ingestion_object_id']]) }}" target="_blank" rel="noopener" class="rounded-lg bg-white px-3 py-2 text-xs font-semibold text-brand-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-brand-400 dark:ring-gray-700">{{ __('operator.website.pages_content.detail.view_html') }}</a>
                                @endif
                            </div>
                            @if ($selectedPage['public']['html_hash'])<p class="mt-3 break-all font-mono text-[11px] text-gray-400">SHA-256: {{ $selectedPage['public']['html_hash'] }}</p>@endif
                        </div>
                    </div>

                    <div class="bg-white p-5 dark:bg-gray-900">
                        <div><h4 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.pages_content.detail.wordpress_title') }}</h4><p class="mt-1 text-xs text-gray-500">{{ __('operator.website.pages_content.detail.wordpress_hint') }}</p></div>
                        @if ($selectedPage['wordpress']['available'])
                            <dl class="mt-5 space-y-3 text-sm">
                                <div class="grid grid-cols-2 gap-3"><div><dt class="text-xs text-gray-400">{{ __('operator.website.pages_content.detail.content_type') }}</dt><dd class="mt-1 font-medium text-gray-800 dark:text-gray-200">{{ $selectedPage['wordpress']['type'] ?: '—' }}</dd></div><div><dt class="text-xs text-gray-400">{{ __('operator.website.pages_content.detail.status') }}</dt><dd class="mt-1 font-medium text-gray-800 dark:text-gray-200">{{ $selectedPage['wordpress']['status'] ?: '—' }}</dd></div></div>
                                <div><dt class="text-xs text-gray-400">{{ __('operator.website.pages_content.detail.cms_title') }}</dt><dd class="mt-1 break-words text-gray-800 dark:text-gray-200">{{ $selectedPage['wordpress']['title'] ?: '—' }}</dd></div>
                                <div class="grid grid-cols-2 gap-3"><div><dt class="text-xs text-gray-400">Slug</dt><dd class="mt-1 break-all text-gray-800 dark:text-gray-200">{{ $selectedPage['wordpress']['slug'] ?: '—' }}</dd></div><div><dt class="text-xs text-gray-400">{{ __('operator.website.pages_content.detail.language') }}</dt><dd class="mt-1 text-gray-800 dark:text-gray-200">{{ $selectedPage['wordpress']['language'] ?: '—' }}</dd></div></div>
                                <div><dt class="text-xs text-gray-400">{{ __('operator.website.pages_content.detail.modified_at') }}</dt><dd class="mt-1 text-gray-800 dark:text-gray-200">{{ $selectedPage['wordpress']['modified_at'] ?: '—' }}</dd></div>
                                <div class="grid grid-cols-2 gap-3"><div><dt class="text-xs text-gray-400">{{ __('operator.website.pages_content.detail.content_length') }}</dt><dd class="mt-1 text-gray-800 dark:text-gray-200">{{ $selectedPage['wordpress']['content_length'] !== null ? number_format($selectedPage['wordpress']['content_length'], 0, ',', '.') : '—' }}</dd></div><div><dt class="text-xs text-gray-400">Builder</dt><dd class="mt-1 text-gray-800 dark:text-gray-200">{{ $selectedPage['wordpress']['builder_provider'] ?: '—' }}</dd></div></div>
                                <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]"><dt class="text-xs font-medium text-gray-500">{{ __('operator.website.pages_content.detail.cms_seo') }}</dt><dd class="mt-3 space-y-2 text-xs text-gray-700 dark:text-gray-300"><p><span class="text-gray-400">Title:</span> {{ data_get($selectedPage, 'wordpress.seo.title') ?: '—' }}</p><p><span class="text-gray-400">Meta:</span> {{ data_get($selectedPage, 'wordpress.seo.meta_description') ?: '—' }}</p><p class="break-all"><span class="text-gray-400">Canonical:</span> {{ data_get($selectedPage, 'wordpress.seo.canonical_url') ?: '—' }}</p></dd></div>
                            </dl>
                        @else
                            <p class="mt-5 text-sm text-gray-500">{{ __('operator.website.pages_content.detail.no_wordpress') }}</p>
                        @endif
                    </div>
                </div>

                <div class="grid gap-px border-t border-gray-100 bg-gray-100 xl:grid-cols-2 dark:border-gray-800 dark:bg-gray-800">
                    <div class="bg-white p-5 dark:bg-gray-900">
                        <h4 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.pages_content.detail.search_title') }}</h4>
                        <p class="mt-1 text-xs text-gray-500">{{ __('operator.website.pages_content.detail.search_hint') }}</p>
                        <div class="mt-4 grid grid-cols-3 gap-3">
                            @foreach ([['clicks', __('operator.website.pages_content.units.clicks')], ['impressions', __('operator.website.pages_content.units.impressions')], ['average_position', __('operator.website.pages_content.units.position')]] as [$key, $label])
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ $label }}</p><p class="mt-1 font-semibold text-gray-900 dark:text-white">{{ $selectedPage['search'][$key] !== null ? number_format((float) $selectedPage['search'][$key], $key === 'average_position' ? 1 : 0, ',', '.') : '—' }}</p></div>
                            @endforeach
                        </div>
                    </div>
                    <div class="bg-white p-5 dark:bg-gray-900">
                        <h4 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.pages_content.detail.behavior_title') }}</h4>
                        <p class="mt-1 text-xs text-gray-500">{{ __('operator.website.pages_content.detail.behavior_hint') }}</p>
                        <div class="mt-4 grid grid-cols-3 gap-3">
                            @foreach ([['sessions', __('operator.website.pages_content.units.sessions')], ['engaged_sessions', __('operator.website.pages_content.units.engaged_sessions')], ['key_events', __('operator.website.pages_content.units.key_events')]] as [$key, $label])
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ $label }}</p><p class="mt-1 font-semibold text-gray-900 dark:text-white">{{ $selectedPage['behavior'][$key] !== null ? number_format((float) $selectedPage['behavior'][$key], 0, ',', '.') : '—' }}</p></div>
                            @endforeach
                        </div>
                        <p class="mt-3 text-[11px] text-gray-400">{{ __('operator.website.pages_content.detail.platform_signal_note') }}</p>
                    </div>
                </div>
            </section>
        @endif

        <p class="text-xs text-gray-400">{{ __('operator.website.pages_content.fact_note') }}</p>
    @endif
</div>
