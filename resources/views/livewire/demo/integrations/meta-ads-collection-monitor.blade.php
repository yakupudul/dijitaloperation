<div wire:poll.10s.visible class="space-y-4">
    @if ($actionMessage)
        <div class="card px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
            {{ $actionMessage }}
        </div>
    @endif

    @forelse ($runs as $run)
        <section class="card" wire:key="meta-live-run-{{ $run['id'] }}">
            <div class="p-4">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="relative flex h-2.5 w-2.5">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-gray-400 opacity-60"></span>
                                <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-gray-500"></span>
                            </span>
                            <h2 class="section-title">Meta Ads verileri çekiliyor</h2>
                            <span class="text-xs font-medium text-gray-500">{{ $run['status_label'] }}</span>
                        </div>
                        <p class="mt-1 pl-[18px] text-sm text-gray-500 dark:text-gray-400">{{ $run['label'] }}, çekim #{{ $run['id'] }}</p>
                    </div>
                    @if ($run['can_stop'])
                        <button type="button" wire:click="stopRun({{ $run['id'] }})" wire:loading.attr="disabled" class="btn-quiet hover:text-error-600! dark:hover:text-error-400!">Durdur</button>
                    @endif
                </div>

                <div class="mt-5">
                    <div class="mb-2 flex items-center justify-between gap-3 text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-300">Toplam ilerleme</span>
                        <span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($run['progress_percent'], 1) }}%</span>
                    </div>
                    <div class="h-2.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                        <div class="h-full rounded-full bg-second transition-all duration-500" style="width: {{ max(0, min(100, $run['progress_percent'])) }}%"></div>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-4">
                        <div><p class="text-xs text-gray-500">Reklam hesabı</p><p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $run['accounts_finished'] }}/{{ $run['accounts_total'] }}</p></div>
                        <div><p class="text-xs text-gray-500">Veri seti</p><p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $run['datasets_finished'] }}/{{ $run['datasets_total'] }}</p></div>
                        <div><p class="text-xs text-gray-500">Kaydedilen satır</p><p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ number_format($run['rows_written'], 0, ',', '.') }}</p></div>
                        <div><p class="text-xs text-gray-500">Son hareket</p><p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $run['last_activity'] }}</p></div>
                    </div>
                </div>

                @if ($run['datasets_failed'] > 0 || $run['datasets_retrying'] > 0)
                    <div class="mt-4 rounded-lg p-4 text-sm tone-look">
                        <span class="font-semibold">{{ $run['datasets_failed'] }} hata</span>
                        @if ($run['datasets_retrying'] > 0)<span>, {{ $run['datasets_retrying'] }} yeniden deneniyor</span>@endif
                        <span class="block mt-1 text-xs opacity-80">Diğer veri setleri çekilmeye devam eder.</span>
                    </div>
                @endif
            </div>

            <div class="border-t border-gray-200 dark:border-gray-800">
                @foreach ($run['resources'] as $resource)
                    <article class="px-4 py-4" wire:key="meta-live-resource-{{ $resource['id'] }}">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $resource['name'] }}</h3>
                                    <span class="text-xs text-gray-500">{{ $resource['status_label'] }}</span>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">{{ implode(', ', array_filter(['ID '.$resource['external_id'], $resource['business'] ?? null, $resource['currency'] ?? null, $resource['timezone'] ?? null])) }}</p>
                                <div class="mt-3 max-w-3xl">
                                    <div class="mb-1 flex items-center justify-between text-xs text-gray-500"><span>{{ $resource['datasets_finished'] }}/{{ $resource['datasets_total'] }} veri seti</span><span class="font-medium text-gray-700 dark:text-gray-300">{{ number_format($resource['progress_percent'], 1) }}%</span></div>
                                    <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10"><div class="h-full rounded-full bg-second transition-all duration-500" style="width: {{ max(0, min(100, $resource['progress_percent'])) }}%"></div></div>
                                </div>
                                <p class="mt-3 text-xs text-gray-500"><span class="font-medium text-gray-700 dark:text-gray-300">{{ $resource['current_dataset'] ?? 'Bekliyor' }}</span>{{ $resource['current_range'] ? ', '.$resource['current_range'] : '' }}, {{ number_format($resource['rows_written'], 0, ',', '.') }} satır</p>

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
                                                                    <x-ta.pill tone="critical">Tekrar hata verdi</x-ta.pill>
                                                                @endif
                                                            </div>
                                                            <p class="mt-1 break-words">{{ $error['message'] }}</p>
                                                            <p class="mt-1 text-xs opacity-70">{{ implode(', ', array_filter([$error['code'] ?? null, $error['category'] ?? null, $error['attempts'].' deneme'])) }}</p>
                                                        </div>
                                                        <button type="button" wire:click="retryDataset({{ $error['id'] }})" wire:loading.attr="disabled" class="btn h-7! px-2.5! text-xs!">Yeniden dene</button>
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
        <div class="card">
            <x-ta.empty-state icon="none" size="sm" title="Şu anda aktif Meta Ads veri toplama yok" message="“Şimdi Güncelle” veya “İlk Toplamayı Başlat” dediğinde canlı ilerleme burada görünür." />
        </div>
    @endforelse

    @if (! empty($issues))
        <section class="card">
            <div class="px-4 pt-4 pb-3">
                <h2 class="section-title">Tamamlanamayan Meta Ads verileri</h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Son toplamalarda hata kalan veri setlerini buradan yeniden deneyebilirsiniz.</p>
            </div>
            <div class="divide-y divide-gray-200 dark:divide-gray-800">
                @foreach ($issues as $issue)
                    <article class="px-4 py-4">
                        <div class="flex flex-wrap items-center gap-2"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $issue['name'] }}</h3><x-ta.pill tone="look">{{ $issue['failed_count'] }} hata</x-ta.pill></div>
                        <p class="mt-1 text-xs text-gray-500">{{ $issue['business'] ?? 'Meta Ads' }}, {{ $issue['last_activity'] }}</p>
                        <div class="mt-3 space-y-2">
                            @foreach ($issue['errors'] as $error)
                                <div class="flex flex-col gap-2 border-b border-gray-200 py-2.5 dark:border-gray-800 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="text-xs font-semibold text-gray-800 dark:text-gray-200">{{ $error['label'] }}</p>
                                            @if (($error['attempts'] ?? 0) > 1)
                                                <x-ta.pill tone="critical">Tekrar hata verdi</x-ta.pill>
                                            @endif
                                        </div>
                                        <p class="mt-1 break-words text-xs text-gray-500">{{ $error['message'] }}</p>
                                        <p class="mt-1 text-xs text-gray-500">{{ $error['attempts'] }} deneme</p>
                                    </div>
                                    <button type="button" wire:click="retryDataset({{ $error['id'] }})" wire:loading.attr="disabled" class="btn h-7! px-2.5! text-xs!">Yeniden dene</button>
                                </div>
                            @endforeach
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif
</div>
