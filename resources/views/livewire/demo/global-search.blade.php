<div class="relative hidden lg:block" x-data="{ open: @entangle('open') }" @click.outside="open = false"
    @keydown.window.prevent.ctrl.k="$refs.search.focus(); $refs.search.select()"
    @keydown.window.prevent.meta.k="$refs.search.focus(); $refs.search.select()"
    @keydown.escape="open = false; $refs.search.blur()" data-global-search>
    <label class="sr-only" for="global-portfolio-search">{{ __('operator.actions.search') }}</label>
    <input
        id="global-portfolio-search"
        x-ref="search"
        type="search"
        wire:model.live.debounce.200ms="q"
        placeholder="{{ __('operator.search.placeholder') }}"
        aria-keyshortcuts="Control+K Meta+K"
        class="h-8 w-64 rounded-lg border border-gray-200 bg-white py-0 shadow-card pl-9 pr-12 text-sm text-gray-800 placeholder:text-gray-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-gray-300 dark:bg-white/5 dark:text-gray-200 dark:focus:bg-gray-900 dark:focus:ring-gray-700"
        autocomplete="off"
        @focus="open = $wire.q.trim() !== ''"
    />
    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
    <kbd class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 rounded-md bg-white px-1.5 py-0.5 text-xs font-medium text-gray-500 ring-1 ring-gray-200 dark:bg-white/[0.06] dark:text-gray-400 dark:ring-gray-700">{{ __('operator.search.shortcut') }}</kbd>
    <div
        x-show="open"
        x-cloak
        class="surface absolute left-0 z-50 mt-1.5 w-80 overflow-hidden shadow-theme-lg"
        role="listbox"
    >
        @forelse ($results as $row)
            <a
                href="{{ $row['url'] }}"
                wire:navigate
                wire:click="select"
                class="block px-4 py-2.5 text-sm hover:bg-gray-50 dark:hover:bg-white/[0.03]"
                role="option"
            >
                <span class="font-medium text-gray-900 dark:text-white">{{ $row['label'] }}</span>
                <span class="mt-0.5 block text-xs text-gray-500">{{ $row['meta'] }}</span>
            </a>
        @empty
            @if (trim($q) !== '')
                <p class="px-4 py-3 text-sm text-gray-500">{{ __('operator.search.empty') }}</p>
            @endif
        @endforelse
    </div>
</div>
