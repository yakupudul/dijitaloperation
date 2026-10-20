@php
    $card = 'min-w-0';
    $input = 'rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-white';
    $num = fn ($value) => $value === null ? '—' : number_format((float) $value, 0, ',', '.');
    $dec = fn ($value) => $value === null ? '—' : number_format((float) $value, 1, ',', '.');
    $delta = function (int|float $current, int|float $previous): string {
        if ($previous == 0) {
            return $current > 0 ? 'yeni' : '';
        }
        $pct = round(($current - $previous) / $previous * 100);

        return ($pct > 0 ? '+' : '').$pct.'%';
    };
    $c = $totals['current'];
    $p = $totals['previous'];
@endphp
<div class="space-y-10 text-sm dark:text-gray-200" data-analysis-tab>
    @if ($showNav || ! $screenRange)
    <header class="flex flex-wrap items-center justify-between gap-2">
        @if ($showNav)
            <nav class="flex flex-wrap gap-1" aria-label="Analiz sekmeleri">
                @foreach (\App\Livewire\Operator\Website\V2\AnalysisTab::SUBTABS as $key => $label)
                    <button type="button" wire:click="setSub('{{ $key }}')" data-sub="{{ $key }}" @class(['rounded-lg px-3 py-1.5 text-xs font-semibold', 'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' => $activeSub === $key, 'text-gray-500 hover:text-gray-900 dark:text-gray-400' => $activeSub !== $key])>{{ $label }}</button>
                @endforeach
            </nav>
        @else
            <span></span>
        @endif
        @unless ($screenRange)
            <div class="flex items-center gap-2 text-xs text-gray-500">
                <span>{{ \Carbon\CarbonImmutable::parse($window['start'])->format('d.m.Y') }} – {{ \Carbon\CarbonImmutable::parse($window['end'])->format('d.m.Y') }}</span>
                <select wire:model.live="period" aria-label="Dönem" class="{{ $input }} py-1">
                    @foreach (\App\Services\Site\Analysis\SiteAnalysisReader::PERIODS as $days => $label)<option value="{{ $days }}">{{ $label }}</option>@endforeach
                </select>
            </div>
        @endunless
    </header>
    @endif

    <section class="grid grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-3 lg:grid-cols-5" data-totals>
        @foreach ([['Tıklama', $c['clicks'], $p['clicks'], $num], ['Gösterim', $c['impressions'], $p['impressions'], $num], ['Ort. sıra', $c['position'], $p['position'], $dec], ['Oturum', $c['sessions'], $p['sessions'], $num], ['Anahtar etkinlik', $c['key_events'], $p['key_events'], $dec]] as [$label, $value, $previous, $format])
            <x-ta.metric-card :label="$label" :value="$format($value)" :hint="'önceki '.$format($previous)" />
        @endforeach
    </section>

    @if ($window['gsc'] === [] && $window['ga4'] === [])
        <p class="text-gray-500">Search Console / GA4 bağlı değil.</p>
    @endif

    <section class="{{ $card }} overflow-x-auto" data-rows="{{ $activeSub }}">
        <table class="w-full text-left text-sm">
            @if ($activeSub === 'clusters')
                <thead class="border-b border-gray-200 text-gray-500 dark:border-gray-800"><tr><th class="font-normal py-1">Küme</th><th class="font-normal text-right">Tıklama</th><th class="font-normal text-right">Δ</th><th class="font-normal text-right">Gösterim</th><th class="font-normal text-right">Sıra</th><th class="font-normal text-right">Oturum</th><th class="font-normal text-right">Anahtar etk.</th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-t border-gray-200 dark:border-gray-800" data-cluster="{{ $row['cluster_id'] }}">
                            <td class="py-2.5 font-medium">{{ $row['name'] }}@if ($row['url'])<p class="font-normal text-gray-400">{{ \App\Services\Site\Analysis\SiteAnalysisReader::path($row['url']) }}</p>@endif</td>
                            <td class="text-right">{{ $num($row['clicks']) }}</td><td class="text-right text-gray-500">{{ $delta($row['clicks'], $row['prev_clicks']) }}</td>
                            <td class="text-right">{{ $num($row['impressions']) }}</td><td class="text-right">{{ $dec($row['position']) }}</td>
                            <td class="text-right">{{ $num($row['sessions']) }}</td><td class="text-right">{{ $dec($row['key_events']) }}</td>
                        </tr>
                        @foreach ($row['areas'] as $area)
                            <tr class="text-gray-500" data-area="{{ $area['area'] }}">
                                <td class="py-0.5 pl-4">{{ $area['area'] === '—' ? 'bölgesiz' : $area['area'] }}</td>
                                <td class="text-right">{{ $num($area['clicks']) }}</td><td></td><td class="text-right">{{ $num($area['impressions']) }}</td><td class="text-right">{{ $dec($area['position']) }}</td><td></td><td></td>
                            </tr>
                        @endforeach
                    @empty
                        <tr><td colspan="7" class="py-2"><x-operator.cluster-readiness :asset-id="$this->websiteId" what="Kümeler" /></td></tr>
                    @endforelse
                </tbody>
            @elseif ($activeSub === 'targets')
                <thead class="border-b border-gray-200 text-gray-500 dark:border-gray-800"><tr><th class="font-normal py-1">Sorgu</th><th class="font-normal">Bölge</th><th class="font-normal text-right">Tıklama</th><th class="font-normal text-right">Gösterim</th><th class="font-normal text-right">Sıra</th><th class="font-normal">URL</th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-t border-gray-200 dark:border-gray-800" data-target-query>
                            <td class="py-2.5 font-medium">{{ $row['query'] }}</td>
                            <td>{{ $row['area'] === '—' ? 'bölgesiz' : $row['area'] }}</td>
                            <td class="text-right">{{ $num($row['clicks']) }}</td><td class="text-right">{{ $num($row['impressions']) }}</td><td class="text-right">{{ $dec($row['position']) }}</td>
                            <td class="text-gray-500">{{ $row['url'] ? \App\Services\Site\Analysis\SiteAnalysisReader::path($row['url']) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-2 text-gray-500">Hedef sorgu yok.</td></tr>
                    @endforelse
                </tbody>
            @elseif ($activeSub === 'pages')
                <thead class="border-b border-gray-200 text-gray-500 dark:border-gray-800"><tr><th class="font-normal py-1">Sayfa</th><th class="font-normal text-right">Tıklama</th><th class="font-normal text-right">Δ</th><th class="font-normal text-right">Gösterim</th><th class="font-normal text-right">Sıra</th><th class="font-normal text-right">Oturum</th><th class="font-normal text-right">Anahtar etk.</th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-t border-gray-200 dark:border-gray-800">
                            <td class="py-2.5"><a href="{{ str_contains($row['url'], '://') ? $row['url'] : '#' }}" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ \Illuminate\Support\Str::limit($row['path'], 80) }}</a></td>
                            <td class="text-right">{{ $num($row['clicks']) }}</td><td class="text-right text-gray-500">{{ $delta($row['clicks'], $row['prev_clicks']) }}</td>
                            <td class="text-right">{{ $num($row['impressions']) }}</td><td class="text-right">{{ $dec($row['position']) }}</td>
                            <td class="text-right">{{ $num($row['sessions']) }}</td><td class="text-right">{{ $dec($row['key_events']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-2 text-gray-500">Veri yok.</td></tr>
                    @endforelse
                </tbody>
            @elseif ($activeSub === 'queries')
                <thead class="border-b border-gray-200 text-gray-500 dark:border-gray-800"><tr><th class="font-normal py-1">Sorgu</th><th class="font-normal text-right">Tıklama</th><th class="font-normal text-right">Δ</th><th class="font-normal text-right">Gösterim</th><th class="font-normal text-right">Sıra</th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-t border-gray-200 dark:border-gray-800">
                            <td class="py-2.5">{{ $row['query'] }}</td>
                            <td class="text-right">{{ $num($row['clicks']) }}</td><td class="text-right text-gray-500">{{ $delta($row['clicks'], $row['prev_clicks']) }}</td>
                            <td class="text-right">{{ $num($row['impressions']) }}</td><td class="text-right">{{ $dec($row['position']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-2 text-gray-500">Veri yok.</td></tr>
                    @endforelse
                </tbody>
            @else
                <thead class="border-b border-gray-200 text-gray-500 dark:border-gray-800"><tr><th class="font-normal py-1">Açılış sayfası</th><th class="font-normal">Kaynak / ortam</th><th class="font-normal text-right">Oturum</th><th class="font-normal text-right">Anahtar etkinlik</th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-t border-gray-200 dark:border-gray-800">
                            <td class="py-2.5">{{ \Illuminate\Support\Str::limit($row['landing'], 80) }}</td>
                            <td>{{ $row['source'] }} / {{ $row['medium'] }}</td>
                            <td class="text-right">{{ $num($row['sessions']) }}</td><td class="text-right">{{ $dec($row['key_events']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-2 text-gray-500">Anahtar etkinlik yok.</td></tr>
                    @endforelse
                </tbody>
            @endif
        </table>
        <div class="mt-2">{{ $rows->links() }}</div>
    </section>
</div>
