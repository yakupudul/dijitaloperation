<x-filament-panels::page>
    <div class="mb-6 flex flex-wrap gap-3 text-sm">
        <a href="{{ $control_plane_url }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">Routes</a>
        <span class="text-gray-400">·</span>
        <span class="font-semibold text-gray-950 dark:text-white">Agent Profiles</span>
        <span class="text-gray-400">·</span>
        <a href="{{ $skills_url }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">Skill Library</a>
    </div>

    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Built-in Agent Profiles</x-slot>
            <x-slot name="description">
                Bounded professional AI workflows. Profiles are curated and version-controlled — not arbitrary operator CRUD.
            </x-slot>

            <div class="space-y-4">
                @forelse ($profiles as $profile)
                    <article class="rounded-xl border border-gray-200 p-5 dark:border-white/10">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $profile['name'] }}</h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $profile['purpose'] }}</p>
                            </div>
                            <div class="text-right text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <div>{{ $profile['status'] }}</div>
                                <div class="mt-1">v{{ $profile['version'] }}</div>
                            </div>
                        </div>

                        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">Module</dt>
                                <dd class="font-medium text-gray-950 dark:text-white">{{ $profile['module'] }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">AI Route</dt>
                                <dd class="font-medium text-gray-950 dark:text-white">{{ $profile['ai_route_key'] }}</dd>
                            </div>
                        </dl>

                        <div class="mt-4">
                            <h4 class="text-sm font-medium text-gray-950 dark:text-white">Assigned Skills</h4>
                            <ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                                @foreach ($profile['skills'] as $skill)
                                    <li>{{ $skill['name'] }}@if($skill['version']) <span class="text-gray-400">v{{ $skill['version'] }}</span>@endif</li>
                                @endforeach
                            </ul>
                        </div>

                        <div class="mt-4 grid gap-4 sm:grid-cols-3">
                            <div>
                                <h4 class="text-sm font-medium text-gray-950 dark:text-white">Allowed data</h4>
                                <ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                                    @foreach ($profile['allowed_data'] as $item)
                                        <li>{{ str_replace('_', ' ', $item) }}</li>
                                    @endforeach
                                </ul>
                            </div>
                            <div>
                                <h4 class="text-sm font-medium text-gray-950 dark:text-white">Allowed operations</h4>
                                <ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                                    @foreach ($profile['allowed_operations'] as $item)
                                        <li>{{ str_replace('_', ' ', $item) }}</li>
                                    @endforeach
                                </ul>
                            </div>
                            <div>
                                <h4 class="text-sm font-medium text-gray-950 dark:text-white">Forbidden operations</h4>
                                <ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                                    @foreach ($profile['forbidden_operations'] as $item)
                                        <li>{{ str_replace('_', ' ', $item) }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </article>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">No Agent Profiles registered.</p>
                @endforelse
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
