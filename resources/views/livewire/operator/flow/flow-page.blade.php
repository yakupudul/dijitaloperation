@php
    use App\Services\Work\OperatorTime;

    $n = fn (int $v): string => number_format($v, 0, ',', '.');
@endphp
<div class="pb-24" data-flow-page>
    <livewire:operator.flow.flow-live />

    {{-- Tek iş yapısı (Aşama 4): the four channels in the same five stages. --}}
    @if ($stages !== [])
        <section class="mt-12" data-flow-stages>
            <div class="section-head">
                <h2 class="section-title">İşin yolu</h2>
                <p class="w-full text-[13px] text-gray-500 dark:text-gray-400">Her kanalda iş aynı beş adımdan geçer. Sayılar birkaç dakikada bir yenilenir.</p>
            </div>
            <div class="card overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Kanal</th>
                            @foreach ($stageLabels as $stage => $label)
                                <th class="text-right" title="{{ $stageHints[$stage] }}">{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($channels as $channel => $channelLabel)
                            <tr data-flow-stage-row="{{ $channel }}">
                                <td class="font-medium text-gray-900 dark:text-white">{{ $channelLabel }}
                                    @if (in_array($channel, ['google_ads', 'meta'], true))
                                        <span class="block text-xs font-normal text-gray-500">Yazma kapalı; öneri ve elle yapılacak iş</span>
                                    @endif
                                </td>
                                @foreach ($stageLabels as $stage => $label)
                                    <td class="text-right tabular-nums {{ ($stages[$channel][$stage] ?? 0) === 0 ? 'text-gray-400' : 'text-gray-900 dark:text-white' }}">{{ $n($stages[$channel][$stage] ?? 0) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- Operatör süresi (Aşama 4): "1 kişi 100 marka" needs the minutes it takes. Admin only. --}}
    @if ($time !== null)
        <section class="mt-12" data-flow-time>
            <div class="section-head">
                <h2 class="section-title">Senin zamanın</h2>
                <p class="w-full text-[13px] text-gray-500 dark:text-gray-400">Son 7 gün. Panelde ekran açtığın ya da tıkladığın her dakika bir etkin dakika sayılır.</p>
            </div>
            <div class="grid grid-cols-2 gap-2.5 lg:grid-cols-4">
                <div class="soft-card" data-flow-time-minutes>
                    <span class="text-[13px] text-gray-500 dark:text-gray-400">Etkin süre</span>
                    <span class="soft-number">{{ OperatorTime::minutesLabel($time['minutes']) }}</span>
                    @if ($time['previous'] > 0)
                        @php $delta = (int) round(($time['minutes'] - $time['previous']) / $time['previous'] * 100); @endphp
                        <span @class(['chip self-start', 'chip-up' => $delta < 0, 'chip-down' => $delta > 0, 'chip-flat' => $delta === 0]) title="Önceki 7 güne göre; daha az süre daha iyi.">{{ $delta > 0 ? '+' : '' }}{{ $delta }}%</span>
                    @endif
                </div>
                <div class="soft-card">
                    <span class="text-[13px] text-gray-500 dark:text-gray-400">Marka başına</span>
                    <span class="soft-number">{{ $time['per_brand'] !== null ? OperatorTime::minutesLabel($time['per_brand']) : '—' }}</span>
                    <span class="text-xs text-gray-500">haftada, tüm markalara bölünce</span>
                </div>
                <div class="soft-card" data-flow-time-clicks>
                    <span class="text-[13px] text-gray-500 dark:text-gray-400">Tık</span>
                    <span class="soft-number">{{ $n($time['clicks']) }}</span>
                    <span class="text-xs text-gray-500">{{ $n($time['views']) }} ekran açıldı</span>
                </div>
                <div class="soft-card" data-flow-time-wait>
                    <span class="text-[13px] text-gray-500 dark:text-gray-400">Kararın bekleme süresi</span>
                    <span class="soft-number">{{ OperatorTime::waitLabel($time['wait_seconds']) }}</span>
                    <span class="text-xs text-gray-500">{{ $time['approvals'] > 0 ? $n($time['approvals']).' onayda, iş bulunduktan onaylanana kadar' : 'Bu hafta onay yok' }}</span>
                </div>
            </div>
            @if ($time['brands'] !== [])
                <ul class="card mt-3">
                    @foreach ($time['brands'] as $brand)
                        <li class="card-row py-2.5 text-sm" data-flow-time-brand="{{ $brand['id'] }}">
                            <a href="{{ route('operator.brand', ['brand' => $brand['id']]) }}" wire:navigate class="min-w-0 flex-1 truncate font-medium text-gray-900 hover:underline dark:text-white">{{ $brand['name'] }}</a>
                            <span class="tabular-nums text-gray-500">{{ $n($brand['clicks']) }} tık</span>
                            <span class="w-20 text-right tabular-nums text-gray-900 dark:text-white">{{ OperatorTime::minutesLabel($brand['minutes']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</div>
