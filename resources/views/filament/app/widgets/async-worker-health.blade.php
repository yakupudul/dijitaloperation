<x-filament-widgets::widget>
    <x-filament::section
        :heading="'Queue health'"
        :description="$health['message']"
    >
        <dl class="grid gap-4 sm:grid-cols-4">
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Waiting jobs</dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $health['pending_jobs'] }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Oldest waiting</dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                    @if ($health['oldest_queued_job_age_seconds'] === null)
                        —
                    @else
                        {{ $health['oldest_queued_job_age_seconds'] }}s
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Last completed operation</dt>
                <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                    {{ $health['last_processed_at'] ?? '—' }}
                </dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Worker signal</dt>
                <dd @class([
                    'mt-1 text-sm font-medium',
                    'text-danger-600 dark:text-danger-400' => $health['worker_appears_idle'],
                    'text-success-600 dark:text-success-400' => ! $health['worker_appears_idle'],
                ])>
                    {{ $health['worker_appears_idle'] ? 'Needs attention' : 'OK' }}
                </dd>
            </div>
        </dl>
    </x-filament::section>
</x-filament-widgets::widget>
