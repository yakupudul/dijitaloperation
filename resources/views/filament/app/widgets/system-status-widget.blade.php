<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            {{ $title }}
        </x-slot>

        <x-slot name="description">
            {{ $status }}
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
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Laravel</dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $laravel }}</dd>
            </div>
        </dl>
    </x-filament::section>
</x-filament-widgets::widget>
