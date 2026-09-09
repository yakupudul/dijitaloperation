<div class="space-y-5 dark:text-gray-200">
    <header class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-xs text-gray-500">{{ __('manual-clusters.library') }}</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ __('manual-clusters.title') }}</h1>
            <p class="mt-2 text-sm text-gray-500">{{ __('manual-clusters.intro') }}</p>
        </div>
        <a href="{{ route('operator.library.search-queries') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-gray-700">{{ __('manual-clusters.query_library') }}</a>
    </header>
    @if($message)<div role="status" class="rounded-lg bg-blue-50 p-3 text-sm text-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ $message }}</div>@endif
    @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">{{ $errors->first() }}</div>@endif
    @if($poll)<div wire:poll.4s="refreshWork" class="flex items-center justify-between rounded-lg border border-blue-200 px-4 py-3 text-sm"><span>{{ __('manual-clusters.background') }}</span><button type="button" wire:click="$set('historyOpen', true)" class="font-medium underline">{{ __('manual-clusters.history') }}</button></div>@endif

    <div class="grid items-start gap-5 lg:grid-cols-[280px_minmax(0,1fr)]">
        <aside class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <div class="space-y-3 border-b border-gray-200 p-4 dark:border-gray-800">
                <label class="block text-xs font-medium">{{ __('manual-clusters.sector') }}
                    <select wire:model.live="sector" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                        <option value="">{{ __('manual-clusters.all_sectors') }}</option>
                        <option value="__none">{{ __('manual-clusters.uncategorized') }}</option>
                        @foreach($sectorOptions as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                    </select>
                </label>
                <input type="search" wire:model.live.debounce.350ms="treeSearch" aria-label="{{ __('manual-clusters.find_cluster') }}" placeholder="{{ __('manual-clusters.find_cluster') }}" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
            </div>
            <nav aria-label="{{ __('manual-clusters.title') }}" class="max-h-[65vh] space-y-1 overflow-y-auto p-2">
                @forelse($services as $root)
                    <div wire:key="root-{{ $root->id }}">
                        <div class="group flex items-center gap-1 rounded-lg {{ $serviceId === $root->id ? 'bg-blue-50 dark:bg-blue-950' : 'hover:bg-gray-50 dark:hover:bg-gray-800' }}">
                            <button type="button" wire:click="toggleBranch({{ $root->id }})" aria-label="{{ __('manual-clusters.toggle_branch') }}" aria-expanded="{{ $serviceId === $root->id && !$treeCollapsed ? 'true' : 'false' }}" class="ml-1 rounded px-2 py-2 text-gray-500">{{ $serviceId === $root->id && !$treeCollapsed ? '▾' : '▸' }}</button>
                            <button type="button" wire:click="openCluster({{ $root->id }})" class="min-w-0 flex-1 px-3 py-3 text-left text-sm" aria-current="{{ $serviceId === $root->id && !$clusterId ? 'page' : 'false' }}">
                                <span class="block truncate font-medium">{{ $root->primaryName?->raw_label ?? '#'.$root->id }}</span>
                                <span class="mt-1 block text-xs text-gray-500">{{ __('manual-clusters.root_counts', ['direct' => $direct[$root->id] ?? 0, 'total' => $totals[$root->id] ?? 0]) }}</span>
                            </button>
                            <button type="button" wire:click="createCluster({{ $root->id }})" title="{{ __('manual-clusters.add_child') }}" aria-label="{{ __('manual-clusters.add_child') }}" class="mr-2 rounded-md px-2 py-1 text-xl text-blue-600 hover:bg-blue-100 focus:bg-blue-100">+</button>
                        </div>
                        @if($serviceId === $root->id && !$treeCollapsed)
                            <div class="ml-5 space-y-1 border-l border-gray-200 py-1 pl-2 dark:border-gray-700">
                                @forelse($children as $node)
                                    <button type="button" wire:key="child-{{ $node->id }}" wire:click="openCluster({{ $root->id }}, {{ $node->id }})"
                                        @disabled($node->status !== 'active')
                                        class="flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm disabled:opacity-50 {{ $clusterId === $node->id ? 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-200' : 'hover:bg-gray-50 dark:hover:bg-gray-800' }}">
                                        <span class="truncate">{{ $node->name }}</span><span class="text-xs text-gray-500">{{ $childCounts[$node->id] ?? 0 }}</span>
                                    </button>
                                @empty<p class="p-2 text-xs text-gray-400">{{ __('manual-clusters.no_children') }}</p>@endforelse
                            </div>
                        @endif
                    </div>
                @empty<p class="p-4 text-sm text-gray-500">{{ __('manual-clusters.no_services') }}</p>@endforelse
            </nav>
            <div class="border-t border-gray-200 p-3 dark:border-gray-800">{{ $services->links() }}</div>
        </aside>

        <main class="min-w-0 space-y-4">
            @if(!$service)
                <div class="rounded-xl border border-dashed border-gray-300 bg-white p-12 text-center dark:border-gray-700 dark:bg-gray-900">
                    <h2 class="font-semibold">{{ __('manual-clusters.select_service') }}</h2>
                    <p class="mt-2 text-sm text-gray-500">{{ __('manual-clusters.select_help') }}</p>
                </div>
            @else
                <section class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                    <div class="space-y-4 border-b border-gray-200 p-5 dark:border-gray-800">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs text-gray-500">{{ $service->primaryName?->raw_label }} @if($child) / {{ __('manual-clusters.child') }} @else / {{ __('manual-clusters.main') }} @endif</p>
                                <div class="mt-1 flex items-center gap-2"><h2 class="break-words text-xl font-semibold">{{ $child?->name ?? $service->primaryName?->raw_label }}</h2>
                                    @if($child)<button type="button" wire:click="editCluster" aria-label="{{ __('manual-clusters.edit_cluster') }}" class="rounded px-2 py-1 text-gray-400 hover:text-blue-600">✎</button>@endif
                                </div>
                                @if($child?->description)<p class="mt-2 whitespace-pre-line text-sm text-gray-500">{{ $child->description }}</p>@endif
                                <p class="mt-2 text-xs text-gray-500">{{ __('manual-clusters.membership_note') }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2 text-xs">
                                <button type="button" wire:click="createCluster({{ $serviceId }})" @disabled($busy) class="rounded-lg border border-gray-300 px-3 py-2 disabled:opacity-40 dark:border-gray-700">+ {{ __('manual-clusters.add_child') }}</button>
                                <button type="button" wire:click="$toggle('targetsOpen')" class="rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700">{{ __('manual-clusters.target_pages') }}</button>
                                <button type="button" wire:click="$toggle('historyOpen')" class="rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700">{{ __('manual-clusters.history') }}</button>
                                @if($child)<button type="button" wire:click="prepareAction('retire')" @disabled($busy) class="rounded-lg border border-gray-300 px-3 py-2 disabled:opacity-40 dark:border-gray-700">{{ __('manual-clusters.merge_remove') }}</button>@endif
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <input type="search" wire:model.live.debounce.350ms="search" aria-label="{{ __('manual-clusters.search') }}" placeholder="{{ __('manual-clusters.search') }}" class="min-w-48 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                            <button type="button" wire:click="$toggle('advanced')" class="rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700">{{ __('manual-clusters.filters') }}</button>
                            <button type="button" wire:click="clearFilters" class="px-2 py-2 text-xs text-gray-500 underline">{{ __('manual-clusters.clear_filters') }}</button>
                        </div>
                        <div class="flex flex-wrap items-center gap-4 text-xs">
                            @if(!$clusterId)<label class="flex items-center gap-2"><input type="checkbox" wire:model.live="includeChildren" class="rounded border-gray-300 text-blue-600">{{ __('manual-clusters.include_children') }}</label>@endif
                            <label class="flex items-center gap-2"><input type="checkbox" wire:model.live="newOnly" class="rounded border-gray-300 text-blue-600">{{ __('manual-clusters.new_only') }}</label>
                            <span class="text-gray-500">{{ __('manual-clusters.active_only') }}</span>
                        </div>
                        @if($advanced)
                            <div class="grid gap-3 rounded-lg bg-gray-50 p-3 sm:grid-cols-2 dark:bg-gray-950">
                                <label class="text-xs">{{ __('manual-clusters.include') }}<textarea wire:model.live.debounce.500ms="includeWords" rows="2" placeholder="{{ __('manual-clusters.words_hint') }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></textarea></label>
                                <label class="text-xs">{{ __('manual-clusters.exclude') }}<textarea wire:model.live.debounce.500ms="excludeWords" rows="2" placeholder="{{ __('manual-clusters.words_hint') }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></textarea></label>
                                <label class="text-xs">{{ __('manual-clusters.match') }}<select wire:model.live="matchMode" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"><option value="any">{{ __('manual-clusters.any') }}</option><option value="all">{{ __('manual-clusters.all') }}</option></select></label>
                                <div class="grid grid-cols-2 gap-2">
                                    <label class="text-xs">{{ __('manual-clusters.from') }}<input type="date" wire:model.live="dateFrom" class="mt-1 w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900"></label>
                                    <label class="text-xs">{{ __('manual-clusters.to') }}<input type="date" wire:model.live="dateTo" class="mt-1 w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900"></label>
                                </div>
                            </div>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-4 py-3 text-xs dark:border-gray-800">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="font-medium">{{ number_format($queries->total()) }} {{ __('manual-clusters.queries') }}</span>
                            <button type="button" wire:click="selectPage" class="text-blue-600 hover:underline">{{ __('manual-clusters.select_page') }}</button>
                            <button type="button" wire:click="selectFiltered" class="text-blue-600 hover:underline">{{ __('manual-clusters.select_all') }}</button>
                        </div>
                        <div class="flex items-center gap-2">
                            <select wire:model.live="sort" aria-label="{{ __('manual-clusters.sort') }}" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950"><option value="az">A–Z</option><option value="za">Z–A</option><option value="newest">{{ __('manual-clusters.newest') }}</option></select>
                            <select wire:model.live="perPage" aria-label="{{ __('manual-clusters.per_page') }}" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950"><option value="25">25</option><option value="50">50</option><option value="100">100</option></select>
                        </div>
                    </div>
                    @if($allSelected || count($selected))
                        <div class="flex flex-wrap items-center gap-3 bg-blue-50 px-4 py-3 text-sm dark:bg-blue-950">
                            <span>{{ __('manual-clusters.selected', ['count' => $allSelected ? $queries->total() : count($selected)]) }}</span>
                            <button type="button" wire:click="prepareAction('move')" @disabled($busy) class="rounded-lg bg-blue-600 px-3 py-2 font-medium text-white disabled:opacity-40">{{ __('manual-clusters.move') }}</button>
                            <button type="button" wire:click="prepareAction('keep')" @disabled($busy) class="rounded-lg border border-blue-300 px-3 py-2 disabled:opacity-40">{{ __('manual-clusters.keep') }}</button>
                            <button type="button" wire:click="clearSelection" class="ml-auto text-xs underline">{{ __('manual-clusters.clear_selection') }}</button>
                        </div>
                    @endif
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-gray-200 text-xs text-gray-500 dark:border-gray-800"><tr><th class="w-10 px-4 py-3"><span class="sr-only">{{ __('manual-clusters.select') }}</span></th><th class="px-2 py-3">{{ __('manual-clusters.query') }}</th><th class="px-4 py-3">{{ __('manual-clusters.cluster') }}</th><th class="px-4 py-3">{{ __('manual-clusters.added') }}</th></tr></thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @forelse($queries as $row)
                                    <tr wire:key="query-{{ $row->membership_id }}" class="group hover:bg-gray-50 dark:hover:bg-gray-800">
                                        <td class="px-4 py-3">@if($allSelected)<input type="checkbox" checked disabled aria-label="{{ __('manual-clusters.select_query', ['query' => $row->canonical_text]) }}" class="rounded border-gray-300 text-blue-600">@else<input type="checkbox" wire:model.live="selected" value="{{ $row->membership_id }}" aria-label="{{ __('manual-clusters.select_query', ['query' => $row->canonical_text]) }}" class="rounded border-gray-300 text-blue-600">@endif</td>
                                        <td class="min-w-64 px-2 py-3">
                                            @if($editingQueryId === $row->id)
                                                <form wire:submit="saveQuery" class="space-y-2"><input wire:model="editingText" aria-label="{{ __('manual-clusters.query') }}" class="w-full rounded border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><p class="text-xs text-amber-700 dark:text-amber-300">{{ __('manual-clusters.rename_note') }}</p><div class="flex gap-3 text-xs"><button type="submit" wire:loading.attr="disabled" class="font-medium text-blue-600">{{ __('manual-clusters.save') }}</button><button type="button" wire:click="cancelQuery">{{ __('manual-clusters.cancel') }}</button></div></form>
                                            @else
                                                <div class="flex items-center gap-2"><span class="break-words">{{ $row->canonical_text }}</span><button type="button" wire:click="editQuery({{ $row->id }})" aria-label="{{ __('manual-clusters.edit_query') }}" class="rounded px-2 py-1 text-gray-400 opacity-100 hover:text-blue-600 focus:opacity-100 sm:opacity-0 sm:group-hover:opacity-100">✎</button>@if(!$row->cluster_reviewed_at)<span class="rounded bg-blue-50 px-2 py-0.5 text-xs text-blue-700 dark:bg-blue-950 dark:text-blue-200">{{ __('manual-clusters.new') }}</span>@endif</div>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-xs text-gray-500">{{ $row->cluster_name ?? __('manual-clusters.main') }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($row->created_at)->format('d.m.Y') }}</td>
                                    </tr>
                                @empty<tr><td colspan="4" class="px-6 py-12 text-center text-gray-500">{{ __('manual-clusters.no_queries') }}</td></tr>@endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="space-y-3 border-t border-gray-200 p-4 dark:border-gray-800">
                        {{ $queries->links() }}
                        <div class="flex flex-wrap gap-3 text-xs">
                            <button type="button" wire:click="export(false)" wire:loading.attr="disabled" @disabled($busy || !$queries->total()) class="font-medium text-blue-600 disabled:opacity-40">{{ __('manual-clusters.export_filtered') }}</button>
                            <button type="button" wire:click="export(true)" wire:loading.attr="disabled" @disabled($busy) class="text-blue-600 disabled:opacity-40">{{ __('manual-clusters.export_structure') }}</button>
                        </div>
                    </div>
                </section>

                @if($targetsOpen)
                    <section class="space-y-4 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
                        <h3 class="font-semibold">{{ __('manual-clusters.target_pages') }}</h3>
                        <p class="text-xs text-gray-500">{{ __('manual-clusters.target_note') }}</p>
                        @foreach($targets as $target)
                            <button type="button" wire:click="chooseWebsite({{ $target->digital_asset_id }})" class="block w-full rounded-lg border border-gray-200 p-3 text-left text-sm dark:border-gray-700"><span class="font-medium">{{ $target->brand_name }} · {{ $target->site_name }}</span><span class="mt-1 block break-all text-xs text-gray-500">{{ $target->url }}</span></button>
                        @endforeach
                        <input type="search" wire:model.live.debounce.350ms="siteSearch" aria-label="{{ __('manual-clusters.find_site') }}" placeholder="{{ __('manual-clusters.find_site') }}" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                        <p class="text-xs text-gray-500">{{ __('manual-clusters.site_limit') }}</p>
                        <div class="max-h-48 space-y-1 overflow-y-auto">
                            @forelse($sites as $site)<button type="button" wire:click="chooseWebsite({{ $site->id }})" class="block w-full rounded-lg px-3 py-2 text-left text-sm {{ $targetAssetId === $site->id ? 'bg-blue-50 text-blue-700 dark:bg-blue-950' : 'hover:bg-gray-50 dark:hover:bg-gray-800' }}">{{ $site->brand?->name }} · {{ $site->name }}<span class="ml-2 text-xs text-gray-500">{{ $site->primary_url ?: $site->domain }}</span></button>@empty<p class="text-sm text-gray-500">{{ __('manual-clusters.no_sites') }}</p>@endforelse
                        </div>
                        @if($targetAssetId)
                            <form wire:submit="saveTarget" class="space-y-3">
                                <label class="block text-xs">{{ __('manual-clusters.target_url') }}<input type="url" wire:model="targetUrl" placeholder="https://" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"></label>
                                <p class="text-xs text-gray-500">{{ __('manual-clusters.target_clear') }}</p>
                                <button type="submit" wire:loading.attr="disabled" @disabled($busy) class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white disabled:opacity-40">{{ __('manual-clusters.save') }}</button>
                            </form>
                        @endif
                    </section>
                @endif

                @if($historyOpen)
                    <section class="space-y-4 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex items-center justify-between"><h3 class="font-semibold">{{ __('manual-clusters.history') }}</h3><button type="button" wire:click="refreshWork" class="text-xs text-blue-600">{{ __('manual-clusters.refresh') }}</button></div>
                        <p class="text-xs text-gray-500">{{ __('manual-clusters.undo_note') }}</p>
                        @forelse($operations as $op)
                            @php($meta = json_decode($op->metadata, true))
                            <div wire:key="operation-{{ $op->id }}" class="space-y-2 border-b border-gray-100 pb-4 text-sm dark:border-gray-800">
                                <div class="flex flex-wrap items-center justify-between gap-2"><span class="font-medium">#{{ $op->id }} · {{ __('manual-clusters.kind_'.$op->kind) }}</span><span class="text-xs">{{ __('manual-clusters.status_'.$op->status) }}</span></div>
                                <p class="text-xs text-gray-500">{{ $meta['source_name'] ?? $meta['main'] }} → {{ $meta['target_name'] ?? $meta['main'] }} · {{ $op->actor_name }} · {{ \Illuminate\Support\Carbon::parse($op->created_at)->format('d.m.Y H:i') }}</p>
                                <p class="text-xs">{{ __('manual-clusters.progress', ['processed' => $op->processed, 'total' => $op->total, 'changed' => $op->changed, 'skipped' => $op->skipped]) }}</p>
                                @if(!empty($meta['source_targets']))<details class="text-xs text-gray-500"><summary class="cursor-pointer">{{ __('manual-clusters.previous_targets') }}</summary>@foreach($meta['source_targets'] as $oldTarget)<p class="mt-2 break-all">{{ $oldTarget['name'] }} · {{ $oldTarget['url'] }}</p>@endforeach</details>@endif
                                @if($op->error)<p class="text-xs text-red-600">{{ __($op->error) }}</p>@endif
                                <div class="flex flex-wrap gap-4 text-xs text-blue-600">
                                    <button type="button" wire:click="inspectOperation({{ $op->id }})">{{ __('manual-clusters.details') }}</button>
                                    @if($op->file_path && $op->status === 'completed')<a href="{{ route('operator.library.manual-clusters.download', ['operation' => $op->id]) }}">{{ __('manual-clusters.download') }}</a>@endif
                                    @if(in_array($op->kind, ['move','keep','retire']) && in_array($op->status, ['completed','partial']) && !$op->undo_id)
                                        <button type="button" wire:click="undo({{ $op->id }})" wire:confirm="{{ __('manual-clusters.undo_confirm') }}" wire:loading.attr="disabled" @disabled($busy) class="disabled:opacity-40">{{ __('manual-clusters.undo') }}</button>
                                    @endif
                                    @if($op->status === 'failed')<button type="button" wire:click="resume({{ $op->id }})" wire:loading.attr="disabled">{{ __('manual-clusters.resume') }}</button><button type="button" wire:click="stopFailed({{ $op->id }})" wire:confirm="{{ __('manual-clusters.stop_confirm') }}" wire:loading.attr="disabled">{{ __('manual-clusters.stop') }}</button>@endif
                                </div>
                            </div>
                        @empty<p class="text-sm text-gray-500">{{ __('manual-clusters.no_history') }}</p>@endforelse
                        {{ $operations->links() }}
                    </section>
                @endif
            @endif
        </main>
    </div>

    @if($clusterEditor)
        <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-gray-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="cluster-editor-title" x-data x-trap.inert.noscroll="true" @keydown.escape.window="$wire.set('clusterEditor', false)">
            <form wire:submit="saveCluster" class="w-full max-w-lg space-y-4 rounded-xl bg-white p-6 dark:bg-gray-900">
                <h2 id="cluster-editor-title" class="text-lg font-semibold">{{ $editingClusterId ? __('manual-clusters.edit_cluster') : __('manual-clusters.add_child') }}</h2>
                @if($errors->any())<p role="alert" class="text-sm text-red-600">{{ $errors->first() }}</p>@endif
                <label class="block text-sm">{{ __('manual-clusters.cluster_name') }}<input wire:model="clusterName" maxlength="255" required class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"></label>
                <label class="block text-sm">{{ __('manual-clusters.description') }}<textarea wire:model="clusterDescription" maxlength="2000" rows="3" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"></textarea></label>
                <div class="flex justify-end gap-3"><button type="button" wire:click="$set('clusterEditor', false)" class="px-3 py-2 text-sm">{{ __('manual-clusters.cancel') }}</button><button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white disabled:opacity-50">{{ __('manual-clusters.save') }}</button></div>
            </form>
        </div>
    @endif

    @if($pendingKind !== '')
        <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-gray-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="move-title" x-data x-trap.inert.noscroll="true" @keydown.escape.window="$wire.cancelAction()">
            <div class="w-full max-w-lg space-y-4 rounded-xl bg-white p-6 dark:bg-gray-900">
                <h2 id="move-title" class="text-lg font-semibold">{{ __('manual-clusters.kind_'.$pendingKind) }}</h2>
                @if($errors->any())<p role="alert" class="text-sm text-red-600">{{ $errors->first() }}</p>@endif
                <p class="text-sm">{{ __('manual-clusters.action_count', ['count' => $pendingCount]) }}</p>
                @if($pendingKind === 'retire')<p class="text-xs text-amber-700 dark:text-amber-300">{{ __('manual-clusters.retire_note') }}</p>@endif
                @if($pendingKind !== 'keep')
                    <label class="block text-sm">{{ __('manual-clusters.destination') }}
                        <select wire:model.live="targetClusterId" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                            <option value="0">{{ __('manual-clusters.main') }} · {{ $service?->primaryName?->raw_label }}</option>
                            @foreach($children as $node)@if($node->status === 'active' && ($pendingKind !== 'retire' || $node->id !== $clusterId))<option value="{{ $node->id }}">{{ $node->name }}</option>@endif @endforeach
                        </select>
                    </label>
                    <form wire:submit="createTargetCluster" class="flex gap-2"><input wire:model="newTargetName" maxlength="255" required placeholder="{{ __('manual-clusters.new_child_name') }}" aria-label="{{ __('manual-clusters.new_child_name') }}" class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><button type="submit" wire:loading.attr="disabled" class="rounded-lg border border-gray-300 px-3 text-sm dark:border-gray-700">+ {{ __('manual-clusters.create') }}</button></form>
                @else<p class="text-sm">{{ __('manual-clusters.keep_note') }}</p>@endif
                <p class="text-xs text-gray-500">{{ __('manual-clusters.snapshot_note') }}</p>
                <div class="flex justify-end gap-3"><button type="button" wire:click="cancelAction" class="px-3 py-2 text-sm">{{ __('manual-clusters.cancel') }}</button><button type="button" wire:click="confirmAction" wire:loading.attr="disabled" class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white disabled:opacity-50">{{ __('manual-clusters.confirm') }}</button></div>
            </div>
        </div>
    @endif

    @if($receipts)
        <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-gray-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="receipts-title" x-data x-trap.inert.noscroll="true" @keydown.escape.window="$wire.closeReceipts()">
            <div class="max-h-[85vh] w-full max-w-3xl space-y-4 overflow-y-auto rounded-xl bg-white p-6 dark:bg-gray-900">
                <div class="flex items-center justify-between"><h2 id="receipts-title" class="font-semibold">#{{ $receiptOperationId }} · {{ __('manual-clusters.details') }}</h2><button type="button" wire:click="closeReceipts" class="text-sm">{{ __('manual-clusters.close') }}</button></div>
                <select wire:model.live="receiptDecision" aria-label="{{ __('manual-clusters.status') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="all">{{ __('manual-clusters.all') }}</option>@foreach(['pending','changed','skipped','exported'] as $decision)<option value="{{ $decision }}">{{ __('manual-clusters.decision_'.$decision) }}</option>@endforeach</select>
                <p class="text-xs text-gray-500">{{ __('manual-clusters.skipped_note') }}</p>
                <table class="w-full text-left text-sm"><thead class="text-xs text-gray-500"><tr><th class="py-2">{{ __('manual-clusters.query') }}</th><th>{{ __('manual-clusters.previous_cluster') }}</th><th>{{ __('manual-clusters.status') }}</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">@foreach($receipts as $receipt)<tr><td class="py-3 pr-3">{{ $receipt->query_text }}</td><td class="pr-3 text-xs">{{ $receipt->cluster_name }}</td><td class="text-xs">{{ __('manual-clusters.decision_'.$receipt->decision) }}</td></tr>@endforeach</tbody></table>
                {{ $receipts->links() }}
            </div>
        </div>
    @endif
</div>

