@php
    $num = fn ($v, int $d = 0): string => $v === null ? '—' : number_format((float) $v, $d, ',', '.');
    $money = fn ($v, ?string $cur = null): string => $v === null ? '—' : number_format((float) $v, 0, ',', '.').(filled($cur) ? ' '.$cur : '');
    /** Delta under a number: spend change is information (gray); conversions up = green, down = red; lower-is-better flips it. */
    $change = function (?float $d, bool $lowerIsBetter = false, bool $neutral = false): string {
        if ($d === null) {
            return '';
        }
        $good = $lowerIsBetter ? $d <= 0 : $d >= 0;
        $chipTone = $neutral ? 'chip-flat' : ($good ? 'chip-up' : 'chip-down');

        return '<span class="chip mt-1 '.$chipTone.'">'.($d >= 0 ? '▲ ' : '▼ ').number_format(abs($d), 0, ',', '.').'%</span>';
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
    @include('livewire.operator.google-ads.desk.header', ['active' => 'operator.google-ads-desk', 'sub' => $sub, 'highlight' => true,
        'intro' => 'Her Google Ads hesabının son 30 günü, alarmları ve eksik ayarları. Bir satıra tıklayınca son 6 ay, en çok harcayan kampanyalar ve MoxDOP’un hesapta yaptıkları açılır.'])

    {{-- 1. What waits on the operator: the losing accounts, then the approvals per tab (canlı panel, 2026-10-20). --}}
    @php
        $problemGroups = collect($problems)->groupBy('brand');
        $waitingTotals = ['terms' => 0, 'changes' => 0, 'ads' => 0];
        foreach ($rows as $r) {
            foreach ($waitingTotals as $k => $v) {
                $waitingTotals[$k] += (int) ($r['waiting'][$k] ?? 0);
            }
        }
        $todo = array_values(array_filter([
            ['terms', 'negatif kelime', 'Onay bekliyor; para boşa giden aramaları keser', 'Arama terimleri', 'operator.google-ads-terms', '<path d="M4 6h16M7 12h10M10 18h4"/>'],
            ['changes', 'ayar önerisi', 'Bütçe, ağ, konum ve ölçüm ayarları', 'Ayarlar ve bütçe', 'operator.google-ads-changes', '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>'],
            ['ads', 'reklam taslağı', 'Onaylanan taslak hesabın Editor dosyasına girer', 'Reklamlar', 'operator.google-ads-ads', '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>'],
        ], fn (array $t): bool => $waitingTotals[$t[0]] > 0));
        $lossBrands = $problemGroups->keys();
        $firstAccent = $problems === [];
    @endphp
    @if ($problems !== [] || $todo !== [])
        @php $cardCount = ($problems !== [] ? 1 : 0) + count($todo); @endphp
        <section @class(['grid grid-cols-2 gap-3', 'lg:grid-cols-3' => $cardCount === 3, 'lg:grid-cols-4' => $cardCount === 4]) data-google-ads-todo>
            @if ($problems !== [])
                <div class="soft-card soft-red col-span-2 sm:col-span-1">
                    <span class="soft-icon text-error-600 dark:text-error-400" aria-hidden="true"><svg class="h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5M12 16.5v.5"/><path d="M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg></span>
                    <p class="soft-number">{{ $lossBrands->count() }} marka</p>
                    <p class="text-[15px] font-semibold text-gray-900 dark:text-white">Para ya da veri kaybediyor</p>
                    <div class="flex items-center">
                        @foreach ($problemGroups->take(5) as $brandName => $items)
                            <span class="-ml-2 inline-flex h-[30px] w-[30px] items-center justify-center overflow-hidden rounded-full border-2 border-white bg-white shadow-card first:ml-0 dark:border-gray-900" title="{{ $brandName }}">
                                <x-ta.brand-avatar :name="(string) $brandName" :logo="$logos[$items->first()['asset_id']] ?? null" size="sm" class="border-0!" />
                            </span>
                        @endforeach
                        @if ($problemGroups->count() > 5)<span class="ml-2 text-xs text-gray-600 dark:text-gray-300">+{{ $problemGroups->count() - 5 }}</span>@endif
                    </div>
                    <div class="mt-auto pt-1"><a href="#bakilacaklar" class="btn">Hesaplara bak</a></div>
                </div>
            @endif
            @foreach ($todo as [$key, $unit, $line, $label, $routeName, $icon])
                <div @class(['soft-card', 'soft-accent' => $firstAccent && $loop->first, 'col-span-2 sm:col-span-1' => $loop->last && count($todo) % 2 === 1])>
                    <span class="soft-icon" aria-hidden="true"><svg class="h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">{!! $icon !!}</svg></span>
                    <p class="soft-number">{{ $waitingTotals[$key] }}</p>
                    <p class="text-[15px] font-semibold text-gray-900 dark:text-white">{{ \Illuminate\Support\Str::ucfirst($unit) }} bekliyor</p>
                    <p class="hidden text-[13px] text-gray-600 sm:block dark:text-gray-300">{{ $line }}</p>
                    <div class="mt-auto pt-1"><a href="{{ route($routeName, array_filter(['marka' => $brand])) }}" wire:navigate @class(['btn-primary h-9! px-3.5!' => $firstAccent && $loop->first, 'btn' => ! ($firstAccent && $loop->first)])>{{ $label }}</a></div>
                </div>
            @endforeach
        </section>
    @endif

    {{-- 2. The month in four numbers: one dark panel. --}}
    @php
        $cpa = $totals['conversions'] > 0 ? $totals['cost'] / $totals['conversions'] : null;
        $prevCpa = $totals['prev_conversions'] > 0 ? $totals['prev_cost'] / $totals['prev_conversions'] : null;
        $quiet = count($rows) - $live;
        $chipOf = fn (array $d): string => match ($d[1]) { 'positive' => 'chip-up', 'negative' => 'chip-down', default => 'chip-flat bg-white/10! text-white/80!' };
        $arrowOf = fn (?string $t): string => $t === null ? '' : (str_starts_with($t, '+') ? '▲ '.ltrim($t, '+') : (str_starts_with($t, '−') ? '▼ '.ltrim($t, '−') : $t));
    @endphp
    <section data-testid="google-ads-kpis" title="Değişim önceki 30 güne göre. Dönüşüm, Google Ads’in saydığı dönüşümdür (tıklama dahil); gerçek randevu ve satış Ölçüm sekmesinde.">
        <div class="section-head">
            <h2 class="section-title">Son 30 gün</h2>
            <span class="text-[13px] text-gray-500">tüm hesaplar, önceki 30 güne göre</span>
        </div>
        <div class="hero-panel grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['Harcama', $money($totals['cost'], $currency), $kpiDelta($totalChange($totals['cost'], $totals['prev_cost']), false, true), 'Önceki '.$money($totals['prev_cost'], $currency), false],
                ['Dönüşüm', $num($totals['conversions']), $kpiDelta($totalChange($totals['conversions'], $totals['prev_conversions'])), 'Önceki '.$num($totals['prev_conversions']), true],
                ['Dönüşüm başı', $money($cpa, $currency), $kpiDelta(\App\Services\GoogleAds\Desk\GoogleAdsDesk::change($cpa, $prevCpa), true), 'Önceki '.$money($prevCpa, $currency), false],
                ['Yayındaki hesap', $live.' / '.count($rows), [null, 'neutral'], $quiet > 0 ? $quiet.' hesaptan veri gelmiyor' : 'Hepsinden veri geliyor', false],
            ] as [$label, $value, $delta, $hint, $accent])
                <div @class(['hero-tile', 'hero-tile-accent' => $accent])>
                    <span class="hero-label">{{ $label }}</span>
                    <p class="hero-value text-[24px]! sm:text-[30px]!">{{ $value }}</p>
                    <p @class(['mt-2 flex flex-wrap items-center gap-2 text-xs', 'text-white/60' => ! $accent, 'opacity-70' => $accent])>@if ($delta[0] !== null)<span class="chip {{ $chipOf($delta) }}">{{ $arrowOf($delta[0]) }}</span>@endif {{ $hint }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- 3. What loses money or leaves an account blind now. --}}
    @if ($problems !== [])
        <section id="bakilacaklar" class="scroll-mt-20" data-testid="google-ads-problems">
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
                            <x-ta.empty-state icon="none" title="Operasyonel markaya bağlı Google Ads hesabı yok." size="sm">
                                <a href="{{ route('operator.integrations') }}" wire:navigate class="btn">Entegrasyonlardan bağla</a>
                            </x-ta.empty-state>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
