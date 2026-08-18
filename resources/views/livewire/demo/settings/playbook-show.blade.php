<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @if ($playbook === null)
        <p class="text-sm text-gray-500">{{ __('operator.playbooks.not_found') }}</p>
        <x-ta.button href="{{ route('operator.settings', ['section' => 'operations', 'ops_sub' => 'playbooks']) }}" wire:navigate>{{ __('operator.playbooks.back') }}</x-ta.button>
    @else
        <div>
            <a href="{{ route('operator.settings', ['section' => 'operations', 'ops_sub' => 'playbooks']) }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-brand-600">← {{ __('operator.playbooks.catalog') }}</a>
            <h1 class="mt-2 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $playbook['name'] }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ __('operator.playbooks.standard_label') }} · {{ $playbook['service_label'] }} · {{ ucfirst($playbook['cadence'] ?? '') }}</p>
        </div>

        <x-ta.card>
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.playbooks.overview') }}</h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $playbook['purpose'] }}</p>
            <p class="mt-3 text-sm text-gray-500">{{ __('operator.playbooks.default_owner') }}: {{ $playbook['default_owner_name'] }}</p>
        </x-ta.card>

        @if (! empty($playbook['when_to_use']))
            <x-ta.card>
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.playbooks.when_to_use') }}</h2>
                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                    @foreach ($playbook['when_to_use'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            </x-ta.card>
        @endif

        @if (! empty($playbook['when_not_to_use']))
            <x-ta.card>
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.playbooks.when_not_to_use') }}</h2>
                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                    @foreach ($playbook['when_not_to_use'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            </x-ta.card>
        @endif

        @if (! empty($playbook['methodology']))
            <x-ta.card>
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.playbooks.methodology') }}</h2>
                <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                    @foreach ($playbook['methodology'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ol>
            </x-ta.card>
        @endif

        @if (! empty($playbook['qa_guidance']))
            <x-ta.card>
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.playbooks.qa_guidance') }}</h2>
                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                    @foreach ($playbook['qa_guidance'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            </x-ta.card>
        @endif

        @if (! empty($playbook['related_ai_skill']))
            <x-ta.card>
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.playbooks.related_ai_skill') }}</h2>
                <p class="mt-2 text-sm font-medium text-gray-800 dark:text-white/90">{{ $playbook['related_ai_skill']['name'] ?? '' }}</p>
                <p class="mt-1 text-sm text-gray-500">{{ $playbook['related_ai_skill']['note'] ?? __('operator.playbooks.ai_skill_note') }}</p>
            </x-ta.card>
        @endif

        <div class="grid gap-4 lg:grid-cols-2">
            <x-ta.card>
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.playbooks.checklist') }}</h2>
                <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-gray-600 dark:text-gray-300">
                    @foreach ($playbook['checklist'] ?? [] as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ol>
            </x-ta.card>
            <x-ta.card>
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.playbooks.instructions') }}</h2>
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ $playbook['instructions'] }}</p>
                <h3 class="mt-4 text-xs font-semibold uppercase text-gray-400">{{ __('operator.playbooks.applicability') }}</h3>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    @foreach ($playbook['asset_types'] ?? [] as $assetType)
                        <x-demo.digital-asset-mark :type="$assetType" size="sm" />
                    @endforeach
                </div>
            </x-ta.card>
        </div>

        <x-ta.card>
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.playbooks.references') }}</h2>
            <ul class="mt-3 space-y-2">
                @foreach ($playbook['references'] ?? [] as $ref)
                    <li>
                        @if (! empty($ref['href']))
                            @if (($ref['kind'] ?? '') === 'external_url')
                                <a href="{{ $ref['href'] }}" target="_blank" rel="noopener noreferrer" class="text-sm font-medium text-brand-600 hover:underline">{{ $ref['label'] }}</a>
                            @else
                                <a href="{{ $ref['href'] }}" wire:navigate class="text-sm font-medium text-brand-600 hover:underline">{{ $ref['label'] }}</a>
                            @endif
                        @else
                            <span class="text-sm text-gray-500">{{ $ref['label'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ta.card>

        <x-ta.card>
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.playbooks.recent_reviews') }}</h2>
            <ul class="mt-3 space-y-2 text-sm">
                @forelse ($recentReviews as $review)
                    <li class="flex justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                        <span>{{ $review['brand'] }} · {{ $review['due'] }}</span>
                        <span class="text-gray-500">{{ $review['status'] }}</span>
                    </li>
                @empty
                    <li class="text-gray-500">{{ __('operator.reviews.none_due') }}</li>
                @endforelse
            </ul>
        </x-ta.card>
    @endif
</div>
