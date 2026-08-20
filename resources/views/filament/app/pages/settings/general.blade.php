<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            Agency operations profile
        </x-slot>

        <x-slot name="description">
            Read-only technical summary. Canonical operator Settings, users, mail, and notifications live at the product root — not in this Filament page.
        </x-slot>

        <dl class="grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Agency</dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $agency }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Product</dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $product }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Signed in as</dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $signed_in_as }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Environment</dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $environment }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Operator timezone</dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $timezone }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Default locale</dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $locale }}</dd>
            </div>
        </dl>

        <div class="mt-4">
            <a href="{{ $operator_settings_url }}" class="text-sm font-medium text-primary-600 hover:underline">
                Open operator Settings
            </a>
        </div>
    </x-filament::section>
</x-filament-panels::page>
