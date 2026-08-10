<x-filament-panels::page>
    <div class="mb-6 flex flex-wrap gap-3 text-sm">
        <span class="font-semibold text-gray-950 dark:text-white">Routes</span>
        <span class="text-gray-400">·</span>
        <a href="{{ \App\Filament\App\Clusters\Settings\Pages\AgentProfilesSettings::getUrl() }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">Agent Profiles</a>
        <span class="text-gray-400">·</span>
        <a href="{{ \App\Filament\App\Clusters\Settings\Pages\SkillLibrarySettings::getUrl() }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">Skill Library</a>
    </div>

    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">
                AI providers
            </x-slot>
            <x-slot name="description">
                Choose which AI providers power each MoxDOP workflow and define safe fallbacks. Configure credentials in Integrations.
            </x-slot>

            <ul class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($providers as $provider)
                    <li class="flex items-center justify-between gap-4 py-3 first:pt-0 last:pb-0">
                        <div>
                            <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $provider['label'] }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $provider['status_label'] }}</p>
                        </div>
                        <span
                            @class([
                                'inline-flex h-2.5 w-2.5 rounded-full',
                                'bg-success-500' => $provider['status'] === 'connected',
                                'bg-warning-500' => in_array($provider['status'], ['configured', 'needs_attention'], true),
                                'bg-gray-300 dark:bg-gray-600' => $provider['status'] === 'not_configured',
                                'bg-danger-500' => $provider['status'] === 'disabled',
                            ])
                            aria-hidden="true"
                        ></span>
                    </li>
                @endforeach
            </ul>

            <div class="mt-4">
                <a
                    href="{{ $integrations_url }}"
                    class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                >
                    Manage integrations →
                </a>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                {{ $route_name }}
            </x-slot>
            <x-slot name="description">
                Effective order after eligibility filtering. Unconfigured providers are skipped before any AI call.
            </x-slot>

            <ol class="space-y-3">
                @foreach ($resolved_preview as $index => $step)
                    <li class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-gray-950 dark:text-white">
                                    {{ $index + 1 }}. {{ \App\Support\Ai\AiProviderCatalog::label($step['provider']) }}
                                </p>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ \App\Support\Ai\AiProviderCatalog::humanModelLabel($step['model']) }}
                                </p>
                            </div>
                            <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ $step['status_label'] }}
                            </span>
                        </div>
                    </li>
                @endforeach
            </ol>
        </x-filament::section>

        <form wire:submit="save" class="space-y-6">
            {{ $this->form }}

            <div class="flex justify-end">
                <x-filament::button type="submit">
                    Save route
                </x-filament::button>
            </div>
        </form>
    </div>
</x-filament-panels::page>
