@if ($intelligence === null)
  <x-ta.card>
    <p class="text-sm text-gray-500">{{ __('operator.prospects.no_intelligence') }}</p>
  </x-ta.card>
@else
  <div class="space-y-4">
    <x-ta.card>
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ __('operator.prospects.tabs.intelligence') }}</h2>
        <x-ta.badge color="light" size="sm">{{ $intelligence['status_label'] }}</x-ta.badge>
      </div>
      @if (!empty($intelligence['summary']))
        <p class="mt-3 text-sm text-gray-700 dark:text-gray-200">{{ $intelligence['summary'] }}</p>
      @endif
      @if (!empty($intelligence['overall_confidence']))
        <p class="mt-2 text-xs text-gray-500">{{ __('operator.prospects.overall_confidence') }}: {{ $intelligence['overall_confidence'] }}</p>
      @endif
    </x-ta.card>

  @if (!empty($intelligence['recommended_services']))
    <x-ta.card :title="__('operator.prospects.recommended_services')">
      <ul class="space-y-3">
        @foreach ($intelligence['recommended_services'] as $service)
          <li class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-medium text-gray-800 dark:text-white/90">{{ $service['service_definition_label'] ?? $service['service_definition_code'] }}</span>
              <x-ta.badge color="light" size="sm">{{ $service['priority'] ?? '' }}</x-ta.badge>
            </div>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $service['rationale'] ?? '' }}</p>
            @if (!empty($service['evidence_refs']))
              <p class="mt-2 text-xs text-gray-500">{{ __('operator.prospects.evidence_refs') }}: {{ collect($service['evidence_refs'])->pluck('evidence_id')->join(', ') }}</p>
            @endif
          </li>
        @endforeach
      </ul>
    </x-ta.card>
  @endif

  @if (!empty($intelligence['not_recommended_services']))
    <x-ta.card :title="__('operator.prospects.not_recommended')">
      <ul class="space-y-2 text-sm">
        @foreach ($intelligence['not_recommended_services'] as $service)
          <li>
            <span class="font-medium">{{ $service['service_definition_label'] ?? $service['service_definition_code'] }}</span>
            <span class="text-gray-500"> — {{ $service['rationale'] ?? '' }}</span>
          </li>
        @endforeach
      </ul>
    </x-ta.card>
  @endif

  @if (!empty($intelligence['first_meeting_focus']))
    <x-ta.card :title="__('operator.prospects.first_meeting_focus')">
      <p class="text-sm text-gray-700 dark:text-gray-200">{{ $intelligence['first_meeting_focus'] }}</p>
    </x-ta.card>
  @endif

  @if (!empty($intelligence['diagnostic_questions']))
    <x-ta.card :title="__('operator.prospects.diagnostic_questions')">
      <ul class="list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-200">
        @foreach ($intelligence['diagnostic_questions'] as $question)
          <li>{{ $question }}</li>
        @endforeach
      </ul>
    </x-ta.card>
  @endif

  @if (!empty($intelligence['uncertainties']))
    <x-ta.card :title="__('operator.prospects.uncertainties')">
      <ul class="list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
        @foreach ($intelligence['uncertainties'] as $item)
          <li>{{ $item }}</li>
        @endforeach
      </ul>
    </x-ta.card>
  @endif
  </div>
@endif
