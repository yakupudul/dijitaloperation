@php
    $opsData = $data['operations'];
@endphp

<div class="space-y-5">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Operations</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $opsData['subtitle'] }}</p>
    </div>

    <div class="inline-flex rounded-lg ring-1 ring-inset ring-gray-300 dark:ring-gray-700" role="tablist" aria-label="Operations sections">
        @foreach (['findings' => 'Findings', 'recommendations' => 'Recommendations', 'tasks' => 'Tasks', 'outcomes' => 'Outcomes'] as $key => $label)
            <button type="button" role="tab" wire:click="setOps('{{ $key }}')" @class([
                'px-3 py-2 text-xs font-medium',
                'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' => $ops === $key,
                'text-gray-600 dark:text-gray-300' => $ops !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($ops === 'findings')
        <div class="grid gap-4 lg:grid-cols-12">
            <section class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 lg:col-span-7">
                <table class="min-w-full text-left text-sm">
                    <thead class="border-b border-gray-100 text-xs uppercase text-gray-500 dark:border-gray-700">
                        <tr>
                            <th class="px-4 py-2">Severity</th>
                            <th class="px-4 py-2">Category</th>
                            <th class="px-4 py-2">Finding</th>
                            <th class="px-4 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($opsData['findings'] as $finding)
                            <tr @class(['bg-brand-50/40 dark:bg-brand-500/5' => $finding['id'] === $this->finding])>
                                <td class="px-4 py-2 text-xs font-semibold uppercase">{{ $finding['severity'] }}</td>
                                <td class="px-4 py-2 text-xs text-gray-500">{{ $finding['category'] }}</td>
                                <td class="px-4 py-2">
                                    <button type="button" wire:click="openFinding('{{ $finding['id'] }}')" class="text-left font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $finding['title'] }}</button>
                                    @if (! empty($finding['affected']))
                                        <p class="text-xs text-gray-400">Affected · {{ $finding['affected'] }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-xs">{{ $finding['status'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            <aside class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 lg:col-span-5">
                @if ($selectedFinding)
                    <div class="flex items-start justify-between gap-2">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Finding detail</h3>
                        <button type="button" wire:click="closeFinding" class="text-xs text-gray-500 hover:underline">Close</button>
                    </div>
                    <p class="mt-2 text-sm font-medium text-gray-900 dark:text-white">{{ $selectedFinding['title'] }}</p>
                    <dl class="mt-3 space-y-2 text-sm">
                        @foreach ([
                            'what' => 'What happened?',
                            'where' => 'Where?',
                            'why' => 'Why it matters',
                            'evidence' => 'Evidence',
                            'affected' => 'Affected',
                            'owner' => 'Who can act',
                            'next' => 'What should happen next',
                            'verify' => 'How we verify',
                        ] as $key => $label)
                            @if (! empty($selectedFinding[$key]))
                                <div>
                                    <dt class="text-xs text-gray-400">{{ $label }}</dt>
                                    <dd class="text-gray-700 dark:text-gray-300">{{ $selectedFinding[$key] }}</dd>
                                </div>
                            @endif
                        @endforeach
                    </dl>
                    <p class="mt-3 text-xs text-gray-400">Tasks are not auto-created from Findings.</p>
                @else
                    <p class="text-sm text-gray-500">Select a Finding to inspect evidence and next steps.</p>
                @endif
            </aside>
        </div>
    @elseif ($ops === 'recommendations')
        <ul class="space-y-2">
            @foreach ($opsData['recommendations'] as $rec)
                <li class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <p class="text-xs text-gray-400">{{ $rec['status'] }} · Finding {{ $rec['finding_id'] }}</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $rec['title'] }}</p>
                    <button type="button" wire:click="openFinding('{{ $rec['finding_id'] }}')" class="mt-2 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open related finding</button>
                </li>
            @endforeach
        </ul>
    @elseif ($ops === 'tasks')
        <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-gray-100 text-xs uppercase text-gray-500 dark:border-gray-700">
                    <tr>
                        <th class="px-4 py-2">Task</th>
                        <th class="px-4 py-2">Owner</th>
                        <th class="px-4 py-2">Due</th>
                        <th class="px-4 py-2">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($opsData['tasks'] as $task)
                        <tr>
                            <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">{{ $task['title'] }}</td>
                            <td class="px-4 py-2 text-xs">{{ $task['owner'] }}</td>
                            <td class="px-4 py-2 text-xs">{{ $task['due'] }}</td>
                            <td class="px-4 py-2 text-xs font-medium">{{ $task['status'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <ul class="space-y-2">
            @foreach ($opsData['outcomes'] as $outcome)
                <li class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $outcome['task'] }}</p>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $outcome['note'] }}</p>
                        </div>
                        <span @class([
                            'text-xs font-semibold',
                            'text-emerald-700 dark:text-emerald-400' => $outcome['state'] === 'Improvement observed',
                            'text-amber-700 dark:text-amber-400' => $outcome['state'] === 'Still observed',
                        ])>{{ $outcome['state'] }}</span>
                    </div>
                </li>
            @endforeach
        </ul>
        <p class="text-xs text-gray-500">Observational language only — outcomes do not claim the Task caused ranking or review changes.</p>
    @endif

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">AI guidance</h3>
        <p class="mt-1 text-xs text-gray-400">{{ $data['ai_guidance']['disclaimer'] }}</p>
        <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
            @foreach ($data['ai_guidance']['what_matters'] as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
    </section>
</div>
