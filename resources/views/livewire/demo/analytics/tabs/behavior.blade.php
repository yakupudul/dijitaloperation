@php
    $behavior = $data['behavior'] ?? [];
    $rows = $behavior['landing_pages'] ?? [];
    $engagement = $behavior['engagement'] ?? [];

    /** Reads an engagement value the Turkish way: "59.6%" → "%59,6", "1m 42s" → "1 dk 42 sn", "2.4" → "2,4". */
    $metricValue = static function (mixed $raw): string {
        if (in_array($raw, ['Unavailable', null, ''], true)) {
            return '—';
        }
        $text = trim((string) $raw);
        if (preg_match('/^(\d+(?:\.\d+)?)\s?%$/', $text, $match)) {
            return '%'.str_replace('.', ',', $match[1]);
        }
        if (preg_match('/^\d+\.\d+$/', $text)) {
            return str_replace('.', ',', $text);
        }

        return preg_replace(['/(\d+)m\b/', '/(\d+)s\b/'], ['$1 dk', '$1 sn'], $text);
    };
@endphp

<div class="space-y-10">
    {{-- 1. How engaged the visits are. --}}
    @if ($engagement !== [])
        <section>
            <div @class(['metric-strip grid-cols-2', 'lg:grid-cols-3' => count($engagement) === 3, 'lg:grid-cols-4' => count($engagement) >= 4])>
                @foreach ($engagement as $metricRow)
                    @php $value = $metricValue($metricRow['value'] ?? null); @endphp
                    <x-ta.metric-card :label="$say($metricRow['metric'])" :value="$value" :muted="$value === '—'" :hint="$say($metricRow['state'] ?? null)" :class="$loop->last && $loop->odd ? 'col-span-2 lg:col-span-1' : ''" />
                @endforeach
            </div>
        </section>
    @endif

    {{-- 2. Which pages the visits land on and what they lead to. --}}
    <section>
        <div class="section-head">
            <h2 class="section-title" title="{{ $behavior['subtitle'] ?? '' }}">Giriş sayfaları <span class="section-count">{{ count($rows) }}</span></h2>
        </div>
        <div class="card overflow-x-auto">
            @if ($rows === [])
                <x-ta.empty-state icon="none" title="Giriş sayfası verisi yok" message="Analytics sayfa bazında oturum gönderince burada görünür." />
            @else
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Sayfa</th>
                            <th class="hidden lg:table-cell">İçerik rolü</th>
                            <th class="text-right!">Oturum</th>
                            <th class="hidden text-right! sm:table-cell">Etkileşim</th>
                            <th class="hidden text-right! sm:table-cell">Aksiyon</th>
                            <th class="hidden lg:table-cell">Site uyarısı</th>
                            <th><span class="sr-only">İncele</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr wire:key="ga4-landing-{{ $row['path'] }}">
                                <td class="min-w-[12rem]">
                                    <p class="font-medium text-gray-900 dark:text-white">{{ $row['path'] }}</p>
                                    <p class="text-[13px] text-gray-500">{{ $row['title'] ?? '' }}</p>
                                </td>
                                <td class="hidden text-[13px] text-gray-600 lg:table-cell dark:text-gray-300">{{ $say($row['content_role'] ?? null) }}</td>
                                <td class="text-right tabular-nums text-gray-900 dark:text-white">{{ $num($row['sessions']) }}</td>
                                <td class="hidden text-right tabular-nums sm:table-cell" title="{{ $num($row['engaged_sessions'] ?? 0) }} etkileşimli oturum">%{{ $row['engaged_rate'] }}</td>
                                <td @class(['hidden text-right tabular-nums sm:table-cell', 'text-gray-400' => $count($row, 'mapped_actions') === '—'])>{{ $count($row, 'mapped_actions') }}</td>
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
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Aksiyon eşlenmemiş sayfa başarısız dönüşüm demek değildir.</p>
    </section>

    {{-- 3. Devices. --}}
    @if (! empty($behavior['devices']))
        <section>
            <div class="section-head"><h2 class="section-title">Cihazlar</h2></div>
            <ul class="card">
                @foreach ($behavior['devices'] as $device)
                    <li class="card-row flex-col items-stretch gap-1.5 py-3">
                        <div class="flex items-baseline justify-between gap-3 text-sm">
                            <span class="text-gray-900 dark:text-white">{{ $say($device['device']) }}</span>
                            <span class="tabular-nums text-gray-900 dark:text-white">{{ $num($device['sessions']) }} <span class="text-[13px] text-gray-500">%{{ $device['share_pct'] }}</span></span>
                        </div>
                        <div class="h-1 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5">
                            <div class="h-full rounded-full bg-second" style="width: {{ min(100, (int) $device['share_pct']) }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
