@php
    $card = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
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
<div class="space-y-5 text-sm" data-analytics-tab>
    <section class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" data-scorecards>
        @foreach ($cards as $metric => $item)
            @php $selected = $item['source'] === 'gsc' ? $gscMetric === $metric : $ga4Metric === $metric; @endphp
            <button type="button" wire:click="pick('{{ $metric }}')" data-scorecard="{{ $metric }}" aria-pressed="{{ $selected ? 'true' : 'false' }}"
                    class="{{ $card }} relative overflow-hidden p-3 text-left transition hover:ring-gray-300 {{ $selected ? '!ring-2' : '' }}"
                    style="{{ $selected ? '--tw-ring-color: '.$colors[$item['source']] : '' }}">
                <span class="absolute inset-x-0 top-0 h-1" style="background: {{ $selected ? $colors[$item['source']] : 'transparent' }}"></span>
                <span class="flex items-center justify-between text-xs text-gray-500">
                    <span>{{ $item['label'] }}</span>
                    <span class="text-[10px] font-semibold uppercase" style="color: {{ $colors[$item['source']] }}">{{ $item['source'] === 'gsc' ? 'GSC' : 'GA4' }}</span>
                </span>
                <span class="mt-1 block text-xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $fmt($item['value'], $item['format']) }}</span>
                <span class="mt-0.5 flex items-center gap-1 text-xs">
                    @if ($item['delta'] !== null)
                        <span @class(['font-semibold tabular-nums', 'text-emerald-600' => $item['better'] === true, 'text-rose-600' => $item['better'] === false, 'text-gray-500' => $item['better'] === null])>{{ $item['delta'] > 0 ? '▲' : ($item['delta'] < 0 ? '▼' : '') }} %{{ number_format(abs($item['delta']), 1, ',', '.') }}</span>
                    @endif
                    <span class="truncate text-gray-400">önceki {{ $fmt($item['previous'], $item['format']) }}</span>
                </span>
                @if (($d = $spark($item['points'])) !== '')
                    <svg viewBox="0 0 100 28" preserveAspectRatio="none" class="mt-2 h-7 w-full" aria-hidden="true"><path d="{{ $d }}" fill="none" stroke="{{ $colors[$item['source']] }}" stroke-width="1.5" vector-effect="non-scaling-stroke" /></svg>
                @endif
            </button>
        @endforeach
    </section>

    <section class="{{ $card }} space-y-4 p-4" data-charts>
        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
            <span>Günlük · {{ $first ? \App\Services\Site\Analysis\SiteRange::format($first, $last) : '—' }}</span>
            <span class="flex items-center gap-3">
                <span class="flex items-center gap-1"><span class="inline-block h-0.5 w-4 bg-gray-700 dark:bg-gray-200"></span> bu dönem</span>
                <span class="flex items-center gap-1"><span class="inline-block w-4 border-t border-dashed border-gray-400"></span> karşılaştırma</span>
            </span>
        </div>
        @foreach ($charts as [$source, $chart, $metrics, $sourceLabel, $has])
            <div data-chart="{{ $source }}">
                <div class="mb-1 flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold" style="color: {{ $colors[$source] }}">{{ $sourceLabel }}</span>
                    <span class="flex gap-1">
                        @foreach ($metrics as $key => $label)
                            <button type="button" wire:click="pick('{{ $key }}')" @class(['rounded-md px-2 py-0.5 text-xs', 'bg-gray-900 font-semibold text-white dark:bg-white dark:text-gray-900' => $chart['metric'] === $key, 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800' => $chart['metric'] !== $key])>{{ $label }}</button>
                        @endforeach
                    </span>
                    @if ($chart['metric'] === 'position')<span class="text-[11px] text-gray-400">(yukarısı daha iyi)</span>@endif
                </div>
                @if (! $has)
                    <p class="rounded-lg bg-gray-50 p-6 text-center text-xs text-gray-500 dark:bg-white/[0.03]">{{ $sourceLabel }} bağlı değil.</p>
                @else
                    <div class="relative" x-data="siteChart(@js($tooltip($chart)))" @mousemove="move($event)" @mouseleave="leave()">
                        <svg viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none" class="h-40 w-full" role="img" aria-label="{{ $sourceLabel }} · {{ $metrics[$chart['metric']] }}">
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
                                    <p><span class="font-semibold" x-text="points[hover].value"></span> <span class="text-gray-400">· önceki <span x-text="points[hover].prev"></span></span></p>
                                </div>
                            </div>
                        </template>
                    </div>
                @endif
            </div>
        @endforeach
    </section>

    <section class="{{ $card }} p-4" data-funnel>
        <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">Aramadan dönüşüme</h3>
        @php $top = max(1, (float) ($funnel[0]['value'] ?? 0)); @endphp
        <ol class="grid grid-cols-2 gap-3 md:grid-cols-4">
            @foreach ($funnel as $i => $step)
                <li class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]" data-funnel-step="{{ $i }}">
                    <p class="text-xs text-gray-500">{{ $step['label'] }}</p>
                    <p class="text-lg font-semibold tabular-nums text-gray-900 dark:text-white">{{ $fmt($step['value'], $i === 3 ? 'dec' : 'int') }}</p>
                    <div class="mt-2 h-1.5 rounded-full bg-gray-200 dark:bg-gray-800"><div class="h-1.5 rounded-full" style="width: {{ $step['value'] !== null ? max(2, min(100, $step['value'] / $top * 100)) : 0 }}%; background: {{ $i < 2 ? $colors['gsc'] : $colors['ga4'] }}"></div></div>
                    <p class="mt-1.5 text-[11px] text-gray-500">@if ($step['rate'] !== null)<span class="font-semibold text-gray-700 dark:text-gray-300">%{{ number_format($step['rate'], 1, ',', '.') }}</span> · @endif{{ $step['value'] === null && $i >= 2 ? 'GA4 kaynak / ortam verisi yok' : $step['note'] }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    <section class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4" data-insights>
        @foreach ($insights as $insight)
            <article class="{{ $card }} flex flex-col p-4" data-insight="{{ $insight['key'] }}">
                <div class="flex items-start justify-between gap-2">
                    <h4 class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ $insight['title'] }}</h4>
                    <span @class(['mt-0.5 h-2 w-2 shrink-0 rounded-full', 'bg-amber-500' => $insight['tone'] === 'warn', 'bg-emerald-500' => $insight['tone'] !== 'warn'])></span>
                </div>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $insight['value'] }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ $insight['text'] }}</p>
                @if ($insight['items'] !== [])
                    <ul class="mt-2 space-y-0.5 text-xs text-gray-700 dark:text-gray-300">@foreach ($insight['items'] as $item)<li class="truncate" title="{{ $item }}">{{ $item }}</li>@endforeach</ul>
                @endif
                @if ($insight['tab'])
                    <button type="button" wire:click="$parent.setTab('{{ $insight['tab'] }}')" class="mt-auto pt-2 text-left text-xs font-medium text-brand-600 hover:underline">Ayrıntı →</button>
                @endif
            </article>
        @endforeach
    </section>

    <section class="{{ $card }} overflow-x-auto" data-page-scorecard>
        <header class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Sayfa karnesi</h3>
                <p class="text-xs text-gray-500">Search Console + GA4, en çok görünen {{ count($scorecard) }} sayfa (toplam {{ number_format($pageCount, 0, ',', '.') }}). Teşhis kurallarla, AI değil.</p>
            </div>
            <span class="flex items-center gap-2">
                <button type="button" wire:click="$parent.setTab('sayfalar')" class="text-xs font-medium text-brand-600 hover:underline">Tüm sayfalar →</button>
                <button type="button" wire:click="csv" class="h-8 rounded-lg px-3 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700" data-csv>CSV indir</button>
            </span>
        </header>
        <table class="w-full text-left text-xs">
            <thead class="text-gray-500">
                <tr>
                    <th class="px-4 py-2">Sayfa</th>
                    <th class="px-2 text-right"><span style="color: {{ $colors['gsc'] }}">●</span> Tıklama</th><th class="px-2 text-right">Gösterim</th><th class="px-2 text-right">TO</th><th class="px-2 text-right">Sıra</th>
                    <th class="px-2 text-right"><span style="color: {{ $colors['ga4'] }}">●</span> Oturum</th><th class="px-2 text-right">Dönüşüm</th>
                    <th class="px-4">Teşhis</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($scorecard as $row)
                    <tr class="border-t border-gray-100 dark:border-gray-800" wire:key="karne-{{ md5($row['path']) }}" data-karne-row="{{ $row['path'] }}">
                        <td class="max-w-xs px-4 py-2">
                            <span class="block truncate font-medium text-gray-900 dark:text-white">{{ $row['title'] ?: $row['path'] }}</span>
                            <span class="block truncate text-gray-400">{{ $row['path'] }} · {{ \App\Services\Site\Analysis\SitePagesReader::TYPES[$row['type']] ?? '' }}</span>
                        </td>
                        <td class="px-2 text-right tabular-nums">{{ $fmt($row['clicks'], 'int') }}</td>
                        <td class="px-2 text-right tabular-nums">{{ $fmt($row['impressions'], 'int') }}</td>
                        <td class="px-2 text-right tabular-nums">{{ $row['ctr'] === null ? '—' : '%'.number_format($row['ctr'], 1, ',', '.') }}</td>
                        <td class="px-2 text-right tabular-nums">{{ $fmt($row['position'], 'dec') }}</td>
                        <td class="px-2 text-right tabular-nums">{{ $fmt($row['sessions'], 'int') }}</td>
                        <td class="px-2 text-right tabular-nums">{{ $fmt($row['key_events'], 'dec') }}</td>
                        <td class="px-4"><span @class(['whitespace-nowrap rounded-full px-2 py-0.5', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $row['diagnosis'] === 'İyi', 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300' => $row['diagnosis'] !== 'İyi'])>{{ $row['diagnosis'] }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-6 text-center text-gray-500">Bu dönemde Search Console / GA4 sayfa verisi yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
