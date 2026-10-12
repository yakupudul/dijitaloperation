@php
    $types = \App\Services\Meta\MetaCampaignBoard::TYPES;
    $chip = 'inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium';
    $tones = [
        'bad' => 'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30',
        'warn' => 'bg-amber-50 text-amber-800 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30',
    ];
    $cost = fn (array $row) => $row['cpr'] === null ? '—' : $money($row['cpr']);
    $count = fn (float $value) => $value >= 10000 ? $num($value / 1000, 1).' bin' : $num($value, $value == floor($value) ? 0 : 1);
    // "Hizmet yok" is not an alert chip: the row's "+ Hizmet ata" action already says it.
    $rowAlerts = fn (array $row): array => array_values(array_filter($row['alerts'], fn (array $a): bool => $a['key'] !== 'no_service'));
    $alertCount = $board ? count(array_filter($board['rows'], fn (array $r): bool => $rowAlerts($r) !== [])) : 0;
    $statusLabels = ['live' => 'Yayında', 'paused' => 'Durmuş', 'ended' => 'Bitti'];
    // A row action, not a loss: neutral outline (red stays for money / traffic loss, orange for the page's main action).
    $assignChip = 'bg-white text-gray-700 ring-1 ring-inset ring-gray-300 hover:ring-gray-400 hover:text-gray-900 dark:bg-transparent dark:text-gray-300 dark:ring-gray-600';
@endphp

@if (! ($board['bound'] ?? false))
    <p class="{{ $card }} text-sm text-gray-500">Veri yok.</p>
@else
    <div class="flex flex-wrap items-end gap-3" data-testid="meta-campaign-filters">
        <div>
            <p class="mb-1 text-xs text-gray-500">Durum</p>
            <div class="flex gap-0.5 rounded-lg bg-gray-100 p-0.5 dark:bg-white/[0.04]">
                @foreach (['live' => 'Yayında', 'paused' => 'Durmuş', 'all' => 'Tümü'] as $key => $label)
                    <button type="button" wire:click="$set('status', '{{ $key }}')" @class(['rounded-md px-3 py-1 text-sm font-medium', 'bg-white text-gray-900 shadow-sm dark:bg-gray-700 dark:text-white' => $status === $key, 'text-gray-600 dark:text-gray-400' => $status !== $key])>{{ $label }} <span class="text-xs text-gray-400">{{ $board['counts'][$key] ?? 0 }}</span></button>
                @endforeach
            </div>
        </div>
        <label class="text-xs text-gray-500">Hizmet
            <select wire:model.live="service" class="mt-1 block rounded-lg border-gray-300 py-1.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                <option value="">Tüm hizmetler</option>
                @foreach ($board['offerings'] as $offering)<option value="{{ $offering['id'] }}">{{ $offering['name'] }}</option>@endforeach
                <option value="none">Hizmet atanmamış</option>
            </select>
        </label>
        <label class="text-xs text-gray-500">Sonuç türü
            <select wire:model.live="resultType" class="mt-1 block rounded-lg border-gray-300 py-1.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                <option value="">Tümü</option>
                @foreach ($types as $key => [$label])<option value="{{ $key }}">{{ ucfirst($label) }}</option>@endforeach
            </select>
        </label>
        <label class="min-w-[12rem] flex-1 text-xs text-gray-500">Ara
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Kampanya adı" class="mt-1 block w-full rounded-lg border-gray-300 py-1.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
        </label>
        @if ($brand)
            <a href="{{ route('operator.meta-strategy', ['marka' => $brand->id]) }}" wire:navigate class="rounded-lg px-3 py-1.5 text-sm font-semibold text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:ring-brand-500/30 dark:hover:bg-brand-500/10">Strateji öner</a>
        @endif
    </div>

    @php
        $k = $board['kpis'];
        // One card per result type that has campaigns (form / mesaj / satış / tıklama); a card filters the table to that type.
        $typeTiles = [];
        foreach (['leads' => 'Form', 'messages' => 'Mesaj', 'purchases' => 'Satış', 'clicks' => 'Tıklama'] as $typeKey => $typeLabel) {
            $campaignsOfType = (int) ($board['type_campaigns'][$typeKey] ?? 0);
            if (isset($k[$typeKey]) && ($campaignsOfType > 0 || ($k[$typeKey]['value'] ?? 0) > 0)) {
                $typeTiles[$typeKey] = [$typeLabel, $count($k[$typeKey]['value']), $types[$typeKey][1].' '.($k[$typeKey]['cost'] === null ? '—' : $money($k[$typeKey]['cost'])), $k[$typeKey]['change'], $campaignsOfType];
            }
        }
    @endphp
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5" data-testid="meta-campaign-kpis">
        <section class="{{ $card }}">
            <p class="text-xs font-medium text-gray-500">Harcama</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $money($k['spend']['value']) }}</p>
            <p class="text-xs text-gray-500">{{ $k['spend']['change'] === null ? 'önceki dönem yok' : $pct($k['spend']['change']).' karşılaştırma dönemine göre' }}</p>
        </section>
        @foreach ($typeTiles as $typeKey => [$label, $value, $sub, $change, $campaignsOfType])
            <button type="button" wire:click="$set('resultType', '{{ $resultType === $typeKey ? '' : $typeKey }}')" wire:key="kpi-{{ $typeKey }}" data-kpi-type="{{ $typeKey }}"
                @class(['rounded-xl p-4 text-left ring-1 ring-inset transition', 'bg-gray-50 ring-gray-400 dark:bg-white/[0.06] dark:ring-gray-500' => $resultType === $typeKey, 'bg-white ring-gray-200 hover:ring-gray-300 dark:bg-gray-800 dark:ring-gray-700' => $resultType !== $typeKey])
                title="Tabloyu {{ mb_strtolower($label) }} kampanyalarına süz">
                <p class="text-xs font-medium text-gray-500">{{ $label }} <span class="text-gray-400">· {{ $campaignsOfType }} kampanya</span></p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $value }}</p>
                <p class="text-xs text-gray-500">{{ $sub }}@if ($change !== null) <span @class(['font-semibold', 'text-emerald-600' => $change < 0, 'text-rose-600' => $change > 0])>{{ $pct($change) }}</span>@endif</p>
            </button>
        @endforeach
    </div>

    @if (($board['by_service'] ?? []) !== [])
        <section class="space-y-2" data-testid="meta-campaigns-by-service">
            <div class="flex items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Hizmetlere göre</h2>
                <p class="text-xs text-gray-500">{{ $range->label() }} · iki hizmetli kampanya ikisinde de sayılır · tıklayınca tablo o hizmete süzülür</p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($board['by_service'] as $svc)
                    @php [$svcUnit, $svcCost] = $types[$svc['type']] ?? ['sonuç', 'sonuç başı']; @endphp
                    <button type="button" wire:click="$set('service', '{{ $service === (string) $svc['id'] ? '' : $svc['id'] }}')" wire:key="by-service-{{ $svc['id'] }}"
                        @class(['rounded-xl p-4 text-left ring-1 ring-inset transition', 'bg-brand-50 ring-brand-300 dark:bg-brand-500/10 dark:ring-brand-500/40' => $service === (string) $svc['id'], 'bg-white ring-gray-200 hover:ring-brand-200 dark:bg-gray-800 dark:ring-gray-700' => $service !== (string) $svc['id']])>
                        <p class="truncate font-semibold text-gray-900 dark:text-white">{{ $svc['name'] }}</p>
                        <p class="mt-0.5 text-xs text-gray-500">{{ $svc['campaigns'] }} kampanya · {{ $svc['live'] }} yayında</p>
                        <p class="mt-2 text-sm tabular-nums text-gray-700 dark:text-gray-300"><span class="font-semibold text-gray-900 dark:text-white">{{ $money($svc['spend']) }}</span> · {{ $count($svc['results']) }} {{ $svcUnit }} · {{ $money($svc['cpr']) }} {{ $svcCost }}</p>
                    </button>
                @endforeach
            </div>
        </section>
    @endif

    @if ($alertCount > 0)
        <p class="text-xs text-gray-500" data-testid="meta-campaign-alert-count">Bu listede {{ $alertCount }} kampanyada uyarı var; etiketler Uyarı sütununda.</p>
    @endif

    <section class="{{ $panel }} overflow-hidden" data-testid="meta-campaigns">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[72rem] text-sm">
                <thead class="bg-gray-50 dark:bg-white/[0.02]">
                    <tr class="text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                        <th class="px-4 py-2.5">Kampanya</th><th class="px-3 py-2.5">Hizmetler</th><th class="px-3 py-2.5 text-right">Günlük bütçe</th><th class="px-3 py-2.5 text-right">Harcama</th>
                        <th class="px-3 py-2.5 text-right">Sonuç</th><th class="px-3 py-2.5 text-right">Sonuç başı</th><th class="px-3 py-2.5 text-right">Hizmet ort.</th><th class="px-3 py-2.5">Uyarı</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 tabular-nums dark:divide-gray-700">
                    @forelse ($board['rows'] as $row)
                        <tr class="align-top text-gray-700 dark:text-gray-300" wire:key="campaign-{{ $row['id'] }}">
                            <td class="px-4 py-3">
                                <div class="flex gap-2.5">
                                    <span @class(['mt-1.5 h-2 w-2 shrink-0 rounded-full', 'bg-emerald-500' => $row['status'] === 'live', 'bg-gray-400' => $row['status'] !== 'live']) title="{{ $statusLabels[$row['status']] ?? '' }}"></span>
                                    @php
                                        // Readable line: the confirmed service(s) and target countries; the raw Meta name goes small and grey below.
                                        $confirmed = array_column(array_filter($row['services'], fn (array $s): bool => $s['status'] === 'confirmed'), 'name');
                                        $countries = $row['countries'] ?? [];
                                        $readable = $confirmed === [] ? null : implode(', ', array_slice($confirmed, 0, 2)).(count($confirmed) > 2 ? ' +'.(count($confirmed) - 2) : '')
                                            .($countries !== [] ? ' · '.implode(', ', array_slice($countries, 0, 3)).(count($countries) > 3 ? ' +'.(count($countries) - 3) : '') : '');
                                        $campaignUrl = route('operator.meta.campaign', ['assetId' => $assetId, 'campaignId' => $row['id'], 'gun' => $days, 'bas' => $start ?: null, 'bit' => $end ?: null, 'karsilastir' => $compare !== 'prev' ? $compare : null]);
                                    @endphp
                                    <div class="min-w-0 max-w-[24rem]" title="{{ $row['name'] }}{{ $row['objective'] !== '' ? ' · '.$row['objective'] : '' }}">
                                        <a href="{{ $campaignUrl }}" wire:navigate class="block truncate font-semibold text-gray-900 hover:underline dark:text-white" data-campaign-title>{{ $readable ?? $row['name'] }}</a>
                                        @if ($readable !== null)<p class="truncate text-xs text-gray-400" data-campaign-raw-name>{{ $row['name'] }}</p>@endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-3">
                                <div class="flex max-w-[20rem] flex-wrap gap-1.5">
                                    @foreach ($row['services'] as $s)
                                        @if ($s['status'] === 'confirmed')
                                            <span class="{{ $chip }} bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-200 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/30">{{ $s['name'] }}</span>
                                        @else
                                            <button type="button" wire:click="confirmService('{{ $row['id'] }}', {{ $s['id'] }})" title="Öneri ({{ \App\Models\AdCampaignService::SOURCES[$s['source']] ?? $s['source'] }}): {{ $s['reason'] }} · Onaylamak için tıklayın" class="{{ $chip }} border border-dashed border-gray-400 bg-white text-gray-700 hover:border-brand-500 hover:text-brand-700 dark:bg-transparent dark:text-gray-300">{{ $s['name'] }}</button>
                                        @endif
                                    @endforeach
                                    @if ($row['service_state'] === 'excluded')
                                        <span class="{{ $chip }} bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300">Hizmet dışı</span>
                                    @elseif ($row['services'] === [])
                                        <a href="{{ route('operator.meta.assign', ['assetId' => $assetId]) }}" wire:navigate class="{{ $chip }} {{ $assignChip }}" data-assign-service>+ Hizmet ata</a>
                                    @endif
                                </div>
                            </td>
                            <td class="px-3 py-3 text-right">{{ $row['budget']['amount'] === null ? '—' : $money($row['budget']['amount']) }}@if ($row['budget']['level'] === 'adset' && $row['budget']['amount'] !== null)<span class="block text-[11px] text-gray-400">set bütçeleri</span>@endif</td>
                            <td class="px-3 py-3 text-right">{{ $money($row['spend']) }}</td>
                            <td class="whitespace-nowrap px-3 py-3 text-right" data-result-type="{{ $row['type'] }}"><span class="font-medium text-gray-900 dark:text-white">{{ $count($row['results']) }}</span> <span class="ml-1 inline-block min-w-[4.5rem] rounded bg-gray-100 px-1.5 py-0.5 text-center text-[11px] font-medium text-gray-600 dark:bg-white/[0.06] dark:text-gray-300">{{ $types[$row['type']][0] }}</span></td>
                            <td class="px-3 py-3 text-right">
                                <span class="font-medium text-gray-900 dark:text-white">{{ $cost($row) }}</span>
                                @if ($row['cpr_change'] !== null)
                                    <span @class(['block text-xs font-semibold', 'text-emerald-600' => $row['cpr_change'] < 0, 'text-rose-600' => $row['cpr_change'] > 0, 'text-gray-500' => $row['cpr_change'] == 0])>{{ $row['cpr_change'] > 0 ? '↑' : ($row['cpr_change'] < 0 ? '↓' : '→') }} {{ $pct($row['cpr_change']) }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-right">
                                @if ($row['average'])
                                    <span class="text-gray-700 dark:text-gray-300" title="{{ $row['average']['brands'] }} markanın ortancası · {{ $row['average']['scope'] }}">{{ $money($row['average']['median']) }}</span>
                                    @if ($row['average']['diff'] !== null)
                                        <span @class(['block text-xs font-semibold', 'text-emerald-600' => $row['average']['verdict'] === 'better', 'text-rose-600' => $row['average']['verdict'] === 'worse', 'text-gray-500' => $row['average']['verdict'] === 'around'])>{{ $pct($row['average']['diff']) }}</span>
                                    @endif
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-3">
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($rowAlerts($row) as $alert)<span class="rounded-md px-2 py-0.5 text-xs font-semibold {{ $tones[$alert['tone']] }}">{{ $alert['label'] }}</span>@endforeach
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-6 text-center text-sm text-gray-500">
                            @if ($status === 'live' && ($board['counts']['all'] ?? 0) > 0)
                                Yayında kampanya yok. <button type="button" wire:click="$set('status', 'all')" class="font-medium text-gray-700 underline dark:text-gray-300">Tümünü göster ({{ $board['counts']['all'] }})</button>
                            @else
                                Bu süzgeçte kampanya yok.
                            @endif
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-gray-100 px-4 py-3 text-xs text-gray-500 dark:border-gray-700">
            <span class="flex items-center gap-1.5"><span class="{{ $chip }} bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-200">Hizmet</span> onaylı</span>
            <span class="flex items-center gap-1.5"><span class="{{ $chip }} border border-dashed border-gray-400 text-gray-700">Hizmet</span> sistem önerisi, tıklayınca onaylanır</span>
            <span class="flex items-center gap-1.5"><span class="{{ $chip }} {{ $assignChip }}">+ Hizmet ata</span> eşleşme yok, eşleştirme ekranını açar</span>
            <span>Sonuç başı maliyet karşılaştırma dönemine göre; her kampanya kendi sonuç türüyle. Hizmet ort.: aynı hizmet ve sonuç türünde diğer markaların son 30 gün ortancası.</span>
        </div>
    </section>
@endif
