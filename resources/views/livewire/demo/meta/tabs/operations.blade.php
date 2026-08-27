@php
    $isTr = app()->getLocale() === 'tr';
    $operations = $data['operations'] ?? [];
    $findings = $operations['findings'] ?? [];
    $recommendations = $operations['recommendations'] ?? [];
    $tasks = $operations['tasks'] ?? [];
    $outcomes = $operations['outcomes'] ?? [];
    $changes = $professional['change_history'] ?? [];
    $datasets = $professional['datasets'] ?? [];
    $health = $professional['health'] ?? ['usable' => 0, 'total' => 0, 'issues' => []];
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

    $objectTypeLabel = static function (?string $value) use ($isTr): string {
        if (! $isTr || ! filled($value)) return $value ?: '—';

        return match (strtoupper((string) $value)) {
            'ACCOUNT' => 'Reklam Hesabı',
            'CAMPAIGN' => 'Kampanya',
            'ADSET', 'AD_SET', 'ADGROUP', 'AD_GROUP' => 'Reklam Seti',
            'AD' => 'Reklam',
            'CREATIVE' => 'Kreatif',
            default => str_replace('_', ' ', (string) $value),
        };
    };
@endphp

<section class="space-y-5">
    <div>
        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">MOXDOP · Observe → Diagnose → Recommend</p>
        <h2 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $isTr ? 'İçgörüler & Aksiyonlar' : 'Insights & Actions' }}</h2>
        <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Bu alan Meta Ads verisini MOXDOP operasyon akışıyla birleştirir. Analiz motoru gerçek bir bulgu üretmediyse sistem kendi kendine teşhis veya öneri uydurmaz.' : 'This workspace connects Meta Ads evidence with the MOXDOP operations layer. If no real finding exists, the system does not invent one.' }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            [$isTr ? 'Bulgular' : 'Findings', count($findings)],
            [$isTr ? 'Öneriler' : 'Recommendations', count($recommendations)],
            [$isTr ? 'Görevler' : 'Tasks', count($tasks)],
            [$isTr ? 'İş Sonuçları' : 'Outcomes', count($outcomes)],
        ] as [$label, $value])
            <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900"><p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p><p class="mt-3 text-3xl font-bold text-gray-900 dark:text-white">{{ number_format($value) }}</p></article>
        @endforeach
    </div>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(320px,.65fr)]">
        <article class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800 sm:px-6">
                <h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Meta Değişiklik Geçmişi' : 'Meta Change History' }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Reklam hesabında gerçekleşen gerçek değişiklikler. Bir değişikliğin performansla aynı zamana denk gelmesi otomatik olarak neden-sonuç ilişkisi sayılmaz.' : 'Real account changes. Temporal proximity is not automatically treated as causality.' }}</p>
            </div>

            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse (array_slice($changes, 0, 30) as $row)
                    <div class="grid gap-2 px-5 py-4 sm:grid-cols-[160px_minmax(0,1fr)_160px] sm:items-center">
                        <div>
                            <p class="text-xs font-semibold text-gray-600 dark:text-gray-300">{{ $formatTime($row['event_time'] ?? null) }}</p>
                            <p class="mt-0.5 text-[11px] text-gray-400">{{ $row['actor_name'] ?? $row['application_name'] ?? 'Meta' }}</p>
                        </div>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $row['translated_event_type'] ?? $row['event_type'] ?? ($isTr ? 'Değişiklik' : 'Change') }}</p>
                            <p class="mt-0.5 truncate text-xs text-gray-400">{{ $row['object_name'] ?? $row['object_id'] ?? '—' }}</p>
                        </div>
                        <div class="text-xs text-gray-400 sm:text-right">{{ $objectTypeLabel($row['object_type'] ?? null) }}</div>
                    </div>
                @empty
                    <div class="px-5 py-14 text-center text-sm text-gray-400">{{ $isTr ? 'Seçili dönem için değişiklik kaydı yok.' : 'No change-history data for the selected period.' }}</div>
                @endforelse
            </div>
        </article>

        <article class="h-fit rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Veri Hazırlığı' : 'Data Readiness' }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Analiz motorunun kullanabileceği veri gruplarının genel durumu.' : 'Overall readiness of the data groups available to analysis.' }}</p>
                </div>
                <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-600 dark:bg-white/[0.05] dark:text-gray-300">{{ $health['usable'] ?? 0 }}/{{ $health['total'] ?? 0 }}</span>
            </div>

            @if (($health['usable'] ?? 0) === ($health['total'] ?? -1) && ($health['total'] ?? 0) > 0)
                <div class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50/60 p-4 text-sm text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/[0.06] dark:text-emerald-300">{{ $isTr ? 'Gerekli veri grupları kullanılabilir durumda.' : 'Required data groups are usable.' }}</div>
            @else
                <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50/60 p-4 text-sm text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/[0.06] dark:text-amber-300">
                    <p class="font-semibold">{{ $isTr ? 'Bazı veri gruplarında kapsam veya güncellik sınırlaması var.' : 'Some data groups have coverage or freshness limitations.' }}</p>
                    <p class="mt-1 text-xs opacity-80">{{ $isTr ? 'Bu durum eksik alanların 0 kabul edildiği anlamına gelmez.' : 'Missing fields are not treated as zero.' }}</p>
                </div>
            @endif

            <details class="mt-4 rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                <summary class="cursor-pointer text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $isTr ? 'Uzman için veri grubu ayrıntıları' : 'Dataset details for experts' }}</summary>
                <div class="mt-4 max-h-[520px] space-y-2 overflow-y-auto pr-1">
                    @forelse ($datasets as $datasetId => $row)
                        @php $usable = ($row['data_source_state'] ?? 'unavailable') !== 'unavailable'; @endphp
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate font-mono text-[11px] font-semibold text-gray-600 dark:text-gray-300">{{ $datasetId }}</p>
                                    <p class="mt-1 font-mono text-[10px] text-gray-400">{{ $row['freshness_state'] ?? 'UNKNOWN' }} · {{ $row['coverage_state'] ?? 'NOT_COVERED' }} · {{ $row['integrity_status'] ?? '—' }}</p>
                                </div>
                                <span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full {{ $usable ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                            </div>
                            @if (! empty($row['effective_start']) || ! empty($row['effective_end']))<p class="mt-2 text-[10px] text-gray-400">{{ $row['effective_start'] ?? '—' }} → {{ $row['effective_end'] ?? '—' }}</p>@endif
                        </div>
                    @empty
                        <p class="py-8 text-center text-sm text-gray-400">{{ $isTr ? 'Teknik veri hazırlığı bilgisi yok.' : 'No technical readiness information.' }}</p>
                    @endforelse
                </div>
            </details>
        </article>
    </div>

    <div class="grid gap-5 lg:grid-cols-2">
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-6">
            <h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Analiz Durumu' : 'Analysis State' }}</h3>
            @if (count($findings) > 0)
                <div class="mt-4 space-y-3">
                    @foreach (array_slice($findings, 0, 8) as $finding)
                        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800"><p class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $finding['title'] ?? ($isTr ? 'Bulgu' : 'Finding') }}</p><p class="mt-1 text-xs text-gray-400">{{ $finding['severity'] ?? '' }}</p></div>
                    @endforeach
                </div>
            @else
                <div class="mt-4 rounded-xl border border-dashed border-gray-300 px-5 py-10 text-center dark:border-gray-700">
                    <p class="text-sm font-semibold text-gray-600 dark:text-gray-300">{{ $isTr ? 'Henüz gerçek performans bulgusu üretilmedi' : 'No real performance finding has been generated yet' }}</p>
                    <p class="mt-2 text-xs leading-5 text-gray-400">{{ $isTr ? 'Verinin toplanmış olması, analiz motorunun otomatik olarak bulgu üretmiş olduğu anlamına gelmez.' : 'Collected data and generated findings are separate states.' }}</p>
                </div>
            @endif
        </article>

        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-6">
            <h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Aksiyon Akışı' : 'Action Flow' }}</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'MOXDOP’un Meta verisini işletme değerine dönüştürme zinciri.' : 'MOXDOP’s chain for turning Meta evidence into business value.' }}</p>
            <div class="mt-6 space-y-0">
                @foreach ([[$isTr ? 'Bulgu' : 'Finding', count($findings)], [$isTr ? 'Öneri' : 'Recommendation', count($recommendations)], [$isTr ? 'Görev' : 'Task', count($tasks)], [$isTr ? 'İş Sonucu' : 'Outcome', count($outcomes)]] as $index => [$step, $count])
                    <div class="relative flex gap-3 pb-5 last:pb-0">
                        @if ($index < 3)<span class="absolute left-[9px] top-5 h-full w-px bg-gray-200 dark:bg-gray-800"></span>@endif
                        <span class="relative z-10 mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-gray-300 bg-white text-[10px] font-bold text-gray-400 dark:border-gray-700 dark:bg-gray-900">{{ $index + 1 }}</span>
                        <div><p class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $step }} <span class="ml-1 text-xs text-gray-400">{{ $count }}</span></p><p class="mt-0.5 text-xs text-gray-400">{{ $count > 0 ? ($isTr ? 'Operasyon kaydı mevcut' : 'Operational record exists') : ($isTr ? 'Henüz oluşturulmadı' : 'Not created yet') }}</p></div>
                    </div>
                @endforeach
            </div>
        </article>
    </div>
</section>
