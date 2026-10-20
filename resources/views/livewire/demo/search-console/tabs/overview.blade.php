@php
    $glance = $data['glance'] ?? [];
    $momentum = $data['search_momentum'] ?? [];
    $discoverability = $data['discoverability'] ?? [];
    $maxStage = max(1, (int) collect($discoverability['stages'] ?? [])->max('count'));
    $attention = $data['needs_attention'] ?? [];
    $opportunities = $data['opportunities'] ?? [];
    $outcomes = $data['recent_outcomes'] ?? [];
    $cells = [
        ['Tıklama', $glanceCell($glance['clicks'] ?? null), $spark('clicks')],
        ['Gösterim', $glanceCell($glance['impressions'] ?? null), $spark('impressions')],
        ['Tıklama oranı', $glanceCell($glance['ctr'] ?? null, false), $spark('ctr')],
        ['Dikkat isteyen', $glanceCell($glance['search_attention'] ?? null, false), []],
    ];
    $momentumBuckets = ['growing' => 'Büyüyen', 'new' => 'Yeni', 'declining' => 'Düşen', 'lost' => 'Kaybolan', 'ctr_review' => 'Tıklama oranı düşük', 'striking_distance' => 'İlk sayfaya yakın'];
    $hasMomentum = collect(array_keys($momentumBuckets))->contains(fn (string $key): bool => array_key_exists($key, $momentum));
@endphp

<div class="space-y-10">
    {{-- 1. What needs a look, when anything does. --}}
    @if ($attention !== [])
        <section data-gsc-attention>
            <div class="section-head">
                <h2 class="section-title">Dikkat isteyen <span class="section-count">{{ count($attention) }}</span></h2>
            </div>
            <ul class="card">
                @foreach ($attention as $i => $item)
                    @php $tone = $toneOf($item['severity'] ?? null); @endphp
                    @if ($i === 5)
                        <li>
                            <details class="group">
                                <summary class="card-row cursor-pointer list-none text-[13px] text-gray-500 hover:bg-gray-50 group-open:hidden dark:hover:bg-white/[0.02]">{{ count($attention) - 5 }} konu daha göster</summary>
                                <ul class="border-t border-gray-200 dark:border-gray-800">
                    @endif
                    <li class="card-row flex-wrap sm:flex-nowrap" wire:key="gsc-att-{{ $item['id'] }}">
                        <span @class(['row-icon', 'border-transparent bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-400' => $tone === 'critical', 'border-transparent bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400' => $tone === 'look']) aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5M12 16.5v.5"/><path d="M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $item['title'] }}</p>
                            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400" title="{{ $item['scope'] ?? '' }}">{{ $item['metric'] }}</p>
                        </div>
                        <x-ta.pill :tone="$tone" class="hidden! sm:inline-flex!">{{ $say($item['severity'] ?? null) }}</x-ta.pill>
                        <button type="button" wire:click="openAttention('{{ $item['id'] }}')" class="btn w-full sm:w-auto">{{ $sayAction($item['action'] ?? 'Inspect') }}</button>
                    </li>
                    @if ($i >= 5 && $loop->last)
                                </ul>
                            </details>
                        </li>
                    @endif
                @endforeach
            </ul>
        </section>
    @endif

    {{-- 2. Is search traffic up or down: four numbers. --}}
    <section data-gsc-glance>
        <div class="metric-strip grid-cols-2 lg:grid-cols-4">
            @foreach ($cells as [$label, $cell, $points])
                <x-ta.metric-card :label="$label" :value="$cell['value']" :delta="$cell['delta']" :tone="$cell['tone']" :hint="$cell['hint']" :muted="$cell['muted']" :spark="$points" :title="$cell['note']" />
            @endforeach
        </div>
    </section>

    {{-- 3. The trend behind the numbers. --}}
    <section>
        <div class="section-head">
            <h2 class="section-title">Arama trendi</h2>
            <button type="button" wire:click="setTab('performance')" class="ml-auto link-neutral text-[13px]">Ayrıntı</button>
        </div>
        <div class="card">
            <div class="flex gap-1 overflow-x-auto border-b border-gray-200 px-3 no-scrollbar dark:border-gray-800" role="group" aria-label="Ölçü">
                @foreach ($metricTabs as $key => $label)
                    <button type="button" wire:click="setMetric('{{ $key }}')" @class(['tab', 'tab-active' => $metric === $key])>{{ $label }}</button>
                @endforeach
            </div>
            @if ($hasTrend)
                <div class="px-2 pt-3 pb-1 sm:px-3">
                    <div data-chart='@json($chart)' aria-label="Search performance trend" class="min-h-[220px]"></div>
                </div>
            @else
                <x-ta.empty-state icon="none" title="Bu dönem için veri yok" message="Search Console verisi gelince günlük çizgi burada görünür." />
            @endif
        </div>
    </section>

    {{-- 4. Which pages bring the clicks. --}}
    <section>
        <div class="section-head">
            <h2 class="section-title">Sayfalar <span class="section-count">{{ count($data['page_pulse'] ?? []) }}</span></h2>
            <button type="button" wire:click="setTab('pages')" class="ml-auto link-neutral text-[13px]">Tümü</button>
        </div>
        <div class="card overflow-x-auto">
            @if (($data['page_pulse'] ?? []) === [])
                <x-ta.empty-state icon="none" title="Sayfa verisi yok" message="Bu dönemde sayfa bazında tıklama gelmedi." />
            @else
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Sayfa</th>
                            <th class="text-right!">Tıklama</th>
                            <th class="hidden sm:table-cell">Gidiş</th>
                            <th class="hidden text-right! md:table-cell" title="Sayfanın göründüğü arama kümesi">Küme</th>
                            <th class="hidden lg:table-cell">Site uyarısı</th>
                            <th><span class="sr-only">İncele</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['page_pulse'] ?? [] as $row)
                            <tr wire:key="gsc-pulse-{{ $row['id'] ?? $row['path'] }}">
                                <td class="max-w-0 w-[40%]">
                                    <p class="truncate font-medium text-gray-900 dark:text-white" title="{{ $row['path'] }}">{{ $row['path'] }}</p>
                                    <p class="truncate text-[13px] text-gray-500">{{ $row['title'] ?? '' }}</p>
                                </td>
                                <td class="text-right tabular-nums text-gray-900 dark:text-white">{{ number_format($row['clicks'], 0, ',', '.') }}</td>
                                <td class="hidden whitespace-nowrap sm:table-cell">
                                    <x-ta.pill :tone="match ($row['state'] ?? '') { 'Growing' => 'ok', 'Review' => 'look', default => 'info' }">{{ $say($row['trend'] ?? $row['state'] ?? null) }}</x-ta.pill>
                                    @if (isset($row['trend_pct']))
                                        <span class="ml-1 text-[13px] tabular-nums text-gray-500">{{ $row['trend_pct'] > 0 ? '+' : '' }}{{ $row['trend_pct'] }}%</span>
                                    @endif
                                </td>
                                <td class="hidden text-right tabular-nums text-gray-600 md:table-cell dark:text-gray-300">{{ $row['cluster_count'] ?? 0 }}</td>
                                <td class="hidden text-[13px] text-gray-500 lg:table-cell">{{ $row['website_attention'] ?? '—' }}</td>
                                <td class="text-right"><button type="button" wire:click="openPage('{{ $row['id'] ?? $row['path'] }}')" class="btn-quiet h-7! px-2!">İncele</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </section>

    {{-- 5. How the searches move, and how far the site's pages get. --}}
    @if ($hasMomentum || ($discoverability['stages'] ?? []) !== [])
        <div class="grid gap-10 lg:grid-cols-2 lg:gap-6">
            <section class="min-w-0">
                <div class="section-head">
                    <h2 class="section-title" title="{{ $momentum['note'] ?? '' }}">Arama hareketi</h2>
                    <button type="button" wire:click="setDemandSub('momentum')" class="ml-auto link-neutral text-[13px]">Aramalar</button>
                </div>
                <ul class="card">
                    @foreach ($momentumBuckets as $key => $label)
                        <li class="card-row py-3">
                            <span @class(['h-1.5 w-1.5 shrink-0 rounded-full', 'bg-success-500' => in_array($key, ['growing', 'new'], true), 'bg-error-500' => in_array($key, ['declining', 'lost'], true), 'bg-warning-500' => in_array($key, ['ctr_review', 'striking_distance'], true)]) aria-hidden="true"></span>
                            <span class="min-w-0 flex-1 text-sm text-gray-700 dark:text-gray-300">{{ $label }}</span>
                            <span class="text-sm font-medium tabular-nums text-gray-900 dark:text-white">{{ $momentum[$key] ?? '—' }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="min-w-0">
                <div class="section-head">
                    <h2 class="section-title" title="{{ $discoverability['subtitle'] ?? '' }}">Google'a ulaşan sayfalar</h2>
                    <button type="button" wire:click="setTab('indexing')" class="ml-auto link-neutral text-[13px]">Dizin</button>
                </div>
                <ul class="card" title="{{ $discoverability['note'] ?? '' }}">
                    @forelse ($discoverability['stages'] ?? [] as $stage)
                        <li class="card-row flex-col items-stretch gap-1.5 py-3">
                            <div class="flex items-baseline justify-between gap-3 text-sm">
                                <span class="text-gray-700 dark:text-gray-300">{{ strtr($stage['stage'], ['Website URLs' => 'Sitedeki sayfa', 'Sitemap discovered' => 'Site haritasında', 'Index observed' => 'Dizinde görülen', 'Impressions' => 'Gösterim alan', 'Clicks' => 'Tıklama alan', 'GA4 mapped actions' => 'Analytics eylemi olan']) }}</span>
                                <span class="font-medium tabular-nums text-gray-900 dark:text-white" title="{{ $stage['provenance'] }}">{{ number_format($stage['count'], 0, ',', '.') }}</span>
                            </div>
                            <div class="h-1 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5">
                                <div class="h-full rounded-full bg-gray-800 dark:bg-gray-300" style="width: {{ min(100, round(($stage['count'] / $maxStage) * 100)) }}%"></div>
                            </div>
                        </li>
                    @empty
                        <li><x-ta.empty-state icon="none" size="sm" title="Veri yok" message="Search Console sitenin tüm dizin sayılarını vermiyor." /></li>
                    @endforelse
                </ul>
            </section>
        </div>
    @endif

    {{-- 6. What to do next, and what earlier work changed. --}}
    @if ($opportunities !== [] || $outcomes !== [])
        <div class="grid gap-10 lg:grid-cols-2 lg:gap-6">
            <section class="min-w-0">
                <div class="section-head">
                    <h2 class="section-title">Fırsatlar <span class="section-count">{{ count($opportunities) }}</span></h2>
                </div>
                <ul class="card">
                    @forelse ($opportunities as $i => $opp)
                        @if ($i === 4)
                            <li>
                                <details class="group">
                                    <summary class="card-row cursor-pointer list-none text-[13px] text-gray-500 hover:bg-gray-50 group-open:hidden dark:hover:bg-white/[0.02]">{{ count($opportunities) - 4 }} fırsat daha göster</summary>
                                    <ul class="border-t border-gray-200 dark:border-gray-800">
                        @endif
                        <li class="card-row">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $opp['title'] }}</p>
                                <p class="mt-0.5 text-[13px] text-gray-500">{{ $opp['metric'] }}</p>
                            </div>
                            <x-ta.pill :tone="$toneOf($opp['priority'] ?? null)" class="hidden! sm:inline-flex!" title="Öncelik">{{ $say($opp['priority'] ?? null) }}</x-ta.pill>
                            @if (! empty($opp['tab']))
                                <button type="button" wire:click="setTab('{{ $opp['tab'] }}')" class="btn h-7! px-2.5! text-xs!">{{ $sayAction($opp['cta'] ?? 'Open') }}</button>
                            @endif
                        </li>
                        @if ($i >= 4 && $loop->last)
                                    </ul>
                                </details>
                            </li>
                        @endif
                    @empty
                        <li><x-ta.empty-state icon="none" size="sm" title="Şu an fırsat yok" /></li>
                    @endforelse
                </ul>
            </section>

            <section class="min-w-0">
                <div class="section-head">
                    <h2 class="section-title">Son sonuçlar</h2>
                    <button type="button" wire:click="setOps('outcomes')" class="ml-auto link-neutral text-[13px]">Operasyon</button>
                </div>
                <ul class="card">
                    @forelse ($outcomes as $o)
                        <li class="card-row flex-wrap sm:flex-nowrap">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $o['title'] }}</p>
                                @if (! empty($o['note']))
                                    <p class="mt-0.5 text-[13px] text-gray-500">{{ $o['note'] }}</p>
                                @endif
                            </div>
                            <x-ta.pill :tone="$toneOf($o['state'] ?? null)">{{ $say($o['state'] ?? null) }}</x-ta.pill>
                        </li>
                    @empty
                        <li><x-ta.empty-state icon="none" size="sm" title="Henüz sonuç yok" /></li>
                    @endforelse
                </ul>
            </section>
        </div>
    @endif

    @include('livewire.demo.search-console.tabs.relationships')
</div>
