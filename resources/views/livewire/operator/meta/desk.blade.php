@php
    $select = 'h-9 w-full rounded-lg border border-gray-200 bg-white pl-3 pr-8 text-sm text-gray-900 sm:w-auto sm:max-w-[13rem] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $num = fn ($v, int $d = 0) => $v === null ? '—' : number_format((float) $v, $d, ',', '.');
    $currency = count($desk['kpis']['currencies'] ?? []) === 1 ? $desk['kpis']['currencies'][0] : '';
    $money = fn ($v, string $cur = '') => $v === null ? '—' : number_format((float) $v, 2, ',', '.').' '.($cur !== '' ? $cur : $currency);
    /** Spend reads in whole units; per-result costs keep kuruş. */
    $whole = fn ($v, string $cur = '') => $v === null ? '—' : number_format((float) $v, 0, ',', '.').' '.($cur !== '' ? $cur : $currency);
    $short = fn ($v) => (float) $v >= 1000000 ? $num((float) $v / 1000000, 2).' mn' : ((float) $v >= 10000 ? $num((float) $v / 1000, 1).' bin' : $num($v));
    $pct = fn ($v) => $v === null ? '—' : (($v > 0 ? '+' : '').number_format((float) $v, 0, ',', '.').'%');
    $tones = ['high_cost' => 'bad', 'cost_up' => 'bad', 'stopped' => 'bad', 'disapproved' => 'bad', 'no_service' => 'warn', 'fatigue' => 'warn'];
    $todayText = function (array $r) use ($pct, $money): string {
        return match (true) {
            in_array('high_cost', $r['alerts'], true) => 'Sonuç başı '.$money($r['cpr'], $r['currency']).'; hizmet ortalamasından '.$pct($r['diff']).' pahalı.',
            in_array('stopped', $r['alerts'], true) => 'Yayında görünüyor ama son günlerde harcama yok.',
            in_array('disapproved', $r['alerts'], true) => 'Reddedilen reklamı var.',
            default => 'Sonuç başı maliyet önceki döneme göre arttı.',
        };
    };
    $hasData = $desk['ready'] && $desk['kpis']['campaigns'] > 0;
    // The page's answer: how many campaigns need a look today, then the size of the board.
    $sub = ! $hasData ? null : ($desk['kpis']['today'] > 0 ? $desk['kpis']['today'].' kampanyaya bugün bakılmalı. ' : 'Bugün acil bakılacak kampanya yok. ')
        .$desk['kpis']['brands'].' marka, '.$desk['kpis']['campaigns'].' kampanya, '.$desk['kpis']['live'].' yayında; son 30 gün, '.\Carbon\CarbonImmutable::parse($desk['period_end'])->format('d.m.Y').'’e kadar.';
@endphp

<div class="min-w-0">
    @include('livewire.operator.winners.partials.nav', ['current' => 'operator.meta-desk'])

    <div class="space-y-10">
        <header class="min-w-0">
            <h1 class="page-title">Meta <span class="highlight">reklamları</span></h1>
            @if ($sub)<p class="page-sub">{{ $sub }}</p>@endif
            @if ($openWork > 0)<a href="{{ route('operator.repair', ['bolum' => 'diger', 'sekme' => 'meta']) }}" class="link-neutral text-sm" data-channel-open-work>Açık öneriler ({{ $openWork }})</a>@endif
        </header>

        @if (! $hasData)
            <div class="card"><x-ta.empty-state icon="none" title="Henüz hesaplanmış kampanya yok." message="Sayılar her sabah, eksik kaldıysa en geç bir saat içinde Meta verisinden hesaplanır." data-testid="meta-desk-empty" /></div>
        @else
            @php $k = $desk['kpis']; @endphp

            {{-- 1. What waits on the operator (canlı panel 2026-10-20): the losing campaigns and the ones without a service. --}}
            @php $todayBrands = collect($desk['today'])->unique('brand_id')->values(); @endphp
            @if ($k['today'] > 0 || $k['no_service'] > 0)
                <div @class(['grid gap-3', 'sm:grid-cols-2' => $k['today'] > 0 && $k['no_service'] > 0])>
                    @if ($k['today'] > 0)
                        <div class="soft-card soft-red">
                            <span class="soft-icon text-error-600 dark:text-error-400" aria-hidden="true"><svg class="h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5M12 16.5v.5"/><path d="M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg></span>
                            <p class="soft-number">{{ $k['today'] }} kampanya</p>
                            <p class="text-[15px] font-semibold text-gray-900 dark:text-white">Bugün bakılmalı</p>
                            <p class="text-[13px] text-gray-600 dark:text-gray-300">Hizmet ortalamasından pahalı, durmuş ya da reklamı reddedilmiş</p>
                            <div class="flex items-center">
                                @foreach ($todayBrands->take(5) as $r)
                                    <span class="-ml-2 inline-flex h-[30px] w-[30px] items-center justify-center overflow-hidden rounded-full border-2 border-white bg-white shadow-card first:ml-0 dark:border-gray-900" title="{{ $r['brand'] }}">
                                        <x-ta.brand-avatar :name="$r['brand']" size="sm" class="border-0!" />
                                    </span>
                                @endforeach
                            </div>
                            <div class="mt-auto pt-1"><a href="#bugun" class="btn">Listeye bak</a></div>
                        </div>
                    @endif
                    @if ($k['no_service'] > 0)
                        <div class="soft-card soft-amber">
                            <span class="soft-icon text-warning-600 dark:text-warning-400" aria-hidden="true"><svg class="h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg></span>
                            <p class="soft-number">{{ $num($k['no_service']) }} kampanya</p>
                            <p class="text-[15px] font-semibold text-gray-900 dark:text-white">Hizmetsiz kampanya</p>
                            <p class="text-[13px] text-gray-600 dark:text-gray-300">Hizmet atanınca ortalamaya girer ve karşılaştırılır</p>
                            <div class="mt-auto pt-1"><button type="button" wire:click="$set('service', 'none')" x-on:click="$nextTick(() => document.getElementById('kampanyalar')?.scrollIntoView())" class="btn">Kampanyaları göster</button></div>
                        </div>
                    @endif
                </div>
            @endif

            {{-- 2. The month in four numbers: one dark panel. --}}
            <section>
                <div class="section-head">
                    <h2 class="section-title">Son 30 gün</h2>
                    <span class="text-[13px] text-gray-500">tüm markalar</span>
                </div>
                <div class="hero-panel grid-cols-2 lg:grid-cols-4" data-testid="meta-desk-kpis">
                    <div class="hero-tile">
                        <span class="hero-label">Harcama</span>
                        <p class="hero-value text-[24px]! sm:text-[30px]!">{{ $currency !== '' ? $short($k['spend']).' '.$currency : $short($k['spend']) }}</p>
                        <p class="mt-2 text-xs text-white/60">{{ $k['campaigns'] }} kampanya</p>
                    </div>
                    <div class="hero-tile hero-tile-accent">
                        <span class="hero-label">Form</span>
                        <p class="hero-value text-[24px]! sm:text-[30px]!">{{ $num($k['leads']) }}</p>
                        <p class="mt-2 text-xs opacity-70">Anında form sonuçları</p>
                    </div>
                    <div class="hero-tile">
                        <span class="hero-label">Mesaj</span>
                        <p class="hero-value text-[24px]! sm:text-[30px]!">{{ $num($k['messages']) }}</p>
                        <p class="mt-2 text-xs text-white/60">WhatsApp ve Messenger</p>
                    </div>
                    <div class="hero-tile">
                        <span class="hero-label">Yayında</span>
                        <p class="hero-value text-[24px]! sm:text-[30px]!">{{ $k['live'] }} / {{ $k['campaigns'] }}</p>
                        <p class="mt-2 text-xs text-white/60">{{ $k['brands'] }} markada kampanya</p>
                    </div>
                </div>
            </section>

            {{-- 3. What costs more than its service, stopped or got rejected. --}}
            <section id="bugun" class="scroll-mt-20" data-testid="meta-desk-today">
                <div class="section-head">
                    <h2 class="section-title">Bugün bakılacaklar <span class="section-count">{{ $k['today'] }}</span></h2>
                    @if ($k['today'] > count($desk['today']))<span class="text-[13px] text-gray-500">en çok harcayan {{ count($desk['today']) }}</span>@endif
                </div>
                @if ($desk['today'] === [])
                    <div class="card"><x-ta.empty-state size="sm" title="Bugün acil bakılacak kampanya yok." /></div>
                @else
                    <ul class="card">
                        @foreach ($desk['today'] as $r)
                            <x-ta.alarm-row :tone="array_intersect(['high_cost', 'stopped'], $r['alerts']) !== [] ? 'critical' : 'look'" :title="$r['name']" :brand="$r['brand']" :message="$todayText($r)"
                                :href="route('operator.meta.campaign', ['assetId' => $r['asset_id'], 'campaignId' => $r['campaign_id']])" action="Kampanyayı aç" class="break-words" />
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- 3. Every campaign against its service average. --}}
            <section id="kampanyalar" class="scroll-mt-20" data-testid="meta-desk-campaigns">
                <div class="section-head">
                    <h2 class="section-title">Kampanyalar <span class="section-count">{{ $desk['total'] }}</span></h2>
                </div>
                <div class="mb-3 flex flex-wrap items-center gap-2" data-testid="meta-desk-filters">
                    <label class="contents"><span class="sr-only">Sektör</span>
                        <select wire:model.live="sector" class="{{ $select }}"><option value="">Tüm sektörler</option>@foreach ($desk['options']['sectors'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    </label>
                    <label class="contents"><span class="sr-only">Hizmet</span>
                        <select wire:model.live="service" class="{{ $select }}"><option value="">Tüm hizmetler</option>@foreach ($desk['options']['services'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach<option value="none">Hizmet atanmamış</option></select>
                    </label>
                    <label class="contents"><span class="sr-only">Marka</span>
                        <select wire:model.live="brand" class="{{ $select }}"><option value="">Tüm markalar</option>@foreach ($desk['options']['brands'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    </label>
                    <label class="contents"><span class="sr-only">Uyarı</span>
                        <select wire:model.live="alert" class="{{ $select }}"><option value="">Tüm uyarılar</option>@foreach ($alerts as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                    </label>
                    <label class="contents"><span class="sr-only">Sırala</span>
                        <select wire:model.live="sort" class="{{ $select }}">@foreach ($sorts as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                    </label>
                    @if (filled($brand))<a href="{{ route('operator.brand', ['brand' => (int) $brand]) }}" wire:navigate class="btn-quiet" data-brand-link>Marka sayfası</a>@endif
                </div>
                <div class="card overflow-x-auto">
                    <table class="data-table min-w-[1000px]">
                        <thead>
                            <tr>
                                <th>Kampanya</th><th>Hizmet</th><th class="text-right!">Harcama</th>
                                <th class="text-right!">Sonuç</th><th class="text-right!">Sonuç başı</th><th class="text-right!" title="Aynı hizmet ve sonuç türünde diğer markaların son 30 gün ortancası (markanın şehrinde en az {{ \App\Services\Ads\AdServiceStats::MIN_CITY_BRANDS }} marka varsa o şehir). Sonuç türleri karıştırılmaz.">Hizmet ort.</th><th class="text-right!">Fark</th><th>Uyarı</th>
                            </tr>
                        </thead>
                        <tbody class="tabular-nums">
                            @forelse ($desk['rows'] as $r)
                                <tr wire:key="desk-{{ $r['asset_id'] }}-{{ $r['campaign_id'] }}">
                                    <td class="w-[28%] max-w-0">
                                        <span class="flex min-w-0 items-center gap-2.5">
                                            <x-ta.brand-avatar :name="$r['brand']" size="sm" />
                                            <span class="min-w-0 flex-1">
                                                <a href="{{ route('operator.meta.campaign', ['assetId' => $r['asset_id'], 'campaignId' => $r['campaign_id']]) }}" wire:navigate class="block truncate font-medium text-gray-900 hover:underline dark:text-white" title="{{ $r['name'] }}">{{ $r['name'] }}</a>
                                                <span class="flex min-w-0 items-center gap-1.5 text-xs text-gray-500">
                                                    <span @class(['inline-block h-1.5 w-1.5 shrink-0 rounded-full', 'bg-success-500' => $r['status'] === 'live', 'bg-gray-300 dark:bg-gray-600' => $r['status'] !== 'live']) title="{{ $r['status'] === 'live' ? 'Yayında' : 'Yayında değil' }}"></span>
                                                    <span class="truncate" title="{{ $r['brand'] }}">{{ $r['brand'] }}</span>
                                                </span>
                                            </span>
                                        </span>
                                    </td>
                                    <td class="text-[13px]">
                                        @if ($r['services'] === [])
                                            <span class="flex flex-wrap items-center gap-x-2 gap-y-0.5"><x-ta.pill tone="look">Hizmet yok</x-ta.pill><a href="{{ route('operator.meta.assign', ['assetId' => $r['asset_id']]) }}" wire:navigate class="link-neutral whitespace-nowrap text-xs" data-assign-service>Hizmet ata</a></span>
                                        @else
                                            {{ implode(', ', array_column($r['services'], 'name')) }}
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap text-right text-gray-600 dark:text-gray-400">{{ $whole($r['spend'], $r['currency']) }}</td>
                                    <td class="whitespace-nowrap text-right">{{ $num($r['results']) }} <span class="text-xs text-gray-500">{{ \App\Services\Meta\MetaCampaignBoard::TYPES[$r['type']][0] ?? '' }}</span></td>
                                    <td class="whitespace-nowrap text-right font-medium text-gray-900 dark:text-white">{{ $money($r['cpr'], $r['currency']) }}</td>
                                    <td class="whitespace-nowrap text-right text-gray-600 dark:text-gray-400">{{ $r['average'] ? $money($r['average']['median'], $r['currency']) : '—' }}</td>
                                    <td class="whitespace-nowrap text-right">@if ($r['diff'] !== null)<span @class(['chip', 'chip-down' => $r['diff'] >= 15, 'chip-up' => $r['diff'] <= -15, 'chip-flat' => abs($r['diff']) < 15])>{{ $r['diff'] > 0 ? '▲ ' : ($r['diff'] < 0 ? '▼ ' : '') }}{{ number_format(abs((float) $r['diff']), 0, ',', '.') }}%</span>@else<span class="text-gray-400">—</span>@endif</td>
                                    <td>
                                        {{-- "Hizmet yok" is already the Hizmet column's pill; not repeated here. --}}
                                        <span class="flex flex-wrap gap-x-3 gap-y-1">@foreach (array_diff($r['alerts'], ['no_service']) as $key)<x-ta.pill :tone="($tones[$key] ?? 'warn') === 'bad' ? 'critical' : 'look'">{{ $alerts[$key] ?? '' }}</x-ta.pill>@endforeach</span>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="p-0"><x-ta.empty-state icon="none" size="sm" title="Bu süzgeçte kampanya yok." /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- 4. The yardstick: what a form or a message costs per service across brands. --}}
            <section data-testid="meta-desk-averages">
                <div class="section-head">
                    <h2 class="section-title">Hizmet ortalamaları <span class="section-count">{{ count($desk['averages']) }}</span></h2>
                    <span class="text-[13px] text-gray-500">tüm markalar, ortanca</span>
                </div>
                @if ($desk['averages'] === [])
                    <div class="card"><x-ta.empty-state icon="none" size="sm" title="Henüz ortalama yok" message="Ortalama için aynı hizmette en az iki markanın en az {{ \App\Services\Ads\AdServiceStats::MIN_RESULTS }} sonucu olmalı." /></div>
                @else
                    <div class="card overflow-x-auto">
                        <table class="data-table min-w-[640px]">
                            <thead><tr><th>Hizmet</th><th class="text-right!">Marka</th><th class="text-right!">Form başı</th><th class="text-right!">Mesaj başı</th><th>En ucuz</th></tr></thead>
                            <tbody class="tabular-nums">
                                @foreach ($desk['averages'] as $a)
                                    <tr>
                                        <td class="font-medium text-gray-900 dark:text-white">{{ $a['name'] }}</td>
                                        <td class="text-right text-gray-600 dark:text-gray-400">{{ $a['brands'] }}</td>
                                        <td class="whitespace-nowrap text-right">{{ $money($a['leads']) }}</td>
                                        <td class="whitespace-nowrap text-right">{{ $money($a['messages']) }}</td>
                                        <td class="text-[13px]">{{ $a['leader'] ?? '—' }}@if ($a['leader']) <span class="text-gray-500">{{ $money($a['leader_cost']) }}</span>@endif</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif
    </div>
</div>
