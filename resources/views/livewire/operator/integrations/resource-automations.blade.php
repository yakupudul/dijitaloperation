<section>
    <button type="button" wire:click="$toggle('expanded')" class="flex w-full items-center justify-between gap-4 py-2 text-left" aria-expanded="{{ $expanded ? 'true' : 'false' }}">
        <span><span class="section-title block">{{ __('resource-auto.title') }}</span><span class="mt-1 block text-xs text-gray-500">{{ __('resource-auto.intro') }}</span></span><span aria-hidden="true">{{ $expanded ? '−' : '+' }}</span>
    </button>
    @if($expanded)
        <div wire:poll.20s class="space-y-4 pt-3">
            @if($message)<p role="status" class="rounded-lg p-3 text-sm tone-info">{{ $message }}</p>@endif
            @if($errors->any())<p role="alert" class="rounded-lg p-3 text-sm tone-critical">{{ $errors->first() }}</p>@endif
            <div class="flex flex-wrap gap-3">
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('resource-auto.search') }}" aria-label="{{ __('resource-auto.search') }}" class="w-full min-w-0 flex-1 rounded-lg border-gray-300 text-sm sm:min-w-48 dark:border-gray-700 dark:bg-gray-950">
                @if($resourceType === '')<select wire:model.live="type" aria-label="{{ __('resource-auto.source') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                    <option value="">{{ __('resource-auto.all') }}</option>
                    @foreach(\App\Services\Integrations\ResourceAutomationService::TYPES as $kind)<option value="{{ $kind }}">{{ __('resource-auto.'.$kind) }}</option>@endforeach
                </select>@endif
                <select wire:model.live="stateFilter" aria-label="{{ __('resource-auto.filter_state') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                    @foreach(['' => 'all', 'due' => 'overdue', 'collecting' => 'active_work', 'attention' => 'state_attention', 'current' => 'state_current', 'paused' => 'paused'] as $value => $label)
                        <option value="{{ $value }}">{{ __('resource-auto.'.$label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-gray-200 text-xs text-gray-500 dark:border-gray-800"><tr><th class="p-3 font-normal">{{ __('resource-auto.account') }}</th><th class="p-3 font-normal">{{ __('resource-auto.connection') }}</th><th class="p-3 font-normal">{{ __('resource-auto.coverage') }}</th><th class="p-3 font-normal">{{ __('resource-auto.collection') }}</th><th class="p-3"></th></tr></thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
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
                            <td class="p-3"><p class="font-medium">{{ $a->resource->display_name ?: $a->resource->external_id }}</p><p class="mt-1 text-xs text-gray-500">{{ __('resource-auto.'.$a->resource->resource_type) }} · {{ $a->resource->external_id }}</p></td>
                            <td class="p-3"><p class="{{ $problem ? 'text-warning-700' : 'text-success-700' }}">{{ __('resource-auto.'.($problem ? 'state_attention' : 'connected')) }}</p>@if($problem)<p class="mt-1 max-w-xs text-xs text-gray-500">{{ __('resource-auto.'.$problem) }}</p>@endif</td>
                            <td class="p-3 text-xs"><p>{{ $a->gbpRun ? __('gbp-connector.progress', ['count' => count(data_get($a->gbpRun->metadata, 'datasets', []))]) : ($range ? $range->first_date.' – '.$range->last_date : __('resource-auto.no_data')) }}</p>@if($range)<p class="mt-1 max-w-xs text-gray-500">{{ __('resource-auto.coverage_note') }}</p>@endif</td>
                            <td class="p-3">
                                <p class="{{ $overdue || $state === 'delayed' || $a->collection_error ? 'text-warning-700 dark:text-warning-400' : '' }}">{{ $active ? __('resource-auto.state_'.$state) : (! $a->collection_enabled ? __('resource-auto.paused') : ($overdue ? __('resource-auto.overdue') : __('resource-auto.state_'.$state))) }}</p>
                                <p class="mt-1 text-xs text-gray-500">{{ __('resource-auto.frequency') }}: {{ __('resource-auto.'.($a->interval_days === 3 ? 'three_days' : 'daily')) }}</p>
                                <p class="mt-1 text-xs text-gray-500">{{ __('resource-auto.last') }}: {{ $a->last_collection_success_at?->copy()->timezone('Europe/Istanbul')->format('d.m.Y H:i') ?? '—' }}</p>
                                <p class="mt-1 text-xs text-gray-500">{{ __('resource-auto.'.($overdue ? 'due_since' : 'next')) }}: {{ $a->collection_enabled ? ($a->next_collection_at?->copy()->timezone('Europe/Istanbul')->format('d.m.Y H:i') ?? '—') : '—' }}</p>
                                @if($active)<p class="mt-1 text-xs text-gray-500">#{{ $latest->collection_run_id }} · {{ $latest->last_activity_at?->diffForHumans() }}</p>@endif
                                @if($a->collection_error)<p class="mt-2 max-w-xs text-xs text-warning-700 dark:text-warning-400">{{ __('resource-auto.'.$a->collection_error) }}</p>@endif
                            </td>
                            <td class="space-y-2 p-3 text-right text-xs">@if($isAdmin)<button type="button" wire:click="edit({{ $a->id }})" class="block w-full link-neutral">{{ __('resource-auto.settings') }}</button><button type="button" wire:click="runNow({{ $a->id }})" wire:loading.attr="disabled" @disabled($problem || $active || in_array($a->collection_status, ['planning','collecting'])) class="block w-full disabled:opacity-40">{{ __('resource-auto.run_now') }}</button>@endif<button type="button" wire:click="inspectCollection({{ $a->id }})" class="block w-full link-neutral">{{ __('resource-auto.inspect_collection') }}</button></td>
                        </tr>
                    @empty<tr><td colspan="5" class="p-8 text-center text-gray-500">{{ __('resource-auto.empty') }}</td></tr>@endforelse
                    </tbody>
                </table>
            </div>
            {{ $accounts->links() }}
            <p class="text-xs text-gray-500">{{ __('resource-auto.runtime_note') }}</p>
        </div>
    @endif
    @if($editor)
        <div class="fixed inset-0 z-[100] flex justify-end bg-black/40" role="dialog" aria-modal="true" aria-label="{{ __('resource-auto.settings') }}" x-data x-trap.inert.noscroll="true" x-on:keydown.escape.window="$wire.closeEditor()">
            <form wire:submit="save" class="flex h-full w-full max-w-lg flex-col bg-white shadow-xl dark:bg-gray-900">
                <div class="flex items-center justify-between border-b p-5 dark:border-gray-800"><h3 class="font-semibold">{{ $editor->resource->display_name }}</h3><button type="button" wire:click="closeEditor" aria-label="{{ __('resource-auto.close') }}">✕</button></div>
                <div class="flex-1 space-y-5 overflow-y-auto p-5">
                    @if($errors->any())<p role="alert" class="text-sm text-error-600">{{ $errors->first() }}</p>@endif
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="collectionEnabled" class="rounded border-gray-300 text-brand-500">{{ __('resource-auto.collection_enabled') }}</label>
                    <label class="block text-sm">{{ __('resource-auto.frequency') }}<select wire:model="intervalDays" class="mt-2 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="1">{{ __('resource-auto.daily') }}</option><option value="3">{{ __('resource-auto.three_days') }}</option></select></label>
                    <label class="block text-sm">Çekim saati (İstanbul)<select wire:model="preferredHour" class="mt-2 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Otomatik (son çekimden itibaren)</option>@for ($h = 0; $h < 24; $h++)<option value="{{ $h }}">{{ str_pad((string) $h, 2, '0', STR_PAD_LEFT) }}:00</option>@endfor</select></label>
                </div>
                <div class="flex justify-end gap-3 border-t p-4 dark:border-gray-800"><button type="button" wire:click="closeEditor" class="px-4 py-2 text-sm">{{ __('resource-auto.cancel') }}</button><button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-4 py-2 text-sm text-white disabled:opacity-50">{{ __('resource-auto.save') }}</button></div>
            </form>
        </div>
    @endif
    @if($collectionAccount)
        <div class="fixed inset-0 z-[100] flex justify-end bg-black/40" role="dialog" aria-modal="true" aria-label="{{ __('resource-auto.inspect_collection') }}" x-data x-trap.inert.noscroll="true" x-on:keydown.escape.window="$wire.closeCollection()">
            <div class="flex h-full w-full max-w-3xl flex-col bg-white shadow-xl dark:bg-gray-900">
                <div class="flex items-center justify-between border-b p-5 dark:border-gray-800"><h3 class="font-semibold">{{ $collectionAccount->resource->display_name }}</h3><button type="button" wire:click="closeCollection" aria-label="{{ __('resource-auto.close') }}">✕</button></div>
                <div class="flex-1 space-y-4 overflow-y-auto p-5">
                    <p class="text-xs text-gray-500">{{ __('resource-auto.collection_history_note') }}</p>
                    @foreach($gbpHistory as $gbpRun)
                        <details class="border-b border-gray-200 py-3 dark:border-gray-800" @if($loop->first) open @endif>
                            <summary class="cursor-pointer text-sm font-medium">#{{ $gbpRun->id }} · {{ __('gbp-connector.'.$gbpRun->status) }} · {{ $gbpRun->started_at?->copy()->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</summary>
                            @if(data_get($gbpRun->metadata, 'active_dataset'))<p class="mt-3 text-xs">{{ __('gbp-connector.running') }}: {{ __('gbp-connector.'.data_get($gbpRun->metadata, 'active_dataset')) }}</p>@endif
                            @foreach(data_get($gbpRun->metadata, 'datasets', []) as $key => $result)
                                <div class="mt-3 border-t pt-3 text-xs dark:border-gray-700">
                                    <p class="font-medium">{{ __('gbp-connector.'.$key) }} · {{ __('gbp-connector.'.$result['status']) }}</p>
                                    <p>{{ __('gbp-connector.rows', ['count' => $result['rows'] ?? 0]) }}</p>
                                    @if(isset($result['from']))<p>{{ $result['from'] }} – {{ $result['to'] ?? '—' }}</p>@endif
                                    @if(!empty($result['reason']))<p class="mt-2 text-warning-700">{{ $result['reason'] }}</p>@endif
                                    @foreach(($result['metric_errors'] ?? $result['month_errors'] ?? []) as $error)<p class="mt-2 text-warning-700">{{ $error }}</p>@endforeach
                                </div>
                            @endforeach
                        </details>
                    @endforeach
                    @forelse($collectionHistory as $collection)
                        <details class="border-b border-gray-200 py-3 dark:border-gray-800" @if($loop->first) open @endif>
                            <summary class="cursor-pointer text-sm font-medium">#{{ $collection->collection_run_id }} · {{ app(\App\Services\Collection\Monitoring\CollectionAccountPresenter::class)->label($collection) }} · {{ $collection->created_at?->copy()->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</summary>
                            <div class="mt-3 space-y-3">
                                @foreach($collection->datasetRuns as $dataset)
                                    <div class="border-t pt-3 text-xs dark:border-gray-800">
                                        <p class="font-medium">{{ data_get($dataset->metadata, 'dataset_label') ?: $dataset->dataset_contract_id }} · {{ __('resource-auto.state_'.$dataset->status->value) }}</p>
                                        <p class="mt-1 text-gray-500">{{ data_get($dataset->metadata, 'date_range.start', '—') }} – {{ data_get($dataset->metadata, 'date_range.end', '—') }}, {{ __('resource-auto.written_rows', ['count' => number_format($dataset->rows_written)]) }}</p>
                                        @if($dataset->retry_at)<p class="mt-1">{{ __('resource-auto.retry_at') }}: {{ $dataset->retry_at->copy()->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</p>@endif
                                        @if($dataset->error_message)<details class="mt-2 text-warning-700"><summary class="cursor-pointer">{{ __('resource-auto.technical_details') }}</summary><p class="mt-2 break-words">{{ $dataset->error_code }} · {{ $dataset->error_message }}</p></details>@endif
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @empty @if($gbpHistory->isEmpty())<p class="text-sm text-gray-500">{{ __('resource-auto.no_data') }}</p>@endif @endforelse
                </div>
            </div>
        </div>
    @endif
</section>

