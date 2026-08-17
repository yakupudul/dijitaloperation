<div class="space-y-5">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator.website.tabs.operations') }}</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Findings, Recommendations, Work, and Outcomes for this Website — shared global models, filtered here.</p>
    </div>

    <div class="inline-flex flex-wrap rounded-lg ring-1 ring-inset ring-gray-300 dark:ring-gray-700" role="tablist" aria-label="Operations sections">
        @foreach (['findings' => __('operator.nav.findings'), 'recommendations' => __('operator.nav.recommendations'), 'tasks' => __('operator.work.tasks_label'), 'outcomes' => 'Outcomes'] as $key => $label)
            <button type="button" role="tab" wire:click="setOps('{{ $key }}')" @class([
                'px-3 py-2 text-xs font-medium',
                'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' => $ops === $key,
                'text-gray-600 dark:text-gray-300' => $ops !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($ops === 'findings')
        <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-gray-100 text-xs uppercase text-gray-500 dark:border-gray-700">
                    <tr>
                        <th class="px-4 py-2">Severity</th>
                        <th class="px-4 py-2">Finding</th>
                        <th class="px-4 py-2">Group</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($opsFindings as $finding)
                        <tr>
                            <td class="px-4 py-2 text-xs font-semibold uppercase">{{ $finding['severity'] ?? '—' }}</td>
                            <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">{{ $finding['title'] ?? 'Finding' }}</td>
                            <td class="px-4 py-2 text-xs text-gray-500">{{ $finding['group'] ?? ($finding['category'] ?? '—') }}</td>
                            <td class="px-4 py-2 text-right">
                                <a href="{{ route('demo.findings') }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">{{ __('operator.actions.open') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-sm text-gray-500">No open findings for this Website.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-400">
            <a href="{{ route('demo.findings') }}" wire:navigate class="font-medium text-brand-600 hover:underline">{{ __('operator.nav.findings') }}</a>
            · canonical global queue
        </p>
    @elseif ($ops === 'recommendations')
        <ul class="space-y-2">
            @forelse ($opsRecommendations as $rec)
                <li class="rounded-xl bg-white px-4 py-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $rec['title'] ?? 'Recommendation' }}</p>
                    <p class="text-xs text-gray-500">{{ $rec['status'] ?? '' }} · {{ $rec['asset'] ?? 'Website' }}</p>
                </li>
            @empty
                <li class="text-sm text-gray-500">No recommendations scoped to this Website.</li>
            @endforelse
        </ul>
        <a href="{{ route('demo.recommendations') }}" wire:navigate class="inline-flex text-xs font-medium text-brand-600 hover:underline">{{ __('operator.nav.recommendations') }}</a>
    @elseif ($ops === 'tasks')
        <ul class="space-y-2">
            @forelse ($opsTasks as $task)
                <li class="rounded-xl bg-white px-4 py-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $task['title'] ?? 'Task' }}</p>
                    <p class="text-xs text-gray-500">{{ $task['status'] ?? '' }} · {{ $task['owner'] ?? ($task['assignee'] ?? '—') }}</p>
                </li>
            @empty
                <li class="text-sm text-gray-500">No open work scoped to this Website.</li>
            @endforelse
        </ul>
        <a href="{{ route('demo.tasks') }}" wire:navigate class="inline-flex text-xs font-medium text-brand-600 hover:underline">{{ __('operator.nav.work') }}</a>
    @else
        <ul class="space-y-2">
            @forelse ($opsOutcomes as $outcome)
                <li class="rounded-xl bg-white px-4 py-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $outcome['title'] ?? ($outcome['label'] ?? 'Outcome') }}</p>
                    <p class="text-xs text-gray-500">{{ $outcome['detail'] ?? ($outcome['summary'] ?? '') }}</p>
                </li>
            @empty
                <li class="text-sm text-gray-500">No recent operational outcomes for this Website.</li>
            @endforelse
        </ul>
    @endif

    <p class="text-xs text-gray-500">
        <a href="{{ route('demo.activity', ['asset' => $this->assetId]) }}" wire:navigate class="font-medium text-brand-600 hover:underline">{{ __('operator.website.actions.view_activity') }}</a>
        · opens global Activity filtered to this Website
    </p>
</div>
