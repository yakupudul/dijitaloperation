<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-600">Kütüphane</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Sorgular</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Manuel araştırma, Google Ads, Search Console ve DataForSEO sorgularını kaynak bilgisiyle birlikte tek bir tekrar kullanılabilir havuzda tutar.</p>
        </div>
        <a href="{{ route('operator.library.services') }}" wire:navigate class="rounded-lg px-4 py-2.5 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:ring-brand-500/30">← Hizmet kütüphanesi</a>
    </div>

    @if ($message !== '')
        <div class="rounded-lg border px-4 py-3 text-sm {{ $message_tone === 'error' ? 'border-red-200 bg-red-50 text-red-800' : ($message_tone === 'warning' ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800') }}">{{ $message }}</div>
    @endif

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([['Tüm sorgular', $summary['total']], ['Etkin', $summary['active']], ['Hizmet atanmamış', $summary['unassigned']], ['Markalı / hariç tutulabilir', $summary['branded']]] as [$label, $value])
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><p class="text-xs text-gray-500">{{ $label }}</p><p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($value, 0, ',', '.') }}</p></div>
        @endforeach
    </div>

    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-base font-semibold text-gray-900 dark:text-white">Sorgu ekle</h2><p class="mt-1 text-sm text-gray-500">Tek sorgu ekleyin veya her satıra bir sorgu gelecek şekilde toplu yapıştırın.</p></div></div>
        <div class="mt-4 grid gap-5 xl:grid-cols-2">
            <form wire:submit="addQuery" class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Tek sorgu</h3>
                <textarea wire:model="query_text" rows="3" placeholder="Örn. bornova implant doktoru" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"></textarea>
                @error('query_text') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                <div class="grid gap-3 sm:grid-cols-2">
                    <select wire:model="query_service_id" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Hizmet seçin</option>@foreach ($serviceOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>
                    <select wire:model="query_sector" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Sektör (isteğe bağlı)</option>@foreach ($sectorOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                    <input wire:model="query_language" type="text" placeholder="Dil: tr" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                    <input wire:model="query_market" type="text" placeholder="Pazar: TR" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                    <input wire:model="query_demand_family" type="text" placeholder="Talep ailesi (isteğe bağlı)" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                    <select wire:model="query_location_scope" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="none">Lokasyon yok</option><option value="country">Ülke</option><option value="city">Şehir</option><option value="district">İlçe</option><option value="pattern">{location} kalıbı</option></select>
                    <input wire:model="query_location_value" type="text" placeholder="Lokasyon / {location}" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                    <label class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300"><input wire:model="query_is_branded" type="checkbox" class="rounded border-gray-300 text-brand-500" /> Marka/lisanslı ürün sorgusu</label>
                </div>
                <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">Kütüphaneye ekle</button>
            </form>

            <form wire:submit="addPastedQueries" class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Toplu metin</h3>
                <p class="text-xs text-gray-500">Soldaki hizmet, dil, pazar ve diğer sınıflandırmalar yapıştırılan tüm satırlara uygulanır.</p>
                <textarea wire:model="paste_text" rows="10" placeholder="Her satıra bir sorgu" class="w-full rounded-lg border-gray-300 font-mono text-sm dark:border-gray-700 dark:bg-gray-950"></textarea>
                @error('paste_text') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                <button type="submit" wire:loading.attr="disabled" class="rounded-lg px-4 py-2.5 text-sm font-semibold text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 disabled:opacity-50">Satırları işle</button>
            </form>
        </div>
    </section>

    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">CSV / Excel içe aktar</h2>
        <p class="mt-1 text-sm text-gray-500">CSV, TSV, TXT ve XLSX desteklenir. Sorgu sütunu için <code>query</code>, <code>search_term</code>, <code>keyword</code>, <code>sorgu</code> veya <code>arama_terimi</code> kullanılabilir.</p>
        <form wire:submit="importQueries" class="mt-4 grid gap-3 lg:grid-cols-[1.5fr_1fr_1fr_0.6fr_0.6fr_auto]">
            <input wire:model="import_file" type="file" accept=".csv,.tsv,.txt,.xlsx" class="rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700" />
            <select wire:model="import_source_type" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="csv">CSV / genel liste</option><option value="xlsx">Excel / genel liste</option><option value="google_ads">Google Ads</option><option value="search_console">Search Console</option><option value="dataforseo">DataForSEO</option></select>
            <select wire:model="import_service_id" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Dosyadaki hizmet / atanmamış</option>@foreach ($serviceOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>
            <input wire:model="import_language" type="text" placeholder="tr" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
            <input wire:model="import_market" type="text" placeholder="TR" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
            <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"><span wire:loading.remove>İçe aktar</span><span wire:loading>İşleniyor…</span></button>
        </form>
        @error('import_file') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror

        @if ($imports->isNotEmpty())
            <details class="mt-4 text-sm"><summary class="cursor-pointer font-medium text-gray-600 dark:text-gray-300">Son içe aktarmalar</summary><div class="mt-3 overflow-x-auto"><table class="min-w-full text-xs"><thead class="text-left text-gray-400"><tr><th class="py-2">Dosya</th><th>Kaynak</th><th>Durum</th><th>Kabul</th><th>Hata</th><th>Zaman</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">@foreach ($imports as $import)<tr><td class="py-2 pr-4">{{ $import->original_filename ?: '—' }}</td><td class="pr-4">{{ $sourceOptions[$import->source_type] ?? $import->source_type }}</td><td class="pr-4">{{ $import->status }}</td><td class="pr-4">{{ $import->accepted_rows }}</td><td class="pr-4">{{ $import->failed_rows }}</td><td>{{ $import->completed_at?->diffForHumans() ?: 'İşleniyor' }}</td></tr>@endforeach</tbody></table></div></details>
        @endif
    </section>

    <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="grid gap-3 border-b border-gray-100 p-4 md:grid-cols-4 dark:border-gray-800">
            <input wire:model.live.debounce.350ms="search" type="search" placeholder="Sorgu veya talep ailesi ara" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
            <select wire:model.live="service" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Tüm hizmetler</option>@foreach ($serviceOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>
            <select wire:model.live="source" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Tüm kaynaklar</option>@foreach ($sourceOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
            <select wire:model.live="status" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="active">Etkin</option><option value="candidate">Aday</option><option value="excluded">Hariç</option><option value="archived">Arşiv</option><option value="all">Tümü</option></select>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead class="bg-gray-50 text-left text-gray-500 dark:bg-white/[0.02]"><tr><th class="px-4 py-3">Sorgu</th><th class="px-4 py-3">Hizmet / aile</th><th class="px-4 py-3">Pazar</th><th class="px-4 py-3 text-right">Gösterim</th><th class="px-4 py-3 text-right">Tıklama</th><th class="px-4 py-3 text-right">Dönüşüm</th><th class="px-4 py-3 text-right">Hacim</th><th class="px-4 py-3">Kaynak</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($queries as $query)
                        <tr class="align-top hover:bg-gray-50/60 dark:hover:bg-white/[0.02]">
                            <td class="max-w-sm px-4 py-3"><div class="font-medium text-gray-900 dark:text-white">{{ $query->canonical_text }}</div><div class="mt-1 flex flex-wrap gap-1">@if($query->is_branded)<span class="rounded bg-red-50 px-1.5 py-0.5 text-[10px] text-red-700">markalı</span>@endif<span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500 dark:bg-gray-800">{{ $query->status }}</span></div></td>
                            <td class="px-4 py-3"><div class="text-gray-700 dark:text-gray-300">{{ $query->services->map(fn($service) => $service->primaryName?->raw_label)->filter()->implode(' · ') ?: 'Atanmamış' }}</div><div class="mt-1 text-gray-400">{{ $query->demand_family ?: 'Talep ailesi bekliyor' }}</div></td>
                            <td class="whitespace-nowrap px-4 py-3 text-gray-600 dark:text-gray-300">{{ $query->market_code ?: '—' }} · {{ $query->language_code ?: '—' }}@if($query->location_value)<div class="text-gray-400">{{ $query->location_value }}</div>@endif</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $query->source_records_sum_impressions !== null ? number_format((float)$query->source_records_sum_impressions, 0, ',', '.') : '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $query->source_records_sum_clicks !== null ? number_format((float)$query->source_records_sum_clicks, 0, ',', '.') : '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $query->source_records_sum_conversions !== null ? number_format((float)$query->source_records_sum_conversions, 1, ',', '.') : '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $query->source_records_sum_search_volume !== null ? number_format((float)$query->source_records_sum_search_volume, 0, ',', '.') : '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3"><div>{{ $query->source_records_count }} kayıt</div><div class="mt-1 text-gray-400">{{ $query->source_records_max_observed_at ? \Carbon\Carbon::parse($query->source_records_max_observed_at)->diffForHumans() : '—' }}</div></td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">@if($query->status === 'active')<button wire:click="setQueryStatus({{ $query->id }}, 'excluded')" class="text-amber-600">Hariç tut</button>@else<button wire:click="setQueryStatus({{ $query->id }}, 'active')" class="text-brand-600">Etkinleştir</button>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-10 text-center text-sm text-gray-500">Bu filtrelerle eşleşen sorgu yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 text-xs text-gray-400 dark:border-gray-800">En fazla son 300 eşleşme gösterilir. Kaynak kayıtları korunur; metrik olmayan sorgular sıfır değil “—” görünür.</div>
    </section>
</div>
