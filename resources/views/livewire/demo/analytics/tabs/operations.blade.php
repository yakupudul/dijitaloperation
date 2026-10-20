@php
    $opsData = $data['operations'] ?? [];
    $subTabs = ['findings' => ['Bulgular', count($opsData['findings'] ?? [])], 'recommendations' => ['Öneriler', count($opsData['recommendations'] ?? [])], 'tasks' => ['Görevler', count($opsData['tasks'] ?? [])], 'outcomes' => ['Sonuçlar', count($opsData['outcomes'] ?? [])]];
@endphp

<div class="space-y-3">
    <div class="flex gap-1.5 overflow-x-auto no-scrollbar" role="group" aria-label="Operasyon" title="{{ $opsData['subtitle'] ?? '' }}">
        @foreach ($subTabs as $key => [$label, $n])
            <button type="button" wire:click="setOps('{{ $key }}')" @class(['seg', 'seg-active' => $ops === $key])>{{ $label }}@if ($n > 0) <span @class(['tabular-nums text-xs', 'text-accent' => $ops === $key, 'text-gray-400 dark:text-gray-500' => $ops !== $key])>{{ $n }}</span>@endif</button>
        @endforeach
    </div>
    <div class="card">

        @if ($ops === 'findings')
            @if (($opsData['findings'] ?? []) === [])
                <x-ta.empty-state title="Bulgu yok" message="Analytics verisinden çıkan bulgular burada listelenir." />
            @else
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Bulgu</th>
                                <th class="hidden sm:table-cell">Konu</th>
                                <th>Önem</th>
                                <th class="hidden md:table-cell">Durum</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($opsData['findings'] as $f)
                                <tr wire:key="ga4-finding-{{ $f['id'] }}">
                                    <td class="min-w-[14rem]"><button type="button" wire:click="openFinding('{{ $f['id'] }}')" class="text-left font-medium text-gray-900 hover:underline dark:text-white">{{ $f['title'] }}</button></td>
                                    <td class="hidden text-[13px] text-gray-500 sm:table-cell">{{ $say($f['category'] ?? null) }}</td>
                                    <td class="whitespace-nowrap"><x-ta.pill :tone="$toneOf($f['severity'] ?? null)">{{ $say($f['severity'] ?? null) }}</x-ta.pill></td>
                                    <td class="hidden text-[13px] text-gray-600 md:table-cell dark:text-gray-300">{{ $say($f['status'] ?? null) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @elseif ($ops === 'recommendations')
            <ul>
                @forelse ($opsData['recommendations'] ?? [] as $r)
                    <li class="card-row flex-wrap sm:flex-nowrap">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $r['title'] }}</p>
                            <p class="mt-0.5 text-[13px] text-gray-500">{{ $say($r['status'] ?? null) }}{{ ! empty($r['detail']) ? '. '.$r['detail'] : '' }}</p>
                        </div>
                        <button type="button" wire:click="openFinding('{{ $r['finding_id'] }}')" class="btn h-7! px-2.5! text-xs! w-full sm:w-auto">Bulguyu aç</button>
                    </li>
                @empty
                    <li><x-ta.empty-state icon="none" title="Öneri yok" /></li>
                @endforelse
            </ul>
        @elseif ($ops === 'tasks')
            @if (($opsData['tasks'] ?? []) === [])
                <x-ta.empty-state icon="none" title="Görev yok" message="Görevler kendiliğinden açılmaz." />
            @else
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Görev</th>
                                <th class="hidden sm:table-cell">Kimde</th>
                                <th class="hidden sm:table-cell">Tarih</th>
                                <th>Durum</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($opsData['tasks'] as $t)
                                <tr>
                                    <td class="min-w-[12rem] font-medium text-gray-900 dark:text-white">{{ $t['title'] }}</td>
                                    <td class="hidden text-[13px] text-gray-600 sm:table-cell dark:text-gray-300">{{ $t['owner'] }}</td>
                                    <td class="hidden text-[13px] whitespace-nowrap text-gray-600 sm:table-cell dark:text-gray-300">{{ $t['due'] }}</td>
                                    <td class="whitespace-nowrap"><x-ta.pill :tone="$toneOf($t['status'] ?? null)">{{ $say($t['status'] ?? null) }}</x-ta.pill></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @else
            <ul>
                @forelse ($opsData['outcomes'] ?? [] as $o)
                    <li class="card-row flex-wrap sm:flex-nowrap">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $o['task'] ?? $o['title'] }}</p>
                            <p class="mt-0.5 text-[13px] text-gray-500">{{ $o['note'] }}</p>
                        </div>
                        <x-ta.pill :tone="$toneOf($o['state'] ?? null)">{{ $say($o['state'] ?? null) }}</x-ta.pill>
                    </li>
                @empty
                    <li><x-ta.empty-state icon="none" title="Henüz sonuç yok" /></li>
                @endforelse
            </ul>
        @endif
    </div>

    @if ($ops === 'outcomes')
        <p class="text-xs text-gray-500 dark:text-gray-400">Sonuçlar yalnız gözlemdir; değişimi görevin yaptığını iddia etmez.</p>

        @if (! empty($opsData['decision_history']))
            <section class="pt-7">
                <div class="section-head"><h2 class="section-title">Karar geçmişi</h2></div>
                <ul class="card">
                    @foreach ($opsData['decision_history'] as $event)
                        <li class="card-row py-3 text-sm">
                            <span class="w-16 shrink-0 text-xs text-gray-500">{{ $event['when'] }}</span>
                            <span class="min-w-0 text-gray-800 dark:text-gray-200">{{ $event['event'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endif
</div>
