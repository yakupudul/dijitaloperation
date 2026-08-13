<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div>
        <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">Settings</h1>
        <p class="mt-1 text-sm text-gray-500">How MoxDOP should behave for this agency. Integrations live in their own System workspace.</p>
    </div>

    <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
        <nav class="space-y-1" aria-label="Settings sections">
            <label class="mb-2 block text-xs font-medium text-gray-500 lg:hidden" for="settings-section-mobile">Section</label>
            <select id="settings-section-mobile" wire:model.live="section" class="mb-3 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 lg:hidden">
                @foreach ($sections as $item)
                    <option value="{{ $item['id'] }}">{{ $item['label'] }}</option>
                @endforeach
            </select>
            <ul class="hidden space-y-1 lg:block">
                @foreach ($sections as $item)
                    <li>
                        <button type="button" wire:click="setSection('{{ $item['id'] }}')"
                            @class([
                                'w-full rounded-lg px-3 py-2 text-left text-sm font-medium transition',
                                'bg-brand-500 text-white' => $section === $item['id'],
                                'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.03]' => $section !== $item['id'],
                            ])>
                            {{ $item['label'] }}
                        </button>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="space-y-4">
            @php
                $current = collect($sections)->firstWhere('id', $section);
            @endphp
            <div>
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $current['label'] ?? 'Settings' }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ $current['description'] ?? '' }}</p>
            </div>

            @if ($section === 'general')
                <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <dl class="grid gap-4 sm:grid-cols-2 text-sm">
                        <div><dt class="text-gray-400">Agency Name</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['general']['agency_name'] }}</dd></div>
                        <div><dt class="text-gray-400">Default Locale</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['general']['default_locale'] }}</dd></div>
                        <div><dt class="text-gray-400">Default Timezone</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['general']['default_timezone'] }}</dd></div>
                        <div>
                            <dt class="text-gray-400">Default Display Currency</dt>
                            <dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['general']['default_display_currency'] }}</dd>
                            <p class="mt-1 text-xs text-gray-500">{{ $settings['general']['currency_note'] }}</p>
                        </div>
                        <div><dt class="text-gray-400">Default Analytical Date Range</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['general']['default_analytical_date_range'] }}</dd></div>
                        <div><dt class="text-gray-400">Week starts on</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ ucfirst($settings['general']['week_starts_on']) }}</dd></div>
                    </dl>
                </div>
            @elseif ($section === 'team')
                <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <table class="min-w-full text-sm">
                        <caption class="sr-only">Team members</caption>
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <th scope="col" class="px-4 py-3 text-left text-xs uppercase text-gray-400">User</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs uppercase text-gray-400">Email</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs uppercase text-gray-400">Role</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs uppercase text-gray-400">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($settings['team'] as $index => $member)
                                <tr class="border-b border-gray-50 dark:border-gray-800/60">
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center gap-2">
                                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold dark:bg-white/10" aria-hidden="true">{{ $member['initials'] }}</span>
                                            <span class="font-medium text-gray-800 dark:text-white/90">{{ $member['name'] }}</span>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-500">{{ $member['email'] }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $index === 0 ? 'Admin' : 'Team Member' }}</td>
                                    <td class="px-4 py-3"><x-ta.badge color="success" size="sm">Active</x-ta.badge></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="border-t border-gray-100 px-4 py-3 text-xs text-gray-500 dark:border-gray-800">
                        Responsibility (Brand / Digital Asset owner) is separate from authorization. No enterprise RBAC matrix in Demo Mode.
                    </p>
                </div>
            @elseif ($section === 'notifications')
                <div class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($settings['notifications'] as $row)
                            <li class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                                <div>
                                    <p class="font-medium text-gray-800 dark:text-white/90">{{ $row['event'] }}</p>
                                    <p class="text-xs text-gray-500">{{ $row['channel'] }}</p>
                                </div>
                                <x-ta.badge :color="$row['enabled'] ? 'success' : 'light'" size="sm">{{ $row['enabled'] ? 'On' : 'Off' }}</x-ta.badge>
                            </li>
                        @endforeach
                    </ul>
                    <p class="border-t border-gray-100 px-4 py-3 text-xs text-gray-500 dark:border-gray-800">Delivery starts with in-app. Email/Slack/push infrastructure is out of scope for this Demo milestone.</p>
                </div>
            @elseif ($section === 'operations')
                <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <dl class="grid gap-4 sm:grid-cols-2 text-sm">
                        @foreach ($settings['operations'] as $key => $value)
                            <div>
                                <dt class="text-gray-400">{{ str_replace('_', ' ', ucfirst($key)) }}</dt>
                                <dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @elseif ($section === 'ai')
                <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <dl class="grid gap-4 sm:grid-cols-2 text-sm">
                        <div><dt class="text-gray-400">OpenAI</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['ai']['openai'] }}</dd></div>
                        <div><dt class="text-gray-400">Anthropic</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['ai']['anthropic'] }}</dd></div>
                    </dl>
                    <p class="mt-4 text-sm text-gray-500">{{ $settings['ai']['note'] }}</p>
                    <p class="mt-2 text-xs text-gray-400">AI Control Plane / Agent Profiles / Skill Library are not primary operator navigation — configure here when needed.</p>
                </div>
            @elseif ($section === 'privacy')
                <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800 space-y-3 text-sm">
                    <p><span class="text-gray-400">Retention · </span><span class="text-gray-800 dark:text-white/90">{{ $settings['privacy']['retention'] }}</span></p>
                    <p><span class="text-gray-400">Export · </span><span class="text-gray-800 dark:text-white/90">{{ $settings['privacy']['export'] }}</span></p>
                    <p><span class="text-gray-400">Purge · </span><span class="text-gray-800 dark:text-white/90">{{ $settings['privacy']['purge'] }}</span></p>
                </div>
            @else
                <div class="space-y-4">
                    <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                        <dl class="grid gap-4 sm:grid-cols-2 text-sm">
                            <div><dt class="text-gray-400">Environment</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['advanced']['environment'] }}</dd></div>
                            <div><dt class="text-gray-400">App</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['advanced']['app_name'] }}</dd></div>
                            <div><dt class="text-gray-400">Canonical operator surface</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['advanced']['canonical_surface'] }}</dd></div>
                            <div><dt class="text-gray-400">System panel</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['advanced']['system_panel'] }}</dd></div>
                        </dl>
                        <div class="mt-4">
                            <x-ta.button href="/system" size="sm" variant="outline">Open system panel</x-ta.button>
                        </div>
                        <p class="mt-3 text-xs text-gray-500">Modules / package management are developer architecture — not operator navigation.</p>
                    </div>

                    <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                        <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Demo Mode</h3>
                        <p class="mt-2 text-sm text-gray-500">
                            Resets recommendations, tasks, activity, import simulation, period selection, and session-only customers/brands
                            back to the DemoCatalog seed. Does not touch the operator database.
                        </p>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <x-ta.button wire:click="resetDemo" size="sm" variant="danger">Reset Demo Mode</x-ta.button>
                            <x-ta.button href="{{ route('demo.dashboard') }}" size="sm" variant="outline">Back to dashboard</x-ta.button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
