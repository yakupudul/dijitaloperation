@php
    // Panel concept 2026-10-20: closed it is one card row; open it is a filter row and one table of accounts.
    $field = 'h-9 rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
    $refreshIcon = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12a8 8 0 1 0-2.34 5.66M20 12V6m0 6h-6"/></svg>';
@endphp
<section>
    @if(! $expanded)
        <div class="card">
            <button type="button" wire:click="$toggle('expanded')" class="card-row w-full text-left transition hover:bg-gray-50 dark:hover:bg-white/[0.02]" aria-expanded="false">
                <span class="row-icon">{!! $refreshIcon !!}</span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ __('resource-auto.title') }}</span>
                    <span class="mt-0.5 block text-[13px] text-gray-500 dark:text-gray-400">{{ __('resource-auto.intro') }}</span>
                </span>
                <span class="btn-quiet" aria-hidden="true">Aç</span>
            </button>
        </div>
    @else
        <div class="section-head items-center">
            <h2 class="section-title" title="{{ __('resource-auto.runtime_note') }}">{{ __('resource-auto.title') }} <span class="section-count">{{ $accounts->total() }}</span></h2>
            <button type="button" wire:click="$toggle('expanded')" class="btn-quiet ml-auto" aria-expanded="true">Kapat</button>
        </div>
        <div wire:poll.20s class="space-y-3">
            @if($message)<p role="status" class="card px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{{ $message }}</p>@endif
            @if($errors->any())<p role="alert" class="card px-4 py-3 text-sm text-error-700 dark:text-error-400">{{ $errors->first() }}</p>@endif
            <div class="flex flex-wrap gap-2">
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('resource-auto.search') }}" aria-label="{{ __('resource-auto.search') }}" class="{{ $field }} w-full min-w-0 flex-1 sm:min-w-48">
                @if($resourceType === '')<select wire:model.live="type" aria-label="{{ __('resource-auto.source') }}" class="{{ $field }} w-full sm:w-auto">
                    <option value="">{{ __('resource-auto.all') }}</option>
                    @foreach(\App\Services\Integrations\ResourceAutomationService::TYPES as $kind)<option value="{{ $kind }}">{{ __('resource-auto.'.$kind) }}</option>@endforeach
                </select>@endif
                <select wire:model.live="stateFilter" aria-label="{{ __('resource-auto.filter_state') }}" class="{{ $field }} w-full sm:w-auto">
                    @foreach(['' => 'all', 'due' => 'overdue', 'collecting' => 'active_work', 'attention' => 'state_attention', 'current' => 'state_current', 'paused' => 'paused'] as $value => $label)
                        <option value="{{ $value }}">{{ __('resource-auto.'.$label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="card overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>{{ __('resource-auto.account') }}</th><th>{{ __('resource-auto.connection') }}</th><th title="{{ __('resource-auto.coverage_note') }}">{{ __('resource-auto.coverage') }}</th><th>{{ __('resource-auto.collection') }}</th><th><span class="sr-only">{{ __('resource-auto.settings') }}</span></th></tr></thead>
                    <tbody>
                    @forelse($accounts as $a)
                        @php
                            $problem = app(\App\Services\Integrations\ResourceAutomationService::class)->readiness($a->resource);
                            $latest = $latestCollections->get($a->external_resource_id);
                            $range = $coverage->get($a->external_resource_id);
                            $active = $latest && ! $latest->status->isTerminal() && ! $latest->collectionRun?->status->isTerminal();
                            $state = $active ? app(\App\Services\Collection\Monitoring\CollectionAccountPresenter::class)->state($latest) : $a->collection_status;
                            $overdue = $a->collection_enabled && ! $active && ! in_array($a->collection_status, ['planning','collecting'], true) && $a->next_collection_at?->lt(now()->subMinutes(30));
                        @endphp
                        <tr wire:key="auto-account-{{ $a->id }}" class="align-top">
                            <td class="min-w-48"><p class="font-medium text-gray-900 dark:text-white">{{ $a->resource->display_name ?: $a->resource->external_id }}</p><p class="mt-0.5 text-xs text-gray-500">{{ __('resource-auto.'.$a->resource->resource_type) }}, {{ $a->resource->external_id }}</p></td>
                            <td class="min-w-36"><x-ta.pill :tone="$problem ? 'look' : 'ok'">{{ __('resource-auto.'.($problem ? 'state_attention' : 'connected')) }}</x-ta.pill>@if($problem)<p class="mt-1 max-w-xs text-xs text-gray-500">{{ __('resource-auto.'.$problem) }}</p>@endif</td>
                            <td class="min-w-40 text-xs text-gray-600 dark:text-gray-300" @if($range) title="{{ __('resource-auto.coverage_note') }}" @endif>{{ $a->gbpRun ? __('gbp-connector.progress', ['count' => count(data_get($a->gbpRun->metadata, 'datasets', []))]) : ($range ? $range->first_date.' – '.$range->last_date : __('resource-auto.no_data')) }}</td>
                            <td class="min-w-56">
                                <p @class(['text-sm', 'text-warning-700 dark:text-warning-400' => $overdue || $state === 'delayed' || $a->collection_error, 'text-gray-900 dark:text-white' => ! ($overdue || $state === 'delayed' || $a->collection_error)])>{{ $active ? __('resource-auto.state_'.$state) : (! $a->collection_enabled ? __('resource-auto.paused') : ($overdue ? __('resource-auto.overdue') : __('resource-auto.state_'.$state))) }}</p>
                                <p class="mt-0.5 text-xs text-gray-500">{{ __('resource-auto.frequency') }}: {{ __('resource-auto.'.($a->interval_days === 3 ? 'three_days' : 'daily')) }}</p>
                                <p class="text-xs text-gray-500">{{ __('resource-auto.last') }}: {{ $a->last_collection_success_at?->copy()->timezone('Europe/Istanbul')->format('d.m.Y H:i') ?? '—' }}, {{ __('resource-auto.'.($overdue ? 'due_since' : 'next')) }}: {{ $a->collection_enabled ? ($a->next_collection_at?->copy()->timezone('Europe/Istanbul')->format('d.m.Y H:i') ?? '—') : '—' }}</p>
                                @if($active)<p class="text-xs text-gray-500">#{{ $latest->collection_run_id }}, {{ $latest->last_activity_at?->diffForHumans() }}</p>@endif
                                @if($a->collection_error)<p class="mt-1 max-w-xs text-xs text-warning-700 dark:text-warning-400">{{ __('resource-auto.'.$a->collection_error) }}</p>@endif
                            </td>
                            <td class="text-right">
                                <div class="flex flex-col items-end gap-1">
                                    @if($isAdmin)<button type="button" wire:click="runNow({{ $a->id }})" wire:loading.attr="disabled" @disabled($problem || $active || in_array($a->collection_status, ['planning','collecting'])) class="btn h-7! px-2.5! text-xs!">{{ __('resource-auto.run_now') }}</button><button type="button" wire:click="edit({{ $a->id }})" class="btn-quiet h-7! px-2! text-xs!">{{ __('resource-auto.settings') }}</button>@endif
                                    <button type="button" wire:click="inspectCollection({{ $a->id }})" class="btn-quiet h-7! px-2! text-xs!">{{ __('resource-auto.inspect_collection') }}</button>
                                </div>
                            </td>
                        </tr>
                    @empty<tr><td colspan="5" class="py-8 text-center text-gray-500">{{ __('resource-auto.empty') }}</td></tr>@endforelse
                    </tbody>
                </table>
            </div>
            {{ $accounts->links() }}
        </div>
    @endif
    @if($editor)
        <div class="fixed inset-0 z-[100] flex justify-end bg-black/40" role="dialog" aria-modal="true" aria-label="{{ __('resource-auto.settings') }}" x-data x-trap.inert.noscroll="true" x-on:keydown.escape.window="$wire.closeEditor()">
            <form wire:submit="save" class="flex h-full w-full max-w-lg flex-col bg-white shadow-xl dark:bg-gray-900">
                <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-800"><h3 class="text-[15px] font-semibold text-gray-900 dark:text-white">{{ $editor->resource->display_name }}</h3><button type="button" wire:click="closeEditor" class="btn-quiet" aria-label="{{ __('resource-auto.close') }}">✕</button></div>
                <div class="flex-1 space-y-5 overflow-y-auto p-5">
                    @if($errors->any())<p role="alert" class="text-sm text-error-600">{{ $errors->first() }}</p>@endif
                    <label class="flex items-center gap-2 text-sm text-gray-900 dark:text-white"><input type="checkbox" wire:model="collectionEnabled" class="rounded border-gray-300 text-gray-900">{{ __('resource-auto.collection_enabled') }}</label>
                    <label class="block text-[13px] text-gray-600 dark:text-gray-400">{{ __('resource-auto.frequency') }}<select wire:model="intervalDays" class="{{ $field }} mt-1 w-full"><option value="1">{{ __('resource-auto.daily') }}</option><option value="3">{{ __('resource-auto.three_days') }}</option></select></label>
                    <label class="block text-[13px] text-gray-600 dark:text-gray-400">Çekim saati (İstanbul)<select wire:model="preferredHour" class="{{ $field }} mt-1 w-full"><option value="">Otomatik (son çekimden itibaren)</option>@for ($h = 0; $h < 24; $h++)<option value="{{ $h }}">{{ str_pad((string) $h, 2, '0', STR_PAD_LEFT) }}:00</option>@endfor</select></label>
                </div>
                <div class="flex justify-end gap-2 border-t border-gray-200 px-5 py-4 dark:border-gray-800"><button type="button" wire:click="closeEditor" class="btn">{{ __('resource-auto.cancel') }}</button><button type="submit" wire:loading.attr="disabled" class="btn-primary">{{ __('resource-auto.save') }}</button></div>
            </form>
        </div>
    @endif
    @if($collectionAccount)
        <div class="fixed inset-0 z-[100] flex justify-end bg-black/40" role="dialog" aria-modal="true" aria-label="{{ __('resource-auto.inspect_collection') }}" x-data x-trap.inert.noscroll="true" x-on:keydown.escape.window="$wire.closeCollection()">
            <div class="flex h-full w-full max-w-3xl flex-col bg-white shadow-xl dark:bg-gray-900">
                <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-800"><h3 class="text-[15px] font-semibold text-gray-900 dark:text-white">{{ $collectionAccount->resource->display_name }}</h3><button type="button" wire:click="closeCollection" class="btn-quiet" aria-label="{{ __('resource-auto.close') }}">✕</button></div>
                <div class="flex-1 space-y-3 overflow-y-auto p-5">
                    <p class="text-xs text-gray-500">{{ __('resource-auto.collection_history_note') }}</p>
                    @foreach($gbpHistory as $gbpRun)
                        <details class="card" @if($loop->first) open @endif>
                            <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">#{{ $gbpRun->id }}, {{ __('gbp-connector.'.$gbpRun->status) }}, {{ $gbpRun->started_at?->copy()->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</summary>
                            @if(data_get($gbpRun->metadata, 'active_dataset'))<p class="px-4 pb-2 text-xs text-gray-600">{{ __('gbp-connector.running') }}: {{ __('gbp-connector.'.data_get($gbpRun->metadata, 'active_dataset')) }}</p>@endif
                            @foreach(data_get($gbpRun->metadata, 'datasets', []) as $key => $result)
                                <div class="border-t border-gray-200 px-4 py-3 text-xs dark:border-gray-800">
                                    <p class="font-medium text-gray-900 dark:text-white">{{ __('gbp-connector.'.$key) }}, {{ __('gbp-connector.'.$result['status']) }}</p>
                                    <p class="mt-0.5 text-gray-500">{{ __('gbp-connector.rows', ['count' => $result['rows'] ?? 0]) }}@if(isset($result['from'])), {{ $result['from'] }} – {{ $result['to'] ?? '—' }}@endif</p>
                                    @if(!empty($result['reason']))<p class="mt-1 text-warning-700 dark:text-warning-400">{{ $result['reason'] }}</p>@endif
                                    @foreach(($result['metric_errors'] ?? $result['month_errors'] ?? []) as $error)<p class="mt-1 text-warning-700 dark:text-warning-400">{{ $error }}</p>@endforeach
                                </div>
                            @endforeach
                        </details>
                    @endforeach
                    @forelse($collectionHistory as $collection)
                        <details class="card" @if($loop->first) open @endif>
                            <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">#{{ $collection->collection_run_id }}, {{ app(\App\Services\Collection\Monitoring\CollectionAccountPresenter::class)->label($collection) }}, {{ $collection->created_at?->copy()->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</summary>
                            @foreach($collection->datasetRuns as $dataset)
                                <div class="border-t border-gray-200 px-4 py-3 text-xs dark:border-gray-800">
                                    <p class="font-medium text-gray-900 dark:text-white">{{ data_get($dataset->metadata, 'dataset_label') ?: $dataset->dataset_contract_id }}, {{ __('resource-auto.state_'.$dataset->status->value) }}</p>
                                    <p class="mt-0.5 text-gray-500">{{ data_get($dataset->metadata, 'date_range.start', '—') }} – {{ data_get($dataset->metadata, 'date_range.end', '—') }}, {{ __('resource-auto.written_rows', ['count' => number_format($dataset->rows_written)]) }}</p>
                                    @if($dataset->retry_at)<p class="mt-0.5 text-gray-600">{{ __('resource-auto.retry_at') }}: {{ $dataset->retry_at->copy()->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</p>@endif
                                    @if($dataset->error_message)<details class="mt-1 text-warning-700 dark:text-warning-400"><summary class="cursor-pointer">{{ __('resource-auto.technical_details') }}</summary><p class="mt-1 break-words">{{ $dataset->error_code }}, {{ $dataset->error_message }}</p></details>@endif
                                </div>
                            @endforeach
                        </details>
                    @empty @if($gbpHistory->isEmpty())<p class="text-sm text-gray-500">{{ __('resource-auto.no_data') }}</p>@endif @endforelse
                </div>
            </div>
        </div>
    @endif
</section>
