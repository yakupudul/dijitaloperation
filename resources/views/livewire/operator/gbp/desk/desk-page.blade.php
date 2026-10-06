@php
    $num = fn (?int $n): string => $n === null ? '—' : number_format($n, 0, ',', '.');
    $delta = function (?int $now, ?int $before): ?int {
        return $now !== null && $before !== null && $before > 0 ? (int) round(($now / $before - 1) * 100) : null;
    };
    $deltaClass = fn (?int $d): string => $d === null ? 'text-gray-400' : ($d >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400');
    $deltaText = fn (?int $d): string => $d === null ? '' : ($d >= 0 ? '▲ %'.$d : '▼ %'.abs($d));
@endphp
<div class="space-y-5 dark:text-gray-200" data-gbp-desk>
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">İşletme profilleri</h1>
            <p class="mt-1 text-xs text-gray-500">Her İşletme Profili için geçen ayın sonuçları ve yerel aramada güçlü olmanın altı koşulu. Eksik olana tıklayınca düzeltileceği yere gidersin.</p>
        </div>
        @include('livewire.operator.gbp.partials.brand-filter')
    </header>
    @include('livewire.operator.gbp.partials.desk-tabs', ['active' => 'operator.gbp-desk', 'brandFilter' => $brand])

    <section>
        <h2 class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-200">{{ $monthLabel }} · {{ $count }} işletme</h2>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ($columns as $key => $label)
                @php $d = $delta($totals[$key], $previous[$key] ?: null); @endphp
                <div class="rounded-xl bg-white p-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <span class="block text-2xl font-semibold text-gray-900 dark:text-white">{{ $num($totals[$key]) }}</span>
                    <span class="block text-xs text-gray-500">{{ $label }} <span class="{{ $deltaClass($d) }}">{{ $deltaText($d) }}</span></span>
                </div>
            @endforeach
        </div>
        <p class="mt-1 text-[11px] text-gray-500">Değişim bir önceki aya göre. Google verisi birkaç gün gecikmeli gelir.</p>
    </section>

    <section class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ($readiness as $key => $item)
            <a href="{{ route($item['route'], array_filter(['marka' => $brand])) }}" wire:navigate class="rounded-xl bg-white p-3 ring-1 ring-inset ring-gray-200 hover:ring-brand-400 dark:bg-gray-800 dark:ring-gray-700">
                <span @class(['block text-lg font-semibold', 'text-emerald-600 dark:text-emerald-400' => $item['ok'] === $count && $count > 0, 'text-amber-600 dark:text-amber-400' => $item['ok'] !== $count || $count === 0])>{{ $item['ok'] }}/{{ $count }}</span>
                <span class="block text-xs text-gray-500">{{ $item['label'] }} tamam</span>
                @if ($item['unknown'] > 0)<span class="block text-[11px] text-gray-400" title="Google bu veriyi vermiyor ya da henüz toplanmadı; işletmenin satırında nedeni yazar">{{ $item['unknown'] }} işletmede veri yok</span>@endif
            </a>
        @endforeach
    </section>

    <section class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <table class="w-full min-w-[760px] text-sm">
            <thead class="text-left text-xs text-gray-500">
                <tr class="border-b border-gray-100 dark:border-gray-700">
                    <th class="px-4 py-2 font-medium">İşletme</th>
                    @foreach ($columns as $label)<th class="px-2 py-2 text-right font-medium">{{ $label }}</th>@endforeach
                    <th class="px-4 py-2 font-medium">Koşullar</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($groups as $brandName => $brandRows)
                    <tr class="bg-gray-50 dark:bg-white/[0.03]"><td colspan="{{ count($columns) + 2 }}" class="px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $brandName }} · {{ $brandRows->count() }}</td></tr>
                    @foreach ($brandRows as $row)
                        @php $location = $row['location']; $r = $row['report']; @endphp
                        <tr wire:key="row-{{ $location->id }}" class="cursor-pointer hover:bg-gray-50 dark:hover:bg-white/[0.03]" wire:click="toggle({{ $location->id }})">
                            <td class="px-4 py-2">
                                <span class="block font-medium text-gray-900 dark:text-white" title="{{ $location->name }}">{{ \App\Services\Gbp\Desk\GbpDesk::shortName((string) $location->name) }}</span>
                                <span class="block text-xs text-gray-500">{{ $row['snapshot']['area'] ?? 'Profil verisi yok' }}@if (($row['snapshot']['rating'] ?? null) !== null) · ★ {{ $row['snapshot']['rating'] }} ({{ $row['snapshot']['reviews'] }})@endif</span>
                                @if ($r === null)<span class="block text-[11px] text-amber-600">{{ $row['performance']['state'] === 'unavailable' ? 'Ölçüm verisi gelmiyor: '.$row['performance']['reason'] : ($row['performance']['state'] === 'never' ? 'Ölçüm verisi henüz toplanmadı' : 'Geçen ayın ölçümü yok') }}</span>@endif
                            </td>
                            @foreach (array_keys($columns) as $key)
                                <td class="px-2 py-2 text-right tabular-nums">
                                    {{ $num($r['current'][$key] ?? null) }}
                                    @if (($r['change'][$key] ?? null) !== null)<span class="block text-[11px] {{ $deltaClass($r['change'][$key]) }}">{{ $deltaText($r['change'][$key]) }}</span>@endif
                                </td>
                            @endforeach
                            <td class="px-4 py-2">
                                <span class="flex flex-wrap gap-1">
                                    @foreach ($row['checks'] as $check)
                                        <a href="{{ route($check['route'], array_filter(['marka' => $location->brand_id])) }}" wire:navigate x-on:click.stop title="{{ $check['label'] }}: {{ $check['hint'] }}"
                                            @class(['rounded-full px-2 py-0.5 text-[11px] font-medium', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $check['ok'], 'bg-gray-100 text-gray-500 dark:bg-white/[0.06] dark:text-gray-400' => $check['unknown'], 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => ! $check['ok'] && ! $check['unknown']])>{{ $check['ok'] ? '✓' : ($check['unknown'] ? '?' : '!') }} {{ $check['label'] }}</a>
                                    @endforeach
                                </span>
                            </td>
                        </tr>
                        @if ($open === (int) $location->id)
                            <tr wire:key="detail-{{ $location->id }}">
                                <td colspan="{{ count($columns) + 2 }}" class="bg-gray-50/60 px-4 py-4 dark:bg-white/[0.02]">
                                    @if ($detail === null)
                                        <p class="text-sm text-gray-500">Bu işletmenin performans verisi henüz toplanmadı.</p>
                                    @else
                                        <div class="grid gap-4 lg:grid-cols-3">
                                            <div>
                                                <h3 class="mb-1 text-xs font-semibold uppercase text-gray-500">Son 6 ay</h3>
                                                <table class="w-full text-xs">
                                                    <thead class="text-gray-500"><tr><th class="text-left font-medium">Ay</th>@foreach ($columns as $label)<th class="text-right font-medium">{{ \Illuminate\Support\Str::before($label, ' ') }}</th>@endforeach</tr></thead>
                                                    <tbody>
                                                        @forelse ($detail['history'] as $h)
                                                            <tr><td>{{ \Illuminate\Support\Carbon::parse($h['month'].'-01')->locale('tr')->translatedFormat('M Y') }}</td>@foreach (array_keys($columns) as $key)<td class="text-right tabular-nums">{{ $num($h[$key]) }}</td>@endforeach</tr>
                                                        @empty
                                                            <tr><td colspan="5" class="text-gray-500">Veri yok.</td></tr>
                                                        @endforelse
                                                    </tbody>
                                                </table>
                                            </div>
                                            <div>
                                                <h3 class="mb-1 text-xs font-semibold uppercase text-gray-500">Google’da bulunduğu aramalar · {{ $detail['keywords']['month'] ? \Illuminate\Support\Carbon::parse($detail['keywords']['month'].'-01')->locale('tr')->translatedFormat('F Y') : $monthLabel }}</h3>
                                                @if ($detail['keywords']['month'] !== null && $detail['keywords']['month'] !== $month)<p class="mb-1 text-[11px] text-amber-600">{{ $monthLabel }} kelimeleri Google’dan henüz gelmedi; son gelen ay gösteriliyor.</p>@endif
                                                @forelse ($detail['keywords']['rows'] as $k)
                                                    <div class="flex justify-between gap-2 text-xs">
                                                        <span class="truncate">{{ $k['keyword'] }} @if ($k['new'])<span class="rounded bg-emerald-50 px-1 text-[10px] text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">yeni</span>@endif</span>
                                                        <span class="tabular-nums text-gray-600 dark:text-gray-300">{{ $k['current'] !== null ? $num($k['current']) : '<'.$k['threshold'] }} @if ($k['previous'] !== null && $k['current'] !== null)<span class="{{ $deltaClass($delta($k['current'], $k['previous'])) }}">{{ $deltaText($delta($k['current'], $k['previous'])) }}</span>@endif</span>
                                                    </div>
                                                @empty
                                                    <p class="text-xs text-gray-500">Google bu işletme için arama kelimesi vermedi (az aranan işletmelerde Google kelimeleri gizler) ya da henüz toplanmadı.</p>
                                                @endforelse
                                            </div>
                                            <div>
                                                <h3 class="mb-1 text-xs font-semibold uppercase text-gray-500">MoxDOP’un son yaptıkları</h3>
                                                @forelse ($detail['work'] as $item)
                                                    <p class="text-xs"><span class="tabular-nums text-gray-500">{{ $item['at'] }}</span> · {{ $item['label'] }} · <span @class(['text-emerald-600' => in_array($item['status'], ['succeeded', 'partial'], true), 'text-rose-600' => $item['status'] === 'failed', 'text-gray-500' => ! in_array($item['status'], ['succeeded', 'partial', 'failed'], true)])>{{ $item['status_label'] }}</span></p>
                                                @empty
                                                    <p class="text-xs text-gray-500">Bu profile MoxDOP’tan henüz bir şey gönderilmedi.</p>
                                                @endforelse
                                                <a href="{{ route('operator.gbp', ['assetId' => $location->id, 'tab' => 'analysis']) }}" wire:navigate class="mt-2 inline-block text-xs text-brand-600 hover:underline">Günlük analiz →</a>
                                            </div>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @endforeach
                @empty
                    <tr><td colspan="{{ count($columns) + 2 }}" class="px-4 py-5 text-sm text-gray-500">Operasyonel markaya bağlı İşletme Profili yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
