<div>
    {{-- While an import is running, poll to refresh account states/progress. --}}
    @if ($activeImport)
        <div wire:poll.5s class="hidden"></div>
    @endif

    <x-ta.page-breadcrumb pageTitle="Meta Integration" />

    @if ($flashMessage)
        <div class="mb-6">
            <x-ta.alert :variant="$flashTone === 'success' ? 'success' : ($flashTone === 'error' ? 'error' : 'info')" :message="$flashMessage" />
        </div>
    @endif

    @if (! $integration)
        <x-ta.empty-state title="No Meta integration configured"
            message="Add a Meta integration and access token in the back-office before importing history.">
            <x-ta.button href="/admin/integrations" variant="outline" size="sm">Open Integrations</x-ta.button>
        </x-ta.empty-state>
    @else
        {{-- Status + authoritative counts --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4 md:gap-6">
            <x-ta.card>
                <span class="text-sm text-gray-500 dark:text-gray-400">Connection</span>
                <div class="mt-2 flex items-center gap-2">
                    @if ($configured && $connectionStatus === 'connected')
                        <x-ta.badge color="success">Connected</x-ta.badge>
                    @elseif ($configured)
                        <x-ta.badge color="warning">Configured</x-ta.badge>
                    @else
                        <x-ta.badge color="error">Not connected</x-ta.badge>
                    @endif
                </div>
                <p class="mt-2 text-xs text-gray-400">{{ $integration->name }}</p>
            </x-ta.card>

            <x-ta.metric-card label="Businesses found" :value="number_format($overview['businesses_found'])" />
            <x-ta.metric-card label="Ad Accounts found" :value="number_format($overview['ad_accounts_found'])" />

            <x-ta.card>
                <span class="text-sm text-gray-500 dark:text-gray-400">History coverage</span>
                <h4 class="mt-2 font-bold text-gray-800 text-title-sm dark:text-white/90">
                    {{ $overview['accounts_ready'] }} / {{ $overview['accounts_total'] }}
                </h4>
                <p class="mt-1 text-xs text-gray-400">accounts ready · source: {{ str_replace('_', ' ', $overview['history_source']) }}</p>
                <x-ta.progress-bar class="mt-3" :value="$overview['progress_percent']" :max="100"
                    :tone="$overview['overall_status'] === 'failed' ? 'error' : ($overview['overall_status'] === 'ready' ? 'success' : 'primary')" />
            </x-ta.card>
        </div>

        {{-- Actions --}}
        <div class="mt-6 flex flex-wrap items-center gap-3">
            <x-ta.button wire:click="testConnection" wire:loading.attr="disabled" variant="outline" size="sm">
                Test connection
            </x-ta.button>
            <x-ta.button wire:click="discoverAccounts" wire:loading.attr="disabled" variant="outline" size="sm">
                Discover accounts
            </x-ta.button>
            <x-ta.button wire:click="importHistory" wire:loading.attr="disabled" size="sm" :disabled="(bool) $activeImport">
                {{ $activeImport ? 'Import running…' : 'Import Meta history' }}
            </x-ta.button>
            <a href="/admin/runs" class="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Open Activity &rarr;</a>
        </div>

        @if ($activeImport)
            <div class="mt-4">
                <x-ta.alert variant="info"
                    title="Import in progress"
                    :message="(string) (data_get($activeImport->metadata, 'phase_label') ?: 'Working…').' — this page refreshes automatically.'" />
            </div>
        @endif

        {{-- Per-account table --}}
        <div class="mt-6">
            @if (empty($overview['accounts']))
                <x-ta.empty-state title="No Ad Accounts discovered yet"
                    message="Run “Discover accounts” to fetch the available Meta Ad Accounts for this integration." />
            @else
                <x-ta.table>
                    <x-slot:head>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Ad Account</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Status</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Coverage</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Progress</th>
                        <th class="px-5 py-3 text-right text-xs font-medium uppercase text-gray-400"></th>
                    </x-slot:head>
                    @foreach ($overview['accounts'] as $account)
                        @php
                            $statusColor = match ($account['status']) {
                                'ready' => 'success',
                                'partial' => 'warning',
                                'failed', 'needs_attention' => 'error',
                                'not_imported' => 'light',
                                default => 'info',
                            };
                        @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02] cursor-pointer" wire:click="toggleAccount({{ $account['resource_id'] }})">
                            <td class="px-5 py-4">
                                <span class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $account['account_name'] }}</span>
                                <span class="block text-xs text-gray-400">ID {{ $account['external_id'] }}@if ($account['business_name']) · {{ $account['business_name'] }}@endif</span>
                            </td>
                            <td class="px-5 py-4">
                                <x-ta.badge :color="$statusColor">{{ str_replace('_', ' ', $account['status']) }}</x-ta.badge>
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">
                                @if ($account['coverage_from'] && $account['coverage_to'])
                                    {{ $account['coverage_from'] }} → {{ $account['coverage_to'] }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">
                                @if ($account['is_running'])
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="h-2 w-2 rounded-full bg-brand-500 animate-pulse"></span>
                                        {{ $account['phase_label'] }}
                                    </span>
                                @else
                                    {{ $account['progress_text'] }}
                                @endif
                            </td>
                            <td class="px-5 py-4 text-right text-sm text-brand-600 dark:text-brand-400">
                                {{ $selectedAccountId === $account['resource_id'] ? 'Hide' : 'Details' }}
                            </td>
                        </tr>
                        @if ($selectedAccountId === $account['resource_id'])
                            <tr class="bg-gray-50 dark:bg-white/[0.02]">
                                <td colspan="5" class="px-5 py-4">
                                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                                        <div>
                                            <span class="block text-xs uppercase text-gray-400">Phase</span>
                                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ $account['phase_label'] }}</span>
                                        </div>
                                        <div>
                                            <span class="block text-xs uppercase text-gray-400">Campaigns</span>
                                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ $account['campaigns_done'] ?? '—' }} / {{ $account['campaigns_total'] ?? '—' }}</span>
                                        </div>
                                        <div>
                                            <span class="block text-xs uppercase text-gray-400">Ad Sets</span>
                                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ $account['adsets_done'] ?? '—' }} / {{ $account['adsets_total'] ?? '—' }}</span>
                                        </div>
                                        <div>
                                            <span class="block text-xs uppercase text-gray-400">Ads</span>
                                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ $account['ads_done'] ?? '—' }} / {{ $account['ads_total'] ?? '—' }}</span>
                                        </div>
                                        <div>
                                            <span class="block text-xs uppercase text-gray-400">Daily rows</span>
                                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ number_format((int) $account['daily_facts_count']) }}</span>
                                        </div>
                                        <div>
                                            <span class="block text-xs uppercase text-gray-400">Last success</span>
                                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ $account['last_successful_at'] ?? '—' }}</span>
                                        </div>
                                        @if ($account['last_error_summary'])
                                            <div class="col-span-2 sm:col-span-4">
                                                <span class="block text-xs uppercase text-error-500">Last error{{ $account['last_error_category'] ? ' · '.$account['last_error_category'] : '' }}</span>
                                                <span class="text-sm text-error-600 dark:text-error-400">{{ $account['last_error_summary'] }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </x-ta.table>
            @endif
        </div>
    @endif
</div>
