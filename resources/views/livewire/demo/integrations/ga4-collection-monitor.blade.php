<div wire:poll.2s.visible class="space-y-3">
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
                        <p class="mt-1 pl-[18px] text-sm text-gray-500 dark:text-gray-400">{{ $run['label'] }}</p>
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
                    @php
                        $liveErrors = collect($run['resources'])->flatMap(fn (array $resource) => collect($resource['errors'] ?? [])->map(fn (array $error) => [...$error, 'resource_name' => $resource['name']]));
                    @endphp
                    <div class="mt-4 rounded-lg bg-amber-50 px-3.5 py-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                        <div class="flex items-start gap-2">
                            <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 9v4"></path><path d="M12 17h.01"></path><path d="M10.3 3.7 2.5 17.2A2 2 0 0 0 4.2 20h15.6a2 2 0 0 0 1.7-2.8L13.7 3.7a2 2 0 0 0-3.4 0Z"></path></svg>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium">{{ $run['datasets_failed'] }} veri grubunda sorun var.</p>
                                <p class="mt-0.5 text-xs opacity-80">Diğer veri grupları çekilmeye devam ediyor.</p>
                                @if ($liveErrors->isNotEmpty())
                                    <details class="mt-2">
                                        <summary class="cursor-pointer select-none text-xs font-semibold">Hataları görüntüle</summary>
                                        <div class="mt-2 space-y-2">
                                            @foreach ($liveErrors as $error)
                                                <div class="rounded-md bg-white/70 px-3 py-2 dark:bg-black/10">
                                                    <p class="text-xs font-semibold">{{ $error['resource_name'] }} · {{ $error['label'] }}</p>
                                                    <p class="mt-1 break-words text-xs opacity-90">{{ $error['message'] }}</p>
                                                    @if (! empty($error['code']) || ! empty($error['category']))
                                                        <p class="mt-1 text-[11px] opacity-70">
                                                            @if (! empty($error['code'])){{ $error['code'] }}@endif
                                                            @if (! empty($error['code']) && ! empty($error['category'])) · @endif
                                                            @if (! empty($error['category'])){{ $error['category'] }}@endif
                                                        </p>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                            </div>
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

                                @if (! empty($resource['errors']))
                                    <details class="mt-2">
                                        <summary class="cursor-pointer select-none text-xs font-medium text-amber-700 dark:text-amber-300">{{ count($resource['errors']) }} hata ayrıntısı</summary>
                                        <div class="mt-2 space-y-2">
                                            @foreach ($resource['errors'] as $error)
                                                <div class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                                                    <p class="font-semibold">{{ $error['label'] }}</p>
                                                    <p class="mt-1 break-words">{{ $error['message'] }}</p>
                                                    @if (! empty($error['code']) || ! empty($error['category']))
                                                        <p class="mt-1 text-[11px] opacity-70">
                                                            @if (! empty($error['code'])){{ $error['code'] }}@endif
                                                            @if (! empty($error['code']) && ! empty($error['category'])) · @endif
                                                            @if (! empty($error['category'])){{ $error['category'] }}@endif
                                                        </p>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
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

    @if (! empty($issues))
        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-amber-200 dark:bg-gray-900 dark:ring-amber-800/60">
            <div class="border-b border-amber-100 px-5 py-4 dark:border-amber-900/40">
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">!</span>
                    <div>
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Tamamlanamayan GA4 verileri</h2>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Son aktarımda hata kalan veri gruplarını inceleyebilir ve yalnızca eksikleri yeniden deneyebilirsiniz.</p>
                    </div>
                </div>
            </div>

            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($issues as $issue)
                    <article class="px-5 py-4" wire:key="ga4-issue-{{ $issue['resource_run_id'] }}">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $issue['name'] }}</h3>
                                    <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">{{ $issue['failed_count'] }} hata</span>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">{{ $issue['account_name'] }} · Property {{ $issue['property_id'] }} · {{ $issue['last_activity'] }}</p>

                                <details class="mt-3" open>
                                    <summary class="cursor-pointer select-none text-xs font-semibold text-gray-700 dark:text-gray-300">Hata ayrıntıları</summary>
                                    <div class="mt-2 space-y-2">
                                        @foreach ($issue['errors'] as $error)
                                            <div class="rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-white/[0.03]">
                                                <div class="flex flex-wrap items-start justify-between gap-2">
                                                    <p class="text-xs font-semibold text-gray-800 dark:text-gray-200">{{ $error['label'] }}</p>
                                                    <span class="text-[11px] text-gray-400">{{ $error['attempts'] }} deneme</span>
                                                </div>
                                                <p class="mt-1 break-words text-xs text-gray-600 dark:text-gray-400">{{ $error['message'] }}</p>
                                                @if (! empty($error['code']) || ! empty($error['category']))
                                                    <p class="mt-1.5 text-[11px] text-gray-400">
                                                        @if (! empty($error['code']))Kod: {{ $error['code'] }}@endif
                                                        @if (! empty($error['code']) && ! empty($error['category'])) · @endif
                                                        @if (! empty($error['category']))Tür: {{ $error['category'] }}@endif
                                                    </p>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </details>
                            </div>

                            <button
                                type="button"
                                wire:click="repairResource({{ $issue['external_resource_id'] }})"
                                wire:loading.attr="disabled"
                                wire:target="repairResource({{ $issue['external_resource_id'] }})"
                                class="shrink-0 rounded-lg bg-brand-500 px-3 py-2 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50"
                            >
                                <span wire:loading.remove wire:target="repairResource({{ $issue['external_resource_id'] }})">Eksikleri tamamla</span>
                                <span wire:loading wire:target="repairResource({{ $issue['external_resource_id'] }})">Başlatılıyor…</span>
                            </button>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif
</div>
