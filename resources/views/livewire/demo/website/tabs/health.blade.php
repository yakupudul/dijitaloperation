@php
    $health = $data['health'];
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Website health</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Technical and structural conditions that can affect discovery, usability and measurement.</p>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            {{ $health['summary']['checks_evaluated'] }} checks evaluated
            · {{ $health['summary']['findings_open'] }} findings open
            · {{ $health['summary']['high_severity'] }} high severity
            · {{ $health['summary']['checks_unavailable'] }} checks unavailable
        </p>
        <p class="mt-1 text-xs text-gray-400">No Website Health score. States and issue counts only.</p>
    </div>

    <div class="flex flex-wrap gap-1" role="tablist" aria-label="Health groups">
        <button type="button" wire:click="setHealthGroup('all')" @class(['rounded-md px-3 py-1.5 text-xs font-medium', 'bg-brand-500 text-white' => $health_group === 'all', 'bg-gray-100 text-gray-600 dark:bg-white/[0.04] dark:text-gray-300' => $health_group !== 'all'])>All</button>
        @foreach ($health['groups'] as $key => $label)
            <button type="button" wire:click="setHealthGroup('{{ $key }}')" @class(['rounded-md px-3 py-1.5 text-xs font-medium', 'bg-brand-500 text-white' => $health_group === $key, 'bg-gray-100 text-gray-600 dark:bg-white/[0.04] dark:text-gray-300' => $health_group !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    <div class="flex flex-wrap gap-1">
        @foreach (['all' => 'All severities', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $key => $label)
            <button type="button" wire:click="setSeverity('{{ $key }}')" @class(['rounded-md px-2.5 py-1 text-xs font-medium', 'bg-brand-500 text-white' => $severity === $key, 'ring-1 ring-inset ring-gray-300 text-gray-600 dark:ring-gray-700 dark:text-gray-300' => $severity !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($selectedFinding)
        <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-brand-300 dark:bg-gray-800 dark:ring-brand-500/40">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Problem</p>
                    <h3 class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $selectedFinding['title'] }}</h3>
                </div>
                <button type="button" wire:click="closeFinding" class="text-xs text-gray-500 hover:underline">Close</button>
            </div>
            <dl class="mt-4 space-y-3 text-sm">
                <div><dt class="text-xs text-gray-400">Location</dt><dd class="text-gray-700 dark:text-gray-300">{{ $selectedFinding['where'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Evidence</dt><dd class="text-gray-700 dark:text-gray-300">{{ $selectedFinding['evidence'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Why it matters</dt><dd class="text-gray-700 dark:text-gray-300">{{ $selectedFinding['why'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Affected scope</dt><dd class="text-gray-700 dark:text-gray-300">{{ $selectedFinding['affected_scope'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Recommended action</dt><dd class="text-gray-700 dark:text-gray-300">{{ $selectedFinding['recommended'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Suggested owner</dt><dd class="text-gray-700 dark:text-gray-300">{{ $selectedFinding['suggested_owner'] }} · {{ $selectedFinding['actionability'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Verification</dt><dd class="text-gray-700 dark:text-gray-300">{{ $selectedFinding['verification'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Success / failure signals</dt><dd class="text-gray-700 dark:text-gray-300">{{ $selectedFinding['success_signal'] }} · {{ $selectedFinding['failure_signal'] }}</dd></div>
            </dl>
            <div class="mt-4 flex flex-wrap gap-2">
                <a href="{{ route('demo.findings') }}" wire:navigate class="rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Related Findings</a>
                @if (! empty($selectedFinding['related_task']))
                    <a href="{{ route('demo.task', ['taskId' => $selectedFinding['related_task']]) }}" wire:navigate class="rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Open task</a>
                @endif
                <span class="rounded-lg px-3 py-1.5 text-xs text-gray-400" title="Demo Mode — individual recheck not wired to a live runner">Recheck unavailable in Demo</span>
            </div>
        </div>
    @endif

    <div class="space-y-2">
        @forelse ($healthFindings as $item)
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ta.badge :color="match($item['severity']) { 'high' => 'error', 'medium' => 'warning', default => 'info' }" size="sm">{{ $item['severity'] }}</x-ta.badge>
                            <span class="text-xs text-gray-400">{{ $health['groups'][$item['group']] ?? $item['group'] }}</span>
                            <span class="text-xs text-gray-500">{{ $item['actionability'] }}</span>
                        </div>
                        <p class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">{{ $item['title'] }}</p>
                        <p class="mt-1 text-xs text-gray-500">{{ $item['where'] }} · {{ $item['affected_scope'] }}</p>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $item['why'] }}</p>
                    </div>
                    <button type="button" wire:click="openFinding('{{ $item['id'] }}')" class="shrink-0 rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Review finding</button>
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">No findings match these filters.</p>
        @endforelse
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">WordPress / CMS</h3>
            <dl class="mt-3 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                <div class="flex justify-between gap-3"><span class="text-gray-500">Version</span><span>{{ $health['wordpress']['version'] }}</span></div>
                <div class="flex justify-between gap-3"><span class="text-gray-500">Theme</span><span>{{ $health['wordpress']['theme'] }}</span></div>
                <div class="flex justify-between gap-3"><span class="text-gray-500">Plugins</span><span>{{ $health['wordpress']['plugin_count'] }} · {{ $health['wordpress']['plugin_updates'] }} updates available</span></div>
                <div class="flex justify-between gap-3"><span class="text-gray-500">REST</span><span>{{ $health['wordpress']['rest_state'] }}</span></div>
            </dl>
            <p class="mt-3 text-xs text-gray-400">{{ $health['wordpress']['note'] }}</p>
            <p class="mt-2 text-xs text-gray-500">Update available ≠ known vulnerability.</p>
        </section>

        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Availability</h3>
            @if (! $health['availability']['configured'])
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Availability monitoring is not configured.</p>
            @endif
            <p class="mt-2 text-xs text-amber-700 dark:text-amber-400">{{ $health['availability']['demo_note'] }}</p>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($health['availability']['demo_timeline'] as $incident)
                    <li class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                        <p class="font-medium text-gray-800 dark:text-white/90">{{ $incident['date'] }} · {{ $incident['window'] }}</p>
                        <p class="text-xs text-gray-500">{{ $incident['state'] }} · {{ $incident['duration'] }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>
</div>
