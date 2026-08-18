<div class="space-y-4">
  <x-ta.card :title="__('operator.prospects.research_runs')">
    @if (($detail['research_runs'] ?? []) === [])
      <p class="text-sm text-gray-500">{{ __('operator.prospects.no_research_runs') }}</p>
    @else
      <ul class="divide-y divide-gray-100 dark:divide-gray-700">
        @foreach ($detail['research_runs'] as $run)
          <li class="py-3 text-sm">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <span class="font-medium text-gray-800 dark:text-white/90">{{ $run['status_label'] }}</span>
              <span class="text-xs text-gray-500">{{ $run['finished_at'] ?? $run['started_at'] }}</span>
            </div>
            @if (!empty($run['seed_url']))
              <p class="mt-1 text-xs text-gray-500">{{ $run['seed_url'] }}</p>
            @endif
            @if (!empty($run['message']))
              <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $run['message'] }}</p>
            @endif
          </li>
        @endforeach
      </ul>
    @endif
  </x-ta.card>

  <x-ta.card :title="__('operator.prospects.observed_facts')">
    @if (($detail['evidence'] ?? []) === [])
      <p class="text-sm text-gray-500">{{ __('operator.prospects.no_evidence') }}</p>
    @else
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
              <th class="px-2 py-2">{{ __('operator.prospects.fields.title') }}</th>
              <th class="px-2 py-2">{{ __('operator.prospects.provenance_column') }}</th>
              <th class="px-2 py-2">{{ __('operator.prospects.fields.source') }}</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($detail['evidence'] as $fact)
              <tr class="border-t border-gray-100 dark:border-gray-700">
                <td class="px-2 py-2 text-gray-800 dark:text-white/90">{{ $fact['title'] }}</td>
                <td class="px-2 py-2"><x-ta.badge color="light" size="sm">{{ $fact['provenance_label'] }}</x-ta.badge></td>
                <td class="px-2 py-2 text-xs text-gray-500">{{ $fact['source_url'] ?? '—' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </x-ta.card>

  @if (($detail['candidates'] ?? []) !== [])
    <x-ta.card :title="__('operator.prospects.candidates')">
      <ul class="space-y-2 text-sm">
        @foreach ($detail['candidates'] as $candidate)
          <li class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
            <p class="font-medium text-gray-800 dark:text-white/90">{{ $candidate['value'] }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ $candidate['type'] }} · {{ $candidate['provenance_label'] }}</p>
          </li>
        @endforeach
      </ul>
    </x-ta.card>
  @endif
</div>
