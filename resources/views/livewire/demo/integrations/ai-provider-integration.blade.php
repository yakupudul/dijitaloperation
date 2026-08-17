<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs uppercase tracking-wide text-gray-400">Integration</p>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">{{ $providerLabel }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <x-ta.badge :color="$configured ? 'info' : 'light'" size="sm">{{ $statusLabel }}</x-ta.badge>
            </div>
            <p class="mt-1 text-sm text-gray-500">{{ $supportNote }}</p>
        </div>
        <x-ta.button href="{{ route('demo.integrations') }}" size="sm" variant="outline">All Integrations</x-ta.button>
    </div>

    <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Configuration</h2>
        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-gray-400">API key</dt>
                <dd class="font-medium text-gray-800 dark:text-white/90">{{ $statusLabel }}</dd>
            </div>
            <div>
                <dt class="text-gray-400">Last validation</dt>
                <dd class="font-medium text-gray-800 dark:text-white/90">{{ $lastTestedAt ?? 'Not tested' }}</dd>
            </div>
        </dl>
    </div>

    @if ($canManageCredentials)
        <form wire:submit.prevent="saveConfiguration" class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">API key</h3>
            <div class="mt-4">
                <x-ta.form.field label="API Key" :helper="$keyConfigured ? 'Configured — leave blank to keep the stored value.' : 'Write-only. Never shown after save.'" :error="$errors->first('api_key')">
                    <input wire:model="apiKey" type="password" autocomplete="new-password" placeholder="{{ $keyConfigured ? 'Replace credential' : '' }}"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-800 shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </x-ta.form.field>
                @if ($keyConfigured)
                    <label class="mt-3 flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                        <input type="checkbox" wire:model="clearApiKey" class="rounded border-gray-300" />
                        Clear stored API key
                    </label>
                @endif
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <x-ta.button type="submit" size="sm">Save credentials</x-ta.button>
                <x-ta.button type="button" wire:click="testConfiguration" size="sm" variant="outline">Test configuration</x-ta.button>
                @if ($configured || $keyConfigured)
                    <x-ta.button type="button" wire:click="askRemove" size="sm" variant="outline">Remove credentials</x-ta.button>
                @endif
            </div>
        </form>
        @if ($confirmRemove)
            <div class="rounded-xl bg-warning-50 p-4 ring-1 ring-inset ring-warning-200 dark:bg-warning-500/10 dark:ring-warning-500/30">
                <p class="text-sm font-medium text-gray-800 dark:text-white/90">Remove {{ $providerLabel }} API key?</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">This deletes the stored key. Historical AI runs are preserved.</p>
                <div class="mt-3 flex gap-2">
                    <x-ta.button wire:click="removeConfiguration" size="sm">Confirm remove</x-ta.button>
                    <x-ta.button wire:click="cancelRemove" size="sm" variant="outline">Cancel</x-ta.button>
                </div>
            </div>
        @endif
    @else
        <p class="text-sm text-gray-500">Only administrators can view or change {{ $providerLabel }} credentials.</p>
    @endif
</div>
