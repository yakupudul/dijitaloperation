@php
    $money = fn ($v): string => $v === null ? '—' : number_format((float) $v, 0, ',', '.');
@endphp
<div class="space-y-5 dark:text-gray-200" data-google-ads-tab="operator.google-ads-services">
    @include('livewire.operator.google-ads.desk.header', ['active' => 'operator.google-ads-services',
        'intro' => 'Aynı hizmette markaların Google Ads dönüşüm başı maliyeti (son 30 gün, anahtar kelime → hizmet eşleşmesiyle). Ortanca en az iki markayla hesaplanır; bir marka en az '.\App\Services\Ads\AdServiceStats::MIN_RESULTS.' dönüşümle listeye girer. Pahalı markada hangi kampanyanın farkı yarattığı hesabın Analiz sekmesinde görülür.'])
    @if ($periodEnd)<p class="-mt-3 text-[11px] text-gray-500">Sayılar her sabah yenilenir · {{ \Carbon\CarbonImmutable::parse($periodEnd)->format('d.m.Y') }}’e kadar.</p>@endif

    @forelse ($services as $service)
        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700" wire:key="svc-{{ $service['service_id'] }}" data-testid="google-ads-service">
            <h2 class="flex flex-wrap items-baseline justify-between gap-2 border-b border-gray-100 px-4 py-2 dark:border-gray-700">
                <span class="font-semibold text-gray-900 dark:text-white">{{ $service['name'] }}</span>
                <span class="text-xs text-gray-500">{{ count($service['brands']) }} marka @if ($service['median'] !== null)· ortanca {{ $money($service['median']) }} / dönüşüm @endif</span>
            </h2>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100 tabular-nums dark:divide-gray-700">
                    @foreach ($service['brands'] as $b)
                        <tr @class(['bg-brand-50/40 dark:bg-brand-500/5' => $brand !== null && $b['brand_id'] === $brand])>
                            <td class="px-4 py-1.5 font-medium text-gray-900 dark:text-white">{{ $b['brand'] }} <span class="text-xs font-normal text-gray-500">{{ $b['city'] }}</span></td>
                            <td class="px-3 py-1.5 text-right text-xs text-gray-500">{{ $money($b['spend']) }} harcama · {{ number_format($b['results'], 1, ',', '.') }} dönüşüm</td>
                            <td class="px-3 py-1.5 text-right font-semibold">{{ $money($b['cost']) }}</td>
                            <td @class(['w-20 px-3 py-1.5 text-right text-xs font-semibold', 'text-rose-600' => ($b['diff'] ?? 0) >= 15, 'text-emerald-600' => ($b['diff'] ?? 0) <= -15, 'text-gray-500' => $b['diff'] === null || abs($b['diff']) < 15])>{{ $b['diff'] === null ? '' : (($b['diff'] > 0 ? '+' : '').number_format($b['diff'], 0, ',', '.').'%') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @empty
        <p class="rounded-xl bg-white p-4 text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700" data-testid="google-ads-empty">Karşılaştırılacak hizmet yok. Anahtar kelimeler hizmetlere eşleşip en az {{ \App\Services\Ads\AdServiceStats::MIN_RESULTS }} dönüşüm toplayınca burada görünür.</p>
    @endforelse
</div>
