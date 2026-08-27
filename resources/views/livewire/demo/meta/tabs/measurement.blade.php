@php
    $isTr = app()->getLocale() === 'tr';
    $actions = $professional['typed_actions'] ?? [];
    $sources = $professional['conversion_sources'] ?? [];
    $pixelCount = collect($sources)->where('source_type', 'PIXEL')->count();
    $customConversionCount = collect($sources)->where('source_type', 'CUSTOM_CONVERSION')->count();
    $availableSources = collect($sources)->filter(fn ($row) => ! ($row['is_unavailable'] ?? false) && ! ($row['is_archived'] ?? false))->count();
@endphp

<section class="space-y-5">
    <div>
        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">{{ $isTr ? 'Dönüşümler' : 'Conversions' }}</p>
        <h2 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Meta’nın ölçtüğü action’ları ve dönüşüm kaynaklarını doğrula' : 'Validate Meta-observed actions and conversion sources' }}</h2>
        <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Typed Actions provider tarafından ölçülen action_type değerlerini ayrı tutar. Pixel ve Custom Conversion kaynakları da Meta hesabından alınan snapshot verisidir.' : 'Typed Actions keep provider-observed action_type values separate. Pixel and Custom Conversion sources are also snapshots collected from the Meta account.' }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            [$isTr ? 'Gözlenen Action Türü' : 'Observed Action Types', count($actions)],
            [$isTr ? 'Dönüşüm Kaynağı' : 'Conversion Sources', count($sources)],
            ['Pixels', $pixelCount],
            [$isTr ? 'Custom Conversion' : 'Custom Conversions', $customConversionCount],
        ] as [$label, $value])
            <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900"><p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p><p class="mt-3 text-3xl font-bold text-gray-900 dark:text-white">{{ number_format($value) }}</p></article>
        @endforeach
    </div>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,.85fr)]">
        <article class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800 sm:px-6"><h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Typed Actions' : 'Typed Actions' }}</h3><p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Action türleri birbirine eklenip genel “Results” üretilmez.' : 'Action types are never summed into a generic “Results” metric.' }}</p></div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left">
                    <thead class="bg-gray-50/80 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:bg-white/[0.02]"><tr><th class="px-5 py-3">Action Type</th><th class="px-4 py-3 text-right">{{ $isTr ? 'Gözlenen Adet' : 'Observed Count' }}</th><th class="px-4 py-3 text-right">{{ $isTr ? 'Satır' : 'Rows' }}</th><th class="px-5 py-3">{{ $isTr ? 'Yorum' : 'Interpretation' }}</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($actions as $row)
                            <tr><td class="px-5 py-3.5"><p class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $row['label'] }}</p><p class="mt-0.5 max-w-sm truncate text-[11px] text-gray-400">{{ $row['action_type'] }}</p></td><td class="px-4 py-3.5 text-right text-sm font-bold tabular-nums text-gray-900 dark:text-white">{{ number_format((float) $row['value'], 2) }}</td><td class="px-4 py-3.5 text-right text-sm tabular-nums text-gray-500">{{ number_format((int) $row['rows']) }}</td><td class="px-5 py-3.5 text-xs text-gray-500 dark:text-gray-400">{{ $isTr ? 'Meta-attributed action; qualified lead / satış olduğu varsayılmaz.' : 'Meta-attributed action; not assumed to be a qualified lead / sale.' }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="px-5 py-12 text-center text-sm text-gray-400">{{ $isTr ? 'Typed Actions datasetinde kullanılabilir veri yok.' : 'No usable data in the Typed Actions dataset.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-6">
            <div class="flex items-center justify-between gap-3"><div><h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Ölçüm Kaynağı Sağlığı' : 'Measurement Source Health' }}</h3><p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Toplanan Pixel / Custom Conversion snapshot durumu.' : 'Collected Pixel / Custom Conversion snapshot state.' }}</p></div><span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-600 dark:bg-white/[0.05] dark:text-gray-300">{{ $availableSources }}/{{ count($sources) }} {{ $isTr ? 'aktif' : 'active' }}</span></div>
            <div class="mt-5 space-y-3">
                @forelse (array_slice($sources, 0, 12) as $row)
                    @php
                        $sourceProblem = ($row['is_unavailable'] ?? false) || ($row['is_archived'] ?? false);
                    @endphp
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                        <div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $row['source_name'] ?? ($row['source_type'].' '.$row['source_id']) }}</p><p class="mt-0.5 text-xs text-gray-400">{{ str_replace('_', ' ', $row['source_type']) }}{{ $row['event_type'] ? ' · '.str_replace('_', ' ', $row['event_type']) : '' }}</p></div><span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $sourceProblem ? 'bg-amber-500' : 'bg-emerald-500' }}"></span></div>
                        <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-gray-400"><span>{{ $isTr ? 'Son tetikleme' : 'Last fired' }}: {{ $row['last_fired_time'] ?? '—' }}</span>@if ($row['pixel_id'])<span>Pixel {{ $row['pixel_id'] }}</span>@endif</div>
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-gray-300 px-5 py-12 text-center text-sm text-gray-400 dark:border-gray-700">{{ $isTr ? 'Conversion source snapshot kullanıma hazır değil.' : 'Conversion source snapshot is not ready.' }}</div>
                @endforelse
            </div>
        </article>
    </div>

    <div class="rounded-xl border border-blue-200 bg-blue-50/60 px-4 py-3 text-xs leading-5 text-blue-800 dark:border-blue-500/20 dark:bg-blue-500/[0.06] dark:text-blue-300">
        {{ $isTr ? 'MOXDOP şu aşamada Meta action sayılarını “satış”, “kaliteli lead” veya genel Results olarak yorumlamaz. CRM/Business Action eşlemesi kurulduğunda gerçek iş sonucu katmanı bunun üzerine eklenecek.' : 'MOXDOP does not currently reinterpret Meta action counts as sales, qualified leads or generic Results. A real business-outcome layer can be added after CRM/Business Action mapping.' }}
    </div>
</section>
