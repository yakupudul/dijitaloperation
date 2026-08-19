<div class="space-y-6">
    <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 dark:border-gray-800 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('operator.website', ['assetId' => $asset->id]) }}" wire:navigate class="text-sm font-medium text-brand-600 hover:underline">Website</a>
                <span class="text-gray-300">/</span>
                <span class="text-sm text-gray-500">{{ __('operator_runtime.discovery.title') }}</span>
            </div>
            <h1 class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{{ __('operator_runtime.discovery.title') }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $brand?->name ?? '—' }} · {{ $asset->primary_url ?: $asset->domain ?: $asset->name }}</p>
            <p class="mt-2 max-w-3xl text-sm text-gray-600 dark:text-gray-300">{{ __('operator_runtime.discovery.description') }}</p>
        </div>

        <button type="button" wire:click="runDiscovery" wire:loading.attr="disabled"
            class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-60">
            <span wire:loading.remove wire:target="runDiscovery">{{ __('operator_runtime.discovery.start') }}</span>
            <span wire:loading wire:target="runDiscovery">{{ __('operator_runtime.discovery.queueing') }}</span>
        </button>
    </div>

    @if ($statusMessage !== '')
        <div @class([
            'rounded-xl border px-4 py-3 text-sm',
            'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/20 dark:text-rose-300' => $statusTone === 'error',
            'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/20 dark:text-emerald-300' => $statusTone === 'success',
            'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900/50 dark:bg-blue-950/20 dark:text-blue-300' => ! in_array($statusTone, ['error', 'success'], true),
        ])>{{ $statusMessage }}</div>
    @endif

    <section @class([
        'rounded-xl border p-5',
        'border-emerald-200 bg-emerald-50/60 dark:border-emerald-900/50 dark:bg-emerald-950/10' => $runtime['tone'] === 'success',
        'border-rose-200 bg-rose-50/60 dark:border-rose-900/50 dark:bg-rose-950/10' => $runtime['tone'] === 'error',
        'border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-white/[0.02]' => ! in_array($runtime['tone'], ['success', 'error'], true),
    ])>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <h2 class="font-semibold text-gray-900 dark:text-white">
                    @if ($runtime['tone'] === 'success')
                        {{ __('operator_runtime.discovery.runtime_healthy') }}
                    @elseif ($runtime['tone'] === 'error')
                        {{ __('operator_runtime.discovery.runtime_degraded') }}
                    @else
                        {{ __('operator_runtime.discovery.runtime_unknown') }}
                    @endif
                </h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $runtime['worker_message'] }}</p>
                @if ($runtime['queue_message'] !== '' && $runtime['queue_message'] !== $runtime['worker_message'])
                    <p class="mt-1 text-xs text-gray-500">{{ $runtime['queue_message'] }}</p>
                @endif
            </div>
            <a href="{{ route('operator.activity') }}" wire:navigate class="shrink-0 text-sm font-semibold text-brand-600 hover:underline">{{ __('operator_runtime.discovery.open_activity') }} →</a>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg bg-white/70 p-3 dark:bg-white/[0.03]">
                <p class="text-xs text-gray-400">{{ __('operator_runtime.discovery.worker') }}</p>
                <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $runtime['worker_status'] }}</p>
            </div>
            <div class="rounded-lg bg-white/70 p-3 dark:bg-white/[0.03]">
                <p class="text-xs text-gray-400">{{ __('operator_runtime.discovery.pending_jobs') }}</p>
                <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $runtime['pending_jobs'] }}</p>
            </div>
            <div class="rounded-lg bg-white/70 p-3 dark:bg-white/[0.03]">
                <p class="text-xs text-gray-400">{{ __('operator_runtime.discovery.latest_run') }}</p>
                <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $runtime['run'] ? '#'.$runtime['run']->id.' · '.$runtime['run']->status : '—' }}</p>
            </div>
            <div class="rounded-lg bg-white/70 p-3 dark:bg-white/[0.03]">
                <p class="text-xs text-gray-400">{{ __('operator_runtime.discovery.current_phase') }}</p>
                <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $runtime['phase'] ?: '—' }}</p>
            </div>
        </div>

        @if (filled($runtime['failure']))
            <div class="mt-4 rounded-lg border border-rose-200 bg-white p-3 text-sm text-rose-700 dark:border-rose-900/40 dark:bg-black/10 dark:text-rose-300">
                <span class="font-semibold">{{ __('operator_runtime.discovery.last_failure') }}:</span> {{ $runtime['failure'] }}
            </div>
        @endif
    </section>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator_runtime.discovery.status') }}</p>
            <p class="mt-2 text-lg font-semibold text-gray-900 dark:text-white">{{ $discovery['status_label'] }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ $discovery['retrieved_human'] ?? __('operator_runtime.discovery.not_run') }}</p>
        </section>
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator_runtime.discovery.pages') }}</p>
            <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{{ $discovery['pages_inspected'] }}</p>
        </section>
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator_runtime.discovery.facts') }}</p>
            <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{{ $discovery['fact_count'] }}</p>
        </section>
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator_runtime.discovery.inferences') }}</p>
            <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{{ $discovery['inference_count'] }}</p>
            @if ($discovery['ai_label'])
                <p class="mt-1 text-xs text-gray-500">{{ $discovery['ai_label'] }}</p>
            @endif
        </section>
    </div>

    @if (is_array($discovery['summary'] ?? null))
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('operator_runtime.discovery.summary') }}</h2>
            <div class="mt-3 grid gap-3 text-sm md:grid-cols-2 xl:grid-cols-3">
                @foreach (($discovery['summary'] ?? []) as $key => $value)
                    @continue(is_array($value) || is_object($value))
                    <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                        <p class="text-xs text-gray-400">{{ str($key)->replace('_', ' ')->title() }}</p>
                        <p class="mt-1 break-words font-medium text-gray-800 dark:text-gray-200">
                            {{ is_bool($value) ? ($value ? __('operator_runtime.discovery.yes') : __('operator_runtime.discovery.no')) : ($value ?? '—') }}
                        </p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <section class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-700">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('operator_runtime.discovery.facts_review') }}</h2>
            <p class="mt-1 text-xs text-gray-500">{{ __('operator_runtime.discovery.facts_help') }}</p>
        </div>
        <div class="divide-y divide-gray-100 dark:divide-gray-700">
            @forelse ($discovery['fact_candidates'] as $candidate)
                <div class="flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 dark:bg-white/[0.05] dark:text-gray-300">{{ $candidate->candidate_type }}</span>
                            <span class="rounded-md px-2 py-1 text-xs font-medium {{ $candidate->status === 'pending' ? 'bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-300' : ($candidate->status === 'accepted' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300' : 'bg-gray-100 text-gray-500 dark:bg-white/[0.05]') }}">{{ $candidate->status }}</span>
                        </div>
                        <p class="mt-2 font-medium text-gray-900 dark:text-white">{{ $candidate->proposed_value }}</p>
                        <p class="mt-1 text-xs text-gray-500">{{ __('operator_runtime.discovery.target_field') }}: {{ $candidate->target_field ?: '—' }}</p>
                        @if (is_array($candidate->support_json) && filled(data_get($candidate->support_json, 'source_url')))
                            <a href="{{ data_get($candidate->support_json, 'source_url') }}" target="_blank" rel="noopener" class="mt-1 inline-block break-all text-xs text-brand-600 hover:underline">{{ data_get($candidate->support_json, 'source_url') }}</a>
                        @endif
                    </div>
                    @if ($candidate->status === 'pending')
                        <div class="flex shrink-0 gap-2">
                            <button type="button" wire:click="acceptCandidate({{ $candidate->id }})" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700">{{ __('operator_runtime.discovery.accept') }}</button>
                            <button type="button" wire:click="ignoreCandidate({{ $candidate->id }})" class="rounded-lg px-3 py-2 text-xs font-semibold text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-600">{{ __('operator_runtime.discovery.ignore') }}</button>
                        </div>
                    @endif
                </div>
            @empty
                <div class="px-5 py-8 text-sm text-gray-500">{{ __('operator_runtime.discovery.no_facts') }}</div>
            @endforelse
        </div>
    </section>

    <div class="grid gap-4 xl:grid-cols-2">
        <section class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-700"><h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('operator_runtime.discovery.ai_candidates') }}</h2></div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($discovery['inference_candidates'] as $candidate)
                    <div class="px-5 py-4">
                        <div class="flex items-center justify-between gap-2"><span class="text-xs font-medium text-gray-400">{{ $candidate->candidate_type }}</span><span class="text-xs text-gray-400">{{ $candidate->status }}</span></div>
                        <p class="mt-2 text-sm font-medium text-gray-800 dark:text-gray-200">{{ $candidate->proposed_value }}</p>
                    </div>
                @empty
                    <div class="px-5 py-8 text-sm text-gray-500">{{ __('operator_runtime.discovery.no_ai') }}</div>
                @endforelse
            </div>
        </section>

        <section class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-700"><h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('operator_runtime.discovery.competitors') }}</h2></div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($discovery['competitor_candidates'] as $candidate)
                    <div class="flex items-start justify-between gap-3 px-5 py-4">
                        <div><p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ $candidate->proposed_value }}</p><p class="mt-1 text-xs text-gray-500">{{ $candidate->status }}</p></div>
                        @if ($candidate->status === 'pending')
                            <button type="button" wire:click="acceptCandidate({{ $candidate->id }})" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white">{{ __('operator_runtime.discovery.accept') }}</button>
                        @endif
                    </div>
                @empty
                    <div class="px-5 py-8 text-sm text-gray-500">{{ $discovery['competitor_empty_message'] }}</div>
                @endforelse
            </div>
        </section>
    </div>
</div>
