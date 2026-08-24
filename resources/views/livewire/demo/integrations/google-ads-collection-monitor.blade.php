<div wire:poll.2s.visible class="space-y-4">
    @if ($actionMessage)
        <div class="rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-700 ring-1 ring-inset ring-blue-100 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20">
            {{ $actionMessage }}
        </div>
    @endif

    @forelse ($runs as $run)
        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset {{ $run['quota_waiting'] ? 'ring-amber-200 dark:ring-amber-500/30' : 'ring-gray-200 dark:ring-gray-800' }} dark:bg-gray-900" wire:key="gads-live-run-{{ $run['id'] }}">
            <div class="px-5 py-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span @class([
                                'h-2.5 w-2.5 rounded-full',
                                'bg-amber-500' => $run['quota_waiting'] || $run['status'] === 'cancellation_requested',
                                'animate-pulse bg-blue-500' => ! $run['quota_waiting'] && in_array($run['status'], ['queued', 'running', 'retrying']),
                            ])></span>
                            <h2 class="text-base font-semibold text-gray-900 dark:text-white">
                                @if ($run['status'] === 'cancellation_requested')
                                    Google Ads aktarımı durduruluyor
                                @elseif ($run['quota_waiting'])
                                    Google Ads kotası bekleniyor
                                @else
                                    Google Ads verileri çekiliyor
                                @endif
                            </h2>
                            <span class="text-xs font-medium {{ $run['quota_waiting'] ? 'text-amber-700 dark:text-amber-300' : 'text-gray-500' }}">{{ $run['status_label'] }}</span>
                        </div>
                        <p class="mt-1 pl-[18px] text-sm text-gray-500 dark:text-gray-400">{{ $run['label'] }} · Run #{{ $run['id'] }}</p>
                    </div>

                    @if ($run['can_stop'])
                        <button type="button" wire:click="stopRun({{ $run['id'] }})" wire:loading.attr="disabled" wire:target="stopRun({{ $run['id'] }})" class="rounded-lg px-3 py-2 text-sm font-medium text-rose-600 ring-1 ring-inset ring-gray-200 hover:bg-rose-50 disabled:opacity-50 dark:text-rose-300 dark:ring-gray-700 dark:hover:bg-rose-500/10">
                            Durdur
                        </button>
                    @endif
                </div>

                @if ($run['quota_waiting'])
                    <div class="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-inset ring-amber-100 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20">
                        <p class="font-semibold">Google Ads developer-token kotası şu anda bekleme süresinde.</p>
                        <p class="mt-1 text-xs leading-5">
                            Sistem yeni API çağrısı göndermiyor ve mevcut veriyi kaybetmiyor.
                            @if ($run['quota_retry_human']) Otomatik yeniden deneme {{ $run['quota_retry_human'] }}.@endif
                            @if ($run['quota_retry_at']) <span class="font-mono">{{ $run['quota_retry_at'] }}</span>@endif
                        </p>
                    </div>
                @endif

                <div class="mt-4">
                    <div class="mb-1.5 flex items-center justify-between gap-3 text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-300">Toplam ilerleme</span>
                        <span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($run['progress_percent'], 1) }}%</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                        <div class="h-full rounded-full bg-brand-500 transition-all duration-500" style="width: {{ max(0, min(100, $run['progress_percent'])) }}%"></div>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                        <span>{{ $run['datasets_finished'] }} / {{ $run['datasets_total'] }} veri grubu</span>
                        <span>{{ $run['accounts_finished'] }} / {{ $run['accounts_total'] }} hesap</span>
                        <span>{{ number_format($run['rows_written'], 0, ',', '.') }} satır kaydedildi</span>
                        <span>Son hareket {{ $run['last_activity'] }}</span>
                    </div>
                </div>

                @if ($run['datasets_failed'] > 0)
                    <div class="mt-4 rounded-lg bg-amber-50 px-3.5 py-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                        {{ $run['datasets_failed'] }} veri grubunda sorun var. Sağlıklı datasetler çekilmeye devam ediyor; işlem bittiğinde yalnızca eksikleri yeniden deneyebilirsiniz.
                    </div>
                @endif
            </div>

            <div class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-800 dark:border-gray-800">
                @foreach ($run['resources'] as $resource)
                    <article class="px-5 py-4" wire:key="gads-live-resource-{{ $resource['id'] }}">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $resource['name'] }}</h3>
                                    <span class="text-xs {{ $resource['status'] === 'retrying' ? 'text-amber-700 dark:text-amber-300' : 'text-gray-500' }}">{{ $resource['status_label'] }}</span>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">Customer ID {{ $resource['customer_id'] }}</p>

                                <div class="mt-3 max-w-3xl">
                                    <div class="mb-1 flex items-center justify-between gap-3 text-xs text-gray-500">
                                        <span>{{ $resource['datasets_finished'] }} / {{ $resource['datasets_total'] }} veri grubu</span>
                                        <span class="font-medium tabular-nums text-gray-700 dark:text-gray-300">{{ number_format($resource['progress_percent'], 1) }}%</span>
                                    </div>
                                    <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                                        <div class="h-full rounded-full bg-brand-500 transition-all duration-500" style="width: {{ max(0, min(100, $resource['progress_percent'])) }}%"></div>
                                    </div>
                                </div>

                                <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                    <span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $resource['current_dataset'] ?? 'Bekliyor' }}</span></span>
                                    @if (! empty($resource['current_range']))<span>{{ $resource['current_range'] }}</span>@endif
                                    <span>{{ number_format($resource['rows_written'], 0, ',', '.') }} satır</span>
                                    <span>{{ $resource['last_activity'] }}</span>
                                </div>

                                @if (! empty($resource['errors']))
                                    <details class="mt-3">
                                        <summary class="cursor-pointer select-none text-xs font-semibold text-amber-700 dark:text-amber-300">{{ count($resource['errors']) }} hata ayrıntısı</summary>
                                        <div class="mt-2 space-y-2">
                                            @foreach ($resource['errors'] as $error)
                                                <div class="rounded-lg bg-amber-50 px-3 py-2.5 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                                        <p class="font-semibold">{{ $error['label'] }}</p>
                                                        <span class="opacity-70">{{ $error['attempts'] }} deneme</span>
                                                    </div>
                                                    <p class="mt-1 break-words">{{ $error['message'] }}</p>
                                                    @if (! empty($error['code']) || ! empty($error['category']))
                                                        <p class="mt-1 opacity-70">
                                                            @if (! empty($error['code']))Kod: {{ $error['code'] }}@endif
                                                            @if (! empty($error['code']) && ! empty($error['category'])) · @endif
                                                            @if (! empty($error['category']))Tür: {{ $error['category'] }}@endif
                                                        </p>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                            </div>

                            @if ($resource['can_stop'])
                                <button type="button" wire:click="stopResource({{ $resource['id'] }})" wire:loading.attr="disabled" wire:target="stopResource({{ $resource['id'] }})" class="shrink-0 text-xs font-medium text-rose-600 hover:underline disabled:opacity-50 dark:text-rose-300">
                                    Bu hesabı durdur
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
                    <div><dt class="text-gray-400">Tamamlanan</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">{{ $run['datasets_completed'] }}</dd></div>
                    <div><dt class="text-gray-400">Hata</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">{{ $run['datasets_failed'] }}</dd></div>
                    <div><dt class="text-gray-400">Durdurulan</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">{{ $run['datasets_cancelled'] }}</dd></div>
                    <div><dt class="text-gray-400">Alınan satır</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">{{ number_format($run['rows_received'], 0, ',', '.') }}</dd></div>
                    <div><dt class="text-gray-400">Kaydedilen satır</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">{{ number_format($run['rows_written'], 0, ',', '.') }}</dd></div>
                    <div><dt class="text-gray-400">Kapsam</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">Central Data Pool</dd></div>
                </dl>
            </details>
        </section>
    @empty
        <div class="rounded-xl bg-white px-5 py-8 text-center ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Aktif Google Ads veri aktarımı yok.</p>
            <p class="mt-1 text-xs text-gray-500">Hesaplar sekmesinden bir veya daha fazla müşteri hesabı seçip veri çekebilirsiniz.</p>
        </div>
    @endforelse

    @if (! empty($issues))
        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-amber-200 dark:bg-gray-900 dark:ring-amber-800/60">
            <div class="border-b border-amber-100 px-5 py-4 dark:border-amber-900/40">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Tamamlanamayan Google Ads verileri</h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Yalnızca eksik kalan datasetleri yeniden deneyebilirsiniz; tamamlanan veri tekrar çekilmez.</p>
            </div>

            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($issues as $issue)
                    <article class="px-5 py-4" wire:key="gads-issue-{{ $issue['id'] }}">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $issue['name'] }}</h3>
                                    <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">{{ $issue['failed_count'] }} hata</span>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">Customer ID {{ $issue['customer_id'] }} · {{ $issue['last_activity'] }}</p>
                                @if (! empty($issue['errors']))
                                    <div class="mt-3 space-y-2">
                                        @foreach ($issue['errors'] as $error)
                                            <div class="rounded-lg bg-gray-50 px-3 py-2.5 text-xs dark:bg-white/[0.03]">
                                                <p class="font-semibold text-gray-800 dark:text-gray-200">{{ $error['label'] }}</p>
                                                <p class="mt-1 break-words text-gray-600 dark:text-gray-400">{{ $error['message'] }}</p>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            <button type="button" wire:click="repairResource({{ $issue['external_resource_id'] }})" wire:loading.attr="disabled" wire:target="repairResource({{ $issue['external_resource_id'] }})" class="shrink-0 rounded-lg bg-brand-500 px-3 py-2 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50">
                                Eksikleri tamamla
                            </button>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif
</div>
