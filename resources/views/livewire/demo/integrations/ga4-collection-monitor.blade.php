<div @if($hasActive) wire:poll.2s.visible @endif class="space-y-3">
    @if ($actionMessage)
        <div class="rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-700 ring-1 ring-inset ring-blue-100 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20">
            {{ $actionMessage }}
        </div>
    @endif

    @foreach ($runs as $run)
        <section class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" wire:key="ga4-live-run-{{ $run['id'] }}">
            <div class="px-5 py-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span @class([
                                'h-2.5 w-2.5 rounded-full',
                                'bg-blue-500 animate-pulse' => in_array($run['status'], ['queued', 'running', 'retrying']),
                                'bg-amber-500' => $run['status'] === 'cancellation_requested',
                            ])></span>
                            <h2 class="text-base font-semibold text-gray-900 dark:text-white">
                                {{ $run['status'] === 'cancellation_requested' ? 'Veri aktarımı durduruluyor' : 'Veriler çekiliyor' }}
                            </h2>
                            <span class="text-xs font-medium text-gray-500">{{ $run['status_label'] }}</span>
                        </div>
                        <p class="mt-1 pl-[18px] text-sm text-gray-500 dark:text-gray-400">486 günlük GA4 geçmişi merkezi veri havuzuna aktarılıyor.</p>
                    </div>

                    @if ($run['can_stop'])
                        <button
                            type="button"
                            wire:click="stopRun({{ $run['id'] }})"
                            wire:loading.attr="disabled"
                            wire:target="stopRun({{ $run['id'] }})"
                            class="rounded-lg px-3 py-2 text-sm font-medium text-rose-600 ring-1 ring-inset ring-gray-200 hover:bg-rose-50 disabled:opacity-50 dark:text-rose-300 dark:ring-gray-700 dark:hover:bg-rose-500/10"
                        >
                            Durdur
                        </button>
                    @endif
                </div>

                <div class="mt-4">
                    <div class="mb-1.5 flex items-center justify-between gap-3 text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-300">Toplam ilerleme</span>
                        <span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($run['progress_percent'], 1) }}%</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                        <div class="h-full rounded-full bg-brand-500 transition-all duration-500" style="width: {{ max(0, min(100, $run['progress_percent'])) }}%"></div>
                    </div>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        {{ $run['datasets_finished'] }} / {{ $run['datasets_total'] }} veri grubu · {{ number_format($run['rows_written'], 0, ',', '.') }} satır kaydedildi
                    </p>
                </div>

                @if ($run['datasets_failed'] > 0)
                    <div class="mt-4 flex items-start gap-2 rounded-lg bg-amber-50 px-3.5 py-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                        <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 9v4"></path><path d="M12 17h.01"></path><path d="M10.3 3.7 2.5 17.2A2 2 0 0 0 4.2 20h15.6a2 2 0 0 0 1.7-2.8L13.7 3.7a2 2 0 0 0-3.4 0Z"></path></svg>
                        <div>
                            <p class="font-medium">{{ $run['datasets_failed'] }} veri grubunda sorun var.</p>
                            <p class="mt-0.5 text-xs opacity-80">Aktarım diğer veri gruplarıyla devam ediyor. Geçmiş ekranında tamamlanan sonucu kontrol edebilirsiniz.</p>
                        </div>
                    </div>
                @endif
            </div>

            <div class="border-t border-gray-100 dark:border-gray-800">
                @foreach ($run['resources'] as $resource)
                    <article class="px-5 py-4" wire:key="ga4-live-resource-{{ $resource['id'] }}">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $resource['name'] }}</h3>
                                    <span class="text-xs text-gray-500">{{ $resource['status_label'] }}</span>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">{{ $resource['account_name'] }} · Property {{ $resource['property_id'] }}</p>

                                <div class="mt-3 max-w-3xl">
                                    <div class="mb-1 flex items-center justify-between gap-3 text-xs text-gray-500">
                                        <span>{{ $resource['datasets_finished'] }} / {{ $resource['datasets_total'] }} veri grubu</span>
                                        <span class="font-medium tabular-nums text-gray-700 dark:text-gray-300">{{ number_format($resource['progress_percent'], 1) }}%</span>
                                    </div>
                                    <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                                        <div class="h-full rounded-full bg-brand-500 transition-all duration-500" style="width: {{ max(0, min(100, $resource['progress_percent'])) }}%"></div>
                                    </div>
                                </div>

                                <p class="mt-3 text-xs text-gray-500">
                                    <span class="font-medium text-gray-700 dark:text-gray-300">{{ $resource['current_dataset'] ?? 'Bekliyor' }}</span>
                                    @if (! empty($resource['current_range']))
                                        <span class="mx-1.5 text-gray-300 dark:text-gray-700">·</span>{{ $resource['current_range'] }}
                                    @endif
                                    <span class="mx-1.5 text-gray-300 dark:text-gray-700">·</span>{{ number_format($resource['rows_written'], 0, ',', '.') }} satır
                                </p>
                            </div>

                            @if ($resource['can_stop'])
                                <button
                                    type="button"
                                    wire:click="stopResource({{ $resource['id'] }})"
                                    wire:loading.attr="disabled"
                                    wire:target="stopResource({{ $resource['id'] }})"
                                    class="shrink-0 text-xs font-medium text-rose-600 hover:underline disabled:opacity-50 dark:text-rose-300"
                                >
                                    Bu mülkü durdur
                                </button>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>

            <details class="border-t border-gray-100 px-5 py-3 dark:border-gray-800">
                <summary class="cursor-pointer select-none text-xs font-medium text-gray-500 hover:text-gray-800 dark:hover:text-gray-300">Teknik ayrıntılar</summary>
                <dl class="mt-3 grid gap-x-6 gap-y-2 text-xs sm:grid-cols-2 lg:grid-cols-4">
                    <div><dt class="text-gray-400">Run ID</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">#{{ $run['id'] }}</dd></div>
                    <div><dt class="text-gray-400">API sayfası</dt><dd class="mt-0.5 font-medium tabular-nums text-gray-700 dark:text-gray-300">{{ number_format($run['pages_completed'], 0, ',', '.') }}</dd></div>
                    <div><dt class="text-gray-400">Mülk</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">{{ $run['properties_finished'] }} / {{ $run['properties_total'] }}</dd></div>
                    <div><dt class="text-gray-400">Son hareket</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">{{ $run['last_activity'] }}</dd></div>
                    <div><dt class="text-gray-400">Tamamlanan</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">{{ $run['datasets_completed'] }}</dd></div>
                    <div><dt class="text-gray-400">Hata</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">{{ $run['datasets_failed'] }}</dd></div>
                    <div><dt class="text-gray-400">Durdurulan</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">{{ $run['datasets_cancelled'] }}</dd></div>
                    <div><dt class="text-gray-400">Kapsam</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">Central Data Pool</dd></div>
                </dl>
            </details>
        </section>
    @endforeach
</div>