@php
    $currency = $card['currency'] ?? '';
    $num = fn ($v, int $d = 0) => $v === null ? '—' : number_format((float) $v, $d, ',', '.');
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 0, ',', '.').' '.$currency;
    $pct = fn ($v) => $v === null ? '' : (($v > 0 ? '+' : '').number_format((float) $v, 0, ',', '.').'%');
    // Sade panel (2026-10-20): a cell's standing is a dot before its line, not a coloured box.
    $cellTone = [
        'better' => 'bg-success-500',
        'around' => 'bg-gray-400',
        'worse' => 'bg-error-500',
        'none' => 'ring-1 ring-inset ring-gray-400 dark:ring-gray-500',
        'na' => 'bg-gray-200 dark:bg-gray-700',
    ];
    $noteTone = ['Maliyet yüksek' => 'text-error-700 dark:text-error-400', 'Fırsat' => 'text-success-700 dark:text-success-400', 'Sayfa yok' => 'text-warning-700 dark:text-warning-400'];
    $adCell = function (?array $c) use ($money, $num, $pct, $typeLabels): array {
        if ($c === null) {
            return ['none', 'Reklam yok', 'fırsat'];
        }
        $line = $c['cost'] === null ? $money($c['spend']).', sonuç yok' : ($typeLabels[$c['type']] ?? '').' başı '.$money($c['cost']);
        $sub = $c['compared'] ? 'ort. '.$money($c['average']['median']).', '.$pct($c['diff']) : $num($c['results']).' '.($typeLabels[$c['type']] ?? '').', ortalama için marka az';

        return [$c['state'], $line, $sub];
    };
@endphp

<div class="space-y-10" data-testid="brand-scorecard">
    @if (! $card['ready'])
        <p class="text-sm text-gray-500">Markanın onaylı hizmeti yok. Hizmetler Ayarlar › Marka bilgileri’nden eklenir.</p>
    @else
        @if ($card['notes'] !== [])
            <div class="grid gap-x-10 gap-y-6 lg:grid-cols-3" data-testid="brand-scorecard-notes">
                @foreach ($card['notes'] as $note)
                    <section>
                        <p class="text-xs {{ $noteTone[$note['kind']] ?? 'text-gray-500' }}">{{ $note['kind'] }}</p>
                        <p class="mt-1 font-semibold text-gray-900 dark:text-white">{{ $note['title'] }}</p>
                        <p class="mt-1 text-sm leading-relaxed text-gray-500 dark:text-gray-400">{{ $note['body'] }}</p>
                        @php
                            $href = match ($note['tab']) {
                                'meta' => $metaAssetId ? route('operator.meta.overview', ['assetId' => $metaAssetId]) : null,
                                'google_ads' => $googleAdsAssetId ? route('operator.google-ads.overview', ['assetId' => $googleAdsAssetId]) : null,
                                default => route('operator.brand', ['brand' => $brand->id, 'tab' => $note['tab']]),
                            };
                        @endphp
                        @if ($href)<a href="{{ $href }}" wire:navigate class="link-neutral mt-2 inline-block text-sm">{{ $note['cta'] }}</a>@endif
                    </section>
                @endforeach
            </div>
        @endif

        <section>
            <div class="flex flex-wrap items-baseline gap-x-5 gap-y-2">
                <h2 class="section-title text-gray-900 dark:text-white">Hizmet başına dört kanal <span class="text-sm font-normal text-gray-500">son 30 gün</span></h2>
                <span class="flex-1"></span>
                @foreach (['better' => 'ortalamadan iyi', 'around' => 'ortalama civarı', 'worse' => 'ortalamadan kötü', 'none' => 'yok, fırsat'] as $tone => $label)
                    <span class="flex items-center gap-1.5 text-xs text-gray-500"><span class="h-1.5 w-1.5 rounded-full {{ $cellTone[$tone] }}"></span>{{ $label }}</span>
                @endforeach
            </div>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[64rem] text-sm" data-testid="brand-scorecard-table">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs font-normal text-gray-500 dark:border-gray-800">
                            <th class="py-2 pr-3 font-normal">Hizmet</th><th class="px-3 py-2 font-normal">Web sitesi</th><th class="px-3 py-2 font-normal">Google Ads</th><th class="px-3 py-2 font-normal">Meta</th><th class="px-3 py-2 font-normal">İşletme Profili</th><th class="py-2 pl-3 text-right font-normal">Yarıştaki yeri</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                        @foreach ($card['rows'] as $row)
                            @php
                                $w = $row['web'];
                                $cells = [
                                    [$w['state'], $w['pages'] === 0 ? 'Sayfa yok' : $num($w['clicks']).' arama tıkı', $w['pages'] === 0 ? 'fırsat' : $w['pages'].' sayfa, '.$num($w['key_events'], 1).' dönüşüm'],
                                    $adCell($row['google_ads']),
                                    $adCell($row['meta']),
                                    match ($row['gbp']['state']) {
                                        'na' => ['na', 'Profil verisi yok', ''],
                                        'around' => ['around', 'Profilde listeli', ''],
                                        default => ['none', 'Profilde yok', 'fırsat'],
                                    },
                                ];
                            @endphp
                            <tr class="align-top hover:bg-gray-50 dark:hover:bg-white/[0.03]" wire:key="scorecard-{{ $row['id'] }}">
                                <td class="py-2.5 pr-3">
                                    <p class="font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</p>
                                    <p class="text-xs text-gray-500">{{ $row['main'] ? 'ana hizmet' : 'ikincil hizmet' }}</p>
                                </td>
                                @foreach ($cells as [$tone, $line, $sub])
                                    <td class="px-3 py-2.5">
                                        <p @class(['flex items-center gap-1.5 text-sm tabular-nums', 'text-gray-900 dark:text-white' => $tone !== 'none' && $tone !== 'na', 'text-gray-500' => $tone === 'none' || $tone === 'na'])><span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $cellTone[$tone] }}" aria-hidden="true"></span>{{ $line }}</p>
                                        @if ($sub !== '')<p class="pl-3 text-xs text-gray-500">{{ $sub }}</p>@endif
                                    </td>
                                @endforeach
                                <td class="py-2.5 pl-3 text-right">
                                    @if ($row['rank'])
                                        <p class="text-base font-semibold tabular-nums text-gray-900 dark:text-white">{{ $row['rank']['rank'] }}<span class="text-xs font-normal text-gray-500"> / {{ $row['rank']['of'] }} marka</span></p>
                                    @else
                                        <span class="text-xs text-gray-500">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-gray-500">Ortalama: aynı hizmeti yapan diğer markaların aynı sonuç türündeki son 30 gün ortancası. Google Ads hizmeti anahtar kelimeden, Meta hizmeti kampanya ile hizmet eşleşmesinden gelir. Yarıştaki yeri: maliyete göre sıra (1 = en ucuz). Sayılar her sabah yenilenir.</p>
        </section>
    @endif
</div>
