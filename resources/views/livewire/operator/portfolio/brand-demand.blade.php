<section class="space-y-4 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
            <h2 class="font-semibold text-gray-800 dark:text-white/90">Talep (son 90 gün)</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Search Console, Google Ads arama terimleri ve İşletme Profili aramaları; hizmete ve bölgeye otomatik atanır, her pazartesi yenilenir.
                @if ($builtAt) Son güncelleme: {{ \Illuminate\Support\Carbon::parse($builtAt)->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}. @endif
            </p>
        </div>
        <button type="button" wire:click="rebuild" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Şimdi yenile</button>
    </div>

    @if ($message !== '')
        <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>
    @endif

    @if ($total === 0)
        <p class="text-sm text-gray-500">Henüz sorgu yok. Search Console / Google Ads / İşletme Profili bağlandıktan ve veri geldikten sonra dolar.</p>
    @else
        <p class="text-xs text-gray-500">{{ $total }} sorgu · {{ $branded }} markalı · {{ $outOfArea }} hizmet bölgesi dışında</p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-400">
                    <tr><th class="py-2 pr-3">Hizmet</th><th class="py-2 pr-3">Sorgu</th><th class="py-2 pr-3">Tıklama</th><th class="py-2 pr-3">Gösterim</th><th class="py-2 pr-3">Ads dönüşüm</th><th class="py-2">En değerli sorgular</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($services as $service)
                        <tr>
                            <td class="py-2 pr-3 font-medium text-gray-800 dark:text-gray-200">{{ $service['name'] }}</td>
                            <td class="py-2 pr-3">{{ $service['queries'] }}</td>
                            <td class="py-2 pr-3">{{ number_format($service['clicks'], 0, ',', '.') }}</td>
                            <td class="py-2 pr-3">{{ number_format($service['impressions'], 0, ',', '.') }}</td>
                            <td class="py-2 pr-3">{{ $service['conversions'] }}</td>
                            <td class="py-2 text-xs text-gray-500">{{ implode(' · ', $service['top']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($unassigned->isNotEmpty())
            <div class="space-y-2">
                <p class="text-xs font-medium uppercase text-gray-400">Hizmete atanamayan değerli sorgular</p>
                <ul class="space-y-1 text-sm">
                    @foreach ($unassigned as $row)
                        <li wire:key="demand-{{ $row->id }}" class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-gray-800 dark:text-gray-200">{{ $row->query }} <span class="text-xs text-gray-500">{{ (int) $row->gsc_clicks }} tık · {{ (int) $row->gsc_impressions }} gösterim</span></span>
                            <select wire:change="assign({{ $row->id }}, $event.target.value)" class="rounded-lg border border-gray-200 bg-transparent px-2 py-1 text-xs dark:border-gray-700 dark:text-white">
                                <option value="">Hizmete ata…</option>
                                @foreach ($offerings as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </li>
                    @endforeach
                </ul>
                <p class="text-xs text-gray-500">Kalıcı çözüm: hizmetin eşleştirme ifadelerine bu kelimeyi eklemek (Hizmetler kütüphanesi).</p>
            </div>
        @endif
    @endif
</section>
