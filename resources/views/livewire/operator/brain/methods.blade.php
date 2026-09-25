@php
    use App\Services\Brain\Clustering\PageTypes;
    use App\Services\Brain\BrainLabels;
    $card = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $statusLabels = ['validated' => ['Etkisi kanıtlandı', 'bg-success-50 text-success-700'], 'hypothesis' => ['Gözlendi, ölçülüyor', 'bg-blue-50 text-blue-700'], 'retired' => ['Kapatıldı', 'bg-gray-100 text-gray-600']];
@endphp
<div class="space-y-5">
    @include('livewire.demo.partials.flash')
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Yöntemler</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Beyin, aynı hizmet ve aynı sayfa türündeki sayfaları başarı puanına göre karşılaştırır; başarılı sayfalarda belirgin şekilde daha sık görülen özellikler "gözlendi" olarak yöntem olur (yeterli marka ve sayfa şartıyla). Bu yöntemle yapılan öneriler uygulanınca 28 / 56 gün sonra benzer, uygulanmamış sayfalarla karşılaştırılır: etki tutarlı ise "kanıtlandı", değilse kapatılır. Başka markaların adı gösterilmez, yalnız sayılar.</p>
    </div>
    <label class="block text-sm"><span class="block text-xs text-gray-500">Durum</span>
        <select wire:model.live="status" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
            <option value="">Tümü</option>
            @foreach ($statusLabels as $key => [$label])<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
    </label>
    <section class="{{ $card }}">
        @if ($methods->isEmpty())
            <p class="p-5 text-sm text-gray-500">Henüz yöntem yok. Yöntem çıkması için bir hizmette yeterli marka ve sayfanın başarı puanı ve sayfa özellikleri hesaplanmış olmalı (Ayarlar → Yöntem Kütüphanesi → Hizmet Beyni eşikleri).</p>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($methods as $method)
                    @php
                        $evidence = (array) json_decode((string) $method->evidence, true);
                        $outcome = $method->outcome ? (array) json_decode((string) $method->outcome, true) : null;
                        [$statusLabel, $tone] = $statusLabels[$method->status] ?? [$method->status, 'bg-gray-100 text-gray-600'];
                        $use = $applied->get($method->id);
                    @endphp
                    <li wire:key="method-{{ $method->id }}" class="flex flex-wrap items-start justify-between gap-3 p-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                <span class="rounded-full px-2 py-0.5 {{ $tone }}">{{ $statusLabel }}</span>
                                <span>{{ $method->service_name ?? 'Tüm hizmetler' }}</span>
                                @if ($method->page_type)<span>· {{ PageTypes::label($method->page_type) }}</span>@endif
                                <span>· {{ BrainLabels::channel($method->channel) }}</span>
                            </div>
                            <div class="mt-1 font-medium text-gray-800 dark:text-gray-200">{{ $method->label }}</div>
                            <div class="mt-1 text-xs text-gray-500">
                                @if (isset($evidence['top_rate']))
                                    Başarılı sayfaların %{{ (int) round($evidence['top_rate'] * 100) }}'inde, zayıfların %{{ (int) round($evidence['bottom_rate'] * 100) }}'inde · {{ $evidence['brands'] ?? '?' }} marka, {{ $evidence['pages'] ?? '?' }} sayfa
                                @elseif (isset($evidence['median_cpr']))
                                    {{ $evidence['brands'] }} markada sonuç başı medyan {{ number_format($evidence['median_cpr'], 2, ',', '.') }} (hizmet medyanı {{ number_format($evidence['service_median_cpr'], 2, ',', '.') }})
                                @endif
                                @if ($use) · {{ (int) $use->done }} uygulandı, {{ (int) $use->open }} açık öneri @endif
                                @if ($outcome && $outcome['treated'] > 0) · ölçülen {{ $outcome['treated'] }} uygulamada ortalama etki {{ $outcome['mean_effect'] !== null ? sprintf('%+.0f', $outcome['mean_effect'] * 100) : '—' }} puan @endif
                            </div>
                        </div>
                        @if ($method->status === 'retired')
                            <button type="button" wire:click="restore({{ $method->id }})" class="text-xs text-brand-600 hover:underline">Yeniden aç</button>
                        @else
                            <button type="button" wire:click="retire({{ $method->id }})" wire:confirm="Bu yöntem kapatılsın mı? Açık önerileri de kapanır." class="text-xs text-gray-500 hover:underline">Kapat</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
