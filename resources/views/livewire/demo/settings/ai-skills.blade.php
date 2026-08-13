<div class="space-y-6">
    <div>
        <a href="{{ route('demo.settings', ['section' => 'ai']) }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-brand-600">← {{ __('operator.settings.ai.back') }}</a>
        <h1 class="mt-2 text-2xl font-bold text-gray-800 dark:text-white/90">{{ __('operator.settings.ai.skills_title') }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ __('operator.settings.ai.skills_subtitle') }}</p>
    </div>

    <nav class="flex flex-wrap gap-3 text-sm" aria-label="{{ __('operator.settings.ai.nav') }}">
        <a href="{{ route('demo.settings.ai.control-plane') }}" wire:navigate class="font-medium text-brand-600 hover:underline">{{ __('operator.settings.ai.control_plane') }}</a>
        <span class="text-gray-400">·</span>
        <a href="{{ route('demo.settings.ai.agents') }}" wire:navigate class="font-medium text-brand-600 hover:underline">{{ __('operator.settings.ai.agents') }}</a>
        <span class="text-gray-400">·</span>
        <span class="font-semibold text-gray-800 dark:text-white/90">{{ __('operator.settings.ai.skills') }}</span>
    </nav>

    <div class="grid gap-6 lg:grid-cols-[16rem_minmax(0,1fr)]">
        <aside class="rounded-xl bg-white p-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <ul class="space-y-1">
                @foreach ($skills as $skill)
                    <li>
                        <button type="button" wire:click="selectSkill('{{ $skill['slug'] }}')"
                            @class([
                                'w-full rounded-lg px-3 py-2 text-left text-sm transition',
                                'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-400' => $selectedSkill && $selectedSkill['slug'] === $skill['slug'],
                                'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/[0.03]' => ! ($selectedSkill && $selectedSkill['slug'] === $skill['slug']),
                            ])>
                            <span class="font-medium">{{ $skill['name'] }}</span>
                            <span class="mt-0.5 block text-xs text-gray-500">{{ $skill['module'] }} · v{{ $skill['version'] }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </aside>

        @if ($selectedSkill)
            <article class="space-y-4 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $selectedSkill['name'] }}</h2>
                    <p class="mt-1 text-sm text-gray-500">{{ $selectedSkill['purpose'] }}</p>
                </div>

                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ __('operator.settings.ai.module') }}: <strong>{{ $selectedSkill['module'] }}</strong>
                    · {{ __('operator.settings.ai.version') }}: <strong>{{ $selectedSkill['version'] }}</strong>
                </p>

                <section>
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.settings.ai.assigned_agents') }}</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $selectedSkill['assigned_agents'] ? implode(', ', $selectedSkill['assigned_agents']) : __('operator.settings.ai.none') }}</p>
                </section>

                <section>
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.settings.ai.required_evidence') }}</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $selectedSkill['required_evidence'] ? implode(', ', $selectedSkill['required_evidence']) : __('operator.settings.ai.none') }}</p>
                </section>

                <section>
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.playbooks.when_to_use') }}</h3>
                    <div class="mt-1 whitespace-pre-wrap text-sm text-gray-600 dark:text-gray-300">{{ $selectedSkill['when_to_use'] }}</div>
                </section>

                <section>
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.playbooks.when_not_to_use') }}</h3>
                    <div class="mt-1 whitespace-pre-wrap text-sm text-gray-600 dark:text-gray-300">{{ $selectedSkill['do_not_use_when'] }}</div>
                </section>

                <section>
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.playbooks.methodology') }}</h3>
                    <div class="mt-1 whitespace-pre-wrap text-sm text-gray-600 dark:text-gray-300">{{ $selectedSkill['methodology'] }}</div>
                </section>

                <div class="grid gap-4 sm:grid-cols-2">
                    <section>
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.settings.ai.allowed_conclusions') }}</h3>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                            @foreach ($selectedSkill['allowed_conclusions'] as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    </section>
                    <section>
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.settings.ai.forbidden_claims') }}</h3>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                            @foreach ($selectedSkill['forbidden_claims'] as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    </section>
                </div>

                <p class="text-xs text-gray-400">{{ __('operator.settings.ai.skill_vs_playbook') }}</p>
                <p class="text-xs text-gray-400">{{ __('operator.settings.ai.readonly_note') }}</p>
            </article>
        @endif
    </div>
</div>
