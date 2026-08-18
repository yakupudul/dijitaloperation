<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            {{ $title }}
        </x-slot>

        <x-slot name="description">
            Explicit operational dimensions — no overall health score
            @if(($open_alert_count ?? 0) > 0)
                · {{ $open_alert_count }} open alert(s)
            @endif
        </x-slot>

        <dl class="grid gap-4 sm:grid-cols-3">
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Signed in as</dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $user }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Environment</dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $environment }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Runtime</dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white">Laravel {{ $laravel }}</dd>
            </div>
        </dl>

        <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach(($dimensions ?? []) as $name => $dimension)
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $name }}</div>
                    <div class="mt-1 text-sm font-medium text-gray-950 dark:text-white">{{ $dimension['status'] ?? 'UNKNOWN' }}</div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $dimension['message'] ?? '' }}</div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
