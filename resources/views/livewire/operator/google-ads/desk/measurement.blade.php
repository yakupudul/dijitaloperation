@php
    $date = fn (?string $d): string => $d ? \Carbon\CarbonImmutable::parse($d)->format('d.m.Y') : '—';
    $month = fn (string $m): string => \Illuminate\Support\Carbon::parse($m.'-01')->locale('tr')->translatedFormat('F Y');
@endphp
<div class="space-y-5 dark:text-gray-200" data-google-ads-tab="operator.google-ads-measurement">
    @include('livewire.operator.google-ads.desk.header', ['active' => 'operator.google-ads-measurement',
        'intro' => 'Google Ads’in neyi dönüşüm saydığı ve gerçek sonuçla ne kadar örtüştüğü. Teklif stratejisi birincil dönüşümlere göre çalışır; sayfa görüntüleme gibi zayıf bir birincil hedef ya da eksik telefon dönüşümü bütçeyi yanlış yöne götürür. Gerçek sonuçlar (form, uygun müşteri, randevu, satış) her ay hesabın Ölçümleme sekmesine girilir.'])

    <section class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <table class="w-full min-w-[900px] text-sm" data-testid="google-ads-measurement">
            <thead class="text-left text-xs text-gray-500">
                <tr class="border-b border-gray-100 dark:border-gray-700">
                    <th class="px-4 py-2 font-medium">Hesap</th>
                    <th class="px-3 py-2 font-medium">Birincil dönüşümler</th>
                    <th class="px-3 py-2 font-medium">Son dönüşüm</th>
                    <th class="px-3 py-2 font-medium">Kontroller</th>
                    <th class="px-3 py-2 font-medium">Gerçek sonuç girişi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($rows as $row)
                    @php $asset = $row['asset']; @endphp
                    <tr class="align-top" wire:key="m-{{ $asset->id }}">
                        <td class="px-4 py-2">
                            <span class="block font-medium text-gray-900 dark:text-white">{{ $row['brand'] }}</span>
                            <span class="block text-xs text-gray-500">{{ $asset->name }} · {{ $row['actions'] }} etkin dönüşüm işlemi</span>
                        </td>
                        <td class="px-3 py-2 text-xs">
                            @forelse ($row['primary'] as $p)
                                <span class="block">{{ $p['name'] }} <span class="text-gray-500">· {{ $p['conversions'] !== null ? number_format((float) $p['conversions'], 0, ',', '.').' / 30 gün' : 'veri yok' }}</span></span>
                            @empty
                                <span class="font-medium text-rose-600">Birincil dönüşüm yok</span>
                            @endforelse
                            @foreach ($row['weak_primary'] as $p)
                                <span class="mt-0.5 block text-amber-700 dark:text-amber-300">! «{{ $p['name'] }}» zayıf bir işlem ama birincil: teklif bunu çoğaltmaya çalışır.</span>
                            @endforeach
                            @if (! $row['has_phone'] && $row['actions'] > 0)
                                <span class="mt-0.5 block text-amber-700 dark:text-amber-300">! Telefon araması dönüşümü yok.</span>
                            @endif
                        </td>
                        <td @class(['px-3 py-2 text-xs tabular-nums', 'text-rose-600' => $row['last_conversion'] !== null && $row['last_conversion'] < now()->subDays(7)->toDateString()])>{{ $date($row['last_conversion']) }}</td>
                        <td class="px-3 py-2">
                            <span class="flex flex-wrap gap-1">
                                @if ($row['checks'] === null)
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] text-gray-500 dark:bg-white/[0.06]">? Kontrol henüz yok</span>
                                @else
                                    @foreach ($row['checks'] as $check)
                                        <span title="{{ $check['reason'] }}" @class(['rounded-full px-2 py-0.5 text-[11px] font-medium', 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => $check['state'] === 'fail', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $check['state'] === 'pass', 'bg-gray-100 text-gray-500 dark:bg-white/[0.06]' => $check['state'] === 'nodata'])>{{ ['fail' => '!', 'pass' => '✓', 'nodata' => '?'][$check['state']] ?? '' }} {{ $check['label'] }}</span>
                                    @endforeach
                                @endif
                            </span>
                        </td>
                        <td class="px-3 py-2 text-xs">
                            @if ($row['quality_entered'])
                                <span class="text-emerald-600">✓ {{ $month($row['quality_month']) }} girildi</span>
                            @elseif ($row['quality_campaigns'] > 0)
                                <span class="text-amber-700 dark:text-amber-300">{{ $month($row['quality_month']) }} girilmedi</span>
                            @else
                                <span class="text-gray-400">Geçen ay kampanya verisi yok</span>
                            @endif
                            <a href="{{ route('operator.google-ads.overview', ['assetId' => $asset->id, 'tab' => 'measurement']) }}" wire:navigate class="block text-brand-600 hover:underline">Ölçümlemeyi aç →</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-5 text-sm text-gray-500">Operasyonel markaya bağlı Google Ads hesabı yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
