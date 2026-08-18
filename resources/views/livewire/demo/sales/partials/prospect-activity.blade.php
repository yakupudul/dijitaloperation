<x-ta.card :title="__('operator.prospects.tabs.activity')">
  @if ($activities === [])
    <p class="text-sm text-gray-500">{{ __('operator.prospects.no_activity') }}</p>
  @else
    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
      @foreach ($activities as $activity)
        <li class="py-3">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $activity['title'] }}</p>
            <span class="text-xs text-gray-500">{{ $activity['occurred_at'] }}</span>
          </div>
          @if (!empty($activity['description']))
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $activity['description'] }}</p>
          @endif
          @if (!empty($activity['actor_name']))
            <p class="mt-1 text-xs text-gray-500">{{ $activity['actor_name'] }}</p>
          @endif
        </li>
      @endforeach
    </ul>
  @endif
</x-ta.card>
