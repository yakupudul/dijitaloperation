@php
    $statusColor = static function (string $status): string {
        return match ($status) {
            'ready' => 'success',
            'importing' => 'info',
            'needs_attention' => 'error',
            'waiting' => 'warning',
            'queued' => 'light',
            default => 'light',
        };
    };

    $statusLabel = static function (string $status): string {
        return match ($status) {
            'needs_attention' => 'Needs attention',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    };
@endphp

<div class="space-y-6" @if (! empty($import['running'])) wire:poll.2s="pollImport" @endif>
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <x-ta.button href="{{ route('demo.integrations') }}" size="sm" variant="outline">← Integrations</x-ta.button>
            <h1 class="mt-3 text-2xl font-bold text-gray-800 dark:text-white/90">Meta data import</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Simulated history import with account-level progress. Demo Mode — no Meta API calls.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @include('livewire.demo.partials.demo-badge', ['label' => 'Import simulation'])
            <x-ta.button wire:click="startImport" size="sm" :disabled="! empty($import['running'])">
                {{ ! empty($import['running']) ? 'Import running…' : 'Import all Meta data' }}
            </x-ta.button>
        </div>
    </div>

    <x-ta.card>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Overall readiness</p>
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

    <div class="space-y-6">
        @foreach ($groups as $groupKey => $group)
            @continue(count($group['accounts']) === 0)
            <div>
                @include('livewire.demo.partials.section-question', [
                    'question' => $group['label'].' ('.count($group['accounts']).')',
                    'hint' => $group['hint'],
                ])
                <div class="space-y-3">
                    @foreach ($group['accounts'] as $account)
                        <x-ta.card>
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $account['name'] }}</h3>
                                        <x-ta.badge :color="$statusColor($account['status'])" size="sm">{{ $statusLabel($account['status']) }}</x-ta.badge>
                                    </div>
                                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $account['business'] }} · {{ $account['phase'] }}</p>
                                </div>
                                <x-ta.button wire:click="expandAccount('{{ $account['id'] }}')" size="sm" variant="outline">
                                    {{ $expandedAccountId === $account['id'] ? 'Hide' : 'Details' }}
                                </x-ta.button>
                            </div>

                            @if ($expandedAccountId === $account['id'])
                                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                                    <div class="rounded-xl bg-gray-50 p-3 text-sm dark:bg-white/[0.02]">
                                        <p class="text-xs text-gray-400">Coverage</p>
                                        <p class="mt-1 text-gray-700 dark:text-gray-300">{{ $account['coverage'] ?? '—' }}</p>
                                    </div>
                                    <div class="rounded-xl bg-gray-50 p-3 text-sm dark:bg-white/[0.02]">
                                        <p class="text-xs text-gray-400">Campaigns</p>
                                        <p class="mt-1 text-gray-700 dark:text-gray-300">{{ $account['campaigns'] ?? '—' }}</p>
                                    </div>
                                    <div class="rounded-xl bg-gray-50 p-3 text-sm dark:bg-white/[0.02]">
                                        <p class="text-xs text-gray-400">Ad sets</p>
                                        <p class="mt-1 text-gray-700 dark:text-gray-300">{{ $account['adsets'] ?? '—' }}</p>
                                    </div>
                                    <div class="rounded-xl bg-gray-50 p-3 text-sm dark:bg-white/[0.02]">
                                        <p class="text-xs text-gray-400">Ads</p>
                                        <p class="mt-1 text-gray-700 dark:text-gray-300">{{ $account['ads'] ?? '—' }}</p>
                                    </div>
                                    <div class="rounded-xl bg-gray-50 p-3 text-sm dark:bg-white/[0.02]">
                                        <p class="text-xs text-gray-400">Creatives</p>
                                        <p class="mt-1 text-gray-700 dark:text-gray-300">{{ $account['creatives'] ?? '—' }}</p>
                                    </div>
                                    <div class="rounded-xl bg-gray-50 p-3 text-sm dark:bg-white/[0.02]">
                                        <p class="text-xs text-gray-400">Daily facts</p>
                                        <p class="mt-1 text-gray-700 dark:text-gray-300">{{ isset($account['daily_facts']) ? number_format($account['daily_facts']) : '—' }}</p>
                                    </div>
                                </div>
                                @if (! empty($account['chunks_total']))
                                    <div class="mt-3 max-w-md">
                                        <x-ta.progress-bar :value="$account['chunks_done']" :max="$account['chunks_total']" label="Insight chunks" tone="info" />
                                    </div>
                                @endif
                                @if (! empty($account['error']))
                                    <x-ta.alert class="mt-3" variant="error" :message="$account['error']" />
                                @endif
                            @endif
                        </x-ta.card>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>
