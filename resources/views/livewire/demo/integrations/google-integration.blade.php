<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <x-demo.digital-asset-mark type="google_ads" size="lg" />
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-400">Integration</p>
                <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">{{ $integration['name'] }}</h1>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    @php
                        $stateColor = match ($integration['state'] ?? '') {
                            'connected' => 'success',
                            'needs_attention', 'authorization_expired', 'configuration_incomplete' => 'warning',
                            'configured' => 'info',
                            default => 'light',
                        };
                    @endphp
                    <x-ta.badge :color="$stateColor" size="sm">{{ $integration['state_label'] }}</x-ta.badge>
                    <span class="text-xs text-gray-500">Last check · {{ $integration['last_check'] }}</span>
                    @if (! empty($integration['next_action_label']))
                        <span class="text-xs text-gray-500">Next · {{ $integration['next_action_label'] }}</span>
                    @endif
                </div>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-ta.button href="{{ route('demo.integrations') }}" size="sm" variant="outline">All Integrations</x-ta.button>
            @if (($integration['actions']['authorize'] ?? false) && ! empty($integration['authorize_url']) && ($integration['auth_status'] ?? '') !== 'connected')
                <x-ta.button :href="$integration['authorize_url']" size="sm">Connect Google</x-ta.button>
            @elseif (($integration['actions']['authorize'] ?? false) && empty($integration['integration_id']))
                <x-ta.button wire:click="bootstrapAndConnect" size="sm">Configure & Connect</x-ta.button>
            @elseif (($integration['actions']['reauthorize'] ?? false) && ! empty($integration['reauthorize_url']))
                <x-ta.button :href="$integration['reauthorize_url']" size="sm" variant="outline">Re-authorize</x-ta.button>
            @endif
            @if ($integration['actions']['disconnect'] ?? false)
                <x-ta.button wire:click="openDisconnect" size="sm" variant="danger">Revoke Google access…</x-ta.button>
            @endif
        </div>
    </div>

    <div class="flex flex-wrap gap-2" role="tablist" aria-label="Google integration sections">
        @foreach ([
            'overview' => 'Overview',
            'connectors' => 'Connectors',
            'configuration' => 'Configuration',
            'resources' => 'Resources & Bindings',
            'activity' => 'Activity',
        ] as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')"
                @class([
                    'rounded-lg px-3 py-2 text-sm font-medium',
                    'bg-brand-500 text-white' => $tab === $key,
                    'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $tab !== $key,
                ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'overview')
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-xs text-gray-400">Resources discovered</p>
                <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $integration['resources_discovered'] }}</p>
                @if (($integration['resources_discovered'] ?? 0) === 0)
                    <p class="mt-1 text-xs text-gray-500">Discovery not run</p>
                @endif
            </div>
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-xs text-gray-400">Bound</p>
                <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $integration['bound'] }}</p>
            </div>
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-xs text-gray-400">Available / unbound</p>
                <p class="mt-1 text-2xl font-bold text-warning-600 dark:text-warning-400">{{ $integration['available'] }}</p>
            </div>
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-xs text-gray-400">Dependent Digital Assets</p>
                <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $integration['dependent_assets'] }}</p>
            </div>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-xs text-gray-400">Collection</p>
                <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">{{ $integration['collection_state_label'] ?? 'Collection not run' }}</p>
            </div>
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-xs text-gray-400">Data availability</p>
                <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">{{ $integration['data_state_label'] ?? 'No data available' }}</p>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($integration['resource_groups'] as $group)
                <a href="{{ route('demo.integrations.connector', ['connector' => $group['connector']]) }}" wire:navigate class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-900 dark:ring-gray-800 dark:hover:bg-white/[0.03]">
                    <div class="flex items-center gap-2">
                        <x-demo.digital-asset-mark :type="$group['type']" size="sm" />
                        <h3 class="font-semibold text-gray-800 dark:text-white/90">{{ $group['label'] }}</h3>
                    </div>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                        {{ $group['accounts'] }} accounts · {{ $group['bound'] }} bound · {{ $group['available'] }} available
                    </p>
                    <p class="mt-2 text-xs font-medium text-brand-600 dark:text-brand-400">Open connector →</p>
                </a>
            @endforeach
        </div>
    @elseif ($tab === 'connectors')
        <p class="text-sm text-gray-500 dark:text-gray-400">One Google Integration authorization. Product-specific Connectors manage discovery, binding, data freshness and sync — not specialist analytics.</p>
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($integration['connectors'] as $connector)
                <a href="{{ route('demo.integrations.connector', ['connector' => $connector['ui_slug']]) }}" wire:navigate class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-900 dark:ring-gray-800 dark:hover:bg-white/[0.03]">
                    <div class="flex items-center gap-2">
                        <x-demo.digital-asset-mark :type="$connector['ui_slug'] === 'google-ads' ? 'google_ads' : $connector['ui_slug']" size="md" />
                        <div>
                            <h3 class="font-semibold text-gray-900 dark:text-white">{{ $connector['label'] }} Connector</h3>
                            <p class="text-xs text-gray-500">{{ $connector['auth_status_label'] ?? 'Not authorized' }} · {{ $connector['discovered'] }} resources · {{ $connector['bound'] }} bound · {{ $connector['available'] }} available</p>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @elseif ($tab === 'configuration')
        <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Configuration</h2>
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-gray-400">Application configuration</dt>
                    <dd class="font-medium text-gray-800 dark:text-white/90">{{ $integration['app_configuration_label'] ?? 'Incomplete' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-400">Authorization</dt>
                    <dd class="font-medium text-gray-800 dark:text-white/90">{{ $integration['auth_status_label'] ?? 'Not configured' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-400">Granted capabilities</dt>
                    <dd class="font-medium text-gray-800 dark:text-white/90">{{ $integration['granted_scopes_label'] ?? 'Not granted' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-400">Ads developer token</dt>
                    <dd class="font-medium text-gray-800 dark:text-white/90">{{ $integration['ads_developer_token_label'] ?? 'Developer token missing' }}</dd>
                </div>
                @if (! empty($integration['account_email']))
                    <div>
                        <dt class="text-gray-400">Authorized account</dt>
                        <dd class="font-medium text-gray-800 dark:text-white/90">{{ $integration['account_email'] }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="text-gray-400">Write actions</dt>
                    <dd class="font-medium text-gray-800 dark:text-white/90">{{ $integration['write_actions'] ?? 'Disabled — MoxDOP is read / bind only' }}</dd>
                </div>
            </dl>
            <p class="mt-4 text-xs text-gray-500">OAuth lifecycle productionization is Prompt 14. Secrets are never shown here.</p>
        </div>
    @elseif ($tab === 'resources')
        <div class="space-y-4">
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Available / unbound resources</h2>
                <p class="mt-1 text-sm text-gray-500">Discovered provider resources not yet bound to a Digital Asset. Binding is an internal MoxDOP action (Prompt 16).</p>
                @if (empty($integration['unbound_resources']))
                    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                        @if (($integration['resources_discovered'] ?? 0) === 0)
                            No resources discovered yet.
                        @else
                            Resources available — none unbound, or none selected.
                        @endif
                    </p>
                @else
                    <ul class="mt-4 space-y-3">
                        @foreach ($integration['unbound_resources'] as $resource)
                            <li class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-gray-50 px-4 py-3 dark:bg-white/[0.03]">
                                <div class="flex items-start gap-3">
                                    <x-demo.digital-asset-mark :type="$resource['type']" size="sm" />
                                    <div>
                                        <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $resource['type_label'] }}</p>
                                        <p class="text-sm text-gray-700 dark:text-gray-300">{{ $resource['name'] }}</p>
                                        <p class="text-xs text-gray-500">Property ID · {{ $resource['external_id'] }}</p>
                                        <p class="mt-1 text-xs text-warning-600 dark:text-warning-400">{{ $resource['status_label'] }}</p>
                                    </div>
                                </div>
                                <x-ta.button wire:click="bindResource('{{ $resource['id'] }}')" size="sm" variant="outline">Bind (later)</x-ta.button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Bindings</h2>
                <p class="mt-1 text-sm text-gray-500">Provider Resource → Binding → Digital Asset (not Asset Relationship).</p>
                @if (empty($integration['bindings']))
                    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">No resources selected / bound yet.</p>
                @else
                    <ul class="mt-4 divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($integration['bindings'] as $binding)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div class="text-sm">
                                    <p class="font-medium text-gray-800 dark:text-white/90">{{ $binding['resource'] }}</p>
                                    <p class="text-xs text-gray-500">↓ {{ $binding['binding'] }} ↓</p>
                                    <p class="text-gray-600 dark:text-gray-300">{{ $binding['asset'] }}</p>
                                </div>
                                <x-ta.button :href="route($binding['route'])" size="sm" variant="outline">Open Asset</x-ta.button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    @else
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Integration activity</h2>
            <ul class="mt-4 space-y-3">
                @foreach ($integration['activity'] as $event)
                    <li class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-white/[0.03]">
                        <div>
                            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $event['event'] }}</p>
                            <p class="text-xs text-gray-500">{{ $event['actor'] }} · {{ $event['when'] }}</p>
                        </div>
                        <x-ta.badge :color="match($event['status']) { 'success' => 'success', 'failed', 'needs_attention' => 'warning', default => 'light' }" size="sm">
                            {{ $event['status'] }}
                        </x-ta.badge>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($confirmDisconnect)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4" role="dialog" aria-modal="true" aria-labelledby="disconnect-title">
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl dark:bg-gray-900">
                <h2 id="disconnect-title" class="text-lg font-semibold text-gray-800 dark:text-white/90">Revoke Google access?</h2>
                <p class="mt-2 text-sm text-gray-500">This revokes the entire Google OAuth grant for MoxDOP — not a single Connector. Historical resources, bindings, and collected data are preserved.</p>
                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                    @foreach ($integration['disconnect_impact'] as $label => $count)
                        <li>{{ $count }} {{ $label }}</li>
                    @endforeach
                </ul>
                <div class="mt-5 flex flex-wrap justify-end gap-2">
                    <x-ta.button wire:click="cancelDisconnect" size="sm" variant="outline">Cancel</x-ta.button>
                    <x-ta.button wire:click="confirmDisconnectAction" size="sm" variant="danger">Revoke Google access</x-ta.button>
                </div>
            </div>
        </div>
    @endif
</div>
