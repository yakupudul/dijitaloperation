@php
    $card = 'min-w-0';
    $fmt = function ($value, string $format): string {
        if ($value === null) {
            return '—';
        }

        return match ($format) {
            'pct' => '%'.number_format((float) $value, 2, ',', '.'),
            'dec' => number_format((float) $value, 1, ',', '.'),
            default => number_format((float) $value, 0, ',', '.'),
        };
    };
    $formatOf = ['clicks' => 'int', 'impressions' => 'int', 'ctr' => 'pct', 'position' => 'dec', 'sessions' => 'int', 'key_events' => 'dec'];
    $colors = ['gsc' => '#2a78d6', 'ga4' => '#eb6834'];
    $w = 600;
    $h = 150;
    /** SVG path of a series; position is drawn inverted (1 at the top). */
    $path = function (array $points, array $chart, bool $invert) use ($w, $h): string {
        $n = count($points);
        if ($n === 0) {
            return '';
        }
        $min = $chart['metric'] === 'position' ? $chart['min'] : 0.0;
        $span = max($chart['max'] - $min, 0.0001);
        $d = '';
        $pen = false;
        foreach ($points as $i => $point) {
            if ($point['value'] === null) {
                $pen = false;

                continue;
            }
            $x = $n === 1 ? $w / 2 : $i / ($n - 1) * $w;
            $ratio = ((float) $point['value'] - $min) / $span;
            $y = $invert ? 8 + $ratio * ($h - 16) : $h - 8 - $ratio * ($h - 16);
            $d .= ($pen ? 'L' : 'M').round($x, 1).' '.round($y, 1).' ';
            $pen = true;
        }

        return trim($d);
    };
    $spark = function (array $values): string {
        $values = array_values($values);
        $n = count($values);
        $nums = array_filter($values, fn ($v) => $v !== null);
        if ($n < 2 || $nums === []) {
            return '';
        }
        $max = max($nums);
        $min = min($nums);
        $span = max($max - $min, 0.0001);
        $d = '';
        foreach ($values as $i => $v) {
            if ($v === null) {
                continue;
            }
            $d .= ($d === '' ? 'M' : 'L').round($i / ($n - 1) * 100, 1).' '.round(26 - ($v - $min) / $span * 24, 1).' ';
        }

        return trim($d);
    };
    $tooltip = fn (array $chart) => array_map(fn ($point, $i) => [
        'date' => \Carbon\CarbonImmutable::parse($point['date'])->format('d.m.Y'),
        'value' => $fmt($point['value'], $formatOf[$chart['metric']]),
        'prev' => isset($chart['previous'][$i]) ? $fmt($chart['previous'][$i]['value'], $formatOf[$chart['metric']]) : '—',
    ], $chart['current'], array_keys($chart['current']));
    $charts = [
        ['gsc', $gscChart, \App\Livewire\Operator\Website\V2\AnalyticsTab::GSC_METRICS, 'Search Console', $trend['has_gsc']],
        ['ga4', $ga4Chart, \App\Livewire\Operator\Website\V2\AnalyticsTab::GA4_METRICS, 'Google Analytics 4', $trend['has_ga4']],
    ];
    $first = $trend['series'][0]['date'] ?? null;
    $last = $trend['series'] !== [] ? end($trend['series'])['date'] : null;
@endphp
<div class="min-w-0 space-y-10 text-sm" data-analytics-tab>
    {{-- 1. The answer: is search traffic and what it brings up or down against the previous period. Click a number to chart it. --}}
    <section class="metric-strip grid-cols-2 sm:grid-cols-3 xl:grid-cols-6" data-scorecards>
        @foreach ($cards as $metric => $item)
            @php $selected = $item['source'] === 'gsc' ? $gscMetric === $metric : $ga4Metric === $metric; @endphp
            <button type="button" wire:click="pick('{{ $metric }}')" data-scorecard="{{ $metric }}" aria-pressed="{{ $selected ? 'true' : 'false' }}"
                    @if ($selected) style="box-shadow: inset 0 -2px 0 {{ $colors[$item['source']] }}" @endif
                    @class(['flex min-w-0 flex-col px-[18px] py-4 text-left transition', 'bg-gray-50 dark:bg-gray-800' => $selected, 'bg-white hover:bg-gray-50 dark:bg-gray-900 dark:hover:bg-gray-800/60' => ! $selected])>
                <span class="flex items-center justify-between gap-2 text-[13px] text-gray-500 dark:text-gray-400">
                    <span class="truncate">{{ $item['label'] }}</span>
                    <span class="shrink-0 text-[11px] text-gray-400">{{ $item['source'] === 'gsc' ? 'GSC' : 'GA4' }}</span>
                </span>
                <span class="mt-1.5 block text-[26px] leading-8 font-semibold tracking-[-0.02em] tabular-nums text-gray-900 dark:text-white">{{ $fmt($item['value'], $item['format']) }}</span>
                <span class="mt-1 flex min-w-0 items-center gap-1.5 text-xs">
                    @if ($item['delta'] !== null)
                        <span @class(['shrink-0 font-medium tabular-nums', 'text-success-600 dark:text-success-400' => $item['better'] === true, 'text-error-600 dark:text-error-400' => $item['better'] === false, 'text-gray-500' => $item['better'] === null])>{{ $item['delta'] > 0 ? '+' : ($item['delta'] < 0 ? '−' : '') }}%{{ number_format(abs($item['delta']), 1, ',', '.') }}</span>
                    @endif
                    <span class="truncate text-gray-500">önceki {{ $fmt($item['previous'], $item['format']) }}</span>
                </span>
                @if (($d = $spark($item['points'])) !== '')
                    <svg viewBox="0 0 100 28" preserveAspectRatio="none" class="mt-2.5 h-6 w-full" aria-hidden="true"><path d="{{ $d }}" fill="none" stroke="{{ $colors[$item['source']] }}" stroke-width="1.5" vector-effect="non-scaling-stroke" /></svg>
                @endif
            </button>
        @endforeach
    </section>

    {{-- 2. The day-by-day lines of the chosen numbers. --}}
    <section class="card" data-charts>
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b border-gray-200 px-4 py-3 text-xs text-gray-500 dark:border-gray-800">
            <span>Günlük, {{ $first ? \App\Services\Site\Analysis\SiteRange::format($first, $last) : '—' }}</span>
            <span class="flex items-center gap-3">
                <span class="flex items-center gap-1.5"><span class="inline-block h-0.5 w-4 bg-gray-700 dark:bg-gray-200"></span>bu dönem</span>
                <span class="flex items-center gap-1.5"><span class="inline-block w-4 border-t border-dashed border-gray-400"></span>karşılaştırma</span>
            </span>
        </div>
        @foreach ($charts as [$source, $chart, $metrics, $sourceLabel, $has])
            <div class="border-t border-gray-200 px-4 py-4 first-of-type:border-t-0 dark:border-gray-800" data-chart="{{ $source }}">
                <div class="mb-2 flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1.5">
                    <span class="flex items-center gap-1.5 text-[13px] font-medium text-gray-900 dark:text-white"><span class="h-2 w-2 rounded-full" style="background: {{ $colors[$source] }}" aria-hidden="true"></span>{{ $sourceLabel }}</span>
                    <span class="flex flex-wrap gap-1">
                        @foreach ($metrics as $key => $label)
                            <button type="button" wire:click="pick('{{ $key }}')" @class(['h-7 rounded-md px-2 text-xs', 'bg-gray-100 font-medium text-gray-900 dark:bg-white/10 dark:text-white' => $chart['metric'] === $key, 'text-gray-500 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/5' => $chart['metric'] !== $key])>{{ $label }}</button>
                        @endforeach
                    </span>
                    @if ($chart['metric'] === 'position')<span class="text-xs text-gray-500">yukarısı daha iyi</span>@endif
                </div>
                @if (! $has)
                    <p class="py-6 text-center text-[13px] text-gray-500">{{ $sourceLabel }} bağlı değil.</p>
                @else
                    <div class="relative" x-data="siteChart(@js($tooltip($chart)))" @mousemove="move($event)" @mouseleave="leave()">
                        <svg viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none" class="h-40 w-full" role="img" aria-label="{{ $sourceLabel }}, {{ $metrics[$chart['metric']] }}">
                            @foreach ([0.25, 0.5, 0.75] as $line)<line x1="0" x2="{{ $w }}" y1="{{ $h * $line }}" y2="{{ $h * $line }}" stroke="currentColor" class="text-gray-100 dark:text-gray-800" vector-effect="non-scaling-stroke" />@endforeach
                            <path d="{{ $path($chart['previous'], $chart, $chart['metric'] === 'position') }}" fill="none" stroke="#98a2b3" stroke-width="1.5" stroke-dasharray="4 4" vector-effect="non-scaling-stroke" />
                            <path d="{{ $path($chart['current'], $chart, $chart['metric'] === 'position') }}" fill="none" stroke="{{ $colors[$source] }}" stroke-width="2" vector-effect="non-scaling-stroke" />
                        </svg>
                        <template x-if="hover !== null && points[hover]">
                            <div>
                                <div class="pointer-events-none absolute inset-y-0 w-px bg-gray-300 dark:bg-gray-600" :style="'left:' + (points.length > 1 ? hover / (points.length - 1) * 100 : 50) + '%'"></div>
                                <div class="pointer-events-none absolute top-1 z-10 rounded-lg bg-gray-900 px-2.5 py-1.5 text-xs text-white shadow-lg"
                                     :style="(hover / Math.max(points.length - 1, 1) > 0.6 ? 'right:' + (100 - hover / Math.max(points.length - 1, 1) * 100) + '%' : 'left:' + (hover / Math.max(points.length - 1, 1) * 100) + '%') + ';margin:0 8px'">
                                    <p class="text-gray-300" x-text="points[hover].date"></p>
                                    <p><span class="font-semibold" x-text="points[hover].value"></span> <span class="text-gray-400">önceki <span x-text="points[hover].prev"></span></span></p>
                                </div>
                            </div>
                        </template>
                    </div>
                @endif
            </div>
        @endforeach
    </section>

    {{-- 3. From a search to a conversion. --}}
    <section class="min-w-0" data-funnel>
        <div class="section-head"><h2 class="section-title">Aramadan dönüşüme</h2></div>
        @php $top = max(1, (float) ($funnel[0]['value'] ?? 0)); @endphp
        <ol class="metric-strip grid-cols-2 md:grid-cols-4">
            @foreach ($funnel as $i => $step)
                <li class="flex min-w-0 flex-col bg-white px-[18px] py-4 dark:bg-gray-900" data-funnel-step="{{ $i }}">
                    <p class="text-[13px] text-gray-500 dark:text-gray-400">{{ $step['label'] }}</p>
                    <p class="mt-1.5 text-[26px] leading-8 font-semibold tracking-[-0.02em] tabular-nums text-gray-900 dark:text-white">{{ $fmt($step['value'], $i === 3 ? 'dec' : 'int') }}</p>
                    <div class="mt-2.5 h-1 rounded-full bg-gray-100 dark:bg-gray-800"><div class="h-1 rounded-full bg-gray-400 dark:bg-gray-500" style="width: {{ $step['value'] !== null ? max(2, min(100, $step['value'] / $top * 100)) : 0 }}%"></div></div>
                    <p class="mt-2 text-xs text-gray-500">@if ($step['rate'] !== null)<span class="font-medium text-gray-700 dark:text-gray-300">%{{ number_format($step['rate'], 1, ',', '.') }}</span>, @endif{{ $step['value'] === null && $i >= 2 ? 'GA4 kaynak / ortam verisi yok' : $step['note'] }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- 4. What the rules noticed in the numbers. --}}
    <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" data-insights>
        @foreach ($insights as $insight)
            <article class="card flex flex-col px-4 py-3.5" data-insight="{{ $insight['key'] }}">
                <div class="flex items-start justify-between gap-2">
                    <h4 class="text-[13px] text-gray-500 dark:text-gray-400">{{ $insight['title'] }}</h4>
                    <span @class(['mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full', 'bg-warning-500' => $insight['tone'] === 'warn', 'bg-success-500' => $insight['tone'] !== 'warn'])></span>
                </div>
                <p class="mt-1 text-[22px] leading-8 font-semibold tracking-[-0.02em] tabular-nums text-gray-900 dark:text-white">{{ $insight['value'] }}</p>
                <p class="mt-0.5 text-xs text-gray-500">{{ $insight['text'] }}</p>
                @if ($insight['items'] !== [])
                    <ul class="mt-2 space-y-0.5 text-xs text-gray-700 dark:text-gray-300">@foreach ($insight['items'] as $item)<li class="truncate" title="{{ $item }}">{{ $item }}</li>@endforeach</ul>
                @endif
                @if ($insight['tab'])
                    <button type="button" wire:click="$parent.setTab('{{ $insight['tab'] }}')" class="link-neutral mt-auto pt-2.5 text-left text-xs">Ayrıntı</button>
                @endif
            </article>
        @endforeach
    </section>

    {{-- 5. Page by page. --}}
    <section class="min-w-0" data-page-scorecard>
        <div class="section-head justify-between">
            <div class="flex min-w-0 flex-wrap items-baseline gap-x-3">
                <h2 class="section-title" title="Search Console + GA4. Teşhis kurallarla, AI değil.">Sayfa karnesi</h2>
                <span class="section-count">{{ count($scorecard) }} / {{ number_format($pageCount, 0, ',', '.') }}</span>
            </div>
            <span class="flex items-center gap-1.5">
                <button type="button" wire:click="$parent.setTab('sayfalar')" class="btn-quiet">Tüm sayfalar</button>
                <button type="button" wire:click="csv" class="btn" data-csv>CSV indir</button>
            </span>
        </div>
        <div class="card overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Sayfa</th>
                        <th class="text-right!">Tıklama</th><th class="text-right!">Gösterim</th><th class="text-right!">TO</th><th class="text-right!">Sıra</th>
                        <th class="text-right!">Oturum</th><th class="text-right!">Dönüşüm</th>
                        <th>Teşhis</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($scorecard as $row)
                        <tr wire:key="karne-{{ md5($row['path']) }}" data-karne-row="{{ $row['path'] }}">
                            <td class="max-w-xs min-w-[12rem]">
                                <span class="block truncate font-medium text-gray-900 dark:text-white">{{ $row['title'] ?: $row['path'] }}</span>
                                <span class="block truncate text-xs text-gray-500">{{ $row['path'] }}, {{ \App\Services\Site\Analysis\SitePagesReader::TYPES[$row['type']] ?? '' }}</span>
                            </td>
                            <td class="text-right tabular-nums">{{ $fmt($row['clicks'], 'int') }}</td>
                            <td class="text-right tabular-nums">{{ $fmt($row['impressions'], 'int') }}</td>
                            <td class="text-right tabular-nums">{{ $row['ctr'] === null ? '—' : '%'.number_format($row['ctr'], 1, ',', '.') }}</td>
                            <td class="text-right tabular-nums">{{ $fmt($row['position'], 'dec') }}</td>
                            <td class="text-right tabular-nums">{{ $fmt($row['sessions'], 'int') }}</td>
                            <td class="text-right tabular-nums">{{ $fmt($row['key_events'], 'dec') }}</td>
                            <td class="whitespace-nowrap"><x-ta.pill :tone="$row['diagnosis'] === 'İyi' ? 'ok' : 'look'">{{ $row['diagnosis'] }}</x-ta.pill></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-6 text-center text-[13px] text-gray-500">Bu dönemde Search Console / GA4 sayfa verisi yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
