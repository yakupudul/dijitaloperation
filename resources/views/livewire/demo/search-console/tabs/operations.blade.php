@php $opsData = $data['operations'] ?? []; @endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Operations</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $opsData['subtitle'] ?? 'Findings, recommendations, tasks, and observed outcomes' }}</p>
    </div>

    <div class="inline-flex rounded-lg ring-1 ring-inset ring-gray-300 dark:ring-gray-700" role="tablist">
        @foreach (['findings' => 'Findings', 'recommendations' => 'Recommendations', 'tasks' => 'Tasks', 'outcomes' => 'Outcomes'] as $key => $label)
            <button type="button" wire:click="setOps('{{ $key }}')" @class([
                'px-3 py-2 text-xs font-medium',
                'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' => $ops === $key,
                'text-gray-600 dark:text-gray-300' => $ops !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($ops === 'findings')
        <x-ta.table>
            <x-slot:head>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Severity</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Category</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Finding</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Status</th>
            </x-slot:head>
            @foreach ($opsData['findings'] ?? [] as $f)
                <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                    <td class="px-4 py-2.5"><x-ta.badge :color="match($f['severity']) { 'critical', 'Critical', 'high', 'High' => 'error', 'medium', 'Medium' => 'warning', default => 'light' }" size="sm">{{ $f['severity'] }}</x-ta.badge></td>
                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $f['category'] }}</td>
                    <td class="px-4 py-2.5"><button type="button" wire:click="openFinding('{{ $f['id'] }}')" class="text-left text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $f['title'] }}</button></td>
                    <td class="px-4 py-2.5 text-xs">{{ $f['status'] }}</td>
                </tr>
            @endforeach
        </x-ta.table>
    @elseif ($ops === 'recommendations')
        <ul class="space-y-2">
            @foreach ($opsData['recommendations'] ?? [] as $r)
                <li class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="text-xs text-gray-400">{{ $r['status'] }} · {{ $r['finding_id'] }}</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $r['title'] }}</p>
                    @if (! empty($r['detail']))
                        <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $r['detail'] }}</p>
                    @endif
                    <button type="button" wire:click="openFinding('{{ $r['finding_id'] }}')" class="mt-2 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open Finding</button>
                </li>
            @endforeach
        </ul>
    @elseif ($ops === 'tasks')
        <x-ta.table>
            <x-slot:head>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Task</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Owner</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Due</th>
                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-400">Status</th>
            </x-slot:head>
            @foreach ($opsData['tasks'] ?? [] as $t)
                <tr>
                    <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $t['title'] }}</td>
                    <td class="px-4 py-2.5 text-xs">{{ $t['owner'] }}</td>
                    <td class="px-4 py-2.5 text-xs">{{ $t['due'] }}</td>
                    <td class="px-4 py-2.5 text-xs font-medium">{{ $t['status'] }}</td>
                </tr>
            @endforeach
        </x-ta.table>
    @else
        <ul class="space-y-2">
            @foreach ($opsData['outcomes'] ?? [] as $o)
                <li class="flex flex-col gap-1 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03] sm:flex-row sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $o['task'] ?? $o['title'] }}</p>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $o['note'] }}</p>
                    </div>
                    <span @class([
                        'text-xs font-semibold',
                        'text-emerald-700 dark:text-emerald-400' => ($o['state'] ?? '') === 'Improvement observed',
                        'text-amber-700 dark:text-amber-400' => ($o['state'] ?? '') !== 'Improvement observed',
                    ])>{{ $o['state'] }}</span>
                </li>
            @endforeach
        </ul>
        <p class="text-xs text-gray-500">Observational language only — outcomes do not claim the Task caused the change.</p>
    @endif
</div>
