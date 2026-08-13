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
            <x-ta.button href="{{ route('demo.integrations') }}" size="sm" variant="outline">All Integrations</x-ta.button>
            <a href="{{ route('demo.integrations.connector', ['connector' => 'meta-ads']) }}" wire:navigate class="inline-flex rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white">Meta Ads Connector</a>
        </div>
    </div>

    <div class="flex flex-wrap gap-2" role="tablist" aria-label="Meta integration sections">
        @foreach ([
            'overview' => 'Overview',
            'connectors' => 'Connectors',
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
                <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $integration['ad_accounts_discovered'] }}</p>
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
                <p class="mt-3 text-xs text-warning-600 dark:text-warning-400">Legacy manual token path is active for compatibility. Production OAuth authorization is Prompt 22 — not the frozen product destination.</p>
            @endif
        </div>

        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Upcoming product actions</h2>
            <p class="mt-1 text-sm text-gray-500">Frozen UI does not enable Discover / Select / Collect until their owning prompts land.</p>
            <ul class="mt-3 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                <li>Authorization & discovery · {{ $integration['milestones']['authorization_discovery'] }}</li>
                <li>Resource selection & binding · {{ $integration['milestones']['resource_selection_binding'] }}</li>
                <li>Production collector · {{ $integration['milestones']['production_collector'] }}</li>
                <li>Initial backfill · {{ $integration['milestones']['initial_backfill'] }}</li>
            </ul>
            <p class="mt-3 text-xs text-gray-500">Internal Settings → Integrations may still configure/test/discover until Prompt 22 moves those actions onto /app. That admin surface does not redefine the frozen product.</p>
        </div>
    @elseif ($tab === 'connectors')
        <p class="text-sm text-gray-500 dark:text-gray-400">One Meta Integration authorization. The Meta Ads Connector owns advertising capability — not credentials, Businesses, or Ad Accounts themselves.</p>
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($integration['connectors'] as $connector)
                <a href="{{ route('demo.integrations.connector', ['connector' => $connector['ui_slug']]) }}" wire:navigate class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 transition hover:bg-gray-50 dark:bg-gray-900 dark:ring-gray-800 dark:hover:bg-white/[0.03]">
                    <div class="flex items-center gap-2">
                        <x-demo.digital-asset-mark type="meta_ads" size="sm" />
                        <h3 class="font-semibold text-gray-800 dark:text-white/90">{{ $connector['label'] }}</h3>
                    </div>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                        {{ $connector['discovered'] }} Ad Accounts · {{ $connector['bound'] }} bound · {{ $connector['available'] }} available
                    </p>
                    <p class="mt-1 text-xs text-gray-500">Collection · {{ $connector['collection_status'] }} · {{ $connector['collection_note'] }}</p>
                    <p class="mt-2 text-xs font-medium text-brand-600 dark:text-brand-400">Open connector →</p>
                </a>
            @endforeach
        </div>
        <p class="text-xs text-gray-400">Facebook Page / Instagram organic connectors are future capabilities — not advertised as active production connectors here.</p>
    @elseif ($tab === 'resources')
        <div class="space-y-4">
            @foreach ($integration['resource_groups'] as $group)
                <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $group['label'] }}</h2>
                    <p class="mt-1 text-sm text-gray-500">{{ $group['note'] ?? '' }}</p>
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                        {{ $group['accounts'] }} discovered
                        @if (! ($group['container'] ?? false))
                            · {{ $group['bound'] }} bound · {{ $group['available'] }} available
                        @endif
                    </p>
                </section>
            @endforeach

            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Unbound Ad Accounts</h2>
                <p class="mt-1 text-sm text-gray-500">Discovered inventory is not automatically a Digital Asset and is not automatically bound. Human selection is Prompt 23.</p>
                @if (empty($integration['unbound_resources']))
                    <p class="mt-4 text-sm text-gray-500">No unbound Ad Accounts in inventory.</p>
                @else
                    <ul class="mt-4 space-y-3">
                        @foreach ($integration['unbound_resources'] as $resource)
                            <li class="rounded-lg bg-gray-50 px-4 py-3 dark:bg-white/[0.03]">
                                <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $resource['name'] }}</p>
                                <p class="text-xs text-gray-500">{{ $resource['external_id'] }} · {{ $resource['business'] ?? '—' }}</p>
                                <p class="mt-1 text-xs text-warning-600 dark:text-warning-400">{{ $resource['status_label'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Bindings</h2>
                @if (empty($integration['bindings']))
                    <p class="mt-4 text-sm text-gray-500">No Ad Accounts selected / bound yet.</p>
                @else
                    <ul class="mt-4 divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($integration['bindings'] as $binding)
                            <li class="py-3 text-sm">
                                <p class="font-medium text-gray-800 dark:text-white/90">{{ $binding['resource'] }}</p>
                                <p class="text-xs text-gray-500">↓ {{ $binding['binding'] }} ↓</p>
                                <p class="text-gray-600 dark:text-gray-300">{{ $binding['asset'] }}</p>
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
</div>
