@php
    // A deployment placeholder sender (Laravel's example.com default) is not a real setting: shown as "—" (2026-10-19).
    $isPlaceholder = fn (?string $value): bool => is_string($value) && preg_match('/@example\.(com|org|net)$/i', trim($value)) === 1;
    $current = collect($sections)->firstWhere('id', $section);

    // Settings rows (panel concept 2026-10-20): label and one gray line on the left, the control on the right.
    $row = 'card-row flex-wrap items-start sm:flex-nowrap';
    $rowLabel = 'w-full min-w-0 sm:w-auto sm:flex-1 sm:pt-2';
    $labelText = 'block text-sm font-medium text-gray-900 dark:text-white';
    $hint = 'mt-0.5 block text-[13px] text-gray-500 dark:text-gray-400';
    $control = 'w-full min-w-0 sm:w-80 sm:shrink-0';
    $input = 'h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none disabled:bg-gray-50 disabled:text-gray-500 dark:border-gray-700 dark:bg-transparent dark:text-white';
    $error = 'mt-1 text-xs text-error-600 dark:text-error-400';
    $file = 'block w-full text-[13px] text-gray-600 file:mr-3 file:h-8 file:rounded-lg file:border file:border-gray-300 file:bg-white file:px-3 file:text-[13px] file:font-medium file:text-gray-900 hover:file:bg-gray-50 dark:text-gray-300 dark:file:border-gray-700 dark:file:bg-white/[0.04] dark:file:text-gray-200';
    $footer = 'flex flex-wrap items-center justify-end gap-2 border-t border-gray-200 bg-gray-50/60 px-4 py-3 dark:border-gray-800 dark:bg-white/[0.02]';
    $mailTone = ($mail['production_delivery'] ?? false) ? 'ok' : 'look';
@endphp
<div class="space-y-10">
    @include('livewire.demo.partials.flash')

    <header>
        <h1 class="page-title">{{ __('operator.nav.settings') }}</h1>
        @if (($current['description'] ?? '') !== '')<p class="page-sub">{{ $current['description'] }}</p>@endif
    </header>

    {{-- One navigation level (2026-10-19): the layout's section tabs are the top navigation; this page's own parts are
         in-page tabs (was a second, left-hand menu). --}}
    <nav class="-mt-4 flex gap-1 overflow-x-auto border-b border-gray-200 no-scrollbar dark:border-gray-800" aria-label="{{ __('operator.settings.sections') }}" data-settings-sections>
        @foreach ($sections as $item)
            <button type="button" wire:click="setSection('{{ $item['id'] }}')" @if ($section === $item['id']) aria-current="true" @endif
                @class(['tab', 'tab-active' => $section === $item['id']])>
                {{ $item['label'] }}
            </button>
        @endforeach
    </nav>

    @if ($section === 'general')
        <section aria-label="{{ $current['label'] ?? __('operator.settings_ia.general') }}">
            <form wire:submit="saveGeneral" class="card overflow-visible!">
                <div class="{{ $row }}">
                    <label for="set-agency-name" class="{{ $rowLabel }}"><span class="{{ $labelText }}">{{ __('operator.settings.agency_name') }} <span class="text-error-500">*</span></span></label>
                    <div class="{{ $control }}">
                        <input id="set-agency-name" wire:model="agency_name" type="text" @disabled(! $isAdmin) class="{{ $input }}" />
                        @error('agency_name')<p class="{{ $error }}">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="{{ $row }}">
                    <label for="set-portal-name" class="{{ $rowLabel }}"><span class="{{ $labelText }}">{{ __('operator.settings.portal_name') }} <span class="text-error-500">*</span></span></label>
                    <div class="{{ $control }}">
                        <input id="set-portal-name" wire:model="portal_name" type="text" @disabled(! $isAdmin) class="{{ $input }}" />
                        @error('portal_name')<p class="{{ $error }}">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="{{ $row }}">
                    <div class="{{ $rowLabel }}">
                        <span class="{{ $labelText }}">{{ __('operator.settings.logo') }}</span>
                        <span class="{{ $hint }}">{{ __('operator.settings.logo_hint') }}</span>
                    </div>
                    <div class="{{ $control }} flex items-center gap-3">
                        @if ($branding['logo_url'])
                            <img src="{{ $branding['logo_url'] }}" alt="" class="h-9 max-w-[96px] shrink-0 rounded-md border border-gray-200 bg-white object-contain p-1 dark:border-gray-700" />
                        @endif
                        <div class="min-w-0 flex-1">
                            <input type="file" wire:model="logo" accept="image/jpeg,image/png,image/webp" @disabled(! $isAdmin) aria-label="{{ __('operator.settings.logo') }}" class="{{ $file }}" />
                            @error('logo')<p class="{{ $error }}">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>
                <div class="{{ $row }}">
                    <div class="{{ $rowLabel }}">
                        <span class="{{ $labelText }}">{{ __('operator.settings.favicon') }}</span>
                        <span class="{{ $hint }}">{{ __('operator.settings.favicon_hint') }}</span>
                    </div>
                    <div class="{{ $control }} flex items-center gap-3">
                        @if ($branding['favicon_url'])
                            <img src="{{ $branding['favicon_url'] }}" alt="" class="h-9 w-9 shrink-0 rounded-md border border-gray-200 bg-white object-contain p-1.5 dark:border-gray-700" />
                        @endif
                        <div class="min-w-0 flex-1">
                            <input type="file" wire:model="favicon" accept="image/jpeg,image/png,image/webp" @disabled(! $isAdmin) aria-label="{{ __('operator.settings.favicon') }}" class="{{ $file }}" />
                            @error('favicon')<p class="{{ $error }}">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>
                <div class="{{ $row }}">
                    <label for="set-locale" class="{{ $rowLabel }}"><span class="{{ $labelText }}">{{ __('operator.settings.default_locale') }} <span class="text-error-500">*</span></span></label>
                    <div class="{{ $control }}">
                        <select id="set-locale" wire:model="default_locale" @disabled(! $isAdmin) class="{{ $input }}">
                            @foreach ($localeOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('default_locale')<p class="{{ $error }}">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="{{ $row }}">
                    <div class="{{ $rowLabel }}">
                        <span class="{{ $labelText }}">{{ __('operator.settings.default_timezone') }} <span class="text-error-500">*</span></span>
                        <span class="{{ $hint }}">{{ __('operator.settings.timezone_note') }}</span>
                    </div>
                    <div class="{{ $control }}">
                        <x-ta.form.select wire:model="default_timezone" :options="$timezoneOptions" :placeholder="__('operator.settings.search_timezone')" :disabled="! $isAdmin" />
                        @error('default_timezone')<p class="{{ $error }}">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="{{ $row }}">
                    <div class="{{ $rowLabel }}">
                        <label for="set-currency" class="{{ $labelText }}">{{ __('operator.settings.display_currency') }} <span class="text-error-500">*</span></label>
                        <span class="{{ $hint }}">{{ __('operator.settings.currency_note') }}</span>
                    </div>
                    <div class="{{ $control }}">
                        <select id="set-currency" wire:model="default_display_currency" @disabled(! $isAdmin) class="{{ $input }}">
                            @foreach ($currencyOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('default_display_currency')<p class="{{ $error }}">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="{{ $row }}">
                    <label for="set-week" class="{{ $rowLabel }}"><span class="{{ $labelText }}">{{ __('operator.settings.week_starts_on') }} <span class="text-error-500">*</span></span></label>
                    <div class="{{ $control }}">
                        <select id="set-week" wire:model="week_starts_on" @disabled(! $isAdmin) class="{{ $input }}">
                            @foreach ($weekStartOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('week_starts_on')<p class="{{ $error }}">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="{{ $row }}">
                    <label for="set-range" class="{{ $rowLabel }}"><span class="{{ $labelText }}">{{ __('operator.settings.analytical_date_range') }} <span class="text-error-500">*</span></span></label>
                    <div class="{{ $control }}">
                        <select id="set-range" wire:model="default_analytical_date_range" @disabled(! $isAdmin) class="{{ $input }}">
                            @foreach ($dateRangeOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('default_analytical_date_range')<p class="{{ $error }}">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="{{ $footer }} rounded-b-xl">
                    @if ($isAdmin)
                        <button type="submit" class="btn-primary w-full sm:w-auto">{{ __('operator.settings.save_general') }}</button>
                    @else
                        <p class="mr-auto text-xs text-gray-500">{{ __('operator.settings.admin_only') }}</p>
                    @endif
                </div>
            </form>
        </section>

        <section>
            <div class="section-head">
                <h2 class="section-title">{{ __('operator.mail.title') }}</h2>
            </div>
            <div class="card">
                <div class="card-row flex-wrap sm:flex-nowrap">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ __('operator.mail.delivery') }}</p>
                        <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $mail['explanation'] }}</p>
                    </div>
                    <x-ta.pill :tone="$mailTone" class="text-sm!">{{ $mail['label'] }}</x-ta.pill>
                </div>
                <div class="card-row flex-wrap sm:flex-nowrap">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ __('operator.mail.sender') }}</p>
                        <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ __('operator.mail.use_cases') }}</p>
                    </div>
                    <div class="min-w-0 text-left sm:text-right">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $mail['from_name'] ?? __('operator.mail.not_set') }}</p>
                        @if ($mail['from_address'] && ! $isPlaceholder($mail['from_address']))
                            <p class="break-all text-[13px] text-gray-500">{{ $mail['from_address'] }}</p>
                        @else
                            <p class="text-[13px] text-gray-500">—</p>
                        @endif
                    </div>
                </div>
            </div>

            @if ($isAdmin)
                <form wire:submit="saveMail" class="card mt-4">
                    <label class="card-row cursor-pointer">
                        <span class="min-w-0 flex-1 text-sm font-medium text-gray-900 dark:text-white">{{ __('operator.mail.enable_operator') }}</span>
                        <input type="checkbox" wire:model="mail_enabled" class="h-4 w-4 rounded border-gray-300 text-gray-900" />
                    </label>
                    <div class="{{ $row }}">
                        <label for="mail-from-name" class="{{ $rowLabel }}"><span class="{{ $labelText }}">{{ __('operator.mail.from_name') }}</span></label>
                        <div class="{{ $control }}">
                            <input id="mail-from-name" wire:model="mail_from_name" type="text" autocomplete="off" class="{{ $input }}" />
                            @error('mail_from_name')<p class="{{ $error }}">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="{{ $row }}">
                        <label for="mail-from-address" class="{{ $rowLabel }}"><span class="{{ $labelText }}">{{ __('operator.mail.from_address') }}</span></label>
                        <div class="{{ $control }}">
                            <input id="mail-from-address" wire:model="mail_from_address" type="email" autocomplete="off" placeholder="ornek@alanadi.com" class="{{ $input }}" />
                            @error('mail_from_address')<p class="{{ $error }}">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="{{ $row }}">
                        <label for="mail-host" class="{{ $rowLabel }}"><span class="{{ $labelText }}">{{ __('operator.mail.host') }}</span></label>
                        <div class="{{ $control }}">
                            <input id="mail-host" wire:model="mail_host" type="text" autocomplete="off" class="{{ $input }}" />
                            @error('mail_host')<p class="{{ $error }}">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="{{ $row }}">
                        <label for="mail-port" class="{{ $rowLabel }}"><span class="{{ $labelText }}">{{ __('operator.mail.port') }}</span></label>
                        <div class="{{ $control }}">
                            <input id="mail-port" wire:model="mail_port" type="number" min="1" max="65535" class="{{ $input }}" />
                            @error('mail_port')<p class="{{ $error }}">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="{{ $row }}">
                        <label for="mail-username" class="{{ $rowLabel }}"><span class="{{ $labelText }}">{{ __('operator.mail.username') }}</span></label>
                        <div class="{{ $control }}">
                            <input id="mail-username" wire:model="mail_username" type="text" autocomplete="off" class="{{ $input }}" />
                            @error('mail_username')<p class="{{ $error }}">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="{{ $row }}">
                        <label for="mail-encryption" class="{{ $rowLabel }}"><span class="{{ $labelText }}">{{ __('operator.mail.encryption_label') }}</span></label>
                        <div class="{{ $control }}">
                            <select id="mail-encryption" wire:model="mail_encryption" class="{{ $input }}">
                                @foreach ($mailEncryptionOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('mail_encryption')<p class="{{ $error }}">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="{{ $row }}">
                        <div class="{{ $rowLabel }}">
                            <label for="mail-password" class="{{ $labelText }}">{{ __('operator.mail.password') }}</label>
                            <span class="{{ $hint }}">{{ $mail['has_password'] ? __('operator.mail.password_keep') : __('operator.mail.password_hint') }}</span>
                        </div>
                        <div class="{{ $control }}">
                            <input id="mail-password" wire:model="mail_password" type="password" autocomplete="new-password" class="{{ $input }}" />
                            @error('mail_password')<p class="{{ $error }}">{{ $message }}</p>@enderror
                            @if ($mail['has_password'])
                                <label class="mt-2 inline-flex items-center gap-2 text-[13px] text-gray-600 dark:text-gray-300">
                                    <input type="checkbox" wire:model="clear_mail_password" class="rounded border-gray-300 text-gray-900" />
                                    {{ __('operator.mail.clear_password') }}
                                </label>
                            @endif
                        </div>
                    </div>
                    <div class="{{ $footer }}">
                        <button type="button" wire:click="clearOperatorMail" class="btn-quiet w-full sm:mr-auto sm:w-auto">{{ __('operator.mail.clear') }}</button>
                        <button type="button" wire:click="sendTestEmail" class="btn w-full sm:w-auto">{{ __('operator.mail.test') }}</button>
                        <button type="submit" class="btn-primary w-full sm:w-auto">{{ __('operator.mail.save') }}</button>
                    </div>
                </form>
            @endif
        </section>
    @elseif ($section === 'team')
        <section>
            <div class="section-head">
                <h2 class="section-title">{{ __('operator.team.caption') }} <span class="section-count">{{ count($settings['team']) }}</span></h2>
            </div>
            <div class="card overflow-x-auto">
                <table class="data-table">
                    <caption class="sr-only">{{ __('operator.team.caption') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('operator.team.columns.name') }}</th>
                            <th scope="col" class="hidden md:table-cell">{{ __('operator.team.columns.email') }}</th>
                            <th scope="col">{{ __('operator.team.columns.role') }}</th>
                            <th scope="col" class="hidden sm:table-cell">{{ __('operator.team.columns.status') }}</th>
                            <th scope="col" class="hidden lg:table-cell">{{ __('operator.team.columns.last_login') }}</th>
                            @if ($isAdmin)
                                <th scope="col" class="text-right!"><span class="sr-only">{{ __('operator.team.columns.actions') }}</span></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($settings['team'] as $member)
                            <tr>
                                <td>
                                    <span class="flex min-w-0 items-center gap-2.5">
                                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-200" aria-hidden="true">{{ $member['initials'] }}</span>
                                        <span class="min-w-0">
                                            <span class="block truncate font-medium text-gray-900 dark:text-white">{{ $member['name'] }}</span>
                                            <span class="block truncate text-xs text-gray-500 md:hidden">{{ $member['email'] }}</span>
                                        </span>
                                    </span>
                                </td>
                                <td class="hidden text-gray-500 md:table-cell">{{ $member['email'] }}</td>
                                <td class="text-gray-600 dark:text-gray-300">
                                    @if ($isAdmin)
                                        <select wire:change="updateUserRole({{ (int) $member['id'] }}, $event.target.value)" aria-label="{{ __('operator.team.columns.role') }}" class="h-8 rounded-lg border border-gray-200 bg-white px-2 text-[13px] dark:border-gray-700 dark:bg-transparent">
                                            @foreach ($roleOptions as $value => $label)
                                                <option value="{{ $value }}" @selected(($member['role'] ?? '') === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        {{ $roleOptions[$member['role'] ?? ''] ?? ($member['role'] ?? '') }}
                                    @endif
                                </td>
                                <td class="hidden sm:table-cell">
                                    <x-ta.pill :tone="$member['is_active'] ? 'ok' : 'info'">{{ $member['is_active'] ? __('operator.team.active') : __('operator.team.inactive') }}</x-ta.pill>
                                </td>
                                <td class="hidden text-[13px] text-gray-500 lg:table-cell">{{ $member['last_login'] ?? '—' }}</td>
                                @if ($isAdmin)
                                    <td class="text-right">
                                        @if ($member['is_active'])
                                            <button type="button" wire:click="deactivateUser({{ (int) $member['id'] }})" class="btn h-7! px-2.5! text-xs!">
                                                {{ __('operator.team.deactivate') }}
                                            </button>
                                        @else
                                            <button type="button" wire:click="reactivateUser({{ (int) $member['id'] }})" class="btn h-7! px-2.5! text-xs!">
                                                {{ __('operator.team.reactivate') }}
                                            </button>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($errors->has('user') || $errors->has('role'))
                <p class="mt-2 text-sm text-error-600 dark:text-error-400" role="alert">
                    {{ $errors->first('user') ?: $errors->first('role') }}
                </p>
            @endif
            <p class="mt-2 text-xs text-gray-500">
                {{ __('operator.team.owner_note') }} {{ __('operator.team.deactivate_note') }}
            </p>
        </section>

        @if ($isAdmin)
            <section>
                <div class="section-head"><h2 class="section-title">{{ __('operator.team.add_title') }}</h2></div>
                <form wire:submit="addTeamMember" class="card">
                    <div class="{{ $row }}">
                        <label for="team-name" class="{{ $rowLabel }}"><span class="{{ $labelText }}">{{ __('operator.team.fields.name') }} <span class="text-error-500">*</span></span></label>
                        <div class="{{ $control }}">
                            <input id="team-name" wire:model="new_name" type="text" class="{{ $input }}" />
                            @error('new_name')<p class="{{ $error }}">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="{{ $row }}">
                        <label for="team-email" class="{{ $rowLabel }}"><span class="{{ $labelText }}">{{ __('operator.team.fields.email') }} <span class="text-error-500">*</span></span></label>
                        <div class="{{ $control }}">
                            <input id="team-email" wire:model="new_email" type="email" class="{{ $input }}" />
                            @error('new_email')<p class="{{ $error }}">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="{{ $row }}">
                        <label for="team-role" class="{{ $rowLabel }}"><span class="{{ $labelText }}">{{ __('operator.team.fields.role') }} <span class="text-error-500">*</span></span></label>
                        <div class="{{ $control }}">
                            <select id="team-role" wire:model="new_role" class="{{ $input }}">
                                @foreach ($roleOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('new_role')<p class="{{ $error }}">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <label class="card-row cursor-pointer">
                        <span class="min-w-0 flex-1 text-sm font-medium text-gray-900 dark:text-white">{{ __('operator.team.fields.status') }}</span>
                        <span class="inline-flex items-center gap-2 text-[13px] text-gray-600 dark:text-gray-300">
                            <input type="checkbox" wire:model="new_is_active" class="h-4 w-4 rounded border-gray-300 text-gray-900" />
                            {{ __('operator.team.active') }}
                        </span>
                    </label>
                    <div class="{{ $row }}">
                        <div class="{{ $rowLabel }}">
                            <label for="team-password" class="{{ $labelText }}">{{ __('operator.team.fields.password') }} <span class="text-error-500">*</span></label>
                            <span class="{{ $hint }}">{{ __('operator.team.password_hint') }}</span>
                        </div>
                        <div class="{{ $control }}">
                            <input id="team-password" wire:model="new_password" type="password" autocomplete="new-password" class="{{ $input }}" />
                            @error('new_password')<p class="{{ $error }}">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="{{ $row }}">
                        <label for="team-password-confirm" class="{{ $rowLabel }}"><span class="{{ $labelText }}">{{ __('operator.team.fields.password_confirmation') }} <span class="text-error-500">*</span></span></label>
                        <div class="{{ $control }}">
                            <input id="team-password-confirm" wire:model="new_password_confirmation" type="password" autocomplete="new-password" class="{{ $input }}" />
                            @error('new_password_confirmation')<p class="{{ $error }}">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="{{ $footer }}">
                        <button type="submit" class="btn-primary w-full sm:w-auto">{{ __('operator.team.add') }}</button>
                    </div>
                </form>
            </section>
        @endif
    @elseif ($section === 'notifications')
        <section>
            <div class="section-head">
                <h2 class="section-title">{{ $current['label'] ?? __('operator.settings_ia.notifications') }} <span class="section-count">{{ count($settings['notifications']) }}</span></h2>
            </div>
            <ul class="card">
                @foreach ($settings['notifications'] as $index => $row)
                    @php($enabled = $notificationEnabled[$index] ?? $row['enabled'])
                    <li class="card-row">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['event'] }}</p>
                            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $row['channel'] }}</p>
                        </div>
                        <button type="button" wire:click="toggleNotification({{ $index }})" aria-pressed="{{ $enabled ? 'true' : 'false' }}" class="btn w-20">
                            <span @class(['h-1.5 w-1.5 rounded-full', 'bg-success-500' => $enabled, 'bg-gray-300 dark:bg-gray-600' => ! $enabled]) aria-hidden="true"></span>
                            {{ $enabled ? __('operator.notifications.on') : __('operator.notifications.off') }}
                        </button>
                    </li>
                @endforeach
            </ul>
            <p class="mt-2 text-xs text-gray-500">
                {{ __('operator.notifications.footnote') }}
            </p>
        </section>
    @elseif ($section === 'operations')
        <section>
            <div class="section-head" role="tablist">
                <button type="button" role="tab" wire:click="setOpsSub('defaults')" aria-selected="{{ ($ops_sub ?? 'defaults') === 'defaults' ? 'true' : 'false' }}" class="section-title">{{ __('operator.playbooks.settings_defaults') }}</button>
            </div>
            <div class="card">
                <div class="card-row flex-wrap sm:flex-nowrap">
                    <span class="row-icon" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ __('operator.nav.dashboard') }}</p>
                        <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ __('operator.settings.operations.dashboard_mode_note') }}</p>
                    </div>
                    <a href="{{ route('operator.dashboard') }}" class="btn w-full sm:w-auto">{{ __('operator.nav.dashboard') }}</a>
                </div>
                <div class="card-row flex-wrap sm:flex-nowrap">
                    <span class="row-icon" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3-8 4 16 3-8h4"/></svg></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">Sistem Sağlığı</p>
                        <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">Zamanlayıcı ve işçiler, sistem uyarıları, bağlantı yetkileri ve hesap bazında veri tazeliği.</p>
                    </div>
                    <a href="{{ route('operator.settings.system-health') }}" class="btn w-full sm:w-auto">Sistem Sağlığı</a>
                </div>
                <div class="card-row flex-wrap sm:flex-nowrap">
                    <span class="row-icon" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M4 12h16M4 17h10"/></svg></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">Sektör paketleri</p>
                    </div>
                    <a href="{{ route('operator.settings.sector-packs') }}" class="btn w-full sm:w-auto">Sektör paketleri</a>
                </div>
            </div>
        </section>
    @elseif ($section === 'ai')
        <section>
            <div class="section-head"><h2 class="section-title">{{ __('operator.settings.ai.overview') }}</h2></div>
            {{-- Only Claude is used (2026-10-19, owner decision): every AI step runs on the Claude MCP queue; the
                 other providers are hidden here and on Entegrasyonlar (their pages and code stay). --}}
            <div class="card">
                <div class="card-row flex-wrap sm:flex-nowrap">
                    <span class="row-icon font-semibold text-gray-700" aria-hidden="true">C</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">Anthropic (Claude)</p>
                        <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $settings['ai']['note'] }}</p>
                    </div>
                    <span class="text-[13px] text-gray-500 dark:text-gray-400">{{ $settings['ai']['anthropic'] }}</span>
                    <a href="{{ route('operator.integrations.ai', ['provider' => 'anthropic']) }}" class="btn w-full sm:w-auto">Anthropic</a>
                </div>
                <div class="card-row flex-wrap sm:flex-nowrap">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">AI işlemleri ve promptlar</p>
                    </div>
                    <a href="{{ route('operator.settings.ai-operations') }}" class="btn w-full sm:w-auto">AI işlemleri ve promptlar</a>
                </div>
                <div class="card-row flex-wrap sm:flex-nowrap">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">AI işleri</p>
                    </div>
                    <a href="{{ route('operator.ai-jobs') }}" class="btn w-full sm:w-auto">AI işleri</a>
                </div>
            </div>
        </section>
    @elseif ($section === 'advanced')
        @if ($advanced_sub === null)
            <div class="-mt-4 flex flex-wrap gap-2">
                <button type="button" wire:click="setAdvancedSub('files')" class="btn">{{ __('operator.settings_ia.advanced_files') }}</button>
                <button type="button" wire:click="setAdvancedSub('privacy')" class="btn">{{ __('operator.settings_ia.advanced_privacy') }}</button>
                <button type="button" wire:click="setAdvancedSub('diagnostics')" class="btn">{{ __('operator.settings_ia.advanced_diagnostics') }}</button>
            </div>
        @else
            <div class="-mt-4"><button type="button" wire:click="setAdvancedSub(null)" class="btn-quiet -ml-2.5">{{ __('operator.settings.advanced.back') }}</button></div>
        @endif

        @if ($advanced_sub === null || $advanced_sub === 'diagnostics')
            <section>
                <div class="section-head">
                    <h2 class="section-title">{{ __('operator.settings_ia.advanced_diagnostics') }}</h2>
                    <a href="{{ route('operator.dashboard') }}" class="ml-auto text-[13px] text-gray-500 hover:text-gray-900 dark:hover:text-white">{{ __('operator.nav.dashboard') }}</a>
                </div>
                <dl class="card">
                    <div class="card-row justify-between"><dt class="text-sm text-gray-600 dark:text-gray-300">{{ __('operator.settings.advanced.environment') }}</dt><dd class="text-sm font-medium text-gray-900 dark:text-white">{{ $settings['advanced']['environment'] }}</dd></div>
                    <div class="card-row justify-between"><dt class="text-sm text-gray-600 dark:text-gray-300">{{ __('operator.settings.advanced.surface') }}</dt><dd class="text-sm font-medium text-gray-900 dark:text-white">{{ $settings['advanced']['canonical_surface'] }}</dd></div>
                </dl>
                <p class="mt-2 text-xs text-gray-500">{{ __('operator.settings.advanced.note') }}</p>
            </section>
        @endif

        @if ($advanced_sub === null || $advanced_sub === 'files')
            <section id="files">
                <div class="section-head">
                    <h2 class="section-title">{{ __('operator.settings_ia.advanced_files') }}</h2>
                    <a href="{{ route('operator.files') }}" class="ml-auto text-[13px] text-gray-500 hover:text-gray-900 dark:hover:text-white">{{ __('operator.nav.files') }}</a>
                </div>
                <dl class="card">
                    <div class="card-row flex-wrap justify-between"><dt class="text-sm text-gray-600 dark:text-gray-300">{{ __('operator.settings.files.private_disk_label') }}</dt><dd class="text-sm font-medium text-gray-900 dark:text-white">{{ $settings['files']['disk'] }}</dd></div>
                    <div class="card-row flex-wrap justify-between"><dt class="text-sm text-gray-600 dark:text-gray-300">{{ __('operator.settings.files.avatar_disk_label') }}</dt><dd class="text-sm font-medium text-gray-900 dark:text-white">{{ $settings['files']['avatar_disk'] }}</dd></div>
                    <div class="card-row flex-wrap justify-between"><dt class="text-sm text-gray-600 dark:text-gray-300">{{ __('operator.settings.files.max_upload') }}</dt><dd class="text-sm font-medium tabular-nums text-gray-900 dark:text-white">{{ $settings['files']['max_upload_mb'] }} MB</dd></div>
                    <div class="card-row flex-wrap justify-between"><dt class="text-sm text-gray-600 dark:text-gray-300">{{ __('operator.settings.files.allowed_label') }}</dt><dd class="text-sm font-medium text-gray-900 sm:text-right dark:text-white">{{ $settings['files']['allowed'] }}</dd></div>
                    <div class="card-row flex-wrap justify-between"><dt class="text-sm text-gray-600 dark:text-gray-300">{{ __('operator.settings.files.blocked_label') }}</dt><dd class="text-sm font-medium text-gray-900 sm:text-right dark:text-white">{{ $settings['files']['blocked'] }}</dd></div>
                </dl>
                <p class="mt-2 text-xs text-gray-500">{{ $settings['files']['note'] }}</p>
            </section>
        @endif

        @if ($advanced_sub === null || $advanced_sub === 'privacy')
            <section id="privacy">
                <div class="section-head"><h2 class="section-title">{{ __('operator.settings_ia.advanced_privacy') }}</h2></div>
                <dl class="card">
                    <div class="card-row flex-wrap justify-between"><dt class="text-sm text-gray-600 dark:text-gray-300">{{ __('operator.settings.privacy.retention_label') }}</dt><dd class="text-sm text-gray-900 sm:text-right dark:text-white">{{ $settings['privacy']['retention'] }}</dd></div>
                    <div class="card-row flex-wrap justify-between"><dt class="text-sm text-gray-600 dark:text-gray-300">{{ __('operator.settings.privacy.export_label') }}</dt><dd class="text-sm text-gray-900 sm:text-right dark:text-white">{{ $settings['privacy']['export'] }}</dd></div>
                    <div class="card-row flex-wrap justify-between"><dt class="text-sm text-gray-600 dark:text-gray-300">{{ __('operator.settings.privacy.purge_label') }}</dt><dd class="text-sm text-gray-900 sm:text-right dark:text-white">{{ $settings['privacy']['purge'] }}</dd></div>
                </dl>
            </section>
        @endif
    @endif
</div>
