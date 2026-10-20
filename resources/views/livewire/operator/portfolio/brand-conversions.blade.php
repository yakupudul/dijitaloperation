<section class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
            <h2 class="section-title">Dönüşümler (son 30 gün)</h2>
            <p class="sr-only">
                Hesapların bildirdiği her dönüşüm sinyali burada; hangisinin ne anlama geldiğini ve toplama girip girmediğini sen belirlersin.
                Varsayılan: web sitesini GA4 sayar; Google Ads web/GA4 aktarımları ve Meta piksel olayları çift sayılmasın diye toplama girmez.
            </p>
        </div>
        <button type="button" wire:click="refresh" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Sinyalleri yenile</button>
    </div>

    @if ($tags !== null)
        <p class="text-xs text-gray-500">
            Ana sayfadaki ölçüm etiketleri:
            Tag Manager {{ $tags['gtm'] !== [] ? implode(', ', $tags['gtm']) : 'yok' }};
            GA4 {{ $tags['ga4'] !== [] ? implode(', ', $tags['ga4']) : ($tags['gtm'] !== [] ? 'Tag Manager içinde olabilir' : 'yok') }};
            Google Ads {{ $tags['google_ads'] !== [] ? implode(', ', $tags['google_ads']) : '—' }};
            Meta piksel {{ $tags['meta_pixel'] !== [] ? 'var' : '—' }}
            <span>({{ \Illuminate\Support\Carbon::parse($tags['observed_at'])->timezone('Europe/Istanbul')->format('d.m.Y') }} tarihli sayfa)</span>
        </p>
    @endif

    @if ($message !== '')
        <p class="tone-ok rounded-lg px-3 py-2 text-sm" role="status">{{ $message }}</p>
    @endif

    @if ($rows->isEmpty())
        <p class="text-sm text-gray-500">Henüz dönüşüm sinyali yok. GA4, Google Ads, Meta veya İşletme Profili bağlanıp veri geldikten sonra dolar.</p>
    @else
        <div class="grid grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-3 lg:grid-cols-4">
            <x-ta.metric-card label="Toplam dönüşüm" :value="number_format($summary['current']['total'], 0, ',', '.')"
                :delta="$summary['change_pct'] !== null ? ($summary['change_pct'] >= 0 ? '+' : '−').abs($summary['change_pct']).'%' : null"
                :tone="$summary['change_pct'] === null || $summary['change_pct'] == 0 ? 'neutral' : ($summary['change_pct'] > 0 ? 'positive' : 'negative')"
                :hint="'Önceki 30 gün: '.number_format($summary['previous']['total'], 0, ',', '.')" />
            @foreach ($summary['current']['by_type'] as $type => $value)
                <x-ta.metric-card :label="$types[$type] ?? $type" :value="number_format($value, 0, ',', '.')" />
            @endforeach
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-gray-800">
                    <tr><th class="py-2 pr-3 font-normal">Kaynak</th><th class="py-2 pr-3 font-normal">Sinyal</th><th class="py-2 pr-3 font-normal">Ne anlama geliyor</th><th class="py-2 pr-3 text-right font-normal">30 gün</th><th class="py-2 font-normal">Toplama girer</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                    @foreach ($rows as $row)
                        <tr wire:key="conversion-{{ $row->id }}" @class(['opacity-60' => ! in_array($row->id, $counted, true)])>
                            <td class="py-2 pr-3 text-xs text-gray-500">{{ $sources[$row->source] ?? $row->source }}</td>
                            <td class="py-2 pr-3 text-gray-800 dark:text-gray-200">
                                {{ $row->label }}
                                @if ($row->origin === 'operator' || isset($row->metadata['type_by_operator'])) <span class="text-xs text-gray-500">elle ayarlandı</span> @endif
                                @php $isEngagement = in_array($row->id, $engagement, true); @endphp
                                @if ($row->counts && $isEngagement && in_array($row->id, $counted, true))
                                    <span class="block text-xs font-medium text-warning-600" data-engagement-counted>Etkileşim sinyali müşteri sayılıyor: kaydırma, sayfa ya da profil ziyareti, form başlatma müşteri değildir. Bilerek açık tuttuysan kalsın; değilse kapat.</span>
                                @elseif ($row->counts && $isEngagement)
                                    <span class="block text-xs text-gray-500">İlgi ölçüsü; açık görünse de toplama girmiyor. Gerçekten müşteri sayılsın istiyorsan kapatıp yeniden aç.</span>
                                @elseif (! $row->counts && $isEngagement)
                                    <span class="block text-xs text-gray-500">İlgi ölçüsü (kaydırma, sayfa ya da profil ziyareti, form başlatma); müşteri sayılmaz.</span>
                                @elseif ($row->counts && ($summary['current']['by_row'][$row->id] ?? 0) == 0)
                                    <span class="block text-xs text-warning-600">Son 30 günde hiç tetiklenmedi; kurulumu kontrol et.</span>
                                @endif
                            </td>
                            <td class="py-2 pr-3">
                                <select wire:change="setType({{ $row->id }}, $event.target.value)" class="rounded-lg border border-gray-200 bg-transparent px-2 py-1 text-xs dark:border-gray-700 dark:text-white">
                                    @foreach ($types as $value => $label)
                                        <option value="{{ $value }}" @selected($row->conversion_type === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ number_format($summary['current']['by_row'][$row->id] ?? 0, 0, ',', '.') }}</td>
                            <td class="py-2">
                                <button type="button" wire:click="toggleCounts({{ $row->id }})" role="switch" aria-checked="{{ $row->counts ? 'true' : 'false' }}"
                                    class="relative inline-flex h-5 w-9 items-center rounded-full {{ $row->counts ? 'bg-brand-500' : 'bg-gray-300 dark:bg-gray-700' }}">
                                    <span class="sr-only">Toplama girer</span>
                                    <span class="inline-block h-4 w-4 transform rounded-full bg-white transition {{ $row->counts ? 'translate-x-4' : 'translate-x-0.5' }}"></span>
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
