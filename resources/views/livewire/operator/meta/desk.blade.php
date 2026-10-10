@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
    $panel = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
    $select = 'mt-1 block rounded-lg border-gray-300 py-1.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $num = fn ($v, int $d = 0) => $v === null ? '—' : number_format((float) $v, $d, ',', '.');
    $currency = count($desk['kpis']['currencies'] ?? []) === 1 ? $desk['kpis']['currencies'][0] : '';
    $money = fn ($v, string $cur = '') => $v === null ? '—' : number_format((float) $v, 2, ',', '.').' '.($cur !== '' ? $cur : $currency);
    $short = fn ($v) => (float) $v >= 1000000 ? $num((float) $v / 1000000, 2).' mn' : ((float) $v >= 10000 ? $num((float) $v / 1000, 1).' bin' : $num($v));
    $pct = fn ($v) => $v === null ? '—' : (($v > 0 ? '+' : '').number_format((float) $v, 0, ',', '.').'%');
    $tones = ['high_cost' => 'bad', 'cost_up' => 'bad', 'stopped' => 'bad', 'disapproved' => 'bad', 'no_service' => 'warn', 'fatigue' => 'warn'];
    $toneClass = ['bad' => 'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30',
        'warn' => 'bg-amber-50 text-amber-800 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30'];
    $todayText = function (array $r) use ($pct, $money): string {
        return match (true) {
            in_array('high_cost', $r['alerts'], true) => 'Sonuç başı '.$money($r['cpr'], $r['currency']).'; hizmet ortalamasından '.$pct($r['diff']).' pahalı.',
            in_array('stopped', $r['alerts'], true) => 'Yayında görünüyor ama son günlerde harcama yok.',
            in_array('disapproved', $r['alerts'], true) => 'Reddedilen reklamı var.',
            default => 'Sonuç başı maliyet önceki döneme göre arttı.',
        };
    };
@endphp

<div class="space-y-5">
    @include('livewire.operator.winners.partials.nav', ['current' => 'operator.meta-desk'])
    <div class="space-y-1">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Meta masası</h1>
            <span class="flex-1"></span>
            <a href="{{ route('operator.meta-strategy') }}" wire:navigate class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600">Strateji öner</a>
        </div>
        @if ($desk['ready'] && $desk['kpis']['campaigns'] > 0)
            <p class="text-sm text-gray-500">Tüm markaların Meta kampanyaları tek yerde · {{ $desk['kpis']['brands'] }} marka · {{ $desk['kpis']['campaigns'] }} kampanya · {{ $desk['kpis']['live'] }} yayında · son 30 gün, {{ \Carbon\CarbonImmutable::parse($desk['period_end'])->format('d.m.Y') }}’e kadar</p>
        @endif
    </div>

    @if (! $desk['ready'] || $desk['kpis']['campaigns'] === 0)
        <p class="{{ $card }} text-sm text-gray-500" data-testid="meta-desk-empty">Henüz hesaplanmış kampanya yok. Sayılar her sabah, eksik kaldıysa en geç bir saat içinde Meta verisinden hesaplanır.</p>
    @else
        @php $k = $desk['kpis']; @endphp
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5" data-testid="meta-desk-kpis">
            @foreach ([['Harcama · 30 gün', $currency !== '' ? $short($k['spend']).' '.$currency : $short($k['spend'])], ['Form', $num($k['leads'])], ['Mesaj', $num($k['messages'])], ['Hizmetsiz kampanya', $num($k['no_service'])], ['Bugün bakılacaklar', $num($k['today'])]] as [$label, $value])
                <section class="{{ $card }}">
                    <p class="text-xs font-medium text-gray-500">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $value }}</p>
                </section>
            @endforeach
        </div>

        <div class="grid gap-4 xl:grid-cols-5">
            <section class="{{ $panel }} xl:col-span-3" data-testid="meta-desk-today">
                <h2 class="border-b border-gray-100 px-4 py-3 font-semibold text-gray-900 dark:border-gray-700 dark:text-white">Bugün bakılacaklar</h2>
                @forelse ($desk['today'] as $i => $r)
                    <div class="flex flex-wrap items-start gap-3 border-b border-gray-100 px-4 py-3 last:border-0 dark:border-gray-700">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold text-gray-600 dark:bg-white/[0.06] dark:text-gray-300">{{ $i + 1 }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm"><span class="font-semibold text-gray-900 dark:text-white">{{ $r['brand'] }}</span> <span class="text-gray-400">·</span> <span class="text-gray-700 dark:text-gray-300">{{ $r['name'] }}</span></p>
                            <p class="text-xs text-gray-500">{{ $todayText($r) }}</p>
                        </div>
                        <a href="{{ route('operator.meta.campaign', ['assetId' => $r['asset_id'], 'campaignId' => $r['campaign_id']]) }}" wire:navigate class="text-xs font-semibold text-brand-600 hover:underline">Kampanyayı aç →</a>
                    </div>
                @empty
                    <p class="px-4 py-4 text-sm text-gray-500">Bugün acil bakılacak kampanya yok.</p>
                @endforelse
            </section>

            <section class="{{ $panel }} overflow-x-auto xl:col-span-2" data-testid="meta-desk-averages">
                <h2 class="border-b border-gray-100 px-4 py-3 font-semibold text-gray-900 dark:border-gray-700 dark:text-white">Hizmet ortalamaları · tüm markalar</h2>
                @if ($desk['averages'] === [])
                    <p class="px-4 py-4 text-sm text-gray-500">Ortalama için aynı hizmette en az iki markanın en az {{ \App\Services\Ads\AdServiceStats::MIN_RESULTS }} sonucu olmalı.</p>
                @else
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-xs text-gray-500"><th class="px-4 py-2 font-medium">Hizmet</th><th class="px-3 py-2 text-right font-medium">Marka</th><th class="px-3 py-2 text-right font-medium">Form başı</th><th class="px-3 py-2 text-right font-medium">Mesaj başı</th><th class="px-3 py-2 font-medium">Lider</th></tr></thead>
                        <tbody class="divide-y divide-gray-100 tabular-nums dark:divide-gray-700">
                            @foreach ($desk['averages'] as $a)
                                <tr class="text-gray-700 dark:text-gray-300">
                                    <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">{{ $a['name'] }}</td><td class="px-3 py-2 text-right">{{ $a['brands'] }}</td>
                                    <td class="px-3 py-2 text-right">{{ $money($a['leads']) }}</td><td class="px-3 py-2 text-right">{{ $money($a['messages']) }}</td>
                                    <td class="px-3 py-2 text-xs">{{ $a['leader'] ?? '—' }}@if ($a['leader']) <span class="text-gray-400">· {{ $money($a['leader_cost']) }}</span>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        </div>

        <div class="flex flex-wrap items-end gap-3" data-testid="meta-desk-filters">
            <label class="text-xs text-gray-500">Sektör
                <select wire:model.live="sector" class="{{ $select }}"><option value="">Tüm sektörler</option>@foreach ($desk['options']['sectors'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
            </label>
            <label class="text-xs text-gray-500">Hizmet
                <select wire:model.live="service" class="{{ $select }}"><option value="">Tüm hizmetler</option>@foreach ($desk['options']['services'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach<option value="none">Hizmet atanmamış</option></select>
            </label>
            <label class="text-xs text-gray-500">Marka
                <select wire:model.live="brand" class="{{ $select }}"><option value="">Tüm markalar</option>@foreach ($desk['options']['brands'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                @if (filled($brand))<a href="{{ route('operator.brand', ['brand' => (int) $brand]) }}" wire:navigate class="self-center text-sm font-semibold text-brand-600 hover:underline" data-brand-link>Marka sayfası →</a>@endif
            </label>
            <label class="text-xs text-gray-500">Uyarı
                <select wire:model.live="alert" class="{{ $select }}"><option value="">Hepsi</option>@foreach ($alerts as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
            </label>
            <label class="text-xs text-gray-500">Sırala
                <select wire:model.live="sort" class="{{ $select }}">@foreach ($sorts as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
            </label>
            <span class="flex-1"></span>
            <span class="text-sm text-gray-500">{{ $desk['total'] }} kampanya</span>
        </div>

        <section class="{{ $panel }} overflow-hidden" data-testid="meta-desk-campaigns">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[72rem] text-sm">
                    <thead class="bg-gray-50 dark:bg-white/[0.02]">
                        <tr class="text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-2.5">Marka</th><th class="px-3 py-2.5">Kampanya</th><th class="px-3 py-2.5">Hizmet</th><th class="px-3 py-2.5 text-right">Harcama</th>
                            <th class="px-3 py-2.5 text-right">Sonuç</th><th class="px-3 py-2.5 text-right">Sonuç başı</th><th class="px-3 py-2.5 text-right">Hizmet ort.</th><th class="px-3 py-2.5 text-right">Fark</th><th class="px-3 py-2.5">Uyarı</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 tabular-nums dark:divide-gray-700">
                        @forelse ($desk['rows'] as $r)
                            <tr class="align-top text-gray-700 dark:text-gray-300" wire:key="desk-{{ $r['asset_id'] }}-{{ $r['campaign_id'] }}">
                                <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white">{{ $r['brand'] }}</td>
                                <td class="px-3 py-2.5">
                                    <span @class(['mr-1.5 inline-block h-2 w-2 rounded-full', 'bg-emerald-500' => $r['status'] === 'live', 'bg-gray-400' => $r['status'] !== 'live'])></span>
                                    <a href="{{ route('operator.meta.campaign', ['assetId' => $r['asset_id'], 'campaignId' => $r['campaign_id']]) }}" wire:navigate class="hover:text-brand-600">{{ $r['name'] }}</a>
                                </td>
                                <td class="px-3 py-2.5">
                                    @if ($r['services'] === [])
                                        <a href="{{ route('operator.meta.assign', ['assetId' => $r['asset_id']]) }}" wire:navigate class="text-xs font-semibold text-rose-600 hover:underline">+ Hizmet ata</a>
                                    @else
                                        <span class="text-xs">{{ implode(', ', array_column($r['services'], 'name')) }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 text-right">{{ $money($r['spend'], $r['currency']) }}</td>
                                <td class="px-3 py-2.5 text-right">{{ $num($r['results']) }} <span class="text-xs text-gray-500">{{ \App\Services\Meta\MetaCampaignBoard::TYPES[$r['type']][0] ?? '' }}</span></td>
                                <td class="px-3 py-2.5 text-right font-medium text-gray-900 dark:text-white">{{ $money($r['cpr'], $r['currency']) }}</td>
                                <td class="px-3 py-2.5 text-right">{{ $r['average'] ? $money($r['average']['median'], $r['currency']) : '—' }}</td>
                                <td @class(['px-3 py-2.5 text-right font-semibold', 'text-rose-600' => ($r['diff'] ?? 0) >= 15, 'text-emerald-600' => ($r['diff'] ?? 0) <= -15, 'text-gray-500' => $r['diff'] === null || abs($r['diff']) < 15])>{{ $pct($r['diff']) }}</td>
                                <td class="px-3 py-2.5">
                                    <div class="flex flex-wrap gap-1">@foreach ($r['alerts'] as $key)<span class="rounded-md px-2 py-0.5 text-xs font-semibold {{ $toneClass[$tones[$key] ?? 'warn'] }}">{{ $alerts[$key] ?? $key }}</span>@endforeach</div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-4 py-6 text-center text-sm text-gray-500">Bu süzgeçte kampanya yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="border-t border-gray-100 px-4 py-3 text-xs text-gray-500 dark:border-gray-700">Hizmet ort.: aynı hizmet ve sonuç türünde diğer markaların son 30 gün ortancası (markanın şehrinde en az {{ \App\Services\Ads\AdServiceStats::MIN_CITY_BRANDS }} marka varsa o şehir). Sonuç türleri karıştırılmaz.</p>
        </section>
    @endif
</div>
