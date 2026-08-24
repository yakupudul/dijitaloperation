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
            'cancelled', 'cancellation_requested', 'abstained' => 'bg-slate-50 text-slate-600 ring-slate-200 dark:bg-white/5 dark:text-slate-300 dark:ring-white/10',
            default => 'bg-gray-50 text-gray-600 ring-gray-200 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10',
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
            ['label' => __('background_operations.summary.active'), 'value' => $summary['active_collection_runs'], 'tone' => 'text-blue-700 dark:text-blue-300'],
            ['label' => __('background_operations.summary.stalled'), 'value' => $summary['stalled_collection_runs'], 'tone' => ($summary['stalled_collection_runs'] > 0 ? 'text-rose-700 dark:text-rose-300' : 'text-gray-900 dark:text-white')],
            ['label' => __('background_operations.summary.retrying'), 'value' => $summary['retrying_datasets'], 'tone' => 'text-amber-700 dark:text-amber-300'],
            ['label' => __('background_operations.summary.locked'), 'value' => $summary['locked_datasets'], 'tone' => 'text-gray-900 dark:text-white'],
            ['label' => __('background_operations.summary.failed_jobs'), 'value' => $summary['failed_jobs'], 'tone' => ($summary['failed_jobs'] > 0 ? 'text-rose-700 dark:text-rose-300' : 'text-gray-900 dark:text-white')],
            ['label' => __('background_operations.summary.queue_depth'), 'value' => $summary['queue_depth'], 'tone' => 'text-gray-900 dark:text-white'],
            ['label' => __('background_operations.summary.agents'), 'value' => $summary['running_agents'], 'tone' => 'text-violet-700 dark:text-violet-300'],
        ] as $card)
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-xs leading-4 text-gray-400">{{ $card['label'] }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums {{ $card['tone'] }}">{{ $card['value'] }}</p>
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
                <div class="p-4 sm:p-5">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-xs font-semibold text-gray-400">#{{ $run['id'] }}</span>
                                <h3 class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $run['title'] }}</h3>
                                <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $statusTone($run['status']) }}">
                                    {{ __('background_operations.status.'.$run['status']) !== 'background_operations.status.'.$run['status'] ? __('background_operations.status.'.$run['status']) : $run['status'] }}
                                </span>
                                @if ($run['stalled'])
                                    <span class="inline-flex rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-bold tracking-wide text-rose-700 ring-1 ring-inset ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20">{{ __('background_operations.labels.stalled') }}</span>
                                @endif
                            </div>

                            <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                <span><strong class="font-medium text-gray-700 dark:text-gray-300">{{ __('background_operations.columns.scope') }}:</strong> {{ $run['scope'] }}</span>
                                <span><strong class="font-medium text-gray-700 dark:text-gray-300">{{ __('background_operations.columns.provider') }}:</strong> {{ $run['provider_label'] }}</span>
                                <span><strong class="font-medium text-gray-700 dark:text-gray-300">{{ __('background_operations.columns.last_activity') }}:</strong> {{ $run['last_activity_human'] ?? '—' }}</span>
                                <span><strong class="font-medium text-gray-700 dark:text-gray-300">{{ __('background_operations.columns.attempts') }}:</strong> {{ $run['progress']['attempts'] }}</span>
                                <span><strong class="font-medium text-gray-700 dark:text-gray-300">{{ __('background_operations.columns.rows') }}:</strong> {{ number_format($run['progress']['rows']) }}</span>
                            </div>

                            <div class="mt-3 grid gap-2 sm:grid-cols-5">
                                <div class="rounded-lg bg-gray-50 px-3 py-2 text-xs dark:bg-white/[0.03]"><span class="text-gray-400">Queued</span><strong class="ml-2 tabular-nums text-gray-800 dark:text-gray-200">{{ $run['progress']['queued'] }}</strong></div>
                                <div class="rounded-lg bg-blue-50 px-3 py-2 text-xs dark:bg-blue-500/10"><span class="text-blue-500">Running</span><strong class="ml-2 tabular-nums text-blue-800 dark:text-blue-200">{{ $run['progress']['running'] }}</strong></div>
                                <div class="rounded-lg bg-amber-50 px-3 py-2 text-xs dark:bg-amber-500/10"><span class="text-amber-600">Retry</span><strong class="ml-2 tabular-nums text-amber-800 dark:text-amber-200">{{ $run['progress']['retrying'] }}</strong></div>
                                <div class="rounded-lg bg-emerald-50 px-3 py-2 text-xs dark:bg-emerald-500/10"><span class="text-emerald-600">Done</span><strong class="ml-2 tabular-nums text-emerald-800 dark:text-emerald-200">{{ $run['progress']['completed'] }}</strong></div>
                                <div class="rounded-lg bg-rose-50 px-3 py-2 text-xs dark:bg-rose-500/10"><span class="text-rose-600">Failed</span><strong class="ml-2 tabular-nums text-rose-800 dark:text-rose-200">{{ $run['progress']['failed'] }}</strong></div>
                            </div>
                        </div>

                        <div class="flex shrink-0 flex-wrap gap-2 xl:max-w-[430px] xl:justify-end">
                            <button type="button" wire:click="toggleRun({{ $run['id'] }})" class="rounded-lg px-3 py-2 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">{{ __('background_operations.actions.details') }}</button>
                            @if ($isAdmin && $run['can_wake'])
                                <button type="button" wire:click="wakeRun({{ $run['id'] }})" wire:loading.attr="disabled" class="rounded-lg px-3 py-2 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-200 hover:bg-blue-50 disabled:opacity-50 dark:text-blue-300 dark:ring-blue-500/30 dark:hover:bg-blue-500/10">{{ __('background_operations.actions.wake') }}</button>
                            @endif
                            @if ($isAdmin && $run['can_retry_now'])
                                <button type="button" wire:click="retryNow({{ $run['id'] }})" wire:loading.attr="disabled" class="rounded-lg px-3 py-2 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-200 hover:bg-amber-50 disabled:opacity-50 dark:text-amber-300 dark:ring-amber-500/30 dark:hover:bg-amber-500/10">{{ __('background_operations.actions.retry_now') }}</button>
                            @endif
                            @if ($isAdmin && $run['can_release_stale_locks'])
                                <button type="button" wire:click="releaseStaleLocks({{ $run['id'] }})" wire:confirm="{{ __('background_operations.confirm.release_locks') }}" class="rounded-lg px-3 py-2 text-xs font-medium text-orange-700 ring-1 ring-inset ring-orange-200 hover:bg-orange-50 dark:text-orange-300 dark:ring-orange-500/30 dark:hover:bg-orange-500/10">{{ __('background_operations.actions.release_locks') }} ({{ $run['stale_lock_count'] }})</button>
                            @endif
                            @if ($isAdmin && $run['can_cancel'])
                                <button type="button" wire:click="cancelRun({{ $run['id'] }})" wire:confirm="{{ __('background_operations.confirm.cancel') }}" class="rounded-lg px-3 py-2 text-xs font-medium text-rose-700 ring-1 ring-inset ring-rose-200 hover:bg-rose-50 dark:text-rose-300 dark:ring-rose-500/30 dark:hover:bg-rose-500/10">{{ __('background_operations.actions.cancel') }}</button>
                            @endif
                        </div>
                    </div>
                </div>

                @if ($expandedRunId === $run['id'])
                    <div class="border-t border-gray-100 bg-gray-50/60 p-4 dark:border-gray-800 dark:bg-white/[0.02]">
                        <div class="overflow-x-auto">
                            <table class="min-w-[1100px] w-full text-xs">
                                <thead>
                                    <tr class="text-left uppercase tracking-wide text-gray-400">
                                        <th class="px-2 py-2">ID</th>
                                        <th class="px-2 py-2">{{ __('background_operations.columns.dataset') }}</th>
                                        <th class="px-2 py-2">{{ __('background_operations.columns.status') }}</th>
                                        <th class="px-2 py-2">{{ __('background_operations.columns.attempts') }}</th>
                                        <th class="px-2 py-2">{{ __('background_operations.columns.rows') }}</th>
                                        <th class="px-2 py-2">{{ __('background_operations.columns.retry_at') }}</th>
                                        <th class="px-2 py-2">{{ __('background_operations.columns.lock') }}</th>
                                        <th class="px-2 py-2">{{ __('background_operations.columns.last_activity') }}</th>
                                        <th class="px-2 py-2">{{ __('background_operations.columns.error') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    @foreach ($run['datasets'] as $dataset)
                                        <tr>
                                            <td class="px-2 py-2 font-mono text-gray-500">#{{ $dataset['id'] }}</td>
                                            <td class="px-2 py-2">
                                                <div class="font-medium text-gray-800 dark:text-gray-200">{{ $dataset['family'] }}</div>
                                                <div class="mt-0.5 text-[11px] text-gray-400">{{ $dataset['dataset'] }}{{ $dataset['variant'] ? ' · '.$dataset['variant'] : '' }}</div>
                                                @if (is_array($dataset['date_range']))
                                                    <div class="mt-0.5 text-[11px] text-gray-400">{{ $dataset['date_range']['start'] ?? '—' }} → {{ $dataset['date_range']['end'] ?? '—' }}</div>
                                                @endif
                                            </td>
                                            <td class="px-2 py-2"><span class="inline-flex rounded-full px-2 py-0.5 font-semibold ring-1 ring-inset {{ $statusTone($dataset['status']) }}">{{ $dataset['status'] }}</span></td>
                                            <td class="px-2 py-2 tabular-nums text-gray-600 dark:text-gray-300">{{ $dataset['attempts'] }} / {{ $dataset['max_attempts'] }}</td>
                                            <td class="px-2 py-2 tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($dataset['rows_written']) }}</td>
                                            <td class="px-2 py-2 text-gray-500">{{ $dataset['retry_at'] ?? '—' }}</td>
                                            <td class="px-2 py-2">
                                                @if ($dataset['stale_lock'])
                                                    <span class="font-semibold text-rose-600 dark:text-rose-300">{{ __('background_operations.labels.stale_lock') }}</span>
                                                @elseif ($dataset['locked'])
                                                    <span class="text-amber-600 dark:text-amber-300">{{ __('background_operations.labels.locked') }}</span>
                                                @else
                                                    <span class="text-gray-400">{{ __('background_operations.labels.unlocked') }}</span>
                                                @endif
                                            </td>
                                            <td class="px-2 py-2 text-gray-500">{{ $dataset['last_activity_human'] ?? '—' }}</td>
                                            <td class="max-w-[300px] px-2 py-2 text-gray-500">
                                                @if ($dataset['error_message'])
                                                    <span title="{{ $dataset['error_message'] }}">{{ \Illuminate\Support\Str::limit($dataset['error_message'], 120) }}</span>
                                                @else
                                                    —
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </article>
        @empty
            <div class="rounded-xl bg-white p-8 text-center text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">{{ __('background_operations.labels.no_runs') }}</div>
        @endforelse
    </section>

    <section class="grid gap-4 xl:grid-cols-2">
        <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('background_operations.sections.infrastructure') }}</h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                    <p class="text-xs text-gray-400">{{ __('background_operations.labels.redis') }}</p>
                    <p class="mt-1 text-sm font-semibold {{ $infra['redis_ok'] ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">{{ $infra['redis_ok'] ? __('background_operations.labels.healthy') : 'ERROR' }}</p>
                    @if ($infra['redis_error']) <p class="mt-1 text-xs text-rose-500">{{ $infra['redis_error'] }}</p> @endif
                </div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                    <p class="text-xs text-gray-400">{{ __('background_operations.labels.queue_connection') }}</p>
                    <p class="mt-1 font-mono text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $infra['queue_connection'] }}</p>
                </div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                    <p class="text-xs text-gray-400">{{ __('background_operations.labels.collection_queue') }}</p>
                    <p class="mt-1 font-mono text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $infra['collection_queue'] }}</p>
                </div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                    <p class="text-xs text-gray-400">{{ __('background_operations.labels.latest_activity') }}</p>
                    <p class="mt-1 text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $infra['latest_collection_activity'] ?? '—' }}</p>
                </div>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="border-b border-gray-100 text-left text-xs uppercase text-gray-400 dark:border-gray-800"><th class="py-2">Queue</th><th class="py-2 text-right">Depth</th><th class="py-2 pl-4">Error</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($snapshot['queues'] as $queue)
                            <tr><td class="py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $queue['name'] }}</td><td class="py-2 text-right font-semibold tabular-nums text-gray-800 dark:text-gray-200">{{ $queue['size'] }}</td><td class="py-2 pl-4 text-xs text-rose-500">{{ $queue['error'] ?? '' }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('background_operations.sections.failed_jobs') }}</h2>
            <div class="mt-4 space-y-3">
                @forelse ($snapshot['failed_jobs'] as $job)
                    <div class="rounded-lg bg-rose-50/60 p-3 ring-1 ring-inset ring-rose-100 dark:bg-rose-500/[0.06] dark:ring-rose-500/20">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $job['job'] }}</p>
                                <p class="mt-1 font-mono text-[11px] text-gray-500">{{ $job['connection'] }}:{{ $job['queue'] }} · {{ $job['failed_at'] }}</p>
                                <details class="mt-2"><summary class="cursor-pointer text-xs text-rose-600 dark:text-rose-300">Exception</summary><pre class="mt-2 max-h-48 overflow-auto whitespace-pre-wrap rounded bg-black/5 p-2 text-[11px] text-gray-600 dark:bg-black/20 dark:text-gray-300">{{ $job['exception'] }}</pre></details>
                            </div>
                            @if ($isAdmin)
                                <div class="flex shrink-0 flex-wrap gap-2">
                                    <button type="button" wire:click="retryFailedJob('{{ $job['uuid'] }}')" wire:confirm="{{ __('background_operations.confirm.retry_failed_job') }}" class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-200 hover:bg-blue-50 dark:text-blue-300 dark:ring-blue-500/30">{{ __('background_operations.actions.retry_failed_job') }}</button>
                                    <button type="button" wire:click="forgetFailedJob('{{ $job['uuid'] }}')" wire:confirm="{{ __('background_operations.confirm.forget_failed_job') }}" class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-700 ring-1 ring-inset ring-rose-200 hover:bg-rose-50 dark:text-rose-300 dark:ring-rose-500/30">{{ __('background_operations.actions.forget_failed_job') }}</button>
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
                    <div class="flex items-start justify-between gap-3 rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                        <div class="min-w-0"><p class="truncate text-sm font-medium text-gray-800 dark:text-gray-200">{{ $run['agent'] ?: 'Agent #'.$run['id'] }}</p><p class="mt-0.5 text-xs text-gray-500">{{ $run['scope'] }} · {{ $run['route'] ?: '—' }} · {{ $run['age'] ?: '—' }}</p></div>
                        <span class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $statusTone($run['status']) }}">{{ $run['status'] }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">{{ __('background_operations.labels.no_agents') }}</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('background_operations.sections.legacy') }}</h2>
            <div class="mt-4 space-y-2">
                @forelse ($snapshot['legacy_runs'] as $run)
                    <div class="flex items-start justify-between gap-3 rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                        <div class="min-w-0"><p class="truncate text-sm font-medium text-gray-800 dark:text-gray-200">Run #{{ $run['id'] }} · {{ $run['module'] }}</p><p class="mt-0.5 text-xs text-gray-500">{{ $run['scope'] }} · {{ $run['started_at'] ?? '—' }}</p></div>
                        <span class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $statusTone($run['status']) }}">{{ $run['status'] }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">{{ __('background_operations.labels.no_legacy') }}</p>
                @endforelse
            </div>
        </div>
    </section>
</div>
