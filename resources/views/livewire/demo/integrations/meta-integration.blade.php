<div class="space-y-6" @if (! empty($import['running'])) wire:poll.2s="pollImport" @endif>
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <x-ta.button href="{{ route('demo.integrations') }}" size="sm" variant="outline">← Integrations</x-ta.button>
            <h1 class="mt-3 text-2xl font-bold text-gray-800 dark:text-white/90">Meta data import</h1>
            <p class="mt-1 text-sm text-gray-500">Simulated history import with account-level progress. Demo Mode — no Meta API calls.</p>
        </div>
        <div class="flex gap-2">
            @include('livewire.demo.partials.demo-badge', ['label' => 'Import simulation'])
            <x-ta.button wire:click="startImport" size="sm" :disabled="! empty($import['running'])">
                {{ ! empty($import['running']) ? 'Import running…' : 'Import all Meta data' }}
            </x-ta.button>
        </div>
    </div>

    <x-ta.card>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm text-gray-500">Overall readiness</p>
                <p class="text-2xl font-bold text-gray-800 dark:text-white/90">{{ $import['overall_ready'] }} / {{ $import['overall_total'] }} accounts</p>
            </div>
            <x-ta.badge :color="! empty($import['running']) ? 'info' : 'success'" size="sm">
                {{ ! empty($import['running']) ? 'Running' : 'Idle' }}
            </x-ta.badge>
        </div>
        <div class="mt-4">
            <x-ta.progress-bar :value="$import['overall_ready']" :max="$import['overall_total']" label="Accounts ready" />
        </div>
    </x-ta.card>

    <x-ta.table>
        <x-slot:head>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Account</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Business</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Status</th>
            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Phase</th>
            <th class="px-5 py-3"></th>
        </x-slot:head>
        @foreach ($accounts as $account)
            <tr>
                <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $account['name'] }}</td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ $account['business'] }}</td>
                <td class="px-5 py-4">
                    <x-ta.badge :color="match($account['status']) {
                        'ready' => 'success',
                        'importing' => 'info',
                        'needs_attention' => 'error',
                        'waiting' => 'warning',
                        default => 'light'
                    }" size="sm">{{ $account['status'] }}</x-ta.badge>
                </td>
                <td class="px-5 py-4 text-sm text-gray-500">{{ $account['phase'] }}</td>
                <td class="px-5 py-4 text-right">
                    <x-ta.button wire:click="expandAccount('{{ $account['id'] }}')" size="sm" variant="outline">
                        {{ $expandedAccountId === $account['id'] ? 'Hide' : 'Details' }}
                    </x-ta.button>
                </td>
            </tr>
            @if ($expandedAccountId === $account['id'])
                <tr class="bg-gray-50 dark:bg-white/[0.02]">
                    <td colspan="5" class="px-5 py-4 text-sm text-gray-600 dark:text-gray-300">
                        <div class="grid gap-3 md:grid-cols-3">
                            <div>Coverage: {{ $account['coverage'] ?? '—' }}</div>
                            <div>Campaigns: {{ $account['campaigns'] ?? '—' }}</div>
                            <div>Ad sets: {{ $account['adsets'] ?? '—' }}</div>
                            <div>Ads: {{ $account['ads'] ?? '—' }}</div>
                            <div>Creatives: {{ $account['creatives'] ?? '—' }}</div>
                            <div>Daily facts: {{ isset($account['daily_facts']) ? number_format($account['daily_facts']) : '—' }}</div>
                        </div>
                        @if (! empty($account['chunks_total']))
                            <div class="mt-3 max-w-md">
                                <x-ta.progress-bar :value="$account['chunks_done']" :max="$account['chunks_total']" label="Insight chunks" tone="info" />
                            </div>
                        @endif
                        @if (! empty($account['error']))
                            <x-ta.alert class="mt-3" variant="error" :message="$account['error']" />
                        @endif
                    </td>
                </tr>
            @endif
        @endforeach
    </x-ta.table>
</div>