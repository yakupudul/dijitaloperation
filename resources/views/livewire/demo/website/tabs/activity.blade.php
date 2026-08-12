<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Activity</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">What MoxDOP and operators have done for this Website.</p>
    </div>

    <div class="flex flex-wrap gap-1">
        @foreach (['all' => 'All', 'collection' => 'Data collection', 'diagnosis' => 'Diagnosis', 'seo' => 'SEO intelligence', 'discovery' => 'Discovery', 'ai' => 'AI analysis', 'operator' => 'Operator changes', 'failure' => 'Failures'] as $key => $label)
            <button type="button" wire:click="setActivityFilter('{{ $key }}')" @class([
                'rounded-md px-2.5 py-1.5 text-xs font-medium',
                'bg-brand-500 text-white' => $activity_filter === $key,
                'ring-1 ring-inset ring-gray-300 text-gray-600 dark:ring-gray-700 dark:text-gray-300' => $activity_filter !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    <ul class="space-y-2">
        @forelse ($activityRows as $row)
            <li class="rounded-xl bg-white px-4 py-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['title'] }}</p>
                        <p class="text-xs text-gray-500">{{ $row['detail'] }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-400">{{ $row['when'] }}</p>
                        <p class="text-[11px] uppercase tracking-wide text-gray-400">{{ $row['category'] }}</p>
                    </div>
                </div>
            </li>
        @empty
            <li class="text-sm text-gray-500">No activity matches this filter.</li>
        @endforelse
    </ul>
</div>
