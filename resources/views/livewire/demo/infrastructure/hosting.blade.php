<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
        <p class="font-semibold">Legacy Hosting workspace</p>
        <p class="mt-1 text-xs opacity-90">Hosting is Website Infrastructure — not an intended standalone Digital Asset. Preserved for legacy safety.</p>
        <a href="{{ route('operator.website', ['tab' => 'infrastructure']) }}" wire:navigate class="mt-2 inline-flex text-xs font-medium underline">{{ __('operator.chrome.open_website_infrastructure') }}</a>
    </div>

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Hosting · '.($asset['name'] ?? $data['provider']),
        'title' => 'Workspace',
        'subtitle' => 'Legacy infrastructure view — uptime, backups, renewal continuity',
        'badges' => [$data['provenance'] ?? 'Manual', 'Legacy'],
        'actions' => '<a href="'.e(route('operator.website', ['tab' => 'infrastructure'])).'" class="inline-flex"><span class="inline-flex items-center justify-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Website Infrastructure</span></a>',
    ])

    @include('livewire.demo.partials.section-question', [
        'question' => 'Is hosting continuity at risk?',
        'hint' => $data['days_remaining'].' days until renewal · '.$data['plan'].' · '.$data['region'],
    ])

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ta.metric-card label="Provider" :value="$data['provider']" />
        <x-ta.metric-card label="Plan" :value="$data['plan']" />
        <x-ta.metric-card label="Renewal" :value="$data['renewal_at']" />
        <x-ta.metric-card label="Uptime 30d" :value="$data['uptime']['30d'].'%'" />
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-ta.card>
            <div class="mb-3 flex items-center justify-between gap-2">
                <h2 class="font-semibold text-gray-800 dark:text-white/90">Environment</h2>
                @include('livewire.demo.partials.provenance-badge', ['label' => $data['provenance']])
            </div>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-gray-400">Environment</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['environment'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-400">Region</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['region'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-400">Status</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ str_replace('_', ' ', $data['status']) }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-400">PHP</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['resources']['php'] }}</dd></div>
            </dl>
        </x-ta.card>

        <x-ta.card>
            <h2 class="mb-3 font-semibold text-gray-800 dark:text-white/90">Resources</h2>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-gray-400">CPU</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['resources']['cpu'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-400">Memory</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['resources']['memory'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-400">Disk</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['resources']['disk'] }}</dd></div>
            </dl>
        </x-ta.card>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-ta.card>
            <h2 class="mb-3 font-semibold text-gray-800 dark:text-white/90">Uptime</h2>
            <p class="text-sm text-gray-500">90d {{ $data['uptime']['90d'] }}%</p>
            <ul class="mt-3 space-y-2">
                @foreach ($data['uptime']['incidents'] as $incident)
                    <li class="rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/[0.02]">
                        {{ $incident['when'] }} · {{ $incident['duration'] }} — {{ $incident['note'] }}
                    </li>
                @endforeach
            </ul>
        </x-ta.card>

        <x-ta.card>
            <div class="mb-3 flex items-center justify-between gap-2">
                <h2 class="font-semibold text-gray-800 dark:text-white/90">Backups</h2>
                <x-ta.badge color="success" size="sm">{{ ucfirst($data['backups']['status']) }}</x-ta.badge>
            </div>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-gray-400">Last full</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['backups']['last_full'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-400">Last incremental</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['backups']['last_incremental'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-400">Retention</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['backups']['retention_days'] }} days</dd></div>
            </dl>
        </x-ta.card>
    </div>

    <x-ta.card>
        @include('livewire.demo.partials.section-question', ['question' => 'Operator notes'])
        <ul class="mt-2 space-y-2 text-sm text-gray-600 dark:text-gray-300">
            @foreach ($data['notes'] as $note)
                <li>• {{ $note }}</li>
            @endforeach
        </ul>
    </x-ta.card>
</div>
