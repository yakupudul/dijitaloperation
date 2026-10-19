@php
    $card = 'surface p-4';
    $panel = 'surface';
    $select = 'mt-1 block rounded-lg border-gray-300 py-1.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $num = fn ($v, int $d = 0) => $v === null ? '—' : number_format((float) $v, $d, ',', '.');
    $currency = count($desk['kpis']['currencies'] ?? []) === 1 ? $desk['kpis']['currencies'][0] : '';
    $money = fn ($v, string $cur = '') => $v === null ? '—' : number_format((float) $v, 2, ',', '.').' '.($cur !== '' ? $cur : $currency);
    $short = fn ($v) => (float) $v >= 1000000 ? $num((float) $v / 1000000, 2).' mn' : ((float) $v >= 10000 ? $num((float) $v / 1000, 1).' bin' : $num($v));
    $pct = fn ($v) => $v === null ? '—' : (($v > 0 ? '+' : '').number_format((float) $v, 0, ',', '.').'%');
    $tones = ['high_cost' => 'bad', 'cost_up' => 'bad', 'stopped' => 'bad', 'disapproved' => 'bad', 'no_service' => 'warn', 'fatigue' => 'warn'];
    $toneClass = ['bad' => 'bg-error-50 text-error-700 ring-1 ring-inset ring-error-200 dark:bg-error-500/10 dark:text-error-300 dark:ring-error-500/30',
        'warn' => 'bg-warning-50 text-warning-800 ring-1 ring-inset ring-warning-200 dark:bg-warning-500/10 dark:text-warning-300 dark:ring-warning-500/30'];
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
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Meta masası</h1>
            @if ($desk['ready'] && $desk['kpis']['campaigns'] > 0)
                <p class="mt-1 text-sm text-gray-500">Tüm markaların Meta kampanyaları tek yerde · {{ $desk['kpis']['brands'] }} marka · {{ $desk['kpis']['campaigns'] }} kampanya · {{ $desk['kpis']['live'] }} yayında · son 30 gün, {{ \Carbon\CarbonImmutable::parse($desk['period_end'])->format('d.m.Y') }}’e kadar</p>
            @else
                <p class="mt-1 text-sm text-gray-500">Tüm markaların Meta kampanyaları tek yerde.</p>
            @endif
        </div>
        <x-ta.button :href="route('operator.meta-strategy')" wire:navigate variant="primary" size="md" class="w-full sm:w-auto">Strateji öner</x-ta.button>
    </div>
    @include('livewire.operator.winners.partials.nav', ['current' => 'operator.meta-desk'])

    @if (! $desk['ready'] || $desk['kpis']['campaigns'] === 0)
        <x-ta.empty-state title="Henüz hesaplanmış kampanya yok." message="Sayılar her sabah, eksik kaldıysa en geç bir saat içinde Meta verisinden hesaplanır." data-testid="meta-desk-empty" />
    @else
        @php $k = $desk['kpis']; @endphp
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-5" data-testid="meta-desk-kpis">
            @foreach ([['Harcama · 30 gün', $currency !== '' ? $short($k['spend']).' '.$currency : $short($k['spend'])], ['Form', $num($k['leads'])], ['Mesaj', $num($k['messages'])], ['Hizmetsiz kampanya', $num($k['no_service'])], ['Bugün bakılacaklar', $num($k['today'])]] as [$label, $value])
                <x-ta.metric-card :label="$label" :value="$value" class="min-w-0" />
            @endforeach
        </div>

        <div class="grid gap-4 xl:grid-cols-5">
            <section class="{{ $panel }} xl:col-span-3" data-testid="meta-desk-today">
                <h2 class="border-b border-gray-100 px-4 py-3 font-semibold text-gray-900 dark:border-gray-800 dark:text-white">Bugün bakılacaklar</h2>
                @if ($desk['today'] === [])
                    <x-ta.empty-state size="sm" title="Bugün acil bakılacak kampanya yok." class="m-4" />
                @else
                    <ul class="divide-y divide-gray-100 px-3 dark:divide-gray-800">
                        @foreach ($desk['today'] as $r)
                            <x-ta.alarm-row :tone="array_intersect(['high_cost', 'stopped'], $r['alerts']) !== [] ? 'critical' : 'look'" :title="$r['name']" :brand="$r['brand']" :message="$todayText($r)"
                                :href="route('operator.meta.campaign', ['assetId' => $r['asset_id'], 'campaignId' => $r['campaign_id']])" action="Kampanyayı aç" class="break-words" />
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="{{ $panel }} overflow-x-auto xl:col-span-2" data-testid="meta-desk-averages">
                <h2 class="border-b border-gray-100 px-4 py-3 font-semibold text-gray-900 dark:border-gray-800 dark:text-white">Hizmet ortalamaları · tüm markalar</h2>
                @if ($desk['averages'] === [])
                    <x-ta.empty-state size="sm" title="Henüz ortalama yok" message="Ortalama için aynı hizmette en az iki markanın en az {{ \App\Services\Ads\AdServiceStats::MIN_RESULTS }} sonucu olmalı." class="m-4" />
                @else
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-xs text-gray-500"><th class="px-4 py-2 font-medium">Hizmet</th><th class="px-3 py-2 text-right font-medium">Marka</th><th class="px-3 py-2 text-right font-medium">Form başı</th><th class="px-3 py-2 text-right font-medium">Mesaj başı</th><th class="px-3 py-2 font-medium">Lider</th></tr></thead>
                        <tbody class="divide-y divide-gray-100 tabular-nums dark:divide-gray-700">
                            @foreach ($desk['averages'] as $a)
                                <tr class="text-gray-700 dark:text-gray-300">
                                    <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">{{ $a['name'] }}</td><td class="px-3 py-2 text-right">{{ $a['brands'] }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right">{{ $money($a['leads']) }}</td><td class="whitespace-nowrap px-3 py-2 text-right">{{ $money($a['messages']) }}</td>
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
                @if (filled($brand))<a href="{{ route('operator.brand', ['brand' => (int) $brand]) }}" wire:navigate class="link-neutral mt-1 block text-sm font-medium" data-brand-link>Marka sayfası →</a>@endif
            </label>
            <label class="text-xs text-gray-500">Uyarı
                <select wire:model.live="alert" class="{{ $select }}"><option value="">Hepsi</option>@foreach ($alerts as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
            </label>
            <label class="text-xs text-gray-500">Sırala
                <select wire:model.live="sort" class="{{ $select }}">@foreach ($sorts as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
            </label>
            <span class="hidden flex-1 sm:block"></span>
            <span class="text-sm tabular-nums text-gray-500">{{ $desk['total'] }} kampanya</span>
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
                    <tbody class="divide-y divide-gray-100 tabular-nums dark:divide-gray-800">
                        @forelse ($desk['rows'] as $r)
                            <tr class="align-top text-gray-700 dark:text-gray-300" wire:key="desk-{{ $r['asset_id'] }}-{{ $r['campaign_id'] }}">
                                <td class="px-4 py-2.5"><span class="flex items-center gap-2"><x-ta.brand-avatar :name="$r['brand']" size="xs" /><span class="font-medium text-gray-900 dark:text-white">{{ $r['brand'] }}</span></span></td>
                                <td class="max-w-[20rem] px-3 py-2.5">
                                    <span class="flex min-w-0 items-center gap-1.5">
                                        <span @class(['inline-block h-2 w-2 shrink-0 rounded-full', 'bg-success-500' => $r['status'] === 'live', 'bg-gray-400' => $r['status'] !== 'live']) title="{{ $r['status'] === 'live' ? 'Yayında' : 'Yayında değil' }}"></span>
                                        <a href="{{ route('operator.meta.campaign', ['assetId' => $r['asset_id'], 'campaignId' => $r['campaign_id']]) }}" wire:navigate class="min-w-0 truncate hover:text-gray-900 hover:underline dark:hover:text-white" title="{{ $r['name'] }}">{{ $r['name'] }}</a>
                                    </span>
                                </td>
                                <td class="px-3 py-2.5">
                                    @if ($r['services'] === [])
                                        <span class="flex flex-wrap items-center gap-1.5"><x-ta.pill tone="look">Hizmet yok</x-ta.pill><a href="{{ route('operator.meta.assign', ['assetId' => $r['asset_id']]) }}" wire:navigate class="link-neutral whitespace-nowrap text-xs" data-assign-service>+ Hizmet ata</a></span>
                                    @else
                                        <span class="text-xs">{{ implode(', ', array_column($r['services'], 'name')) }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right">{{ $money($r['spend'], $r['currency']) }}</td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right">{{ $num($r['results']) }} <span class="text-xs text-gray-500">{{ \App\Services\Meta\MetaCampaignBoard::TYPES[$r['type']][0] ?? '' }}</span></td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right font-medium text-gray-900 dark:text-white">{{ $money($r['cpr'], $r['currency']) }}</td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right">{{ $r['average'] ? $money($r['average']['median'], $r['currency']) : '—' }}</td>
                                <td @class(['px-3 py-2.5 text-right font-semibold', 'text-error-600' => ($r['diff'] ?? 0) >= 15, 'text-success-600' => ($r['diff'] ?? 0) <= -15, 'text-gray-500' => $r['diff'] === null || abs($r['diff']) < 15])>{{ $pct($r['diff']) }}</td>
                                <td class="px-3 py-2.5">
                                    {{-- "Hizmet yok" is already the Hizmet column's pill; not repeated here. --}}
                                    <div class="flex flex-wrap gap-1">@foreach (array_diff($r['alerts'], ['no_service']) as $key)<x-ta.pill :tone="($tones[$key] ?? 'warn') === 'bad' ? 'critical' : 'look'">{{ $alerts[$key] ?? '' }}</x-ta.pill>@endforeach</div>
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
