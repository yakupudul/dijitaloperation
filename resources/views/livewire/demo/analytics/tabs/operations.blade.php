@php $opsData = $data['operations'] ?? []; @endphp

<div class="space-y-10">
    <div>
        <h2 class="section-title">Operations</h2>
        <p class="mt-0.5 text-xs text-gray-500">{{ $opsData['subtitle'] ?? 'Findings, recommendations, tasks, and observed outcomes' }}</p>
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
        <div class="max-w-full overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-gray-200 dark:border-gray-800">
                    <tr>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Severity</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Category</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Finding</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Status</th>
            </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
            @foreach ($opsData['findings'] ?? [] as $f)
                <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                    <td class="px-4 py-2.5"><x-ta.pill :tone="['error' => 'critical', 'warning' => 'look', 'success' => 'ok'][match($f['severity']) { 'critical', 'Critical', 'high', 'High' => 'error', 'medium', 'Medium' => 'warning', default => 'light' }] ?? 'info'">{{ $f['severity'] }}</x-ta.pill></td>
                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $f['category'] }}</td>
                    <td class="px-4 py-2.5"><button type="button" wire:click="openFinding('{{ $f['id'] }}')" class="text-left text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $f['title'] }}</button></td>
                    <td class="px-4 py-2.5 text-xs">{{ $f['status'] }}</td>
                </tr>
            @endforeach
        </tbody>
            </table>
        </div>
    @elseif ($ops === 'recommendations')
        <ul class="space-y-2">
            @foreach ($opsData['recommendations'] ?? [] as $r)
                <li class="border-b border-gray-200 py-3 dark:border-gray-800">
                    <p class="text-xs text-gray-500">{{ $r['status'] }}, {{ $r['finding_id'] }}</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $r['title'] }}</p>
                    @if (! empty($r['detail']))
                        <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $r['detail'] }}</p>
                    @endif
                    <button type="button" wire:click="openFinding('{{ $r['finding_id'] }}')" class="mt-2 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open Finding</button>
                </li>
            @endforeach
        </ul>
    @elseif ($ops === 'tasks')
        <div class="max-w-full overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-gray-200 dark:border-gray-800">
                    <tr>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Task</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Owner</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Due</th>
                <th class="px-4 py-2.5 text-left text-xs font-normal text-gray-500">Status</th>
            </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
            @foreach ($opsData['tasks'] ?? [] as $t)
                <tr>
                    <td class="px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-white">{{ $t['title'] }}</td>
                    <td class="px-4 py-2.5 text-xs">{{ $t['owner'] }}</td>
                    <td class="px-4 py-2.5 text-xs">{{ $t['due'] }}</td>
                    <td class="px-4 py-2.5 text-xs font-medium">{{ $t['status'] }}</td>
                </tr>
            @endforeach
        </tbody>
            </table>
        </div>
    @else
        <ul class="space-y-2">
            @foreach ($opsData['outcomes'] ?? [] as $o)
                <li class="flex flex-col gap-1 border-b border-gray-200 py-3 dark:border-gray-800 sm:flex-row sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $o['task'] ?? $o['title'] }}</p>
                        <p class="mt-0.5 text-xs text-gray-500">{{ $o['note'] }}</p>
                    </div>
                    <span @class([
                        'text-xs font-semibold',
                        'text-success-700 dark:text-success-400' => ($o['state'] ?? '') === 'Improvement observed',
                        'text-warning-700 dark:text-warning-400' => ($o['state'] ?? '') !== 'Improvement observed',
                    ])>{{ $o['state'] }}</span>
                </li>
            @endforeach
        </ul>
        <p class="text-xs text-gray-500">Observational language only — outcomes do not claim the Task caused the change.</p>

        @if (! empty($opsData['decision_history']))
            <section class="min-w-0">
                <h3 class="section-title">Decision history</h3>
                <ol class="mt-3 space-y-2 text-sm">
                    @foreach ($opsData['decision_history'] as $event)
                        <li class="flex gap-3"><span class="w-14 shrink-0 text-xs text-gray-500">{{ $event['when'] }}</span><span>{{ $event['event'] }}</span></li>
                    @endforeach
                </ol>
            </section>
        @endif
    @endif
</div>
