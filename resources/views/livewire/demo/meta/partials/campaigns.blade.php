@php
    $types = \App\Services\Meta\MetaCampaignBoard::TYPES;
    $chip = 'inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium';
    $cost = fn (array $row) => $row['cpr'] === null ? '—' : $money($row['cpr']);
    $count = fn (float $value) => $value >= 10000 ? $num($value / 1000, 1).' bin' : $num($value, $value == floor($value) ? 0 : 1);
    // "Hizmet yok" is not an alert chip: the row's "+ Hizmet ata" action already says it.
    $rowAlerts = fn (array $row): array => array_values(array_filter($row['alerts'], fn (array $a): bool => $a['key'] !== 'no_service'));
    $alertCount = $board ? count(array_filter($board['rows'], fn (array $r): bool => $rowAlerts($r) !== [])) : 0;
    $statusLabels = ['live' => 'Yayında', 'paused' => 'Durmuş', 'ended' => 'Bitti'];
    // A row action, not a loss: neutral outline (red stays for money / traffic loss, orange for the page's main action).
    $assignChip = 'bg-white text-gray-700 ring-1 ring-inset ring-gray-300 hover:ring-gray-400 hover:text-gray-900 dark:bg-transparent dark:text-gray-300 dark:ring-gray-600';
    // Confirmed service: a quiet solid gray chip (orange is only the page's main action).
    $serviceChip = 'bg-gray-100 text-gray-800 ring-1 ring-inset ring-gray-200 dark:bg-white/[0.06] dark:text-gray-200 dark:ring-gray-700';
@endphp

@if (! ($board['bound'] ?? false))
    <x-ta.empty-state size="sm" title="Veri yok." message="Reklam hesabı bağlanınca kampanyalar burada görünür." />
@else
    <div class="flex flex-wrap items-end gap-3" data-testid="meta-campaign-filters">
        <div>
            <p class="mb-1 text-xs text-gray-500">Durum</p>
            <div class="flex max-w-full flex-wrap gap-0.5 rounded-lg bg-gray-100 p-0.5 dark:bg-white/[0.04]">
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
        <label class="w-full min-w-0 flex-1 text-xs text-gray-500 sm:w-auto sm:min-w-[12rem]">Ara
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Kampanya adı" class="mt-1 block w-full rounded-lg border-gray-300 py-1.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
        </label>
        @if ($brand)
            <a href="{{ route('operator.meta-strategy', ['marka' => $brand->id]) }}" wire:navigate class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-white/[0.04]">Strateji öner</a>
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
    @php
        // Nothing spent in the range: one calm line instead of a wall of 0,00 cards.
        $nothingSpent = (float) ($k['spend']['value'] ?? 0) == 0.0 && collect(['leads', 'messages', 'purchases', 'clicks'])->every(fn (string $t): bool => (float) ($k[$t]['value'] ?? 0) == 0.0);
    @endphp
    @if ($nothingSpent)
        <x-ta.empty-state size="sm" title="Bu dönemde harcama yok" message="{{ $range->label() }} içinde yayında harcama yapan kampanya yok; sayılar kampanya harcadıkça burada görünür." data-testid="meta-campaign-kpis" data-meta-nothing-spent />
    @else
    <div class="grid grid-cols-2 gap-3 xl:grid-cols-5" data-testid="meta-campaign-kpis">
        <x-ta.metric-card label="Harcama" :value="$money($k['spend']['value'])" :delta="$k['spend']['change'] === null ? null : $pct($k['spend']['change'])" tone="neutral"
            :hint="$k['spend']['change'] === null ? 'önceki dönem yok' : 'karşılaştırma dönemine göre'" class="col-span-2 min-w-0 sm:col-span-1" />
        @foreach ($typeTiles as $typeKey => [$label, $value, $sub, $change, $campaignsOfType])
            <button type="button" wire:click="$set('resultType', '{{ $resultType === $typeKey ? '' : $typeKey }}')" wire:key="kpi-{{ $typeKey }}" data-kpi-type="{{ $typeKey }}"
                @class(['min-w-0 rounded-xl p-4 text-left ring-1 ring-inset transition', 'bg-gray-50 ring-gray-400 dark:bg-white/[0.06] dark:ring-gray-500' => $resultType === $typeKey, 'bg-white ring-gray-200 hover:ring-gray-300 dark:bg-gray-900 dark:ring-gray-800' => $resultType !== $typeKey])
                title="Tabloyu {{ mb_strtolower($label) }} kampanyalarına süz">
                <p class="text-xs font-medium text-gray-500">{{ $label }} <span class="text-gray-400">· {{ $campaignsOfType }} kampanya</span></p>
                <p class="mt-1 text-2xl font-semibold tabular-nums tracking-tight text-gray-900 dark:text-white">{{ $value }}</p>
                <p class="text-xs tabular-nums text-gray-500">{{ $sub }}@if ($change !== null) <span @class(['font-semibold', 'text-success-600' => $change < 0, 'text-error-600' => $change > 0])>{{ $pct($change) }}</span>@endif</p>
            </button>
        @endforeach
    </div>
    @endif

    @if (! $nothingSpent && ($board['by_service'] ?? []) !== [])
        <section class="space-y-2" data-testid="meta-campaigns-by-service">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Hizmetlere göre</h2>
                <p class="text-xs text-gray-500">{{ $range->label() }} · iki hizmetli kampanya ikisinde de sayılır · tıklayınca tablo o hizmete süzülür</p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach (collect($board['by_service'])->sortByDesc(fn (array $svc): float => (float) $svc['spend']) as $svc)
                    @php [$svcUnit, $svcCost] = $types[$svc['type']] ?? ['sonuç', 'sonuç başı']; @endphp
                    <button type="button" wire:click="$set('service', '{{ $service === (string) $svc['id'] ? '' : $svc['id'] }}')" wire:key="by-service-{{ $svc['id'] }}"
                        @class(['min-w-0 rounded-xl p-4 text-left ring-1 ring-inset transition', 'bg-gray-50 ring-gray-400 dark:bg-white/[0.06] dark:ring-gray-500' => $service === (string) $svc['id'], 'bg-white ring-gray-200 hover:ring-gray-300 dark:bg-gray-900 dark:ring-gray-800' => $service !== (string) $svc['id']])>
                        <p class="truncate font-semibold text-gray-900 dark:text-white" title="{{ $svc['name'] }}">{{ $svc['name'] }}</p>
                        <p class="mt-0.5 text-xs text-gray-500">{{ $svc['campaigns'] }} kampanya · {{ $svc['live'] }} yayında</p>
                        @if ((float) $svc['spend'] == 0.0 && (float) $svc['results'] == 0.0)
                            <p class="mt-2 text-sm text-gray-400">Bu dönemde harcama yok</p>
                        @else
                            <p class="mt-2 text-sm tabular-nums text-gray-700 dark:text-gray-300"><span class="whitespace-nowrap font-semibold text-gray-900 dark:text-white">{{ $money($svc['spend']) }}</span> · <span class="whitespace-nowrap">{{ $count($svc['results']) }} {{ $svcUnit }}</span> · <span class="whitespace-nowrap">{{ $money($svc['cpr']) }} {{ $svcCost }}</span></p>
                        @endif
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
                <tbody class="divide-y divide-gray-100 tabular-nums dark:divide-gray-800">
                    @forelse ($board['rows'] as $row)
                        <tr class="align-top text-gray-700 dark:text-gray-300" wire:key="campaign-{{ $row['id'] }}">
                            <td class="px-4 py-3">
                                <div class="flex gap-2.5">
                                    <span @class(['mt-1.5 h-2 w-2 shrink-0 rounded-full', 'bg-success-500' => $row['status'] === 'live', 'bg-gray-400' => $row['status'] !== 'live']) title="{{ $statusLabels[$row['status']] ?? '' }}"></span>
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
                                            <span class="{{ $chip }} {{ $serviceChip }}">{{ $s['name'] }}</span>
                                        @else
                                            <button type="button" wire:click="confirmService('{{ $row['id'] }}', {{ $s['id'] }})" title="Öneri ({{ \App\Models\AdCampaignService::SOURCES[$s['source']] ?? $s['source'] }}): {{ $s['reason'] }} · Onaylamak için tıklayın" class="{{ $chip }} border border-dashed border-gray-400 bg-white text-gray-700 hover:border-gray-600 hover:text-gray-900 dark:bg-transparent dark:text-gray-300 dark:hover:text-white">{{ $s['name'] }}</button>
                                        @endif
                                    @endforeach
                                    @if ($row['service_state'] === 'excluded')
                                        <span class="{{ $chip }} bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300">Hizmet dışı</span>
                                    @elseif ($row['services'] === [])
                                        <a href="{{ route('operator.meta.assign', ['assetId' => $assetId]) }}" wire:navigate class="{{ $chip }} {{ $assignChip }}" data-assign-service>+ Hizmet ata</a>
                                    @endif
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-3 text-right">{{ $row['budget']['amount'] === null ? '—' : $money($row['budget']['amount']) }}@if ($row['budget']['level'] === 'adset' && $row['budget']['amount'] !== null)<span class="block text-[11px] text-gray-400">set bütçeleri</span>@endif</td>
                            <td class="whitespace-nowrap px-3 py-3 text-right">{{ $money($row['spend']) }}</td>
                            <td class="whitespace-nowrap px-3 py-3 text-right" data-result-type="{{ $row['type'] }}"><span class="font-medium text-gray-900 dark:text-white">{{ $count($row['results']) }}</span> <span class="ml-1 inline-block min-w-[4.5rem] rounded bg-gray-100 px-1.5 py-0.5 text-center text-[11px] font-medium text-gray-600 dark:bg-white/[0.06] dark:text-gray-300">{{ $types[$row['type']][0] }}</span></td>
                            <td class="whitespace-nowrap px-3 py-3 text-right">
                                <span class="font-medium text-gray-900 dark:text-white">{{ $cost($row) }}</span>
                                @if ($row['cpr_change'] !== null)
                                    <span @class(['block text-xs font-semibold', 'text-success-600' => $row['cpr_change'] < 0, 'text-error-600' => $row['cpr_change'] > 0, 'text-gray-500' => $row['cpr_change'] == 0])>{{ $row['cpr_change'] > 0 ? '↑' : ($row['cpr_change'] < 0 ? '↓' : '→') }} {{ $pct($row['cpr_change']) }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-3 text-right">
                                @if ($row['average'])
                                    <span class="text-gray-700 dark:text-gray-300" title="{{ $row['average']['brands'] }} markanın ortancası · {{ $row['average']['scope'] }}">{{ $money($row['average']['median']) }}</span>
                                    @if ($row['average']['diff'] !== null)
                                        <span @class(['block text-xs font-semibold', 'text-success-600' => $row['average']['verdict'] === 'better', 'text-error-600' => $row['average']['verdict'] === 'worse', 'text-gray-500' => $row['average']['verdict'] === 'around'])>{{ $pct($row['average']['diff']) }}</span>
                                    @endif
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-3">
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($rowAlerts($row) as $alert)<x-ta.pill :tone="$alert['tone'] === 'bad' ? 'critical' : 'look'">{{ $alert['label'] }}</x-ta.pill>@endforeach
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
            <span class="flex items-center gap-1.5"><span class="{{ $chip }} {{ $serviceChip }}">Hizmet</span> onaylı</span>
            <span class="flex items-center gap-1.5"><span class="{{ $chip }} border border-dashed border-gray-400 text-gray-700">Hizmet</span> sistem önerisi, tıklayınca onaylanır</span>
            <span class="flex items-center gap-1.5"><span class="{{ $chip }} {{ $assignChip }}">+ Hizmet ata</span> eşleşme yok, eşleştirme ekranını açar</span>
            <span>Sonuç başı maliyet karşılaştırma dönemine göre; her kampanya kendi sonuç türüyle. Hizmet ort.: aynı hizmet ve sonuç türünde diğer markaların son 30 gün ortancası.</span>
        </div>
    </section>
@endif
