{{-- Operator header, adapted from TailAdmin layouts/app-header.blade.php --}}
<header
    class="sticky top-0 flex w-full bg-white border-gray-200 z-99990 dark:border-gray-800 dark:bg-gray-900 xl:border-b">
    <div class="flex flex-wrap items-center justify-between gap-y-2 grow px-3 py-3 xl:px-6 lg:py-4 min-w-0">
        <div class="flex items-center gap-2 sm:gap-4">
            <button
                class="hidden xl:flex items-center justify-center w-11 h-11 text-gray-500 border border-gray-200 rounded-lg dark:border-gray-800 dark:text-gray-400"
                :class="{ 'bg-gray-100 dark:bg-white/[0.03]': !$store.sidebar.isExpanded }"
                @click="$store.sidebar.toggleExpanded()" aria-label="Toggle Sidebar">
                <svg width="16" height="12" viewBox="0 0 16 12" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" clip-rule="evenodd"
                        d="M0.583252 1C0.583252 0.585788 0.919038 0.25 1.33325 0.25H14.6666C15.0808 0.25 15.4166 0.585786 15.4166 1C15.4166 1.41421 15.0808 1.75 14.6666 1.75L1.33325 1.75C0.919038 1.75 0.583252 1.41422 0.583252 1ZM0.583252 11C0.583252 10.5858 0.919038 10.25 1.33325 10.25L14.6666 10.25C15.0808 10.25 15.4166 10.5858 15.4166 11C15.4166 11.4142 15.0808 11.75 14.6666 11.75L1.33325 11.75C0.919038 11.75 0.583252 11.4142 0.583252 11ZM1.33325 5.25C0.919038 5.25 0.583252 5.58579 0.583252 6C0.583252 6.41421 0.919038 6.75 1.33325 6.75L7.99992 6.75C8.41413 6.75 8.74992 6.41421 8.74992 6C8.74992 5.58579 8.41413 5.25 7.99992 5.25Z"
                        fill="currentColor"></path>
                </svg>
            </button>

            <button
                class="flex xl:hidden items-center justify-center w-11 h-11 text-gray-500 border border-gray-200 rounded-lg dark:border-gray-800 dark:text-gray-400"
                @click="$store.sidebar.toggleMobileOpen()" aria-label="Toggle Mobile Menu">
                <svg width="16" height="12" viewBox="0 0 16 12" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" clip-rule="evenodd"
                        d="M0.583252 1C0.583252 0.585788 0.919038 0.25 1.33325 0.25H14.6666C15.0808 0.25 15.4166 0.585786 15.4166 1C15.4166 1.41421 15.0808 1.75 14.6666 1.75L1.33325 1.75C0.919038 1.75 0.583252 1.41422 0.583252 1ZM0.583252 11C0.583252 10.5858 0.919038 10.25 1.33325 10.25L14.6666 10.25C15.0808 10.25 15.4166 10.5858 15.4166 11C15.4166 11.4142 15.0808 11.75 14.6666 11.75L1.33325 11.75C0.919038 11.75 0.583252 11.4142 0.583252 11ZM1.33325 5.25C0.919038 5.25 0.583252 5.58579 0.583252 6C0.583252 6.41421 0.919038 6.75 1.33325 6.75L7.99992 6.75C8.41413 6.75 8.74992 6.41421 8.74992 6C8.74992 5.58579 8.41413 5.25 7.99992 5.25Z"
                        fill="currentColor"></path>
                </svg>
            </button>
        </div>

        <div class="flex min-w-0 flex-wrap items-center justify-end gap-2 sm:gap-3">
            <livewire:demo.partials.active-data-sync-indicator />
            @auth
                <livewire:operator.ai-live-indicator />
            @endauth
            <livewire:demo.global-search />

            <x-ta.theme-toggle />

            <livewire:demo.notification-bell />

            @auth
                {{-- Profile menu: profile, language (EN / TR) and sign out; one user, so the language is not on every page. --}}
                <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false" data-profile-menu>
                    <button type="button" @click="open = ! open" :aria-expanded="open" class="flex items-center gap-2 pl-2" aria-haspopup="menu" aria-label="{{ __('operator.nav.profile') }}">
                        @if (! empty(auth()->user()->avatar_path))
                            <img src="{{ asset('storage/'.auth()->user()->avatar_path) }}" alt="" class="h-9 w-9 rounded-full object-cover ring-1 ring-gray-200 dark:ring-gray-700" />
                        @else
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-gray-100 text-sm font-semibold text-gray-700 dark:bg-white/5 dark:text-gray-300">
                                {{ strtoupper(mb_substr(auth()->user()->name ?? 'U', 0, 1)) }}
                            </span>
                        @endif
                        <span class="hidden md:block text-sm font-medium text-gray-700 dark:text-gray-300">{{ auth()->user()->name }}</span>
                        <svg class="hidden h-4 w-4 text-gray-400 md:block" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06z" clip-rule="evenodd"/></svg>
                    </button>
                    <div x-show="open" x-cloak x-transition.opacity role="menu"
                        class="absolute right-0 z-50 mt-2 w-60 rounded-xl bg-white p-2 shadow-lg ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700">
                        <p class="truncate px-3 pb-2 pt-1 text-xs text-gray-500 dark:text-gray-400">{{ auth()->user()->email }}</p>
                        <a href="{{ route('operator.profile') }}" wire:navigate role="menuitem" class="block rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">{{ __('operator.nav.profile') }}</a>
                        <div class="flex items-center justify-between gap-2 rounded-lg px-3 py-2 text-sm text-gray-700 dark:text-gray-300">
                            <span>{{ __('operator.profile.locale') }}</span>
                            <livewire:demo.locale-switcher />
                        </div>
                        <form method="POST" action="{{ route('app.logout') }}" class="border-t border-gray-100 pt-1 dark:border-gray-800">
                            @csrf
                            <button type="submit" role="menuitem" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">{{ __('operator.auth.logout') }}</button>
                        </form>
                    </div>
                </div>
            @endauth
        </div>
    </div>
</header>