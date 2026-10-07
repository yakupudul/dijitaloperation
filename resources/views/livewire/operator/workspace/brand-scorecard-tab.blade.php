@php
    $currency = $card['currency'] ?? '';
    $num = fn ($v, int $d = 0) => $v === null ? '—' : number_format((float) $v, $d, ',', '.');
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 0, ',', '.').' '.$currency;
    $pct = fn ($v) => $v === null ? '' : (($v > 0 ? '+' : '').number_format((float) $v, 0, ',', '.').'%');
    $cellTone = [
        'better' => 'bg-emerald-50 ring-emerald-200 dark:bg-emerald-500/10 dark:ring-emerald-500/30',
        'around' => 'bg-gray-50 ring-gray-200 dark:bg-white/[0.03] dark:ring-gray-700',
        'worse' => 'bg-rose-50 ring-rose-200 dark:bg-rose-500/10 dark:ring-rose-500/30',
        'none' => 'bg-white ring-gray-300 dark:bg-transparent dark:ring-gray-600',
        'na' => 'bg-white ring-gray-100 dark:bg-transparent dark:ring-gray-800',
    ];
    $noteTone = ['Maliyet yüksek' => 'text-rose-700 dark:text-rose-300', 'Fırsat' => 'text-emerald-700 dark:text-emerald-300', 'Sayfa yok' => 'text-amber-700 dark:text-amber-300'];
    $adCell = function (?array $c) use ($money, $num, $pct, $typeLabels): array {
        if ($c === null) {
            return ['none', 'Reklam yok', 'fırsat'];
        }
        $line = $c['cost'] === null ? $money($c['spend']).' · sonuç yok' : ($typeLabels[$c['type']] ?? '').' başı '.$money($c['cost']);
        $sub = $c['compared'] ? 'ort. '.$money($c['average']['median']).' · '.$pct($c['diff']) : $num($c['results']).' '.($typeLabels[$c['type']] ?? '').' · ortalama için marka az';

        return [$c['state'], $line, $sub];
    };
@endphp

<div class="space-y-5" data-testid="brand-scorecard">
    @if (! $card['ready'])
        <p class="rounded-xl bg-white p-4 text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">Markanın onaylı hizmeti yok. Hizmetler Ayarlar › Marka bilgileri’nden eklenir.</p>
    @else
        @if ($card['notes'] !== [])
            <div class="grid gap-3 lg:grid-cols-3" data-testid="brand-scorecard-notes">
                @foreach ($card['notes'] as $note)
                    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                        <p class="text-xs font-semibold uppercase tracking-wide {{ $noteTone[$note['kind']] ?? 'text-gray-500' }}">{{ $note['kind'] }}</p>
                        <p class="mt-1 font-semibold text-gray-900 dark:text-white">{{ $note['title'] }}</p>
                        <p class="mt-1 text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ $note['body'] }}</p>
                        @php
                            $href = match ($note['tab']) {
                                'meta' => $metaAssetId ? route('operator.meta.overview', ['assetId' => $metaAssetId]) : null,
                                'google_ads' => $googleAdsAssetId ? route('operator.google-ads.overview', ['assetId' => $googleAdsAssetId]) : null,
                                default => route('operator.brand', ['brand' => $brand->id, 'tab' => $note['tab']]),
                            };
                        @endphp
                        @if ($href)<a href="{{ $href }}" wire:navigate class="mt-2 inline-block text-sm font-semibold text-brand-600 hover:underline">{{ $note['cta'] }} →</a>@endif
                    </section>
                @endforeach
            </div>
        @endif

        <section class="overflow-hidden rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                <h2 class="font-semibold text-gray-900 dark:text-white">Hizmet başına dört kanal · son 30 gün</h2>
                <span class="flex-1"></span>
                @foreach (['better' => 'ortalamadan iyi', 'around' => 'ortalama civarı', 'worse' => 'ortalamadan kötü', 'none' => 'yok, fırsat'] as $tone => $label)
                    <span class="flex items-center gap-1.5 text-xs text-gray-500"><span class="h-3 w-3 rounded ring-1 ring-inset {{ $cellTone[$tone] }}"></span>{{ $label }}</span>
                @endforeach
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[64rem] text-sm" data-testid="brand-scorecard-table">
                    <thead class="bg-gray-50 dark:bg-white/[0.02]">
                        <tr class="text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-2.5">Hizmet</th><th class="px-2 py-2.5">Web sitesi</th><th class="px-2 py-2.5">Google Ads</th><th class="px-2 py-2.5">Meta</th><th class="px-2 py-2.5">İşletme Profili</th><th class="px-3 py-2.5 text-right">Yarıştaki yeri</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($card['rows'] as $row)
                            @php
                                $w = $row['web'];
                                $cells = [
                                    [$w['state'], $w['pages'] === 0 ? 'Sayfa yok' : $num($w['clicks']).' arama tıkı', $w['pages'] === 0 ? 'fırsat' : $w['pages'].' sayfa · '.$num($w['key_events'], 1).' dönüşüm'],
                                    $adCell($row['google_ads']),
                                    $adCell($row['meta']),
                                    match ($row['gbp']['state']) {
                                        'na' => ['na', 'Profil verisi yok', ''],
                                        'around' => ['around', 'Profilde listeli', ''],
                                        default => ['none', 'Profilde yok', 'fırsat'],
                                    },
                                ];
                            @endphp
                            <tr class="align-top" wire:key="scorecard-{{ $row['id'] }}">
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-gray-900 dark:text-white">{{ $row['name'] }}</p>
                                    <p class="text-xs text-gray-500">{{ $row['main'] ? 'ana hizmet' : 'ikincil hizmet' }}</p>
                                </td>
                                @foreach ($cells as [$tone, $line, $sub])
                                    <td class="px-2 py-2">
                                        <div class="h-full rounded-lg px-3 py-2 ring-1 ring-inset {{ $cellTone[$tone] }}">
                                            <p @class(['text-[13px] font-medium tabular-nums', 'text-gray-900 dark:text-white' => $tone !== 'none' && $tone !== 'na', 'text-gray-500' => $tone === 'none' || $tone === 'na'])>{{ $line }}</p>
                                            @if ($sub !== '')<p class="text-[11px] text-gray-500">{{ $sub }}</p>@endif
                                        </div>
                                    </td>
                                @endforeach
                                <td class="px-3 py-3 text-right">
                                    @if ($row['rank'])
                                        <p class="text-lg font-semibold tabular-nums text-gray-900 dark:text-white">{{ $row['rank']['rank'] }}<span class="text-xs font-normal text-gray-500"> / {{ $row['rank']['of'] }} marka</span></p>
                                    @else
                                        <span class="text-xs text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="border-t border-gray-100 px-4 py-3 text-xs text-gray-500 dark:border-gray-700">Ortalama: aynı hizmeti yapan diğer markaların aynı sonuç türündeki son 30 gün ortancası. Google Ads hizmeti anahtar kelimeden, Meta hizmeti kampanya → hizmet eşleşmesinden gelir. Yarıştaki yeri: maliyete göre sıra (1 = en ucuz). Sayılar her sabah yenilenir.</p>
        </section>
    @endif
</div>
