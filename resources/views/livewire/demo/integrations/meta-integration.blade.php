<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <x-demo.digital-asset-mark type="meta_ads" size="lg" />
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-400">Integration</p>
                <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">{{ $integration['name'] }}</h1>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    @php
                        $stateColor = match ($integration['state'] ?? '') {
                            'connected' => 'success',
                            'needs_attention' => 'warning',
                            'configured' => 'info',
                            default => 'light',
                        };
                    @endphp
                    <x-ta.badge :color="$stateColor" size="sm">{{ $integration['state_label'] }}</x-ta.badge>
                    <span class="text-xs text-gray-500">Auth · {{ $integration['auth_status_label'] }}</span>
                    @if (! empty($integration['next_action_label']))
                        <span class="text-xs text-gray-500">Next · {{ $integration['next_action_label'] }}</span>
                    @endif
                </div>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Authorization plane for Meta Ads. Specialist creative analytics live on the Meta Ads Digital Asset.</p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-ta.button href="{{ route('operator.integrations') }}" size="sm" variant="outline">All Integrations</x-ta.button>
            @if (! ($integration['actions']['authorize'] ?? false) && ! ($integration['actions']['reauthorize'] ?? false))
                <x-ta.button wire:click="setTab('configuration')" size="sm">{{ __('operator.integrations_ui.configure') }}</x-ta.button>
            @endif
            @if (($integration['actions']['authorize'] ?? false) && ($integration['auth_status'] ?? '') !== 'connected')
                @if (! empty($integration['authorize_url']))
                    <x-ta.button :href="$integration['authorize_url']" size="sm">Connect Meta</x-ta.button>
                @else
                    <x-ta.button wire:click="bootstrapAndConnect" size="sm">Connect Meta</x-ta.button>
                @endif
            @elseif (($integration['actions']['reauthorize'] ?? false) && ! empty($integration['reauthorize_url']))
                <x-ta.button :href="$integration['reauthorize_url']" size="sm" variant="outline">Reauthorize Meta</x-ta.button>
            @endif
            @if ($integration['actions']['discover_businesses'] ?? false)
                <x-ta.button wire:click="discoverBusinesses" size="sm" variant="outline">Discover Businesses</x-ta.button>
            @endif
            @if ($integration['actions']['discover_ad_accounts'] ?? false)
                <x-ta.button wire:click="discoverAdAccounts" size="sm" variant="outline">Discover Ad Accounts</x-ta.button>
            @endif
            @if (($integration['actions']['discover_businesses'] ?? false) || ($integration['actions']['discover_ad_accounts'] ?? false))
                <x-ta.button wire:click="refreshResources" size="sm" variant="outline">Refresh Resources</x-ta.button>
            @endif
            @if ($integration['actions']['collect'] ?? false)
                <x-ta.button wire:click="collectData" size="sm" wire:loading.attr="disabled">
                    Collect Data
                </x-ta.button>
            @endif
            @if ($integration['actions']['disconnect'] ?? false)
                <x-ta.button wire:click="askDisconnect" size="sm" variant="outline">Disconnect</x-ta.button>
            @endif
            <a href="{{ route('operator.integrations.connector', ['connector' => 'meta-ads']) }}" wire:navigate class="inline-flex rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white">Meta Ads Connector</a>
        </div>
    </div>

    @if ($confirmDisconnect ?? false)
        <div class="rounded-xl bg-warning-50 p-4 ring-1 ring-inset ring-warning-200 dark:bg-warning-500/10 dark:ring-warning-500/30">
            <p class="text-sm font-medium text-gray-800 dark:text-white/90">Disconnect Meta authorization?</p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Clears the local credential and attempts provider permission revoke. Business/Ad Account inventory and history are preserved.</p>
            <div class="mt-3 flex gap-2">
                <x-ta.button wire:click="confirmDisconnectAction" size="sm">Confirm disconnect</x-ta.button>
                <x-ta.button wire:click="cancelDisconnect" size="sm" variant="outline">Cancel</x-ta.button>
            </div>
        </div>
    @endif

    <div class="flex flex-wrap gap-2" role="tablist" aria-label="Meta integration sections">
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
                <p class="text-xs text-gray-400">Meta Businesses</p>
                <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $integration['businesses_discovered'] }}</p>
                <p class="mt-1 text-xs text-gray-500">Container context — not Digital Assets</p>
            </div>
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-xs text-gray-400">Ad Accounts discovered</p>
                @if ($integration['discovery_not_run'] ?? true)
                    <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">—</p>
                    <p class="mt-1 text-xs text-gray-500">Not discovered yet</p>
                @else
                    <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $integration['ad_accounts_discovered'] }}</p>
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
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-xs text-gray-400">Application configuration</p>
                <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">{{ $integration['app_configuration_label'] }}</p>
                <p class="mt-1 text-xs text-gray-500">Graph API {{ $integration['graph_api_version'] }}</p>
            </div>
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-xs text-gray-400">Authorization credential</p>
                <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">{{ $integration['authorization_credential_label'] }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ $integration['connection_test_label'] }}</p>
            </div>
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-xs text-gray-400">Collection / data</p>
                <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">{{ $integration['collection_state_label'] }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ $integration['data_state_label'] }}</p>
            </div>
        </div>

        @if (! empty($preflight))
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Meta initial collection</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    {{ $preflight['summary']['eligible_resources'] ?? 0 }} bound Ad Accounts ready
                    · Datasets contract-driven
                    · Historical coverage varies by dataset
                </p>
                @if (! empty($preflight['summary']['by_account']))
                    <ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                        @foreach ($preflight['summary']['by_account'] as $provider => $counts)
                            <li>
                                {{ str_replace('_', ' ', $provider) }}
                                · {{ $counts['eligible'] ?? 0 }} eligible
                                / {{ $counts['bound'] ?? 0 }} bound
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if (($preflight['summary']['planned_datasets'] ?? 0) > 0 || ($preflight['summary']['already_satisfied_datasets'] ?? 0) > 0)
                    <p class="mt-2 text-xs text-gray-500">
                        Planned {{ $preflight['summary']['planned_datasets'] ?? 0 }} datasets
                        · Already satisfied {{ $preflight['summary']['already_satisfied_datasets'] ?? 0 }}
                    </p>
                @endif
                @foreach ($preflight['action_required'] ?? [] as $issue)
                    <p class="mt-2 text-xs text-warning-600 dark:text-warning-400">
                        {{ $issue['provider_or_source'] ?? 'META_ADS' }} · {{ $issue['label'] ?? 'Action required' }}
                    </p>
                @endforeach
                @if ($preflight['can_start'] ?? false)
                    <p class="mt-2 text-xs text-gray-500">{{ $preflight['summary']['async_insights_note'] ?? 'Large Insights reports may run asynchronously' }}. Collection continues in the background. You may leave this page.</p>
                @elseif (! empty($preflight['message']))
                    <p class="mt-2 text-xs text-gray-500">{{ $preflight['message'] }}</p>
                @endif
            </div>
        @endif

        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">State separation</h2>
            <p class="mt-1 text-sm text-gray-500">Configured ≠ authorized ≠ discovered ≠ bound ≠ collected ≠ fresh.</p>
            <ul class="mt-3 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                <li>App configured · {{ ($integration['app_configured'] ?? false) ? 'YES' : 'NO' }}</li>
                <li>Authorized / credential · {{ $integration['auth_status_label'] }}</li>
                <li>Ad Accounts discovered · {{ $integration['ad_accounts_discovered'] }}</li>
                <li>Bound · {{ $integration['bound'] }}</li>
                <li>Collection · {{ $integration['collection_state_label'] }}</li>
                <li>Data · {{ $integration['data_state_label'] }}</li>
            </ul>
            @if (! empty($integration['credential_summary']['legacy_manual_token_path']))
                <p class="mt-3 text-xs text-warning-600 dark:text-warning-400">A stored access token is present without OAuth authorization. Prefer Connect Meta after application credentials are configured.</p>
            @endif
        </div>

        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Capability status</h2>
            <p class="mt-1 text-sm text-gray-500">Authorization, discovery, binding, and collection are live on this workspace. Specialist analytical UI remains separate.</p>
            <ul class="mt-3 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                <li>Authorization & discovery · {{ $integration['milestones']['authorization_discovery'] }}</li>
                <li>Resource selection & binding · {{ $integration['milestones']['resource_selection_binding'] }}</li>
                <li>Collector · {{ $integration['milestones']['production_collector'] }}</li>
                <li>Initial backfill · {{ $integration['milestones']['initial_backfill'] }}</li>
            </ul>
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
                        <dt class="text-gray-400">Authorization</dt>
                        <dd class="font-medium text-gray-800 dark:text-white/90">{{ $integration['auth_status_label'] ?? 'Not authorized' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-400">Access token</dt>
                        <dd class="font-medium text-gray-800 dark:text-white/90">{{ $integration['authorization_credential_label'] ?? 'Not configured' }}</dd>
                    </div>
                </dl>
                <p class="mt-4 text-xs text-gray-500">App Secret is write-only and never shown after save. Application credentials are not the same as Meta authorization.</p>
            </div>

            @if ($canManageCredentials ?? false)
                <form wire:submit.prevent="saveMetaConfiguration" class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Application credentials</h3>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <x-ta.form.field label="Meta App ID" helper="Not a secret. Visible after save." :error="$errors->first('app_id')">
                            <input wire:model="metaAppId" type="text" autocomplete="off"
                                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-800 shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                        </x-ta.form.field>
                        <x-ta.form.field label="Meta App Secret" :helper="$metaAppSecretConfigured ? 'Configured — leave blank to keep the stored value.' : 'Write-only. Never shown after save.'" :error="$errors->first('app_secret')">
                            <input wire:model="metaAppSecret" type="password" autocomplete="new-password" placeholder="{{ $metaAppSecretConfigured ? 'Replace credential' : '' }}"
                                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-800 shadow-theme-xs outline-none focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                        </x-ta.form.field>
                        @if ($metaAppSecretConfigured ?? false)
                            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300 md:col-span-2">
                                <input type="checkbox" wire:model="clearMetaAppSecret" class="rounded border-gray-300" />
                                Clear stored App Secret
                            </label>
                        @endif
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <x-ta.button type="submit" size="sm">Save credentials</x-ta.button>
                        <x-ta.button type="button" wire:click="testMetaConfiguration" size="sm" variant="outline">Test configuration</x-ta.button>
                        @if (($integration['app_configured'] ?? false) || ($metaAppSecretConfigured ?? false))
                            <x-ta.button type="button" wire:click="askRemoveMetaCredentials" size="sm" variant="outline">Remove credentials</x-ta.button>
                        @endif
                    </div>
                </form>
                @if ($confirmRemoveMetaCredentials ?? false)
                    <div class="rounded-xl bg-warning-50 p-4 ring-1 ring-inset ring-warning-200 dark:bg-warning-500/10 dark:ring-warning-500/30">
                        <p class="text-sm font-medium text-gray-800 dark:text-white/90">Remove Meta application credentials?</p>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">This deletes stored App ID and App Secret. Discovered resources, bindings, and history are preserved.</p>
                        <div class="mt-3 flex gap-2">
                            <x-ta.button wire:click="removeMetaConfiguration" size="sm">Confirm remove</x-ta.button>
                            <x-ta.button wire:click="cancelRemoveMetaCredentials" size="sm" variant="outline">Cancel</x-ta.button>
                        </div>
                    </div>
                @endif
            @else
                <p class="text-sm text-gray-500">Only administrators can view or change Meta application credentials.</p>
            @endif
        </div>
    @elseif ($tab === 'connectors')
        <p class="text-sm text-gray-500 dark:text-gray-400">One Meta Integration authorization. The Meta Ads Connector owns advertising capability — not credentials, Businesses, or Ad Accounts themselves.</p>
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($integration['connectors'] as $connector)
                <a href="{{ route('operator.integrations.connector', ['connector' => $connector['ui_slug']]) }}" wire:navigate class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-900 dark:ring-gray-800 dark:hover:bg-white/[0.03]">
                    <div class="flex items-center gap-2">
                        <x-demo.digital-asset-mark type="meta_ads" size="sm" />
                        <h3 class="font-semibold text-gray-800 dark:text-white/90">{{ $connector['label'] }}</h3>
                    </div>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                        {{ $connector['discovered'] }} Ad Accounts · {{ $connector['bound'] }} bound · {{ $connector['available'] }} available
                    </p>
                    <p class="mt-1 text-xs text-gray-500">Collection · {{ ($connector['collection_status'] ?? '') === 'NOT_YET' ? 'Not collected yet' : ($connector['collection_status'] ?? 'Not collected yet') }} · {{ $connector['collection_note'] }}</p>
                    <p class="mt-2 text-xs font-medium text-brand-600 dark:text-brand-400">Open connector →</p>
                </a>
            @endforeach
        </div>
        <p class="text-xs text-gray-400">Facebook Page / Instagram organic connectors are future capabilities — not advertised as active production connectors here.</p>
    @elseif ($tab === 'resources')
        <div class="space-y-4">
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Meta Businesses</h2>
                <p class="mt-1 text-sm text-gray-500">Select which Business contexts MoxDOP should use to discover Ad Accounts. This is discovery context — not a Digital Asset binding.</p>
                <p class="mt-2 text-xs text-gray-500">Discovery · {{ ($integration['discovery']['businesses'] ?? 'never_run') === 'never_run' ? 'Not discovered yet' : $integration['discovery']['businesses'] }} · Selected {{ $integration['businesses_selected'] ?? 0 }}</p>
                @if (empty($integration['businesses']))
                    <p class="mt-4 text-sm text-gray-500">No Businesses discovered yet.</p>
                @else
                    <ul class="mt-4 space-y-3">
                        @foreach ($integration['businesses'] as $business)
                            <li class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-gray-50 px-4 py-3 dark:bg-white/[0.03]">
                                <div>
                                    <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $business['name'] }}</p>
                                    <p class="text-xs text-gray-500">{{ $business['external_id'] }} · container</p>
                                </div>
                                @if ($integration['actions']['select_business'] ?? false)
                                    <x-ta.button wire:click="toggleBusinessSelection('{{ $business['id'] }}')" size="sm" variant="outline">
                                        {{ ($business['selected'] ?? false) ? 'Deselect context' : 'Use for discovery' }}
                                    </x-ta.button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            @foreach ($integration['resource_groups'] as $group)
                @continue(($group['resource_type'] ?? '') === 'meta_business')
                <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $group['label'] }}</h2>
                    <p class="mt-1 text-sm text-gray-500">{{ $group['note'] ?? '' }}</p>
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                        {{ $group['accounts'] }} discovered
                        @if (! ($group['container'] ?? false))
                            · {{ $group['bound'] }} bound · {{ $group['available'] }} available
                        @endif
                    </p>
                    <p class="mt-1 text-xs text-gray-500">Ad Account discovery · {{ $integration['discovery']['ad_accounts'] ?? 'never_run' }}</p>
                </section>
            @endforeach

            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Ad Accounts</h2>
                <p class="mt-1 text-sm text-gray-500">Select an Ad Account, confirm Brand / Meta Ads asset, then confirm the connection. Discovery never auto-binds. Names and domains never auto-bind.</p>
                @if (empty($integration['unbound_resources']))
                    <p class="mt-4 text-sm text-gray-500">No unbound Ad Accounts in inventory.</p>
                @else
                    <ul class="mt-4 space-y-3">
                        @foreach ($integration['unbound_resources'] as $resource)
                            <li class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-gray-50 px-4 py-3 dark:bg-white/[0.03]">
                                <div>
                                    <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $resource['name'] }}</p>
                                    <p class="text-xs text-gray-500">
                                        ID {{ $resource['external_id_masked'] ?? $resource['external_id'] }}
                                        @if (! empty($resource['business'])) · Business {{ $resource['business'] }} @endif
                                        @if (! empty($resource['access_label'])) · {{ $resource['access_label'] }} @endif
                                        @if (! empty($resource['currency'])) · {{ $resource['currency'] }} @endif
                                        @if (! empty($resource['timezone'])) · {{ $resource['timezone'] }} @endif
                                    </p>
                                    <p class="mt-1 text-xs text-warning-600 dark:text-warning-400">{{ $resource['status_label'] }}</p>
                                </div>
                                @if ($integration['actions']['bind'] ?? false)
                                    <x-ta.button wire:click="bindResource('{{ $resource['id'] }}')" size="sm">Select &amp; confirm…</x-ta.button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Connected Ad Accounts</h2>
                <p class="mt-1 text-sm text-gray-500">Authorized account ≠ data ready. Collection still has to run after binding.</p>
                @if (empty($integration['bindings']))
                    <p class="mt-4 text-sm text-gray-500">No Ad Accounts connected yet.</p>
                @else
                    <ul class="mt-4 divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($integration['bindings'] as $binding)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                                <div>
                                    <p class="font-medium text-gray-800 dark:text-white/90">{{ $binding['resource'] }}</p>
                                    <p class="text-xs text-gray-500">
                                        ID {{ $binding['external_id_masked'] ?? $binding['external_id'] ?? '—' }}
                                        @if (! empty($binding['business'])) · {{ $binding['business'] }} @endif
                                        @if (! empty($binding['currency'])) · {{ $binding['currency'] }} @endif
                                    </p>
                                    <p class="text-xs text-gray-500">↓ {{ $binding['binding'] }} ↓</p>
                                    <p class="text-gray-600 dark:text-gray-300">
                                        {{ $binding['asset'] }}
                                        @if (! empty($binding['brand'])) · {{ $binding['brand'] }} @endif
                                    </p>
                                    <p class="mt-1 text-xs text-gray-500">Data · {{ $integration['data_state_label'] ?? 'Not collected yet' }}</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    @if (! empty($binding['route']))
                                        <x-ta.button :href="route($binding['route'])" size="sm" variant="outline">Open Asset</x-ta.button>
                                    @endif
                                    @if ($integration['actions']['unbind'] ?? false)
                                        <x-ta.button wire:click="unbindBinding('{{ $binding['id'] }}')" size="sm" variant="outline" wire:confirm="Disconnect this Ad Account from this Meta Ads asset? Meta authorization stays connected.">Disconnect Ad Account</x-ta.button>
                                    @endif
                                </div>
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

        <div class="mox-collection-monitor-embed" wire:key="meta-collection-monitoring">
            @livewire(\App\Livewire\Collection\MonitoringPanel::class)
        </div>
    @endif

    @if ($showBindModal ?? false)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4" role="dialog" aria-modal="true" aria-labelledby="meta-bind-title">
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl dark:bg-gray-900">
                <h2 id="meta-bind-title" class="text-lg font-semibold text-gray-800 dark:text-white/90">Confirm Meta Ad Account connection</h2>
                <p class="mt-2 text-sm text-gray-500">You are confirming that this Meta Ad Account is the provider account for this MoxDOP Meta Ads Digital Asset. This does not connect the entire Meta Business and does not start collection.</p>

                @if ($bindingPreview)
                    <div class="mt-4 rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/[0.03]">
                        <p class="font-medium text-gray-800 dark:text-white/90">{{ $bindingPreview['name'] }}</p>
                        <p class="text-xs text-gray-500">
                            Provider · Meta
                            @if (! empty($bindingPreview['business'])) · Business {{ $bindingPreview['business'] }} @endif
                            · ID {{ $bindingPreview['external_id'] }}
                            @if (! empty($bindingPreview['access'])) · {{ $bindingPreview['access'] }} @endif
                            @if (! empty($bindingPreview['currency'])) · {{ $bindingPreview['currency'] }} @endif
                            @if (! empty($bindingPreview['timezone'])) · {{ $bindingPreview['timezone'] }} @endif
                        </p>
                    </div>
                @endif

                <div class="mt-4 space-y-3">
                    <div>
                        <label class="text-xs font-medium text-gray-500" for="meta-bind-brand">Customer / Brand</label>
                        <select id="meta-bind-brand" wire:model.live="brandId" class="mt-1 w-full rounded-lg border-gray-200 text-sm dark:border-gray-700 dark:bg-gray-800">
                            <option value="">Select Brand…</option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand['id'] }}">{{ $brand['label'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <fieldset class="space-y-2">
                        <legend class="text-xs font-medium text-gray-500">Meta Ads asset</legend>
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="radio" wire:model.live="bindMode" value="create_asset" class="text-brand-500" />
                            Create Meta Ads Digital Asset if needed
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="radio" wire:model.live="bindMode" value="existing_asset" class="text-brand-500" />
                            Bind existing Meta Ads Digital Asset
                        </label>
                    </fieldset>

                    @if ($bindMode === 'create_asset')
                        <div>
                            <label class="text-xs font-medium text-gray-500" for="meta-bind-asset-name">Digital Asset name</label>
                            <input id="meta-bind-asset-name" type="text" wire:model="assetName" class="mt-1 w-full rounded-lg border-gray-200 text-sm dark:border-gray-700 dark:bg-gray-800" />
                            <p class="mt-1 text-xs text-gray-400">If this Brand already has an unbound Meta Ads asset, that asset is reused instead of creating a duplicate.</p>
                        </div>
                    @else
                        <div>
                            <label class="text-xs font-medium text-gray-500" for="meta-bind-existing-asset">Existing Meta Ads Digital Asset</label>
                            <select id="meta-bind-existing-asset" wire:model="digitalAssetId" class="mt-1 w-full rounded-lg border-gray-200 text-sm dark:border-gray-700 dark:bg-gray-800">
                                <option value="">Select asset…</option>
                                @forelse ($compatibleAssets as $asset)
                                    <option value="{{ $asset['id'] }}">
                                        {{ $asset['name'] }}
                                        @if (! empty($asset['has_active_binding'])) · already connected @endif
                                    </option>
                                @empty
                                    <option value="" disabled>No Meta Ads assets in this Brand</option>
                                @endforelse
                            </select>
                        </div>
                    @endif

                    <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" wire:model="allowReplace" class="mt-1 text-brand-500" />
                        <span>Replace existing Ad Account on the selected Meta Ads asset if one is already connected. Historical data from the previous account stays attached to that previous account.</span>
                    </label>
                </div>

                <div class="mt-5 flex flex-wrap justify-end gap-2">
                    <x-ta.button wire:click="cancelBind" size="sm" variant="outline">Cancel</x-ta.button>
                    <x-ta.button wire:click="confirmBind" size="sm">Confirm Connection</x-ta.button>
                </div>
            </div>
        </div>
    @endif
</div>
