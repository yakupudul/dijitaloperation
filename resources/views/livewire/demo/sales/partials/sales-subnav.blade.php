@php
    $current = $current ?? '';
@endphp
<nav class="flex flex-wrap gap-2" aria-label="{{ __('operator.nav.groups.sales') }}">
    <a href="{{ route('operator.prospects') }}" wire:navigate
        class="rounded-lg px-3 py-1.5 text-sm font-medium {{ $current === 'prospects' ? 'bg-brand-50 text-brand-700 ring-1 ring-brand-200' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300' }}">
        {{ __('operator.nav.prospects') }}
    </a>
    <a href="{{ route('operator.intent-radar') }}" wire:navigate
        class="rounded-lg px-3 py-1.5 text-sm font-medium {{ $current === 'intent-radar' ? 'bg-brand-50 text-brand-700 ring-1 ring-brand-200' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300' }}">
        {{ __('operator.nav.intent_radar') }}
    </a>
    <a href="{{ route('operator.search-profiles') }}" wire:navigate
        class="rounded-lg px-3 py-1.5 text-sm font-medium {{ $current === 'search-profiles' ? 'bg-brand-50 text-brand-700 ring-1 ring-brand-200' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300' }}">
        {{ __('operator.nav.search_profiles') }}
    </a>
</nav>
