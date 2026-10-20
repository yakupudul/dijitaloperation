{{-- Operator header (panel redesign 2026-10-19): one 52 px row inside the white panel — menu toggle, search, live indicator, theme, bell, profile. --}}
<header class="sticky top-0 z-99990 flex h-13 w-full items-center border-b border-gray-200 bg-white/90 backdrop-blur xl:rounded-t-xl dark:border-gray-800 dark:bg-gray-900/90" data-operator-header>
    <div class="flex min-w-0 grow items-center justify-between gap-3 px-3 xl:px-5">
        <div class="flex items-center gap-2">
            <button type="button"
                class="hidden h-9 w-9 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-800 xl:flex dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white"
                @click="$store.sidebar.toggleExpanded()" aria-label="Toggle Sidebar" title="Menüyü daralt / genişlet">
                <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/></svg>
            </button>
            <button type="button"
                class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-800 xl:hidden dark:text-gray-400 dark:hover:bg-white/5"
                @click="$store.sidebar.toggleMobileOpen()" aria-label="Toggle Mobile Menu">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
            </button>
            <livewire:demo.global-search />
        </div>

        <div class="flex min-w-0 items-center gap-1.5 sm:gap-2">
            {{-- Data jobs while something is happening; Claude's work is the card at the bottom of the menu (2026-10-20). --}}
            <div class="flex items-center gap-1.5" data-live-strip>
                <livewire:demo.partials.active-data-sync-indicator />
            </div>

            <x-ta.theme-toggle />

            <livewire:demo.notification-bell />

            @auth
                {{-- Profile menu: profile, language (EN / TR) and sign out; one user, so the language is not on every page. --}}
                <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false" data-profile-menu>
                    <button type="button" @click="open = ! open" :aria-expanded="open" class="flex h-9 items-center gap-2 rounded-lg pl-1 pr-1.5 hover:bg-gray-100 dark:hover:bg-white/5" aria-haspopup="menu" aria-label="{{ __('operator.nav.profile') }}">
                        @if (! empty(auth()->user()->avatar_path))
                            <img src="{{ asset('storage/'.auth()->user()->avatar_path) }}" alt="" class="h-7 w-7 rounded-full object-cover ring-1 ring-gray-200 dark:ring-gray-700" />
                        @else
                            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-200 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-200">
                                {{ strtoupper(mb_substr(auth()->user()->name ?? 'U', 0, 1)) }}
                            </span>
                        @endif
                        <span class="hidden max-w-32 truncate text-sm font-medium text-gray-700 md:block dark:text-gray-300">{{ auth()->user()->name }}</span>
                        <svg class="hidden h-3.5 w-3.5 text-gray-400 md:block" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06z" clip-rule="evenodd"/></svg>
                    </button>
                    <div x-show="open" x-cloak x-transition.opacity.duration.150ms role="menu"
                        class="absolute right-0 z-50 mt-1.5 w-60 rounded-xl bg-white p-1.5 shadow-theme-lg ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700">
                        <p class="truncate px-2.5 pb-1.5 pt-1 text-xs text-gray-500 dark:text-gray-400">{{ auth()->user()->email }}</p>
                        <a href="{{ route('operator.profile') }}" wire:navigate role="menuitem" class="block rounded-lg px-2.5 py-1.5 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5">{{ __('operator.nav.profile') }}</a>
                        <div class="flex items-center justify-between gap-2 rounded-lg px-2.5 py-1.5 text-sm text-gray-700 dark:text-gray-300">
                            <span>{{ __('operator.profile.locale') }}</span>
                            <livewire:demo.locale-switcher />
                        </div>
                        <form method="POST" action="{{ route('app.logout') }}" class="mt-1 border-t border-gray-100 pt-1 dark:border-gray-800">
                            @csrf
                            <button type="submit" role="menuitem" class="block w-full rounded-lg px-2.5 py-1.5 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5">{{ __('operator.auth.logout') }}</button>
                        </form>
                    </div>
                </div>
            @endauth
        </div>
    </div>
</header>
