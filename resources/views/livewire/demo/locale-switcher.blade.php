<div class="flex items-center gap-1 rounded-lg border border-gray-200 p-1 dark:border-gray-800" role="group" aria-label="{{ __('operator.profile.locale') }}">
    <button
        type="button"
        wire:click="setLocale('en')"
        @class([
            'rounded-md px-2.5 py-1.5 text-xs font-semibold transition',
            'bg-brand-500 text-white' => $locale === 'en',
            'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.03]' => $locale !== 'en',
        ])
    >
        EN
    </button>
    <button
        type="button"
        wire:click="setLocale('tr')"
        @class([
            'rounded-md px-2.5 py-1.5 text-xs font-semibold transition',
            'bg-brand-500 text-white' => $locale === 'tr',
            'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.03]' => $locale !== 'tr',
        ])
    >
        TR
    </button>
</div>
