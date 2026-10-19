@php
    $num = fn ($v, int $d = 0): string => $v === null ? '—' : number_format((float) $v, $d, ',', '.');
    $money = fn ($v, ?string $cur = null): string => $v === null ? '—' : number_format((float) $v, 0, ',', '.').(filled($cur) ? ' '.$cur : '');
    /** Delta chip: spend change is information (gray); conversions up = green, down = red; lower-is-better flips it. */
    $change = function (?float $d, bool $lowerIsBetter = false, bool $neutral = false): string {
        if ($d === null) {
            return '';
        }
        $good = $lowerIsBetter ? $d <= 0 : $d >= 0;
        $tone = $neutral ? 'text-gray-500 dark:text-gray-400' : ($good ? 'text-success-600 dark:text-success-400' : 'text-error-600 dark:text-error-400');

        return '<span class="whitespace-nowrap '.$tone.'">'.($d >= 0 ? '▲ %' : '▼ %').number_format(abs($d), 0, ',', '.').'</span>';
    };
    /** Same rule for the KPI cards: [text, metric-card tone]. */
    $kpiDelta = function (?float $d, bool $lowerIsBetter = false, bool $neutral = false): array {
        if ($d === null) {
            return [null, 'neutral'];
        }
        $good = $lowerIsBetter ? $d <= 0 : $d >= 0;

        return [($d >= 0 ? '▲ %' : '▼ %').number_format(abs($d), 0, ',', '.'), $neutral ? 'neutral' : ($good ? 'positive' : 'negative')];
    };
    $stateLabel = ['ok' => null, 'late' => 'Veri gelmiyor', 'idle' => 'Harcama yok (30+ gün)', 'nodata' => 'Henüz veri yok', 'unbound' => 'Hesap bağlı değil'];
    $sharedKeys = array_keys($shared);
    $totalChange = fn (float $now, float $before): ?float => \App\Services\GoogleAds\Desk\GoogleAdsDesk::change($now ?: null, $before ?: null);
@endphp
<div class="space-y-5 dark:text-gray-200" data-google-ads-desk>
    @include('livewire.operator.google-ads.desk.header', ['active' => 'operator.google-ads-desk',
        'intro' => 'Her Google Ads hesabının son 30 günü, alarmları ve eksik ayarları. Bir satıra tıklayınca son 6 ay, en çok harcayan kampanyalar ve MoxDOP’un hesapta yaptıkları açılır.'])

    @if ($problems !== [])
        @php $problemGroups = collect($problems)->groupBy('brand'); @endphp
        <section class="surface p-4" data-testid="google-ads-problems">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Şimdi bakılacaklar · {{ count($problems) }} <span class="font-normal text-gray-500">· {{ $problemGroups->count() }} marka</span></h2>
            <ul class="mt-1 divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($problemGroups as $brandName => $items)
                    @php $assetIds = $items->pluck('asset_id')->unique()->values(); $worst = $items->contains(fn (array $p): bool => $p['severity'] === 'critical') ? 'critical' : 'look'; @endphp
                    <x-ta.alarm-row :tone="$worst" :title="$items->pluck('title')->unique()->implode(' · ')" :brand="(string) $brandName"
                        :href="$assetIds->count() === 1 ? route('operator.google-ads.overview', ['assetId' => $assetIds->first()]) : null" action="Hesabı aç">
                        <ul class="space-y-0.5 text-gray-600 dark:text-gray-400">
                            @foreach ($items as $p)
                                <li class="break-words"><span class="font-medium text-gray-700 dark:text-gray-300">{{ $p['title'] }}:</span> {{ $p['message'] }}
                                    @if ($assetIds->count() > 1)<a href="{{ route('operator.google-ads.overview', ['assetId' => $p['asset_id']]) }}" wire:navigate class="link-neutral whitespace-nowrap">Hesabı aç →</a>@endif
                                </li>
                            @endforeach
                        </ul>
                    </x-ta.alarm-row>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="grid grid-cols-2 gap-3 lg:grid-cols-4" data-testid="google-ads-kpis">
        @php $cpa = $totals['conversions'] > 0 ? $totals['cost'] / $totals['conversions'] : null; $prevCpa = $totals['prev_conversions'] > 0 ? $totals['prev_cost'] / $totals['prev_conversions'] : null; @endphp
        @foreach ([
            ['Harcama · 30 gün', $money($totals['cost'], $currency), $kpiDelta($totalChange($totals['cost'], $totals['prev_cost']), false, true)],
            ['Dönüşüm', $num($totals['conversions']), $kpiDelta($totalChange($totals['conversions'], $totals['prev_conversions']))],
            ['Dönüşüm başı', $money($cpa, $currency), $kpiDelta(\App\Services\GoogleAds\Desk\GoogleAdsDesk::change($cpa, $prevCpa), true)],
            ['Yayındaki hesap', $live.' / '.count($rows), [null, 'neutral']],
        ] as [$label, $value, [$deltaText, $deltaTone]])
            <x-ta.metric-card :label="$label" :value="$value" :delta="$deltaText" :tone="$deltaTone" class="min-w-0" />
        @endforeach
    </section>
    <p class="-mt-3 text-[11px] text-gray-500">Değişim önceki 30 güne göre. Dönüşüm, Google Ads’in saydığı dönüşümdür (tıklama dahil); gerçek randevu ve satış Ölçüm sekmesinde.</p>

    @if ($shared !== [])
        <section class="surface p-4" data-testid="google-ads-shared-gaps">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Hesapların çoğunda ortak eksik</h2>
            <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">Bunlar satırlarda tekrar gösterilmiyor.</p>
            <ul class="mt-1.5 flex flex-wrap gap-2">
                @foreach ($shared as $gap)
                    <li class="break-words rounded-full bg-gray-100 px-2.5 py-1 text-xs text-gray-700 dark:bg-white/5 dark:text-gray-300">{{ $gap['label'] }} · {{ $gap['missing'] }} hesapta</li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="surface overflow-x-auto">
        <table class="w-full min-w-[920px] text-sm" data-testid="google-ads-accounts">
            <thead class="text-left text-xs text-gray-500">
                <tr class="border-b border-gray-100 dark:border-gray-700">
                    <th class="px-4 py-2 font-medium">Hesap</th>
                    <th class="px-2 py-2 text-right font-medium">Harcama</th>
                    <th class="px-2 py-2 text-right font-medium">Dönüşüm</th>
                    <th class="px-2 py-2 text-right font-medium">Dönüşüm başı</th>
                    <th class="px-3 py-2 font-medium">Eksik ayarlar</th>
                    <th class="px-3 py-2 font-medium">Bekleyen</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($rows as $row)
                    @php $asset = $row['asset']; $c = $row['current']; $label = $stateLabel[$row['state']]; @endphp
                    <tr wire:key="acc-{{ $asset->id }}" class="cursor-pointer align-top hover:bg-gray-50 dark:hover:bg-white/[0.03]" wire:click="toggle({{ $asset->id }})">
                        <td class="px-4 py-2">
                            <span class="flex items-center gap-2"><x-ta.brand-avatar :name="$row['brand']" size="xs" /><span class="min-w-0 break-words font-medium text-gray-900 dark:text-white">{{ $row['brand'] }}</span></span>
                            <span class="block text-xs text-gray-500">{{ $asset->name }}@if ($row['last_date']) · son veri {{ \Carbon\CarbonImmutable::parse($row['last_date'])->format('d.m') }}@endif</span>
                            @if ($label)<x-ta.pill :tone="in_array($row['state'], ['late', 'unbound'], true) ? 'critical' : 'info'" class="mt-0.5">{{ $label }}</x-ta.pill>@endif
                            @foreach ($row['alerts'] as $alert)
                                <x-ta.pill :tone="in_array($alert['severity'], ['critical', 'high'], true) ? 'critical' : 'look'" title="{{ $alert['message'] }}" class="mt-0.5">{{ $alert['title'] }}</x-ta.pill>
                            @endforeach
                        </td>
                        <td class="whitespace-nowrap px-2 py-2 text-right tabular-nums">{{ $money($c['cost'] ?? null, $row['currency']) }}<span class="block text-[11px]">{!! $change($row['change']['cost'], false, true) !!}</span></td>
                        <td class="whitespace-nowrap px-2 py-2 text-right tabular-nums">{{ $num($c['conversions'] ?? null, 1) }}<span class="block text-[11px]">{!! $change($row['change']['conversions']) !!}</span></td>
                        <td class="whitespace-nowrap px-2 py-2 text-right tabular-nums">{{ $money($c['cpa'] ?? null, $row['currency']) }}<span class="block text-[11px]">{!! $change($row['change']['cpa'], true) !!}</span></td>
                        <td class="min-w-[180px] px-3 py-2">
                            <span class="flex flex-wrap gap-1">
                                @if ($row['checks'] === null)
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] text-gray-500 dark:bg-white/[0.06]" title="Sistem kontrolleri her sabah 07:07’de çalışır">? Kontrol henüz yok</span>
                                @else
                                    @forelse (array_filter($row['checks'], fn (array $ch): bool => ! in_array($ch['id'], $sharedKeys, true)) as $check)
                                        <a href="{{ route(in_array($check['id'], \App\Services\GoogleAds\Desk\GoogleAdsDesk::MEASUREMENT_CHECKS, true) ? 'operator.google-ads-measurement' : 'operator.google-ads-changes', array_filter(['marka' => $asset->brand_id])) }}" wire:navigate x-on:click.stop
                                            title="{{ $check['reason'] }}" class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-700 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10">{{ $check['label'] }}</a>
                                    @empty
                                        <x-ta.pill tone="ok">{{ $shared !== [] ? 'Özel eksik yok' : 'Tamam' }}</x-ta.pill>
                                    @endforelse
                                @endif
                            </span>
                        </td>
                        <td class="px-3 py-2 text-xs">
                            @php $w = $row['waiting']; @endphp
                            @if (array_sum($w) === 0)
                                <span class="text-gray-400">—</span>
                            @else
                                <span class="flex flex-col gap-0.5">
                                    @if ($w['terms'] > 0)<a href="{{ route('operator.google-ads-terms', ['marka' => $asset->brand_id]) }}" wire:navigate x-on:click.stop class="link-neutral whitespace-nowrap">{{ $w['terms'] }} negatif</a>@endif
                                    @if ($w['changes'] > 0)<a href="{{ route('operator.google-ads-changes', ['marka' => $asset->brand_id]) }}" wire:navigate x-on:click.stop class="link-neutral whitespace-nowrap">{{ $w['changes'] }} ayar</a>@endif
                                    @if ($w['ads'] > 0)<a href="{{ route('operator.google-ads-ads', ['marka' => $asset->brand_id]) }}" wire:navigate x-on:click.stop class="link-neutral whitespace-nowrap">{{ $w['ads'] }} taslak</a>@endif
                                </span>
                            @endif
                        </td>
                    </tr>
                    @if ($open === (int) $asset->id && $detail !== null)
                        <tr wire:key="detail-{{ $asset->id }}">
                            <td colspan="6" class="bg-gray-50/60 px-4 py-4 dark:bg-white/[0.02]">
                                <div class="grid gap-4 lg:grid-cols-3">
                                    <div>
                                        <h3 class="mb-1 text-xs font-semibold uppercase text-gray-500">Son 6 ay</h3>
                                        <table class="w-full text-xs">
                                            <thead class="text-gray-500"><tr><th class="text-left font-medium">Ay</th><th class="text-right font-medium">Harcama</th><th class="text-right font-medium">Dönüşüm</th><th class="text-right font-medium">Başı</th></tr></thead>
                                            <tbody>
                                                @forelse ($detail['months'] as $m)
                                                    <tr><td>{{ \Illuminate\Support\Carbon::parse($m['month'].'-01')->locale('tr')->translatedFormat('M Y') }}</td><td class="text-right tabular-nums">{{ $money($m['cost']) }}</td><td class="text-right tabular-nums">{{ $num($m['conversions'], 1) }}</td><td class="text-right tabular-nums">{{ $m['conversions'] > 0 ? $money($m['cost'] / $m['conversions']) : '—' }}</td></tr>
                                                @empty
                                                    <tr><td colspan="4" class="text-gray-500">Veri yok.</td></tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                    <div>
                                        <h3 class="mb-1 text-xs font-semibold uppercase text-gray-500">En çok harcayan kampanyalar · 30 gün</h3>
                                        @forelse ($detail['campaigns'] as $cmp)
                                            <div class="flex justify-between gap-2 text-xs">
                                                <span class="truncate" title="{{ $cmp['label'] }}">{{ $cmp['label'] }}</span>
                                                <span class="shrink-0 whitespace-nowrap tabular-nums text-gray-600 dark:text-gray-300">{{ $money($cmp['cost']) }} · {{ $num($cmp['conversions'], 1) }} dön.</span>
                                            </div>
                                        @empty
                                            <p class="text-xs text-gray-500">Kampanya verisi yok.</p>
                                        @endforelse
                                    </div>
                                    <div>
                                        <h3 class="mb-1 text-xs font-semibold uppercase text-gray-500">MoxDOP’un hesapta yaptıkları</h3>
                                        @forelse ($detail['work'] as $item)
                                            <p class="text-xs"><span class="tabular-nums text-gray-500">{{ $item['at'] }}</span> · {{ $item['label'] }} · <span @class(['text-success-600' => in_array($item['status'], ['succeeded', 'partial'], true), 'text-error-600' => $item['status'] === 'failed', 'text-gray-500' => ! in_array($item['status'], ['succeeded', 'partial', 'failed'], true)])>{{ $item['status_label'] }}</span></p>
                                        @empty
                                            <p class="text-xs text-gray-500">Bu hesaba MoxDOP’tan henüz bir şey yazılmadı.</p>
                                        @endforelse
                                        <a href="{{ route('operator.google-ads.overview', ['assetId' => $asset->id]) }}" wire:navigate class="link-neutral mt-2 inline-block text-xs">Hesabın tüm ekranı →</a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="6" class="px-4 py-5 text-sm text-gray-500">Operasyonel markaya bağlı Google Ads hesabı yok. <a href="{{ route('operator.integrations') }}" wire:navigate class="font-medium text-brand-600 hover:underline">Entegrasyonlardan bağla →</a></td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
