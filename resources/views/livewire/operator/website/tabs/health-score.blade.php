@php
    $healthScoreTones = [
        'good' => ['ring' => 'ring-emerald-200 dark:ring-emerald-500/30', 'text' => 'text-emerald-600 dark:text-emerald-400', 'badge' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300', 'bar' => 'bg-emerald-500'],
        'fair' => ['ring' => 'ring-amber-200 dark:ring-amber-500/30', 'text' => 'text-amber-600 dark:text-amber-400', 'badge' => 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300', 'bar' => 'bg-amber-500'],
        'poor' => ['ring' => 'ring-rose-200 dark:ring-rose-500/30', 'text' => 'text-rose-600 dark:text-rose-400', 'badge' => 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300', 'bar' => 'bg-rose-500'],
    ];
    $healthSeverityTones = [
        'critical' => 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300',
        'high' => 'border-orange-200 bg-orange-50 text-orange-700 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-300',
        'medium' => 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300',
        'low' => 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-300',
        'info' => 'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300',
    ];
    $healthTone = $healthScoreTones[$healthScore['grade'] ?? 'poor'] ?? $healthScoreTones['poor'];
    $healthCollectedAt = $healthScore['collected_at'] ? \Carbon\CarbonImmutable::parse($healthScore['collected_at'])->timezone(config('app.timezone')) : null;
    $healthUrlLimit = \App\Services\Website\WebsiteHealthScoreService::URL_DISPLAY_LIMIT;
    $healthWeights = $healthScore['severity_weights'];
    $healthSummaryCards = [
        ['key' => 'broken_pages', 'unavailable' => null],
        ['key' => 'broken_links', 'unavailable' => __('operator_website.health_score.unavailable.links')],
        ['key' => 'redirects', 'unavailable' => null],
        ['key' => 'orphans', 'unavailable' => __('operator_website.health_score.unavailable.links')],
        ['key' => 'duplicates', 'unavailable' => __('operator_website.health_score.unavailable.duplicates')],
    ];
@endphp

<section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-website-health-score>
    <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
        <div>
            <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('operator_website.health_score.title') }}</h3>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('operator_website.health_score.subtitle') }}</p>
        </div>
        <button type="button" wire:click="exportHealthCsv" class="shrink-0 rounded-lg bg-white px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">{{ __('operator_website.health_score.export_csv') }}</button>
    </div>

    <div class="grid gap-5 p-5 lg:grid-cols-[auto_1fr_1fr]">
        <div class="flex items-center gap-4">
            <div class="flex h-24 w-24 shrink-0 flex-col items-center justify-center rounded-full ring-8 ring-inset {{ $healthTone['ring'] }}" data-health-score-value="{{ $healthScore['score'] }}">
                <span class="text-3xl font-bold {{ $healthTone['text'] }}">{{ $healthScore['score'] ?? '—' }}</span>
                <span class="text-[10px] font-medium uppercase tracking-wide text-gray-400">/ 100</span>
            </div>
            <div>
                @if ($healthScore['grade'])
                    <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-medium {{ $healthTone['badge'] }}">{{ __('operator_website.health_score.grades.'.$healthScore['grade']) }}</span>
                @endif
                @if ($healthScore['change'] !== null)
                    <p @class([
                        'mt-2 text-xs font-medium',
                        'text-emerald-600 dark:text-emerald-400' => $healthScore['change'] > 0,
                        'text-rose-600 dark:text-rose-400' => $healthScore['change'] < 0,
                        'text-gray-500' => $healthScore['change'] === 0,
                    ])>{{ $healthScore['change'] === 0 ? __('operator_website.health_score.no_change') : __('operator_website.health_score.change', ['value' => ($healthScore['change'] > 0 ? '+' : '−').abs($healthScore['change'])]) }}</p>
                @endif
            </div>
        </div>

        <dl class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <dt class="text-xs text-gray-400">{{ __('operator_website.health_score.pages_checked') }}</dt>
                <dd class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ number_format($healthScore['pages_checked'], 0, ',', '.') }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-400">{{ __('operator_website.health_score.crawl_count') }}</dt>
                <dd class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ number_format($healthScore['crawl_count'], 0, ',', '.') }}</dd>
            </div>
            <div class="col-span-2">
                <dt class="text-xs text-gray-400">{{ __('operator_website.health_score.collected_at') }}</dt>
                <dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $healthCollectedAt ? $healthCollectedAt->format('d.m.Y H:i').' · '.$healthCollectedAt->diffForHumans() : '—' }}</dd>
            </div>
        </dl>

        <div>
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('operator_website.health_score.trend_title') }}</p>
            @if ($healthScore['trend'] !== [])
                <p class="mt-0.5 text-[11px] text-gray-400">{{ __('operator_website.health_score.trend_hint', ['count' => count($healthScore['trend'])]) }}</p>
                <div class="mt-3 flex h-24 items-end gap-2" data-health-trend>
                    @foreach ($healthScore['trend'] as $point)
                        <div class="flex min-w-0 flex-1 flex-col items-center justify-end gap-1" title="{{ $point['collected_at'] ? \Carbon\CarbonImmutable::parse($point['collected_at'])->format('d.m.Y H:i') : '' }}">
                            <span class="text-[11px] font-semibold text-gray-700 dark:text-gray-300">{{ $point['score'] ?? '—' }}</span>
                            <div class="w-full rounded-t {{ ($healthScoreTones[$point['score'] === null ? 'poor' : ($point['score'] >= 80 ? 'good' : ($point['score'] >= 60 ? 'fair' : 'poor'))])['bar'] }}" style="height: {{ max(4, (int) round(($point['score'] ?? 0) * 0.6)) }}px"></div>
                            <span class="truncate text-[10px] text-gray-400">{{ $point['collected_at'] ? \Carbon\CarbonImmutable::parse($point['collected_at'])->format('d.m') : '—' }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-2 text-xs text-gray-400">{{ __('operator_website.health_score.trend_hidden') }}</p>
            @endif
        </div>
    </div>

    @if ($healthScore['source'] === 'projection')
        <p class="mx-5 mb-4 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500 dark:bg-white/[0.03] dark:text-gray-400">{{ __('operator_website.health_score.source_projection') }}</p>
    @endif

    <details class="border-t border-gray-100 px-5 py-3 dark:border-gray-800">
        <summary class="cursor-pointer text-xs font-medium text-brand-600">{{ __('operator_website.health_score.formula_title') }}</summary>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('operator_website.health_score.formula', ['critical' => $healthWeights['critical'], 'high' => $healthWeights['high'], 'medium' => $healthWeights['medium'], 'low' => $healthWeights['low']]) }}</p>
    </details>

    <div class="grid gap-px border-t border-gray-100 bg-gray-100 sm:grid-cols-5 dark:border-gray-800 dark:bg-gray-800">
        @foreach ($healthSummaryCards as $card)
            <div class="bg-white px-5 py-4 dark:bg-gray-900" data-health-summary="{{ $card['key'] }}">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('operator_website.health_score.summary.'.$card['key']) }}</p>
                <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $healthScore['summary'][$card['key']] === null ? '—' : number_format($healthScore['summary'][$card['key']], 0, ',', '.') }}</p>
                @if ($healthScore['summary'][$card['key']] === null && $card['unavailable'])
                    <p class="mt-1 text-[11px] text-gray-400">{{ $card['unavailable'] }}</p>
                @endif
            </div>
        @endforeach
    </div>
</section>

<section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-website-health-groups>
    <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
        <h3 class="font-semibold text-gray-900 dark:text-white">{{ __('operator_website.health_score.groups_title') }}</h3>
        <p class="mt-1 text-xs text-gray-500">{{ __('operator_website.health_score.groups_hint') }}</p>
    </div>
    @if ($healthScore['groups'] === [])
        <p class="px-5 py-10 text-center text-sm text-gray-500">{{ __('operator_website.health_score.no_issues') }}</p>
    @else
        <div class="hidden grid-cols-[minmax(0,1fr)_7rem_7rem_7rem_8rem] gap-3 border-b border-gray-100 px-5 py-2 text-[11px] font-medium uppercase tracking-wide text-gray-400 md:grid dark:border-gray-800">
            <span>{{ __('operator_website.health_score.columns.issue') }}</span>
            <span>{{ __('operator_website.health_score.columns.severity') }}</span>
            <span class="text-right">{{ __('operator_website.health_score.columns.affected') }}</span>
            <span class="text-right">{{ __('operator_website.health_score.columns.share') }}</span>
            <span class="text-right">{{ __('operator_website.health_score.columns.new') }}</span>
        </div>
        @foreach ($healthScore['groups'] as $group)
            <details class="group border-b border-gray-100 last:border-0 dark:border-gray-800" data-health-group="{{ $group['code'] }}">
                <summary class="grid cursor-pointer list-none grid-cols-2 gap-3 px-5 py-3 hover:bg-gray-50 md:grid-cols-[minmax(0,1fr)_7rem_7rem_7rem_8rem] md:items-center dark:hover:bg-white/[0.03]">
                    <div class="col-span-2 min-w-0 md:col-span-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><span class="mr-1 text-gray-400 group-open:hidden">▸</span><span class="mr-1 hidden text-gray-400 group-open:inline">▾</span>{{ $group['label'] }}</p>
                        <p class="mt-1 flex flex-wrap items-center gap-2 text-[11px] text-gray-400">
                            <span class="font-mono">{{ $group['code'] }}</span>
                            <span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-500 dark:bg-gray-800">{{ __('operator_website.health_score.kinds.'.$group['kind']) }}</span>
                            @if (! $group['scored'])
                                <span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-500 dark:bg-gray-800">{{ __('operator_website.health_score.not_scored') }}</span>
                            @endif
                        </p>
                    </div>
                    <span><span class="inline-flex rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $healthSeverityTones[$group['severity']] ?? $healthSeverityTones['info'] }}">{{ __('operator_website.severity.'.$group['severity']) }}</span></span>
                    <span class="text-sm font-semibold text-gray-900 md:text-right dark:text-white">{{ number_format($group['url_count'], 0, ',', '.') }}</span>
                    <span class="text-sm text-gray-600 md:text-right dark:text-gray-300">%{{ number_format($group['share'] * 100, 1, ',', '.') }}</span>
                    <span class="text-sm text-gray-600 md:text-right dark:text-gray-300">{{ $group['new_count'] === null ? __('operator_website.health_score.new_unknown') : number_format($group['new_count'], 0, ',', '.') }}</span>
                </summary>
                <ul class="space-y-1 bg-gray-50 px-5 py-3 text-xs dark:bg-white/[0.02]">
                    @foreach (array_slice($group['items'], 0, $healthUrlLimit) as $item)
                        <li class="flex flex-col gap-0.5 sm:flex-row sm:items-center sm:gap-3">
                            @if (preg_match('#^https?://#i', $item['url']) === 1)
                                <a href="{{ $item['url'] }}" target="_blank" rel="noopener noreferrer" class="min-w-0 truncate font-medium text-brand-600 hover:underline">{{ $item['url'] }}</a>
                            @else
                                <span class="min-w-0 truncate font-medium text-gray-700 dark:text-gray-300">{{ $item['url'] }}</span>
                            @endif
                            @if ($item['detail'])
                                <span class="min-w-0 truncate text-gray-500 dark:text-gray-400">{{ $item['detail'] }}</span>
                            @endif
                        </li>
                    @endforeach
                    @if (count($group['items']) > $healthUrlLimit)
                        <li class="pt-1 text-gray-400">{{ __('operator_website.health_score.url_limit', ['limit' => $healthUrlLimit, 'total' => count($group['items'])]) }}</li>
                    @endif
                </ul>
            </details>
        @endforeach
    @endif
</section>
