<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div>
        <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">{{ __('operator.profile.title') }}</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('operator.profile.subtitle') }}</p>
    </div>

    <div class="space-y-6 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
    <form wire:submit="save" class="space-y-6">
        <div class="flex flex-wrap items-start gap-4">
            <div class="flex h-20 w-20 items-center justify-center overflow-hidden rounded-full bg-brand-500/10 text-2xl font-semibold text-brand-600 dark:text-brand-400">
                @if ($avatarUrl)
                    <img src="{{ $avatarUrl }}" alt="" class="h-full w-full object-cover" />
                @else
                    {{ strtoupper(mb_substr($name !== '' ? $name : 'U', 0, 1)) }}
                @endif
            </div>
            <div class="min-w-0 flex-1 space-y-2">
                <label class="block text-sm">
                    <span class="text-gray-500 dark:text-gray-400">{{ __('operator.profile.avatar') }}</span>
                    <input type="file" wire:model="avatar" accept="image/jpeg,image/png,image/webp"
                        class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-500 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white dark:text-gray-300" />
                    <span class="mt-1 block text-xs text-gray-500">{{ __('operator.profile.avatar_hint') }}</span>
                </label>
                @error('avatar') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                @if ($user->avatar_path || $avatarUrl)
                    <button type="button" wire:click="markAvatarForRemoval" class="text-sm font-medium text-red-600 hover:underline dark:text-red-400">
                        {{ __('operator.profile.remove_avatar') }}
                    </button>
                @endif
                @if ($removeAvatar)
                    <p class="text-xs text-amber-600 dark:text-amber-400">{{ __('operator.profile.remove_avatar_pending') }}</p>
                @endif
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 text-sm">
            <label class="block">
                <span class="text-gray-500 dark:text-gray-400">{{ __('operator.profile.name') }}</span>
                <input wire:model="name" type="text" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700 dark:text-white" />
                @error('name') <span class="mt-1 block text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="text-gray-500 dark:text-gray-400">{{ __('operator.profile.email') }}</span>
                <input wire:model="email" type="email" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700 dark:text-white" />
                @error('email') <span class="mt-1 block text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="text-gray-500 dark:text-gray-400">{{ __('operator.profile.locale') }}</span>
                <select wire:model="locale" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700 dark:text-white">
                    <option value="en">{{ __('operator.languages.en') }}</option>
                    <option value="tr">{{ __('operator.languages.tr') }}</option>
                </select>
                @error('locale') <span class="mt-1 block text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="text-gray-500 dark:text-gray-400">{{ __('operator.profile.timezone') }}</span>
                <select wire:model="timezone" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700 dark:text-white">
                    <option value="">—</option>
                    @foreach ($timezones as $tz)
                        <option value="{{ $tz }}">{{ $tz }}</option>
                    @endforeach
                </select>
                @error('timezone') <span class="mt-1 block text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="text-gray-500 dark:text-gray-400">{{ __('operator.profile.password') }}</span>
                <input wire:model="password" type="password" autocomplete="new-password" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700 dark:text-white" />
                @error('password') <span class="mt-1 block text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="text-gray-500 dark:text-gray-400">{{ __('operator.profile.password_confirmation') }}</span>
                <input wire:model="password_confirmation" type="password" autocomplete="new-password" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 dark:border-gray-700 dark:text-white" />
            </label>
        </div>

        <div class="flex flex-wrap gap-2">
            <x-ta.button type="submit" size="sm">{{ __('operator.actions.save') }}</x-ta.button>
            <x-ta.button :href="route('operator.settings')" size="sm" variant="outline">{{ __('operator.actions.cancel') }}</x-ta.button>
        </div>
    </form>
    <section class="space-y-3 border-t border-gray-100 pt-5 text-sm dark:border-gray-800">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="font-semibold text-gray-800 dark:text-white/90">{{ __('two_factor.section_title') }}</h2>
                <p class="mt-1 max-w-2xl text-gray-500 dark:text-gray-400">{{ __('two_factor.section_hint') }}</p>
            </div>
            <span @class(['rounded-full px-2.5 py-1 text-xs font-medium', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $twoFactorEnabled, 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400' => ! $twoFactorEnabled])>
                {{ $twoFactorEnabled ? __('two_factor.status_on') : __('two_factor.status_off') }}
            </span>
        </div>

        @if ($twoFactorRecoveryCodes !== [])
            <div class="rounded-lg bg-amber-50 p-3 dark:bg-amber-500/10">
                <p class="font-medium text-amber-800 dark:text-amber-300">{{ __('two_factor.recovery_codes') }}</p>
                <ul class="mt-2 grid gap-1 font-mono text-xs text-gray-800 sm:grid-cols-2 dark:text-gray-200">
                    @foreach ($twoFactorRecoveryCodes as $recoveryCode)
                        <li>{{ $recoveryCode }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($twoFactorPendingSecret !== null)
            <div class="space-y-3 rounded-lg ring-1 ring-inset ring-gray-200 p-4 dark:ring-gray-800">
                <p class="text-gray-600 dark:text-gray-300">{{ __('two_factor.scan') }}</p>
                @if ($twoFactorQr)
                    <img src="{{ $twoFactorQr }}" alt="QR" class="h-44 w-44 rounded bg-white p-2" />
                @endif
                <p class="text-gray-500 dark:text-gray-400">{{ __('two_factor.setup_key') }}: <span class="select-all font-mono text-gray-800 dark:text-gray-200">{{ $twoFactorPendingSecret }}</span></p>
                <label class="block max-w-xs">
                    <span class="text-gray-500 dark:text-gray-400">{{ __('two_factor.confirm_code') }}</span>
                    <input wire:model="twoFactorCode" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 tracking-widest dark:border-gray-700 dark:text-white" />
                    @error('twoFactorCode') <span class="mt-1 block text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                </label>
                <div class="flex flex-wrap gap-2">
                    <x-ta.button type="button" size="sm" wire:click="confirmTwoFactor">{{ __('two_factor.confirm') }}</x-ta.button>
                    <x-ta.button type="button" size="sm" variant="outline" wire:click="cancelTwoFactorSetup">{{ __('two_factor.cancel') }}</x-ta.button>
                </div>
            </div>
        @elseif ($twoFactorEnabled)
            <div class="flex flex-wrap items-end gap-2">
                <label class="block max-w-xs">
                    <span class="text-gray-500 dark:text-gray-400">{{ __('two_factor.disable_code') }}</span>
                    <input wire:model="twoFactorCode" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 tracking-widest dark:border-gray-700 dark:text-white" />
                </label>
                <x-ta.button type="button" size="sm" variant="outline" wire:click="regenerateRecoveryCodes">{{ __('two_factor.regenerate') }}</x-ta.button>
                <x-ta.button type="button" size="sm" variant="outline" wire:click="disableTwoFactor">{{ __('two_factor.disable') }}</x-ta.button>
            </div>
            @error('twoFactorCode') <p class="text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        @else
            <x-ta.button type="button" size="sm" wire:click="startTwoFactorSetup">{{ __('two_factor.enable') }}</x-ta.button>
        @endif
    </section>

    <form method="POST" action="{{ route('app.logout') }}">
        @csrf
        <x-ta.button type="submit" size="sm" variant="outline">{{ __('operator.auth.logout') }}</x-ta.button>
    </form>
    </div>
</div>
