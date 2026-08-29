<div wire:poll.2s.visible class="space-y-4">
    @if ($actionMessage)
        <div class="rounded-xl bg-blue-50 px-4 py-3 text-sm text-blue-700 ring-1 ring-inset ring-blue-100 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20">
            {{ $actionMessage }}
        </div>
    @endif

    @forelse ($runs as $run)
        <section class="overflow-hidden rounded-2xl bg-white shadow-theme-xs ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" wire:key="meta-live-run-{{ $run['id'] }}">
            <div class="p-5 md:p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="relative flex h-2.5 w-2.5">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-blue-400 opacity-60"></span>
                                <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-blue-500"></span>
                            </span>
                            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Meta Ads verileri çekiliyor</h2>
                            <span class="text-xs font-medium text-gray-500">{{ $run['status_label'] }}</span>
                        </div>
                        <p class="mt-1 pl-[18px] text-sm text-gray-500 dark:text-gray-400">{{ $run['label'] }} · Run #{{ $run['id'] }}</p>
                    </div>
                    @if ($run['can_stop'])
                        <button type="button" wire:click="stopRun({{ $run['id'] }})" wire:loading.attr="disabled" class="rounded-lg px-3 py-2 text-sm font-medium text-error-600 ring-1 ring-inset ring-gray-200 hover:bg-error-50 dark:text-error-400 dark:ring-gray-700 dark:hover:bg-error-500/10">Durdur</button>
                    @endif
                </div>

                <div class="mt-5">
                    <div class="mb-2 flex items-center justify-between gap-3 text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-300">Toplam ilerleme</span>
                        <span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($run['progress_percent'], 1) }}%</span>
                    </div>
                    <div class="h-2.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                        <div class="h-full rounded-full bg-brand-500 transition-all duration-500" style="width: {{ max(0, min(100, $run['progress_percent'])) }}%"></div>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-4">
                        <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-xs text-gray-500">Reklam hesabı</p><p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $run['accounts_finished'] }}/{{ $run['accounts_total'] }}</p></div>
                        <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-xs text-gray-500">Veri seti</p><p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $run['datasets_finished'] }}/{{ $run['datasets_total'] }}</p></div>
                        <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-xs text-gray-500">Kaydedilen satır</p><p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ number_format($run['rows_written'], 0, ',', '.') }}</p></div>
                        <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-xs text-gray-500">Son hareket</p><p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $run['last_activity'] }}</p></div>
                    </div>
                </div>

                @if ($run['datasets_failed'] > 0 || $run['datasets_retrying'] > 0)
                    <div class="mt-4 rounded-xl bg-warning-50 p-4 text-sm text-warning-800 ring-1 ring-inset ring-warning-200 dark:bg-warning-500/10 dark:text-warning-300 dark:ring-warning-500/20">
                        <span class="font-semibold">{{ $run['datasets_failed'] }} hata</span>
                        @if ($run['datasets_retrying'] > 0) · {{ $run['datasets_retrying'] }} yeniden deneniyor @endif
                        <span class="block mt-1 text-xs opacity-80">Diğer veri setleri çekilmeye devam eder.</span>
                    </div>
                @endif
            </div>

            <div class="border-t border-gray-100 dark:border-gray-800">
                @foreach ($run['resources'] as $resource)
                    <article class="px-5 py-4 md:px-6" wire:key="meta-live-resource-{{ $resource['id'] }}">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $resource['name'] }}</h3>
                                    <span class="text-xs text-gray-500">{{ $resource['status_label'] }}</span>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">ID {{ $resource['external_id'] }} @if($resource['business']) · {{ $resource['business'] }} @endif @if($resource['currency']) · {{ $resource['currency'] }} @endif @if($resource['timezone']) · {{ $resource['timezone'] }} @endif</p>
                                <div class="mt-3 max-w-3xl">
                                    <div class="mb-1 flex items-center justify-between text-xs text-gray-500"><span>{{ $resource['datasets_finished'] }}/{{ $resource['datasets_total'] }} veri seti</span><span class="font-medium text-gray-700 dark:text-gray-300">{{ number_format($resource['progress_percent'], 1) }}%</span></div>
                                    <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10"><div class="h-full rounded-full bg-brand-500 transition-all duration-500" style="width: {{ max(0, min(100, $resource['progress_percent'])) }}%"></div></div>
                                </div>
                                <p class="mt-3 text-xs text-gray-500"><span class="font-medium text-gray-700 dark:text-gray-300">{{ $resource['current_dataset'] ?? 'Bekliyor' }}</span>@if($resource['current_range']) · {{ $resource['current_range'] }} @endif · {{ number_format($resource['rows_written'], 0, ',', '.') }} satır</p>

                                @if (! empty($resource['errors']))
                                    <details class="mt-3">
                                        <summary class="cursor-pointer select-none text-xs font-semibold text-warning-700 dark:text-warning-300">{{ count($resource['errors']) }} hata ayrıntısı</summary>
                                        <div class="mt-2 space-y-2">
                                            @foreach ($resource['errors'] as $error)
                                                <div class="rounded-lg bg-warning-50 p-3 text-xs text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
                                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                                        <div class="min-w-0">
                                                            <div class="flex flex-wrap items-center gap-2">
                                                                <p class="font-semibold">{{ $error['label'] }}</p>
                                                                @if (($error['attempts'] ?? 0) > 1)
                                                                    <span class="rounded-full bg-error-50 px-2 py-0.5 text-[10px] font-bold text-error-700 ring-1 ring-inset ring-error-200 dark:bg-error-500/10 dark:text-error-300 dark:ring-error-500/20">Tekrar hata verdi</span>
                                                                @endif
                                                            </div>
                                                            <p class="mt-1 break-words">{{ $error['message'] }}</p>
                                                            <p class="mt-1 text-[11px] opacity-70">{{ $error['code'] ?? '' }} @if($error['category']) · {{ $error['category'] }} @endif · {{ $error['attempts'] }} deneme</p>
                                                        </div>
                                                        <button type="button" wire:click="retryDataset({{ $error['id'] }})" wire:loading.attr="disabled" class="shrink-0 rounded-lg bg-white px-2.5 py-1.5 text-xs font-semibold text-warning-800 ring-1 ring-inset ring-warning-200 dark:bg-gray-900 dark:text-warning-300 dark:ring-warning-700">Yeniden dene</button>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                            </div>
                            @if ($resource['can_stop'])
                                <button type="button" wire:click="stopResource({{ $resource['id'] }})" wire:loading.attr="disabled" class="shrink-0 text-xs font-medium text-error-600 hover:underline dark:text-error-400">Bu hesabı durdur</button>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @empty
        <section class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-10 text-center dark:border-gray-700 dark:bg-gray-900">
            <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">↻</span>
            <p class="mt-3 text-sm font-semibold text-gray-900 dark:text-white">Şu anda aktif Meta Ads veri toplama yok</p>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">“Şimdi Güncelle” veya “İlk Toplamayı Başlat” dediğinizde canlı ilerleme burada görünür.</p>
        </section>
    @endforelse

    @if (! empty($issues))
        <section class="overflow-hidden rounded-2xl bg-white shadow-theme-xs ring-1 ring-inset ring-warning-200 dark:bg-gray-900 dark:ring-warning-800/60">
            <div class="border-b border-warning-100 px-5 py-4 dark:border-warning-900/40 md:px-6">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Tamamlanamayan Meta Ads verileri</h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Son toplamalarda hata kalan veri setlerini buradan yeniden deneyebilirsiniz.</p>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($issues as $issue)
                    <article class="px-5 py-4 md:px-6">
                        <div class="flex flex-wrap items-center gap-2"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $issue['name'] }}</h3><span class="rounded-full bg-warning-50 px-2 py-0.5 text-[11px] font-semibold text-warning-700 dark:bg-warning-500/10 dark:text-warning-300">{{ $issue['failed_count'] }} hata</span></div>
                        <p class="mt-1 text-xs text-gray-500">{{ $issue['business'] ?? 'Meta Ads' }} · {{ $issue['last_activity'] }}</p>
                        <div class="mt-3 space-y-2">
                            @foreach ($issue['errors'] as $error)
                                <div class="flex flex-col gap-2 rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03] sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="text-xs font-semibold text-gray-800 dark:text-gray-200">{{ $error['label'] }}</p>
                                            @if (($error['attempts'] ?? 0) > 1)
                                                <span class="rounded-full bg-error-50 px-2 py-0.5 text-[10px] font-bold text-error-700 ring-1 ring-inset ring-error-200 dark:bg-error-500/10 dark:text-error-300 dark:ring-error-500/20">Tekrar hata verdi</span>
                                            @endif
                                        </div>
                                        <p class="mt-1 break-words text-xs text-gray-500">{{ $error['message'] }}</p>
                                        <p class="mt-1 text-[11px] text-gray-400">{{ $error['attempts'] }} deneme</p>
                                    </div>
                                    <button type="button" wire:click="retryDataset({{ $error['id'] }})" wire:loading.attr="disabled" class="shrink-0 rounded-lg bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:text-brand-400 dark:ring-gray-700">Yeniden dene</button>
                                </div>
                            @endforeach
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif
</div>
