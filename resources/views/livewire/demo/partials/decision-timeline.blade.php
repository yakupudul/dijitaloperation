@php
    $events = $events ?? $timeline ?? [];
    $title = $title ?? 'Decision timeline';
    $limit = isset($limit) ? (int) $limit : null;
    $rows = collect($events);
    if ($limit !== null) {
        $rows = $rows->take($limit);
    }
@endphp

@if ($rows->isEmpty())
    @include('livewire.demo.partials.empty-panel', [
        'title' => 'No timeline events',
        'message' => 'Decision history will appear here as findings and tasks progress.',
    ])
@else
    <div>
        @if ($title !== '')
            <h2 class="mb-4 font-semibold text-gray-800 dark:text-white/90">{{ $title }}</h2>
        @endif
        <ol class="relative space-y-4 border-l border-gray-200 pl-6 dark:border-gray-800">
            @foreach ($rows as $event)
                <li class="relative">
                    <span class="absolute -left-[1.625rem] mt-1.5 h-3 w-3 rounded-full bg-brand-500 ring-4 ring-white dark:ring-gray-900"></span>
                    <p class="text-xs text-gray-400">
                        {{ $event['date'] ?? '' }}
                        @if (! empty($event['actor']))
                            · {{ $event['actor'] }}
                        @endif
                    </p>
                    <p class="font-medium text-gray-800 dark:text-white/90">{{ $event['event'] ?? '' }}</p>
                    @if (! empty($event['detail']))
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $event['detail'] }}</p>
                    @endif
                    @if (! empty($event['provenance']))
                        <div class="mt-1">
                            @include('livewire.demo.partials.provenance-badge', ['label' => $event['provenance']])
                        </div>
                    @endif
                </li>
            @endforeach
        </ol>
    </div>
@endif
