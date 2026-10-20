@php
    $money = fn ($v): string => $v === null ? '—' : number_format((float) $v, 0, ',', '.');
@endphp
<div class="space-y-10 dark:text-gray-200" data-google-ads-tab="operator.google-ads-services">
    @include('livewire.operator.google-ads.desk.header', ['active' => 'operator.google-ads-services',
        'intro' => 'Aynı hizmette markaların Google Ads dönüşüm başı maliyeti (son 30 gün, anahtar kelime → hizmet eşleşmesiyle). Ortanca en az iki markayla hesaplanır; bir marka en az '.\App\Services\Ads\AdServiceStats::MIN_RESULTS.' dönüşümle listeye girer. Pahalı markada hangi kampanyanın farkı yarattığı hesabın Analiz sekmesinde görülür.'])
    @if ($periodEnd)<p class="-mt-6 text-sm text-gray-500" title="Sayılar her sabah yenilenir">{{ \Carbon\CarbonImmutable::parse($periodEnd)->format('d.m.Y') }}’e kadar</p>@endif

    @forelse ($services as $service)
        <section class="overflow-x-auto" wire:key="svc-{{ $service['service_id'] }}" data-testid="google-ads-service">
            <h2 class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                <span class="section-title">{{ $service['name'] }}</span>
                <span class="text-sm text-gray-500">{{ count($service['brands']) }} marka @if ($service['median'] !== null)<span class="-ml-1">, ortanca {{ $money($service['median']) }} / dönüşüm</span>@endif</span>
            </h2>
            <table class="w-full min-w-[520px] text-sm">
                <tbody class="divide-y divide-gray-200 border-y border-gray-200 tabular-nums dark:divide-gray-800 dark:border-gray-800">
                    @foreach ($service['brands'] as $b)
                        <tr @class(['bg-gray-50 dark:bg-white/[0.03]' => $brand !== null && $b['brand_id'] === $brand])>
                            <td class="py-2 pr-4 font-medium text-gray-900 dark:text-white">{{ $b['brand'] }} <span class="text-xs font-normal text-gray-500">{{ $b['city'] }}</span></td>
                            <td class="whitespace-nowrap px-3 py-1.5 text-right text-xs text-gray-500">{{ $money($b['spend']) }} harcama, {{ number_format($b['results'], 1, ',', '.') }} dönüşüm</td>
                            <td class="whitespace-nowrap px-3 py-1.5 text-right font-medium">{{ $money($b['cost']) }}</td>
                            <td @class(['w-20 px-3 py-1.5 text-right text-xs', 'text-error-700' => ($b['diff'] ?? 0) >= 15, 'text-success-700' => ($b['diff'] ?? 0) <= -15, 'text-gray-500' => $b['diff'] === null || abs($b['diff']) < 15])>{{ $b['diff'] === null ? '' : (($b['diff'] > 0 ? '+' : '').number_format($b['diff'], 0, ',', '.').'%') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @empty
        <x-ta.empty-state size="sm" title="Karşılaştırılacak hizmet yok." message="Anahtar kelimeler hizmetlere eşleşip en az {{ \App\Services\Ads\AdServiceStats::MIN_RESULTS }} dönüşüm toplayınca burada görünür." data-testid="google-ads-empty" />
    @endforelse
</div>
