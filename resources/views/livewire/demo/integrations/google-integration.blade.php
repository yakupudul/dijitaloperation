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
            <x-ta.button href="{{ route('operator.integrations') }}" size="sm" variant="outline">All Integrations</x-ta.button>
            @if (! ($integration['actions']['authorize'] ?? false))
                <x-ta.button wire:click="setTab('configuration')" size="sm">{{ __('operator.integrations_ui.configure') }}</x-ta.button>
            @endif
            @if (($integration['actions']['authorize'] ?? false) && ! empty($integration['authorize_url']) && ($integration['auth_status'] ?? '') !== 'connected')
                <x-ta.button :href="$integration['authorize_url']" size="sm">Connect Google</x-ta.button>
            @elseif (($integration['actions']['authorize'] ?? false) && empty($integration['integration_id']))
                <x-ta.button wire:click="bootstrapAndConnect" size="sm">Configure & Connect</x-ta.button>
            @elseif (($integration['actions']['reauthorize'] ?? false) && ! empty($integration['reauthorize_url']))
                <x-ta.button :href="$integration['reauthorize_url']" size="sm" variant="outline">Re-authorize</x-ta.button>
            @endif
            @if ($integration['actions']['discover'] ?? false)
                <x-ta.button wire:click="discoverResources" size="sm" variant="outline">
                    {{ ($integration['resources_discovered'] ?? 0) > 0 ? 'Refresh Resources' : 'Discover Resources' }}
                </x-ta.button>
            @endif
            @if ($integration['actions']['collect'] ?? false)
                <x-ta.button wire:click="collectData" size="sm" wire:loading.attr="disabled">
                    Collect Data
                </x-ta.button>
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
                @if ($integration['discovery_not_run'] ?? true)
                    <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">—</p>
                    <p class="mt-1 text-xs text-gray-500">Not discovered yet</p>
                @else
                    <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $integration['resources_discovered'] }}</p>
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

        @if (! empty($preflight))
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Google initial collection</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    {{ $preflight['summary']['eligible_resources'] ?? 0 }} bound production resources ready
                    · Historical coverage varies by dataset
                </p>
                @if (! empty($preflight['summary']['by_connector']))
                    <ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                        @foreach ($preflight['summary']['by_connector'] as $provider => $counts)
                            <li>
                                {{ str_replace('_', ' ', $provider) }}
                                · {{ $counts['eligible'] ?? 0 }} eligible
                                / {{ $counts['bound'] ?? 0 }} bound
                            </li>
                        @endforeach
                    </ul>
                @endif
                @foreach ($preflight['connectors'] ?? [] as $connector)
                    @if (($connector['status'] ?? '') !== 'ready')
                        <p class="mt-2 text-xs text-warning-600 dark:text-warning-400">
                            {{ $connector['provider_or_source'] ?? $connector['capability'] }} · {{ $connector['label'] }}
                        </p>
                    @endif
                @endforeach
                @if ($preflight['can_start'] ?? false)
                    <p class="mt-2 text-xs text-gray-500">This collection continues in the background. You may leave this page.</p>
                @elseif (! empty($preflight['message']))
                    <p class="mt-2 text-xs text-gray-500">{{ $preflight['message'] }}</p>
                @endif
            </div>
        @endif

        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($integration['resource_groups'] as $group)
                <a href="{{ route('operator.integrations.connector', ['connector' => $group['connector']]) }}" wire:navigate class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-900 dark:ring-gray-800 dark:hover:bg-white/[0.03]">
                    <div class="flex items-center gap-2">
                        <x-demo.digital-asset-mark :type="$group['type']" size="sm" />
                        <h3 class="font-semibold text-gray-800 dark:text-white/90">{{ $group['label'] }}</h3>
                    </div>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                        {{ $group['accounts'] }}
                        {{ $group['capability'] === 'google_business_profile' ? 'locations' : ($group['capability'] === 'google_ads' ? 'accounts' : 'properties') }}
                        · {{ $group['bound'] }} bound · {{ $group['available'] }} available
                    </p>
                    <p class="mt-1 text-xs text-gray-500">{{ $group['discovery_status_label'] ?? 'Discovery not run' }}</p>
                    @if (in_array($group['discovery_status'] ?? '', ['external_access_required', 'setup_required', 'scope_required'], true) && ! empty($group['discovery_message']))
                        <p class="mt-1 text-xs text-warning-600 dark:text-warning-400">{{ $group['discovery_message'] }}</p>
                    @endif
                    <p class="mt-2 text-xs font-medium text-brand-600 dark:text-brand-400">Open connector →</p>
                </a>
            @endforeach
        </div>
    @elseif ($tab === 'connectors')
        <p class="text-sm text-gray-500 dark:text-gray-400">One Google Integration authorization. Product-specific Connectors manage discovery, binding, data freshness and sync — not specialist analytics.</p>
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($integration['connectors'] as $connector)
                <a href="{{ route('operator.integrations.connector', ['connector' => $connector['ui_slug']]) }}" wire:navigate class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-900 dark:ring-gray-800 dark:hover:bg-white/[0.03]">
                    <div class="flex items-center gap-2">
                        <x-demo.digital-asset-mark :type="$connector['ui_slug'] === 'google-ads' ? 'google_ads' : $connector['ui_slug']" size="md" />
                        <div>
                            <h3 class="font-semibold text-gray-900 dark:text-white">{{ $connector['label'] }} Connector</h3>
                            <p class="text-xs text-gray-500">{{ $connector['auth_status_label'] ?? 'Not authorized' }} · {{ $connector['discovery_status_label'] ?? 'Discovery not run' }} · {{ $connector['discovered'] }} resources · {{ $connector['bound'] }} bound · {{ $connector['available'] }} available</p>
                            @if (($connector['discovery_status'] ?? '') === 'external_access_required')
                                <p class="mt-1 text-xs text-warning-600 dark:text-warning-400">API access required</p>
                            @endif
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @elseif ($tab === 'configuration')
        <div class="space-y-4">
            <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Configuration</h2>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-gray-400">Application credentials</dt>
                        <dd class="font-medium text-gray-800 dark:text-white/90">{{ $integration['app_configuration_label'] ?? 'Not configured' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-400">Google Ads developer token</dt>
                        <dd class="font-medium text-gray-800 dark:text-white/90">{{ $integration['ads_developer_token_label'] ?? 'Missing' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-400">Authorization</dt>
                        <dd class="font-medium text-gray-800 dark:text-white/90">{{ $integration['auth_status_label'] ?? 'Not authorized' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-400">Granted capabilities</dt>
                        <dd class="font-medium text-gray-800 dark:text-white/90">{{ $integration['granted_scopes_label'] ?? 'Not granted' }}</dd>
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
                <p class="mt-4 text-xs text-gray-500">Secrets are write-only and never shown after save. Application credentials are not the same as Google authorization.</p>
            </div>

            @if ($canManageCredentials ?? false)
                <form wire:submit.prevent="saveGoogleConfiguration" class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Application credentials</h3>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <x-ta.form.field label="Google OAuth Client ID" helper="Not a secret. Visible after save." :error="$errors->first('client_id')">
                            <input wire:model="googleClientId" type="text" autocomplete="off"
                                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-800 shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                        </x-ta.form.field>
                        <x-ta.form.field label="Google OAuth Client Secret" :helper="$googleClientSecretConfigured ? 'Configured — leave blank to keep the stored value.' : 'Write-only. Never shown after save.'" :error="$errors->first('client_secret')">
                            <input wire:model="googleClientSecret" type="password" autocomplete="new-password" placeholder="{{ $googleClientSecretConfigured ? 'Replace credential' : '' }}"
                                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-800 shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                        </x-ta.form.field>
                        @if ($googleClientSecretConfigured ?? false)
                            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300 md:col-span-2">
                                <input type="checkbox" wire:model="clearGoogleClientSecret" class="rounded border-gray-300" />
                                Clear stored Client Secret
                            </label>
                        @endif
                        <x-ta.form.field label="Google Ads Developer Token" :helper="$googleDeveloperTokenConfigured ? 'Configured — leave blank to keep the stored value.' : 'Write-only. Required for Google Ads discovery.'" :error="$errors->first('developer_token')" class="md:col-span-2">
                            <input wire:model="googleDeveloperToken" type="password" autocomplete="new-password" placeholder="{{ $googleDeveloperTokenConfigured ? 'Replace credential' : '' }}"
                                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-800 shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                        </x-ta.form.field>
                        @if ($googleDeveloperTokenConfigured ?? false)
                            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300 md:col-span-2">
                                <input type="checkbox" wire:model="clearGoogleDeveloperToken" class="rounded border-gray-300" />
                                Clear stored Ads developer token
                            </label>
                        @endif
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <x-ta.button type="submit" size="sm">Save credentials</x-ta.button>
                        <x-ta.button type="button" wire:click="testGoogleConfiguration" size="sm" variant="outline">Test configuration</x-ta.button>
                        @if (($integration['app_configuration_label'] ?? '') === 'Configured' || ($googleClientSecretConfigured ?? false))
                            <x-ta.button type="button" wire:click="askRemoveGoogleCredentials" size="sm" variant="outline">Remove credentials</x-ta.button>
                        @endif
                    </div>
                </form>
                @if ($confirmRemoveGoogleCredentials ?? false)
                    <div class="rounded-xl bg-warning-50 p-4 ring-1 ring-inset ring-warning-200 dark:bg-warning-500/10 dark:ring-warning-500/30">
                        <p class="text-sm font-medium text-gray-800 dark:text-white/90">Remove Google application credentials?</p>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">This deletes stored Client ID, Client Secret, and developer token. Discovered resources, bindings, and history are preserved.</p>
                        <div class="mt-3 flex gap-2">
                            <x-ta.button wire:click="removeGoogleConfiguration" size="sm">Confirm remove</x-ta.button>
                            <x-ta.button wire:click="cancelRemoveGoogleCredentials" size="sm" variant="outline">Cancel</x-ta.button>
                        </div>
                    </div>
                @endif
            @else
                <p class="text-sm text-gray-500">Only administrators can view or change Google application credentials.</p>
            @endif
        </div>
    @elseif ($tab === 'resources')
        <div class="space-y-4">
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Available / unbound resources</h2>
                <p class="mt-1 text-sm text-gray-500">Discovered provider resources not yet bound. Human confirmation is required — discovery never binds automatically. Binding does not start collection.</p>
                @if (empty($integration['unbound_resources']))
                    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                        @if (($integration['resources_discovered'] ?? 0) === 0)
                            No resources discovered yet.
                        @else
                            Resources available — none unbound, or none selectable.
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
                                @if ($integration['actions']['bind'] ?? false)
                                    <x-ta.button wire:click="bindResource('{{ $resource['id'] }}')" size="sm">Select & bind…</x-ta.button>
                                @else
                                    <x-ta.button size="sm" variant="outline" disabled>Connect Google first</x-ta.button>
                                @endif
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

        <div class="mox-collection-monitor-embed" wire:key="google-collection-monitoring">
            @livewire(\App\Livewire\Collection\MonitoringPanel::class)
        </div>
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

    @if ($showBindModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4" role="dialog" aria-modal="true" aria-labelledby="bind-title">
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl dark:bg-gray-900">
                <h2 id="bind-title" class="text-lg font-semibold text-gray-800 dark:text-white/90">Confirm Google resource binding</h2>
                <p class="mt-2 text-sm text-gray-500">Human confirmation is required. This creates a technical Binding only — it does not start collection or create Asset Relationships.</p>

                <div class="mt-4 space-y-3">
                    <div>
                        <label class="text-xs font-medium text-gray-500" for="bind-brand">Customer / Brand</label>
                        <select id="bind-brand" wire:model.live="brandId" class="mt-1 w-full rounded-lg border-gray-200 text-sm dark:border-gray-700 dark:bg-gray-800">
                            <option value="">Select Brand…</option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand['id'] }}">{{ $brand['label'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <fieldset class="space-y-2">
                        <legend class="text-xs font-medium text-gray-500">Target</legend>
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="radio" wire:model.live="bindMode" value="create_asset" class="text-brand-500" />
                            Create new Digital Asset
                            @if ($preferred_asset_type)
                                <span class="text-xs text-gray-400">({{ $preferred_asset_type }})</span>
                            @endif
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="radio" wire:model.live="bindMode" value="existing_asset" class="text-brand-500" />
                            Bind to existing compatible Digital Asset
                        </label>
                    </fieldset>

                    @if ($bindMode === 'create_asset')
                        <div>
                            <label class="text-xs font-medium text-gray-500" for="bind-asset-name">Digital Asset name</label>
                            <input id="bind-asset-name" type="text" wire:model="assetName" class="mt-1 w-full rounded-lg border-gray-200 text-sm dark:border-gray-700 dark:bg-gray-800" />
                        </div>
                    @else
                        <div>
                            <label class="text-xs font-medium text-gray-500" for="bind-existing-asset">Existing Digital Asset</label>
                            <select id="bind-existing-asset" wire:model="digitalAssetId" class="mt-1 w-full rounded-lg border-gray-200 text-sm dark:border-gray-700 dark:bg-gray-800">
                                <option value="">Select asset…</option>
                                @forelse ($compatibleAssets as $asset)
                                    <option value="{{ $asset['id'] }}">{{ $asset['name'] }} · {{ $asset['type'] }}</option>
                                @empty
                                    <option value="" disabled>No compatible unbound assets in this Brand</option>
                                @endforelse
                            </select>
                        </div>
                    @endif
                </div>

                <div class="mt-5 flex flex-wrap justify-end gap-2">
                    <x-ta.button wire:click="cancelBind" size="sm" variant="outline">Cancel</x-ta.button>
                    <x-ta.button wire:click="confirmBind" size="sm">Confirm binding</x-ta.button>
                </div>
            </div>
        </div>
    @endif
</div>
