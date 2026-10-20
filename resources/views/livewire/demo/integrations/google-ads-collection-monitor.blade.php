<div wire:poll.15s.visible class="space-y-4">
    @if ($actionMessage)
        <div class="card px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
            {{ $actionMessage }}
        </div>
    @endif

    @forelse ($runs as $run)
        <section class="card" wire:key="gads-live-run-{{ $run['id'] }}">
            <div class="p-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span @class([
                                'h-2.5 w-2.5 rounded-full',
                                'bg-warning-500' => $run['quota_waiting'] || $run['status'] === 'cancellation_requested',
                                'animate-pulse bg-gray-500' => ! $run['quota_waiting'] && in_array($run['status'], ['queued', 'running', 'retrying']),
                            ])></span>
                            <h2 class="section-title">
                                @if ($run['status'] === 'cancellation_requested')
                                    Google Ads aktarımı durduruluyor
                                @elseif ($run['quota_waiting'])
                                    Google Ads kotası bekleniyor
                                @else
                                    Google Ads · {{ $run['status_label'] }}
                                @endif
                            </h2>
                            <span class="text-xs font-medium {{ $run['quota_waiting'] ? 'text-warning-700 dark:text-warning-300' : 'text-gray-500' }}">{{ $run['status_label'] }}</span>
                        </div>
                        <p class="mt-1 pl-[18px] text-sm text-gray-500 dark:text-gray-400">{{ $run['label'] }}, çekim #{{ $run['id'] }}</p>
                    </div>

                    @if ($run['can_stop'])
                        <button type="button" wire:click="stopRun({{ $run['id'] }})" wire:loading.attr="disabled" wire:target="stopRun({{ $run['id'] }})" class="btn-quiet hover:text-error-600! dark:hover:text-error-400!">
                            Durdur
                        </button>
                    @endif
                </div>

                @if ($run['quota_waiting'])
                    <div class="mt-4 rounded-lg px-4 py-3 text-sm tone-look">
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
                    <div class="mt-4 rounded-lg px-3.5 py-3 text-sm tone-look">
                        {{ $run['datasets_failed'] }} veri grubunda sorun var. Sağlıklı datasetler çekilmeye devam ediyor; işlem bittiğinde yalnızca eksikleri yeniden deneyebilirsiniz.
                    </div>
                @endif
            </div>

            <div class="divide-y divide-gray-200 border-t border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                @foreach ($run['resources'] as $resource)
                    <article class="px-4 py-4" wire:key="gads-live-resource-{{ $resource['id'] }}">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $resource['name'] }}</h3>
                                    <span class="text-xs {{ $resource['status'] === 'retrying' ? 'text-warning-700 dark:text-warning-300' : 'text-gray-500' }}">{{ $resource['status_label'] }}</span>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">Müşteri kimliği {{ $resource['customer_id'] }}</p>

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
                                        <summary class="cursor-pointer select-none text-xs font-semibold text-warning-700 dark:text-warning-300">{{ count($resource['errors']) }} hata ayrıntısı</summary>
                                        <div class="mt-2 space-y-2">
                                            @foreach ($resource['errors'] as $error)
                                                <div class="rounded-lg bg-warning-50 px-3 py-2.5 text-xs text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
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
                                <button type="button" wire:click="stopResource({{ $resource['id'] }})" wire:loading.attr="disabled" wire:target="stopResource({{ $resource['id'] }})" class="shrink-0 text-xs font-medium text-error-600 hover:underline disabled:opacity-50 dark:text-error-300">
                                    Bu hesabı durdur
                                </button>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>

            <details class="border-t border-gray-200 px-4 py-3 dark:border-gray-800">
                <summary class="cursor-pointer select-none text-xs font-medium text-gray-500 hover:text-gray-800 dark:hover:text-gray-300">Teknik ayrıntılar</summary>
                <dl class="mt-3 grid gap-x-6 gap-y-2 text-xs sm:grid-cols-2 lg:grid-cols-4">
                    <div><dt class="text-gray-400">Çekim no</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">#{{ $run['id'] }}</dd></div>
                    <div><dt class="text-gray-400">API sayfası</dt><dd class="mt-0.5 font-medium tabular-nums text-gray-700 dark:text-gray-300">{{ number_format($run['pages_completed'], 0, ',', '.') }}</dd></div>
                    <div><dt class="text-gray-400">Tamamlanan</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">{{ $run['datasets_completed'] }}</dd></div>
                    <div><dt class="text-gray-400">Hata</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">{{ $run['datasets_failed'] }}</dd></div>
                    <div><dt class="text-gray-400">Durdurulan</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">{{ $run['datasets_cancelled'] }}</dd></div>
                    <div><dt class="text-gray-400">Alınan satır</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">{{ number_format($run['rows_received'], 0, ',', '.') }}</dd></div>
                    <div><dt class="text-gray-400">Kaydedilen satır</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">{{ number_format($run['rows_written'], 0, ',', '.') }}</dd></div>
                    <div><dt class="text-gray-400">Kapsam</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">Merkezi veri havuzu</dd></div>
                </dl>
            </details>
        </section>
    @empty
        <div class="card">
            <x-ta.empty-state size="sm" title="Aktif Google Ads veri aktarımı yok." message="Hesaplar sekmesinden bir veya daha fazla müşteri hesabı seçip veri çekebilirsin." />
        </div>
    @endforelse

    @if (! empty($issues))
        <details class="card">
            <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-warning-800 dark:text-warning-300">{{ count($issues) }} hesapta tamamlanamayan önceki çekim</summary>
        <section class="border-t border-gray-200 dark:border-gray-800">
            <div class="px-4 pt-4 pb-3">
                <h2 class="section-title">Tamamlanamayan Google Ads verileri</h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Yalnızca eksik kalan datasetleri yeniden deneyebilirsiniz; tamamlanan veri tekrar çekilmez.</p>
            </div>

            <div class="divide-y divide-gray-200 dark:divide-gray-800">
                @foreach ($issues as $issue)
                    <article class="px-4 py-4" wire:key="gads-issue-{{ $issue['id'] }}">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $issue['name'] }}</h3>
                                    <x-ta.pill tone="look">{{ $issue['failed_count'] }} hata</x-ta.pill>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">Müşteri kimliği {{ $issue['customer_id'] }} · {{ $issue['last_activity'] }}</p>
                                @if (! empty($issue['errors']))
                                    <div class="mt-3 space-y-2">
                                        @foreach ($issue['errors'] as $error)
                                            <div class="border-b border-gray-200 py-2 text-xs dark:border-gray-800">
                                                <p class="font-semibold text-gray-800 dark:text-gray-200">{{ $error['label'] }}</p>
                                                <p class="mt-1 break-words text-gray-600 dark:text-gray-400">{{ $error['message'] }}</p>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            <button type="button" wire:click="repairResource({{ $issue['external_resource_id'] }})" wire:loading.attr="disabled" wire:target="repairResource({{ $issue['external_resource_id'] }})" class="btn-primary">
                                Eksikleri tamamla
                            </button>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
        </details>
    @endif
</div>

