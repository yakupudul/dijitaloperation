@php
    use App\Services\Brain\Clustering\PageTypes;
    $card = 'rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $typeColor = ['main' => 'bg-brand-50 text-brand-700', 'landing' => 'bg-blue-50 text-blue-700', 'support' => 'bg-emerald-50 text-emerald-700', 'faq' => 'bg-gray-100 text-gray-600'];
@endphp
<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Hizmet haritası</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Bir hizmetin sorguları sayfa boyutunda kümelere ayrılır: her küme tek bir sayfanın cevaplayabileceği konudur. Her markanın hangi sayfasının hangi kümeyi üstlendiği, aynı konuyu bölen sayfalar ve eksik sayfalar burada görünür.</p>
    </div>

    @if ($services === [])
        <div class="{{ $card }} text-sm text-gray-500">Henüz markalara bağlı ya da kümelenmiş bir hizmet yok. Markanın hizmetlerini kütüphanedeki hizmetlere bağlayın ve sorguları atayın.</div>
    @else
        <label class="block text-sm">
            <span class="block text-xs text-gray-500">Hizmet</span>
            <select wire:model.live="service" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                @foreach ($services as $option)
                    <option value="{{ $option['id'] }}">{{ $option['name'] }} — {{ $option['brands'] }} marka · {{ $option['clusters'] }} küme</option>
                @endforeach
            </select>
        </label>

        @if ($map !== null)
            <div class="flex flex-wrap items-center gap-3">
                <livewire:operator.brain.prepare-button kind="service_clusters" :options="['service_id' => $service]" :key="'brain-clusters-'.$service" />
                <livewire:operator.brain.prepare-button kind="cluster_targets" :options="['service_id' => $service]" :key="'brain-targets-'.$service" />
                @if ($map['unclustered'] > 0)
                    <span class="text-xs text-warning-700">{{ $map['unclustered'] }} sorgu henüz bir kümede değil.</span>
                @endif
            </div>

            <section class="{{ $card }} overflow-x-auto">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Kümeler ve sayfalar</h2>
                @if ($map['clusters'] === [])
                    <p class="mt-2 text-sm text-gray-500">Bu hizmette küme yok. "AI ile hazırla: Hizmet → sayfa kümeleri" ile öneri hazırlatın; onay kuyruğundan onaylayın.</p>
                @else
                    <table class="mt-3 w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-gray-500">
                                <th class="py-1 pr-3">Küme (sayfa konusu)</th>
                                <th class="py-1 pr-3">Tür</th>
                                <th class="py-1 pr-3">Niyet</th>
                                <th class="py-1 pr-3">Sorgu</th>
                                @foreach ($map['sites'] as $site)
                                    <th class="py-1 pr-3">{{ $site['brand'] ?: $site['name'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($map['clusters'] as $cluster)
                                <tr wire:key="cluster-{{ $cluster['id'] }}" class="align-top">
                                    <td class="py-2 pr-3">
                                        <div class="font-medium text-gray-800 dark:text-gray-200">{{ $cluster['name'] }}</div>
                                        @if ($cluster['head'])<div class="text-xs text-gray-500">{{ $cluster['head'] }}</div>@endif
                                    </td>
                                    <td class="py-2 pr-3"><span class="rounded-full px-2 py-0.5 text-xs {{ $typeColor[$cluster['page_type']] ?? 'bg-gray-100 text-gray-600' }}">{{ PageTypes::label($cluster['page_type']) }}</span></td>
                                    <td class="py-2 pr-3 text-xs text-gray-600">{{ PageTypes::intent($cluster['intent']) }}</td>
                                    <td class="py-2 pr-3 text-xs text-gray-600">{{ $cluster['queries'] }}</td>
                                    @foreach ($map['sites'] as $site)
                                        @php
                                            $target = $map['targets'][$cluster['id'].'|'.$site['id']] ?? null;
                                            $split = collect($map['cannibalizations'])->first(fn ($c) => $c['cluster_id'] === $cluster['id'] && $c['site_id'] === $site['id']);
                                        @endphp
                                        <td class="py-2 pr-3 text-xs">
                                            @if ($cluster['page_type'] === 'faq')
                                                <span class="text-gray-400">ana sayfada SSS</span>
                                            @elseif ($target)
                                                <a href="{{ $target['url'] }}" target="_blank" rel="noopener" class="break-all text-brand-600 hover:underline">{{ \Illuminate\Support\Str::after($target['url'], '://') }}</a>
                                            @else
                                                <span class="text-warning-700">sayfa yok</span>
                                            @endif
                                            @if ($split)
                                                <div class="mt-1 text-error-600">{{ count($split['pages']) }} sayfa bölüşüyor</div>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>

            @if ($map['cannibalizations'] !== [])
                <section class="{{ $card }}">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Aynı konuyu bölen sayfalar ({{ count($map['cannibalizations']) }})</h2>
                    <p class="mt-1 text-xs text-gray-500">Bir kümenin gösterimleri iki sayfa arasında bölünüyor ve ikisi de ilk iki sonuç sayfasında. Genelde tek sayfada birleştirmek, diğerini ona yönlendirmek ya da içerikleri ayırmak gerekir.</p>
                    <ul class="mt-3 space-y-3 text-sm">
                        @foreach ($map['cannibalizations'] as $row)
                            @php $site = collect($map['sites'])->firstWhere('id', $row['site_id']); @endphp
                            <li wire:key="cannibal-{{ $row['id'] }}" class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ $site['brand'] ?? '' }} · {{ $row['subject'] }} <span class="text-xs text-gray-500">({{ number_format($row['impressions'], 0, ',', '.') }} gösterim)</span></span>
                                    <button type="button" wire:click="dismissCannibalization({{ $row['id'] }})" class="text-xs text-gray-500 hover:underline">Bilinçli, gösterme</button>
                                </div>
                                <ul class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                                    @foreach ($row['pages'] as $page)
                                        <li>%{{ (int) round(($page['share'] ?? 0) * 100) }} · sıra {{ $page['position'] ?? '—' }} · <span class="break-all">{{ $page['url'] }}</span></li>
                                    @endforeach
                                </ul>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @endif
    @endif
</div>
