@php
    $card = 'min-w-0';
    $select = 'h-8 rounded-lg border border-gray-200 bg-white py-0 pl-2.5 pr-8 text-[13px] text-gray-700 shadow-card focus:border-gray-300 focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $num = fn ($value) => $value === null ? '—' : number_format((float) $value, 0, ',', '.');
    $dec = fn ($value) => $value === null ? '—' : number_format((float) $value, 1, ',', '.');
    $delta = function (int|float $current, int|float $previous): string {
        if ($previous == 0) {
            return $current > 0 ? 'yeni' : '';
        }
        $pct = round(($current - $previous) / $previous * 100);

        return ($pct > 0 ? '+' : '').$pct.'%';
    };
    $chipClass = fn ($current, $previous): string => 'chip '.($current > $previous ? 'chip-up' : ($current < $previous ? 'chip-down' : 'chip-flat'));
    $c = $totals['current'];
    $p = $totals['previous'];
    // Change against the previous period; for the average position a smaller number is better.
    $tone = fn ($current, $previous, bool $lowerIsBetter = false): string => $previous == 0 || $current == $previous ? 'neutral' : (($current > $previous) !== $lowerIsBetter ? 'positive' : 'negative');
    $subTitle = \App\Livewire\Operator\Website\V2\AnalysisTab::SUBTABS[$activeSub] ?? '';
@endphp
<div class="min-w-0 space-y-8 text-sm dark:text-gray-200" data-analysis-tab>
    {{-- 1. The answer: what search brought in this period against the previous one. --}}
    <section class="metric-strip grid-cols-2 sm:grid-cols-5" data-totals>
        @foreach ([['Tıklama', $c['clicks'], $p['clicks'], $num, false], ['Gösterim', $c['impressions'], $p['impressions'], $num, false], ['Ort. sıra', $c['position'], $p['position'], $dec, true], ['Oturum', $c['sessions'], $p['sessions'], $num, false], ['Anahtar etkinlik', $c['key_events'], $p['key_events'], $dec, false]] as [$label, $value, $previous, $format, $lowerIsBetter])
            <x-ta.metric-card :label="$label" :value="$format($value)" :delta="$value !== null && $previous !== null ? $delta($value, $previous) : null" :tone="$value !== null && $previous !== null ? $tone($value, $previous, $lowerIsBetter) : 'neutral'" :hint="'önceki '.$format($previous)" @class(['col-span-2 sm:col-span-1' => $loop->last]) />
        @endforeach
    </section>

    @if ($window['gsc'] === [] && $window['ga4'] === [])
        <p class="text-[13px] text-gray-500">Search Console / GA4 bağlı değil.</p>
    @endif

    {{-- 2. The rows: one view (queries, target queries, conversions…) per screen tab. --}}
    <section class="min-w-0">
        <div class="section-head justify-between">
            <div class="flex min-w-0 flex-wrap items-baseline gap-x-3">
                <h2 class="section-title">{{ $subTitle }}</h2>
                <span class="section-count">{{ $num($rows->total()) }}</span>
            </div>
            @if ($showNav || ! $screenRange)
                <div class="flex min-w-0 flex-wrap items-center gap-2">
                    @if ($showNav)
                        <nav class="no-scrollbar flex min-w-0 max-w-full gap-1.5 overflow-x-auto" aria-label="Analiz sekmeleri">
                            @foreach (\App\Livewire\Operator\Website\V2\AnalysisTab::SUBTABS as $key => $label)
                                <button type="button" wire:click="setSub('{{ $key }}')" data-sub="{{ $key }}" @class(['seg h-8! px-3!', 'seg-active' => $activeSub === $key])>{{ $label }}</button>
                            @endforeach
                        </nav>
                    @endif
                    @unless ($screenRange)
                        <span class="text-xs text-gray-500">{{ \Carbon\CarbonImmutable::parse($window['start'])->format('d.m.Y') }} – {{ \Carbon\CarbonImmutable::parse($window['end'])->format('d.m.Y') }}</span>
                        <select wire:model.live="period" aria-label="Dönem" class="{{ $select }}">
                            @foreach (\App\Services\Site\Analysis\SiteAnalysisReader::PERIODS as $days => $label)<option value="{{ $days }}">{{ $label }}</option>@endforeach
                        </select>
                    @endunless
                </div>
            @endif
        </div>
        <div class="card overflow-x-auto" data-rows="{{ $activeSub }}">
        <table class="data-table">
            @if ($activeSub === 'clusters')
                <thead><tr><th>Küme</th><th class="text-right!">Tıklama</th><th class="text-right!">Δ</th><th class="text-right!">Gösterim</th><th class="text-right!">Sıra</th><th class="text-right!">Oturum</th><th class="text-right!">Anahtar etk.</th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr data-cluster="{{ $row['cluster_id'] }}">
                            <td class="min-w-[12rem] font-medium text-gray-900 dark:text-white">{{ $row['name'] }}@if ($row['url'])<p class="text-xs font-normal text-gray-500">{{ \App\Services\Site\Analysis\SiteAnalysisReader::path($row['url']) }}</p>@endif</td>
                            <td class="text-right tabular-nums">{{ $num($row['clicks']) }}</td><td class="text-right tabular-nums">@if (($d = $delta($row['clicks'], $row['prev_clicks'])) !== '')<span class="{{ $chipClass($row['clicks'], $row['prev_clicks']) }}">{{ $d }}</span>@endif</td>
                            <td class="text-right tabular-nums">{{ $num($row['impressions']) }}</td><td class="text-right tabular-nums">{{ $dec($row['position']) }}</td>
                            <td class="text-right tabular-nums">{{ $num($row['sessions']) }}</td><td class="text-right tabular-nums">{{ $dec($row['key_events']) }}</td>
                        </tr>
                        @foreach ($row['areas'] as $area)
                            <tr class="text-[13px] text-gray-500" data-area="{{ $area['area'] }}">
                                <td class="py-1.5! pl-8!">{{ $area['area'] === '—' ? 'bölgesiz' : $area['area'] }}</td>
                                <td class="text-right tabular-nums">{{ $num($area['clicks']) }}</td><td></td><td class="text-right tabular-nums">{{ $num($area['impressions']) }}</td><td class="text-right tabular-nums">{{ $dec($area['position']) }}</td><td></td><td></td>
                            </tr>
                        @endforeach
                    @empty
                        <tr><td colspan="7" class="p-0!"><x-operator.cluster-readiness :asset-id="$this->websiteId" what="Kümeler" class="px-4 py-3.5" /></td></tr>
                    @endforelse
                </tbody>
            @elseif ($activeSub === 'targets')
                <thead><tr><th>Sorgu</th><th>Bölge</th><th class="text-right!">Tıklama</th><th class="text-right!">Gösterim</th><th class="text-right!">Sıra</th><th>URL</th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr data-target-query>
                            <td class="min-w-[12rem] font-medium text-gray-900 dark:text-white">{{ $row['query'] }}</td>
                            <td>{{ $row['area'] === '—' ? 'bölgesiz' : $row['area'] }}</td>
                            <td class="text-right tabular-nums">{{ $num($row['clicks']) }}</td><td class="text-right tabular-nums">{{ $num($row['impressions']) }}</td><td class="text-right tabular-nums">{{ $dec($row['position']) }}</td>
                            <td class="text-gray-500">{{ $row['url'] ? \App\Services\Site\Analysis\SiteAnalysisReader::path($row['url']) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-[13px] text-gray-500">Hedef sorgu yok.</td></tr>
                    @endforelse
                </tbody>
            @elseif ($activeSub === 'pages')
                <thead><tr><th>Sayfa</th><th class="text-right!">Tıklama</th><th class="text-right!">Δ</th><th class="text-right!">Gösterim</th><th class="text-right!">Sıra</th><th class="text-right!">Oturum</th><th class="text-right!">Anahtar etk.</th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td class="min-w-[12rem]"><a href="{{ str_contains($row['url'], '://') ? $row['url'] : '#' }}" target="_blank" rel="noopener noreferrer" class="text-gray-900 hover:underline dark:text-white">{{ \Illuminate\Support\Str::limit($row['path'], 80) }}</a></td>
                            <td class="text-right tabular-nums">{{ $num($row['clicks']) }}</td><td class="text-right tabular-nums">@if (($d = $delta($row['clicks'], $row['prev_clicks'])) !== '')<span class="{{ $chipClass($row['clicks'], $row['prev_clicks']) }}">{{ $d }}</span>@endif</td>
                            <td class="text-right tabular-nums">{{ $num($row['impressions']) }}</td><td class="text-right tabular-nums">{{ $dec($row['position']) }}</td>
                            <td class="text-right tabular-nums">{{ $num($row['sessions']) }}</td><td class="text-right tabular-nums">{{ $dec($row['key_events']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-[13px] text-gray-500">Veri yok.</td></tr>
                    @endforelse
                </tbody>
            @elseif ($activeSub === 'queries')
                <thead><tr><th>Sorgu</th><th class="text-right!">Tıklama</th><th class="text-right!">Δ</th><th class="text-right!">Gösterim</th><th class="text-right!">Sıra</th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td class="min-w-[12rem] text-gray-900 dark:text-white">{{ $row['query'] }}</td>
                            <td class="text-right tabular-nums">{{ $num($row['clicks']) }}</td><td class="text-right tabular-nums">@if (($d = $delta($row['clicks'], $row['prev_clicks'])) !== '')<span class="{{ $chipClass($row['clicks'], $row['prev_clicks']) }}">{{ $d }}</span>@endif</td>
                            <td class="text-right tabular-nums">{{ $num($row['impressions']) }}</td><td class="text-right tabular-nums">{{ $dec($row['position']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-[13px] text-gray-500">Veri yok.</td></tr>
                    @endforelse
                </tbody>
            @else
                <thead><tr><th>Açılış sayfası</th><th>Kaynak / ortam</th><th class="text-right!">Oturum</th><th class="text-right!">Anahtar etkinlik</th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td class="min-w-[12rem] text-gray-900 dark:text-white">{{ \Illuminate\Support\Str::limit($row['landing'], 80) }}</td>
                            <td>{{ $row['source'] }} / {{ $row['medium'] }}</td>
                            <td class="text-right tabular-nums">{{ $num($row['sessions']) }}</td><td class="text-right tabular-nums">{{ $dec($row['key_events']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-[13px] text-gray-500">Anahtar etkinlik yok.</td></tr>
                    @endforelse
                </tbody>
            @endif
        </table>
        </div>
        @if ($rows->hasPages())<div class="mt-3">{{ $rows->links() }}</div>@endif
    </section>
</div>
