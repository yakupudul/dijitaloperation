@php
    $date = fn (?string $d): string => $d ? \Carbon\CarbonImmutable::parse($d)->format('d.m.Y') : '—';
    $month = fn (string $m): string => \Illuminate\Support\Carbon::parse($m.'-01')->locale('tr')->translatedFormat('F Y');
@endphp
<div class="space-y-8 dark:text-gray-200" data-google-ads-tab="operator.google-ads-measurement">
    @include('livewire.operator.google-ads.desk.header', ['active' => 'operator.google-ads-measurement',
        'intro' => 'Google Ads’in neyi dönüşüm saydığı ve gerçek sonuçla ne kadar örtüştüğü. Teklif stratejisi birincil dönüşümlere göre çalışır; sayfa görüntüleme gibi zayıf bir birincil hedef ya da eksik telefon dönüşümü bütçeyi yanlış yöne götürür. Gerçek sonuçlar (form, uygun müşteri, randevu, satış) her ay hesabın Ölçümleme sekmesine girilir.'])

    <section class="overflow-x-auto">
        <table class="w-full min-w-[900px] text-sm" data-testid="google-ads-measurement">
            <thead class="text-left text-xs text-gray-500 dark:text-gray-400">
                <tr class="border-b border-gray-200 dark:border-gray-800">
                    <th class="py-2 pr-4 font-normal">Hesap</th>
                    <th class="px-3 py-2 font-normal">Birincil dönüşümler</th>
                    <th class="px-3 py-2 font-normal">Son dönüşüm</th>
                    <th class="px-3 py-2 font-normal">Kontroller</th>
                    <th class="px-3 py-2 font-normal">Gerçek sonuç girişi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                @forelse ($rows as $row)
                    @php $asset = $row['asset']; @endphp
                    <tr class="align-top" wire:key="m-{{ $asset->id }}">
                        <td class="py-2.5 pr-4">
                            <span class="flex items-center gap-2"><x-ta.brand-avatar :name="$row['brand']" size="xs" /><span class="font-medium text-gray-900 dark:text-white">{{ $row['brand'] }}</span></span>
                            <span class="block text-xs text-gray-500">{{ $asset->name }}, {{ $row['actions'] }} etkin dönüşüm işlemi</span>
                        </td>
                        <td class="px-3 py-2 text-xs">
                            @forelse ($row['primary'] as $p)
                                <span class="block">{{ $p['name'] }} <span class="text-gray-500">{{ $p['conversions'] !== null ? number_format((float) $p['conversions'], 0, ',', '.').' / 30 gün' : 'veri yok' }}</span></span>
                            @empty
                                <span class="font-medium text-error-700">Birincil dönüşüm yok</span>
                            @endforelse
                            @foreach ($row['weak_primary'] as $p)
                                <span class="mt-0.5 block text-warning-700 dark:text-warning-300">«{{ $p['name'] }}» zayıf bir işlem ama birincil: teklif bunu çoğaltmaya çalışır.</span>
                            @endforeach
                            @if (! $row['has_phone'] && $row['actions'] > 0)
                                <span class="mt-0.5 block text-warning-700 dark:text-warning-300">Telefon araması dönüşümü yok.</span>
                            @endif
                        </td>
                        <td @class(['px-3 py-2 text-xs tabular-nums', 'text-error-700' => $row['last_conversion'] !== null && $row['last_conversion'] < now()->subDays(7)->toDateString()])>{{ $date($row['last_conversion']) }}</td>
                        <td class="px-3 py-2">
                            <span class="flex flex-col gap-0.5">
                                @if ($row['checks'] === null)
                                    <span class="text-xs text-gray-500">Kontrol henüz yok</span>
                                @else
                                    @foreach ($row['checks'] as $check)
                                        <x-ta.pill :tone="['fail' => 'look', 'pass' => 'ok'][$check['state']] ?? 'info'" title="{{ $check['reason'] }}">{{ $check['label'] }}</x-ta.pill>
                                    @endforeach
                                @endif
                            </span>
                        </td>
                        <td class="px-3 py-2 text-xs">
                            @if ($row['quality_entered'])
                                <x-ta.pill tone="ok">{{ $month($row['quality_month']) }} girildi</x-ta.pill>
                            @elseif ($row['quality_campaigns'] > 0)
                                <x-ta.pill tone="look">{{ $month($row['quality_month']) }} girilmedi</x-ta.pill>
                            @else
                                <span class="text-gray-500">Geçen ay kampanya verisi yok</span>
                            @endif
                            <a href="{{ route('operator.google-ads.overview', ['assetId' => $asset->id, 'tab' => 'measurement']) }}" wire:navigate class="link-neutral mt-0.5 block">Ölçümlemeyi aç</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-5 text-sm text-gray-500">Operasyonel markaya bağlı Google Ads hesabı yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
