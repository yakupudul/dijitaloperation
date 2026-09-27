@php
    $usd = fn (?float $value): string => $value === null ? '—' : '$'.number_format($value, $value > 0 && $value < 1 ? 3 : 2, ',', '.');
    $card = 'overflow-x-auto rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
@endphp
<div class="space-y-5">
    <div>
        <a href="{{ route('operator.settings', ['section' => 'operations']) }}" wire:navigate class="text-sm text-gray-500 hover:text-brand-600">← Ayarlar</a>
        <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">AI kalitesi</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">AI'ın ürettiklerinden ne kadarının kullanıldığı. Sonuç iş kaydındaki kararınızdan gelir (öneri yapıldı / atlandı, düzeltme uygulandı / reddedildi, yorum yanıtı yayınlandı, rapor yayınlandı…); Üretim Arşivi'ndeki işaret (kullanıldı / atıldı, 👍 / 👎) önceliklidir. Yenisi üretilen ve kullanılmayan sürüm reddedilmiş sayılır. Kabul oranı = kabul / (kabul + red); en az {{ \App\Services\Operations\AiQualityReport::MIN_DECIDED }} kararda %{{ (int) \App\Services\Operations\AiQualityReport::LOW_ACCEPTANCE }} altı işaretlenir.</p>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <select wire:model.live="days" aria-label="Dönem" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
            <option value="30">Son 30 gün</option>
            <option value="90">Son 90 gün</option>
        </select>
        <span class="text-sm text-gray-600 dark:text-gray-300">Toplam AI maliyeti: <strong>{{ $usd($report['total_cost']) }}</strong></span>
        @if ($report['flagged'] > 0)
            <span class="rounded-full bg-rose-50 px-2.5 py-0.5 text-xs font-medium text-rose-700 ring-1 ring-inset ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300">{{ $report['flagged'] }} kaynakta kabul oranı düşük</span>
        @endif
    </div>

    @foreach ($report['groups'] as $group)
        <section class="{{ $card }}">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $group['label'] }}</h2>
                <span class="text-xs text-gray-500">Grup maliyeti {{ $usd($group['cost']) }}</span>
            </div>
            @if ($group['rows'] === [])
                <p class="mt-3 text-sm text-gray-500">Bu dönemde üretim yok.</p>
            @else
                <table class="mt-3 w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-400">
                        <tr>
                            <th class="py-2 pr-3">Kaynak</th>
                            <th class="py-2 pr-3">Sürüm</th>
                            <th class="py-2 pr-3 text-right">Üretilen</th>
                            <th class="py-2 pr-3 text-right">Kabul</th>
                            <th class="py-2 pr-3 text-right">Düzenlenerek</th>
                            <th class="py-2 pr-3 text-right">Red</th>
                            <th class="py-2 pr-3 text-right">Bekleyen</th>
                            <th class="py-2 pr-3 text-right">Kabul oranı</th>
                            <th class="py-2 text-right">Maliyet</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($group['rows'] as $row)
                            <tr @class(['text-gray-500' => ! $row['is_total']])>
                                <td class="py-2 pr-3 {{ $row['is_total'] ? 'font-medium text-gray-800 dark:text-gray-200' : 'pl-4' }}">
                                    @if ($row['is_total']){{ $row['label'] }}@else ↳ @endif
                                    @if ($row['flagged'])<span class="ml-1 rounded bg-rose-50 px-1.5 py-0.5 text-xs font-medium text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">Düşük kabul</span>@endif
                                </td>
                                <td class="py-2 pr-3 text-xs">{{ $row['version'] ?? ($row['is_total'] ? 'tümü' : '—') }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $row['produced'] }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $row['accepted'] }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $row['edited'] }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $row['rejected'] }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $row['pending'] }}</td>
                                <td @class(['py-2 pr-3 text-right tabular-nums', 'font-semibold text-rose-600' => $row['flagged']])>{{ $row['acceptance'] !== null ? '%'.number_format($row['acceptance'], 1, ',', '.') : '—' }}</td>
                                <td class="py-2 text-right tabular-nums">{{ $row['is_total'] ? $usd($row['cost']) : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    @endforeach

    <section class="{{ $card }}">
        <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">AI maliyeti (rota bazında)</h2>
        <p class="mt-1 text-xs text-gray-500">Kaydedilen tüm AI çağrıları. "Ölçülmüyor" rotaların çıktısı için kabul bilgisi yok (ör. arka plan sınıflandırma, rakip analizi).</p>
        <table class="mt-3 w-full text-sm">
            <thead class="text-left text-xs uppercase text-gray-400">
                <tr><th class="py-2 pr-3">Rota</th><th class="py-2 pr-3 text-right">Çağrı</th><th class="py-2 pr-3 text-right">Maliyet</th><th class="py-2">Kalite ölçümü</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($report['routes'] as $route)
                    <tr>
                        <td class="py-2 pr-3 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $route['route'] }}</td>
                        <td class="py-2 pr-3 text-right tabular-nums">{{ $route['calls'] }}</td>
                        <td class="py-2 pr-3 text-right tabular-nums">{{ $usd($route['cost']) }}</td>
                        <td class="py-2 text-xs {{ $route['mapped'] ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-400' }}">{{ $route['mapped'] ? 'Ölçülüyor' : 'Ölçülmüyor' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-3 text-gray-500">Bu dönemde kayıtlı AI çağrısı yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
