@php
    $items = collect($items ?? [])->take(5);
@endphp

@if ($items->isEmpty())
    @include('livewire.demo.partials.empty-panel', [
        'title' => 'Nothing needs attention',
        'message' => 'No attention items for this view right now.',
    ])
@else
    <ul class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
        @foreach ($items as $item)
            @php
                $severity = strtolower((string) ($item['severity'] ?? 'info'));
                $tone = match ($severity) {
                    'high', 'critical' => 'critical',
                    'medium' => 'look',
                    default => 'info',
                };
                $routeName = $item['route'] ?? null;
                $routeParams = $item['route_params'] ?? [];
                $actionLabel = $item['action_label'] ?? 'Inspect';
            @endphp
            <li class="flex items-start justify-between gap-3 py-2.5">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5">
                        <h3 class="text-sm font-medium text-gray-900 dark:text-white">{{ $item['title'] ?? '' }}</h3>
                        <x-ta.pill :tone="$tone">{{ ucfirst($severity) }}</x-ta.pill>
                        @if (! empty($item['source']))
                            <span class="text-xs text-gray-500">{{ $item['source'] }}</span>
                        @endif
                    </div>
                    @if (! empty($item['body']))
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ $item['body'] }}</p>
                    @endif
                    @if (! empty($item['evidence']))
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ $item['evidence'] }}</p>
                    @endif
                    @if (! empty($item['why']))
                        <p class="mt-0.5 text-xs text-gray-500">Why it matters: {{ $item['why'] }}</p>
                    @endif
                </div>
                @if ($routeName)
                    <x-ta.button :href="route($routeName, $routeParams)" size="sm" variant="outline">{{ $actionLabel }}</x-ta.button>
                @endif
            </li>
        @endforeach
    </ul>
@endif
