<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $dashboard['date_label'] }}</p>
            <h1 class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $dashboard['greeting'] }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $dashboard['subtitle'] }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('operator.dashboard_exec.mode_label') }}">
            <button type="button" wire:click="setMode('my_work')"
                @class([
                    'rounded-lg px-3 py-2 text-sm font-medium transition',
                    'bg-brand-500 text-white' => $mode === 'my_work',
                    'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $mode !== 'my_work',
                ])>{{ __('operator.dashboard_exec.my_work') }}</button>
            <button type="button" wire:click="setMode('agency')"
                @class([
                    'rounded-lg px-3 py-2 text-sm font-medium transition',
                    'bg-brand-500 text-white' => $mode === 'agency',
                    'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $mode !== 'agency',
                ])>{{ __('operator.dashboard_exec.agency') }}</button>
            @include('livewire.demo.partials._dashboard-actions')
        </div>
    </div>

    <section aria-labelledby="today-heading">
        <h2 id="today-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('operator.dashboard_exec.today') }}</h2>
        <div class="mt-3 grid grid-cols-2 gap-3 xl:grid-cols-4">
            @foreach ($dashboard['today'] as $metric)
                <a href="{{ route($metric['route'], $metric['route_params'] ?? []) }}" wire:navigate
                    class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-900 dark:ring-gray-800 dark:hover:bg-white/[0.03]">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ $metric['label'] }}</p>
                    <p @class([
                        'mt-2 text-3xl font-bold',
                        'text-error-600 dark:text-error-400' => ($metric['tone'] ?? '') === 'error',
                        'text-warning-600 dark:text-warning-400' => ($metric['tone'] ?? '') === 'warning',
                        'text-brand-600 dark:text-brand-400' => ($metric['tone'] ?? '') === 'info',
                        'text-gray-800 dark:text-white/90' => ! in_array($metric['tone'] ?? '', ['error', 'warning', 'info'], true),
                    ])>{{ $metric['value'] }}</p>
                </a>
            @endforeach
        </div>
    </section>

    @if (count($growthOpportunities) > 0)
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" aria-labelledby="growth-opportunities-heading">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="growth-opportunities-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('operator.opportunities.growth_section') }}</h2>
                <a href="{{ route('demo.opportunities') }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">{{ __('operator.opportunities.actions.view_all') }}</a>
            </div>
            <ul class="mt-3 space-y-2">
                @foreach ($growthOpportunities as $opp)
                    <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $opp['title'] }}</p>
                            <p class="text-xs text-gray-500">{{ $opp['brand_name'] }} · {{ $opp['service_label'] }}</p>
                        </div>
                        <a href="{{ route('demo.opportunities', ['view' => 'open']) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">{{ __('operator.actions.open') }}</a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="grid gap-4 xl:grid-cols-5">
        <section class="space-y-3 xl:col-span-3" aria-labelledby="needs-attention-heading">
            <h2 id="needs-attention-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('operator.dashboard_exec.needs_attention') }}</h2>
            <ul class="space-y-3">
                @foreach ($dashboard['needs_attention'] as $item)
                    @php
                        $badgeColor = match ($item['severity'] ?? '') {
                            'critical', 'high' => 'error',
                            'medium' => 'warning',
                            default => 'info',
                        };
                    @endphp
                    <li class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-ta.badge :color="$badgeColor" size="sm">{{ strtoupper($item['severity'] ?? '') }}</x-ta.badge>
                                    @if (! empty($item['asset_type']))
                                        <x-demo.digital-asset-mark :type="$item['asset_type']" size="sm" />
                                    @endif
                                    <span class="text-xs text-gray-400">{{ $item['source'] ?? '' }}</span>
                                </div>
                                <h3 class="mt-2 text-base font-semibold text-gray-800 dark:text-white/90">{{ $item['title'] }}</h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $item['evidence'] ?? ($item['body'] ?? '') }}</p>
                            </div>
                            <x-ta.button :href="route($item['route'], $item['route_params'] ?? [])" size="sm" variant="outline">
                                {{ $item['action_label'] ?? __('operator.actions.open') }}
                            </x-ta.button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="space-y-3 xl:col-span-2" aria-labelledby="my-work-heading">
            <div class="flex items-center justify-between gap-2">
                <h2 id="my-work-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('operator.dashboard_exec.my_work_section') }}</h2>
                <x-ta.button href="{{ route('demo.tasks') }}" size="sm" variant="outline">{{ __('operator.work.view_all') }}</x-ta.button>
            </div>
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                @forelse ($dashboard['my_work'] as $item)
                    <a href="{{ route($item['route'] ?? 'demo.tasks', $item['route_params'] ?? []) }}" wire:navigate
                        @class(['block rounded-lg bg-gray-50 px-3 py-2 text-sm hover:bg-gray-100 dark:bg-white/[0.03] dark:hover:bg-white/[0.06]', 'mt-2' => ! $loop->first])>
                        <span class="font-medium text-gray-800 dark:text-white/90">{{ $item['title'] }}</span>
                        <span class="mt-0.5 block text-xs text-gray-500">{{ $item['brand'] ?? '' }} · {{ ucfirst(str_replace('_', ' ', $item['type'] ?? 'work')) }} · {{ $item['due'] ?? '—' }}</span>
                    </a>
                @empty
                    <p class="text-sm text-gray-500">{{ __('operator.work.empty') }}</p>
                @endforelse
            </div>
        </section>
    </div>

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" aria-labelledby="capacity-heading">
        <h2 id="capacity-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('operator.capacity.title') }}</h2>
        @php $cap = $dashboard['team_capacity']; @endphp
        <div class="mt-3 flex flex-wrap gap-4 text-sm text-gray-600 dark:text-gray-300">
            <span>{{ __('operator.capacity.active') }}: <strong>{{ $cap['active_count'] }}</strong></span>
            <span>{{ __('operator.capacity.due_today') }}: <strong>{{ $cap['due_today'] }}</strong></span>
            <span>{{ __('operator.capacity.overdue') }}: <strong>{{ $cap['overdue'] }}</strong></span>
            <span>{{ __('operator.capacity.planned_hours') }}: <strong>{{ $cap['planned_hours'] }}h</strong></span>
            <x-ta.badge color="info" size="sm">{{ $cap['label'] }}</x-ta.badge>
        </div>
        <p class="mt-2 text-xs text-gray-400">{{ __('operator.capacity.thresholds_note') }}</p>
        <ul class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($cap['members'] ?? [] as $member)
                <li class="rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/[0.03]">
                    <p class="font-medium text-gray-800 dark:text-white/90">{{ $member['name'] }}</p>
                    <p class="text-xs text-gray-500">{{ $member['active'] }} {{ __('operator.capacity.active_short') }} · {{ $member['label'] }}</p>
                </li>
            @endforeach
        </ul>
    </section>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" aria-labelledby="reviews-heading">
            <h2 id="reviews-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('operator.dashboard_exec.recurring_reviews') }}</h2>
            <ul class="mt-3 space-y-2">
                @forelse ($dashboard['recurring_reviews_due'] as $review)
                    <li class="flex items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                        <div>
                            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $review['title'] }}</p>
                            <p class="text-xs text-gray-500">{{ $review['brand'] }} · {{ $review['due'] }}</p>
                        </div>
                        <a href="{{ route('demo.work.show', ['workId' => $review['id'], 'type' => 'recurring_review']) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">{{ __('operator.actions.open') }}</a>
                    </li>
                @empty
                    <li class="text-sm text-gray-500">{{ __('operator.reviews.none_due') }}</li>
                @endforelse
            </ul>
        </section>

        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" aria-labelledby="portfolio-focus-heading">
            <h2 id="portfolio-focus-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('operator.dashboard_exec.portfolio_focus') }}</h2>
            @foreach ($dashboard['portfolio_focus'] as $focus)
                <div class="mt-3 rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                    <p class="font-semibold text-gray-800 dark:text-white/90">{{ $focus['brand'] }}</p>
                    <ul class="mt-2 list-disc space-y-1 pl-4 text-sm text-gray-600 dark:text-gray-300">
                        @foreach ($focus['reasons'] as $reason)
                            <li>{{ $reason }}</li>
                        @endforeach
                    </ul>
                    <x-ta.button :href="route($focus['route'], $focus['route_params'] ?? [])" size="sm" variant="outline" class="mt-3">{{ __('operator.actions.open') }}</x-ta.button>
                </div>
            @endforeach
        </section>
    </div>

    @if (count($dashboard['system_exceptions'] ?? []) > 0)
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" aria-labelledby="exceptions-heading">
            <h2 id="exceptions-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('operator.dashboard_exec.system_exceptions') }}</h2>
            <ul class="mt-3 space-y-2">
                @foreach ($dashboard['system_exceptions'] as $integration)
                    <li class="flex items-center justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                        <div>
                            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $integration['name'] }}</p>
                            <p class="text-xs text-gray-500">{{ $integration['detail'] }}</p>
                        </div>
                        <x-ta.badge color="warning" size="sm">{{ $integration['state_label'] }}</x-ta.badge>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" aria-labelledby="outcomes-heading">
        <h2 id="outcomes-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('operator.dashboard_exec.recent_outcomes') }}</h2>
        <ul class="mt-3 space-y-3">
            @foreach ($dashboard['recent_outcomes'] as $outcome)
                <li class="flex items-start gap-3 rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-white/[0.03]">
                    @if (! empty($outcome['asset_type']))
                        <x-demo.digital-asset-mark :type="$outcome['asset_type']" size="sm" />
                    @endif
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $outcome['title'] }}</p>
                        <p class="text-xs text-gray-500">{{ $outcome['scope'] }}</p>
                    </div>
                    <x-ta.badge :color="match($outcome['tone'] ?? '') { 'good' => 'success', 'warn' => 'warning', default => 'light' }" size="sm">
                        {{ $outcome['outcome'] }}
                    </x-ta.badge>
                </li>
            @endforeach
        </ul>
    </section>
</div>
