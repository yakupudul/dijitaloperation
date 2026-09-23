@php
    $g = 'operator_gbp.';
    $tabs = __($g.'page_tabs');
    $connection = $data['connection'] ?? [];
    $profile = $data['profile'] ?? [];
    $perf = $data['performance_live'] ?? ['available' => false];
    $keywords = $data['keywords_live'] ?? ['available' => false, 'items' => []];
    $reviews = $data['reviews_live'] ?? ['available' => false];
    $content = $data['content_live'] ?? ['available' => false];
    $completeness = $profile['completeness'] ?? null;
    $bound = (bool) ($connection['bound'] ?? false);
    $real = ($data['migration_mode'] ?? '') === 'real';
    $gaps = array_values(array_filter($data['unsupported_live_capabilities'] ?? [], fn ($key) => $key !== 'local_visibility'));
    $num = fn ($value) => $value === null ? '—' : number_format((float) $value, 0, ',', '.');
    $card = 'rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
    $delta = function (?int $pct): array {
        if ($pct === null) {
            return ['', 'text-gray-400'];
        }

        return [($pct > 0 ? '+' : '').$pct.'%', $pct >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'];
    };
    $chartOptions = ($perf['available'] ?? false) ? [
        'chart' => ['type' => 'area', 'height' => 260, 'toolbar' => ['show' => false]],
        'series' => [
            ['name' => __($g.'chart_views'), 'data' => $perf['series']['views'] ?? []],
            ['name' => __($g.'chart_actions'), 'data' => $perf['series']['actions'] ?? []],
        ],
        'xaxis' => ['categories' => $perf['series']['dates'] ?? [], 'tickAmount' => 8],
        'yaxis' => [['title' => ['text' => __($g.'chart_views')]], ['opposite' => true, 'title' => ['text' => __($g.'chart_actions')]]],
        'stroke' => ['curve' => 'smooth', 'width' => 2],
        'dataLabels' => ['enabled' => false],
        'colors' => ['#0284c7', '#ea580c'],
        'legend' => ['position' => 'top'],
    ] : null;
@endphp

<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 dark:border-gray-800 lg:flex-row lg:items-start lg:justify-between">
        <div class="flex min-w-0 items-start gap-3">
            <x-demo.digital-asset-mark type="gbp" size="lg" class="mt-0.5" />
            <div class="min-w-0">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Google Business Profile</p>
                <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $identity['title'] }}</h1>
                <a href="{{ route('operator.brand', ['brand' => $identity['brand_id']]) }}" wire:navigate class="mt-1 inline-block text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $identity['brand_name'] }}</a>
                <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
                    <span>{{ $identity['location_line'] ?: '—' }}</span>
                    @if (filled($identity['maps_uri'] ?? null))
                        <a href="{{ $identity['maps_uri'] }}" target="_blank" rel="noopener" class="font-medium text-brand-600 hover:underline dark:text-brand-400">{{ __($g.'open_on_maps') }} ↗</a>
                    @endif
                    <span>·</span>
                    <span>{{ __($g.'last_refresh') }}: {{ $identity['last_refresh'] ?: '—' }}</span>
                    <span @class([
                        'font-semibold text-emerald-600 dark:text-emerald-400' => $real,
                        'font-semibold text-amber-600 dark:text-amber-400' => $bound && ! $real,
                        'font-semibold text-gray-500' => ! $bound,
                    ])>{{ $real ? __($g.'connected') : ($bound ? __($g.'needs_collection') : __($g.'not_connected')) }}</span>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="refreshData" wire:loading.attr="disabled" @disabled(! $bound) class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50">{{ __($g.'refresh') }}</button>
            <a href="{{ route('operator.asset.sources', ['assetId' => $assetId]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-semibold text-brand-700 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:text-brand-300 dark:ring-brand-500/30">{{ __('operator_runtime.sources.title') }}</a>
            <a href="{{ route('operator.asset.edit', ['assetId' => $assetId]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">{{ __('operator_runtime.sources.edit_asset') }}</a>
        </div>
    </div>

    <nav class="flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Google Business Profile">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')" @class([
                'whitespace-nowrap border-b-2 px-3 py-3 text-sm font-medium',
                'border-brand-500 text-brand-600 dark:text-brand-400' => $tab === $key,
                'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200' => $tab !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </nav>

    @if (! $bound)
        <section class="rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900/50 dark:bg-amber-950/20">
            <h2 class="font-semibold text-amber-900 dark:text-amber-200">{{ __($g.'connect_title') }}</h2>
            <p class="mt-1 text-sm text-amber-800/80 dark:text-amber-300/80">{{ __($g.'connect_body') }}</p>
            <a href="{{ route('operator.asset.sources', ['assetId' => $assetId]) }}" wire:navigate class="mt-3 inline-flex rounded-lg bg-amber-600 px-3 py-2 text-sm font-semibold text-white">{{ __($g.'connect_action') }}</a>
        </section>
    @elseif (! $real)
        <section class="rounded-xl border border-blue-200 bg-blue-50 p-5 dark:border-blue-900/50 dark:bg-blue-950/20">
            <h2 class="font-semibold text-blue-900 dark:text-blue-200">{{ __($g.'collect_title') }}</h2>
            <p class="mt-1 text-sm text-blue-800/80 dark:text-blue-300/80">{{ __($g.'collect_body') }}</p>
            @if (filled($connection['last_run_label'] ?? null))
                <p class="mt-2 text-xs text-blue-800/70 dark:text-blue-300/70">{{ __($g.'last_run') }}: {{ $connection['last_run_label'] }} · {{ $connection['last_run_human'] ?? '—' }}</p>
            @endif
        </section>
    @elseif ($gaps !== [] && $tab !== 'advisor')
        <p class="rounded-lg bg-gray-50 px-4 py-2 text-xs text-gray-500 dark:bg-white/[0.03] dark:text-gray-400">
            {{ __($g.'data_gaps', ['items' => collect($gaps)->map(fn ($key) => __($g.'gap.'.$key))->implode(', ')]) }}
            @if (filled($connection['last_run_label'] ?? null)) · {{ __($g.'last_run') }}: {{ $connection['last_run_label'] }} @endif
        </p>
    @endif

    @if (in_array($tab, ['overview', 'performance'], true) && ($perf['available'] ?? false))
        <div class="flex flex-wrap items-center gap-2 text-sm">
            @foreach ($dayOptions as $option)
                <button type="button" wire:click="setDays({{ $option }})" @class([
                    'rounded-lg px-3 py-1.5 font-medium',
                    'bg-brand-500 text-white' => $days === $option,
                    'text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700' => $days !== $option,
                ])>{{ __($g.'days', ['days' => $option]) }}</button>
            @endforeach
            <span class="text-xs text-gray-400">{{ __($g.'period_line', ['from' => $perf['from'], 'to' => $perf['to']]) }} · {{ ($perf['has_previous'] ?? false) ? __($g.'vs_previous', ['days' => $days]) : __($g.'no_previous') }}</span>
        </div>
    @endif

    @if ($tab === 'advisor')
        <livewire:operator.advisor.advisor-panel :asset-id="(int) $assetId" :key="'gbp-advisor-'.$assetId" />

    @elseif ($tab === 'overview')
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <section class="{{ $card }}">
                <p class="text-xs text-gray-400">{{ __($g.'kpi.views') }}</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{{ ($perf['available'] ?? false) ? $num($perf['views']) : '—' }}</p>
                @if ($perf['available'] ?? false)
                    @php
                        $viewsNow = ($perf['metrics']['search_views']['current'] ?? 0) + ($perf['metrics']['maps_views']['current'] ?? 0);
                        $viewsPrev = ($perf['metrics']['search_views']['previous'] ?? null) === null ? null : ($perf['metrics']['search_views']['previous'] ?? 0) + ($perf['metrics']['maps_views']['previous'] ?? 0);
                        [$dText, $dClass] = $delta($viewsPrev ? (int) round(($viewsNow / $viewsPrev - 1) * 100) : null);
                    @endphp
                    <p class="mt-1 text-xs {{ $dClass }}">{{ $dText }}</p>
                @endif
            </section>
            <section class="{{ $card }}">
                <p class="text-xs text-gray-400">{{ __($g.'kpi.actions') }}</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{{ ($perf['available'] ?? false) ? $num($perf['actions']) : '—' }}</p>
                @if (($perf['action_rate'] ?? null) !== null)
                    <p class="mt-1 text-xs text-gray-500">{{ __($g.'kpi.action_rate') }}: %{{ number_format((float) $perf['action_rate'], 1, ',', '.') }}</p>
                @endif
            </section>
            <section class="{{ $card }}">
                <p class="text-xs text-gray-400">{{ __($g.'kpi.rating') }}</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{{ ($reviews['average'] ?? null) !== null ? number_format((float) $reviews['average'], 1, ',', '.').' ★' : '—' }}</p>
                @if ($reviews['available'] ?? false)
                    <p class="mt-1 text-xs text-gray-500">{{ __($g.'kpi.reviews_total', ['count' => $num($reviews['total'])]) }} · {{ __($g.'kpi.unanswered') }}: <span @class(['font-semibold', 'text-rose-600' => ($reviews['unanswered_recent'] ?? 0) > 0])>{{ $reviews['unanswered_recent'] ?? 0 }}</span></p>
                @endif
            </section>
            <section class="{{ $card }}">
                <p class="text-xs text-gray-400">{{ __($g.'kpi.completeness') }}</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{{ $completeness !== null ? '%'.$completeness['score'] : '—' }}</p>
                @if ($completeness !== null)
                    <div class="mt-2 h-1.5 rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-1.5 rounded-full bg-brand-500" style="width: {{ $completeness['score'] }}%"></div></div>
                @endif
            </section>
        </div>

        <div class="grid gap-4 xl:grid-cols-3">
            <div class="xl:col-span-2">
                @if ($chartOptions)
                    <x-ta.chart-card :title="__($g.'chart_title')" :options="$chartOptions" :chart-id="'gbp-chart-overview-'.$days" />
                @else
                    <section class="{{ $card }} text-sm text-gray-500">{{ __($g.'no_performance') }}</section>
                @endif
            </div>
            <section class="{{ $card }}">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="font-semibold text-gray-900 dark:text-white">{{ __($g.'top_advice') }}</h2>
                    <button type="button" wire:click="setTab('advisor')" class="text-xs font-semibold text-brand-600 hover:underline">{{ __($g.'see_advisor') }}</button>
                </div>
                <div class="mt-3 space-y-3">
                    @forelse ($topAdvice as $item)
                        <div class="rounded-lg border border-gray-100 p-3 dark:border-gray-700">
                            <div class="flex items-center gap-2">
                                <x-ta.badge :color="$item->severityColor()" size="sm">{{ $item->severityLabel() }}</x-ta.badge>
                                @if ($item->impact_label)<span class="text-[11px] text-gray-500">{{ $item->impact_label }}</span>@endif
                            </div>
                            <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $item->title }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">{{ __($g.'no_advice') }}</p>
                    @endforelse
                </div>
            </section>
        </div>

        <section class="{{ $card }}">
            <h2 class="font-semibold text-gray-900 dark:text-white">{{ __($g.'collected_profile') }}</h2>
            <dl class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                @foreach (($profile['fields'] ?? []) as $field)
                    <div>
                        <dt class="text-xs text-gray-400">{{ __($g.'fields.'.($field['key'] ?? '')) }}</dt>
                        <dd @class(['mt-1 break-words text-sm font-medium', 'text-gray-900 dark:text-white' => ($field['state'] ?? '') === 'present', 'text-amber-600' => ($field['state'] ?? '') !== 'present'])>{{ ($field['state'] ?? '') === 'present' ? $field['value'] : __($g.'states.missing') }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

    @elseif ($tab === 'performance')
        @if (! ($perf['available'] ?? false))
            <section class="{{ $card }} text-sm text-gray-500">{{ __($g.'no_performance') }}</section>
        @else
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach (($perf['metrics'] ?? []) as $key => $metric)
                    @php
                        [$dText, $dClass] = $delta($metric['change_pct']);
                    @endphp
                    <section class="{{ $card }}">
                        <p class="text-xs text-gray-400">{{ __($g.'metrics.'.$key) }}</p>
                        <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{{ $num($metric['current']) }}</p>
                        <p class="mt-1 text-xs {{ $dClass }}">{{ $dText }}@if ($metric['previous'] !== null) <span class="text-gray-400">({{ $num($metric['previous']) }})</span>@endif</p>
                    </section>
                @endforeach
            </div>
            @if ($chartOptions)
                <x-ta.chart-card :title="__($g.'chart_title')" :options="$chartOptions" :chart-id="'gbp-chart-performance-'.$days" />
            @endif
        @endif

        <section class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                <h2 class="font-semibold text-gray-900 dark:text-white">{{ __($g.'keywords_title') }}</h2>
                <p class="mt-1 text-xs text-gray-500">{{ __($g.'keywords_hint', ['months' => $keywords['months'] ?? 3]) }} @if ($keywords['available'] ?? false){{ __($g.'period_line', ['from' => $keywords['from'], 'to' => $keywords['to']]) }}@endif</p>
            </div>
            @if (($keywords['items'] ?? []) === [])
                <p class="px-5 py-6 text-sm text-gray-500">{{ __($g.'no_keywords') }}</p>
            @else
                @php
                    $maxImpressions = max(1, (int) ($keywords['items'][0]['impressions'] ?? 1));
                @endphp
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-400"><th class="px-5 py-2 font-medium">{{ __($g.'keyword') }}</th><th class="px-5 py-2 text-right font-medium">{{ __($g.'impressions') }}</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($keywords['items'] as $row)
                            <tr>
                                <td class="px-5 py-2 text-gray-800 dark:text-gray-200">
                                    {{ $row['keyword'] }}
                                    <div class="mt-1 h-1 rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-1 rounded-full bg-sky-500" style="width: {{ (int) round($row['impressions'] / $maxImpressions * 100) }}%"></div></div>
                                </td>
                                <td class="px-5 py-2 text-right font-medium tabular-nums text-gray-900 dark:text-white">{{ $num($row['impressions']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if (($keywords['below_threshold_count'] ?? 0) > 0)
                    <p class="border-t border-gray-100 px-5 py-3 text-xs text-gray-500 dark:border-gray-700">{{ __($g.'keywords_hidden', ['count' => $keywords['below_threshold_count']]) }}</p>
                @endif
            @endif
        </section>

    @elseif ($tab === 'reviews')
        @if (! ($reviews['available'] ?? false))
            <section class="{{ $card }} text-sm text-gray-500">{{ __($g.'no_reviews') }}</section>
        @else
            <div class="grid gap-4 xl:grid-cols-3">
                <section class="{{ $card }}">
                    <h2 class="font-semibold text-gray-900 dark:text-white">{{ __($g.'reviews_summary') }}</h2>
                    <p class="mt-3 text-3xl font-bold text-gray-900 dark:text-white">{{ ($reviews['average'] ?? null) !== null ? number_format((float) $reviews['average'], 1, ',', '.') : '—' }} <span class="text-amber-500">★</span></p>
                    <p class="mt-1 text-sm text-gray-500">{{ __($g.'kpi.reviews_total', ['count' => $num($reviews['total'])]) }}</p>
                    <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __($g.'reviews_recent', ['count' => $reviews['recent_count'], 'avg' => ($reviews['recent_average'] ?? null) !== null ? number_format((float) $reviews['recent_average'], 1, ',', '.') : '—']) }}</p>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __($g.'reply_rate') }}: <span class="font-semibold">%{{ $reviews['reply_rate'] ?? 0 }}</span></p>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __($g.'kpi.unanswered') }}: <span @class(['font-semibold', 'text-rose-600' => ($reviews['unanswered_recent'] ?? 0) > 0])>{{ $reviews['unanswered_recent'] ?? 0 }}</span></p>
                </section>
                <section class="{{ $card }} xl:col-span-2">
                    <h2 class="font-semibold text-gray-900 dark:text-white">{{ __($g.'distribution') }}</h2>
                    @php
                        $maxStars = max(1, max($reviews['distribution'] ?? [1]));
                    @endphp
                    <div class="mt-3 space-y-2">
                        @foreach (($reviews['distribution'] ?? []) as $stars => $count)
                            <div class="flex items-center gap-3 text-sm">
                                <span class="w-8 text-gray-500">{{ $stars }} ★</span>
                                <div class="h-2 flex-1 rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-2 rounded-full bg-amber-400" style="width: {{ (int) round($count / $maxStars * 100) }}%"></div></div>
                                <span class="w-10 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ $count }}</span>
                            </div>
                        @endforeach
                    </div>
                </section>
            </div>

            <section class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                    <h2 class="font-semibold text-gray-900 dark:text-white">{{ __($g.'latest_reviews') }}</h2>
                    <p class="mt-1 text-xs text-gray-500">{{ __($g.'reply_note') }}</p>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach (($reviews['latest'] ?? []) as $review)
                        <div class="px-5 py-4">
                            <div class="flex flex-wrap items-center gap-2 text-sm">
                                <span class="font-medium text-gray-900 dark:text-white">{{ $review['reviewer'] }}</span>
                                <span class="text-amber-500">{{ $review['rating'] !== null ? str_repeat('★', $review['rating']).str_repeat('☆', 5 - $review['rating']) : '' }}</span>
                                <span class="text-xs text-gray-400">{{ $review['date'] }}</span>
                                <span @class([
                                    'ml-auto rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                    'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $review['replied'],
                                    'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => ! $review['replied'],
                                ])>{{ $review['replied'] ? __($g.'replied') : __($g.'not_replied') }}</span>
                            </div>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $review['comment'] !== '' ? \Illuminate\Support\Str::limit($review['comment'], 400) : __($g.'no_comment') }}</p>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

    @elseif ($tab === 'profile')
        <div class="grid gap-4 xl:grid-cols-2">
            <section class="{{ $card }}">
                <h2 class="font-semibold text-gray-900 dark:text-white">{{ __($g.'completeness_title') }} @if ($completeness !== null)<span class="text-brand-600">· %{{ $completeness['score'] }}</span>@endif</h2>
                @if ($completeness === null)
                    <p class="mt-3 text-sm text-gray-500">{{ __($g.'no_profile_data') }}</p>
                @else
                    <ul class="mt-3 space-y-2">
                        @foreach ($completeness['items'] as $item)
                            <li class="flex items-center gap-2 text-sm">
                                <span @class(['flex h-5 w-5 items-center justify-center rounded-full text-[11px] font-bold', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' => $item['done'], 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' => ! $item['done']])>{{ $item['done'] ? '✓' : '!' }}</span>
                                <span @class(['text-gray-700 dark:text-gray-300' => $item['done'], 'font-medium text-gray-900 dark:text-white' => ! $item['done']])>{{ __($g.'completeness_items.'.$item['key']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
            <section class="{{ $card }}">
                <h2 class="font-semibold text-gray-900 dark:text-white">{{ __($g.'profile_fields') }}</h2>
                <dl class="mt-3 space-y-3">
                    @foreach (($profile['fields'] ?? []) as $field)
                        <div><dt class="text-xs text-gray-400">{{ __($g.'fields.'.($field['key'] ?? '')) }}</dt><dd class="mt-0.5 break-words text-sm font-medium text-gray-900 dark:text-white">{{ $field['value'] }}</dd></div>
                    @endforeach
                    <div><dt class="text-xs text-gray-400">{{ __($g.'additional_categories') }}</dt><dd class="mt-0.5 text-sm text-gray-900 dark:text-white">{{ ($profile['categories']['additional'] ?? []) !== [] ? implode(', ', $profile['categories']['additional']) : '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-400">{{ __($g.'hours') }}</dt><dd class="mt-0.5 text-sm text-gray-900 dark:text-white">{{ count($profile['hours'] ?? []) }}/7</dd></div>
                </dl>
            </section>
            <section class="{{ $card }}">
                <h2 class="font-semibold text-gray-900 dark:text-white">{{ __($g.'description') }}</h2>
                <p class="mt-2 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ filled($profile['description'] ?? '') ? $profile['description'] : __($g.'no_description') }}</p>
                <h2 class="mt-5 font-semibold text-gray-900 dark:text-white">{{ __($g.'services') }}</h2>
                @if (($profile['services'] ?? []) === [])
                    <p class="mt-2 text-sm text-gray-500">{{ __($g.'no_services') }}</p>
                @else
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($profile['services'] as $service)
                            <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs text-gray-700 dark:bg-white/[0.06] dark:text-gray-300">{{ $service }}</span>
                        @endforeach
                    </div>
                @endif
            </section>
            <section class="{{ $card }}">
                <h2 class="font-semibold text-gray-900 dark:text-white">{{ __($g.'content_title') }}</h2>
                @if (! ($content['available'] ?? false))
                    <p class="mt-2 text-sm text-gray-500">—</p>
                @else
                    <div class="mt-3 grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-xs text-gray-400">{{ __($g.'photos') }}</p>
                            <p class="mt-1 text-xl font-bold text-gray-900 dark:text-white">{{ $content['photos'] }}</p>
                            <p class="text-xs text-gray-500">{{ __($g.'photos_90d', ['count' => $content['photos_90d']]) }}</p>
                            @if ($content['last_photo'])<p class="text-xs text-gray-400">{{ __($g.'last_date', ['date' => $content['last_photo']]) }}</p>@endif
                        </div>
                        <div>
                            <p class="text-xs text-gray-400">{{ __($g.'posts') }}</p>
                            <p class="mt-1 text-xl font-bold text-gray-900 dark:text-white">{{ $content['posts'] }}</p>
                            <p class="text-xs text-gray-500">{{ __($g.'posts_90d', ['count' => $content['posts_90d']]) }}</p>
                            @if ($content['last_post'])<p class="text-xs text-gray-400">{{ __($g.'last_date', ['date' => $content['last_post']]) }}</p>@endif
                        </div>
                    </div>
                @endif
                @if (($profile['attributes_unset'] ?? []) !== [])
                    <h3 class="mt-5 text-sm font-semibold text-gray-900 dark:text-white">{{ __($g.'attributes_missing') }}</h3>
                    <p class="mt-1 text-xs text-gray-500">{{ implode(', ', array_slice($profile['attributes_unset'], 0, 20)) }}</p>
                @endif
            </section>
        </div>
    @endif
</div>
