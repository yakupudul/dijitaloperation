@php
    $money = fn ($v): string => $v === null ? '—' : number_format((float) $v, 0, ',', '.');
    // The answer first: how many brands pay clearly more than the median of their service.
    $expensive = collect($services)->sum(fn (array $s): int => collect($s['brands'])->filter(fn (array $b): bool => ($b['diff'] ?? 0) >= 15)->count());
    $sub = $services === [] ? null : count($services).' hizmet'.($periodEnd ? ', '.\Carbon\CarbonImmutable::parse($periodEnd)->format('d.m.Y').'’e kadar 30 gün' : '').'. '
        .($expensive > 0 ? $expensive.' markada dönüşüm ortancadan pahalı.' : 'Ortancadan belirgin pahalı marka yok.');
@endphp
<div class="space-y-10 dark:text-gray-200" data-google-ads-tab="operator.google-ads-services">
    @include('livewire.operator.google-ads.desk.header', ['active' => 'operator.google-ads-services', 'sub' => $sub,
        'intro' => 'Aynı hizmette markaların Google Ads dönüşüm başı maliyeti (son 30 gün, anahtar kelime → hizmet eşleşmesiyle). Ortanca en az iki markayla hesaplanır; bir marka en az '.\App\Services\Ads\AdServiceStats::MIN_RESULTS.' dönüşümle listeye girer. Pahalı markada hangi kampanyanın farkı yarattığı hesabın Analiz sekmesinde görülür. Sayılar her sabah yenilenir.'])

    @forelse ($services as $service)
        <section wire:key="svc-{{ $service['service_id'] }}" data-testid="google-ads-service">
            <div class="section-head">
                <h2 class="section-title">{{ $service['name'] }} <span class="section-count">{{ count($service['brands']) }}</span></h2>
                @if ($service['median'] !== null)<span class="text-[13px] text-gray-500">ortanca {{ $money($service['median']) }} / dönüşüm</span>@endif
            </div>
            <div class="card overflow-x-auto">
                <table class="data-table min-w-[560px]">
                    <thead>
                        <tr>
                            <th>Marka</th>
                            <th class="text-right!">Harcama</th>
                            <th class="text-right!">Dönüşüm</th>
                            <th class="text-right!">Dönüşüm başı</th>
                            <th class="text-right!" title="Hizmetin ortancasına göre">Fark</th>
                        </tr>
                    </thead>
                    <tbody class="tabular-nums">
                        @foreach ($service['brands'] as $b)
                            <tr @class(['[&>td]:bg-gray-50 dark:[&>td]:bg-white/[0.03]' => $brand !== null && $b['brand_id'] === $brand])>
                                <td class="w-[40%] max-w-0">
                                    <span class="flex min-w-0 items-center gap-2.5">
                                        <x-ta.brand-avatar :name="$b['brand']" :logo="$b['logo'] ?? null" size="sm" />
                                        <span class="min-w-0 truncate font-medium text-gray-900 dark:text-white" title="{{ $b['brand'] }}">{{ $b['brand'] }}</span>
                                        @if (filled($b['city']))<span class="shrink-0 text-xs text-gray-500">{{ $b['city'] }}</span>@endif
                                    </span>
                                </td>
                                <td class="whitespace-nowrap text-right text-gray-600 dark:text-gray-400">{{ $money($b['spend']) }}</td>
                                <td class="whitespace-nowrap text-right text-gray-600 dark:text-gray-400">{{ number_format($b['results'], 1, ',', '.') }}</td>
                                <td class="whitespace-nowrap text-right font-medium text-gray-900 dark:text-white">{{ $money($b['cost']) }}</td>
                                <td class="w-24 whitespace-nowrap text-right">@if ($b['diff'] !== null)<span @class(['chip', 'chip-down' => $b['diff'] >= 15, 'chip-up' => $b['diff'] <= -15, 'chip-flat' => abs($b['diff']) < 15]) title="{{ $b['diff'] >= 15 ? 'Ortancadan pahalı' : ($b['diff'] <= -15 ? 'Ortancadan ucuz' : 'Ortancaya yakın') }}">{{ $b['diff'] > 0 ? '▲ ' : ($b['diff'] < 0 ? '▼ ' : '') }}{{ number_format(abs($b['diff']), 0, ',', '.') }}%</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @empty
        <div class="card"><x-ta.empty-state icon="none" size="sm" title="Karşılaştırılacak hizmet yok." message="Anahtar kelimeler hizmetlere eşleşip en az {{ \App\Services\Ads\AdServiceStats::MIN_RESULTS }} dönüşüm toplayınca burada görünür." data-testid="google-ads-empty" /></div>
    @endforelse
</div>
