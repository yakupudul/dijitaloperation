@php
    $card = 'min-w-0';
    $input = 'h-8 rounded-lg border border-gray-200 bg-white px-2.5 text-[13px] text-gray-700 shadow-card focus:border-gray-300 focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
    $select = 'h-8 rounded-lg border border-gray-200 bg-white py-0 pl-2.5 pr-8 text-[13px] text-gray-700 shadow-card focus:border-gray-300 focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $num = fn ($value) => $value === null ? '—' : number_format((float) $value, 0, ',', '.');
    $dec = fn ($value) => $value === null ? '—' : number_format((float) $value, 1, ',', '.');
    $money = fn ($value) => $value === null ? '—' : number_format((float) $value, 2, ',', '.');
    $hasAds = $rows !== null && collect($rows->items())->contains(fn (array $row): bool => $row['ads_clicks'] !== null);
    $arrow = fn (string $column) => $sort === $column ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    $th = 'cursor-pointer select-none text-right! hover:text-gray-800 dark:hover:text-white';
    $health = function (array $row): array {
        if ($row['status_code'] !== null && $row['status_code'] >= 400) {
            return ['HTTP '.$row['status_code'], 'critical'];
        }
        if ($row['indexable'] === false) {
            return ['noindex', 'critical'];
        }
        if ($row['serious'] > 0) {
            return [$row['serious'].' ciddi sorun', 'critical'];
        }
        if ($row['issues'] > 0) {
            return [$row['issues'].' uyarı', 'look'];
        }
        if ($row['status_code'] !== null) {
            return [(string) $row['status_code'], 'ok'];
        }

        return ['—', 'info'];
    };
@endphp
<div class="min-w-0 space-y-8 text-sm dark:text-gray-200" data-pages-tab>
    @php $all = collect($types)->sum('count'); @endphp
    {{-- 1. The answer: how many pages of each kind the site has and how many of them get clicks. A cell opens its list. --}}
    <nav class="no-scrollbar flex gap-1.5 overflow-x-auto" aria-label="Sayfa türleri" data-page-types>
        <button type="button" wire:click="setType('')" data-type="" title="{{ $periodDays }} gün" @class(['seg', 'seg-active' => $activeType === ''])>
            Tümü <span @class(['tabular-nums text-xs', 'text-accent' => $activeType === '', 'text-gray-400 dark:text-gray-500' => $activeType !== ''])>{{ $num($all) }}</span>
        </button>
        @foreach ($types as $key => $bucket)
            @continue($bucket['count'] === 0 && $activeType !== $key)
            <button type="button" wire:click="setType('{{ $key }}')" data-type="{{ $key }}" title="{{ $num($bucket['clicked']) }} tıklama alan" @class(['seg', 'seg-active' => $activeType === $key])>
                {{ $bucket['label'] }} <span @class(['tabular-nums text-xs', 'text-accent' => $activeType === $key, 'text-gray-400 dark:text-gray-500' => $activeType !== $key])>{{ $num($bucket['count']) }}</span>
            </button>
        @endforeach
    </nav>

    {{-- 2. Filters. --}}
    <div class="flex min-w-0 flex-wrap items-center justify-between gap-2">
        @if ($grouped)
            <span class="text-[13px] text-gray-500 dark:text-gray-400">Her türün en çok tıklanan sayfaları; tamamı için türe tıkla.</span>
        @else
            <nav class="no-scrollbar flex min-w-0 max-w-full gap-1.5 overflow-x-auto" aria-label="Sayfa filtreleri">
                @foreach (\App\Services\Site\Analysis\SitePagesReader::FILTERS as $key => $label)
                    <button type="button" wire:click="setFilter('{{ $key }}')" data-filter="{{ $key }}" @class(['seg h-8! px-3!', 'seg-active' => $activeFilter === $key])>{{ $label }}@if ($activeType === '') <span class="text-xs font-normal tabular-nums opacity-60">{{ $num($counts[$key] ?? 0) }}</span>@endif</button>
                @endforeach
            </nav>
        @endif
        <div class="flex w-full items-center gap-2 sm:w-auto">
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="URL, başlık veya hizmet ara" aria-label="Ara" class="{{ $input }} min-w-0 flex-1 sm:w-60 sm:flex-none">
            @unless ($screenRange)
                <select wire:model.live="period" aria-label="Dönem" class="{{ $select }}">
                    @foreach (\App\Services\Site\Analysis\SitePagesReader::PERIODS as $days => $label)<option value="{{ $days }}">{{ $label }}</option>@endforeach
                </select>
            @endunless
        </div>
    </div>

    {{-- 3. The pages: by kind (top rows of each), or one kind / filter in full. --}}
    @if ($grouped)
        @php $shown = collect($types)->filter(fn (array $bucket): bool => $bucket['count'] > 0); @endphp
        @forelse ($shown as $key => $bucket)
            <section class="{{ $card }}" data-type-section="{{ $key }}">
                <div class="section-head justify-between">
                    <div class="flex min-w-0 flex-wrap items-baseline gap-x-3">
                        <h3 class="section-title">{{ $bucket['label'] }}</h3>
                        <span class="text-[13px] text-gray-500">{{ $num($bucket['count']) }} sayfa, {{ $num($bucket['impressions']) }} gösterim</span>
                    </div>
                    @if ($bucket['count'] > count($bucket['rows']))
                        <button type="button" wire:click="setType('{{ $key }}')" class="btn-quiet">{{ $bucket['label'] }} tümü ({{ $num($bucket['count']) }})</button>
                    @endif
                </div>
                <div class="card overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>Sayfa</th><th class="text-right!">Tıklama</th><th class="text-right!">Gösterim</th><th class="text-right!">Sıra</th><th class="text-right!">Oturum</th><th class="text-right!">Dönüşüm</th><th class="text-right!">Sağlık</th></tr></thead>
                        <tbody>
                            @foreach ($bucket['rows'] as $row)
                                @php [$healthLabel, $healthClass] = $health($row); @endphp
                                <tr wire:key="type-{{ $key }}-{{ md5($row['path']) }}" wire:click="open(@js($row['path']))" data-page-row="{{ $row['path'] }}" class="cursor-pointer">
                                    <td class="max-w-md min-w-[14rem]"><span class="block truncate font-medium text-gray-900 dark:text-white">{{ $row['title'] ?: $row['path'] }}</span><span class="block truncate text-xs text-gray-500">{{ $row['path'] }}</span></td>
                                    <td class="text-right tabular-nums">{{ $num($row['clicks']) }}</td>
                                    <td class="text-right tabular-nums">{{ $num($row['impressions']) }}</td>
                                    <td class="text-right tabular-nums">{{ $dec($row['position']) }}</td>
                                    <td class="text-right tabular-nums">{{ $num($row['sessions']) }}</td>
                                    <td class="text-right tabular-nums">{{ $dec($row['key_events']) }}</td>
                                    <td class="whitespace-nowrap text-right">@if ($healthLabel === '—')<span class="text-gray-400">—</span>@else<x-ta.pill :tone="$healthClass">{{ $healthLabel }}</x-ta.pill>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @empty
            <div class="card"><x-ta.empty-state size="sm" icon="none" title="Henüz sayfa yok" message="Site taraması ya da Search Console verisi gelince burada türlerine göre listelenir." /></div>
        @endforelse
    @else
    <section class="{{ $card }}" data-page-rows>
        @if ($activeType !== '')
            <div class="section-head">
                <button type="button" wire:click="setType('')" class="link-neutral text-[13px]">Tüm türler</button>
                <span class="text-gray-300 dark:text-gray-600" aria-hidden="true">›</span>
                <h3 class="section-title">{{ \App\Services\Site\Analysis\SitePagesReader::TYPES[$activeType] }}</h3>
            </div>
        @endif
        <div class="card overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="cursor-pointer select-none hover:text-gray-800 dark:hover:text-white" wire:click="sortBy('path')">Sayfa{{ $arrow('path') }}</th>
                        <th class="{{ $th }}" wire:click="sortBy('clicks')">Tıklama{{ $arrow('clicks') }}</th>
                        <th class="{{ $th }}" wire:click="sortBy('delta')">Δ{{ $arrow('delta') }}</th>
                        <th class="{{ $th }}" wire:click="sortBy('impressions')">Gösterim{{ $arrow('impressions') }}</th>
                        <th class="{{ $th }}" wire:click="sortBy('position')">Sıra{{ $arrow('position') }}</th>
                        <th class="{{ $th }}" wire:click="sortBy('sessions')">Oturum{{ $arrow('sessions') }}</th>
                        <th class="{{ $th }}" wire:click="sortBy('key_events')">Dönüşüm{{ $arrow('key_events') }}</th>
                        @if ($hasAds)<th class="{{ $th }}" wire:click="sortBy('ads_clicks')">Ads tık / maliyet{{ $arrow('ads_clicks') }}</th>@endif
                        <th class="{{ $th }}" wire:click="sortBy('issues')">Sağlık{{ $arrow('issues') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php [$healthLabel, $healthClass] = $health($row); @endphp
                        <tr wire:key="page-{{ md5($row['path']) }}" wire:click="open(@js($row['path']))" data-page-row="{{ $row['path'] }}" class="cursor-pointer">
                            <td class="max-w-md min-w-[14rem]">
                                <span class="block truncate font-medium text-gray-900 dark:text-white">{{ $row['title'] ?: $row['path'] }}</span>
                                <span class="block truncate text-xs text-gray-500">{{ $row['path'] }}</span>
                                @if ($row['services'] !== [] || $row['clusters'] !== [])
                                    <span class="mt-0.5 block truncate text-xs text-gray-500">
                                        {{ implode(', ', array_slice(array_unique([...$row['services'], ...$row['clusters']]), 0, 3)) }}
                                    </span>
                                @elseif ($row['sources'] === [])
                                    <span class="text-xs text-gray-400">envanterde yok (yalnız Search Console / GA4)</span>
                                @endif
                            </td>
                            <td class="text-right tabular-nums">{{ $num($row['clicks']) }}</td>
                            <td class="text-right tabular-nums">@if ($row['delta'] === null)<span class="text-gray-400">{{ $row['clicks'] > 0 ? 'yeni' : '—' }}</span>@else<span @class(['chip', 'chip-down' => $row['delta'] < 0, 'chip-up' => $row['delta'] > 0, 'chip-flat' => $row['delta'] == 0])>{{ ($row['delta'] > 0 ? '+' : '').$row['delta'].'%' }}</span>@endif</td>
                            <td class="text-right tabular-nums">{{ $num($row['impressions']) }}</td>
                            <td class="text-right tabular-nums">{{ $dec($row['position']) }}</td>
                            <td class="text-right tabular-nums">{{ $num($row['sessions']) }}</td>
                            <td class="text-right tabular-nums">{{ $dec($row['key_events']) }}</td>
                            @if ($hasAds)<td class="whitespace-nowrap text-right tabular-nums">{{ $num($row['ads_clicks']) }} / {{ $money($row['ads_cost']) }}</td>@endif
                            <td class="whitespace-nowrap text-right">@if ($healthLabel === '—')<span class="text-gray-400">—</span>@else<x-ta.pill :tone="$healthClass">{{ $healthLabel }}</x-ta.pill>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-6 text-center text-[13px] text-gray-500">{{ $activeFilter === 'ana' ? 'Hizmete bağlı sayfa yok. "Tüm sayfalar" filtresine bakın ya da Kümeler sekmesinde "Eşleştir".' : 'Eşleşen sayfa yok.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($rows->hasPages())<div class="mt-3">{{ $rows->links() }}</div>@endif
    </section>
    @endif

    {{-- Page drawer: numbers, 90-day lines, queries, channels, technical state, related suggestions. --}}
    @if ($page !== null)
        @php $row = $page['row']; @endphp
        <div class="fixed inset-0 z-99999 flex justify-end" role="dialog" aria-modal="true" aria-label="Sayfa detayı" data-page-detail="{{ $row['path'] }}" wire:keydown.escape.window="close">
            <button type="button" class="absolute inset-0 bg-gray-900/40" wire:click="close" aria-label="Kapat"></button>
            <aside class="relative h-full w-full max-w-2xl space-y-6 overflow-y-auto bg-white px-4 py-5 shadow-xl sm:px-6 dark:bg-gray-900">
                <header class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="break-words text-[17px] leading-6 font-semibold text-gray-900 dark:text-white">{{ $row['title'] ?: $row['path'] }}</h2>
                        <a href="{{ str_contains($row['url'], '://') ? $row['url'] : '#' }}" target="_blank" rel="noopener noreferrer" class="mt-0.5 block truncate text-[13px] text-gray-500 hover:text-gray-900 hover:underline dark:hover:text-white">{{ $row['path'] }} ↗</a>
                        <p class="mt-1 text-xs text-gray-500">
                            @if ($row['services'] !== [])Hizmet: {{ implode(', ', $row['services']) }}. @endif
                            @if ($row['clusters'] !== [])Küme: {{ implode(', ', $row['clusters']) }}. @endif
                            @if ($row['category'])Kategori: {{ \App\Models\Page::CATEGORY_LABELS[$row['category']] ?? $row['category'] }}. @endif
                            Kaynak: {{ $row['sources'] !== [] ? implode(', ', $row['sources']) : 'Search Console / GA4' }}
                        </p>
                    </div>
                    <button type="button" wire:click="close" class="btn shrink-0">Kapat</button>
                </header>

                <section class="metric-strip grid-cols-3 sm:grid-cols-6" data-detail-numbers>
                    @foreach ([['Tıklama', $num($row['clicks'])], ['Δ', $row['delta'] === null ? '—' : ($row['delta'] > 0 ? '+' : '').$row['delta'].'%'], ['Gösterim', $num($row['impressions'])], ['Sıra', $dec($row['position'])], ['Oturum', $num($row['sessions'])], ['Dönüşüm', $dec($row['key_events'])]] as [$label, $value])
                        <div class="min-w-0 bg-white px-3 py-2.5 dark:bg-gray-900"><p class="text-xs text-gray-500">{{ $label }}</p><p class="mt-0.5 text-[15px] font-semibold tabular-nums text-gray-900 dark:text-white">{{ $value }}</p></div>
                    @endforeach
                </section>
                @if ($row['ads_clicks'] !== null)
                    <p class="-mt-3 text-xs text-gray-600 dark:text-gray-300">Google Ads (dönem): {{ $num($row['ads_clicks']) }} tıklama, {{ $money($row['ads_cost']) }} maliyet</p>
                @endif

                <section data-detail-trend>
                    <h3 class="section-title mb-2">Son 90 gün</h3>
                    <div class="card grid gap-4 px-4 py-3.5 sm:grid-cols-2">
                        @include('livewire.operator.website.v2.partials.sparkline', ['points' => array_map(fn ($d) => ['date' => $d['date'], 'value' => $d['clicks']], $page['trend']), 'label' => 'Tıklama', 'format' => $num])
                        @include('livewire.operator.website.v2.partials.sparkline', ['points' => array_map(fn ($d) => ['date' => $d['date'], 'value' => $d['sessions']], $page['trend']), 'label' => 'Oturum', 'format' => $num])
                    </div>
                </section>

                <section data-detail-queries>
                    <h3 class="section-title mb-2">Sorgular <span class="text-[13px] font-normal text-gray-500">{{ $periodDays }} gün</span></h3>
                    <div class="card overflow-x-auto">
                        <table class="data-table">
                            <thead><tr><th>Sorgu</th><th class="text-right!">Tıklama</th><th class="text-right!">Önceki</th><th class="text-right!">Gösterim</th><th class="text-right!">Sıra</th></tr></thead>
                            <tbody>
                                @forelse ($page['queries'] as $query)
                                    <tr><td class="min-w-[10rem]">{{ $query['query'] }}</td><td class="text-right tabular-nums">{{ $num($query['clicks']) }}</td><td class="text-right tabular-nums text-gray-500">{{ $num($query['prev_clicks']) }}</td><td class="text-right tabular-nums">{{ $num($query['impressions']) }}</td><td class="text-right tabular-nums">{{ $dec($query['position']) }}</td></tr>
                                @empty
                                    <tr><td colspan="5" class="text-[13px] text-gray-500">Search Console sorgusu yok.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>

                <section data-detail-channels>
                    <h3 class="section-title mb-2">Kanallar ve dönüşümler</h3>
                    <div class="card overflow-x-auto">
                        <table class="data-table">
                            <thead><tr><th>Kaynak / ortam</th><th class="text-right!">Oturum</th><th class="text-right!">Anahtar etkinlik</th></tr></thead>
                            <tbody>
                                @forelse ($page['channels'] as $channel)
                                    <tr><td>{{ $channel['source'] }} / {{ $channel['medium'] }}</td><td class="text-right tabular-nums">{{ $num($channel['sessions']) }}</td><td class="text-right tabular-nums">{{ $dec($channel['key_events']) }}</td></tr>
                                @empty
                                    <tr><td colspan="3" class="text-[13px] text-gray-500">GA4 verisi yok.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>

                <section data-detail-health>
                    <h3 class="section-title mb-1">Teknik durum</h3>
                    <p class="text-[13px] text-gray-600 dark:text-gray-300">
                        HTTP {{ $row['status_code'] ?? '—' }}, {{ $row['indexable'] === false ? 'indekslenemez (noindex)' : ($row['indexable'] === true ? 'indekslenebilir' : 'indeks durumu bilinmiyor') }}
                        @if ($page['links'] !== null), iç bağlantı: {{ $num($page['links']['in']) }} gelen, {{ $num($page['links']['out']) }} giden @endif
                    </p>
                    <ul class="mt-2 space-y-1.5 text-[13px]">
                        @forelse ($page['issues'] as $issue)
                            <li class="flex items-baseline gap-2"><x-ta.pill :tone="in_array($issue['severity'], ['critical', 'high'], true) ? 'critical' : 'look'">{{ ['critical' => 'Kritik', 'high' => 'Yüksek', 'medium' => 'Orta', 'low' => 'Düşük', 'warning' => 'Uyarı', 'info' => 'Bilgi'][$issue['severity']] ?? \Illuminate\Support\Str::ucfirst((string) $issue['severity']) }}</x-ta.pill> <span class="min-w-0">{{ $issue['message'] }}</span></li>
                        @empty
                            <li class="text-gray-500">Son taramada sorun yok.</li>
                        @endforelse
                    </ul>
                </section>

                <section data-detail-suggestions>
                    <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="section-title">İlgili öneriler</h3>
                        @if ($row['page_id'] !== null)
                            <a href="{{ route('operator.website', ['assetId' => $site->id, 'tab' => 'yapilacaklar', 'url' => $row['page_id']]) }}" wire:navigate class="link-primary text-[13px]" data-detail-fixes>{{ $page['wordpress'] ? 'Önerileri aç ve WordPress’e uygula' : 'Önerileri aç' }}</a>
                        @endif
                    </div>
                    <ul class="card">
                        @forelse ($page['suggestions'] as $suggestion)
                            <li class="border-t border-gray-200 px-4 py-2.5 first:border-t-0 dark:border-gray-800"><span class="text-sm font-medium text-gray-900 dark:text-white">{{ $suggestion->title }}</span> <span class="block text-xs text-gray-500">{{ \App\Services\Site\SiteSuggestionTypes::label((string) $suggestion->action_type) }}, {{ \App\Livewire\Operator\Website\V2\SuggestionsTab::STATUS_LABELS[$suggestion->status] ?? $suggestion->status }}</span></li>
                        @empty
                            <li class="px-4 py-3 text-[13px] text-gray-500">Açık öneri yok.</li>
                        @endforelse
                    </ul>
                    @php
                        $externalLinks = app(\App\Services\Operator\ExternalLinks::class);
                        $gscProperty = $externalLinks->searchConsolePropertyFor((int) $site->id);
                    @endphp
                    <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1" data-page-open-links>
                        @if ($row['wp_post_id'] !== null && ($site->primary_url || $site->domain))
                            <x-operator.open-link :link="$externalLinks->wordPressPost($site, (int) $row['wp_post_id'])" />
                        @endif
                        @if ($gscProperty !== null && str_contains($row['url'], '://'))
                            <x-operator.open-link :link="$externalLinks->searchConsolePage($gscProperty, $row['url'])" />
                        @endif
                    </p>
                </section>
            </aside>
        </div>
    @endif
</div>
