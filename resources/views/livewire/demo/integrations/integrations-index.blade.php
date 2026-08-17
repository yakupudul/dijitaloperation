<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => __('operator.integrations_ui.system'),
        'title' => __('operator.integrations_ui.title'),
        'subtitle' => __('operator.integrations_ui.subtitle'),
    ])

    <section id="site_connectors" class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ __('operator.site_connectors.title') }}</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.site_connectors.subtitle') }}</p>
            </div>
            <x-ta.button :href="route('operator.integrations.site-connectors')" size="sm">
                {{ __('operator.site_connectors.catalog') }}
            </x-ta.button>
        </div>
    </section>

    @foreach ($groups as $group)
        <section class="space-y-3" aria-labelledby="group-{{ \Illuminate\Support\Str::slug($group['group']) }}">
            <h2 id="group-{{ \Illuminate\Support\Str::slug($group['group']) }}" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ $group['group'] }}
            </h2>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($group['providers'] as $provider)
                    @php
                        $statusColor = match ($provider['state']) {
                            'connected' => 'success',
                            'configured' => 'info',
                            'needs_attention', 'authorization_expired', 'configuration_incomplete' => 'warning',
                            'not_connected', 'not_configured', 'provider_unavailable' => 'light',
                            default => 'info',
                        };
                    @endphp
                    <article class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-center gap-3">
                                <x-demo.digital-asset-mark :type="$provider['logo_type'] ?? 'website'" size="md" />
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $provider['name'] }}</h3>
                                    <x-ta.badge :color="$statusColor" size="sm">{{ $provider['state_label'] }}</x-ta.badge>
                                </div>
                            </div>
                        </div>

                        @if ($provider['resources_discovered'] !== null && ! ($provider['discovery_not_run'] ?? false))
                            <dl class="mt-4 grid grid-cols-3 gap-2 text-center text-sm">
                                <div class="rounded-lg bg-gray-50 px-2 py-2 dark:bg-white/[0.03]">
                                    <dt class="text-xs text-gray-400">{{ __('operator.integrations_ui.discovered') }}</dt>
                                    <dd class="font-semibold text-gray-800 dark:text-white/90">{{ $provider['resources_discovered'] }}</dd>
                                </div>
                                <div class="rounded-lg bg-gray-50 px-2 py-2 dark:bg-white/[0.03]">
                                    <dt class="text-xs text-gray-400">{{ __('operator.integrations_ui.bound') }}</dt>
                                    <dd class="font-semibold text-gray-800 dark:text-white/90">{{ $provider['bound'] }}</dd>
                                </div>
                                <div class="rounded-lg bg-gray-50 px-2 py-2 dark:bg-white/[0.03]">
                                    <dt class="text-xs text-gray-400">{{ __('operator.integrations_ui.available') }}</dt>
                                    <dd class="font-semibold text-gray-800 dark:text-white/90">{{ $provider['available'] }}</dd>
                                </div>
                            </dl>
                        @elseif ($provider['resources_discovered'] !== null && ($provider['discovery_not_run'] ?? false))
                            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.states.not_discovered') }}</p>
                        @elseif (! empty($provider['note']))
                            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ $provider['note'] }}</p>
                        @endif

                        <p class="mt-3 text-xs text-gray-500">{{ __('operator.integrations_ui.last_check') }} · {{ $provider['last_check'] }}</p>
                        @if (($provider['dependent_assets'] ?? 0) > 0)
                            <p class="mt-1 text-xs text-gray-500">{{ $provider['dependent_assets'] }} dependent Digital Assets</p>
                        @endif

                        <div class="mt-4">
                            <x-ta.button :href="route($provider['route'], $provider['route_params'] ?? [])" size="sm">
                                {{ $provider['manage_label'] ?? __('operator.integrations_ui.manage') }}
                            </x-ta.button>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endforeach
</div>
