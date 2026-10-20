@php
    $num = fn ($v, int $d = 0): string => $v === null ? '—' : number_format((float) $v, $d, ',', '.');
    $money = fn ($v, ?string $cur = null): string => $v === null ? '—' : number_format((float) $v, 0, ',', '.').(filled($cur) ? ' '.$cur : '');
    /** Delta under a number: spend change is information (gray); conversions up = green, down = red; lower-is-better flips it. */
    $change = function (?float $d, bool $lowerIsBetter = false, bool $neutral = false): string {
        if ($d === null) {
            return '';
        }
        $good = $lowerIsBetter ? $d <= 0 : $d >= 0;
        $tone = $neutral ? 'text-gray-500 dark:text-gray-400' : ($good ? 'text-success-600 dark:text-success-400' : 'text-error-600 dark:text-error-400');

        return '<span class="whitespace-nowrap '.$tone.'">'.($d >= 0 ? '+' : '−').number_format(abs($d), 0, ',', '.').'%</span>';
    };
    /** Same rule for the metric cells: [text, metric-card tone]. */
    $kpiDelta = function (?float $d, bool $lowerIsBetter = false, bool $neutral = false): array {
        if ($d === null) {
            return [null, 'neutral'];
        }
        $good = $lowerIsBetter ? $d <= 0 : $d >= 0;

        return [($d >= 0 ? '+' : '−').number_format(abs($d), 0, ',', '.').'%', $neutral ? 'neutral' : ($good ? 'positive' : 'negative')];
    };
    $stateLabel = ['ok' => null, 'late' => 'Veri gelmiyor', 'idle' => 'Harcama yok (30+ gün)', 'nodata' => 'Henüz veri yok', 'unbound' => 'Hesap bağlı değil'];
    $sharedKeys = array_keys($shared);
    $totalChange = fn (float $now, float $before): ?float => \App\Services\GoogleAds\Desk\GoogleAdsDesk::change($now ?: null, $before ?: null);
    $logos = collect($rows)->mapWithKeys(fn (array $r): array => [(int) $r['asset']->id => $r['asset']->brand?->logo_url])->all();
    $sub = count($rows) === 0 ? null : count($rows).' hesap, son 30 gün. '.($problems === [] ? 'Para ya da veri kaybeden hesap yok.' : collect($problems)->pluck('brand')->unique()->count().' markada bakılacak bir şey var.');
@endphp
<div class="space-y-10 dark:text-gray-200" data-google-ads-desk>
    @include('livewire.operator.google-ads.desk.header', ['active' => 'operator.google-ads-desk', 'sub' => $sub,
        'intro' => 'Her Google Ads hesabının son 30 günü, alarmları ve eksik ayarları. Bir satıra tıklayınca son 6 ay, en çok harcayan kampanyalar ve MoxDOP’un hesapta yaptıkları açılır.'])

    {{-- 1. What loses money or leaves an account blind now. --}}
    @if ($problems !== [])
        @php $problemGroups = collect($problems)->groupBy('brand'); @endphp
        <section data-testid="google-ads-problems">
            <div class="section-head">
                <h2 class="section-title">Şimdi bakılacaklar <span class="section-count">{{ count($problems) }}</span></h2>
                @if ($problemGroups->count() !== count($problems))<span class="text-[13px] text-gray-500">{{ $problemGroups->count() }} marka</span>@endif
            </div>
            <ul class="card">
                @foreach ($problemGroups as $brandName => $items)
                    @php
                        $assetIds = $items->pluck('asset_id')->unique()->values();
                        $worst = $items->contains(fn (array $p): bool => $p['severity'] === 'critical') ? 'critical' : 'look';
                        $single = $items->count() === 1;
                    @endphp
                    <x-ta.alarm-row :tone="$worst" :title="$items->pluck('title')->unique()->implode(', ')" :brand="(string) $brandName" :logo="$logos[$assetIds->first()] ?? null"
                        :message="$single ? $items->first()['message'] : null"
                        :href="$assetIds->count() === 1 ? route('operator.google-ads.overview', ['assetId' => $assetIds->first()]) : null" action="Hesabı aç">
                        @unless ($single)
                            <ul class="space-y-0.5 text-gray-500 dark:text-gray-400">
                                @foreach ($items as $p)
                                    <li class="break-words"><span class="font-medium text-gray-700 dark:text-gray-300">{{ $p['title'] }}:</span> {{ $p['message'] }}
                                        @if ($assetIds->count() > 1)<a href="{{ route('operator.google-ads.overview', ['assetId' => $p['asset_id']]) }}" wire:navigate class="link-neutral whitespace-nowrap">Hesabı aç</a>@endif
                                    </li>
                                @endforeach
                            </ul>
                        @endunless
                    </x-ta.alarm-row>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- 2. The month in four numbers. --}}
    @php
        $cpa = $totals['conversions'] > 0 ? $totals['cost'] / $totals['conversions'] : null;
        $prevCpa = $totals['prev_conversions'] > 0 ? $totals['prev_cost'] / $totals['prev_conversions'] : null;
        $quiet = count($rows) - $live;
    @endphp
    <section data-testid="google-ads-kpis" title="Değişim önceki 30 güne göre. Dönüşüm, Google Ads’in saydığı dönüşümdür (tıklama dahil); gerçek randevu ve satış Ölçüm sekmesinde.">
        <div class="section-head">
            <h2 class="section-title">Son 30 gün</h2>
            <span class="text-[13px] text-gray-500">tüm hesaplar, önceki 30 güne göre</span>
        </div>
        <div class="metric-strip grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['Harcama', $money($totals['cost'], $currency), $kpiDelta($totalChange($totals['cost'], $totals['prev_cost']), false, true), 'Önceki 30 gün '.$money($totals['prev_cost'], $currency)],
                ['Dönüşüm', $num($totals['conversions']), $kpiDelta($totalChange($totals['conversions'], $totals['prev_conversions'])), 'Önceki 30 gün '.$num($totals['prev_conversions'])],
                ['Dönüşüm başı', $money($cpa, $currency), $kpiDelta(\App\Services\GoogleAds\Desk\GoogleAdsDesk::change($cpa, $prevCpa), true), 'Önceki 30 gün '.$money($prevCpa, $currency)],
                ['Yayındaki hesap', $live.' / '.count($rows), [null, 'neutral'], $quiet > 0 ? $quiet.' hesaptan veri gelmiyor' : 'Hepsinden veri geliyor'],
            ] as [$label, $value, [$deltaText, $deltaTone], $hint])
                <x-ta.metric-card :label="$label" :value="$value" :delta="$deltaText" :tone="$deltaTone" :hint="$hint" />
            @endforeach
        </div>
    </section>

    @if ($shared !== [])
        <section data-testid="google-ads-shared-gaps">
            <div class="section-head">
                <h2 class="section-title" title="Bunlar hesap satırlarında tekrar gösterilmiyor.">Hesapların çoğunda ortak eksik <span class="section-count">{{ count($shared) }}</span></h2>
            </div>
            <ul class="card">
                @foreach ($shared as $gap)
                    <li class="card-row flex-wrap justify-between py-3 sm:flex-nowrap">
                        <span class="min-w-0 break-words text-sm text-gray-900 dark:text-white">{{ $gap['label'] }}</span>
                        <span class="shrink-0 text-[13px] tabular-nums text-gray-500">{{ $gap['missing'] }} hesapta</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- 3. Every account; a row opens its last six months. --}}
    <section>
        <div class="section-head">
            <h2 class="section-title">Hesaplar <span class="section-count">{{ count($rows) }}</span></h2>
            <span class="text-[13px] text-gray-500">Satıra tıklayınca son 6 ay açılır</span>
        </div>
        <div class="card overflow-x-auto">
            <table class="data-table min-w-[860px]" data-testid="google-ads-accounts">
                <thead>
                    <tr>
                        <th>Hesap</th>
                        <th class="text-right!">Harcama</th>
                        <th class="text-right!">Dönüşüm</th>
                        <th class="text-right!">Dönüşüm başı</th>
                        <th>Eksik ayarlar</th>
                        <th>Bekleyen</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php $asset = $row['asset']; $c = $row['current']; $label = $stateLabel[$row['state']]; @endphp
                        <tr wire:key="acc-{{ $asset->id }}" @class(['cursor-pointer align-top', '[&>td]:bg-gray-50 dark:[&>td]:bg-white/[0.03]' => $open === (int) $asset->id]) wire:click="toggle({{ $asset->id }})">
                            <td class="w-[34%] max-w-0">
                                <span class="flex min-w-0 items-start gap-2.5">
                                    <x-ta.brand-avatar :name="$row['brand']" :logo="$asset->brand?->logo_url" class="mt-0.5" />
                                    <span class="min-w-0 flex-1">
                                        <span class="block break-words font-medium text-gray-900 dark:text-white">{{ $row['brand'] }}</span>
                                        <span class="block truncate text-xs text-gray-500" title="{{ $asset->name }}">@if ($row['last_date'])Son veri {{ \Carbon\CarbonImmutable::parse($row['last_date'])->format('d.m') }}, @endif{{ $asset->name }}</span>
                                        @if ($label || $row['alerts'] !== [])
                                            <span class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5">
                                                @if ($label)<x-ta.pill :tone="in_array($row['state'], ['late', 'unbound'], true) ? 'critical' : 'info'">{{ $label }}</x-ta.pill>@endif
                                                @foreach ($row['alerts'] as $alert)
                                                    <x-ta.pill :tone="in_array($alert['severity'], ['critical', 'high'], true) ? 'critical' : 'look'" title="{{ $alert['message'] }}">{{ $alert['title'] }}</x-ta.pill>
                                                @endforeach
                                            </span>
                                        @endif
                                    </span>
                                </span>
                            </td>
                            <td class="whitespace-nowrap text-right tabular-nums text-gray-900 dark:text-white">{{ $money($c['cost'] ?? null, $row['currency']) }}<span class="block text-xs">{!! $change($row['change']['cost'], false, true) !!}</span></td>
                            <td class="whitespace-nowrap text-right tabular-nums text-gray-900 dark:text-white">{{ $num($c['conversions'] ?? null, 1) }}<span class="block text-xs">{!! $change($row['change']['conversions']) !!}</span></td>
                            <td class="whitespace-nowrap text-right tabular-nums text-gray-900 dark:text-white">{{ $money($c['cpa'] ?? null, $row['currency']) }}<span class="block text-xs">{!! $change($row['change']['cpa'], true) !!}</span></td>
                            <td class="min-w-[170px]">
                                <span class="flex flex-wrap gap-x-3 gap-y-1">
                                    @if ($row['checks'] === null)
                                        <span class="text-xs text-gray-500" title="Sistem kontrolleri her sabah 07:07’de çalışır">Kontrol henüz yok</span>
                                    @else
                                        @forelse (array_filter($row['checks'], fn (array $ch): bool => ! in_array($ch['id'], $sharedKeys, true)) as $check)
                                            <a href="{{ route(in_array($check['id'], \App\Services\GoogleAds\Desk\GoogleAdsDesk::MEASUREMENT_CHECKS, true) ? 'operator.google-ads-measurement' : 'operator.google-ads-changes', array_filter(['marka' => $asset->brand_id])) }}" wire:navigate x-on:click.stop
                                                title="{{ $check['reason'] }}" class="text-[13px] text-gray-700 underline decoration-gray-300 underline-offset-2 hover:text-gray-900 hover:decoration-gray-900 dark:text-gray-300 dark:decoration-gray-600">{{ $check['label'] }}</a>
                                        @empty
                                            <x-ta.pill tone="ok">{{ $shared !== [] ? 'Özel eksik yok' : 'Tamam' }}</x-ta.pill>
                                        @endforelse
                                    @endif
                                </span>
                            </td>
                            <td class="text-[13px]">
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
                                <td colspan="6" class="bg-gray-50 px-4 py-5 dark:bg-white/[0.02]">
                                    <div class="grid gap-8 lg:grid-cols-3">
                                        <div class="min-w-0">
                                            <h3 class="mb-2 text-[13px] font-medium text-gray-900 dark:text-white">Son 6 ay</h3>
                                            {{-- A grid, not a table: rules of the outer data-table would reach a nested one. --}}
                                            <div class="grid grid-cols-[minmax(0,1fr)_auto_auto_auto] gap-x-4 gap-y-0.5 text-[13px] tabular-nums">
                                                <span class="text-gray-500">Ay</span><span class="text-right text-gray-500">Harcama</span><span class="text-right text-gray-500">Dönüşüm</span><span class="text-right text-gray-500">Başı</span>
                                                @forelse ($detail['months'] as $m)
                                                    <span>{{ \Illuminate\Support\Carbon::parse($m['month'].'-01')->locale('tr')->translatedFormat('M Y') }}</span><span class="text-right">{{ $money($m['cost']) }}</span><span class="text-right">{{ $num($m['conversions'], 1) }}</span><span class="text-right">{{ $m['conversions'] > 0 ? $money($m['cost'] / $m['conversions']) : '—' }}</span>
                                                @empty
                                                    <span class="col-span-4 text-gray-500">Veri yok.</span>
                                                @endforelse
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <h3 class="mb-2 text-[13px] font-medium text-gray-900 dark:text-white">En çok harcayan kampanyalar <span class="font-normal text-gray-500">30 gün</span></h3>
                                            @forelse ($detail['campaigns'] as $cmp)
                                                <div class="flex justify-between gap-2 py-0.5 text-[13px]">
                                                    <span class="truncate" title="{{ $cmp['label'] }}">{{ $cmp['label'] }}</span>
                                                    <span class="shrink-0 whitespace-nowrap tabular-nums text-gray-500 dark:text-gray-400">{{ $money($cmp['cost']) }}, {{ $num($cmp['conversions'], 1) }} dön.</span>
                                                </div>
                                            @empty
                                                <p class="text-[13px] text-gray-500">Kampanya verisi yok.</p>
                                            @endforelse
                                        </div>
                                        <div class="min-w-0">
                                            <h3 class="mb-2 text-[13px] font-medium text-gray-900 dark:text-white">MoxDOP’un hesapta yaptıkları</h3>
                                            @forelse ($detail['work'] as $item)
                                                <p class="flex flex-wrap gap-x-2 py-0.5 text-[13px]"><span class="tabular-nums text-gray-500">{{ $item['at'] }}</span> <span>{{ $item['label'] }}</span> <span @class(['text-success-700 dark:text-success-400' => in_array($item['status'], ['succeeded', 'partial'], true), 'text-error-600 dark:text-error-400' => $item['status'] === 'failed', 'text-gray-500' => ! in_array($item['status'], ['succeeded', 'partial', 'failed'], true)])>{{ $item['status_label'] }}</span></p>
                                            @empty
                                                <p class="text-[13px] text-gray-500">Bu hesaba MoxDOP’tan henüz bir şey yazılmadı.</p>
                                            @endforelse
                                            <a href="{{ route('operator.google-ads.overview', ['assetId' => $asset->id]) }}" wire:navigate class="btn mt-3">Hesabın tüm ekranı</a>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="6" class="p-0">
                            <x-ta.empty-state title="Operasyonel markaya bağlı Google Ads hesabı yok." size="sm">
                                <a href="{{ route('operator.integrations') }}" wire:navigate class="btn">Entegrasyonlardan bağla</a>
                            </x-ta.empty-state>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
