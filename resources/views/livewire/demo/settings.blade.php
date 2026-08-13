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
                <form wire:submit="saveGeneral" class="space-y-4 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <div class="grid gap-4 sm:grid-cols-2 text-sm">
                        <label class="block"><span class="text-gray-400">Agency Name</span><input wire:model="agency_name" type="text" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" /></label>
                        <label class="block"><span class="text-gray-400">Default Locale</span><input wire:model="default_locale" type="text" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" /></label>
                        <label class="block"><span class="text-gray-400">Default Timezone</span><input wire:model="default_timezone" type="text" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" /></label>
                        <label class="block">
                            <span class="text-gray-400">Default Display Currency</span>
                            <input wire:model="default_display_currency" type="text" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" />
                            <p class="mt-1 text-xs text-gray-500">{{ $settings['general']['currency_note'] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-gray-400">Default Analytical Date Range</span>
                            <select wire:model="default_analytical_date_range" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700">
                                <option value="last_7">Last 7 days</option>
                                <option value="last_14">Last 14 days</option>
                                <option value="last_28">Last 28 days</option>
                                <option value="last_30">Last 30 days</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-gray-400">Week starts on</span>
                            <select wire:model="week_starts_on" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700">
                                <option value="monday">Monday</option>
                                <option value="sunday">Sunday</option>
                            </select>
                        </label>
                    </div>
                    <x-ta.button type="submit" size="sm">Save general settings</x-ta.button>
                </form>
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
                        @foreach ($settings['notifications'] as $index => $row)
                            <li class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                                <div>
                                    <p class="font-medium text-gray-800 dark:text-white/90">{{ $row['event'] }}</p>
                                    <p class="text-xs text-gray-500">{{ $row['channel'] }}</p>
                                </div>
                                <button type="button" wire:click="toggleNotification({{ $index }})" class="inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-gray-200 dark:ring-gray-700">
                                    <span @class([
                                        'h-2.5 w-2.5 rounded-full',
                                        'bg-emerald-500' => $notificationEnabled[$index] ?? $row['enabled'],
                                        'bg-slate-400' => ! ($notificationEnabled[$index] ?? $row['enabled']),
                                    ]) aria-hidden="true"></span>
                                    {{ ($notificationEnabled[$index] ?? $row['enabled']) ? 'On' : 'Off' }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                    <p class="border-t border-gray-100 px-4 py-3 text-xs text-gray-500 dark:border-gray-800">Delivery starts with in-app. Email/Slack/push infrastructure is out of scope for this Demo milestone.</p>
                </div>
            @elseif ($section === 'operations')
                <form wire:submit="saveOperations" class="space-y-4 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <div class="grid gap-4 sm:grid-cols-2 text-sm">
                        <label class="block">
                            <span class="text-gray-400">Default dashboard mode</span>
                            <select wire:model="default_dashboard_mode" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700">
                                <option value="My Work">My Work</option>
                                <option value="Agency">Agency</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-gray-400">Outcome review window</span>
                            <input wire:model="outcome_review_window" type="text" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" />
                        </label>
                        @foreach ($settings['operations'] as $key => $value)
                            @if (! in_array($key, ['default_dashboard_mode', 'outcome_review_window'], true))
                                <div>
                                    <dt class="text-gray-400">{{ str_replace('_', ' ', ucfirst($key)) }}</dt>
                                    <dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $value }}</dd>
                                </div>
                            @endif
                        @endforeach
                    </div>
                    <x-ta.button type="submit" size="sm">Save operations settings</x-ta.button>
                </form>
            @elseif ($section === 'ai')
                <div class="space-y-4">
                    <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">AI &amp; Intelligence overview</h3>
                        <dl class="mt-3 grid gap-4 sm:grid-cols-3 text-sm">
                            <div><dt class="text-gray-400">Registered routes</dt><dd class="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{{ count($aiRoutes) }}</dd></div>
                            <div><dt class="text-gray-400">Agent profiles</dt><dd class="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{{ count($aiAgents) }}</dd></div>
                            <div><dt class="text-gray-400">OpenAI</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['ai']['openai'] }}</dd></div>
                        </dl>
                        <p class="mt-4 text-sm text-gray-500">{{ $settings['ai']['note'] }}</p>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <x-ta.button href="{{ route('demo.settings', ['section' => 'ai']) }}" size="sm">AI Control Plane</x-ta.button>
                            <x-ta.button href="{{ route('demo.settings', ['section' => 'ai']) }}" size="sm" variant="outline">Agent Profiles</x-ta.button>
                            <x-ta.button href="{{ route('demo.settings', ['section' => 'ai']) }}" size="sm" variant="outline">Skill Library</x-ta.button>
                        </div>
                    </div>
                    <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Registered AI routes</h3>
                        <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($aiRoutes as $route)
                                <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                    <div>
                                        <p class="font-medium text-gray-800 dark:text-white/90">{{ $route['name'] }}</p>
                                        <p class="text-xs text-gray-500">{{ $route['key'] }} · {{ $route['module'] }}</p>
                                    </div>
                                    <x-ta.button href="{{ route('demo.settings', ['section' => 'ai']) }}" size="sm" variant="outline">Configure</x-ta.button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Agent profiles</h3>
                        <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($aiAgents as $agent)
                                <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                    <div>
                                        <p class="font-medium text-gray-800 dark:text-white/90">{{ $agent['name'] }}</p>
                                        <p class="text-xs text-gray-500">{{ $agent['slug'] }} · {{ $agent['module'] }} · {{ $agent['route'] }}</p>
                                    </div>
                                    <x-ta.badge :color="$agent['status'] === 'operational' ? 'success' : 'warning'" size="sm">{{ $agent['status'] }}</x-ta.badge>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @elseif ($section === 'files')
                <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800 space-y-3 text-sm">
                    <dl class="grid gap-4 sm:grid-cols-2">
                        <div><dt class="text-gray-400">Private file disk</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['files']['disk'] }}</dd></div>
                        <div><dt class="text-gray-400">Avatar disk</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['files']['avatar_disk'] }}</dd></div>
                        <div><dt class="text-gray-400">Max upload</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['files']['max_upload_mb'] }} MB</dd></div>
                        <div><dt class="text-gray-400">Allowed types</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['files']['allowed'] }}</dd></div>
                        <div><dt class="text-gray-400">Blocked types</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['files']['blocked'] }}</dd></div>
                        <div><dt class="text-gray-400">Connector packages</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['files']['connector_storage'] }}</dd></div>
                    </dl>
                    <p class="text-xs text-gray-500">{{ $settings['files']['note'] }}</p>
                    <x-ta.button :href="route('demo.files')" size="sm">{{ __('operator.nav.files') }}</x-ta.button>
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
                            <x-ta.button href="{{ route('demo.settings', ['section' => 'advanced']) }}" size="sm" variant="outline">Advanced diagnostics</x-ta.button>
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
