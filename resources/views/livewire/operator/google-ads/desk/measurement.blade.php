@php
    $date = fn (?string $d): string => $d ? \Carbon\CarbonImmutable::parse($d)->format('d.m.Y') : '—';
    $month = fn (string $m): string => \Illuminate\Support\Carbon::parse($m.'-01')->locale('tr')->translatedFormat('F Y');
    // An account measures wrong when it has no primary conversion, a weak primary one, no phone conversion, or silence for a week.
    $weekAgo = now()->subDays(7)->toDateString();
    $wrong = collect($rows)->filter(fn (array $r): bool => $r['primary'] === [] || $r['weak_primary'] !== [] || (! $r['has_phone'] && $r['actions'] > 0)
        || ($r['last_conversion'] !== null && $r['last_conversion'] < $weekAgo))->count();
    $sub = $rows === [] ? null : ($wrong > 0 ? count($rows).' hesabın '.$wrong.' tanesinde teklif yanlış ya da eksik dönüşüme göre çalışıyor.' : count($rows).' hesap, ölçümde bariz sorun yok.');
@endphp
<div class="space-y-10 dark:text-gray-200" data-google-ads-tab="operator.google-ads-measurement">
    @include('livewire.operator.google-ads.desk.header', ['active' => 'operator.google-ads-measurement', 'sub' => $sub,
        'intro' => 'Google Ads’in neyi dönüşüm saydığı ve gerçek sonuçla ne kadar örtüştüğü. Teklif stratejisi birincil dönüşümlere göre çalışır; sayfa görüntüleme gibi zayıf bir birincil hedef ya da eksik telefon dönüşümü bütçeyi yanlış yöne götürür. Gerçek sonuçlar (form, uygun müşteri, randevu, satış) her ay hesabın Ölçümleme sekmesine girilir.'])

    <section>
        <div class="section-head">
            <h2 class="section-title">Hesaplar <span class="section-count">{{ count($rows) }}</span></h2>
            <span class="text-[13px] text-gray-500" title="Teklif stratejisi birincil dönüşümlere göre çalışır.">Birincil dönüşüm, teklifin neyi çoğaltmaya çalıştığıdır</span>
        </div>
        <div class="card overflow-x-auto">
            <table class="data-table min-w-[860px]" data-testid="google-ads-measurement">
                <thead>
                    <tr>
                        <th>Hesap</th>
                        <th>Birincil dönüşümler</th>
                        <th>Son dönüşüm</th>
                        <th>Kontroller</th>
                        <th>Gerçek sonuç girişi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php $asset = $row['asset']; @endphp
                        <tr class="align-top" wire:key="m-{{ $asset->id }}">
                            <td class="w-[26%] max-w-0">
                                <span class="flex min-w-0 items-start gap-2.5">
                                    <x-ta.brand-avatar :name="$row['brand']" :logo="$asset->brand?->logo_url" class="mt-0.5" />
                                    <span class="min-w-0 flex-1">
                                        <span class="block break-words font-medium text-gray-900 dark:text-white">{{ $row['brand'] }}</span>
                                        <span class="block truncate text-xs text-gray-500" title="{{ $asset->name }}">{{ $row['actions'] }} etkin dönüşüm işlemi, {{ $asset->name }}</span>
                                    </span>
                                </span>
                            </td>
                            <td class="text-[13px]">
                                @forelse ($row['primary'] as $p)
                                    <span class="block">{{ $p['name'] }} <span class="text-gray-500">{{ $p['conversions'] !== null ? number_format((float) $p['conversions'], 0, ',', '.').' / 30 gün' : 'veri yok' }}</span></span>
                                @empty
                                    <x-ta.pill tone="critical">Birincil dönüşüm yok</x-ta.pill>
                                @endforelse
                                @foreach ($row['weak_primary'] as $p)
                                    <span class="mt-0.5 block text-xs text-warning-700 dark:text-warning-300">«{{ $p['name'] }}» zayıf bir işlem ama birincil: teklif bunu çoğaltmaya çalışır.</span>
                                @endforeach
                                @if (! $row['has_phone'] && $row['actions'] > 0)
                                    <span class="mt-0.5 block text-xs text-warning-700 dark:text-warning-300">Telefon araması dönüşümü yok.</span>
                                @endif
                            </td>
                            <td @class(['whitespace-nowrap text-[13px] tabular-nums', 'text-error-700 dark:text-error-400' => $row['last_conversion'] !== null && $row['last_conversion'] < $weekAgo])>{{ $date($row['last_conversion']) }}</td>
                            <td>
                                <span class="flex flex-col gap-1">
                                    @if ($row['checks'] === null)
                                        <span class="text-xs text-gray-500">Kontrol henüz yok</span>
                                    @else
                                        @foreach ($row['checks'] as $check)
                                            <x-ta.pill :tone="['fail' => 'look', 'pass' => 'ok'][$check['state']] ?? 'info'" title="{{ $check['reason'] }}">{{ $check['label'] }}</x-ta.pill>
                                        @endforeach
                                    @endif
                                </span>
                            </td>
                            <td class="text-[13px]">
                                @if ($row['quality_entered'])
                                    <x-ta.pill tone="ok">{{ $month($row['quality_month']) }} girildi</x-ta.pill>
                                @elseif ($row['quality_campaigns'] > 0)
                                    <x-ta.pill tone="look">{{ $month($row['quality_month']) }} girilmedi</x-ta.pill>
                                @else
                                    <span class="text-xs text-gray-500">Geçen ay kampanya verisi yok</span>
                                @endif
                                <a href="{{ route('operator.google-ads.overview', ['assetId' => $asset->id, 'tab' => 'measurement']) }}" wire:navigate class="link-neutral mt-1 block text-xs">Ölçümlemeyi aç</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-0"><x-ta.empty-state size="sm" title="Operasyonel markaya bağlı Google Ads hesabı yok." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
