@php
    $statusLabel = static function (string $status): string {
        return match ($status) {
            'needs_attention' => 'Needs attention',
            'in_progress' => 'In progress',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    };

    $statusColor = static function (string $status): string {
        return match ($status) {
            'completed' => 'success',
            'running' => 'info',
            'partial' => 'warning',
            'queued' => 'light',
            'failed' => 'error',
            'needs_attention' => 'error',
            default => 'light',
        };
    };
@endphp

<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Operations',
        'title' => 'Activity',
        'subtitle' => 'Imports, scans, and analysis jobs — Demo Mode progress timeline.',
        'actions' => '<a href="'.e(route('demo.integrations.meta')).'" class="inline-flex"><span class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-3.5 py-2 text-sm font-medium text-white hover:bg-brand-600">Meta import</span></a>',
    ])

    <div class="space-y-3">
        @foreach ($activity as $row)
            <x-ta.card>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold text-gray-800 dark:text-white/90">{{ $row['title'] }}</h3>
                            <x-ta.badge :color="$statusColor($row['status'])" size="sm">{{ $statusLabel($row['status']) }}</x-ta.badge>
                        </div>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $row['detail'] }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <p class="text-xs text-gray-400">{{ $row['when'] }}</p>
                        @if (! empty($row['link']))
                            <x-ta.button :href="route($row['link'])" size="sm" variant="outline">
                                {{ $row['link_label'] ?? 'Open' }}
                            </x-ta.button>
                        @elseif (str_contains(strtolower($row['title']), 'meta'))
                            <x-ta.button href="{{ route('demo.integrations.meta') }}" size="sm" variant="outline">Open Meta import</x-ta.button>
                        @endif
                    </div>
                </div>
            </x-ta.card>
        @endforeach
    </div>
</div>
