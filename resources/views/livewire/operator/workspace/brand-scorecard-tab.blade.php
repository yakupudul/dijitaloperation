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
    {{-- Marka Düzeni 2.3: the start numbers beside the last 30 full days. Per channel; channels are never added up. --}}
    <section data-brand-baseline>
        <div class="section-head items-center">
            <h2 class="section-title">Başlangıç sayıları</h2>
            @if ($base === null)
                <button type="button" wire:click="takeBaseline" wire:loading.attr="disabled" class="btn ml-auto" data-brand-baseline-take>Başlangıcı al</button>
            @endif
        </div>
        @if ($base === null)
            <p class="-mt-2 max-w-[70ch] text-[13px] text-gray-500 dark:text-gray-400">Bu markanın başlangıcı henüz alınmadı. Alınınca son 30 tam günün sayıları buraya sabitlenir, sonraki her ay bununla kıyaslanır.</p>
        @else
            <p class="-mt-2 mb-3 text-[13px] text-gray-500 dark:text-gray-400">Başlangıç {{ $base['baseline']->period_from->locale('tr')->isoFormat('D MMMM') }} - {{ $base['baseline']->period_to->locale('tr')->isoFormat('D MMMM YYYY') }}. Şimdi {{ $base['from']->locale('tr')->isoFormat('D MMMM') }} - {{ $base['to']->locale('tr')->isoFormat('D MMMM') }} (tam günler).@if (! $base['measured']) Bu markada talep sayılacak dönüşüm tanımlı değil; talep satırları ölçüm düzelince dolar.@endif</p>
            <div class="card">
                <div class="grid grid-cols-[minmax(0,1.6fr)_repeat(3,minmax(0,1fr))] gap-3 border-b border-gray-100 px-4 py-2.5 text-[12px] font-medium text-gray-500 dark:border-gray-800 dark:text-gray-400">
                    <span></span><span class="text-right">Başlangıç</span><span class="text-right">Şimdi</span><span class="text-right">Değişim</span>
                </div>
                @foreach ($base['lines'] as $line)
                    <div class="grid grid-cols-[minmax(0,1.6fr)_repeat(3,minmax(0,1fr))] gap-3 border-b border-gray-100 px-4 py-2.5 text-sm last:border-b-0 dark:border-gray-800" data-baseline-line>
                        <span class="truncate text-gray-900 dark:text-white">{{ $line['label'] }}</span>
                        <span class="text-right tabular-nums text-gray-500">{{ $num($line['start']) }}</span>
                        <span class="text-right tabular-nums text-gray-900 dark:text-white">{{ $num($line['now']) }}</span>
                        <span @class(['text-right tabular-nums', 'text-success-700 dark:text-success-400' => ($line['change'] ?? 0) > 0, 'text-error-700 dark:text-error-400' => ($line['change'] ?? 0) < 0, 'text-gray-400' => ($line['change'] ?? 0) == 0])>{{ $line['change'] === null ? '—' : $pct($line['change']) }}</span>
                    </div>
                @endforeach
                @foreach ($base['services'] as $service)
                    <div class="grid grid-cols-[minmax(0,1.6fr)_repeat(3,minmax(0,1fr))] gap-3 border-b border-gray-100 px-4 py-2.5 text-sm last:border-b-0 dark:border-gray-800" data-baseline-service>
                        <span class="min-w-0 truncate text-gray-900 dark:text-white">{{ $service['name'] }} <span class="text-[13px] text-gray-400">{{ $service['pages'] > 0 ? $service['pages'].' sayfa, tık' : 'sayfası yok' }}</span></span>
                        <span class="text-right tabular-nums text-gray-500">{{ $num($service['start']) }}</span>
                        <span class="text-right tabular-nums text-gray-900 dark:text-white">{{ $num($service['now']) }}</span>
                        @php $change = $service['start'] > 0 ? round(($service['now'] - $service['start']) / $service['start'] * 100) : null; @endphp
                        <span @class(['text-right tabular-nums', 'text-success-700 dark:text-success-400' => ($change ?? 0) > 0, 'text-error-700 dark:text-error-400' => ($change ?? 0) < 0, 'text-gray-400' => ($change ?? 0) == 0])>{{ $change === null ? '—' : $pct($change) }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    @if (! $card['ready'])
        <div class="card"><div class="card-row"><span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span><div class="min-w-0 flex-1"><p class="text-sm font-medium text-gray-900 dark:text-white">Hizmet yok</p><p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">Markanın onaylı hizmeti yok. Hizmetler Ayarlar › Marka bilgileri’nden eklenir.</p></div><a href="{{ route('operator.brand', ['brand' => $brand->id, 'tab' => 'ayarlar']) }}" wire:navigate class="btn">Hizmet ekle</a></div></div>
    @else
        @if ($card['notes'] !== [])
            <div class="grid gap-3 lg:grid-cols-3" data-testid="brand-scorecard-notes">
                @foreach ($card['notes'] as $note)
                    <section class="card flex flex-col p-4">
                        <p class="text-xs font-medium {{ $noteTone[$note['kind']] ?? 'text-gray-500' }}">{{ $note['kind'] }}</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $note['title'] }}</p>
                        <p class="mt-1 flex-1 text-[13px] leading-relaxed text-gray-500 dark:text-gray-400">{{ $note['body'] }}</p>
                        @php
                            $href = match ($note['tab']) {
                                'meta' => $metaAssetId ? route('operator.meta.overview', ['assetId' => $metaAssetId]) : null,
                                'google_ads' => $googleAdsAssetId ? route('operator.google-ads.overview', ['assetId' => $googleAdsAssetId]) : null,
                                default => route('operator.brand', ['brand' => $brand->id, 'tab' => $note['tab']]),
                            };
                        @endphp
                        @if ($href)<a href="{{ $href }}" wire:navigate class="btn mt-3 self-start">{{ $note['cta'] }}</a>@endif
                    </section>
                @endforeach
            </div>
        @endif

        <section>
            <div class="section-head gap-x-4">
                <h2 class="section-title">Hizmet başına dört kanal <span class="section-count">son 30 gün</span></h2>
                <span class="flex-1"></span>
                @foreach (['better' => 'ortalamadan iyi', 'around' => 'ortalama civarı', 'worse' => 'ortalamadan kötü', 'none' => 'yok, fırsat'] as $tone => $label)
                    <span class="flex items-center gap-1.5 text-xs text-gray-500"><span class="h-1.5 w-1.5 rounded-full {{ $cellTone[$tone] }}"></span>{{ $label }}</span>
                @endforeach
            </div>
            <div class="card relative overflow-x-auto">
                <table class="data-table min-w-[64rem]" data-testid="brand-scorecard-table">
                    <thead>
                        <tr>
                            <th>Hizmet</th><th>Web sitesi</th><th>Google Ads</th><th>Meta</th><th>İşletme Profili</th><th class="text-right!">Yarıştaki yeri</th>
                        </tr>
                    </thead>
                    <tbody>
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
                            <tr class="[&>td]:align-top" wire:key="scorecard-{{ $row['id'] }}">
                                <td>
                                    <p class="font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</p>
                                    <p class="text-xs text-gray-500">{{ $row['main'] ? 'ana hizmet' : 'ikincil hizmet' }}</p>
                                </td>
                                @foreach ($cells as [$tone, $line, $sub])
                                    <td>
                                        <p @class(['flex items-center gap-1.5 text-sm tabular-nums', 'text-gray-900 dark:text-white' => $tone !== 'none' && $tone !== 'na', 'text-gray-500' => $tone === 'none' || $tone === 'na'])><span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $cellTone[$tone] }}" aria-hidden="true"></span>{{ $line }}</p>
                                        @if ($sub !== '')<p class="pl-3 text-xs text-gray-500">{{ $sub }}</p>@endif
                                    </td>
                                @endforeach
                                <td class="text-right">
                                    @if ($row['rank'])
                                        <p class="text-sm font-semibold tabular-nums text-gray-900 dark:text-white">{{ $row['rank']['rank'] }}<span class="text-xs font-normal text-gray-500"> / {{ $row['rank']['of'] }} marka</span></p>
                                    @else
                                        <span class="text-xs text-gray-500">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-[13px] text-gray-500">Ortalama: aynı hizmeti yapan diğer markaların aynı sonuç türündeki son 30 gün ortancası. Google Ads hizmeti anahtar kelimeden, Meta hizmeti kampanya ile hizmet eşleşmesinden gelir. Yarıştaki yeri: maliyete göre sıra (1 = en ucuz). Sayılar her sabah yenilenir.</p>
        </section>
    @endif
</div>
