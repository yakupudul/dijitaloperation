@php
    $glance = $data['glance'] ?? [];
    $perf = $data['performance'] ?? [];
    $devices = $perf['devices'] ?? [];
    $maxDeviceClicks = max(1, (int) collect($devices)->max('clicks'));
    $brandNonbrand = $perf['brand_nonbrand'] ?? [];
    $diagnosis = $perf['diagnosis'] ?? [];
    $num = fn (float|int|null $value): string => number_format((float) ($value ?? 0), 0, ',', '.');
    $avgPosition = isset($glance['clicks']['avg_position']) ? number_format($glance['clicks']['avg_position'], 1, ',', '.') : '—';
    $cells = [
        ['Tıklama', $glanceCell($glance['clicks'] ?? null), $spark('clicks')],
        ['Gösterim', $glanceCell($glance['impressions'] ?? null), $spark('impressions')],
        ['Tıklama oranı', $glanceCell($glance['ctr'] ?? null, false), $spark('ctr')],
    ];
@endphp

<div class="space-y-10">
    {{-- 1. The four search numbers of the period. --}}
    <section data-gsc-glance>
        <div class="metric-strip grid-cols-2 lg:grid-cols-4">
            @foreach ($cells as [$label, $cell, $points])
                <x-ta.metric-card :label="$label" :value="$cell['value']" :delta="$cell['delta']" :tone="$cell['tone']" :hint="$cell['hint']" :muted="$cell['muted']" :spark="$points" :title="$cell['note']" />
            @endforeach
            <x-ta.metric-card label="Ortalama sıra" :value="$avgPosition" :muted="$avgPosition === '—'" hint="Gösterime göre ağırlıklı" :spark="$spark('position')" spark-tone="neutral"
                title="Gösterimlere göre ağırlıklı ortalama; tek bir genel sıra değil. Küçük sayı daha iyi." />
        </div>
    </section>

    {{-- 2. Day by day. --}}
    <section>
        <div class="section-head">
            <h2 class="section-title" title="{{ $data['performance_trend']['note'] ?? '' }}">Günlük trend</h2>
        </div>
        <div class="card">
            <div class="flex gap-1.5 overflow-x-auto px-4 pt-4 no-scrollbar" role="group" aria-label="Ölçü">
                @foreach ($metricTabs as $key => $label)
                    <button type="button" wire:click="setMetric('{{ $key }}')" @class(['seg h-8! px-3!', 'seg-active' => $metric === $key])>{{ $label }}</button>
                @endforeach
            </div>
            @if ($hasTrend)
                <div class="px-2 pt-3 pb-1 sm:px-3">
                    <div data-chart='@json($chart)' aria-label="Search performance trend chart" class="min-h-[240px]"></div>
                </div>
                @if ($metric === 'position')
                    <p class="border-t border-gray-200 px-4 py-2.5 text-xs text-gray-500 dark:border-gray-800">Ortalama sıra gösterimlere göre ağırlıklıdır; tek bir genel sıra değildir.</p>
                @endif
            @else
                <x-ta.empty-state icon="none" title="Bu dönem için veri yok" message="Search Console verisi gelince günlük çizgi burada görünür." />
            @endif
        </div>
    </section>

    {{-- 3. Where the clicks come from. --}}
    <div class="grid gap-10 lg:grid-cols-2 lg:gap-6">
        <section class="min-w-0">
            <div class="section-head"><h2 class="section-title">Cihaz</h2></div>
            <ul class="card">
                @forelse ($devices as $row)
                    <li class="card-row flex-col items-stretch gap-1.5 py-3">
                        <div class="flex items-baseline justify-between gap-3 text-sm">
                            <span class="text-gray-900 dark:text-white">{{ $say($row['device']) }}</span>
                            <span class="tabular-nums text-gray-900 dark:text-white">{{ $num($row['clicks']) }} <span class="text-[13px] text-gray-500">tık</span></span>
                        </div>
                        <div class="h-1 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5">
                            <div class="h-full rounded-full bg-second" style="width: {{ min(100, round(($row['clicks'] / $maxDeviceClicks) * 100)) }}%"></div>
                        </div>
                        <p class="text-xs tabular-nums text-gray-500">{{ $num($row['impressions']) }} gösterim, %{{ $dec($row['ctr']) }} tıklama oranı, ort. sıra {{ $dec($row['position']) }}</p>
                    </li>
                @empty
                    <li><x-ta.empty-state icon="none" size="sm" title="Cihaz verisi yok" /></li>
                @endforelse
            </ul>
        </section>

        <section class="min-w-0">
            <div class="section-head"><h2 class="section-title">Ülke</h2></div>
            <div class="card overflow-x-auto">
                @if (($perf['countries'] ?? []) === [])
                    <x-ta.empty-state icon="none" size="sm" title="Ülke verisi yok" />
                @else
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Ülke</th>
                                <th class="text-right!">Tıklama</th>
                                <th class="text-right!">Gösterim</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($perf['countries'] as $row)
                                <tr>
                                    <td class="font-medium text-gray-900 dark:text-white">{{ $row['country'] }}</td>
                                    <td class="text-right tabular-nums">{{ $num($row['clicks']) }}</td>
                                    <td class="text-right tabular-nums text-gray-600 dark:text-gray-300">{{ $num($row['impressions']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </section>
    </div>

    {{-- 4. Searches with the brand name and without it. --}}
    @if (isset($brandNonbrand['brand']) || isset($brandNonbrand['nonbrand']))
        <section>
            <div class="section-head">
                <h2 class="section-title" title="{{ $brandNonbrand['note'] ?? '' }}">Marka adıyla ve marka dışı aramalar</h2>
                <span class="text-[13px] text-gray-500">{{ $say($brandNonbrand['source'] ?? 'Derived') }}</span>
            </div>
            <div class="card overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Arama</th>
                            <th class="text-right!">Tıklama</th>
                            <th class="text-right!">Gösterim</th>
                            <th class="hidden text-right! sm:table-cell">Tıklama oranı</th>
                            <th class="hidden text-right! sm:table-cell">Ort. sıra</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (['brand' => 'Marka adıyla', 'nonbrand' => 'Marka dışı'] as $key => $label)
                            @php $row = $brandNonbrand[$key] ?? []; @endphp
                            <tr>
                                <td class="font-medium text-gray-900 dark:text-white">{{ $label }}</td>
                                <td class="text-right tabular-nums">{{ $num($row['clicks'] ?? 0) }}</td>
                                <td class="text-right tabular-nums">{{ $num($row['impressions'] ?? 0) }}</td>
                                <td class="hidden text-right tabular-nums sm:table-cell">%{{ $dec($row['ctr'] ?? '—') }}</td>
                                <td class="hidden text-right tabular-nums sm:table-cell">{{ $dec($row['position'] ?? '—') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @elseif (! empty($brandNonbrand['note']))
        <p class="text-[13px] text-gray-500">Marka adıyla ve marka dışı aramalar ayrılamadı: veri yok.</p>
    @endif

    {{-- 5. What the numbers say together. --}}
    @if (! empty($diagnosis['interpretation']) && ! str_starts_with((string) $diagnosis['interpretation'], 'Unavailable'))
        <section>
            <div class="section-head"><h2 class="section-title">Yorum</h2></div>
            <div class="card">
                <div class="card-row items-start">
                    <span class="row-icon border-transparent bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.5"/></svg></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-gray-800 dark:text-gray-200">{{ $diagnosis['interpretation'] }}</p>
                        @if (! empty($diagnosis['disclaimer']))
                            <p class="mt-1 text-xs text-gray-500">{{ $diagnosis['disclaimer'] }}</p>
                        @endif
                    </div>
                    <x-ta.pill tone="info" class="hidden! sm:inline-flex!">{{ $say($diagnosis['source'] ?? 'Derived') }}</x-ta.pill>
                </div>
            </div>
        </section>
    @endif
</div>
