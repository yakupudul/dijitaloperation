<div class="space-y-6">
    <div>
        <a href="{{ route('demo.settings', ['section' => 'ai']) }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-brand-600">← {{ __('operator.settings.ai.back') }}</a>
        <h1 class="mt-2 text-2xl font-bold text-gray-800 dark:text-white/90">{{ __('operator.settings.ai.agents_title') }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ __('operator.settings.ai.agents_subtitle') }}</p>
    </div>

    <nav class="flex flex-wrap gap-3 text-sm" aria-label="{{ __('operator.settings.ai.nav') }}">
        <a href="{{ route('demo.settings.ai.control-plane') }}" wire:navigate class="font-medium text-brand-600 hover:underline">{{ __('operator.settings.ai.control_plane') }}</a>
        <span class="text-gray-400">·</span>
        <span class="font-semibold text-gray-800 dark:text-white/90">{{ __('operator.settings.ai.agents') }}</span>
        <span class="text-gray-400">·</span>
        <a href="{{ route('demo.settings.ai.skills') }}" wire:navigate class="font-medium text-brand-600 hover:underline">{{ __('operator.settings.ai.skills') }}</a>
    </nav>

    <div class="grid gap-6 lg:grid-cols-[16rem_minmax(0,1fr)]">
        <aside class="rounded-xl bg-white p-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700" aria-label="{{ __('operator.settings.ai.agents') }}">
            <ul class="space-y-1">
                @foreach ($profiles as $profile)
                    <li>
                        <button type="button" wire:click="selectAgent('{{ $profile['slug'] }}')"
                            @class([
                                'w-full rounded-lg px-3 py-2 text-left text-sm transition',
                                'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-400' => $selectedProfile && $selectedProfile['slug'] === $profile['slug'],
                                'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/[0.03]' => ! ($selectedProfile && $selectedProfile['slug'] === $profile['slug']),
                            ])>
                            <span class="font-medium">{{ $profile['name'] }}</span>
                            <span class="mt-0.5 block text-xs text-gray-500">{{ $profile['module'] }} · {{ $profile['status'] }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </aside>

        @if ($selectedProfile)
            <article class="space-y-4 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $selectedProfile['name'] }}</h2>
                        <p class="mt-1 text-sm text-gray-500">{{ $selectedProfile['purpose'] }}</p>
                    </div>
                    <div class="text-right text-xs uppercase tracking-wide text-gray-400">
                        <div>{{ $selectedProfile['status'] }}</div>
                        <div class="mt-1">v{{ $selectedProfile['version'] }}</div>
                    </div>
                </div>

                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-gray-500">{{ __('operator.settings.ai.module') }}</dt>
                        <dd class="font-medium text-gray-800 dark:text-white/90">{{ $selectedProfile['module'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('operator.settings.ai.route') }}</dt>
                        <dd class="font-medium text-gray-800 dark:text-white/90">{{ $selectedProfile['ai_route_key'] }}</dd>
                    </div>
                </dl>

                <section>
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.settings.ai.assigned_skills') }}</h3>
                    <ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                        @foreach ($selectedProfile['skills'] as $skill)
                            <li>
                                <a href="{{ route('demo.settings.ai.skills', ['skill' => $skill['slug']]) }}" wire:navigate class="font-medium text-brand-600 hover:underline">{{ $skill['name'] }}</a>
                                @if ($skill['version']) <span class="text-gray-400">v{{ $skill['version'] }}</span> @endif
                            </li>
                        @endforeach
                    </ul>
                </section>

                <div class="grid gap-4 sm:grid-cols-3">
                    <section>
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.settings.ai.allowed_data') }}</h3>
                        <ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                            @foreach ($selectedProfile['allowed_data'] as $item)
                                <li>{{ str_replace('_', ' ', $item) }}</li>
                            @endforeach
                        </ul>
                    </section>
                    <section>
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.settings.ai.allowed_ops') }}</h3>
                        <ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                            @foreach ($selectedProfile['allowed_operations'] as $item)
                                <li>{{ str_replace('_', ' ', $item) }}</li>
                            @endforeach
                        </ul>
                    </section>
                    <section>
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.settings.ai.forbidden_ops') }}</h3>
                        <ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                            @foreach ($selectedProfile['forbidden_operations'] as $item)
                                <li>{{ str_replace('_', ' ', $item) }}</li>
                            @endforeach
                        </ul>
                    </section>
                </div>

                <section>
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.settings.ai.output_contract') }}</h3>
                    <p class="mt-1 whitespace-pre-wrap text-sm text-gray-600 dark:text-gray-300">{{ $selectedProfile['output_contract'] }}</p>
                </section>

                <section>
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.settings.ai.success_criteria') }}</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                        @foreach ($selectedProfile['success_criteria'] as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                </section>

                <p class="text-xs text-gray-400">{{ __('operator.settings.ai.readonly_note') }}</p>
            </article>
        @endif
    </div>
</div>
