@php
    // Settings rows (panel concept 2026-10-20): label and one gray line on the left, the control on the right.
    $row = 'card-row flex-wrap items-start sm:flex-nowrap';
    $rowLabel = 'w-full min-w-0 sm:w-auto sm:flex-1 sm:pt-2';
    $labelText = 'block text-sm font-medium text-gray-900 dark:text-white';
    $hint = 'mt-0.5 block text-[13px] text-gray-500 dark:text-gray-400';
    $control = 'w-full min-w-0 sm:w-80 sm:shrink-0';
    $input = 'h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white';
    $error = 'mt-1 block text-xs text-error-600 dark:text-error-400';
    $footer = 'flex flex-wrap items-center justify-end gap-2 border-t border-gray-200 bg-gray-50/60 px-4 py-3 dark:border-gray-800 dark:bg-white/[0.02]';
@endphp
<div class="max-w-[1120px] space-y-10">
    @include('livewire.demo.partials.flash')

    <header class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3.5">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-full bg-gray-100 text-lg font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-200" aria-hidden="true">
                @if ($avatarUrl)
                    <img src="{{ $avatarUrl }}" alt="" class="h-full w-full object-cover" />
                @else
                    {{ strtoupper(mb_substr($name !== '' ? $name : 'U', 0, 1)) }}
                @endif
            </span>
            <div class="min-w-0">
                <h1 class="page-title">{{ __('operator.profile.title') }}</h1>
                <p class="page-sub mt-0! truncate">{{ $email }}</p>
            </div>
        </div>
        <form method="POST" action="{{ route('app.logout') }}" class="w-full sm:w-auto">
            @csrf
            <button type="submit" class="btn w-full sm:w-auto">{{ __('operator.auth.logout') }}</button>
        </form>
    </header>

    <section>
        <form wire:submit="save" class="card">
            <div class="{{ $row }}">
                <div class="{{ $rowLabel }}">
                    <span class="{{ $labelText }}">{{ __('operator.profile.avatar') }}</span>
                    <span class="{{ $hint }}">{{ __('operator.profile.avatar_hint') }}</span>
                </div>
                <div class="{{ $control }} space-y-2">
                    <input type="file" wire:model="avatar" accept="image/jpeg,image/png,image/webp" aria-label="{{ __('operator.profile.avatar') }}"
                        class="block w-full text-[13px] text-gray-600 file:mr-3 file:h-8 file:rounded-lg file:border file:border-gray-300 file:bg-white file:px-3 file:text-[13px] file:font-medium file:text-gray-900 hover:file:bg-gray-50 dark:text-gray-300 dark:file:border-gray-700 dark:file:bg-white/[0.04] dark:file:text-gray-200" />
                    @error('avatar') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    @if ($user->avatar_path || $avatarUrl)
                        <button type="button" wire:click="markAvatarForRemoval" class="btn-quiet -ml-2.5 h-7!">
                            {{ __('operator.profile.remove_avatar') }}
                        </button>
                    @endif
                    @if ($removeAvatar)
                        <p class="text-xs text-warning-700 dark:text-warning-400">{{ __('operator.profile.remove_avatar_pending') }}</p>
                    @endif
                </div>
            </div>
            <div class="{{ $row }}">
                <label for="profile-name" class="{{ $rowLabel }}"><span class="{{ $labelText }}">{{ __('operator.profile.name') }}</span></label>
                <div class="{{ $control }}">
                    <input id="profile-name" wire:model="name" type="text" class="{{ $input }}" />
                    @error('name') <span class="{{ $error }}">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="{{ $row }}">
                <label for="profile-email" class="{{ $rowLabel }}"><span class="{{ $labelText }}">{{ __('operator.profile.email') }}</span></label>
                <div class="{{ $control }}">
                    <input id="profile-email" wire:model="email" type="email" class="{{ $input }}" />
                    @error('email') <span class="{{ $error }}">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="{{ $row }}">
                <label for="profile-locale" class="{{ $rowLabel }}"><span class="{{ $labelText }}">{{ __('operator.profile.locale') }}</span></label>
                <div class="{{ $control }}">
                    <select id="profile-locale" wire:model="locale" class="{{ $input }}">
                        <option value="en">{{ __('operator.languages.en') }}</option>
                        <option value="tr">{{ __('operator.languages.tr') }}</option>
                    </select>
                    @error('locale') <span class="{{ $error }}">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="{{ $row }}">
                <label for="profile-timezone" class="{{ $rowLabel }}"><span class="{{ $labelText }}">{{ __('operator.profile.timezone') }}</span></label>
                <div class="{{ $control }}">
                    <select id="profile-timezone" wire:model="timezone" class="{{ $input }}">
                        <option value="">—</option>
                        @foreach ($timezones as $tz)
                            <option value="{{ $tz }}">{{ $tz }}</option>
                        @endforeach
                    </select>
                    @error('timezone') <span class="{{ $error }}">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="{{ $row }}">
                <label for="profile-password" class="{{ $rowLabel }}"><span class="{{ $labelText }}">{{ __('operator.profile.password') }}</span></label>
                <div class="{{ $control }}">
                    <input id="profile-password" wire:model="password" type="password" autocomplete="new-password" class="{{ $input }}" />
                    @error('password') <span class="{{ $error }}">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="{{ $row }}">
                <label for="profile-password-confirm" class="{{ $rowLabel }}"><span class="{{ $labelText }}">{{ __('operator.profile.password_confirmation') }}</span></label>
                <div class="{{ $control }}">
                    <input id="profile-password-confirm" wire:model="password_confirmation" type="password" autocomplete="new-password" class="{{ $input }}" />
                </div>
            </div>
            <div class="{{ $footer }}">
                <a href="{{ route('operator.settings') }}" class="btn w-full sm:w-auto">{{ __('operator.actions.cancel') }}</a>
                <button type="submit" class="btn-primary w-full sm:w-auto">{{ __('operator.actions.save') }}</button>
            </div>
        </form>
    </section>

    <section>
        <div class="section-head items-center">
            <h2 class="section-title">{{ __('two_factor.section_title') }}</h2>
            <x-ta.pill :tone="$twoFactorEnabled ? 'ok' : 'look'" class="ml-auto">{{ $twoFactorEnabled ? __('two_factor.status_on') : __('two_factor.status_off') }}</x-ta.pill>
        </div>
        {{-- Canlı panel (2026-10-20): while 2FA is off the card is a tinted warning; the form's Kaydet stays the one accent button. --}}
        <div @class(['card' => $twoFactorEnabled, 'relative min-w-0 overflow-hidden rounded-3xl border soft-amber' => ! $twoFactorEnabled])>
            <div class="card-row flex-wrap sm:flex-nowrap">
                <span @class(['row-icon' => $twoFactorEnabled, 'soft-icon text-warning-700 dark:text-warning-400' => ! $twoFactorEnabled]) aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></span>
                <p class="min-w-0 flex-1 text-[13px] text-gray-600 dark:text-gray-400">{{ __('two_factor.section_hint') }}</p>
                @if ($twoFactorPendingSecret === null && ! $twoFactorEnabled)
                    <button type="button" wire:click="startTwoFactorSetup" class="btn-dark w-full sm:w-auto">{{ __('two_factor.enable') }}</button>
                @endif
            </div>

            @if ($twoFactorRecoveryCodes !== [])
                <div class="card-row block bg-warning-50/60 dark:bg-warning-500/10">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ __('two_factor.recovery_codes') }}</p>
                    <ul class="mt-2 grid gap-1 font-mono text-xs text-gray-800 sm:grid-cols-2 dark:text-gray-200">
                        @foreach ($twoFactorRecoveryCodes as $recoveryCode)
                            <li>{{ $recoveryCode }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($twoFactorPendingSecret !== null)
                <div class="card-row block space-y-3">
                    <p class="text-sm text-gray-700 dark:text-gray-300">{{ __('two_factor.scan') }}</p>
                    @if ($twoFactorQr)
                        <img src="{{ $twoFactorQr }}" alt="QR" class="h-44 w-44 rounded-lg border border-gray-200 bg-white p-2" />
                    @endif
                    <p class="text-[13px] text-gray-500 dark:text-gray-400">{{ __('two_factor.setup_key') }}: <span class="select-all break-all font-mono text-gray-800 dark:text-gray-200">{{ $twoFactorPendingSecret }}</span></p>
                    <label class="block max-w-xs">
                        <span class="text-[13px] text-gray-600 dark:text-gray-400">{{ __('two_factor.confirm_code') }}</span>
                        <input wire:model="twoFactorCode" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="{{ $input }} mt-1 tracking-widest" />
                        @error('twoFactorCode') <span class="{{ $error }}">{{ $message }}</span> @enderror
                    </label>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="cancelTwoFactorSetup" class="btn">{{ __('two_factor.cancel') }}</button>
                        <button type="button" wire:click="confirmTwoFactor" class="btn-primary">{{ __('two_factor.confirm') }}</button>
                    </div>
                </div>
            @elseif ($twoFactorEnabled)
                <div class="card-row flex-wrap items-end">
                    <label class="block w-full sm:w-56">
                        <span class="text-[13px] text-gray-600 dark:text-gray-400">{{ __('two_factor.disable_code') }}</span>
                        <input wire:model="twoFactorCode" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="{{ $input }} mt-1 tracking-widest" />
                    </label>
                    <button type="button" wire:click="regenerateRecoveryCodes" class="btn h-9! w-full sm:w-auto">{{ __('two_factor.regenerate') }}</button>
                    <button type="button" wire:click="disableTwoFactor" class="btn h-9! w-full sm:w-auto">{{ __('two_factor.disable') }}</button>
                    @error('twoFactorCode') <p class="w-full text-xs text-error-600 dark:text-error-400">{{ $message }}</p> @enderror
                </div>
            @endif
        </div>
    </section>
</div>
