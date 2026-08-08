<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            Agency operations profile
        </x-slot>

        <x-slot name="description">
            Internal Moximu workspace identity. Integration providers will land under Settings → Integrations in a later milestone.
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
        </dl>
    </x-filament::section>
</x-filament-panels::page>
