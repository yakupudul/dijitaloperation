@php
    $isTr = app()->getLocale() === 'tr';
    $providerNames = [
        'META_ADS' => 'Meta Ads',
        'GOOGLE_ADS' => 'Google Ads',
        'GA4' => 'Google Analytics 4',
        'SEARCH_CONSOLE' => 'Search Console',
        'GSC' => 'Search Console',
        'GBP' => 'Google Business Profile',
        'WEBSITE' => $isTr ? 'Web Sitesi' : 'Website',
        'DATAFORSEO' => 'DataForSEO',
    ];
    $statusClasses = [
        'blue' => 'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20',
        'emerald' => 'bg-success-50 text-success-700 ring-success-200 dark:bg-success-500/10 dark:text-success-300 dark:ring-success-500/20',
        'amber' => 'bg-warning-50 text-warning-700 ring-warning-200 dark:bg-warning-500/10 dark:text-warning-300 dark:ring-warning-500/20',
        'rose' => 'bg-error-50 text-error-700 ring-error-200 dark:bg-error-500/10 dark:text-error-300 dark:ring-error-500/20',
        'slate' => 'bg-gray-100 text-gray-700 ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700',
    ];
@endphp

<div
    class="space-y-6"
    data-testid="collection-monitoring-panel"
    @if ($this->pollingInterval)
        wire:poll.{{ $this->pollingInterval }}="refreshActive"
    @endif
>
    <section class="overflow-hidden rounded-2xl bg-white shadow-theme-xs ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="flex flex-col gap-4 border-b border-gray-100 px-5 py-5 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800 md:px-6">
            <div class="flex items-start gap-3">
                <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400" aria-hidden="true">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M4 7.5h16M7 4v7M17 4v7M5.5 14.5h4v4h-4zM14.5 14.5h4v4h-4z" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                            {{ $isTr ? 'Veri Toplama Merkezi' : 'Data Collection Center' }}
                        </h2>
                        @if ($activeRuns !== [])
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700 ring-1 ring-inset ring-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20">
                                <span class="relative flex h-2 w-2">
                                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-blue-400 opacity-60"></span>
                                    <span class="relative inline-flex h-2 w-2 rounded-full bg-blue-500"></span>
                                </span>
                                {{ $isTr ? count($activeRuns).' aktif işlem' : count($activeRuns).' active run'.(count($activeRuns) === 1 ? '' : 's') }}
                            </span>
                        @endif
                    </div>
                    <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">
                        {{ $isTr
                            ? 'Bağlı kaynaklardan veri akışını, veri seti ilerlemesini ve sorunları tek ekrandan izleyin. Sayfayı kapatsanız da toplama arka planda devam eder.'
                            : 'Track connected-source ingestion, dataset progress and issues in one place. Collection continues in the background if you leave this page.' }}
                    </p>
                </div>
            </div>

            <button
                type="button"
                wire:click="reloadStatus"
                wire:loading.attr="disabled"
                wire:target="reloadStatus"
                class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-white px-3.5 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]"
            >
                <svg wire:loading.class="animate-spin" wire:target="reloadStatus" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M20 12a8 8 0 10-2.34 5.66M20 12V6m0 6h-6" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                {{ $isTr ? 'Durumu yenile' : 'Refresh status' }}
            </button>
        </div>

        @if ($statusError)
            <div class="mx-5 mt-5 flex items-start gap-3 rounded-xl bg-error-50 p-4 text-sm text-error-700 ring-1 ring-inset ring-error-200 dark:bg-error-500/10 dark:text-error-300 dark:ring-error-500/20 md:mx-6" role="status" aria-live="polite" data-testid="collection-status-error">
                <svg class="mt-0.5 h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M12 8v4m0 4h.01M10.3 4.5L2.8 17.5A2 2 0 004.5 20h15a2 2 0 001.7-3L13.7 4.5a2 2 0 00-3.4 0z" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <div>
                    <p class="font-semibold">{{ $isTr ? 'Canlı durum yenilenemedi' : 'Live status could not be refreshed' }}</p>
                    <p class="mt-0.5 opacity-90">{{ $statusError }}</p>
                </div>
            </div>
        @endif

        <div class="p-5 md:p-6">
            @forelse ($activeRuns as $run)
                @php
                    $summary = $run['summary'] ?? [];
                    $plan = $summary['plan_completion'] ?? [];
                    $progress = (float) ($plan['percentage'] ?? 0);
                    $progress = max(0, min(100, $progress));
                    $failed = (int) ($summary['datasets_failed'] ?? 0);
                    $retrying = (int) ($summary['datasets_retrying'] ?? 0);
                    $completed = (int) ($summary['datasets_completed'] ?? 0);
                    $total = max(0, (int) ($summary['datasets_total'] ?? 0));
                    $statusTone = $run['status']['tone'] ?? 'slate';
                    $statusClass = $statusClasses[$statusTone] ?? $statusClasses['slate'];
                    $assetName = $run['digital_asset_name'] ?? null;
                    $providerLabels = collect($run['providers'] ?? [])->map(fn ($p) => $providerNames[$p] ?? $p)->implode(' · ');
                @endphp

                <article
                    class="overflow-hidden rounded-2xl bg-gray-25 ring-1 ring-inset ring-gray-200 dark:bg-gray-950/30 dark:ring-gray-800"
                    data-testid="active-run-{{ $run['uuid'] }}"
                    wire:key="active-{{ $run['uuid'] }}"
                >
                    <div class="flex flex-col gap-5 p-5 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset {{ $statusClass }}" aria-label="{{ $run['status']['label'] }}">
                                    <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                    {{ $run['status']['label'] }}
                                </span>
                                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $providerLabels ?: '—' }}</span>
                            </div>

                            <h3 class="mt-3 text-xl font-semibold text-gray-900 dark:text-white">
                                {{ $assetName ?: ($isTr ? 'Bağlı dijital varlık' : 'Connected digital asset') }}
                            </h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                {{ $run['trigger_label'] ?? ($isTr ? 'Veri toplama' : 'Data collection') }}
                            </p>
                        </div>

                        <button
                            type="button"
                            wire:click="selectRun('{{ $run['uuid'] }}')"
                            class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-brand-500 px-3.5 py-2.5 text-sm font-medium text-white transition hover:bg-brand-600"
                        >
                            {{ $isTr ? 'Toplama detayları' : 'Collection details' }}
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M9 18l6-6-6-6" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                    </div>

                    <div class="grid border-y border-gray-200 bg-white sm:grid-cols-2 xl:grid-cols-4 dark:border-gray-800 dark:bg-gray-900">
                        <div class="border-b border-gray-200 p-4 sm:border-r xl:border-b-0 dark:border-gray-800">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ $isTr ? 'İlerleme' : 'Progress' }}</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ rtrim(rtrim(number_format($progress, 1), '0'), '.') }}%</p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $completed }}/{{ $total }} {{ $isTr ? 'veri seti tamamlandı' : 'datasets completed' }}</p>
                        </div>
                        <div class="border-b border-gray-200 p-4 xl:border-b-0 xl:border-r dark:border-gray-800">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ $isTr ? 'Kaydedilen kayıt' : 'Records stored' }}</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($summary['rows_written'] ?? 0) }}</p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $isTr ? 'Data Pool’a işlendi' : 'written to Data Pool' }}</p>
                        </div>
                        <div class="border-b border-gray-200 p-4 sm:border-b-0 sm:border-r dark:border-gray-800">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ $isTr ? 'Geçen süre' : 'Elapsed' }}</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $run['elapsed'] ?? '—' }}</p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $isTr ? 'Arka planda çalışıyor' : 'running in background' }}</p>
                        </div>
                        <div class="p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ $isTr ? 'Dikkat gereken' : 'Needs attention' }}</p>
                            <p class="mt-1 text-2xl font-semibold {{ $failed > 0 ? 'text-error-600 dark:text-error-400' : ($retrying > 0 ? 'text-warning-600 dark:text-warning-400' : 'text-success-600 dark:text-success-400') }}">
                                {{ $failed + $retrying }}
                            </p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                @if ($failed > 0)
                                    {{ $isTr ? $failed.' veri seti hata verdi' : $failed.' dataset'.($failed === 1 ? '' : 's').' failed' }}
                                @elseif ($retrying > 0)
                                    {{ $isTr ? $retrying.' veri seti yeniden deneniyor' : $retrying.' dataset'.($retrying === 1 ? '' : 's').' retrying' }}
                                @else
                                    {{ $isTr ? 'Sorun görünmüyor' : 'No issues detected' }}
                                @endif
                            </p>
                        </div>
                    </div>

                    <div class="p-5">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ $isTr ? 'Toplama planı' : 'Collection plan' }}</p>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $isTr ? 'Planlanan veri setlerinin tamamlanma oranı' : 'Completion of planned datasets' }}
                                </p>
                            </div>
                            <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $completed }}/{{ $total }}</span>
                        </div>
                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800" role="progressbar" aria-valuenow="{{ (int) $progress }}" aria-valuemin="0" aria-valuemax="100">
                            <div class="h-full rounded-full bg-brand-500 transition-all duration-500" style="width: {{ $progress }}%"></div>
                        </div>

                        @if ($failed > 0 || $retrying > 0)
                            <div class="mt-4 flex items-start gap-3 rounded-xl {{ $failed > 0 ? 'bg-error-50 text-error-700 ring-error-200 dark:bg-error-500/10 dark:text-error-300 dark:ring-error-500/20' : 'bg-warning-50 text-warning-700 ring-warning-200 dark:bg-warning-500/10 dark:text-warning-300 dark:ring-warning-500/20' }} p-3.5 ring-1 ring-inset" data-testid="exceptions-{{ $run['uuid'] }}">
                                <svg class="mt-0.5 h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path d="M12 8v4m0 4h.01M10.3 4.5L2.8 17.5A2 2 0 004.5 20h15a2 2 0 001.7-3L13.7 4.5a2 2 0 00-3.4 0z" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                <div>
                                    <p class="text-sm font-semibold">
                                        {{ $failed > 0
                                            ? ($isTr ? $failed.' veri seti dikkat istiyor' : $failed.' dataset'.($failed === 1 ? '' : 's').' need attention')
                                            : ($isTr ? 'Bazı veri setleri yeniden deneniyor' : 'Some datasets are being retried') }}
                                    </p>
                                    <p class="mt-0.5 text-xs opacity-90">
                                        {{ $isTr
                                            ? 'Detayları açarak hangi veri setinin neden durduğunu ve yeniden deneme durumunu görebilirsiniz.'
                                            : 'Open details to see which datasets stopped, why they stopped, and whether retry is automatic.' }}
                                    </p>
                                </div>
                            </div>
                        @endif

                        @if (! empty($run['resources']))
                            <div class="mt-4 grid gap-3 md:grid-cols-2">
                                @foreach ($run['resources'] as $resource)
                                    @php
                                        $resourceTone = $resource['status']['tone'] ?? 'slate';
                                        $resourceStatusClass = $statusClasses[$resourceTone] ?? $statusClasses['slate'];
                                        $resourceProgress = (float) ($resource['plan_completion']['percentage'] ?? 0);
                                        $resourceProgress = max(0, min(100, $resourceProgress));
                                    @endphp
                                    <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" wire:key="res-{{ $resource['uuid'] }}">
                                        <div class="flex items-center justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $resource['resource_display'] ?: $resource['provider_label'] }}</p>
                                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $resource['provider_label'] }}</p>
                                            </div>
                                            <span class="inline-flex shrink-0 items-center rounded-full px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $resourceStatusClass }}">{{ $resource['status']['label'] }}</span>
                                        </div>
                                        <div class="mt-3 flex items-center gap-3">
                                            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800">
                                                <div class="h-full rounded-full bg-brand-500" style="width: {{ $resourceProgress }}%"></div>
                                            </div>
                                            <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">{{ rtrim(rtrim(number_format($resourceProgress, 1), '0'), '.') }}%</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </article>
            @empty
                <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 px-6 py-12 text-center dark:border-gray-700" data-testid="collection-active-empty">
                    <span class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400" aria-hidden="true">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M4 7.5h16M7 4v7M17 4v7M5.5 14.5h4v4h-4zM14.5 14.5h4v4h-4z" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <p class="mt-3 text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Şu anda aktif veri toplama yok' : 'No active collection right now' }}</p>
                    <p class="mt-1 max-w-md text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Yeni bir toplama başladığında canlı ilerleme burada görünecek.' : 'Live progress will appear here when a new collection starts.' }}</p>
                </div>
            @endforelse
        </div>
    </section>

    @if ($selectedDetail)
        @php
            $detailSummary = $selectedDetail['summary'] ?? [];
            $detailPlan = $detailSummary['plan_completion'] ?? [];
            $detailProgress = max(0, min(100, (float) ($detailPlan['percentage'] ?? 0)));
            $detailTone = $selectedDetail['status']['tone'] ?? 'slate';
            $detailStatusClass = $statusClasses[$detailTone] ?? $statusClasses['slate'];
            $attention = $selectedDetail['attention_datasets'] ?? [];
        @endphp

        <section class="overflow-hidden rounded-2xl bg-white shadow-theme-xs ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-testid="collection-run-detail" aria-labelledby="collection-detail-heading">
            <div class="flex flex-col gap-4 border-b border-gray-100 px-5 py-5 lg:flex-row lg:items-center lg:justify-between dark:border-gray-800 md:px-6">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 id="collection-detail-heading" class="text-lg font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Toplama Detayı' : 'Collection Detail' }}</h3>
                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset {{ $detailStatusClass }}">{{ $selectedDetail['status']['label'] }}</span>
                    </div>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $selectedDetail['digital_asset_name'] ?? ($isTr ? 'Dijital varlık' : 'Digital asset') }} · {{ $selectedDetail['trigger_label'] ?? '' }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if (! ($selectedDetail['is_terminal'] ?? true))
                        <button type="button" wire:click="cancelSelected" wire:confirm="{{ __('operator.collection.cancel_confirm') }}" class="inline-flex items-center justify-center rounded-lg bg-white px-3 py-2 text-sm font-medium text-error-600 ring-1 ring-inset ring-error-200 hover:bg-error-50 dark:bg-gray-900 dark:text-error-400 dark:ring-error-500/20 dark:hover:bg-error-500/10">
                            {{ $isTr ? 'Toplamayı durdur' : 'Stop collection' }}
                        </button>
                    @endif
                    <button type="button" wire:click="toggleTechnical" class="inline-flex items-center justify-center rounded-lg bg-white px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                        {{ $isTr ? 'Teknik detay' : 'Technical details' }}
                    </button>
                    <button type="button" wire:click="clearSelection" class="inline-flex items-center justify-center rounded-lg bg-white px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                        {{ $isTr ? 'Kapat' : 'Close' }}
                    </button>
                </div>
            </div>

            <div class="p-5 md:p-6">
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-gray-800/50">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $isTr ? 'Plan ilerlemesi' : 'Plan progress' }}</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{{ rtrim(rtrim(number_format($detailProgress, 1), '0'), '.') }}%</p>
                    </div>
                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-gray-800/50">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $isTr ? 'Tamamlanan veri seti' : 'Completed datasets' }}</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{{ $detailSummary['datasets_completed'] ?? 0 }}/{{ $detailSummary['datasets_total'] ?? 0 }}</p>
                    </div>
                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-gray-800/50">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $isTr ? 'Kaydedilen kayıt' : 'Records stored' }}</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{{ number_format($detailSummary['rows_written'] ?? 0) }}</p>
                    </div>
                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-gray-800/50">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $isTr ? 'Geçen süre' : 'Elapsed' }}</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{{ $selectedDetail['elapsed'] ?? '—' }}</p>
                    </div>
                </div>

                @if (! empty($attention))
                    <div class="mt-5 rounded-xl bg-error-50 p-4 ring-1 ring-inset ring-error-200 dark:bg-error-500/10 dark:ring-error-500/20">
                        <div class="flex items-start gap-3">
                            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-error-100 text-error-600 dark:bg-error-500/20 dark:text-error-300" aria-hidden="true">
                                <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 8v4m0 4h.01M10.3 4.5L2.8 17.5A2 2 0 004.5 20h15a2 2 0 001.7-3L13.7 4.5a2 2 0 00-3.4 0z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-error-800 dark:text-error-200">{{ $isTr ? count($attention).' veri seti dikkat istiyor' : count($attention).' dataset'.(count($attention) === 1 ? '' : 's').' need attention' }}</p>
                                <p class="mt-0.5 text-xs text-error-700 dark:text-error-300">{{ $isTr ? 'Sorunlu veri setleri listede en üstte gösterilir. Hata nedeni ve yeniden deneme bilgisi aşağıdadır.' : 'Datasets with issues are listed first. Error reason and retry information are shown below.' }}</p>
                            </div>
                        </div>
                    </div>
                @endif

                @if (! empty($selectedDetail['materialization']))
                    <div class="mt-5 grid gap-3 rounded-xl bg-brand-25 p-4 ring-1 ring-inset ring-brand-100 sm:grid-cols-2 dark:bg-brand-500/[0.06] dark:ring-brand-500/15" data-testid="materialization-panel">
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-brand-600 dark:text-brand-400">{{ $isTr ? 'Son yenileme' : 'Latest refresh' }}</p>
                            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $selectedDetail['materialization']['latest_run_status']['label'] ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-brand-600 dark:text-brand-400">{{ $isTr ? 'Mevcut veri' : 'Existing data' }}</p>
                            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                                {{ $selectedDetail['materialization']['pool']['label'] ?? '—' }}
                                @if (! empty($selectedDetail['materialization']['pool']['coverage_end_date']))
                                    · {{ $selectedDetail['materialization']['pool']['coverage_end_date'] }}
                                @endif
                            </p>
                        </div>
                        @if (! empty($selectedDetail['materialization']['note']))
                            <p class="text-xs text-gray-600 sm:col-span-2 dark:text-gray-400">{{ $selectedDetail['materialization']['note'] }}</p>
                        @endif
                    </div>
                @endif

                <div class="mt-6 space-y-4">
                    @foreach ($selectedDetail['resources'] ?? [] as $resource)
                        @php
                            $resourceTone = $resource['status']['tone'] ?? 'slate';
                            $resourceStatusClass = $statusClasses[$resourceTone] ?? $statusClasses['slate'];
                            $resourceProgress = max(0, min(100, (float) ($resource['plan_completion']['percentage'] ?? 0)));
                        @endphp
                        <div class="overflow-hidden rounded-xl ring-1 ring-inset ring-gray-200 dark:ring-gray-800" wire:key="detail-res-{{ $resource['uuid'] }}">
                            <div class="flex flex-col gap-3 bg-gray-50 px-4 py-4 sm:flex-row sm:items-center sm:justify-between dark:bg-gray-800/50">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $resource['resource_display'] ?: $resource['provider_label'] }}</h4>
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $resourceStatusClass }}">{{ $resource['status']['label'] }}</span>
                                    </div>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $resource['provider_label'] }} · {{ $resource['datasets_completed'] ?? 0 }}/{{ $resource['datasets_total'] ?? 0 }} {{ $isTr ? 'veri seti' : 'datasets' }}</p>
                                </div>
                                <div class="flex min-w-40 items-center gap-3">
                                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                        <div class="h-full rounded-full bg-brand-500" style="width: {{ $resourceProgress }}%"></div>
                                    </div>
                                    <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">{{ rtrim(rtrim(number_format($resourceProgress, 1), '0'), '.') }}%</span>
                                </div>
                            </div>

                            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($resource['datasets'] ?? [] as $dataset)
                                    @php
                                        $datasetTone = $dataset['status']['tone'] ?? 'slate';
                                        $datasetStatusClass = $statusClasses[$datasetTone] ?? $statusClasses['slate'];
                                        $p = $dataset['progress'] ?? [];
                                        $datasetPct = ($p['allows_percentage'] ?? false) && ($p['percentage'] ?? null) !== null
                                            ? max(0, min(100, (float) $p['percentage']))
                                            : null;
                                        $hasError = ! empty($dataset['error']['message']);
                                        $isRetrying = ($dataset['status']['key'] ?? '') === 'retrying';
                                    @endphp
                                    <div class="p-4" data-testid="dataset-{{ $dataset['uuid'] }}" wire:key="ds-{{ $dataset['uuid'] }}">
                                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $dataset['display_name'] }}</p>
                                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $datasetStatusClass }}">{{ $dataset['status']['label'] }}</span>
                                                </div>

                                                <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                                    @if ($datasetPct !== null)
                                                        <span>{{ rtrim(rtrim(number_format($datasetPct, 1), '0'), '.') }}% {{ $isTr ? 'tamamlandı' : 'complete' }}</span>
                                                    @elseif (($p['type'] ?? '') === 'INDETERMINATE')
                                                        <span>{{ $isTr ? 'Veri alınıyor' : 'Collecting data' }}</span>
                                                    @elseif (($p['type'] ?? '') === 'STAGE_BASED')
                                                        <span>{{ $p['stage'] ?? ($isTr ? 'İşleniyor' : 'Processing') }}</span>
                                                    @endif
                                                    <span>{{ number_format($p['rows_written'] ?? 0) }} {{ $isTr ? 'kayıt' : 'records' }}</span>
                                                    <span>{{ $isTr ? 'Deneme' : 'Attempt' }} {{ $dataset['attempt_count'] }}@if (! empty($dataset['max_attempts']))/{{ $dataset['max_attempts'] }}@endif</span>
                                                    @if (! empty($dataset['elapsed']))
                                                        <span>{{ $dataset['elapsed'] }}</span>
                                                    @endif
                                                </div>

                                                @if ($datasetPct !== null)
                                                    <div class="mt-2.5 h-1.5 max-w-xl overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800" role="progressbar" aria-valuenow="{{ (int) $datasetPct }}" aria-valuemin="0" aria-valuemax="100">
                                                        <div class="h-full rounded-full bg-brand-500" style="width: {{ $datasetPct }}%"></div>
                                                    </div>
                                                @endif

                                                @if ($hasError || $isRetrying)
                                                    <div class="mt-3 rounded-lg bg-error-50 p-3 text-xs text-error-700 ring-1 ring-inset ring-error-100 dark:bg-error-500/10 dark:text-error-300 dark:ring-error-500/20">
                                                        @if (! empty($dataset['error']['title']))
                                                            <p class="font-semibold">{{ $dataset['error']['title'] }}</p>
                                                        @endif
                                                        @if ($hasError)
                                                            <p class="mt-0.5">{{ $dataset['error']['message'] }}</p>
                                                        @endif
                                                        @if ($dataset['error']['automatic_retry'] ?? false)
                                                            <p class="mt-1 font-medium text-warning-700 dark:text-warning-300">
                                                                {{ $isTr ? 'Otomatik olarak yeniden denenecek.' : 'Will retry automatically.' }}
                                                                @if (! empty($dataset['retry_at']))
                                                                    {{ $dataset['retry_at'] }}
                                                                @endif
                                                            </p>
                                                        @elseif ($dataset['error']['operator_action_required'] ?? false)
                                                            <p class="mt-1 font-medium">{{ $isTr ? 'Operatör işlemi gerekiyor.' : 'Operator action is required.' }}</p>
                                                        @endif
                                                    </div>
                                                @endif

                                                @if (($dataset['status']['key'] ?? '') === 'completed' && (int) ($p['rows_written'] ?? 0) === 0)
                                                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $isTr ? 'Toplama başarıyla tamamlandı; bu aralıkta kayıt dönmedi.' : 'Collection completed successfully; no rows were returned for this range.' }}</p>
                                                @endif
                                            </div>

                                            @if (($dataset['status']['key'] ?? '') === 'failed')
                                                <button type="button" wire:click="retryDataset('{{ $dataset['uuid'] }}')" class="inline-flex shrink-0 items-center justify-center rounded-lg bg-white px-3 py-2 text-xs font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                                    {{ $isTr ? 'Yeniden dene' : 'Retry' }}
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($showTechnical && ! empty($selectedDetail['technical']))
                    <div class="mt-6 rounded-xl bg-gray-950 p-4 text-xs text-gray-300" data-testid="collection-technical">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <p class="font-semibold text-white">{{ $isTr ? 'Teknik çalışma bilgileri' : 'Technical run information' }}</p>
                            <span class="rounded bg-white/10 px-2 py-1 font-mono text-[10px] uppercase tracking-wide text-gray-400">debug</span>
                        </div>
                        <dl class="grid gap-2 sm:grid-cols-2">
                            @foreach ($selectedDetail['technical'] as $k => $v)
                                <div class="rounded-lg bg-white/[0.04] p-3">
                                    <dt class="text-gray-500">{{ $k }}</dt>
                                    <dd class="mt-1 break-all font-mono text-gray-200">{{ $v }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endif
            </div>
        </section>
    @endif

    <section class="overflow-hidden rounded-2xl bg-white shadow-theme-xs ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" aria-labelledby="collection-history-heading">
        <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-5 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800 md:px-6">
            <div>
                <h3 id="collection-history-heading" class="text-base font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Toplama Geçmişi' : 'Collection History' }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Son veri toplama işlemleri ve sonuçları.' : 'Recent collection runs and their outcomes.' }}</p>
            </div>
            <select class="h-10 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 outline-none transition focus:border-brand-300 focus:ring-4 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" wire:model.live="historyStatus" aria-label="{{ __('operator.collection.filter_status') }}">
                <option value="">{{ $isTr ? 'Tüm durumlar' : 'All statuses' }}</option>
                <option value="completed">{{ $isTr ? 'Tamamlandı' : 'Completed' }}</option>
                <option value="partial">{{ $isTr ? 'Kısmen tamamlandı' : 'Partially completed' }}</option>
                <option value="failed">{{ $isTr ? 'Başarısız' : 'Failed' }}</option>
                <option value="cancelled">{{ $isTr ? 'İptal edildi' : 'Cancelled' }}</option>
            </select>
        </div>

        @if ($history->isEmpty())
            <div class="px-5 py-10 text-center md:px-6" data-testid="collection-history-empty">
                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ $isTr ? 'Henüz toplama geçmişi yok' : 'No collection history yet' }}</p>
            </div>
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-800">
                    <thead class="bg-gray-50 dark:bg-gray-800/50">
                        <tr class="text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <th class="px-5 py-3 md:px-6">{{ $isTr ? 'Başlangıç' : 'Started' }}</th>
                            <th class="px-4 py-3">{{ $isTr ? 'Kaynak' : 'Source' }}</th>
                            <th class="px-4 py-3">{{ $isTr ? 'Durum' : 'Status' }}</th>
                            <th class="px-4 py-3">{{ $isTr ? 'Süre' : 'Duration' }}</th>
                            <th class="px-4 py-3">{{ $isTr ? 'Veri setleri' : 'Datasets' }}</th>
                            <th class="px-4 py-3">{{ $isTr ? 'Kayıtlar' : 'Records' }}</th>
                            <th class="px-5 py-3 text-right md:px-6"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($history as $row)
                            @php
                                $historyTone = $row['status']['tone'] ?? 'slate';
                                $historyStatusClass = $statusClasses[$historyTone] ?? $statusClasses['slate'];
                                $historyProviders = collect($row['providers'] ?? [])->map(fn ($p) => $providerNames[$p] ?? $p)->implode(', ');
                                $started = $row['started_at']
                                    ? \Illuminate\Support\Carbon::parse($row['started_at'])->timezone(config('app.timezone'))->format($isTr ? 'd.m.Y H:i' : 'M j, Y H:i')
                                    : '—';
                            @endphp
                            <tr class="transition hover:bg-gray-50/70 dark:hover:bg-white/[0.02]" wire:key="hist-{{ $row['uuid'] }}" data-testid="history-run-{{ $row['uuid'] }}">
                                <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-700 dark:text-gray-300 md:px-6">{{ $started }}</td>
                                <td class="px-4 py-4 text-sm font-medium text-gray-900 dark:text-white">{{ $historyProviders ?: '—' }}</td>
                                <td class="px-4 py-4"><span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $historyStatusClass }}">{{ $row['status']['label'] }}</span></td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-gray-600 dark:text-gray-400">{{ $row['elapsed'] ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-gray-600 dark:text-gray-400">{{ $row['summary']['datasets_completed'] }}/{{ $row['summary']['datasets_total'] }}</td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm font-medium text-gray-800 dark:text-gray-200">{{ number_format($row['summary']['rows_written'] ?? 0) }}</td>
                                <td class="px-5 py-4 text-right md:px-6">
                                    <button type="button" wire:click="selectRun('{{ $row['uuid'] }}')" class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-xs font-semibold text-brand-600 transition hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-500/10">{{ $isTr ? 'Detay' : 'Details' }}</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-gray-100 md:hidden dark:divide-gray-800">
                @foreach ($history as $row)
                    @php
                        $historyTone = $row['status']['tone'] ?? 'slate';
                        $historyStatusClass = $statusClasses[$historyTone] ?? $statusClasses['slate'];
                        $historyProviders = collect($row['providers'] ?? [])->map(fn ($p) => $providerNames[$p] ?? $p)->implode(', ');
                        $started = $row['started_at']
                            ? \Illuminate\Support\Carbon::parse($row['started_at'])->timezone(config('app.timezone'))->format($isTr ? 'd.m.Y H:i' : 'M j, Y H:i')
                            : '—';
                    @endphp
                    <div class="p-5" wire:key="hist-mobile-{{ $row['uuid'] }}">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $historyProviders ?: '—' }}</p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $started }} · {{ $row['elapsed'] ?? '—' }}</p>
                            </div>
                            <span class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $historyStatusClass }}">{{ $row['status']['label'] }}</span>
                        </div>
                        <div class="mt-3 flex items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
                            <span>{{ $row['summary']['datasets_completed'] }}/{{ $row['summary']['datasets_total'] }} {{ $isTr ? 'veri seti' : 'datasets' }} · {{ number_format($row['summary']['rows_written'] ?? 0) }} {{ $isTr ? 'kayıt' : 'records' }}</span>
                            <button type="button" wire:click="selectRun('{{ $row['uuid'] }}')" class="font-semibold text-brand-600 dark:text-brand-400">{{ $isTr ? 'Detay' : 'Details' }}</button>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800 md:px-6">
                {{ $history->links() }}
            </div>
        @endif
    </section>
</div>
