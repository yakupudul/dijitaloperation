<x-filament-panels::page>
    <div class="mb-6 flex flex-wrap gap-3 text-sm">
        <a href="{{ $control_plane_url }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">Routes</a>
        <span class="text-gray-400">·</span>
        <a href="{{ $agents_url }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">Agent Profiles</a>
        <span class="text-gray-400">·</span>
        <span class="font-semibold text-gray-950 dark:text-white">Skill Library</span>
    </div>

    <div class="grid gap-6 lg:grid-cols-[18rem_minmax(0,1fr)]">
        <x-filament::section>
            <x-slot name="heading">Built-in Skills</x-slot>
            <x-slot name="description">Curated module methodologies. Read-only in V1.</x-slot>

            <ul class="space-y-2">
                @foreach ($skills as $skill)
                    <li>
                        <button
                            type="button"
                            wire:click="selectSkill('{{ $skill['slug'] }}')"
                            @class([
                                'w-full rounded-lg px-3 py-2 text-left text-sm transition',
                                'bg-primary-50 text-primary-700 dark:bg-primary-400/10 dark:text-primary-300' => $selected_skill && $selected_skill['slug'] === $skill['slug'],
                                'hover:bg-gray-50 dark:hover:bg-white/5 text-gray-700 dark:text-gray-200' => ! ($selected_skill && $selected_skill['slug'] === $skill['slug']),
                            ])
                        >
                            <div class="font-medium">{{ $skill['name'] }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $skill['module'] }} · v{{ $skill['version'] }}</div>
                        </button>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>

        @if ($selected_skill)
            <x-filament::section>
                <x-slot name="heading">{{ $selected_skill['name'] }}</x-slot>
                <x-slot name="description">{{ $selected_skill['purpose'] }}</x-slot>

                <div class="space-y-5 text-sm">
                    <div class="flex flex-wrap gap-4 text-gray-600 dark:text-gray-300">
                        <span>Module: <strong class="text-gray-950 dark:text-white">{{ $selected_skill['module'] }}</strong></span>
                        <span>Version: <strong class="text-gray-950 dark:text-white">{{ $selected_skill['version'] }}</strong></span>
                    </div>

                    <div>
                        <h4 class="font-medium text-gray-950 dark:text-white">Assigned Agents</h4>
                        <p class="mt-1 text-gray-600 dark:text-gray-300">{{ $selected_skill['assigned_agents'] ? implode(', ', $selected_skill['assigned_agents']) : 'None' }}</p>
                    </div>

                    <div>
                        <h4 class="font-medium text-gray-950 dark:text-white">Required Evidence</h4>
                        <p class="mt-1 text-gray-600 dark:text-gray-300">{{ $selected_skill['required_evidence'] ? implode(', ', $selected_skill['required_evidence']) : 'None' }}</p>
                    </div>

                    <div>
                        <h4 class="font-medium text-gray-950 dark:text-white">Required Capabilities</h4>
                        <p class="mt-1 text-gray-600 dark:text-gray-300">{{ $selected_skill['required_capabilities'] ? implode(', ', $selected_skill['required_capabilities']) : 'None' }}</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Metadata only in V1 — Capability Router is not implemented.</p>
                    </div>

                    <div>
                        <h4 class="font-medium text-gray-950 dark:text-white">When to use</h4>
                        <div class="mt-1 whitespace-pre-wrap text-gray-600 dark:text-gray-300">{{ $selected_skill['when_to_use'] }}</div>
                    </div>

                    <div>
                        <h4 class="font-medium text-gray-950 dark:text-white">Do not use when</h4>
                        <div class="mt-1 whitespace-pre-wrap text-gray-600 dark:text-gray-300">{{ $selected_skill['do_not_use_when'] }}</div>
                    </div>

                    <div>
                        <h4 class="font-medium text-gray-950 dark:text-white">Methodology</h4>
                        <div class="mt-1 whitespace-pre-wrap text-gray-600 dark:text-gray-300">{{ $selected_skill['methodology'] }}</div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <h4 class="font-medium text-gray-950 dark:text-white">Allowed conclusions</h4>
                            <ul class="mt-2 list-disc space-y-1 pl-5 text-gray-600 dark:text-gray-300">
                                @foreach ($selected_skill['allowed_conclusions'] as $item)
                                    <li>{{ $item }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <div>
                            <h4 class="font-medium text-gray-950 dark:text-white">Forbidden claims</h4>
                            <ul class="mt-2 list-disc space-y-1 pl-5 text-gray-600 dark:text-gray-300">
                                @foreach ($selected_skill['forbidden_claims'] as $item)
                                    <li>{{ $item }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <h4 class="font-medium text-gray-950 dark:text-white">Success signals</h4>
                            <ul class="mt-2 list-disc space-y-1 pl-5 text-gray-600 dark:text-gray-300">
                                @foreach ($selected_skill['success_signals'] as $item)
                                    <li>{{ $item }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <div>
                            <h4 class="font-medium text-gray-950 dark:text-white">Failure signals</h4>
                            <ul class="mt-2 list-disc space-y-1 pl-5 text-gray-600 dark:text-gray-300">
                                @foreach ($selected_skill['failure_signals'] as $item)
                                    <li>{{ $item }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <div>
                            <h4 class="font-medium text-gray-950 dark:text-white">Watch metrics</h4>
                            <ul class="mt-2 list-disc space-y-1 pl-5 text-gray-600 dark:text-gray-300">
                                @foreach ($selected_skill['watch_metrics'] as $item)
                                    <li>{{ $item }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>

                    <div>
                        <h4 class="font-medium text-gray-950 dark:text-white">Reference sources</h4>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-gray-600 dark:text-gray-300">
                            @foreach ($selected_skill['reference_sources'] as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
