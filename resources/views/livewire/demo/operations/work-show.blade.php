<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @if ($item === null)
        <p class="text-sm text-gray-500">{{ __('operator.work.not_found') }}</p>
        <x-ta.button href="{{ route('operator.tasks') }}" wire:navigate>{{ __('operator.work.back') }}</x-ta.button>
    @else
        <div>
            <a href="{{ route('operator.tasks') }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-brand-600">← {{ __('operator.work.title') }}</a>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">{{ $item['title'] ?? 'Work item' }}</h1>
                <x-ta.badge color="light" size="sm">{{ __('operator.work.types.'.$type) }}</x-ta.badge>
            </div>
            <p class="mt-1 text-sm text-gray-500">{{ $item['brand'] ?? '' }} · {{ $item['customer'] ?? '' }}</p>
        </div>

        <x-ta.card>
            <dl class="grid gap-3 sm:grid-cols-2 text-sm">
                <div>
                    <dt class="text-gray-400">{{ __('operator.work.columns.status') }}</dt>
                    <dd class="font-medium">
                        @if ($type === 'task' && isset($item['status']))
                            {{ __('operator.work.statuses.'.$item['status']) }}
                        @else
                            {{ $item['status'] ?? '—' }}
                        @endif
                    </dd>
                </div>
                <div><dt class="text-gray-400">{{ __('operator.work.columns.owner') }}</dt><dd class="font-medium">{{ $item['owner'] ?? '—' }}</dd></div>
                <div><dt class="text-gray-400">{{ __('operator.work.columns.due') }}</dt><dd class="font-medium">{{ $item['due'] ?? '—' }}</dd></div>
                @if (! empty($item['service_label']))
                    <div><dt class="text-gray-400">{{ __('operator.commercial.service') }}</dt><dd class="font-medium">{{ $item['service_label'] }}</dd></div>
                @endif
                @if (! empty($item['description']))
                    <div class="sm:col-span-2"><dt class="text-gray-400">{{ __('operator.requests.description') }}</dt><dd class="mt-1">{{ $item['description'] }}</dd></div>
                @endif
            </dl>
        </x-ta.card>

        <div class="flex flex-wrap gap-2">
            @if ($type === 'task')
                @if (($item['status'] ?? '') === 'open')
                    <x-ta.button wire:click="startTask" size="sm">{{ __('operator.work.task_actions.start') }}</x-ta.button>
                @endif
                @if (in_array($item['status'] ?? '', ['open', 'in_progress', 'blocked'], true))
                    <x-ta.button wire:click="completeTask" size="sm" variant="outline">{{ __('operator.work.task_actions.complete') }}</x-ta.button>
                @endif
            @endif
        </div>
    @endif
</div>
