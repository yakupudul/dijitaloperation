<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @if ($item === null)
        <p class="text-sm text-gray-500">{{ __('operator.work.not_found') }}</p>
        <x-ta.button href="{{ route('demo.tasks') }}" wire:navigate>{{ __('operator.work.back') }}</x-ta.button>
    @else
        <div>
            <a href="{{ route('demo.tasks') }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-brand-600">← {{ __('operator.work.title') }}</a>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">{{ $item['title'] ?? ($item['playbook_name'] ?? 'Work item') }}</h1>
                <x-ta.badge color="light" size="sm">{{ __('operator.work.types.'.$type) }}</x-ta.badge>
            </div>
            <p class="mt-1 text-sm text-gray-500">{{ $item['brand'] ?? '' }} · {{ $item['customer'] ?? '' }}</p>
        </div>

        <x-ta.card>
            <dl class="grid gap-3 sm:grid-cols-2 text-sm">
                <div><dt class="text-gray-400">{{ __('operator.work.columns.status') }}</dt><dd class="font-medium">{{ $item['status'] ?? '—' }}</dd></div>
                <div><dt class="text-gray-400">{{ __('operator.work.columns.owner') }}</dt><dd class="font-medium">{{ $item['owner'] ?? '—' }}</dd></div>
                <div><dt class="text-gray-400">{{ __('operator.work.columns.due') }}</dt><dd class="font-medium">{{ $item['due'] ?? '—' }}</dd></div>
                @if (! empty($item['service_label']))
                    <div><dt class="text-gray-400">{{ __('operator.commercial.service') }}</dt><dd class="font-medium">{{ $item['service_label'] }}</dd></div>
                @endif
                @if (! empty($item['description']))
                    <div class="sm:col-span-2"><dt class="text-gray-400">{{ __('operator.requests.description') }}</dt><dd class="mt-1">{{ $item['description'] }}</dd></div>
                @endif
            </dl>
        </x-ta.card>

        @if (! empty($knowledgeContext))
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.value.work_context') }}</h2>
                <dl class="mt-3 grid gap-2 sm:grid-cols-2 text-sm">
                    <div>
                        <dt class="text-xs text-gray-400">{{ __('operator.value.work_context_service') }}</dt>
                        <dd class="font-medium text-gray-800 dark:text-white/90">{{ $knowledgeContext['service'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-400">{{ __('operator.value.work_context_goal') }}</dt>
                        <dd class="font-medium text-gray-800 dark:text-white/90">{{ $knowledgeContext['goal'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-400">{{ __('operator.value.work_context_playbook') }}</dt>
                        <dd class="font-medium">
                            @if (! empty($knowledgeContext['playbook']['url']))
                                <a href="{{ $knowledgeContext['playbook']['url'] }}" wire:navigate class="text-brand-600 hover:underline">{{ $knowledgeContext['playbook']['name'] ?? 'Playbook' }}</a>
                            @else
                                {{ $knowledgeContext['playbook']['name'] ?? '—' }}
                            @endif
                        </dd>
                    </div>
                    @if (! empty($knowledgeContext['decision']))
                        <div>
                            <dt class="text-xs text-gray-400">{{ __('operator.value.work_context_decision') }}</dt>
                            <dd class="font-medium text-gray-800 dark:text-white/90">{{ $knowledgeContext['decision']['title'] ?? '' }}</dd>
                        </div>
                    @endif
                </dl>
                @if (! empty($knowledgeContext['qa_guidance']))
                    <details class="mt-3">
                        <summary class="cursor-pointer text-sm font-medium text-gray-600 dark:text-gray-300">{{ __('operator.value.work_context_qa') }}</summary>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                            @foreach ($knowledgeContext['qa_guidance'] as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </div>
        @endif

        <div class="flex flex-wrap gap-2">
            @if ($type === 'client_request')
                <x-ta.button wire:click="triage" size="sm" variant="outline">{{ __('operator.requests.actions.triage') }}</x-ta.button>
                <x-ta.button wire:click="plan" size="sm" variant="outline">{{ __('operator.requests.actions.plan') }}</x-ta.button>
                <x-ta.button wire:click="waitOnClient" size="sm" variant="outline">{{ __('operator.requests.actions.wait') }}</x-ta.button>
                <x-ta.button wire:click="createTask" size="sm">{{ __('operator.requests.actions.create_task') }}</x-ta.button>
                <x-ta.button wire:click="markDone" size="sm" variant="outline">{{ __('operator.requests.actions.done') }}</x-ta.button>
                <x-ta.button wire:click="decline" size="sm" variant="outline">{{ __('operator.requests.actions.decline') }}</x-ta.button>
            @elseif ($type === 'recurring_review')
                <x-ta.button wire:click="completeReview('no_issue')" size="sm" variant="outline">{{ __('operator.reviews.complete_no_issue') }}</x-ta.button>
                <x-ta.button wire:click="completeReview('opportunity')" size="sm" variant="outline">{{ __('operator.reviews.complete_opportunity') }}</x-ta.button>
                <x-ta.button wire:click="completeReview('task')" size="sm">{{ __('operator.reviews.complete_task') }}</x-ta.button>
                <x-ta.button wire:click="skipReview" size="sm" variant="outline">{{ __('operator.reviews.skip') }}</x-ta.button>
            @elseif ($type === 'approval')
                <x-ta.button wire:click="approve" size="sm">{{ __('operator.approvals.approve') }}</x-ta.button>
            @elseif ($type === 'task')
                <x-ta.button wire:click="approveQa" size="sm" variant="outline">{{ __('operator.qa.approve') }}</x-ta.button>
            @endif
        </div>
    @endif
</div>
