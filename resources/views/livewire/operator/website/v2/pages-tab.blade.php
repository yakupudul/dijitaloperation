@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-white';
    $num = fn ($value) => $value === null ? '—' : number_format((float) $value, 0, ',', '.');
    $dec = fn ($value) => $value === null ? '—' : number_format((float) $value, 1, ',', '.');
    $money = fn ($value) => $value === null ? '—' : number_format((float) $value, 2, ',', '.');
    $hasAds = collect($rows->items())->contains(fn (array $row): bool => $row['ads_clicks'] !== null);
    $arrow = fn (string $column) => $sort === $column ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    $th = 'cursor-pointer select-none whitespace-nowrap px-2 py-2 text-right hover:text-gray-800 dark:hover:text-white';
    $health = function (array $row): array {
        if ($row['status_code'] !== null && $row['status_code'] >= 400) {
            return ['HTTP '.$row['status_code'], 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'];
        }
        if ($row['indexable'] === false) {
            return ['noindex', 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'];
        }
        if ($row['serious'] > 0) {
            return [$row['serious'].' ciddi sorun', 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'];
        }
        if ($row['issues'] > 0) {
            return [$row['issues'].' uyarı', 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300'];
        }
        if ($row['status_code'] !== null) {
            return [(string) $row['status_code'], 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'];
        }

        return ['—', 'text-gray-400'];
    };
@endphp
<div class="space-y-3 text-sm dark:text-gray-200" data-pages-tab>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <nav class="flex flex-wrap gap-1" aria-label="Sayfa filtreleri">
            @foreach (\App\Services\Site\Analysis\SitePagesReader::FILTERS as $key => $label)
                <button type="button" wire:click="setFilter('{{ $key }}')" data-filter="{{ $key }}" @class(['rounded-lg px-3 py-1.5 text-xs font-semibold', 'bg-brand-500 text-white' => $activeFilter === $key, 'text-gray-600 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700' => $activeFilter !== $key])>{{ $label }} <span class="font-normal opacity-75">{{ $num($counts[$key] ?? 0) }}</span></button>
            @endforeach
        </nav>
        <div class="flex items-center gap-2">
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="URL, başlık veya hizmet ara" aria-label="Ara" class="{{ $input }} w-56 py-1">
            <select wire:model.live="period" aria-label="Dönem" class="{{ $input }} py-1">
                @foreach (\App\Services\Site\Analysis\SitePagesReader::PERIODS as $days => $label)<option value="{{ $days }}">{{ $label }}</option>@endforeach
            </select>
        </div>
    </div>

    <section class="{{ $card }} overflow-x-auto p-0" data-page-rows>
        <table class="w-full text-left text-xs">
            <thead class="border-b border-gray-100 text-gray-500 dark:border-gray-800">
                <tr>
                    <th class="cursor-pointer select-none px-3 py-2 hover:text-gray-800 dark:hover:text-white" wire:click="sortBy('path')">Sayfa{{ $arrow('path') }}</th>
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
                    @php([$healthLabel, $healthClass] = $health($row))
                    <tr wire:key="page-{{ md5($row['path']) }}" wire:click="open(@js($row['path']))" data-page-row="{{ $row['path'] }}" class="cursor-pointer border-t border-gray-100 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                        <td class="max-w-md px-3 py-2">
                            <span class="block truncate font-medium text-gray-900 dark:text-white">{{ $row['title'] ?: $row['path'] }}</span>
                            <span class="block truncate text-gray-400">{{ $row['path'] }}</span>
                            @if ($row['services'] !== [] || $row['clusters'] !== [])
                                <span class="mt-0.5 flex flex-wrap gap-1">
                                    @foreach (array_slice(array_unique([...$row['services'], ...$row['clusters']]), 0, 3) as $tag)<span class="rounded bg-brand-50 px-1.5 py-0.5 text-[10px] text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">{{ $tag }}</span>@endforeach
                                </span>
                            @elseif ($row['sources'] === [])
                                <span class="text-[10px] text-gray-400">envanterde yok (yalnız Search Console / GA4)</span>
                            @endif
                        </td>
                        <td class="px-2 text-right tabular-nums">{{ $num($row['clicks']) }}</td>
                        <td @class(['px-2 text-right tabular-nums', 'text-rose-600' => ($row['delta'] ?? 0) < 0, 'text-emerald-600' => ($row['delta'] ?? 0) > 0, 'text-gray-400' => $row['delta'] === null])>{{ $row['delta'] === null ? ($row['clicks'] > 0 ? 'yeni' : '—') : ($row['delta'] > 0 ? '+' : '').$row['delta'].'%' }}</td>
                        <td class="px-2 text-right tabular-nums">{{ $num($row['impressions']) }}</td>
                        <td class="px-2 text-right tabular-nums">{{ $dec($row['position']) }}</td>
                        <td class="px-2 text-right tabular-nums">{{ $num($row['sessions']) }}</td>
                        <td class="px-2 text-right tabular-nums">{{ $dec($row['key_events']) }}</td>
                        @if ($hasAds)<td class="whitespace-nowrap px-2 text-right tabular-nums">{{ $num($row['ads_clicks']) }} / {{ $money($row['ads_cost']) }}</td>@endif
                        <td class="px-2 text-right"><span class="whitespace-nowrap rounded-full px-2 py-0.5 {{ $healthClass }}">{{ $healthLabel }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-3 py-6 text-center text-gray-500">{{ $activeFilter === 'ana' ? 'Hizmete bağlı sayfa yok. "Tüm sayfalar" filtresine bakın ya da Sorgular › İçerik fikirleri › "Eşleştir".' : 'Eşleşen sayfa yok.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-3 py-2">{{ $rows->links() }}</div>
    </section>

    @if ($page !== null)
        @php($row = $page['row'])
        <div class="fixed inset-0 z-99999 flex justify-end" role="dialog" aria-modal="true" aria-label="Sayfa detayı" data-page-detail="{{ $row['path'] }}" wire:keydown.escape.window="close">
            <button type="button" class="absolute inset-0 bg-gray-900/40" wire:click="close" aria-label="Kapat"></button>
            <aside class="relative h-full w-full max-w-2xl space-y-4 overflow-y-auto bg-white p-5 shadow-xl dark:bg-gray-900">
                <header class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="truncate text-lg font-semibold text-gray-900 dark:text-white">{{ $row['title'] ?: $row['path'] }}</h2>
                        <a href="{{ str_contains($row['url'], '://') ? $row['url'] : '#' }}" target="_blank" rel="noopener noreferrer" class="block truncate text-xs text-gray-500 hover:underline">{{ $row['path'] }} ↗</a>
                        <p class="mt-1 text-xs text-gray-500">
                            @if ($row['services'] !== [])Hizmet: {{ implode(', ', $row['services']) }} · @endif
                            @if ($row['clusters'] !== [])Küme: {{ implode(', ', $row['clusters']) }} · @endif
                            @if ($row['category'])Kategori: {{ \App\Models\Page::CATEGORY_LABELS[$row['category']] ?? $row['category'] }} · @endif
                            Kaynak: {{ $row['sources'] !== [] ? implode(', ', $row['sources']) : 'Search Console / GA4' }}
                        </p>
                    </div>
                    <button type="button" wire:click="close" class="rounded-lg px-2 py-1 text-xs ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Kapat</button>
                </header>

                <section class="grid grid-cols-3 gap-2 text-xs sm:grid-cols-6" data-detail-numbers>
                    @foreach ([['Tıklama', $num($row['clicks'])], ['Δ', $row['delta'] === null ? '—' : ($row['delta'] > 0 ? '+' : '').$row['delta'].'%'], ['Gösterim', $num($row['impressions'])], ['Sıra', $dec($row['position'])], ['Oturum', $num($row['sessions'])], ['Dönüşüm', $dec($row['key_events'])]] as [$label, $value])
                        <div class="rounded-lg bg-gray-50 p-2 dark:bg-white/[0.03]"><p class="text-gray-500">{{ $label }}</p><p class="text-base font-semibold tabular-nums">{{ $value }}</p></div>
                    @endforeach
                </section>
                @if ($row['ads_clicks'] !== null)
                    <p class="text-xs text-gray-600 dark:text-gray-300">Google Ads (dönem): {{ $num($row['ads_clicks']) }} tıklama · {{ $money($row['ads_cost']) }} maliyet</p>
                @endif

                <section class="space-y-2" data-detail-trend>
                    <h3 class="text-sm font-semibold">Son 90 gün</h3>
                    @include('livewire.operator.website.v2.partials.sparkline', ['points' => array_map(fn ($d) => ['date' => $d['date'], 'value' => $d['clicks']], $page['trend']), 'label' => 'Tıklama', 'format' => $num])
                    @include('livewire.operator.website.v2.partials.sparkline', ['points' => array_map(fn ($d) => ['date' => $d['date'], 'value' => $d['sessions']], $page['trend']), 'label' => 'Oturum', 'format' => $num])
                </section>

                <section data-detail-queries>
                    <h3 class="mb-1 text-sm font-semibold">Sorgular <span class="font-normal text-gray-500">({{ $periodDays }} gün)</span></h3>
                    <table class="w-full text-left text-xs">
                        <thead class="text-gray-500"><tr><th class="py-1">Sorgu</th><th class="text-right">Tıklama</th><th class="text-right">Önceki</th><th class="text-right">Gösterim</th><th class="text-right">Sıra</th></tr></thead>
                        <tbody>
                            @forelse ($page['queries'] as $query)
                                <tr class="border-t border-gray-100 dark:border-gray-800"><td class="py-1">{{ $query['query'] }}</td><td class="text-right tabular-nums">{{ $num($query['clicks']) }}</td><td class="text-right tabular-nums text-gray-500">{{ $num($query['prev_clicks']) }}</td><td class="text-right tabular-nums">{{ $num($query['impressions']) }}</td><td class="text-right tabular-nums">{{ $dec($query['position']) }}</td></tr>
                            @empty
                                <tr><td colspan="5" class="py-1 text-gray-500">Search Console sorgusu yok.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </section>

                <section data-detail-channels>
                    <h3 class="mb-1 text-sm font-semibold">Kanallar ve dönüşümler</h3>
                    <table class="w-full text-left text-xs">
                        <thead class="text-gray-500"><tr><th class="py-1">Kaynak / ortam</th><th class="text-right">Oturum</th><th class="text-right">Anahtar etkinlik</th></tr></thead>
                        <tbody>
                            @forelse ($page['channels'] as $channel)
                                <tr class="border-t border-gray-100 dark:border-gray-800"><td class="py-1">{{ $channel['source'] }} / {{ $channel['medium'] }}</td><td class="text-right tabular-nums">{{ $num($channel['sessions']) }}</td><td class="text-right tabular-nums">{{ $dec($channel['key_events']) }}</td></tr>
                            @empty
                                <tr><td colspan="3" class="py-1 text-gray-500">GA4 verisi yok.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </section>

                <section data-detail-health>
                    <h3 class="mb-1 text-sm font-semibold">Teknik durum</h3>
                    <p class="text-xs text-gray-600 dark:text-gray-300">
                        HTTP {{ $row['status_code'] ?? '—' }} · {{ $row['indexable'] === false ? 'indekslenemez (noindex)' : ($row['indexable'] === true ? 'indekslenebilir' : 'indeks durumu bilinmiyor') }}
                        @if ($page['links'] !== null) · iç bağlantı: {{ $num($page['links']['in']) }} gelen, {{ $num($page['links']['out']) }} giden @endif
                    </p>
                    <ul class="mt-1 space-y-1 text-xs">
                        @forelse ($page['issues'] as $issue)
                            <li><span @class(['rounded px-1.5 py-0.5 font-medium', 'bg-rose-50 text-rose-700' => in_array($issue['severity'], ['critical', 'high'], true), 'bg-amber-50 text-amber-800' => ! in_array($issue['severity'], ['critical', 'high'], true)])>{{ $issue['severity'] }}</span> {{ $issue['message'] }}</li>
                        @empty
                            <li class="text-gray-500">Son taramada sorun yok.</li>
                        @endforelse
                    </ul>
                </section>

                <section data-detail-suggestions>
                    <div class="mb-1 flex items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold">İlgili öneriler</h3>
                        @if ($row['page_id'] !== null)
                            <a href="{{ route('operator.website', ['assetId' => $site->id, 'tab' => 'ozet', 'sub' => 'oneriler', 'url' => $row['page_id']]) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline" data-detail-fixes>{{ $page['wordpress'] ? 'Önerileri aç ve WordPress’e uygula →' : 'Önerileri aç →' }}</a>
                        @endif
                    </div>
                    <ul class="divide-y divide-gray-100 text-xs dark:divide-gray-800">
                        @forelse ($page['suggestions'] as $suggestion)
                            <li class="py-1.5"><span class="font-medium">{{ $suggestion->title }}</span> <span class="text-gray-500">· {{ \App\Services\Site\SiteSuggestionTypes::label((string) $suggestion->action_type) }} · {{ \App\Livewire\Operator\Website\V2\SuggestionsTab::STATUS_LABELS[$suggestion->status] ?? $suggestion->status }}</span></li>
                        @empty
                            <li class="py-1.5 text-gray-500">Açık öneri yok.</li>
                        @endforelse
                    </ul>
                    @if ($row['wp_post_id'] !== null && ($site->primary_url || $site->domain))
                        <a href="{{ rtrim($site->primary_url ?: 'https://'.$site->domain, '/') }}/wp-admin/post.php?post={{ $row['wp_post_id'] }}&action=edit" target="_blank" rel="noopener noreferrer" class="mt-2 inline-block text-xs text-gray-500 hover:underline">WordPress’te aç ↗</a>
                    @endif
                </section>
            </aside>
        </div>
    @endif
</div>
