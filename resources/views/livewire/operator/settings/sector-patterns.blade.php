@php
    $card = 'rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $channelLabels = ['google_ads' => 'Google Ads', 'meta_ads' => 'Meta Ads', 'google_business_profile' => 'İşletme Profili', 'cross_channel' => 'Kanallar arası'];
@endphp
<div class="space-y-5">
    <div>
        <a href="{{ route('operator.settings', ['section' => 'operations']) }}" wire:navigate class="text-sm text-gray-500 hover:text-brand-600">← Ayarlar</a>
        <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">Sektör örüntüleri</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Aynı sektördeki aktif markalarda tekrar eden aramalar, sorunlar ve işe yarayan öneriler. Yalnız toplamlar gösterilir: bir sektör ve bir örüntü en az {{ $minBrands }} farklı marka ister, başka markanın adı hiçbir örüntünün yanında görünmez. Ajans içi bilgidir; müşteri raporlarına girmez (ADR-066).</p>
    </div>

    @if ($sectors === [])
        <div class="{{ $card }} text-sm text-gray-500">Henüz en az {{ $minBrands }} aktif markası olan bir sektör yok. Marka ayarlarında sektör seçildikçe örüntüler burada görünür.</div>
    @else
        <div class="flex flex-wrap items-end gap-3">
            <label class="text-sm">
                <span class="block text-xs text-gray-500">Sektör</span>
                <select wire:model.live="sector" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                    @foreach ($sectors as $option)
                        <option value="{{ $option['code'] }}">{{ $option['label'] }} ({{ $option['brands'] }} marka)</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">
                <span class="block text-xs text-gray-500">Marka için eksikler</span>
                <select wire:model.live="brand" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                    <option value="">Marka seç…</option>
                    @foreach ($brands as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        @if ($patterns !== null && $patterns['available'])
            @if ($brand !== null)
                <section class="{{ $card }}">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Sektörde sık, bu markada yok ({{ count($patterns['gaps']) }})</h2>
                    <p class="mt-1 text-xs text-gray-500">Diğer en az {{ $minBrands }} markanın talep tablosunda olan, bu markanın talep tablosunda olmayan aramalar. Hizmet ifadesi, sayfa ya da reklam fikri için bakılır.</p>
                    @if ($patterns['gaps'] === [])
                        <p class="mt-2 text-sm text-gray-500">Eksik bir sektör araması yok.</p>
                    @else
                        <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-800">
                            @foreach ($patterns['gaps'] as $row)
                                <li class="flex justify-between gap-2 py-1.5"><span class="text-gray-800 dark:text-gray-200">{{ $row['query'] }}</span><span class="text-xs text-gray-500">{{ $row['brands'] }} marka · {{ number_format($row['impressions'], 0, ',', '.') }} gösterim</span></li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            <section class="{{ $card }}">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Sektörde tekrar eden aramalar</h2>
                @if ($patterns['queries'] === [])
                    <p class="mt-2 text-sm text-gray-500">En az {{ $minBrands }} markada ortak arama yok (talep tabloları haftalık kurulur).</p>
                @else
                    <table class="mt-2 w-full text-sm">
                        <thead><tr class="text-left text-xs text-gray-500"><th class="py-1">Arama</th><th>Marka</th><th>Gösterim</th><th>Tık</th><th>Reklam dönüşümü</th></tr></thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($patterns['queries'] as $row)
                                <tr><td class="py-1.5 text-gray-800 dark:text-gray-200">{{ $row['query'] }}</td><td>{{ $row['brands'] }}</td><td>{{ number_format($row['impressions'], 0, ',', '.') }}</td><td>{{ number_format($row['clicks'], 0, ',', '.') }}</td><td>{{ $row['ads_conversions'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>

            <section class="{{ $card }}">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Sektörde sık çıkan sorunlar</h2>
                @if ($patterns['problems'] === [])
                    <p class="mt-2 text-sm text-gray-500">En az {{ $minBrands }} markada tekrar eden danışman bulgusu yok.</p>
                @else
                    <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-800">
                        @foreach ($patterns['problems'] as $row)
                            <li class="flex justify-between gap-2 py-1.5"><span><span class="text-xs text-gray-500">{{ $channelLabels[$row['channel']] ?? $row['channel'] }}</span> <span class="font-mono text-xs text-gray-800 dark:text-gray-200">{{ $row['rule_id'] }}</span></span><span class="text-xs text-gray-500">{{ $row['brands'] }} marka · {{ $row['open'] }} açık</span></li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="{{ $card }}">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Bu sektörde işe yarayan öneriler</h2>
                <p class="mt-1 text-xs text-gray-500">"Yapıldı" işaretlenen önerilerin ölçülen sonucu. Bir kuralın sektörde yeterli ölçümü olunca bu sektördeki markalar için önceliği sektör sonucuna göre ayarlanır.</p>
                @if ($patterns['rules'] === [])
                    <p class="mt-2 text-sm text-gray-500">Bu sektörde henüz ölçülmüş sonuç yok.</p>
                @else
                    <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-800">
                        @foreach ($patterns['rules'] as $row)
                            <li class="flex justify-between gap-2 py-1.5"><span class="font-mono text-xs text-gray-800 dark:text-gray-200">{{ $row['rule_id'] }}</span><span class="text-xs text-gray-500">{{ $row['measured'] }} ölçüldü · sektörde %{{ $row['rate'] }} iyileşti{{ $row['global_rate'] !== null ? ' · tümünde %'.$row['global_rate'] : '' }}</span></li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif
    @endif
</div>
