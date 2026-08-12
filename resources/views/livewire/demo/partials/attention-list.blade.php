@php
    $items = collect($items ?? [])->take(5);
@endphp

@if ($items->isEmpty())
    @include('livewire.demo.partials.empty-panel', [
        'title' => 'Nothing needs attention',
        'message' => 'No attention items for this view right now.',
    ])
@else
    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($items as $item)
            @php
                $severity = strtolower((string) ($item['severity'] ?? 'info'));
                $badgeColor = match ($severity) {
                    'high' => 'error',
                    'critical' => 'error',
                    'medium' => 'warning',
                    default => 'info',
                };
                $routeName = $item['route'] ?? null;
                $routeParams = $item['route_params'] ?? [];
                $actionLabel = $item['action_label'] ?? 'Inspect';
            @endphp
            <x-ta.card>
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ta.badge :color="$badgeColor" size="sm">{{ ucfirst($severity) }}</x-ta.badge>
                            @if (! empty($item['source']))
                                <span class="text-xs text-gray-400">{{ $item['source'] }}</span>
                            @endif
                        </div>
                        <h3 class="mt-2 text-base font-semibold text-gray-800 dark:text-white/90">{{ $item['title'] ?? '' }}</h3>
                        @if (! empty($item['body']))
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $item['body'] }}</p>
                        @endif
                        @if (! empty($item['evidence']))
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $item['evidence'] }}</p>
                        @endif
                        @if (! empty($item['why']))
                            <p class="mt-2 text-xs text-gray-400">Why it matters: {{ $item['why'] }}</p>
                        @endif
                    </div>
                    @if ($routeName)
                        <x-ta.button :href="route($routeName, $routeParams)" size="sm" variant="outline">{{ $actionLabel }}</x-ta.button>
                    @endif
                </div>
            </x-ta.card>
        @endforeach
    </div>
@endif
