<div class="relative hidden lg:block" x-data="{ open: @entangle('open') }" @click.outside="open = false">
    <label class="sr-only" for="global-portfolio-search">{{ __('operator.actions.search') }}</label>
    <input
        id="global-portfolio-search"
        type="search"
        wire:model.live.debounce.200ms="q"
        placeholder="{{ __('operator.search.placeholder') }}"
        class="h-11 w-56 rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 placeholder:text-gray-400 focus:border-brand-300 focus:outline-none dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
        autocomplete="off"
        @focus="open = $wire.q.trim() !== ''"
    />
    <div
        x-show="open"
        x-cloak
        class="absolute right-0 z-50 mt-2 w-80 overflow-hidden rounded-xl bg-white shadow-lg ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700"
        role="listbox"
    >
        @forelse ($results as $row)
            <a
                href="{{ $row['url'] }}"
                wire:navigate
                wire:click="select"
                class="block px-4 py-3 text-sm hover:bg-gray-50 dark:hover:bg-white/[0.03]"
                role="option"
            >
                <span class="font-medium text-gray-800 dark:text-white/90">{{ $row['label'] }}</span>
                <span class="mt-0.5 block text-xs text-gray-500">{{ $row['meta'] }}</span>
            </a>
        @empty
            @if (trim($q) !== '')
                <p class="px-4 py-3 text-sm text-gray-500">{{ __('operator.search.empty') }}</p>
            @endif
        @endforelse
    </div>
</div>
