<div class="flex items-center gap-1" role="group" aria-label="{{ __('operator.profile.locale') }}">
    <button
        type="button"
        wire:click="setLocale('en')"
        @class([
            'rounded-md px-2.5 py-1.5 text-xs transition',
            'bg-gray-100 font-medium text-gray-900 dark:bg-white/10 dark:text-white' => $locale === 'en',
            'text-gray-500 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/[0.03]' => $locale !== 'en',
        ])
    >
        EN
    </button>
    <button
        type="button"
        wire:click="setLocale('tr')"
        @class([
            'rounded-md px-2.5 py-1.5 text-xs transition',
            'bg-gray-100 font-medium text-gray-900 dark:bg-white/10 dark:text-white' => $locale === 'tr',
            'text-gray-500 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/[0.03]' => $locale !== 'tr',
        ])
    >
        TR
    </button>
</div>
