<div class="space-y-6" wire:poll.5s.visible>
    @include('livewire.demo.partials.flash')

    @php
        $summary = $snapshot['summary'];
        $infra = $snapshot['infrastructure'];
        $statusTone = static fn (string $value): string => match ($value) {
            'running' => 'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20',
            'queued' => 'bg-gray-50 text-gray-700 ring-gray-200 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10',
            'retrying' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20',
            'completed', 'validated' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20',
            'failed', 'blocked' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20',
            default => 'bg-slate-50 text-slate-600 ring-slate-200 dark:bg-white/5 dark:text-slate-300 dark:ring-white/10',
        };
    @endphp

    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('background_operations.title') }}</h1>
                @if (($summary['stalled_collection_runs'] ?? 0) > 0)
                    <span class="inline-flex rounded-full bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700 ring-1 ring-inset ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20">
                        {{ $summary['stalled_collection_runs'] }} {{ __('background_operations.labels.stalled') }}
                    </span>
                @endif
            </div>
            <p class="mt-1 max-w-4xl text-sm text-gray-500 dark:text-gray-400">{{ __('background_operations.subtitle') }}</p>
            <p class="mt-1 text-xs text-gray-400">{{ __('background_operations.refresh_note') }}</p>
        </div>
        <a href="{{ route('operator.settings') }}" wire:navigate class="inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
            {{ __('background_operations.back_to_settings') }}
        </a>
    </header>

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-7">
        @foreach ([
            [__('background_operations.summary.active'), $summary['active_collection_runs']],
            [__('background_operations.summary.stalled'), $summary['stalled_collection_runs']],
            [__('background_operations.summary.retrying'), $summary['retrying_datasets']],
            [__('background_operations.summary.locked'), $summary['locked_datasets']],
            [__('background_operations.summary.failed_jobs'), $failedJobTotal],
            [__('background_operations.summary.queue_depth'), $summary['queue_depth']],
            [__('background_operations.summary.agents'), $summary['running_agents']],
        ] as [$label, $value])
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-xs text-gray-400">{{ $label }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $value }}</p>
            </div>
        @endforeach
    </section>

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="grid gap-3 md:grid-cols-[180px_220px_minmax(0,1fr)]">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-500">{{ __('background_operations.filters.status') }}</label>
                <select wire:model.live="status" class="w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700">
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-500">{{ __('background_operations.filters.provider') }}</label>
                <select wire:model.live="provider" class="w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700">
                    <option value="all">{{ __('background_operations.filters.all') }}</option>
                    @foreach ($snapshot['providers'] as $providerOption)
                        <option value="{{ $providerOption }}">{{ $providerOption }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-500">{{ __('background_operations.filters.search') }}</label>
                <input wire:model.live.debounce.350ms="search" type="search" placeholder="{{ __('background_operations.filters.search') }}" class="w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700" />
            </div>
        </div>
    </section>

    <section class="space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('background_operations.sections.collection') }}</h2>
            <span class="text-xs text-gray-400">{{ count($snapshot['collection_runs']) }} run</span>
        </div>

        @forelse ($snapshot['collection_runs'] as $run)
            <article class="overflow-hidden rounded-xl bg-white ring-1 ring-inset {{ $run['stalled'] ? 'ring-rose-200 dark:ring-rose-500/30' : 'ring-gray-200 dark:ring-gray-800' }} dark:bg-gray-900" wire:key="background-run-{{ $run['id'] }}">
                <div class="p-4">
                    <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-xs text-gray-400">#{{ $run['id'] }}</span>
                                <h3 class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $run['title'] }}</h3>
                                <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $statusTone($run['status']) }}">{{ $run['status'] }}</span>
                                @if ($run['stalled'])
                                    <span class="inline-flex rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-bold text-rose-700 ring-1 ring-inset ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300">{{ __('background_operations.labels.stalled') }}</span>
                                @endif
                            </div>
                            <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-gray-500">
                                <span>{{ $run['scope'] }}</span>
                                <span>{{ $run['provider_label'] }}</span>
                                <span>{{ __('background_operations.columns.attempts') }}: {{ $run['progress']['attempts'] }}</span>
                                <span>{{ __('background_operations.columns.rows') }}: {{ number_format($run['progress']['rows']) }}</span>
                                <span>{{ __('background_operations.columns.last_activity') }}: {{ $run['last_activity_human'] ?? '—' }}</span>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2 text-xs">
                                <span class="rounded bg-gray-50 px-2 py-1 dark:bg-white/5">Q {{ $run['progress']['queued'] }}</span>
                                <span class="rounded bg-blue-50 px-2 py-1 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300">R {{ $run['progress']['running'] }}</span>
                                <span class="rounded bg-amber-50 px-2 py-1 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">Retry {{ $run['progress']['retrying'] }}</span>
                                <span class="rounded bg-emerald-50 px-2 py-1 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">Done {{ $run['progress']['completed'] }}</span>
                                <span class="rounded bg-rose-50 px-2 py-1 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">Fail {{ $run['progress']['failed'] }}</span>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="toggleRun({{ $run['id'] }})" class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">{{ __('background_operations.actions.details') }}</button>
                            @if ($isAdmin && $run['can_wake'])
                                <button type="button" wire:click="wakeRun({{ $run['id'] }})" class="rounded-lg px-3 py-2 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-200 dark:text-blue-300 dark:ring-blue-500/30">{{ __('background_operations.actions.wake') }}</button>
                            @endif
                            @if ($isAdmin && $run['can_retry_now'])
                                <button type="button" wire:click="retryNow({{ $run['id'] }})" class="rounded-lg px-3 py-2 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-200 dark:text-amber-300 dark:ring-amber-500/30">{{ __('background_operations.actions.retry_now') }}</button>
                            @endif
                            @if ($isAdmin && $run['can_release_stale_locks'])
                                <button type="button" wire:click="releaseStaleLocks({{ $run['id'] }})" wire:confirm="{{ __('background_operations.confirm.release_locks') }}" class="rounded-lg px-3 py-2 text-xs font-medium text-orange-700 ring-1 ring-inset ring-orange-200 dark:text-orange-300 dark:ring-orange-500/30">{{ __('background_operations.actions.release_locks') }} ({{ $run['stale_lock_count'] }})</button>
                            @endif
                            @if ($isAdmin && $run['can_cancel'])
                                <button type="button" wire:click="cancelRun({{ $run['id'] }})" wire:confirm="{{ __('background_operations.confirm.cancel') }}" class="rounded-lg px-3 py-2 text-xs font-medium text-rose-700 ring-1 ring-inset ring-rose-200 dark:text-rose-300 dark:ring-rose-500/30">{{ __('background_operations.actions.cancel') }}</button>
                            @endif
                        </div>
                    </div>
                </div>

                @if ($expandedRunId === $run['id'])
                    <div class="overflow-x-auto border-t border-gray-100 bg-gray-50/60 p-4 dark:border-gray-800 dark:bg-white/[0.02]">
                        <table class="min-w-[1050px] w-full text-xs">
                            <thead><tr class="text-left uppercase tracking-wide text-gray-400"><th class="px-2 py-2">ID</th><th class="px-2 py-2">{{ __('background_operations.columns.dataset') }}</th><th class="px-2 py-2">{{ __('background_operations.columns.status') }}</th><th class="px-2 py-2">Attempt</th><th class="px-2 py-2">Rows</th><th class="px-2 py-2">Retry</th><th class="px-2 py-2">Lock</th><th class="px-2 py-2">{{ __('background_operations.columns.error') }}</th></tr></thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($run['datasets'] as $dataset)
                                    <tr>
                                        <td class="px-2 py-2 font-mono">#{{ $dataset['id'] }}</td>
                                        <td class="px-2 py-2"><div class="font-medium">{{ $dataset['family'] }}</div><div class="text-[11px] text-gray-400">{{ $dataset['dataset'] }}</div></td>
                                        <td class="px-2 py-2">{{ $dataset['status'] }}</td>
                                        <td class="px-2 py-2">{{ $dataset['attempts'] }}/{{ $dataset['max_attempts'] }}</td>
                                        <td class="px-2 py-2">{{ $dataset['rows_written'] }}</td>
                                        <td class="px-2 py-2">{{ $dataset['retry_at'] ?? '—' }}</td>
                                        <td class="px-2 py-2">{{ $dataset['stale_lock'] ? __('background_operations.labels.stale_lock') : ($dataset['locked'] ? __('background_operations.labels.locked') : __('background_operations.labels.unlocked')) }}</td>
                                        <td class="max-w-[320px] px-2 py-2 text-rose-600">{{ $dataset['error_message'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </article>
        @empty
            <div class="rounded-xl bg-white p-6 text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">{{ __('background_operations.labels.no_runs') }}</div>
        @endforelse
    </section>

    <section class="grid gap-4 xl:grid-cols-2">
        <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('background_operations.sections.infrastructure') }}</h2>
            <dl class="mt-4 grid gap-3 sm:grid-cols-2 text-sm">
                <div><dt class="text-gray-400">{{ __('background_operations.labels.redis') }}</dt><dd class="font-medium {{ $infra['redis_ok'] ? 'text-emerald-700' : 'text-rose-700' }}">{{ $infra['redis_ok'] ? __('background_operations.labels.healthy') : ($infra['redis_error'] ?? 'Error') }}</dd></div>
                <div><dt class="text-gray-400">{{ __('background_operations.labels.queue_connection') }}</dt><dd class="font-medium">{{ $infra['queue_connection'] }}</dd></div>
                <div><dt class="text-gray-400">{{ __('background_operations.labels.collection_queue') }}</dt><dd class="font-medium">{{ $infra['collection_queue'] }}</dd></div>
                <div><dt class="text-gray-400">{{ __('background_operations.labels.latest_activity') }}</dt><dd class="font-medium">{{ $infra['latest_collection_activity'] ?? '—' }}</dd></div>
            </dl>
            <div class="mt-4 grid gap-2 sm:grid-cols-2">
                @foreach ($snapshot['queues'] as $queue)
                    <div class="rounded-lg bg-gray-50 px-3 py-2 text-xs dark:bg-white/[0.03]"><span class="font-medium">{{ $queue['name'] }}</span><span class="float-right tabular-nums">{{ $queue['size'] }}</span>@if ($queue['error'])<p class="mt-1 text-rose-600">{{ $queue['error'] }}</p>@endif</div>
                @endforeach
            </div>
        </div>

        <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('background_operations.sections.failed_jobs') }}</h2>
                    <p class="mt-1 text-xs text-gray-500">{{ $failedJobTotal }} kayıt</p>
                </div>
                @if ($isAdmin && $failedJobTotal > 0)
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="retryAllFailedJobs" wire:loading.attr="disabled" wire:confirm="{{ __('background_operations.confirm.retry_all_failed_jobs') }}" class="rounded-lg px-3 py-2 text-xs font-semibold text-blue-700 ring-1 ring-inset ring-blue-200 hover:bg-blue-50 disabled:opacity-50 dark:text-blue-300 dark:ring-blue-500/30">{{ __('background_operations.actions.retry_all_failed_jobs') }}</button>
                        <button type="button" wire:click="forgetAllFailedJobs" wire:loading.attr="disabled" wire:confirm="{{ __('background_operations.confirm.forget_all_failed_jobs') }}" class="rounded-lg px-3 py-2 text-xs font-semibold text-rose-700 ring-1 ring-inset ring-rose-200 hover:bg-rose-50 disabled:opacity-50 dark:text-rose-300 dark:ring-rose-500/30">{{ __('background_operations.actions.forget_all_failed_jobs') }}</button>
                    </div>
                @endif
            </div>
            <div class="mt-4 space-y-3">
                @forelse ($snapshot['failed_jobs'] as $job)
                    <div class="rounded-lg bg-rose-50/60 p-3 ring-1 ring-inset ring-rose-100 dark:bg-rose-500/[0.06] dark:ring-rose-500/20">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $job['job'] }}</p>
                                <p class="mt-1 font-mono text-[11px] text-gray-500">{{ $job['connection'] }}:{{ $job['queue'] }} · {{ $job['failed_at'] }}</p>
                                <details class="mt-2"><summary class="cursor-pointer text-xs text-rose-600">Exception</summary><pre class="mt-2 max-h-44 overflow-auto whitespace-pre-wrap rounded bg-black/5 p-2 text-[11px]">{{ $job['exception'] }}</pre></details>
                            </div>
                            @if ($isAdmin)
                                <div class="flex gap-2">
                                    <button type="button" wire:click="retryFailedJob('{{ $job['uuid'] }}')" wire:confirm="{{ __('background_operations.confirm.retry_failed_job') }}" class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-200">{{ __('background_operations.actions.retry_failed_job') }}</button>
                                    <button type="button" wire:click="forgetFailedJob('{{ $job['uuid'] }}')" wire:confirm="{{ __('background_operations.confirm.forget_failed_job') }}" class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-700 ring-1 ring-inset ring-rose-200">{{ __('background_operations.actions.forget_failed_job') }}</button>
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">{{ __('background_operations.labels.no_failed_jobs') }}</p>
                @endforelse
            </div>
        </div>
    </section>

    <section class="grid gap-4 xl:grid-cols-2">
        <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('background_operations.sections.agents') }}</h2>
            <div class="mt-4 space-y-2">
                @forelse ($snapshot['agent_runs'] as $run)
                    <div class="flex items-start justify-between gap-3 rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><div class="min-w-0"><p class="truncate text-sm font-medium">{{ $run['agent'] ?: 'Agent #'.$run['id'] }}</p><p class="mt-0.5 text-xs text-gray-500">{{ $run['scope'] }} · {{ $run['route'] ?: '—' }} · {{ $run['age'] ?: '—' }}</p></div><span class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $statusTone($run['status']) }}">{{ $run['status'] }}</span></div>
                @empty
                    <p class="text-sm text-gray-500">{{ __('background_operations.labels.no_agents') }}</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('background_operations.sections.legacy') }}</h2>
            <div class="mt-4 space-y-2">
                @forelse ($snapshot['legacy_runs'] as $run)
                    <div class="flex items-start justify-between gap-3 rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><div class="min-w-0"><p class="truncate text-sm font-medium">Run #{{ $run['id'] }} · {{ $run['module'] }}</p><p class="mt-0.5 text-xs text-gray-500">{{ $run['scope'] }} · {{ $run['started_at'] ?? '—' }}</p></div><span class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $statusTone($run['status']) }}">{{ $run['status'] }}</span></div>
                @empty
                    <p class="text-sm text-gray-500">{{ __('background_operations.labels.no_legacy') }}</p>
                @endforelse
            </div>
        </div>
    </section>
</div>
