<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('demo.settings', ['section' => 'ai']) }}" wire:navigate class="text-sm text-gray-500 hover:text-brand-600">← {{ __('operator.nav.settings') }}</a>
            <h1 class="mt-2 text-2xl font-bold text-gray-800 dark:text-white/90">{{ __('operator.settings.ai.control_plane_title') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ __('operator.settings.ai.control_plane_subtitle') }}</p>
        </div>
        @include('livewire.demo.partials.demo-badge')
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
        @foreach ($routes as $route)
            <button type="button" wire:click="selectRoute('{{ $route['key'] }}')"
                @class([
                    'rounded-xl border p-4 text-left transition',
                    'border-brand-500 bg-brand-50 dark:bg-brand-500/10' => $selectedRoute === $route['key'],
                    'border-gray-200 dark:border-gray-700 hover:border-gray-300' => $selectedRoute !== $route['key'],
                ])>
                <p class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ $route['name'] }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ $route['key'] }} · {{ $route['module'] }}</p>
            </button>
        @endforeach
    </div>

    @if ($selectedRoute !== '')
        <form wire:submit="save" class="space-y-4 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ __('operator.settings.ai.provider_order') }}</h2>
            @foreach ($steps as $index => $step)
                <div class="grid gap-3 sm:grid-cols-[1fr_1fr_auto]" wire:key="step-{{ $index }}">
                    <label class="block text-sm">
                        <span class="text-gray-500">{{ __('operator.settings.ai.provider') }}</span>
                        <select wire:model="steps.{{ $index }}.provider" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700">
                            @foreach ($providers as $provider)
                                <option value="{{ $provider }}">{{ \App\Support\Ai\AiProviderCatalog::label($provider) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm">
                        <span class="text-gray-500">{{ __('operator.settings.ai.model') }}</span>
                        <input wire:model="steps.{{ $index }}.model" type="text" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" />
                    </label>
                    <div class="flex items-end">
                        <x-ta.button type="button" wire:click="removeStep({{ $index }})" size="sm" variant="outline">{{ __('operator.actions.remove') }}</x-ta.button>
                    </div>
                </div>
            @endforeach
            <div class="flex flex-wrap gap-2">
                <x-ta.button type="button" wire:click="addStep" size="sm" variant="outline">{{ __('operator.settings.ai.add_provider') }}</x-ta.button>
                <x-ta.button type="submit" size="sm">{{ __('operator.actions.save') }}</x-ta.button>
            </div>
        </form>
    @endif
</div>
