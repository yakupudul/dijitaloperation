@php
    $glance = $data['glance'] ?? [];
    $acqBars = $data['acquisition_mix'] ?? [];
    $landingPulse = $data['landing_pulse'] ?? [];
    $actionMatrix = $data['business_actions'] ?? [];
    $maxAcq = max(1, (float) collect($acqBars)->max('sessions'));
    $attention = $data['needs_attention'] ?? [];
    $journeys = array_is_list($data['journeys'] ?? []) ? ($data['journeys'] ?? []) : ($data['journeys']['paths'] ?? []);
    $outcomes = $data['recent_outcomes'] ?? [];
    $trend = $data['performance_trend'] ?? [];
    // Users, sessions, conversions (when the property sends them), business actions and measurement health.
    $cells = [
        ['Kullanıcı', $glanceCell($glance['users'] ?? null), []],
        ['Oturum', $glanceCell($glance['sessions'] ?? null), array_map('floatval', $trend['sessions'] ?? $trend['values'] ?? [])],
    ];
    if (array_key_exists('conversions', $glance)) {
        $cells[] = ['Dönüşüm', $glanceCell($glance['conversions']), []];
    }
    $cells[] = ['İş aksiyonu', $glanceCell($glance['business_actions'] ?? null), array_map('floatval', $trend['business_actions'] ?? [])];
    $cells[] = ['Ölçüm', $glanceCell($glance['measurement_state'] ?? null), []];
@endphp

<div class="space-y-10">
    {{-- 1. What needs a look, when anything does. --}}
    @if ($attention !== [])
        <section data-ga4-attention>
            <div class="section-head">
                <h2 class="section-title">Dikkat isteyen <span class="section-count">{{ count($attention) }}</span></h2>
            </div>
            <ul class="card">
                @foreach ($attention as $item)
                    @php $tone = $toneOf($item['severity'] ?? null); @endphp
                    <li class="card-row flex-wrap sm:flex-nowrap" wire:key="ga4-att-{{ $item['id'] }}">
                        <span @class(['row-icon', 'border-transparent bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-400' => $tone === 'critical', 'border-transparent bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400' => $tone === 'look']) aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5M12 16.5v.5"/><path d="M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $item['title'] }}</p>
                            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400" title="{{ $item['scope'] ?? '' }}">{{ $item['metric'] }}</p>
                        </div>
                        <x-ta.pill :tone="$tone" class="hidden! sm:inline-flex!">{{ $say($item['severity'] ?? null) }}</x-ta.pill>
                        <button type="button" wire:click="openAttention('{{ $item['id'] }}')" class="btn w-full sm:w-auto">{{ $sayAction($item['action'] ?? 'Inspect') }}</button>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- 2. Is the site bringing business: the head numbers. --}}
    <section data-ga4-glance>
        <div @class(['metric-strip grid-cols-2', 'lg:grid-cols-4' => count($cells) === 4, 'lg:grid-cols-5' => count($cells) === 5])>
            @foreach ($cells as [$label, $cell, $points])
                <x-ta.metric-card :label="$label" :value="$cell['value']" :delta="$cell['delta']" :tone="$cell['tone']" :hint="$cell['hint']" :muted="$cell['muted']" :spark="$points" :title="$cell['note']" :class="$loop->last && $loop->odd ? 'col-span-2 lg:col-span-1' : ''" />
            @endforeach
        </div>
    </section>

    {{-- 3. Day by day. --}}
    <section>
        <div class="section-head">
            <h2 class="section-title" title="{{ $trend['note'] ?? '' }}">Oturum trendi</h2>
        </div>
        <div class="card">
            @if ($hasTrend)
                <div class="px-2 pt-3 pb-1 sm:px-3">
                    <div data-chart='@json($chart)' aria-label="Sessions and mapped business actions trend" class="min-h-[220px]"></div>
                </div>
            @else
                <x-ta.empty-state title="Bu dönem için veri yok" message="Analytics verisi gelince günlük çizgi burada görünür." />
            @endif
        </div>
    </section>

    {{-- 4. Where the visits come from, and which actions they lead to. --}}
    <div class="grid gap-10 lg:grid-cols-2 lg:gap-6">
        <section class="min-w-0">
            <div class="section-head">
                <h2 class="section-title">Trafik kaynakları</h2>
                <button type="button" wire:click="setTab('acquisition')" class="ml-auto link-neutral text-[13px]">Ayrıntı</button>
            </div>
            <ul class="card">
                @forelse ($acqBars as $row)
                    <li class="card-row flex-col items-stretch gap-1.5 py-3">
                        <div class="flex items-baseline justify-between gap-3 text-sm">
                            <span class="text-gray-900 dark:text-white">{{ $say($row['channel']) }}</span>
                            <span class="tabular-nums text-gray-900 dark:text-white">{{ $num($row['sessions']) }} <span class="text-[13px] text-gray-500">%{{ $row['share_pct'] }}</span></span>
                        </div>
                        <div class="h-1 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5">
                            <div class="h-full rounded-full bg-gray-800 dark:bg-gray-300" style="width: {{ min(100, $row['bar'] ?? round(($row['sessions'] / $maxAcq) * 100)) }}%"></div>
                        </div>
                    </li>
                @empty
                    <li><x-ta.empty-state size="sm" title="Kaynak verisi yok" /></li>
                @endforelse
            </ul>
        </section>

        <section class="min-w-0">
            <div class="section-head">
                <h2 class="section-title" title="{{ $data['missing_note'] ?? '' }}">İş aksiyonları</h2>
                <button type="button" wire:click="setMeasSub('business_actions')" class="ml-auto link-neutral text-[13px]">Ölçüm</button>
            </div>
            <ul class="card">
                @forelse ($actionMatrix as $row)
                    <li class="card-row py-3">
                        <button type="button" wire:click="openAction('{{ $row['action'] }}')" class="min-w-0 flex-1 text-left">
                            <span class="block truncate text-sm font-medium text-gray-900 dark:text-white">{{ $row['action'] }}</span>
                            <span class="mt-0.5 block truncate text-[13px] text-gray-500">{{ $row['ga4_event'] ?? 'Etkinlik yok' }}, {{ mb_strtolower($say($row['role'] ?? null)) }}</span>
                        </button>
                        <span class="text-sm tabular-nums text-gray-900 dark:text-white">{{ $count($row, 'event_count') }}</span>
                        <x-ta.pill :tone="$toneOf($row['state'] ?? null)" class="w-24 justify-end">{{ $say($row['state'] ?? null) }}</x-ta.pill>
                    </li>
                @empty
                    <li><x-ta.empty-state size="sm" title="İş aksiyonu eşlenmedi" message="Form, telefon gibi aksiyonlar Analytics etkinliğine eşlenince burada sayılır." /></li>
                @endforelse
            </ul>
        </section>
    </div>

    {{-- 5. The pages visits land on. --}}
    <section>
        <div class="section-head">
            <h2 class="section-title">Giriş sayfaları <span class="section-count">{{ count($landingPulse) }}</span></h2>
            <button type="button" wire:click="setTab('behavior')" class="ml-auto link-neutral text-[13px]">Tümü</button>
        </div>
        <div class="card overflow-x-auto">
            @if ($landingPulse === [])
                <x-ta.empty-state title="Giriş sayfası verisi yok" message="Bu dönemde sayfa bazında oturum gelmedi." />
            @else
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Sayfa</th>
                            <th class="text-right!">Oturum</th>
                            <th class="hidden text-right! sm:table-cell">Etkileşim</th>
                            <th class="hidden text-right! sm:table-cell">Aksiyon</th>
                            <th class="hidden lg:table-cell">Site uyarısı</th>
                            <th><span class="sr-only">İncele</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($landingPulse as $row)
                            <tr wire:key="ga4-pulse-{{ $row['path'] }}">
                                <td class="min-w-[12rem]">
                                    <p class="font-medium text-gray-900 dark:text-white">{{ $row['path'] }}</p>
                                    <p class="text-[13px] text-gray-500">{{ $row['title'] ?? $say($row['content_role'] ?? null) }}</p>
                                </td>
                                <td class="text-right tabular-nums text-gray-900 dark:text-white">{{ $num($row['sessions']) }}</td>
                                <td class="hidden text-right tabular-nums sm:table-cell">%{{ $row['engaged_rate'] }}</td>
                                <td class="hidden text-right tabular-nums sm:table-cell">{{ $count($row, 'mapped_actions') }}</td>
                                <td class="hidden text-[13px] lg:table-cell">
                                    @if (! empty($row['attention']))
                                        <span class="text-warning-700 dark:text-warning-400">{{ $row['attention'] }}</span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="text-right"><button type="button" wire:click="openLanding('{{ $row['path'] }}')" class="btn-quiet h-7! px-2!">İncele</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </section>

    {{-- 6. The usual paths through the site, and what earlier work changed. --}}
    @if ($journeys !== [] || $outcomes !== [])
        <div class="grid gap-10 lg:grid-cols-2 lg:gap-6">
            <section class="min-w-0">
                <div class="section-head">
                    <h2 class="section-title">Ziyaret yolları</h2>
                    <button type="button" wire:click="setTab('journeys')" class="ml-auto link-neutral text-[13px]">Tümü</button>
                </div>
                <ul class="card">
                    @forelse (array_slice($journeys, 0, 4) as $journey)
                        <li class="card-row items-start py-3">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm text-gray-900 dark:text-white">{{ implode(' › ', array_map(fn ($step) => is_array($step) ? ($step['label'] ?? '') : $step, $journey['steps'] ?? [])) ?: ($journey['path'] ?? '') }}</p>
                                @if (! empty($journey['note']) || (array_key_exists('mapped_actions', $journey) && $journey['mapped_actions'] === null))
                                    <p class="mt-0.5 text-[13px] text-warning-700 dark:text-warning-400">{{ $journey['note'] ?? 'Aksiyon verisi yok' }}</p>
                                @endif
                            </div>
                            <span class="text-sm tabular-nums text-gray-900 dark:text-white" title="Oturum">{{ $count($journey, 'sessions') }}</span>
                        </li>
                    @empty
                        <li><x-ta.empty-state size="sm" title="Yol verisi yok" /></li>
                    @endforelse
                </ul>
            </section>

            <section class="min-w-0">
                <div class="section-head">
                    <h2 class="section-title">Son sonuçlar</h2>
                    <button type="button" wire:click="setOps('outcomes')" class="ml-auto link-neutral text-[13px]">Operasyon</button>
                </div>
                <ul class="card">
                    @forelse ($outcomes as $o)
                        <li class="card-row flex-wrap sm:flex-nowrap">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $o['title'] }}</p>
                                @if (! empty($o['note']))
                                    <p class="mt-0.5 text-[13px] text-gray-500">{{ $o['note'] }}</p>
                                @endif
                            </div>
                            <x-ta.pill :tone="$toneOf($o['state'] ?? null)">{{ $say($o['state'] ?? null) }}</x-ta.pill>
                        </li>
                    @empty
                        <li><x-ta.empty-state size="sm" title="Henüz sonuç yok" /></li>
                    @endforelse
                </ul>
            </section>
        </div>
    @endif

    @include('livewire.demo.analytics.tabs.relationships')
</div>
