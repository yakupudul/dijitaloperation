@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $num = fn ($value) => number_format((float) $value, 0, ',', '.');
    $dec = fn ($value) => number_format((float) $value, 1, ',', '.');
    $pct = function (int|float $current, int|float $previous): ?int {
        return $previous > 0 ? (int) round(($current - $previous) / $previous * 100) : null;
    };
    $c = $trend['current'];
    $p = $trend['previous'];
    $pageUrl = fn (array $query) => route('operator.website', ['assetId' => $this->assetId, 'tab' => 'sayfalar', ...$query]);
    $stateLabels = ['iyi' => 'İyi', 'dususte' => 'Düşüşte', 'sorunlu' => 'Sorunlu'];
    $stateClass = ['iyi' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300', 'dususte' => 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300', 'sorunlu' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'];
@endphp
<div class="space-y-4" data-overview>
    @foreach ($banners as $bannerState => $group)
        <section class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200" data-data-status-banner="{{ $bannerState }}">
            <span>{{ __('data_status.banner.'.$bannerState.'_title', ['sources' => $group->map(fn ($status) => $status->sourceLabel())->implode(' / ')], 'tr') }}</span>
            @if ($bannerState === 'not_bound')
                <a href="{{ route('operator.asset.sources', ['assetId' => $this->assetId]) }}" wire:navigate class="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white">{{ __('data_status.actions.bind', [], 'tr') }}</a>
            @endif
        </section>
    @endforeach

    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
        <span>{{ \Carbon\CarbonImmutable::parse($trend['start'])->format('d.m.Y') }} – {{ \Carbon\CarbonImmutable::parse($trend['end'])->format('d.m.Y') }} · önceki {{ $periodDays }} günle karşılaştırma</span>
        <select wire:model.live="period" aria-label="Dönem" class="rounded-lg border-gray-300 py-1 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-white">
            @foreach (\App\Services\Site\Analysis\SitePagesReader::PERIODS as $days => $label)<option value="{{ $days }}">{{ $label }}</option>@endforeach
        </select>
    </div>

    <section class="grid grid-cols-2 gap-3 lg:grid-cols-4" data-numbers>
        @foreach ([
            ['Organik tıklama', $c['clicks'], $p['clicks'], $num, $trend['has_gsc'], 'Search Console bağlı değil'],
            ['Gösterim', $c['impressions'], $p['impressions'], $num, $trend['has_gsc'], 'Search Console bağlı değil'],
            ['Oturum', $c['sessions'], $p['sessions'], $num, $trend['has_ga4'], 'GA4 bağlı değil'],
            ['Anahtar etkinlik', $c['key_events'], $p['key_events'], $dec, $trend['has_ga4'], 'GA4 bağlı değil'],
        ] as [$label, $value, $previous, $format, $bound, $missing])
            @php($delta = $pct($value, $previous))
            <div class="{{ $card }}">
                <p class="text-xs text-gray-500">{{ $label }}</p>
                <p class="text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $bound ? $format($value) : '—' }}</p>
                <p @class(['text-xs', 'text-rose-600' => $delta !== null && $delta < 0, 'text-emerald-600' => $delta !== null && $delta > 0, 'text-gray-500' => $delta === null || $delta === 0])>
                    @if (! $bound){{ $missing }}@elseif ($delta === null)önceki dönem verisi yok@else{{ $delta > 0 ? '▲ +' : ($delta < 0 ? '▼ ' : '') }}{{ $delta }}% · önceki {{ $format($previous) }}@endif
                </p>
            </div>
        @endforeach
    </section>

    @if ($trend['has_gsc'] || $trend['has_ga4'])
        <section class="grid gap-3 lg:grid-cols-2" data-trend>
            @if ($trend['has_gsc'])
                <div class="{{ $card }}">@include('livewire.operator.website.v2.partials.sparkline', ['points' => array_map(fn ($d) => ['date' => $d['date'], 'value' => $d['clicks']], $trend['series']), 'label' => 'Organik tıklama', 'format' => $num])</div>
            @endif
            @if ($trend['has_ga4'])
                <div class="{{ $card }}">@include('livewire.operator.website.v2.partials.sparkline', ['points' => array_map(fn ($d) => ['date' => $d['date'], 'value' => $d['sessions']], $trend['series']), 'label' => 'Oturum', 'format' => $num])</div>
            @endif
        </section>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="{{ $card }}" data-service-pages>
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold">Ana hizmet sayfaları</h2>
                <a href="{{ $pageUrl(['filtre' => 'ana']) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">Tümü →</a>
            </div>
            <div class="mb-3 flex flex-wrap gap-2 text-xs">
                @foreach (['iyi', 'dususte', 'sorunlu'] as $state)
                    <span class="rounded-full px-2.5 py-1 font-medium {{ $stateClass[$state] }}" data-service-state="{{ $state }}">{{ $stateLabels[$state] }} {{ $services[$state] }}</span>
                @endforeach
            </div>
            <ul class="divide-y divide-gray-100 text-xs dark:divide-gray-800">
                @forelse (array_slice($services['rows'], 0, 8) as $item)
                    @php($row = $item['row'])
                    <li class="flex items-center justify-between gap-3 py-2" wire:key="svc-{{ md5($row['path']) }}">
                        <a href="{{ $pageUrl(['sayfa' => $row['path']]) }}" wire:navigate class="min-w-0 hover:underline">
                            <span class="block truncate font-medium text-gray-800 dark:text-white/90">{{ $row['services'][0] ?? $row['clusters'][0] ?? $row['title'] ?? $row['path'] }}</span>
                            <span class="block truncate text-gray-400">{{ $row['path'] }}</span>
                        </a>
                        <span class="flex shrink-0 items-center gap-2">
                            <span class="tabular-nums text-gray-500">{{ $num($row['clicks']) }} tık.@if ($row['delta'] !== null) · {{ $row['delta'] > 0 ? '+' : '' }}{{ $row['delta'] }}%@endif</span>
                            <span class="rounded-full px-2 py-0.5 font-medium {{ $stateClass[$item['state']] }}">{{ $stateLabels[$item['state']] }}</span>
                        </span>
                    </li>
                @empty
                    <li class="py-2 text-gray-500">Hizmete bağlı sayfa yok. Sorgular › Kümeler &amp; Sayfalar'dan eşleştir.</li>
                @endforelse
            </ul>
        </section>

        <section class="{{ $card }}" data-top-suggestions>
            <div class="mb-2 flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold">Açık işler <span class="font-normal text-gray-500">{{ $num($openCount) }}</span></h2>
                <button type="button" wire:click="$parent.setSub('oneriler')" class="text-xs font-medium text-brand-600 hover:underline">Tümü →</button>
            </div>
            <ul class="divide-y divide-gray-100 text-xs dark:divide-gray-800">
                @forelse ($top as $suggestion)
                    <li class="flex items-center justify-between gap-3 py-2" wire:key="top-{{ $suggestion->id }}">
                        <span class="min-w-0"><span class="font-medium">{{ $suggestion->title }}</span> <span class="text-gray-500">· {{ \App\Services\Site\SiteSuggestionTypes::label((string) $suggestion->action_type) }}@if ($suggestion->page) · {{ $suggestion->page->path }}@endif</span></span>
                        <button type="button" wire:click="$parent.setSub('oneriler')" class="shrink-0 rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700">Aç</button>
                    </li>
                @empty
                    <li class="py-2 text-gray-500">Açık öneri yok.</li>
                @endforelse
            </ul>
        </section>
    </div>
</div>
