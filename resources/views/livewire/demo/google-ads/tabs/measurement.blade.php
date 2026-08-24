@php
    $isTr = app()->getLocale() === 'tr';
    $m = $data['measurement'] ?? [];
    $rows = collect($m['matrix'] ?? []);
    $primary = $rows->where('role', 'Primary')->count();
    $secondary = $rows->where('role', 'Secondary')->count();
    $excluded = $rows->where('role', 'Excluded')->count();
    $observed = $rows->filter(fn ($row) => ($row['state'] ?? null) === 'Observed')->count();
    $formatNumber = fn ($v) => is_numeric($v) ? number_format((float)$v, 2, ',', '.') : '—';
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Dönüşümler & ölçüm' : 'Conversions & measurement' }}</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Google Ads hesabındaki conversion action’ları, optimizasyon rollerini ve seçili dönemde ürettikleri sağlayıcı dönüşümlerini denetleyin.' : 'Audit Google Ads conversion actions, optimization roles and provider conversions observed in the selected period.' }}</p>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-ta.metric-card :label="$isTr ? 'Conversion action' : 'Conversion actions'" :value="$rows->count() ? (string)$rows->count() : '—'" />
        <x-ta.metric-card :label="$isTr ? 'Primary' : 'Primary'" :value="(string)$primary" />
        <x-ta.metric-card :label="$isTr ? 'Secondary / hariç' : 'Secondary / excluded'" :value="(string)($secondary + $excluded)" />
        <x-ta.metric-card :label="$isTr ? 'Dönemde sinyal üreten' : 'Observed in period'" :value="(string)$observed" :tone="$observed > 0 ? 'positive' : 'neutral'" />
    </div>

    <div class="rounded-xl bg-blue-50 px-4 py-3 text-sm text-blue-800 ring-1 ring-inset ring-blue-100 dark:bg-blue-500/10 dark:text-blue-200 dark:ring-blue-500/20">
        <strong>{{ $isTr ? 'Önemli ayrım:' : 'Important distinction:' }}</strong>
        {{ $isTr ? 'Google Ads conversion sayısı otomatik olarak nitelikli lead, satış veya doğrulanmış gelir değildir. MOXDOP Business Action eşlemesi oluşturulana kadar bu değerleri sağlayıcı dönüşümü olarak tutar.' : 'A Google Ads conversion is not automatically a qualified lead, sale or verified revenue. Until a MOXDOP Business Action mapping exists, these remain provider conversions.' }}
    </div>

    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Conversion action matrisi' : 'Conversion action matrix' }}</h3>
            <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Primary/Secondary Google Ads rolüdür. “Dönüşümler” ve “Tüm dönüşümler” ayrı provider metrikleridir.' : 'Primary/Secondary is the Google Ads optimization role. Conversions and All conversions remain separate provider metrics.' }}</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-400 dark:bg-white/[0.02]"><tr>
                    <th class="px-4 py-2.5 text-left">{{ $isTr ? 'Aksiyon' : 'Action' }}</th>
                    <th class="px-3 py-2.5 text-left">{{ $isTr ? 'Rol' : 'Role' }}</th>
                    <th class="px-3 py-2.5 text-left">{{ $isTr ? 'Kategori' : 'Category' }}</th>
                    <th class="px-3 py-2.5 text-left">{{ $isTr ? 'Durum' : 'Status' }}</th>
                    <th class="px-3 py-2.5 text-right">{{ $isTr ? 'Dönüşümler' : 'Conversions' }}</th>
                    <th class="px-3 py-2.5 text-right">{{ $isTr ? 'Tüm dönüşümler' : 'All conv.' }}</th>
                    <th class="px-4 py-2.5 text-right">{{ $isTr ? 'Dönüşüm değeri' : 'Conv. value' }}</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($rows as $row)
                        <tr>
                            <td class="px-4 py-2.5"><p class="font-medium text-gray-900 dark:text-white">{{ $row['action'] ?? '—' }}</p><p class="mt-0.5 text-[11px] text-gray-400">{{ $row['type'] ?? '' }}</p></td>
                            <td class="px-3 py-2.5"><x-ta.badge :color="($row['role'] ?? '') === 'Primary' ? 'success' : (($row['role'] ?? '') === 'Excluded' ? 'light' : 'info')" size="sm">{{ $row['role'] ?? '—' }}</x-ta.badge></td>
                            <td class="px-3 py-2.5 text-xs text-gray-600 dark:text-gray-300">{{ $row['category'] ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-xs text-gray-600 dark:text-gray-300">{{ $row['status'] ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ $formatNumber($row['conversions'] ?? null) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ $formatNumber($row['all_conversions'] ?? null) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ $formatNumber($row['conversions_value'] ?? null) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">{{ $isTr ? 'Conversion action dataset’i henüz kullanılabilir değil. Veri & Bağlantı sekmesinden toplama durumunu kontrol edin.' : 'Conversion action data is not yet usable. Check collection state under Data & Connection.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Business Action eşlemesi' : 'Business Action mapping' }}</h3>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $isTr ? 'Henüz canonical Business Action eşlemesi yok. Bu nedenle MOXDOP “lead”, “qualified lead”, “sale” veya “revenue” anlamını kendi başına uydurmaz.' : 'No canonical Business Action mapping exists yet. MOXDOP therefore does not invent lead, qualified-lead, sale or revenue semantics.' }}</p>
            <p class="mt-3 text-xs text-gray-500">{{ $isTr ? 'İleride aynı aksiyon CRM/Outcome verisiyle eşlendiğinde Google Ads dönüşümü → iş sonucu zinciri kurulabilir.' : 'Once mapped to CRM/Outcome data, the system can connect Google Ads conversion → business outcome.' }}</p>
        </section>
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">GA4 {{ $isTr ? 'tutarlılığı' : 'consistency' }}</h3>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $isTr ? 'Bu ekranda henüz Google Ads conversion action ↔ GA4 event otomatik eşlemesi yapılmıyor. Bu ilişki kurulmadan “tracking sağlıklı” gibi bir sonuç üretilmez.' : 'Google Ads conversion action ↔ GA4 event mapping is not automated here yet. The UI will not claim tracking is healthy until that relationship exists.' }}</p>
            <p class="mt-3 text-xs text-gray-500">{{ $m['mapping_trust_note'] ?? '' }}</p>
        </section>
    </div>
</div>
