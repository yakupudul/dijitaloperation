<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Domain · '.($asset['name'] ?? $data['domain']),
        'title' => 'Workspace',
        'subtitle' => 'Infrastructure / lifecycle — registrar, DNS, SSL continuity',
        'badges' => [$data['provenance'] ?? 'Detected'],
        'actions' => '<a href="'.e(route('demo.website', ['tab' => 'lifecycle'])).'" class="inline-flex"><span class="inline-flex items-center justify-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Website lifecycle</span></a>',
    ])

    @include('livewire.demo.partials.section-question', [
        'question' => 'What is the registration and renewal status?',
    ])

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ta.metric-card label="Domain" :value="$data['domain']" />
        <x-ta.metric-card label="Status" :value="ucfirst($data['status'])" />
        <x-ta.metric-card label="Expires" :value="$data['expires_at']" />
        <x-ta.metric-card label="Days remaining" :value="(string) $data['days_remaining']" />
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-ta.card>
            <div class="mb-3 flex items-center justify-between gap-2">
                <h2 class="font-semibold text-gray-800 dark:text-white/90">Registration</h2>
                @include('livewire.demo.partials.provenance-badge', ['label' => $data['provenance']])
            </div>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-gray-400">Registrar</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['registrar'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-400">Registered</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['registered_at'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-400">Auto-renew</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['auto_renew'] ? 'On' : 'Off' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-400">WHOIS</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['whois_summary'] }}</dd></div>
            </dl>
        </x-ta.card>

        <x-ta.card>
            <div class="mb-3 flex items-center justify-between gap-2">
                <h2 class="font-semibold text-gray-800 dark:text-white/90">SSL</h2>
                @include('livewire.demo.partials.provenance-badge', ['label' => $data['ssl']['provenance']])
            </div>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-gray-400">Issuer</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['ssl']['issuer'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-400">Expires</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['ssl']['expires_at'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-400">Days remaining</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['ssl']['days_remaining'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-400">Grade</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ $data['ssl']['grade'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-400">SAN</dt><dd class="font-medium text-gray-800 dark:text-white/90">{{ implode(', ', $data['ssl']['san']) }}</dd></div>
            </dl>
        </x-ta.card>
    </div>

    <x-ta.card>
        <div class="mb-3 flex items-center justify-between gap-2">
            <h2 class="font-semibold text-gray-800 dark:text-white/90">DNS</h2>
            <x-ta.badge color="success" size="sm">{{ ucfirst($data['dns']['health']) }}</x-ta.badge>
        </div>
        <p class="text-sm text-gray-500">Nameservers: {{ implode(', ', $data['dns']['nameservers']) }}</p>
        <x-ta.table class="mt-4">
            <x-slot:head>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Type</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Host</th>
                <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-400">Value</th>
            </x-slot:head>
            @foreach ($data['dns']['records'] as $record)
                <tr>
                    <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $record['type'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $record['host'] }}</td>
                    <td class="px-5 py-4 text-sm text-gray-500">{{ $record['value'] }}</td>
                </tr>
            @endforeach
        </x-ta.table>
        @if (empty($data['dns']['issues']))
            @include('livewire.demo.partials.empty-panel', [
                'title' => 'No DNS issues',
                'message' => 'Detected DNS records look healthy for this demo domain.',
            ])
        @endif
    </x-ta.card>
</div>
