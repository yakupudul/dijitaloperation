@php
    $num = fn ($value) => number_format((float) $value, 0, ',', '.');
    $dec = fn ($value) => number_format((float) $value, 1, ',', '.');
    $pct = function (int|float $current, int|float $previous): ?int {
        return $previous > 0 ? (int) round(($current - $previous) / $previous * 100) : null;
    };
    $c = $trend['current'];
    $p = $trend['previous'];
    $pageUrl = fn (array $query) => route('operator.website', ['assetId' => $this->assetId, 'tab' => 'sayfalar', ...$query]);
    $stateLabels = ['iyi' => 'İyi', 'dususte' => 'Düşüşte', 'sorunlu' => 'Sorunlu'];
    $stateTone = ['iyi' => 'ok', 'dususte' => 'look', 'sorunlu' => 'critical'];
@endphp
<div class="space-y-10" data-overview>
    @foreach ($banners as $bannerState => $group)
        <section class="tone-look flex flex-wrap items-center justify-between gap-2 rounded-lg px-3 py-2 text-sm" data-data-status-banner="{{ $bannerState }}">
            <span>{{ __('data_status.banner.'.$bannerState.'_title', ['sources' => $group->map(fn ($status) => $status->sourceLabel())->implode(' / ')], 'tr') }}</span>
            @if ($bannerState === 'not_bound')
                <a href="{{ route('operator.asset.sources', ['assetId' => $this->assetId]) }}" wire:navigate class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600">{{ __('data_status.actions.bind', [], 'tr') }}</a>
            @endif
        </section>
    @endforeach

    {{-- Aşama 7: site score in four layers, sector median and leader, one "Hepsini onar" per problem type. --}}
    <livewire:operator.website.v2.site-health-card :asset-id="$this->assetId" lazy :key="'site-health-'.$this->assetId" />

    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
        <span>{{ \Carbon\CarbonImmutable::parse($trend['start'])->format('d.m.Y') }} – {{ \Carbon\CarbonImmutable::parse($trend['end'])->format('d.m.Y') }}, önceki {{ $periodDays }} günle karşılaştırma</span>
        <select wire:model.live="period" aria-label="Dönem" class="rounded-lg border-gray-300 py-1 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-white">
            @foreach (\App\Services\Site\Analysis\SitePagesReader::PERIODS as $days => $label)<option value="{{ $days }}">{{ $label }}</option>@endforeach
        </select>
    </div>

    @php
        $ctr = fn (array $t): ?float => $t['impressions'] > 0 ? round($t['clicks'] / $t['impressions'] * 100, 1) : null;
        $cards = [
            ['Tıklama', $c['clicks'], $p['clicks'], $num, $trend['has_gsc'], 'Search Console bağlı değil', false],
            ['Gösterim', $c['impressions'], $p['impressions'], $num, $trend['has_gsc'], 'Search Console bağlı değil', false],
            ['Tıklama oranı', $ctr($c), $ctr($p), fn ($v) => $dec($v).'%', $trend['has_gsc'], 'Search Console bağlı değil', false],
            ['Ortalama sıra', $totals['current']['position'], $totals['previous']['position'], $dec, $trend['has_gsc'], 'Search Console bağlı değil', true],
            ['Oturum', $c['sessions'], $p['sessions'], $num, $trend['has_ga4'], 'GA4 bağlı değil', false],
            ['Anahtar etkinlik', $c['key_events'], $p['key_events'], $dec, $trend['has_ga4'], 'GA4 bağlı değil', false],
        ];
    @endphp
    <section class="grid grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-3 xl:grid-cols-6" data-numbers>
        @foreach ($cards as [$label, $value, $previous, $format, $bound, $missing, $lowerIsBetter])
            @php
                $delta = $value !== null && $previous !== null ? $pct($value, $previous) : null;
                $good = $delta !== null && $delta !== 0 && (($delta > 0) xor $lowerIsBetter);
            @endphp
            <x-ta.metric-card data-number="{{ $label }}" :label="$label" :value="$bound && $value !== null ? $format($value) : '—'" :muted="! $bound || $value === null"
                :delta="$bound && $delta !== null ? ($delta > 0 ? '+' : ($delta < 0 ? '−' : '')).abs($delta).'%' : null"
                :tone="$delta === null || $delta === 0 ? 'neutral' : ($good ? 'positive' : 'negative')"
                :hint="! $bound ? $missing : ($delta === null ? 'önceki dönem verisi yok' : 'önceki '.$format($previous))" />
        @endforeach
    </section>

    @if ($trend['has_gsc'] || $trend['has_ga4'])
        <section class="grid gap-10 lg:grid-cols-2" data-trend>
            @if ($trend['has_gsc'])
                <div>@include('livewire.operator.website.v2.partials.sparkline', ['points' => array_map(fn ($d) => ['date' => $d['date'], 'value' => $d['clicks']], $trend['series']), 'label' => 'Organik tıklama', 'format' => $num])</div>
            @endif
            @if ($trend['has_ga4'])
                <div>@include('livewire.operator.website.v2.partials.sparkline', ['points' => array_map(fn ($d) => ['date' => $d['date'], 'value' => $d['sessions']], $trend['series']), 'label' => 'Oturum', 'format' => $num])</div>
            @endif
        </section>
    @endif

    @if ($bing)
        {{-- Bing Webmaster (ChatGPT search reads Bing's index): the last 4 weeks, read every morning. --}}
        <section data-bing-queries>
            <div class="mb-1 flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="section-title">Bing'de en çok görülen sorgular</h2>
                <span class="text-xs text-gray-500">son 4 hafta, {{ $num($bing['impressions']) }} gösterim, {{ $num($bing['clicks']) }} tıklama @if ($bing['error'])<span class="ml-2 text-error-600">{{ $bing['error'] }}</span>@endif</span>
            </div>
            <div class="min-w-0 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 text-gray-500 dark:border-gray-800"><tr><th class="py-2 text-left font-normal">Sorgu</th><th class="text-right font-normal">Tıklama</th><th class="text-right font-normal">Gösterim</th><th class="text-right font-normal">Sıra</th></tr></thead>
                <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                    @forelse ($bing['queries'] as $q)
                        <tr wire:key="bq-{{ md5($q['query']) }}">
                            <td class="max-w-[16rem] truncate py-2.5">{{ $q['query'] }}</td>
                            <td class="text-right tabular-nums">{{ $num($q['clicks']) }}</td>
                            <td class="text-right tabular-nums">{{ $num($q['impressions']) }}</td>
                            <td class="text-right tabular-nums">{{ $q['position'] !== null ? $dec($q['position']) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-2 text-gray-500">Bing'den henüz sorgu okunmadı.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </section>
    @endif

    @if ($trend['has_gsc'])
        <div class="grid gap-4 lg:grid-cols-2">
            <section data-top-queries>
                <div class="mb-1 flex items-center justify-between gap-2">
                    <h2 class="section-title">En çok tıklanan sorgular</h2>
                    <a href="{{ route('operator.website', ['assetId' => $this->assetId, 'tab' => 'sorgular', 'sub' => 'sorgular']) }}" wire:navigate class="link-neutral text-xs">Tümü</a>
                </div>
                <div class="min-w-0 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 text-gray-500 dark:border-gray-800"><tr><th class="py-2 text-left font-normal">Sorgu</th><th class="text-right font-normal">Tıklama</th><th class="text-right font-normal">Gösterim</th><th class="text-right font-normal">Sıra</th></tr></thead>
                    <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                        @forelse ($topQueries as $q)
                            @php($qDelta = $pct($q['clicks'], $q['prev_clicks']))
                            <tr wire:key="tq-{{ md5($q['query']) }}">
                                <td class="max-w-[16rem] truncate py-2.5">{{ $q['query'] }}</td>
                                <td class="text-right tabular-nums">{{ $num($q['clicks']) }}@if ($qDelta !== null) <span @class(['text-xs', 'text-success-600' => $qDelta > 0, 'text-error-600' => $qDelta < 0, 'text-gray-500' => $qDelta === 0])>{{ $qDelta > 0 ? '+' : '' }}{{ $qDelta }}%</span>@endif</td>
                                <td class="text-right tabular-nums">{{ $num($q['impressions']) }}</td>
                                <td class="text-right tabular-nums">{{ $q['position'] !== null ? $dec($q['position']) : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-2 text-gray-500">Bu dönemde tıklama alan sorgu yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </section>

            <section data-top-pages>
                <div class="mb-1 flex items-center justify-between gap-2">
                    <h2 class="section-title">En çok tıklanan sayfalar</h2>
                    <a href="{{ $pageUrl(['filtre' => 'tum']) }}" wire:navigate class="link-neutral text-xs">Tümü</a>
                </div>
                <div class="min-w-0 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 text-gray-500 dark:border-gray-800"><tr><th class="py-2 text-left font-normal">Sayfa</th><th class="text-right font-normal">Tıklama</th><th class="text-right font-normal">Oturum</th><th class="text-right font-normal">Sıra</th></tr></thead>
                    <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                        @forelse ($topPages as $row)
                            <tr wire:key="tp-{{ md5($row['path']) }}">
                                <td class="max-w-[16rem] py-2.5"><a href="{{ $pageUrl(['sayfa' => $row['path']]) }}" wire:navigate class="block truncate hover:underline">{{ $row['title'] ?? $row['path'] }}</a><span class="block truncate text-xs text-gray-500">{{ $row['path'] }}</span></td>
                                <td class="text-right tabular-nums">{{ $num($row['clicks']) }}@if ($row['delta'] !== null) <span @class(['text-xs', 'text-success-600' => $row['delta'] > 0, 'text-error-600' => $row['delta'] < 0, 'text-gray-500' => $row['delta'] === 0])>{{ $row['delta'] > 0 ? '+' : '' }}{{ $row['delta'] }}%</span>@endif</td>
                                <td class="text-right tabular-nums">{{ $num($row['sessions']) }}</td>
                                <td class="text-right tabular-nums">{{ $row['position'] !== null ? $dec($row['position']) : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-2 text-gray-500">Bu dönemde tıklama alan sayfa yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </section>
        </div>

        @if ($winners !== [] || $losers !== [])
            <section data-movers>
                <h2 class="mb-2 section-title" title="Önceki {{ $periodDays }} güne göre en çok tıklama kazanan ve kaybeden sayfalar">Neler değişti</h2>
                <div class="grid gap-x-10 gap-y-6 md:grid-cols-2">
                    @foreach (['Kazananlar' => $winners, 'Kaybedenler' => $losers] as $heading => $list)
                        <div>
                            <p class="mb-1 text-xs text-gray-500">{{ $heading }}</p>
                            <ul class="divide-y divide-gray-200 border-b border-gray-200 text-sm dark:divide-gray-800 dark:border-gray-800">
                                @forelse ($list as $row)
                                    <li class="flex items-center justify-between gap-3 py-1.5" wire:key="mv-{{ md5($row['path']) }}">
                                        <a href="{{ $pageUrl(['sayfa' => $row['path']]) }}" wire:navigate class="min-w-0 truncate hover:underline">{{ $row['title'] ?? $row['path'] }}</a>
                                        <span class="shrink-0 tabular-nums text-gray-500">{{ $num($row['prev_clicks']) }} → {{ $num($row['clicks']) }} <span class="{{ $row['change'] > 0 ? 'text-success-600' : 'text-error-600' }}">({{ $row['change'] > 0 ? '+' : '' }}{{ $num($row['change']) }})</span></span>
                                    </li>
                                @empty
                                    <li class="py-1.5 text-gray-500">—</li>
                                @endforelse
                            </ul>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    @endif

    <div class="grid gap-10 lg:grid-cols-2">
        <section data-service-pages>
            <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                <h2 class="section-title">Ana hizmet sayfaları</h2>
                <a href="{{ $pageUrl(['filtre' => 'ana']) }}" wire:navigate class="link-neutral text-xs">Tümü</a>
            </div>
            <div class="mb-2 flex flex-wrap gap-x-4 gap-y-1">
                @foreach (['iyi', 'dususte', 'sorunlu'] as $state)
                    <x-ta.pill :tone="$stateTone[$state]" data-service-state="{{ $state }}">{{ $stateLabels[$state] }} {{ $services[$state] }}</x-ta.pill>
                @endforeach
            </div>
            <ul class="divide-y divide-gray-200 border-b border-gray-200 text-sm dark:divide-gray-800 dark:border-gray-800">
                @forelse (array_slice($services['rows'], 0, 8) as $item)
                    @php($row = $item['row'])
                    <li class="flex items-center justify-between gap-3 py-2" wire:key="svc-{{ md5($row['path']) }}">
                        <a href="{{ $pageUrl(['sayfa' => $row['path']]) }}" wire:navigate class="min-w-0 hover:underline">
                            <span class="block truncate font-medium text-gray-800 dark:text-white/90">{{ $row['services'][0] ?? $row['clusters'][0] ?? $row['title'] ?? $row['path'] }}</span>
                            <span class="block truncate text-xs text-gray-500">{{ $row['path'] }}</span>
                        </a>
                        <span class="flex shrink-0 items-center gap-2">
                            <span class="tabular-nums text-gray-500">{{ $num($row['clicks']) }} tık.@if ($row['delta'] !== null), {{ $row['delta'] > 0 ? '+' : '' }}{{ $row['delta'] }}%@endif</span>
                            <x-ta.pill :tone="$stateTone[$item['state']]">{{ $stateLabels[$item['state']] }}</x-ta.pill>
                        </span>
                    </li>
                @empty
                    <li class="space-y-2 py-2 text-gray-500"><span class="block">Hizmet sayfası bulunamadı. Hizmet bölümündeki sayfalar (/tedavilerimiz/, /hizmetlerimiz/ …) ve kümelerle eşleşen sayfalar burada görünür.</span><x-operator.cluster-readiness :asset-id="$this->assetId" what="Küme eşleşmesi" /></li>
                @endforelse
            </ul>
        </section>

        <section data-top-suggestions>
            <div class="mb-1 flex items-center justify-between gap-2">
                <h2 class="section-title">Açık işler <span class="font-normal text-gray-500">{{ $num($openCount) }}</span></h2>
                <button type="button" wire:click="$parent.setSub('oneriler')" class="link-neutral text-xs">Tümü</button>
            </div>
            <ul class="divide-y divide-gray-200 border-b border-gray-200 text-sm dark:divide-gray-800 dark:border-gray-800">
                @forelse ($top as $suggestion)
                    <li class="flex items-center justify-between gap-3 py-2" wire:key="top-{{ $suggestion->id }}">
                        <span class="min-w-0"><span class="block font-medium text-gray-800 dark:text-white/90">{{ $suggestion->title }}</span> <span class="block truncate text-gray-500">{{ \App\Services\Site\SiteSuggestionTypes::label((string) $suggestion->action_type) }}@if ($suggestion->page), {{ $suggestion->page->path }}@endif</span></span>
                        <button type="button" wire:click="$parent.setSub('oneriler')" class="shrink-0 rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700">Aç</button>
                    </li>
                @empty
                    <li class="py-2 text-gray-500">Açık öneri yok.</li>
                @endforelse
            </ul>
        </section>
    </div>
</div>
