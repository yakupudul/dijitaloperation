<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => __('operator.activity.eyebrow'),
        'title' => __('operator.activity.title'),
        'subtitle' => __('operator.activity.subtitle'),
    ])

    <div class="flex flex-wrap items-end gap-3 rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500" for="activity-actor">{{ __('operator.activity.actor') }}</label>
            <select id="activity-actor" wire:model.live="actor" class="rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700">
                <option value="all">{{ __('operator.activity.actor_all') }}</option>
                <option value="human">{{ __('operator.activity.actor_human') }}</option>
                <option value="system">{{ __('operator.activity.actor_system') }}</option>
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500" for="activity-status">{{ __('operator.activity.status') }}</label>
            <select id="activity-status" wire:model.live="status" class="rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700">
                <option value="all">{{ __('operator.activity.status_all') }}</option>
                <option value="success">{{ __('operator.activity.status_success') }}</option>
                <option value="running">{{ __('operator.activity.status_running') }}</option>
                <option value="failed">{{ __('operator.activity.status_failed') }}</option>
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500" for="activity-period">{{ __('operator.activity.date_range') }}</label>
            <select id="activity-period" wire:model.live="period" class="rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700">
                @foreach ($periodOptions as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <ol class="relative space-y-4 border-l border-gray-200 pl-6 dark:border-gray-800">
        @forelse ($timeline as $event)
            <li class="relative">
                <span class="absolute -left-[1.55rem] top-1.5 flex h-3 w-3 rounded-full bg-brand-500 ring-4 ring-white dark:ring-gray-900" aria-hidden="true"></span>
                <article class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex min-w-0 items-start gap-3">
                            @if (! empty($event['asset_type']))
                                <x-demo.digital-asset-mark :type="$event['asset_type']" size="sm" />
                            @endif
                            <div>
                                <p class="text-xs text-gray-400">{{ $event['when'] }} · {{ $event['time'] }}</p>
                                <h3 class="mt-1 text-sm font-semibold text-gray-800 dark:text-white/90">{{ $event['title'] }}</h3>
                                <p class="mt-1 text-sm text-gray-500">{{ $event['scope'] }}</p>
                                <p class="mt-1 text-xs text-gray-500">{{ $event['detail'] }}</p>
                                <p class="mt-2 text-xs text-gray-400">{{ $event['actor'] }} · {{ ucfirst($event['actor_kind']) }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-ta.badge :color="match($event['status']) { 'success' => 'success', 'running' => 'info', 'failed' => 'error', default => 'light' }" size="sm">
                                {{ $event['status'] }}
                            </x-ta.badge>
                            @if (! empty($event['route']))
                                <x-ta.button :href="route($event['route'], $event['route_params'] ?? [])" size="sm" variant="outline">Open</x-ta.button>
                            @endif
                        </div>
                    </div>
                </article>
            </li>
        @empty
            <li>
                @include('livewire.demo.partials.empty-panel', [
                    'title' => __('operator.activity.empty'),
                    'message' => __('operator.activity.subtitle'),
                ])
            </li>
        @endforelse
    </ol>

    <details class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <summary class="cursor-pointer text-sm font-medium text-gray-700 dark:text-gray-300">Technical Run history (subset)</summary>
        <ul class="mt-3 space-y-2 text-sm text-gray-600 dark:text-gray-300">
            @foreach ($legacyRuns as $row)
                <li class="flex justify-between gap-3">
                    <span>{{ $row['title'] }} · {{ $row['detail'] }}</span>
                    <span class="text-xs text-gray-400">{{ $row['status'] }} · {{ $row['when'] }}</span>
                </li>
            @endforeach
        </ul>
    </details>
</div>
