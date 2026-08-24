<div wire:poll.2s.visible class="space-y-3">
    @if ($actionMessage)
        <div class="rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-700 ring-1 ring-inset ring-blue-100 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20">
            {{ $actionMessage }}
        </div>
    @endif

    @foreach ($runs as $run)
        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" wire:key="gsc-live-run-{{ $run['id'] }}">
            <div class="px-5 py-5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span @class([
                                'h-2.5 w-2.5 rounded-full',
                                'bg-blue-500 animate-pulse' => in_array($run['status'], ['queued', 'running', 'retrying']),
                                'bg-amber-500' => $run['status'] === 'cancellation_requested',
                            ])></span>
                            <h2 class="text-base font-semibold text-gray-900 dark:text-white">
                                {{ $run['status'] === 'cancellation_requested' ? 'Search Console aktarımı durduruluyor' : 'Search Console verileri çekiliyor' }}
                            </h2>
                            <span class="rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-blue-700 dark:bg-blue-500/10 dark:text-blue-300">{{ $run['status_label'] }}</span>
                        </div>
                        <p class="mt-1 pl-[18px] text-sm text-gray-500 dark:text-gray-400">{{ $run['label'] }}</p>
                        @if (! empty($run['coverage_start']) && ! empty($run['coverage_end']))
                            <p class="mt-1 pl-[18px] text-xs text-gray-400">Veri kapsamı · {{ $run['coverage_start'] }} → {{ $run['coverage_end'] }}</p>
                        @endif
                    </div>

                    @if ($run['can_stop'])
                        <button type="button" wire:click="stopRun({{ $run['id'] }})" wire:loading.attr="disabled" wire:target="stopRun({{ $run['id'] }})" class="shrink-0 rounded-lg px-3 py-2 text-sm font-medium text-rose-600 ring-1 ring-inset ring-gray-200 hover:bg-rose-50 disabled:opacity-50 dark:text-rose-300 dark:ring-gray-700 dark:hover:bg-rose-500/10">
                            Aktarımı durdur
                        </button>
                    @endif
                </div>

                <div class="mt-5">
                    <div class="mb-2 flex items-end justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Toplam aktarım</p>
                            <p class="mt-0.5 text-xs text-gray-400">{{ $run['datasets_finished'] }} / {{ $run['datasets_total'] }} veri grubu tamamlandı</p>
                        </div>
                        <p class="text-2xl font-semibold tabular-nums tracking-tight text-gray-900 dark:text-white">{{ number_format($run['progress_percent'], 1) }}%</p>
                    </div>
                    <div class="h-2.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                        <div class="h-full rounded-full bg-brand-500 transition-all duration-500" style="width: {{ max(0, min(100, $run['progress_percent'])) }}%"></div>
                    </div>
                </div>

                <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <div class="rounded-lg bg-gray-50 px-3.5 py-3 dark:bg-white/[0.03]">
                        <p class="text-[11px] font-medium uppercase tracking-wide text-gray-400">Kaydedilen veri</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($run['rows_written'], 0, ',', '.') }}</p>
                        <p class="text-xs text-gray-400">satır</p>
                    </div>
                    <div class="rounded-lg bg-gray-50 px-3.5 py-3 dark:bg-white/[0.03]">
                        <p class="text-[11px] font-medium uppercase tracking-wide text-gray-400">Google API</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($run['pages_completed'], 0, ',', '.') }}</p>
                        <p class="text-xs text-gray-400">sayfa işlendi</p>
                    </div>
                    <div class="rounded-lg bg-gray-50 px-3.5 py-3 dark:bg-white/[0.03]">
                        <p class="text-[11px] font-medium uppercase tracking-wide text-gray-400">Siteler</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-white">{{ $run['sites_finished'] }} / {{ $run['sites_total'] }}</p>
                        <p class="text-xs text-gray-400">tamamlandı</p>
                    </div>
                    <div class="rounded-lg bg-gray-50 px-3.5 py-3 dark:bg-white/[0.03]">
                        <p class="text-[11px] font-medium uppercase tracking-wide text-gray-400">Son hareket</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $run['last_activity'] }}</p>
                        <p class="text-xs text-gray-400">arka planda çalışıyor</p>
                    </div>
                </div>

                @if ($run['datasets_failed'] > 0)
                    <div class="mt-4 rounded-lg bg-amber-50 px-3.5 py-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                        <p class="font-medium">{{ $run['datasets_failed'] }} veri grubunda sorun var.</p>
                        <p class="mt-0.5 text-xs opacity-80">Diğer Search Console verileri çekilmeye devam ediyor. Hatalı gruplar daha sonra ayrı olarak tamamlanabilir.</p>
                    </div>
                @endif
            </div>

            <div class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-800 dark:border-gray-800">
                @foreach ($run['resources'] as $resource)
                    <article class="px-5 py-5" wire:key="gsc-live-resource-{{ $resource['id'] }}">
                        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $resource['name'] }}</h3>
                                    <span class="text-xs text-gray-500">{{ $resource['status_label'] }}</span>
                                </div>
                                <p class="mt-1 truncate text-xs text-gray-400">{{ $resource['site_url'] }}</p>

                                <div class="mt-4 max-w-4xl">
                                    <div class="mb-1.5 flex items-center justify-between gap-3 text-xs">
                                        <span class="text-gray-500">Site aktarımı · {{ $resource['datasets_finished'] }} / {{ $resource['datasets_total'] }} veri grubu</span>
                                        <span class="font-semibold tabular-nums text-gray-700 dark:text-gray-300">{{ number_format($resource['progress_percent'], 1) }}%</span>
                                    </div>
                                    <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                                        <div class="h-full rounded-full bg-brand-500 transition-all duration-500" style="width: {{ max(0, min(100, $resource['progress_percent'])) }}%"></div>
                                    </div>
                                </div>

                                @if (! empty($resource['current_dataset']))
                                    <div class="mt-4 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                                        <span class="inline-flex h-1.5 w-1.5 rounded-full bg-blue-500 animate-pulse"></span>
                                        <span class="font-medium text-gray-700 dark:text-gray-300">Şu anda: {{ $resource['current_dataset'] }}</span>
                                        @if (! empty($resource['current_search_type']))<span class="text-gray-300 dark:text-gray-700">·</span><span class="text-gray-500">{{ $resource['current_search_type'] }}</span>@endif
                                        @if (! empty($resource['current_range']))<span class="text-gray-300 dark:text-gray-700">·</span><span class="text-gray-500">{{ $resource['current_range'] }}</span>@endif
                                    </div>
                                @endif

                                @if (! empty($resource['surfaces']))
                                    <div class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                        @foreach ($resource['surfaces'] as $surface)
                                            <div class="rounded-lg bg-gray-50 px-3 py-3 ring-1 ring-inset ring-gray-100 dark:bg-white/[0.03] dark:ring-white/5">
                                                <div class="flex items-center justify-between gap-2">
                                                    <p class="text-xs font-semibold text-gray-700 dark:text-gray-300">{{ $surface['label'] }}</p>
                                                    <span @class([
                                                        'text-[11px] font-medium tabular-nums',
                                                        'text-amber-600 dark:text-amber-300' => $surface['failed'] > 0,
                                                        'text-gray-500' => $surface['failed'] === 0,
                                                    ])>{{ number_format($surface['progress_percent'], 0) }}%</span>
                                                </div>
                                                <div class="mt-2 h-1 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                                                    <div @class(['h-full rounded-full transition-all duration-500','bg-amber-500' => $surface['failed'] > 0,'bg-brand-500' => $surface['failed'] === 0]) style="width: {{ max(0, min(100, $surface['progress_percent'])) }}%"></div>
                                                </div>
                                                <div class="mt-2 flex items-center justify-between gap-2 text-[11px] text-gray-400">
                                                    <span>{{ $surface['finished'] }} / {{ $surface['total'] }} tamamlandı</span>
                                                    @if ($surface['failed'] > 0)<span class="font-medium text-amber-600 dark:text-amber-300">{{ $surface['failed'] }} hata</span>@elseif (! empty($surface['current']))<span class="truncate">{{ $surface['current'] }}</span>@endif
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                @if (! empty($resource['errors']))
                                    <details class="mt-3">
                                        <summary class="cursor-pointer select-none text-xs font-medium text-amber-700 dark:text-amber-300">{{ count($resource['errors']) }} hata ayrıntısını göster</summary>
                                        <div class="mt-2 space-y-2">
                                            @foreach ($resource['errors'] as $error)
                                                <div class="rounded-lg bg-amber-50 px-3 py-2.5 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                                                    <p class="font-semibold">{{ $error['label'] }}@if (! empty($error['search_type'])) · {{ $error['search_type'] }}@endif</p>
                                                    <p class="mt-1 break-words">{{ $error['message'] }}</p>
                                                    @if (! empty($error['code']) || ! empty($error['category']))
                                                        <p class="mt-1 text-[11px] opacity-70">@if (! empty($error['code'])){{ $error['code'] }}@endif @if (! empty($error['code']) && ! empty($error['category']))·@endif @if (! empty($error['category'])){{ $error['category'] }}@endif</p>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                            </div>

                            <div class="flex shrink-0 items-center gap-3">
                                <span class="text-xs text-gray-400">{{ number_format($resource['rows_written'], 0, ',', '.') }} satır</span>
                                @if ($resource['can_stop'])
                                    <button type="button" wire:click="stopResource({{ $resource['id'] }})" wire:loading.attr="disabled" wire:target="stopResource({{ $resource['id'] }})" class="text-xs font-medium text-rose-600 hover:underline disabled:opacity-50 dark:text-rose-300">Bu siteyi durdur</button>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            <details class="border-t border-gray-100 px-5 py-3 dark:border-gray-800">
                <summary class="cursor-pointer select-none text-xs font-medium text-gray-500 hover:text-gray-800 dark:hover:text-gray-300">Teknik ayrıntılar</summary>
                <dl class="mt-3 grid gap-x-6 gap-y-2 text-xs sm:grid-cols-2 lg:grid-cols-4">
                    <div><dt class="text-gray-400">Run ID</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">#{{ $run['id'] }}</dd></div>
                    <div><dt class="text-gray-400">Tamamlanan veri grubu</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">{{ $run['datasets_completed'] }}</dd></div>
                    <div><dt class="text-gray-400">Hata</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">{{ $run['datasets_failed'] }}</dd></div>
                    <div><dt class="text-gray-400">Durdurulan</dt><dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">{{ $run['datasets_cancelled'] }}</dd></div>
                </dl>
            </details>
        </section>
    @endforeach

    @if (! empty($issues))
        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-amber-200 dark:bg-gray-900 dark:ring-amber-800/60">
            <div class="border-b border-amber-100 px-5 py-4 dark:border-amber-900/40">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Tamamlanamayan Search Console verileri</h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Yalnızca sorun kalan veri gruplarını yeniden çalıştırabilirsiniz.</p>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($issues as $issue)
                    <article class="px-5 py-4" wire:key="gsc-issue-{{ $issue['resource_run_id'] }}">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $issue['name'] }}</h3><span class="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">{{ $issue['failed_count'] }} hata</span></div>
                                <p class="mt-1 text-xs text-gray-400">{{ $issue['site_url'] }} · {{ $issue['last_activity'] }}</p>
                                <details class="mt-3">
                                    <summary class="cursor-pointer select-none text-xs font-semibold text-gray-700 dark:text-gray-300">Hata ayrıntıları</summary>
                                    <div class="mt-2 space-y-2">
                                        @foreach ($issue['errors'] as $error)
                                            <div class="rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-white/[0.03]"><p class="text-xs font-semibold text-gray-800 dark:text-gray-200">{{ $error['label'] }}@if (! empty($error['search_type'])) · {{ $error['search_type'] }}@endif</p><p class="mt-1 break-words text-xs text-gray-600 dark:text-gray-400">{{ $error['message'] }}</p></div>
                                        @endforeach
                                    </div>
                                </details>
                            </div>
                            <button type="button" wire:click="repairResource({{ $issue['external_resource_id'] }})" wire:loading.attr="disabled" wire:target="repairResource({{ $issue['external_resource_id'] }})" class="shrink-0 rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800 hover:bg-amber-100 disabled:opacity-50 dark:bg-amber-500/10 dark:text-amber-300 dark:hover:bg-amber-500/20">Eksikleri tamamla</button>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif
</div>
