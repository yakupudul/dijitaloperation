@php
    $number = fn ($value, int $decimals = 0): string => $value === null ? '—' : number_format((float) $value, $decimals, ',', '.');
    $channelLabels = ['Organic Search' => 'Google organik', 'Paid Search' => 'Google Ads', 'Direct' => 'Doğrudan', 'Referral' => 'Yönlendirme', 'Organic Social' => 'Sosyal', 'Paid Social' => 'Meta / sosyal reklam', 'Organic Maps' => 'Haritalar', 'Email' => 'E-posta', 'Unassigned' => 'Belirsiz'];
@endphp
<section class="space-y-4 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="font-semibold text-gray-800 dark:text-white/90">Sayfa Karnesi (son 28 gün)</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Her sayfa için Google tıkları, GA4 ziyaret ve dönüşümleri, trafiğin geldiği kanal, Google Ads, dizin durumu, hız ve açık SEO görevleri bir arada.
                {{ \Illuminate\Support\Carbon::parse($card['period']['start'])->format('d.m.Y') }} – {{ \Illuminate\Support\Carbon::parse($card['period']['end'])->format('d.m.Y') }}.
                “—” = o kaynakta bu sayfa için veri yok.
            </p>
        </div>
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Sayfa ara…" class="w-56 rounded-lg border border-gray-200 bg-transparent px-3 py-1.5 text-sm dark:border-gray-700 dark:text-white" />
    </div>

    <p class="text-xs text-gray-500">
        Kaynaklar:
        @foreach (['search_console' => 'Search Console', 'ga4' => 'GA4', 'ga4_channels' => 'GA4 kanal', 'google_ads' => 'Google Ads', 'inspection' => 'Dizin kontrolü', 'speed' => 'Hız'] as $source => $label)
            <span @class(['text-emerald-600' => $card['sources'][$source], 'text-gray-400 line-through' => ! $card['sources'][$source]])>{{ $label }}</span>@if (! $loop->last) · @endif
        @endforeach
    </p>

    @if ($card['rows'] === [])
        <p class="text-sm text-gray-500">{{ $search !== '' ? 'Aramaya uyan sayfa yok.' : 'Henüz sayfa verisi yok. Search Console, GA4 veya Google Ads bağlanıp veri geldikten sonra dolar.' }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-400">
                    <tr>
                        <th class="py-2 pr-3">Sayfa</th>
                        <th class="py-2 pr-3 text-right">Google tık</th>
                        <th class="py-2 pr-3 text-right">Ziyaret</th>
                        <th class="py-2 pr-3 text-right">Dönüşüm</th>
                        <th class="py-2 pr-3">Ana kanal</th>
                        <th class="py-2 pr-3 text-right">Ads tık / maliyet / dönüşüm</th>
                        <th class="py-2 pr-3">Dizin · Hız</th>
                        <th class="py-2">Durum</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($card['rows'] as $row)
                        @php
                            $sessionsTotal = array_sum($row['channels']);
                            $topChannel = array_key_first($row['channels']);
                        @endphp
                        <tr wire:key="scorecard-{{ md5($row['key']) }}" wire:click="toggle('{{ $row['key'] }}')" class="cursor-pointer hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                            <td class="max-w-xs truncate py-2 pr-3 font-medium text-gray-800 dark:text-gray-200" title="{{ $row['url'] }}">{{ $row['path'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">
                                {{ $number($row['clicks']) }}
                                @if ($row['clicks_prev'] !== null && $row['clicks_prev'] > 0)
                                    @php
                                        $change = (int) round(($row['clicks'] - $row['clicks_prev']) / $row['clicks_prev'] * 100);
                                    @endphp
                                    <span class="text-xs {{ $change >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">{{ $change >= 0 ? '+' : '' }}{{ $change }}%</span>
                                @endif
                            </td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $number($row['sessions']) }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $number($row['key_events']) }}</td>
                            <td class="py-2 pr-3 text-xs text-gray-600 dark:text-gray-400">
                                @if ($topChannel !== null && $sessionsTotal > 0)
                                    {{ $channelLabels[$topChannel] ?? $topChannel }} %{{ (int) round($row['channels'][$topChannel] / $sessionsTotal * 100) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="py-2 pr-3 text-right text-xs tabular-nums">
                                @if ($row['ads_clicks'] !== null)
                                    {{ $number($row['ads_clicks']) }} / {{ $number($row['ads_cost']) }} / {{ $number($row['ads_conversions'], 1) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="py-2 pr-3 text-xs">
                                <span @class(['text-emerald-600' => $row['indexed'] === true, 'text-rose-600' => $row['indexed'] === false, 'text-gray-400' => $row['indexed'] === null])>{{ $row['indexed'] === null ? 'Dizin ?' : ($row['indexed'] ? 'Dizinde' : 'Dizinde değil') }}</span>
                                · <span @class(['text-rose-600' => ($row['lcp_ms'] ?? 0) > 4000, 'text-gray-500' => ($row['lcp_ms'] ?? 0) <= 4000])>{{ $row['lcp_ms'] !== null ? number_format($row['lcp_ms'] / 1000, 1, ',', '.').' sn' : 'Hız ?' }}</span>
                            </td>
                            <td class="py-2 text-xs">
                                @foreach ($row['flags'] as $flag)
                                    <span class="mr-1 inline-block rounded bg-amber-50 px-1.5 py-0.5 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ $flag }}</span>
                                @endforeach
                                @if ($row['task_count'] > 0)
                                    <span class="inline-block rounded bg-brand-50 px-1.5 py-0.5 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">{{ $row['task_count'] }} SEO görevi</span>
                                @endif
                            </td>
                        </tr>
                        @if ($openKey === $row['key'])
                            <tr wire:key="scorecard-detail-{{ md5($row['key']) }}">
                                <td colspan="8" class="bg-gray-50 px-3 py-3 text-xs text-gray-600 dark:bg-white/[0.02] dark:text-gray-400">
                                    <div class="grid gap-4 md:grid-cols-3">
                                        <div>
                                            <p class="font-medium text-gray-700 dark:text-gray-300">Kanal dağılımı (ziyaret)</p>
                                            @forelse ($row['channels'] as $channel => $sessions)
                                                <p>{{ $channelLabels[$channel] ?? $channel }}: {{ $number($sessions) }} (%{{ $sessionsTotal > 0 ? (int) round($sessions / $sessionsTotal * 100) : 0 }})</p>
                                            @empty
                                                <p>Kanal verisi yok (GA4 açılış sayfası × kanal verisi henüz çekilmedi).</p>
                                            @endforelse
                                        </div>
                                        <div>
                                            <p class="font-medium text-gray-700 dark:text-gray-300">Google</p>
                                            <p>Gösterim: {{ $number($row['impressions']) }} · Önceki 28 gün tık: {{ $number($row['clicks_prev']) }}</p>
                                            <p>Dizin durumu: {{ $row['coverage'] ?? '—' }}</p>
                                            <p><a href="{{ $row['url'] }}" target="_blank" rel="noopener" class="text-brand-600 hover:underline">Sayfayı aç ↗</a></p>
                                        </div>
                                        <div>
                                            <p class="font-medium text-gray-700 dark:text-gray-300">Açık SEO görevleri</p>
                                            @forelse ($row['tasks'] as $title)
                                                <p>• {{ $title }}</p>
                                            @empty
                                                <p>Yok.</p>
                                            @endforelse
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($card['total'] > count($card['rows']))
            <p class="text-xs text-gray-500">En çok trafik alan {{ count($card['rows']) }} sayfa gösteriliyor (toplam {{ $card['total'] }}). Diğerleri için arama kutusunu kullanın.</p>
        @endif
    @endif
</section>
