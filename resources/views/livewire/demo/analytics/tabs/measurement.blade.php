@php
    $m = $data['measurement'] ?? [];
    $activeSub = $meas_sub ?? 'business_actions';
    $utm = $m['utm_hygiene'] ?? [];
    $subTabs = ['business_actions' => ['İş aksiyonları', count($m['business_actions'] ?? [])], 'events' => ['Etkinlikler', count($m['events'] ?? [])], 'streams' => ['Veri akışları', count($m['streams'] ?? [])], 'quality' => ['Veri kalitesi', null]];
@endphp

<div class="space-y-10">
    {{-- A broken signal comes first: leads may be going uncounted. --}}
    @if (! empty($m['interruptions']))
        <ul class="card">
            @foreach ($m['interruptions'] as $interrupt)
                <li class="card-row">
                    <span class="row-icon border-transparent bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5M12 16.5v.5"/><path d="M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $interrupt['title'] }}</p>
                        <p class="mt-0.5 text-[13px] text-gray-500">{{ ($interrupt['detail'] ?? '').(! empty($interrupt['window']) ? ', '.$interrupt['window'] : '') }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="space-y-3">
        <div class="flex gap-1.5 overflow-x-auto no-scrollbar" role="group" aria-label="Ölçüm" title="{{ $m['subtitle'] ?? '' }}">
            @foreach ($subTabs as $key => [$label, $n])
                <button type="button" wire:click="setMeasSub('{{ $key }}')" @class(['seg', 'seg-active' => $activeSub === $key])>{{ $label }}@if ($n !== null && $n > 0) <span @class(['tabular-nums text-xs', 'text-accent' => $activeSub === $key, 'text-gray-400 dark:text-gray-500' => $activeSub !== $key])>{{ $n }}</span>@endif</button>
            @endforeach
        </div>
        <div class="card">

            @if ($activeSub === 'business_actions')
                @if (($m['business_actions'] ?? []) === [])
                    <x-ta.empty-state icon="none" title="İş aksiyonu eşlenmedi" message="Form, telefon, WhatsApp gibi aksiyonlar bir Analytics etkinliğine eşlenince burada sayılır." />
                @else
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th title="Senin tanımladığın eşleme; tahmin değil">İş aksiyonu</th>
                                    <th class="hidden sm:table-cell">Analytics etkinliği</th>
                                    <th class="hidden md:table-cell">Rol</th>
                                    <th class="text-right!">Adet</th>
                                    <th class="hidden sm:table-cell">Durum</th>
                                    <th><span class="sr-only">İncele</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($m['business_actions'] as $row)
                                    <tr wire:key="ga4-action-{{ $row['action'] }}">
                                        <td>
                                            <p class="font-medium text-gray-900 dark:text-white">{{ $row['action'] }}</p>
                                            <x-ta.pill :tone="$toneOf($row['state'] ?? null)" class="mt-0.5 sm:hidden!">{{ $say($row['state'] ?? null) }}</x-ta.pill>
                                        </td>
                                        <td class="hidden text-[13px] text-gray-500 sm:table-cell">{{ $row['ga4_event'] ?? 'Veri yok' }}</td>
                                        <td class="hidden text-[13px] text-gray-600 md:table-cell dark:text-gray-300">{{ $say($row['role'] ?? null) }}</td>
                                        <td @class(['text-right tabular-nums', 'text-gray-400' => $count($row, 'event_count') === '—'])>{{ $count($row, 'event_count') }}</td>
                                        <td class="hidden whitespace-nowrap sm:table-cell"><x-ta.pill :tone="$toneOf($row['state'] ?? null)">{{ $say($row['state'] ?? null) }}</x-ta.pill></td>
                                        <td class="text-right"><button type="button" wire:click="openAction('{{ $row['action'] }}')" class="btn-quiet h-7! px-2!">İncele</button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @elseif ($activeSub === 'events')
                @if (($m['events'] ?? []) === [])
                    <x-ta.empty-state icon="none" title="Etkinlik yok" message="Analytics'ten gelen etkinlikler burada listelenir." />
                @else
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Etkinlik</th>
                                    <th class="hidden sm:table-cell">Eşlenen aksiyon</th>
                                    <th class="text-right!">Adet</th>
                                    <th class="hidden sm:table-cell">Durum</th>
                                    <th><span class="sr-only">İncele</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($m['events'] as $row)
                                    <tr>
                                        <td>
                                            <p class="font-medium text-gray-900 dark:text-white">{{ $row['event'] }}</p>
                                            <x-ta.pill :tone="$toneOf($row['state'] ?? null)" class="mt-0.5 sm:hidden!">{{ $say($row['state'] ?? null) }}</x-ta.pill>
                                        </td>
                                        <td class="hidden text-[13px] text-gray-500 sm:table-cell">{{ $say($row['mapped_action'] ?? null) }}</td>
                                        <td @class(['text-right tabular-nums', 'text-gray-400' => $count($row, 'count') === '—'])>{{ $count($row, 'count') }}</td>
                                        <td class="hidden whitespace-nowrap sm:table-cell"><x-ta.pill :tone="$toneOf($row['state'] ?? null)">{{ $say($row['state'] ?? null) }}</x-ta.pill></td>
                                        <td class="text-right"><button type="button" wire:click="openEvent('{{ $row['event'] }}')" class="btn-quiet h-7! px-2!">İncele</button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @elseif ($activeSub === 'streams')
                @if (($m['streams'] ?? []) === [])
                    <x-ta.empty-state icon="none" title="Veri akışı yok" message="Mülkün web veri akışları burada görünür." />
                @else
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Akış</th>
                                    <th class="hidden sm:table-cell">Tür</th>
                                    <th class="hidden md:table-cell">Ölçüm kimliği</th>
                                    <th>Durum</th>
                                    <th class="hidden sm:table-cell">Son veri</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($m['streams'] as $row)
                                    <tr>
                                        <td class="font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</td>
                                        <td class="hidden text-[13px] text-gray-500 sm:table-cell">{{ $say($row['type'] ?? 'Web') }}</td>
                                        <td class="hidden text-[13px] tabular-nums text-gray-500 md:table-cell">{{ $row['measurement_id'] ?? '—' }}</td>
                                        <td class="whitespace-nowrap"><x-ta.pill :tone="$toneOf($row['status'] ?? null)">{{ $say($row['status'] ?? null) }}</x-ta.pill></td>
                                        <td class="hidden text-[13px] text-gray-500 sm:table-cell">{{ $row['last_hit'] ?? 'Veri yok' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @else
                <ul>
                    @forelse ($m['data_quality'] ?? [] as $row)
                        <li class="card-row">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['check'] }}</p>
                                @if (! empty($row['detail']))
                                    <p class="mt-0.5 text-[13px] text-gray-500">{{ $row['detail'] }}</p>
                                @endif
                            </div>
                            <x-ta.pill :tone="$toneOf($row['state'] ?? null)">{{ $say($row['state'] ?? null) }}</x-ta.pill>
                        </li>
                    @empty
                        <li><x-ta.empty-state icon="none" title="Veri kalitesi kontrolü yok" /></li>
                    @endforelse
                </ul>
            @endif
        </div>

        @if ($activeSub === 'business_actions' && ! empty($m['trust_chips']))
            <p class="flex flex-wrap gap-x-4 gap-y-1">
                @foreach ($m['trust_chips'] as $chip)
                    <x-ta.pill :tone="$toneOf($chip['state'] ?? null)">{{ $chip['label'] }}: {{ mb_strtolower($say($chip['state'] ?? null)) }}</x-ta.pill>
                @endforeach
            </p>
        @endif
    </div>

    @if ($activeSub === 'quality')
        <div class="grid gap-10 lg:grid-cols-2 lg:gap-6">
            <section class="min-w-0">
                <div class="section-head"><h2 class="section-title">Olası kopya etkinlikler</h2></div>
                <ul class="card">
                    @forelse ($m['duplicates'] ?? [] as $row)
                        <li class="card-row">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['title'] }}</p>
                                @if (! empty($row['detail']))
                                    <p class="mt-0.5 text-[13px] text-gray-500">{{ $row['detail'] }}</p>
                                @endif
                            </div>
                            <x-ta.pill :tone="$toneOf($row['state'] ?? 'Review')">{{ $say($row['state'] ?? 'Review') }}</x-ta.pill>
                        </li>
                    @empty
                        <li><x-ta.empty-state size="sm" title="Kopya görünmüyor" /></li>
                    @endforelse
                </ul>
            </section>

            <section class="min-w-0">
                <div class="section-head">
                    <h2 class="section-title" title="{{ $utm['note'] ?? '' }}">Kampanya etiketleri (UTM)</h2>
                    @if (! empty($utm['finding_id']))
                        <button type="button" wire:click="openFinding('{{ $utm['finding_id'] }}')" class="ml-auto link-neutral text-[13px]">Bulguyu aç</button>
                    @endif
                </div>
                <div class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-gray-200 bg-gray-200 shadow-card dark:border-gray-800 dark:bg-gray-800">
                    <x-ta.metric-card label="Etiketsiz oturum payı" :value="isset($utm['unavailable_pct']) ? '%'.$utm['unavailable_pct'] : '—'" :muted="! isset($utm['unavailable_pct'])" :hint="isset($utm['prior_unavailable_pct']) ? 'Önceki dönem %'.$utm['prior_unavailable_pct'] : null" />
                    <x-ta.metric-card label="Etkilenen oturum" :value="isset($utm['unavailable_sessions']) ? $num($utm['unavailable_sessions']) : '—'" :muted="! isset($utm['unavailable_sessions'])" :hint="isset($utm['trend']) && ! isset($utm['prior_unavailable_pct']) ? $utm['trend'] : null" />
                </div>
            </section>

            <section class="min-w-0 lg:col-span-2">
                <div class="section-head"><h2 class="section-title">Şüpheli yönlendirmeler</h2></div>
                <div class="card overflow-x-auto">
                    @if (($m['referrals'] ?? []) === [])
                        <x-ta.empty-state size="sm" title="Şüpheli yönlendirme yok" />
                    @else
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Kaynak / ortam</th>
                                    <th class="text-right!">Oturum</th>
                                    <th class="hidden sm:table-cell">İşaret</th>
                                    <th><span class="sr-only">Bulgu</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($m['referrals'] as $row)
                                    <tr>
                                        <td class="text-gray-900 dark:text-white">{{ $row['source'] }} / {{ $row['medium'] }}</td>
                                        <td class="text-right tabular-nums">{{ $num($row['sessions']) }}</td>
                                        <td class="hidden text-[13px] text-warning-700 sm:table-cell dark:text-warning-400">{{ $say($row['state'] ?? null) }}</td>
                                        <td class="text-right">
                                            @if (! empty($row['finding_id']))
                                                <button type="button" wire:click="openFinding('{{ $row['finding_id'] }}')" class="btn-quiet h-7! px-2!">Bulgu</button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </section>
        </div>
    @endif

    <p class="text-xs text-gray-500 dark:text-gray-400">Eşlenmemiş ya da gelmeyen sinyal ölçülmüş bir sıfır değildir; sinyal yoktur.</p>
</div>
