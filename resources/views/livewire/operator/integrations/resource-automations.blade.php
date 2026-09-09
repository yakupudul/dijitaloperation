<section class="rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
    <button type="button" wire:click="$toggle('expanded')" class="flex w-full items-center justify-between gap-4 p-4 text-left" aria-expanded="{{ $expanded ? 'true' : 'false' }}">
        <span><span class="block text-sm font-semibold">{{ __('resource-auto.title') }}</span><span class="mt-1 block text-xs text-gray-500">{{ __('resource-auto.intro') }}</span></span><span aria-hidden="true">{{ $expanded ? '−' : '+' }}</span>
    </button>
    @if($expanded)
        <div wire:poll.20s class="space-y-4 border-t border-gray-200 p-4 dark:border-gray-800">
            @if($message)<p role="status" class="rounded-lg bg-blue-50 p-3 text-sm text-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ $message }}</p>@endif
            @if($errors->any())<p role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ $errors->first() }}</p>@endif
            <div class="flex flex-wrap gap-3">
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('resource-auto.search') }}" aria-label="{{ __('resource-auto.search') }}" class="min-w-48 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                <select wire:model.live="type" aria-label="{{ __('resource-auto.source') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                    <option value="">{{ __('resource-auto.all') }}</option>
                    @foreach($queriesOnly ? ['google_ads', 'search_console'] : \App\Services\Integrations\ResourceAutomationService::TYPES as $kind)<option value="{{ $kind }}">{{ __('resource-auto.'.$kind) }}</option>@endforeach
                </select>
                <a href="{{ route('operator.activity') }}" class="self-center text-xs text-brand-600 underline">{{ __('resource-auto.activity') }}</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b text-xs text-gray-500"><tr><th class="p-3">{{ __('resource-auto.account') }}</th><th class="p-3">{{ __('resource-auto.collection') }}</th><th class="p-3">{{ __('resource-auto.queries') }}</th><th class="p-3">{{ __('resource-auto.mapping') }}</th><th class="p-3"></th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($accounts as $a)
                        @php($problem = app(\App\Services\Integrations\ResourceAutomationService::class)->readiness($a->resource))
                        <tr wire:key="auto-account-{{ $a->id }}" class="align-top">
                            <td class="p-3"><p class="font-medium">{{ $a->resource->display_name ?: $a->resource->external_id }}</p><p class="mt-1 text-xs text-gray-500">{{ __('resource-auto.'.$a->resource->resource_type) }} · {{ $a->resource->external_id }}</p></td>
                            <td class="p-3"><p>{{ $a->collection_enabled ? __('resource-auto.state_'.$a->collection_status) : __('resource-auto.paused') }}</p><p class="mt-1 text-xs text-gray-500">{{ __('resource-auto.last') }}: {{ $a->last_collection_success_at?->format('d.m.Y H:i') ?? '—' }}</p><p class="mt-1 text-xs text-gray-500">{{ __('resource-auto.data_through') }}: {{ $a->data_through ?? '—' }}</p><p class="mt-1 text-xs text-gray-500">{{ __('resource-auto.next') }}: {{ $a->collection_enabled ? ($a->next_collection_at ? $a->next_collection_at->format('d.m.Y H:i').' UTC' : '—') : '—' }}</p>@if($problem || $a->collection_error)<p class="mt-2 max-w-xs text-xs text-amber-700 dark:text-amber-400">{{ __('resource-auto.'.($problem ?: $a->collection_error)) }}</p>@endif</td>
                            <td class="p-3">@if(in_array($a->resource->resource_type, ['google_ads','search_console'], true))<p>{{ $a->query_enabled ? __('resource-auto.enabled') : ($a->sector ? __('resource-auto.paused') : __('resource-auto.needs_mapping')) }}</p><p class="mt-1 text-xs text-gray-500">{{ $a->last_query_success_at ? $a->last_query_success_at->format('d.m.Y H:i').' UTC' : '—' }}</p>@if(isset($stats[$a->id]))<p class="mt-1 text-xs text-gray-500">{{ __('resource-auto.counts', ['added' => $stats[$a->id]->accepted_rows, 'unassigned' => $stats[$a->id]->unassigned_rows, 'excluded' => $stats[$a->id]->excluded_rows]) }}</p>@endif @if($a->query_error)<p class="mt-2 text-xs text-amber-700 dark:text-amber-400">{{ __('resource-auto.'.$a->query_error) }}</p><button type="button" wire:click="resume({{ $a->id }})" wire:loading.attr="disabled" class="mt-1 text-xs text-brand-600 underline">{{ __('resource-auto.resume') }}</button><button type="button" wire:click="closeFailed({{ $a->id }})" wire:confirm="{{ __('resource-auto.close_failed_confirm') }}" class="mt-2 block text-xs text-gray-500 underline">{{ __('resource-auto.close_failed') }}</button>@endif @else<span class="text-gray-400">—</span>@endif</td>
                            <td class="p-3"><p>{{ $sectors[$a->sector] ?? '—' }}</p>@if($a->sector)<p class="mt-1 text-xs text-gray-500">{{ count($a->service_ids ?? []) ? __('resource-auto.selected_services', ['count' => count($a->service_ids)]) : __('resource-auto.all_sector_services') }}</p>@endif</td>
                            <td class="space-y-2 p-3 text-right text-xs"><button type="button" wire:click="edit({{ $a->id }})" class="block w-full text-brand-600">{{ __('resource-auto.settings') }}</button><button type="button" wire:click="runNow({{ $a->id }})" wire:loading.attr="disabled" @disabled($problem || in_array($a->collection_status, ['planning','collecting'])) class="block w-full disabled:opacity-40">{{ __('resource-auto.run_now') }}</button>@if(in_array($a->resource->resource_type, ['google_ads','search_console'], true))<button type="button" wire:click="details({{ $a->id }})" class="block w-full">{{ __('resource-auto.details') }}</button>@endif</td>
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
                    @if($errors->any())<p role="alert" class="text-sm text-red-600">{{ $errors->first() }}</p>@endif
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="collectionEnabled" class="rounded border-gray-300 text-brand-500">{{ __('resource-auto.collection_enabled') }}</label>
                    <label class="block text-sm">{{ __('resource-auto.frequency') }}<select wire:model="intervalDays" class="mt-2 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="1">{{ __('resource-auto.daily') }}</option><option value="3">{{ __('resource-auto.three_days') }}</option></select></label>
                    @if(in_array($editor->resource->resource_type, ['google_ads', 'search_console'], true))
                        <hr class="dark:border-gray-800">
                        <label class="block text-sm">{{ __('resource-auto.sector') }} *<select wire:model.live="sector" class="mt-2 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">{{ __('resource-auto.choose_sector') }}</option>@foreach($sectors as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select></label>
                        <div><p class="text-sm">{{ __('resource-auto.services') }}</p><p class="mt-1 text-xs text-gray-500">{{ __('resource-auto.services_help') }}</p><div class="mt-3 max-h-56 space-y-2 overflow-y-auto">@foreach($services as $service)<label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="serviceIds" value="{{ $service->id }}" class="rounded border-gray-300 text-brand-500">{{ $service->primaryName?->raw_label }}</label>@endforeach</div></div>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="queryEnabled" class="rounded border-gray-300 text-brand-500">{{ __('resource-auto.query_enabled') }}</label>
                        <p class="rounded-lg bg-gray-50 p-3 text-xs text-gray-600 dark:bg-gray-950 dark:text-gray-400">{{ __('resource-auto.mapping_help') }}</p>
                    @endif
                </div>
                <div class="flex justify-end gap-3 border-t p-4 dark:border-gray-800"><button type="button" wire:click="closeEditor" class="px-4 py-2 text-sm">{{ __('resource-auto.cancel') }}</button><button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-4 py-2 text-sm text-white disabled:opacity-50">{{ __('resource-auto.save') }}</button></div>
            </form>
        </div>
    @endif
    @if($detail)
        <div class="fixed inset-0 z-[100] overflow-y-auto bg-black/40 p-4 sm:p-8" role="dialog" aria-modal="true" aria-label="{{ __('resource-auto.details') }}" x-data x-trap.inert.noscroll="true" x-on:keydown.escape.window="$wire.closeDetails()">
            <div class="mx-auto max-w-5xl space-y-4 rounded-xl bg-white p-5 dark:bg-gray-900">
                <div class="flex items-center justify-between"><h3 class="font-semibold">{{ $detail->resource->display_name }}</h3><button type="button" wire:click="closeDetails" aria-label="{{ __('resource-auto.close') }}">✕</button></div>
                <p class="text-xs text-gray-500">{{ __('resource-auto.history_help') }}</p>
                @foreach($history as $h)<div class="rounded-lg border p-3 text-xs dark:border-gray-700">#{{ $h->id }} · {{ __('resource-auto.state_'.$h->status) }} · {{ $h->created_at }} UTC <span class="ml-3">{{ __('resource-auto.counts', ['added' => $h->accepted_rows, 'unassigned' => $h->unassigned_rows, 'excluded' => $h->excluded_rows]) }} · {{ __('resource-auto.suppressed') }}: {{ $h->suppressed_rows }}</span></div>@endforeach
                @if($detail->sector)<button type="button" wire:click="recheck({{ $detail->id }})" wire:confirm="{{ __('resource-auto.recheck_confirm') }}" wire:loading.attr="disabled" class="rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700">{{ __('resource-auto.recheck') }}</button>@endif
                <select wire:model.live="decision" aria-label="{{ __('resource-auto.decision') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">{{ __('resource-auto.all') }}</option>@foreach(['assigned','unassigned','excluded','suppressed','invalid'] as $value)<option value="{{ $value }}">{{ __('resource-auto.'.$value) }}</option>@endforeach</select>
                <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="text-xs text-gray-500"><tr><th class="p-2">{{ __('resource-auto.original') }}</th><th class="p-2">{{ __('resource-auto.decision') }}</th><th class="p-2">{{ __('resource-auto.first_seen') }}</th><th class="p-2">{{ __('resource-auto.last_seen') }}</th></tr></thead><tbody>@foreach($observations as $row)<tr class="border-t dark:border-gray-800"><td class="max-w-lg break-words p-2">{{ $row->original_text }}</td><td class="p-2">{{ __('resource-auto.'.$row->decision) }}</td><td class="p-2">{{ $row->first_seen_date }}</td><td class="p-2">{{ $row->last_seen_date }}</td></tr>@endforeach</tbody></table></div>
                {{ $observations->links() }}
            </div>
        </div>
    @endif
</section>
