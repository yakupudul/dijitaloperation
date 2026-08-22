<div @if($hasActive) wire:poll.2s.visible @endif class="space-y-3">
    @if ($actionMessage)
        <div class="rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-700 ring-1 ring-inset ring-blue-100 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20">
            {{ $actionMessage }}
        </div>
    @endif

    @foreach ($runs as $run)
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" wire:key="ga4-live-run-{{ $run['id'] }}">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Canlı GA4 Veri Toplama</h2>
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-white/5 dark:text-gray-300">Run #{{ $run['id'] }}</span>
                        <span @class([
                            'rounded-full px-2 py-0.5 text-xs font-semibold',
                            'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' => in_array($run['status'], ['queued', 'running', 'retrying']),
                            'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => $run['status'] === 'cancellation_requested',
                        ])>{{ $run['status_label'] }}</span>
                    </div>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $run['label'] }}</p>
                    <p class="mt-1 text-xs text-gray-400">Son hareket {{ $run['last_activity'] }}</p>
                </div>

                @if ($run['can_stop'])
                    <button
                        type="button"
                        wire:click="stopRun({{ $run['id'] }})"
                        wire:loading.attr="disabled"
                        wire:target="stopRun({{ $run['id'] }})"
                        class="rounded-lg px-3 py-2 text-sm font-semibold text-rose-600 ring-1 ring-inset ring-rose-200 hover:bg-rose-50 disabled:opacity-50 dark:text-rose-300 dark:ring-rose-800 dark:hover:bg-rose-500/10"
                    >
                        Tümünü Durdur
                    </button>
                @endif
            </div>

            <div class="mt-4">
                <div class="mb-1 flex items-center justify-between gap-3 text-xs">
                    <span class="font-medium text-gray-600 dark:text-gray-300">Genel ilerleme</span>
                    <span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($run['progress_percent'], 1) }}%</span>
                </div>
                <div class="h-2.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                    <div class="h-full rounded-full bg-brand-500 transition-all duration-500" style="width: {{ max(0, min(100, $run['progress_percent'])) }}%"></div>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                    <p class="text-[11px] text-gray-400">Property</p>
                    <p class="mt-1 text-sm font-semibold tabular-nums text-gray-900 dark:text-white">{{ $run['properties_finished'] }} / {{ $run['properties_total'] }}</p>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                    <p class="text-[11px] text-gray-400">Veri grubu işlendi</p>
                    <p class="mt-1 text-sm font-semibold tabular-nums text-gray-900 dark:text-white">{{ $run['datasets_finished'] }} / {{ $run['datasets_total'] }}</p>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                    <p class="text-[11px] text-gray-400">Data Pool'a yazılan</p>
                    <p class="mt-1 text-sm font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($run['rows_written'], 0, ',', '.') }} satır</p>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                    <p class="text-[11px] text-gray-400">API sayfası</p>
                    <p class="mt-1 text-sm font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($run['pages_completed'], 0, ',', '.') }}</p>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                    <p class="text-[11px] text-gray-400">Sonuçlar</p>
                    <p class="mt-1 text-xs font-medium text-gray-700 dark:text-gray-300">
                        {{ $run['datasets_completed'] }} tamam · {{ $run['datasets_failed'] }} hata · {{ $run['datasets_cancelled'] }} durdu
                    </p>
                </div>
            </div>

            <div class="mt-4 space-y-3">
                @foreach ($run['resources'] as $resource)
                    <article class="rounded-xl border border-gray-200 p-4 dark:border-gray-800" wire:key="ga4-live-resource-{{ $resource['id'] }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $resource['name'] }}</h3>
                                    <span class="text-xs font-medium text-gray-500">{{ $resource['status_label'] }}</span>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">{{ $resource['account_name'] }} · Property {{ $resource['property_id'] }}</p>
                            </div>

                            @if ($resource['can_stop'])
                                <button
                                    type="button"
                                    wire:click="stopResource({{ $resource['id'] }})"
                                    wire:loading.attr="disabled"
                                    wire:target="stopResource({{ $resource['id'] }})"
                                    class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-rose-600 ring-1 ring-inset ring-rose-200 hover:bg-rose-50 disabled:opacity-50 dark:text-rose-300 dark:ring-rose-800 dark:hover:bg-rose-500/10"
                                >
                                    Bu property'yi durdur
                                </button>
                            @endif
                        </div>

                        <div class="mt-3">
                            <div class="mb-1 flex items-center justify-between gap-3 text-[11px] text-gray-500">
                                <span>{{ $resource['datasets_finished'] }} / {{ $resource['datasets_total'] }} veri grubu işlendi</span>
                                <span class="font-semibold tabular-nums text-gray-700 dark:text-gray-300">{{ number_format($resource['progress_percent'], 1) }}%</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                                <div class="h-full rounded-full bg-brand-500 transition-all duration-500" style="width: {{ max(0, min(100, $resource['progress_percent'])) }}%"></div>
                            </div>
                        </div>

                        <div class="mt-3 grid gap-2 text-xs text-gray-500 sm:grid-cols-2 lg:grid-cols-4">
                            <div><span class="text-gray-400">Şu anda:</span> <strong class="font-medium text-gray-700 dark:text-gray-300">{{ $resource['current_dataset'] ?? 'Bekliyor' }}</strong></div>
                            <div><span class="text-gray-400">Tarih:</span> <strong class="font-medium text-gray-700 dark:text-gray-300">{{ $resource['current_range'] ?? '—' }}</strong></div>
                            <div><span class="text-gray-400">Yazılan:</span> <strong class="font-medium tabular-nums text-gray-700 dark:text-gray-300">{{ number_format($resource['rows_written'], 0, ',', '.') }}</strong></div>
                            <div><span class="text-gray-400">Son hareket:</span> <strong class="font-medium text-gray-700 dark:text-gray-300">{{ $resource['last_activity'] }}</strong></div>
                        </div>

                        @if (! empty($resource['current_stage']))
                            <p class="mt-2 text-[11px] text-gray-400">İşlem aşaması · {{ $resource['current_stage'] }}</p>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    @endforeach
</div>
