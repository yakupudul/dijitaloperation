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
            <h1 class="page-title">Meta masası</h1>
            @if ($sub)<p class="page-sub">{{ $sub }}</p>@endif
        </header>

        @if (! $hasData)
            <div class="card"><x-ta.empty-state icon="none" title="Henüz hesaplanmış kampanya yok." message="Sayılar her sabah, eksik kaldıysa en geç bir saat içinde Meta verisinden hesaplanır." data-testid="meta-desk-empty" /></div>
        @else
            @php $k = $desk['kpis']; @endphp

            {{-- 1. What costs more than its service, stopped or got rejected. --}}
            <section data-testid="meta-desk-today">
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

            {{-- 2. The month in four numbers. --}}
            <section>
                <div class="section-head">
                    <h2 class="section-title">Son 30 gün</h2>
                    <span class="text-[13px] text-gray-500">tüm markalar</span>
                </div>
                <div class="metric-strip grid-cols-2 lg:grid-cols-4" data-testid="meta-desk-kpis">
                    <x-ta.metric-card label="Harcama" :value="$currency !== '' ? $short($k['spend']).' '.$currency : $short($k['spend'])" :hint="$k['campaigns'].' kampanya'" />
                    <x-ta.metric-card label="Form" :value="$num($k['leads'])" hint="Anında form sonuçları" />
                    <x-ta.metric-card label="Mesaj" :value="$num($k['messages'])" hint="WhatsApp ve Messenger" />
                    <x-ta.metric-card label="Hizmetsiz kampanya" :value="$num($k['no_service'])" hint="Hizmet atanınca ortalamaya girer" />
                </div>
            </section>

            {{-- 3. Every campaign against its service average. --}}
            <section data-testid="meta-desk-campaigns">
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
                                    <td @class(['whitespace-nowrap text-right text-[13px]', 'text-error-700 dark:text-error-400' => ($r['diff'] ?? 0) >= 15, 'text-success-700 dark:text-success-400' => ($r['diff'] ?? 0) <= -15, 'text-gray-500' => $r['diff'] === null || abs($r['diff']) < 15])>{{ $pct($r['diff']) }}</td>
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
