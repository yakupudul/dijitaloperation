<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div>
        <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">{{ __('operator.nav.settings') }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ __('operator.settings.subtitle') }}</p>
    </div>

    <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
        <nav class="space-y-1" aria-label="{{ __('operator.settings.sections') }}">
            <label class="mb-2 block text-xs font-medium text-gray-500 lg:hidden" for="settings-section-mobile">{{ __('operator.settings.section') }}</label>
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
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $current['label'] ?? __('operator.nav.settings') }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ $current['description'] ?? '' }}</p>
            </div>

            @if ($section === 'general')
                <form wire:submit="saveGeneral" class="space-y-4 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <div class="grid gap-4 sm:grid-cols-2 text-sm">
                        <x-ta.form.field :label="__('operator.settings.agency_name')" :required="true" :error="$errors->first('agency_name')">
                            <input wire:model="agency_name" type="text" @disabled(! $isAdmin) class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" />
                        </x-ta.form.field>
                        <x-ta.form.field :label="__('operator.settings.portal_name')" :required="true" :error="$errors->first('portal_name')">
                            <input wire:model="portal_name" type="text" @disabled(! $isAdmin) class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" />
                        </x-ta.form.field>
                        <x-ta.form.field :label="__('operator.settings.default_locale')" :required="true" :error="$errors->first('default_locale')">
                            <x-ta.form.select wire:model="default_locale" :options="$localeOptions" :searchable="false" :nullable="false" :disabled="! $isAdmin" />
                        </x-ta.form.field>
                        <x-ta.form.field :label="__('operator.settings.default_timezone')" :required="true" :error="$errors->first('default_timezone')">
                            <x-ta.form.select wire:model="default_timezone" :options="$timezoneOptions" :placeholder="__('operator.settings.search_timezone')" :disabled="! $isAdmin" />
                        </x-ta.form.field>
                        <x-ta.form.field :label="__('operator.settings.display_currency')" :required="true" :error="$errors->first('default_display_currency')" :helper="__('operator.settings.currency_note')">
                            <x-ta.form.select wire:model="default_display_currency" :options="$currencyOptions" :searchable="false" :nullable="false" :disabled="! $isAdmin" />
                        </x-ta.form.field>
                        <x-ta.form.field :label="__('operator.settings.week_starts_on')" :required="true" :error="$errors->first('week_starts_on')">
                            <x-ta.form.select wire:model="week_starts_on" :options="$weekStartOptions" :searchable="false" :nullable="false" :disabled="! $isAdmin" />
                        </x-ta.form.field>
                        <x-ta.form.field :label="__('operator.settings.analytical_date_range')" :required="true" :error="$errors->first('default_analytical_date_range')" class="sm:col-span-2">
                            <x-ta.form.select wire:model="default_analytical_date_range" :options="$dateRangeOptions" :searchable="false" :nullable="false" :disabled="! $isAdmin" />
                        </x-ta.form.field>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2 text-sm">
                        <div>
                            <p class="text-gray-400">{{ __('operator.settings.logo') }}</p>
                            @if ($branding['logo_url'])
                                <img src="{{ $branding['logo_url'] }}" alt="" class="mt-2 h-12 max-w-[180px] object-contain" />
                            @endif
                            <input type="file" wire:model="logo" accept="image/jpeg,image/png,image/webp" @disabled(! $isAdmin)
                                class="mt-2 block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-500 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white" />
                            <p class="mt-1 text-xs text-gray-500">{{ __('operator.settings.logo_hint') }}</p>
                            @error('logo') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <p class="text-gray-400">{{ __('operator.settings.favicon') }}</p>
                            @if ($branding['favicon_url'])
                                <img src="{{ $branding['favicon_url'] }}" alt="" class="mt-2 h-8 w-8 object-contain" />
                            @endif
                            <input type="file" wire:model="favicon" accept="image/jpeg,image/png,image/webp" @disabled(! $isAdmin)
                                class="mt-2 block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-500 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white" />
                            <p class="mt-1 text-xs text-gray-500">{{ __('operator.settings.favicon_hint') }}</p>
                            @error('favicon') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    @if ($isAdmin)
                        <x-ta.button type="submit" size="sm">{{ __('operator.settings.save_general') }}</x-ta.button>
                    @else
                        <p class="text-xs text-gray-500">{{ __('operator.settings.admin_only') }}</p>
                    @endif
                </form>

                <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.mail.title') }}</h3>
                    <dl class="mt-3 grid gap-4 sm:grid-cols-2 text-sm">
                        <div>
                            <dt class="text-gray-400">{{ __('operator.mail.delivery') }}</dt>
                            <dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $mail['label'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-400">{{ __('operator.mail.sender') }}</dt>
                            <dd class="mt-1 font-medium text-gray-800 dark:text-white/90">
                                {{ $mail['from_name'] ?? __('operator.mail.not_set') }}
                                @if ($mail['from_address'])
                                    <span class="block text-xs font-normal text-gray-500">{{ $mail['from_address'] }}</span>
                                @endif
                            </dd>
                        </div>
                    </dl>
                    <p class="mt-3 text-xs text-gray-500">{{ $mail['explanation'] }}</p>
                </div>
            @elseif ($section === 'team')
                <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <table class="min-w-full text-sm">
                        <caption class="sr-only">{{ __('operator.team.caption') }}</caption>
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <th scope="col" class="px-4 py-3 text-left text-xs uppercase text-gray-400">{{ __('operator.team.columns.name') }}</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs uppercase text-gray-400">{{ __('operator.team.columns.email') }}</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs uppercase text-gray-400">{{ __('operator.team.columns.role') }}</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs uppercase text-gray-400">{{ __('operator.team.columns.status') }}</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs uppercase text-gray-400">{{ __('operator.team.columns.last_login') }}</th>
                                @if ($isAdmin)
                                    <th scope="col" class="px-4 py-3 text-left text-xs uppercase text-gray-400">{{ __('operator.team.columns.actions') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($settings['team'] as $member)
                                <tr class="border-b border-gray-50 dark:border-gray-800/60">
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center gap-2">
                                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold dark:bg-white/10" aria-hidden="true">{{ $member['initials'] }}</span>
                                            <span class="font-medium text-gray-800 dark:text-white/90">{{ $member['name'] }}</span>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-500">{{ $member['email'] }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                        @if ($isAdmin)
                                            <select wire:change="updateUserRole({{ (int) $member['id'] }}, $event.target.value)" class="rounded-lg border border-gray-200 bg-transparent px-2 py-1 text-sm dark:border-gray-700">
                                                @foreach ($roleOptions as $value => $label)
                                                    <option value="{{ $value }}" @selected(($member['role'] ?? '') === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            {{ $roleOptions[$member['role'] ?? ''] ?? ($member['role'] ?? '') }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <x-ta.badge :color="$member['is_active'] ? 'success' : 'light'" size="sm">
                                            {{ $member['is_active'] ? __('operator.team.active') : __('operator.team.inactive') }}
                                        </x-ta.badge>
                                    </td>
                                    <td class="px-4 py-3 text-gray-500">{{ $member['last_login'] ?? '—' }}</td>
                                    @if ($isAdmin)
                                        <td class="px-4 py-3">
                                            @if ($member['is_active'])
                                                <button type="button" wire:click="deactivateUser({{ (int) $member['id'] }})" class="text-xs font-medium text-red-600 hover:underline dark:text-red-400">
                                                    {{ __('operator.team.deactivate') }}
                                                </button>
                                            @else
                                                <button type="button" wire:click="reactivateUser({{ (int) $member['id'] }})" class="text-xs font-medium text-brand-600 hover:underline">
                                                    {{ __('operator.team.reactivate') }}
                                                </button>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="border-t border-gray-100 px-4 py-3 text-xs text-gray-500 dark:border-gray-800">
                        {{ __('operator.team.owner_note') }}
                    </p>
                </div>

                @if ($isAdmin)
                    <form wire:submit="addTeamMember" class="space-y-4 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.team.add_title') }}</h3>
                        <div class="grid gap-4 sm:grid-cols-2 text-sm">
                            <x-ta.form.field :label="__('operator.team.fields.name')" :required="true" :error="$errors->first('new_name')">
                                <input wire:model="new_name" type="text" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" />
                            </x-ta.form.field>
                            <x-ta.form.field :label="__('operator.team.fields.email')" :required="true" :error="$errors->first('new_email')">
                                <input wire:model="new_email" type="email" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" />
                            </x-ta.form.field>
                            <x-ta.form.field :label="__('operator.team.fields.role')" :required="true" :error="$errors->first('new_role')">
                                <x-ta.form.select wire:model="new_role" :options="$roleOptions" :searchable="false" :nullable="false" />
                            </x-ta.form.field>
                            <x-ta.form.field :label="__('operator.team.fields.status')">
                                <label class="mt-2 inline-flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                                    <input type="checkbox" wire:model="new_is_active" class="rounded border-gray-300 text-brand-600" />
                                    {{ __('operator.team.active') }}
                                </label>
                            </x-ta.form.field>
                            <x-ta.form.field :label="__('operator.team.fields.password')" :required="true" :error="$errors->first('new_password')" :helper="__('operator.team.password_hint')">
                                <input wire:model="new_password" type="password" autocomplete="new-password" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" />
                            </x-ta.form.field>
                            <x-ta.form.field :label="__('operator.team.fields.password_confirmation')" :required="true" :error="$errors->first('new_password_confirmation')">
                                <input wire:model="new_password_confirmation" type="password" autocomplete="new-password" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700" />
                            </x-ta.form.field>
                        </div>
                        <x-ta.button type="submit" size="sm">{{ __('operator.team.add') }}</x-ta.button>
                    </form>
                @endif
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
                                    {{ ($notificationEnabled[$index] ?? $row['enabled']) ? __('operator.notifications.on') : __('operator.notifications.off') }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                    <p class="border-t border-gray-100 px-4 py-3 text-xs text-gray-500 dark:border-gray-800">
                        {{ __('operator.notifications.footnote') }}
                    </p>
                </div>
            @elseif ($section === 'operations')
                <div class="mb-4 inline-flex gap-1 rounded-lg bg-gray-100 p-1 dark:bg-white/[0.04]" role="tablist">
                    <button type="button" wire:click="setOpsSub('defaults')"
                        @class(['rounded-md px-3 py-1.5 text-sm font-medium', 'bg-white shadow-sm dark:bg-gray-800' => ($ops_sub ?? 'defaults') === 'defaults'])>{{ __('operator.playbooks.settings_defaults') }}</button>
                    <button type="button" wire:click="setOpsSub('playbooks')"
                        @class(['rounded-md px-3 py-1.5 text-sm font-medium', 'bg-white shadow-sm dark:bg-gray-800' => ($ops_sub ?? 'defaults') === 'playbooks'])>{{ __('operator.playbooks.catalog') }}</button>
                </div>
                @if (($ops_sub ?? 'defaults') === 'playbooks')
                    <div class="space-y-3 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.playbooks.catalog') }}</h3>
                        <p class="text-sm text-gray-500">{{ __('operator.playbooks.catalog_subtitle') }}</p>
                        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($playbooks as $playbook)
                                <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                                    <div>
                                        <p class="font-medium text-gray-800 dark:text-white/90">{{ $playbook['name'] }}</p>
                                        <p class="text-xs text-gray-500">{{ $playbook['service_label'] ?? '' }} · {{ ucfirst($playbook['cadence'] ?? '') }}@if (! empty($playbook['default_owner_name'])) · {{ $playbook['default_owner_name'] }}@endif</p>
                                    </div>
                                    <x-ta.button :href="route('operator.settings.playbook', ['playbookId' => $playbook['id']])" size="sm" variant="outline">{{ __('operator.actions.open') }}</x-ta.button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    <div class="space-y-3 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('operator.settings.operations.dashboard_mode_note') }}</p>
                        <x-ta.button :href="route('operator.dashboard')" size="sm" variant="outline">{{ __('operator.nav.dashboard') }}</x-ta.button>
                    </div>
                @endif
            @elseif ($section === 'ai')
                <div class="space-y-4">
                    <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.settings.ai.overview') }}</h3>
                        <dl class="mt-3 grid gap-4 sm:grid-cols-3 text-sm">
                            <div><dt class="text-gray-400">{{ __('operator.settings.ai.routes_count') }}</dt><dd class="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{{ count($aiRoutes) }}</dd></div>
                            <div><dt class="text-gray-400">{{ __('operator.settings.ai.agents') }}</dt><dd class="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{{ count($aiAgents) }}</dd></div>
                            <div><dt class="text-gray-400">OpenAI</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['ai']['openai'] }}</dd></div>
                            <div><dt class="text-gray-400">Anthropic</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['ai']['anthropic'] }}</dd></div>
                            <div><dt class="text-gray-400">Gemini</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['ai']['gemini'] }}</dd></div>
                        </dl>
                        <p class="mt-4 text-sm text-gray-500">{{ $settings['ai']['note'] }}</p>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <x-ta.button href="{{ route('operator.integrations.ai', ['provider' => 'openai']) }}" size="sm">OpenAI</x-ta.button>
                            <x-ta.button href="{{ route('operator.integrations.ai', ['provider' => 'anthropic']) }}" size="sm" variant="outline">Anthropic</x-ta.button>
                            <x-ta.button href="{{ route('operator.integrations.ai', ['provider' => 'gemini']) }}" size="sm" variant="outline">Gemini</x-ta.button>
                            <x-ta.button href="{{ route('operator.settings.ai.control-plane') }}" size="sm" variant="outline">{{ __('operator.settings.ai.control_plane') }}</x-ta.button>
                            <x-ta.button href="{{ route('operator.settings.ai.agents') }}" size="sm" variant="outline">{{ __('operator.settings.ai.agents') }}</x-ta.button>
                            <x-ta.button href="{{ route('operator.settings.ai.skills') }}" size="sm" variant="outline">{{ __('operator.settings.ai.skills') }}</x-ta.button>
                        </div>
                    </div>
                    <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.settings.ai.routes_title') }}</h3>
                        <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($aiRoutes as $route)
                                <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                    <div>
                                        <p class="font-medium text-gray-800 dark:text-white/90">{{ $route['name'] }}</p>
                                        <p class="text-xs text-gray-500">{{ $route['key'] }} · {{ $route['module'] }}</p>
                                    </div>
                                    <x-ta.button href="{{ route('operator.settings.ai.control-plane', ['route' => $route['key']]) }}" size="sm" variant="outline">{{ __('operator.settings.ai.configure') }}</x-ta.button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <div id="agents" class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.settings.ai.agents') }}</h3>
                            <a href="{{ route('operator.settings.ai.agents') }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">{{ __('operator.settings.ai.open_catalog') }}</a>
                        </div>
                        <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($aiAgents as $agent)
                                <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                    <div>
                                        <p class="font-medium text-gray-800 dark:text-white/90">{{ $agent['name'] }}</p>
                                        <p class="text-xs text-gray-500">{{ $agent['slug'] }} · {{ $agent['module'] }} · {{ $agent['route'] }}</p>
                                    </div>
                                    <a href="{{ route('operator.settings.ai.agents', ['agent' => $agent['slug']]) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">{{ __('operator.actions.open') }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <div id="skills" class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.settings.ai.skills') }}</h3>
                            <a href="{{ route('operator.settings.ai.skills') }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">{{ __('operator.settings.ai.open_catalog') }}</a>
                        </div>
                        <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($aiSkills as $skill)
                                <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                    <div>
                                        <p class="font-medium text-gray-800 dark:text-white/90">{{ $skill['name'] }}</p>
                                        <p class="text-xs text-gray-500">{{ $skill['slug'].'@'.$skill['version'] }} · {{ $skill['module'] }}</p>
                                        <p class="mt-1 text-xs text-gray-500 line-clamp-2">{{ $skill['purpose'] }}</p>
                                    </div>
                                    <a href="{{ route('operator.settings.ai.skills', ['skill' => $skill['slug']]) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">{{ __('operator.actions.open') }}</a>
                                </li>
                            @empty
                                <li class="py-2 text-sm text-gray-500">{{ __('operator.settings.ai.no_skills') }}</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
            @elseif ($section === 'advanced')
                <div class="space-y-4">
                    @if ($advanced_sub === null || $advanced_sub === 'diagnostics')
                        <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.settings_ia.advanced_diagnostics') }}</h3>
                            <dl class="mt-3 grid gap-4 sm:grid-cols-2 text-sm">
                                <div><dt class="text-gray-400">{{ __('operator.settings.advanced.environment') }}</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['advanced']['environment'] }}</dd></div>
                                <div><dt class="text-gray-400">{{ __('operator.settings.advanced.surface') }}</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['advanced']['canonical_surface'] }}</dd></div>
                            </dl>
                            <div class="mt-4">
                                <x-ta.button href="{{ route('operator.dashboard') }}" size="sm" variant="outline">{{ __('operator.nav.dashboard') }}</x-ta.button>
                            </div>
                            <p class="mt-3 text-xs text-gray-500">{{ __('operator.settings.advanced.note') }}</p>
                        </div>
                    @endif

                    @if ($advanced_sub === null || $advanced_sub === 'files')
                        <div id="files" class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800 space-y-3 text-sm">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.settings_ia.advanced_files') }}</h3>
                            <dl class="grid gap-4 sm:grid-cols-2">
                                <div><dt class="text-gray-400">{{ __('operator.settings.files.private_disk_label') }}</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['files']['disk'] }}</dd></div>
                                <div><dt class="text-gray-400">{{ __('operator.settings.files.avatar_disk_label') }}</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['files']['avatar_disk'] }}</dd></div>
                                <div><dt class="text-gray-400">{{ __('operator.settings.files.max_upload') }}</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['files']['max_upload_mb'] }} MB</dd></div>
                                <div><dt class="text-gray-400">{{ __('operator.settings.files.allowed_label') }}</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['files']['allowed'] }}</dd></div>
                                <div><dt class="text-gray-400">{{ __('operator.settings.files.blocked_label') }}</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $settings['files']['blocked'] }}</dd></div>
                            </dl>
                            <p class="text-xs text-gray-500">{{ $settings['files']['note'] }}</p>
                            <x-ta.button :href="route('operator.files')" size="sm">{{ __('operator.nav.files') }}</x-ta.button>
                        </div>
                    @endif

                    @if ($advanced_sub === null || $advanced_sub === 'privacy')
                        <div id="privacy" class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800 space-y-3 text-sm">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.settings_ia.advanced_privacy') }}</h3>
                            <p><span class="text-gray-400">{{ __('operator.settings.privacy.retention_label') }} · </span><span class="text-gray-800 dark:text-white/90">{{ $settings['privacy']['retention'] }}</span></p>
                            <p><span class="text-gray-400">{{ __('operator.settings.privacy.export_label') }} · </span><span class="text-gray-800 dark:text-white/90">{{ $settings['privacy']['export'] }}</span></p>
                            <p><span class="text-gray-400">{{ __('operator.settings.privacy.purge_label') }} · </span><span class="text-gray-800 dark:text-white/90">{{ $settings['privacy']['purge'] }}</span></p>
                        </div>
                    @endif

                    @if ($advanced_sub === null)
                        <div class="flex flex-wrap gap-2 text-sm">
                            <button type="button" wire:click="setAdvancedSub('files')" class="rounded-lg px-3 py-2 font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">{{ __('operator.settings_ia.advanced_files') }}</button>
                            <button type="button" wire:click="setAdvancedSub('privacy')" class="rounded-lg px-3 py-2 font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">{{ __('operator.settings_ia.advanced_privacy') }}</button>
                            <button type="button" wire:click="setAdvancedSub('diagnostics')" class="rounded-lg px-3 py-2 font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">{{ __('operator.settings_ia.advanced_diagnostics') }}</button>
                        </div>
                    @elseif ($advanced_sub !== null)
                        <button type="button" wire:click="setAdvancedSub(null)" class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">{{ __('operator.settings.advanced.back') }}</button>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
