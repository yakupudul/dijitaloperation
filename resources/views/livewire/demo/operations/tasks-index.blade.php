@php
    $viewFilters = [
        'my' => __('operator.work.views.my'),
        'all' => __('operator.work.views.all'),
        'tasks' => __('operator.work.views.tasks'),
        'client_requests' => __('operator.work.views.client_requests'),
        'recurring_reviews' => __('operator.work.views.recurring_reviews'),
        'approvals' => __('operator.work.views.approvals'),
        'waiting_on_client' => __('operator.work.views.waiting_on_client'),
        'qa_required' => __('operator.work.views.qa_required'),
        'overdue' => __('operator.work.views.overdue'),
        'due_today' => __('operator.work.views.due_today'),
        'completed' => __('operator.work.views.completed'),
        'unassigned' => __('operator.work.views.unassigned'),
    ];
@endphp

<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => __('operator.work.eyebrow'),
        'title' => __('operator.work.title'),
        'subtitle' => __('operator.work.subtitle'),
    ])

    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs uppercase tracking-wide text-gray-400">{{ __('operator.work.glance.due_today') }}</p>
            <p class="mt-1 text-2xl font-bold text-warning-600 dark:text-warning-400">{{ $glance['due_today'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs uppercase tracking-wide text-gray-400">{{ __('operator.work.glance.overdue') }}</p>
            <p class="mt-1 text-2xl font-bold text-error-600 dark:text-error-400">{{ $glance['overdue'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs uppercase tracking-wide text-gray-400">{{ __('operator.work.glance.waiting_on_client') }}</p>
            <p class="mt-1 text-2xl font-bold text-brand-600 dark:text-brand-400">{{ $glance['waiting_on_client'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs uppercase tracking-wide text-gray-400">{{ __('operator.work.glance.qa_required') }}</p>
            <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $glance['qa_required'] }}</p>
        </div>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-2" role="tablist" aria-label="{{ __('operator.work.views_label') }}">
            @foreach ($viewFilters as $key => $label)
                <button type="button" wire:click="setView('{{ $key }}')"
                    @class([
                        'rounded-lg px-3 py-2 text-sm font-medium transition',
                        'bg-brand-500 text-white' => $view === $key,
                        'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $view !== $key,
                    ])>{{ $label }}</button>
            @endforeach
        </div>
        <div class="flex gap-2" role="group" aria-label="Layout">
            <button type="button" wire:click="setViewMode('list')" @class(['rounded-lg px-3 py-2 text-sm font-medium', 'bg-brand-500 text-white' => ($viewMode ?? 'list') === 'list', 'ring-1 ring-inset ring-gray-300 dark:ring-gray-700' => ($viewMode ?? 'list') !== 'list'])>List</button>
            <button type="button" wire:click="setViewMode('board')" @class(['rounded-lg px-3 py-2 text-sm font-medium', 'bg-brand-500 text-white' => ($viewMode ?? 'list') === 'board', 'ring-1 ring-inset ring-gray-300 dark:ring-gray-700' => ($viewMode ?? 'list') !== 'board'])>Board</button>
        </div>
    </div>

    @if (($viewMode ?? 'list') === 'board')
        @php
            $boardColumns = [
                'open' => 'Open',
                'in_progress' => 'In progress',
                'blocked' => 'Blocked',
                'completed' => 'Completed',
            ];
            $boardItems = collect($workItems)->where('type', 'task');
        @endphp
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($boardColumns as $statusKey => $statusLabel)
                <section class="rounded-xl bg-white p-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $statusLabel }}</h3>
                    <ul class="mt-3 space-y-2">
                        @foreach ($boardItems->where('status', $statusKey) as $item)
                            <li class="rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/[0.03]">
                                <a href="{{ $item['detail_url'] ?? route($item['route'] ?? 'operator.tasks', $item['route_params'] ?? []) }}" wire:navigate class="font-medium text-gray-800 hover:text-brand-600 dark:text-white/90">{{ $item['title'] }}</a>
                                <p class="text-xs text-gray-500">{{ $item['owner'] }} · {{ $item['due'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @else
    <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <table class="min-w-full text-sm">
            <caption class="sr-only">{{ __('operator.work.title') }}</caption>
            <thead>
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    <th scope="col" class="px-4 py-3 text-left text-xs uppercase text-gray-400">{{ __('operator.work.columns.title') }}</th>
                    <th scope="col" class="hidden px-4 py-3 text-left text-xs uppercase text-gray-400 md:table-cell">{{ __('operator.work.columns.type') }}</th>
                    <th scope="col" class="hidden px-4 py-3 text-left text-xs uppercase text-gray-400 lg:table-cell">{{ __('operator.work.columns.brand') }}</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs uppercase text-gray-400">{{ __('operator.work.columns.owner') }}</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs uppercase text-gray-400">{{ __('operator.work.columns.due') }}</th>
                    <th scope="col" class="hidden px-4 py-3 text-left text-xs uppercase text-gray-400 lg:table-cell">{{ __('operator.work.columns.status') }}</th>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('operator.actions.open') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($workItems as $item)
                    <tr class="border-b border-gray-50 dark:border-gray-800/60">
                        <td class="px-4 py-3">
                            <p class="font-medium text-gray-800 dark:text-white/90">{{ $item['title'] }}</p>
                            @if ($item['waiting_on_client'] ?? false)
                                <x-ta.badge color="warning" size="sm" class="mt-1">{{ __('operator.work.badges.waiting_on_client') }}</x-ta.badge>
                            @endif
                            @if (($item['in_scope'] ?? true) === false)
                                <x-ta.badge color="light" size="sm" class="mt-1">{{ __('operator.commercial.outside_scope') }}</x-ta.badge>
                            @endif
                            @if ($item['qa_required'] ?? false)
                                <x-ta.badge color="info" size="sm" class="mt-1">{{ __('operator.qa.required') }}</x-ta.badge>
                            @endif
                        </td>
                        <td class="hidden px-4 py-3 text-gray-500 md:table-cell">{{ __('operator.work.types.'.$item['type']) }}</td>
                        <td class="hidden px-4 py-3 text-gray-500 lg:table-cell">{{ $item['brand'] }}</td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $item['owner'] }}</td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $item['due'] }}</td>
                        <td class="hidden px-4 py-3 lg:table-cell">
                            <x-ta.badge color="light" size="sm">{{ $item['status'] }}</x-ta.badge>
                        </td>
                        <td class="px-4 py-3 text-right">
                            @php
                                $detailUrl = $item['detail_url'] ?? route($item['route'] ?? 'operator.work.show', $item['route_params'] ?? ['workId' => $item['id'], 'type' => $item['type'] ?? 'task']);
                            @endphp
                            <x-ta.button :href="$detailUrl" size="sm" variant="outline" wire:navigate>{{ __('operator.actions.open') }}</x-ta.button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-500">{{ __('operator.work.empty') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @endif

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.capacity.title') }}</h2>
        <p class="mt-1 text-sm text-gray-500">{{ __('operator.capacity.team_label') }}: <strong>{{ $capacity['label'] }}</strong> · {{ $capacity['planned_hours'] }}h {{ __('operator.capacity.planned') }}</p>
    </section>
</div>
