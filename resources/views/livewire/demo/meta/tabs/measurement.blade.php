@php
    $isTr = app()->getLocale() === 'tr';
    $actions = $professional['typed_actions'] ?? [];
    $sources = $professional['conversion_sources'] ?? [];
    $pixelCount = collect($sources)->where('source_type', 'PIXEL')->count();
    $customConversionCount = collect($sources)->where('source_type', 'CUSTOM_CONVERSION')->count();
    $availableSources = collect($sources)->filter(fn ($row) => ! ($row['is_unavailable'] ?? false) && ! ($row['is_archived'] ?? false))->count();
    $timezone = $professional['timezone'] ?? config('app.timezone', 'UTC');

    $formatTime = static function ($value) use ($isTr, $timezone): string {
        if (! filled($value)) return '—';

        try {
            $time = \Carbon\CarbonImmutable::parse((string) $value)->setTimezone($timezone);
            return $isTr ? $time->locale('tr')->translatedFormat('j M Y H:i') : $time->format('M j, Y H:i');
        } catch (\Throwable) {
            return (string) $value;
        }
    };

    $kindLabel = static function (string $kind) use ($isTr): string {
        return match ($kind) {
            'conversion' => $isTr ? 'Dönüşüm' : 'Conversion',
            'traffic' => $isTr ? 'Trafik' : 'Traffic',
            'engagement' => $isTr ? 'Etkileşim' : 'Engagement',
            'negative' => $isTr ? 'Olumsuz Sinyal' : 'Negative Signal',
            default => $isTr ? 'Diğer' : 'Other',
        };
    };

    $kindClass = static function (string $kind): string {
        return match ($kind) {
            'conversion' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
            'traffic' => 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300',
            'engagement' => 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300',
            'negative' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
            default => 'bg-gray-100 text-gray-600 dark:bg-white/[0.05] dark:text-gray-300',
        };
    };

    $sourceTypeLabel = static function (?string $sourceType) use ($isTr): string {
        return match (strtoupper((string) $sourceType)) {
            'PIXEL' => 'Pixel',
            'CUSTOM_CONVERSION' => $isTr ? 'Özel Dönüşüm' : 'Custom Conversion',
            default => str_replace('_', ' ', (string) $sourceType),
        };
    };
@endphp

<section class="space-y-5">
    <div>
        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">{{ $isTr ? 'Dönüşümler ve Ölçüm' : 'Conversions and Measurement' }}</p>
        <h2 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Reklamlar hangi sonuçları üretti ve bu sonuçlar nasıl ölçülüyor?' : 'What outcomes did ads generate and how are they measured?' }}</h2>
        <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Lead, mesaj, trafik ve etkileşim aksiyonları kendi gerçek anlamlarıyla ayrı ayrı gösterilir. Birbirini tekrar eden teknik varyantlar genel özetlerde tek sonuç gibi çoğaltılmaz.' : 'Leads, messaging, traffic and engagement actions retain their real semantics; overlapping technical variants are not duplicated in headline summaries.' }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            [$isTr ? 'Meta Aksiyon Türü' : 'Meta Action Types', count($actions)],
            [$isTr ? 'Ölçüm Kaynağı' : 'Measurement Sources', count($sources)],
            ['Pixel', $pixelCount],
            [$isTr ? 'Özel Dönüşüm' : 'Custom Conversions', $customConversionCount],
        ] as [$label, $value])
            <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900"><p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p><p class="mt-3 text-3xl font-bold text-gray-900 dark:text-white">{{ number_format($value) }}</p></article>
        @endforeach
    </div>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,.85fr)]">
        <article class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800 sm:px-6">
                <h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Meta’nın Ölçtüğü Aksiyonlar' : 'Meta-observed Actions' }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Farklı aksiyonlar birbirine eklenmez. Aynı teknik kelimeyi içeren action_type değerleri de otomatik olarak aynı sonuç kabul edilmez.' : 'Different actions are never added together and similar raw names are not automatically treated as the same outcome.' }}</p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-left">
                    <thead class="bg-gray-50/80 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:bg-white/[0.02]">
                        <tr>
                            <th class="px-5 py-3">{{ $isTr ? 'Aksiyon' : 'Action' }}</th>
                            <th class="px-4 py-3">{{ $isTr ? 'Tür' : 'Type' }}</th>
                            <th class="px-4 py-3 text-right">{{ $isTr ? 'Ölçülen Adet' : 'Observed Count' }}</th>
                            <th class="px-5 py-3">{{ $isTr ? 'Ne Anlama Geliyor?' : 'Meaning' }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($actions as $row)
                            @php $kind = (string) ($row['kind'] ?? 'other'); @endphp
                            <tr>
                                <td class="px-5 py-3.5">
                                    <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $isTr ? ($row['label_tr'] ?? $row['label']) : ($row['label_en'] ?? $row['label']) }}</p>
                                    <details class="mt-1"><summary class="cursor-pointer text-[10px] text-gray-300">{{ $isTr ? 'Teknik action adını göster' : 'Show raw action type' }}</summary><code class="text-[10px] text-gray-400">{{ $row['action_type'] }}</code></details>
                                </td>
                                <td class="px-4 py-3.5"><span class="inline-flex rounded-full px-2 py-1 text-[10px] font-semibold {{ $kindClass($kind) }}">{{ $kindLabel($kind) }}</span></td>
                                <td class="px-4 py-3.5 text-right text-sm font-bold tabular-nums text-gray-900 dark:text-white">{{ number_format((float) $row['value'], 2) }}</td>
                                <td class="max-w-md px-5 py-3.5 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $isTr ? ($row['description_tr'] ?? 'Meta tarafından raporlanan ayrı bir aksiyon türü.') : ($row['description_en'] ?? 'A distinct action type reported by Meta.') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-5 py-12 text-center text-sm text-gray-400">{{ $isTr ? 'Seçili dönemde ölçülmüş action verisi yok.' : 'No observed action data in this period.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-6">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Ölçüm Sistemi Sağlığı' : 'Measurement Health' }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Pixel ve Özel Dönüşüm kaynaklarının Meta hesabındaki durumu.' : 'State of Pixels and Custom Conversions in the Meta account.' }}</p>
                </div>
                <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-600 dark:bg-white/[0.05] dark:text-gray-300">{{ $availableSources }}/{{ count($sources) }} {{ $isTr ? 'kullanılabilir' : 'available' }}</span>
            </div>

            <div class="mt-5 space-y-3">
                @forelse (array_slice($sources, 0, 12) as $row)
                    @php $sourceProblem = ($row['is_unavailable'] ?? false) || ($row['is_archived'] ?? false); @endphp
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $row['source_name'] ?? ($row['source_type'].' '.$row['source_id']) }}</p>
                                <p class="mt-0.5 text-xs text-gray-400">{{ $sourceTypeLabel($row['source_type'] ?? null) }}{{ $row['event_type'] ? ' · '.str_replace('_', ' ', $row['event_type']) : '' }}</p>
                            </div>
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $sourceProblem ? 'bg-amber-500' : 'bg-emerald-500' }}"></span>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-gray-400">
                            <span>{{ $isTr ? 'Son ölçüm' : 'Last fired' }}: {{ $formatTime($row['last_fired_time'] ?? null) }}</span>
                            @if ($row['pixel_id'])<span>Pixel {{ $row['pixel_id'] }}</span>@endif
                        </div>
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-gray-300 px-5 py-12 text-center text-sm text-gray-400 dark:border-gray-700">{{ $isTr ? 'Pixel veya Özel Dönüşüm kaynak bilgisi yok.' : 'No Pixel or Custom Conversion source data.' }}</div>
                @endforelse
            </div>
        </article>
    </div>

    <div class="rounded-xl border border-blue-200 bg-blue-50/60 px-4 py-3 text-xs leading-5 text-blue-800 dark:border-blue-500/20 dark:bg-blue-500/[0.06] dark:text-blue-300">{{ $isTr ? 'Öne çıkan gerçek sonuçlar Genel Bakış, Performans, Kampanyalar, Reklam Setleri, Reklamlar ve ilişkilendirilebildiği durumda Kreatifler alanında da kendi bağlamında gösterilir. Bu ayrıntılı tablo ise Meta’nın gönderdiği ayrı action_type kayıtlarını korur.' : 'Headline outcomes also appear throughout the workspace, while this detailed table preserves the distinct action_type records returned by Meta.' }}</div>
</section>
