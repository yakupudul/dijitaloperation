<section class="rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
    <button type="button" wire:click="$toggle('open')" class="flex w-full items-center justify-between gap-3 p-4 text-left" aria-expanded="{{ $open ? 'true' : 'false' }}">
        <span class="text-sm font-semibold">{{ __('query-exclusions.title') }} <span class="ml-2 rounded-full bg-gray-100 px-2 py-1 text-xs font-normal dark:bg-gray-800">{{ $activeCount }} {{ __('query-exclusions.active') }}</span></span>
        <span class="text-gray-400">{{ $open ? '−' : '+' }}</span>
    </button>
    @if($run && in_array($run->status, ['scanning', 'applying'], true))
        <div wire:poll.3s="refreshProgress" class="px-4 pb-3 text-xs text-brand-600">{{ __('query-exclusions.state_'.$run->status) }} · {{ $run->scanned }} {{ __('query-exclusions.scanned') }} · {{ $run->removed }} {{ __('query-exclusions.removed') }}</div>
    @endif
    @if($open)
        <div class="space-y-4 border-t border-gray-100 p-4 dark:border-gray-800">
            <p class="text-xs leading-5 text-gray-500">{{ __('query-exclusions.help') }}</p>
            <div class="flex flex-wrap gap-2">
                @foreach(['rules','preview','exceptions','imports'] as $key)
                    <button type="button" wire:click="changeSection('{{ $key }}')" @class(['rounded-lg px-3 py-2 text-xs font-medium', 'bg-brand-50 text-brand-700 dark:bg-brand-500/10' => $section === $key, 'text-gray-500 hover:bg-gray-50 dark:hover:bg-white/5' => $section !== $key])>{{ __('query-exclusions.tab_'.$key) }}</button>
                @endforeach
            </div>
            @if($notice)<p role="status" class="rounded-lg bg-blue-50 p-3 text-xs text-blue-800 dark:bg-blue-500/10 dark:text-blue-200">{{ $notice }}</p>@endif
            @if($errors->any())<p role="alert" class="text-sm text-red-600">{{ $errors->first() }}</p>@endif

            @if($section === 'rules')
                <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                    <form wire:submit="addRules" class="space-y-3">
                        <label class="block text-sm font-medium">{{ __('query-exclusions.add_words') }}<textarea wire:model="bulkWords" rows="6" placeholder="{{ __('query-exclusions.placeholder') }}" class="mt-2 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"></textarea></label>
                        <p class="text-xs text-gray-500">{{ __('query-exclusions.batch_help') }}</p>
                        <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{{ __('query-exclusions.add') }}</button>
                    </form>
                    <div class="space-y-3">
                        <input wire:model.live.debounce.300ms="ruleSearch" type="search" aria-label="{{ __('query-exclusions.search') }}" placeholder="{{ __('query-exclusions.search') }}" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                        <div class="divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-700">
                            @forelse($rules as $rule)
                                <div wire:key="exclusion-rule-{{ $rule->id }}" class="flex flex-wrap items-center justify-between gap-3 p-3 text-sm">
                                    @if($editingRuleId === $rule->id)
                                        <form wire:submit="saveRule" class="flex w-full flex-wrap gap-2"><input wire:model="ruleLabel" wire:keydown.escape="cancelRuleEdit" aria-label="{{ __('query-exclusions.expression') }}" class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" /><button type="submit" wire:loading.attr="disabled" class="text-brand-600">{{ __('query-exclusions.save') }}</button><button type="button" wire:click="cancelRuleEdit">{{ __('query-exclusions.cancel') }}</button></form>
                                    @else
                                        <span class="break-words font-medium">{{ $rule->label }} <span class="ml-2 text-xs font-normal text-gray-400">{{ __('query-exclusions.'.($rule->active ? 'active' : 'inactive')) }}</span></span>
                                        <div class="flex gap-3 text-xs"><button type="button" wire:click="editRule({{ $rule->id }})" class="text-brand-600">{{ __('query-exclusions.edit') }}</button><button type="button" wire:click="toggleRule({{ $rule->id }})" wire:loading.attr="disabled">{{ __('query-exclusions.'.($rule->active ? 'disable' : 'enable')) }}</button><button type="button" wire:click="deleteRule({{ $rule->id }})" wire:loading.attr="disabled" class="text-red-600">{{ __('query-exclusions.delete') }}</button></div>
                                    @endif
                                </div>
                            @empty
                                <p class="p-4 text-sm text-gray-500">{{ __('query-exclusions.no_rules') }}</p>
                            @endforelse
                        </div>
                        {{ $rules->links() }}
                    </div>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4 dark:border-gray-800"><p class="text-xs text-gray-500">{{ __('query-exclusions.scan_scope') }}</p><button type="button" wire:click="startPreview" wire:loading.attr="disabled" @disabled($activeCount === 0) class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{{ __('query-exclusions.start') }}</button></div>
            @elseif($section === 'preview')
                <div class="flex flex-wrap items-center gap-2">
                    @foreach($runs as $history)<button type="button" wire:click="openRun({{ $history->id }})" class="rounded-lg border border-gray-200 px-3 py-2 text-xs dark:border-gray-700">#{{ $history->id }} · {{ __('query-exclusions.state_'.$history->status) }}</button>@endforeach
                    <button type="button" wire:click="startPreview" wire:loading.attr="disabled" class="text-xs font-medium text-brand-600">{{ __('query-exclusions.new_scan') }}</button>
                </div>
                @if($run)
                    <div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-gray-950">
                        <p class="font-medium">#{{ $run->id }} · {{ __('query-exclusions.state_'.$run->status) }}</p>
                        <p class="mt-2 text-xs text-gray-500">{{ $run->scanned }} {{ __('query-exclusions.scanned') }} · {{ $run->matched }} {{ __('query-exclusions.matched') }} · {{ $selectedCount }} {{ __('query-exclusions.selected') }} · {{ $run->removed }} {{ __('query-exclusions.removed') }} · {{ $run->skipped }} {{ __('query-exclusions.skipped') }}</p>
                    </div>
                    @if($run->error)<p class="text-xs text-red-600">{{ $run->error }}</p>@endif
                    @if($rulesChanged)<p class="text-xs text-amber-700">{{ __('query-exclusions.rules_changed') }}</p>@endif
                    <p class="text-xs text-gray-500">{{ __('query-exclusions.preview_help') }}</p>
                    @if($run->status === 'ready')
                        <div class="flex flex-wrap gap-3 text-xs"><button type="button" wire:click="selectMatches(true)" class="text-brand-600">{{ __('query-exclusions.select_all') }}</button><button type="button" wire:click="selectMatches(false)">{{ __('query-exclusions.select_none') }}</button></div>
                    @endif
                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                        <table class="w-full text-left text-sm"><thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-950"><tr><th class="p-3">{{ __('query-exclusions.select') }}</th><th class="p-3">{{ __('query-exclusions.query') }}</th><th class="p-3">{{ __('query-exclusions.expression') }}</th><th class="p-3">{{ __('query-exclusions.result') }}</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse($matches as $match)
                                <tr wire:key="exclusion-match-{{ $match->id }}"><td class="p-3"><input type="checkbox" wire:click="toggleMatch({{ $match->id }})" @checked($match->selected) @disabled($run->status !== 'ready') aria-label="{{ $match->query_text }}" class="rounded border-gray-300 text-brand-500" /></td><td class="max-w-lg p-3"><p>{{ $match->query_text }}</p>@if($match->matched_text !== $match->query_text)<p class="mt-1 text-xs text-gray-500">{{ __('query-exclusions.original') }}: {{ $match->matched_text }}</p>@endif</td><td class="p-3 text-xs">{{ implode(', ', array_column(json_decode($match->expressions, true), 'label')) }}</td><td class="p-3 text-xs">{{ __('query-exclusions.decision_'.$match->decision) }}</td></tr>
                            @empty<tr><td colspan="4" class="p-6 text-center text-sm text-gray-500">{{ __('query-exclusions.no_matches') }}</td></tr>@endforelse
                        </tbody></table>
                    </div>
                    {{ $matches->links() }}
                    @if($run->status === 'ready')
                        <button type="button" wire:click="confirmRemoval" wire:loading.attr="disabled" @disabled($selectedCount === 0 || $rulesChanged) class="rounded-lg bg-red-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{{ __('query-exclusions.confirm', ['count' => $selectedCount]) }}</button>
                    @endif
                @else<p class="text-sm text-gray-500">{{ __('query-exclusions.no_preview') }}</p>@endif
            @elseif($section === 'exceptions')
                <p class="text-xs text-gray-500">{{ __('query-exclusions.exceptions_help') }}</p>
                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($exceptions as $exception)<div wire:key="query-exception-{{ $exception->id }}" class="flex items-center justify-between gap-3 py-3 text-sm"><span>{{ $exception->query_text }}</span><button type="button" wire:click="removeException({{ $exception->id }})" wire:loading.attr="disabled" class="text-xs text-red-600">{{ __('query-exclusions.remove_exception') }}</button></div>@empty<p class="py-3 text-sm text-gray-500">{{ __('query-exclusions.no_exceptions') }}</p>@endforelse
                </div>
                {{ $exceptions->links() }}
            @elseif($section === 'imports')
                <p class="text-xs text-gray-500">{{ __('query-exclusions.import_help') }}</p>
                <select wire:model.live="importLogId" aria-label="{{ __('query-exclusions.import') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">{{ __('query-exclusions.all_imports') }}</option>@foreach($imports as $import)<option value="{{ $import->id }}">#{{ $import->id }} · {{ $import->source_type }} · {{ $import->excluded_rows }} {{ __('query-exclusions.eliminated') }}</option>@endforeach</select>
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700"><table class="w-full text-left text-sm"><thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-950"><tr><th class="p-3">{{ __('query-exclusions.import') }}</th><th class="p-3">{{ __('query-exclusions.original') }}</th><th class="p-3">{{ __('query-exclusions.expression') }}</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($logs as $log)<tr wire:key="exclusion-log-{{ $log->id }}"><td class="p-3 text-xs">#{{ $log->import_id }} · {{ $log->row_number }}</td><td class="p-3">{{ $log->query_text }}</td><td class="p-3 text-xs">{{ implode(', ', array_column(json_decode($log->expressions, true), 'label')) }}</td></tr>@empty<tr><td colspan="3" class="p-6 text-center text-sm text-gray-500">{{ __('query-exclusions.no_logs') }}</td></tr>@endforelse
                </tbody></table></div>
                {{ $logs->links() }}
            @endif
        </div>
    @endif
</section>
