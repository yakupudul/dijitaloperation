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
    <form method="POST" action="{{ route('app.logout') }}">
        @csrf
        <x-ta.button type="submit" size="sm" variant="outline">{{ __('operator.auth.logout') }}</x-ta.button>
    </form>
    </div>
</div>
