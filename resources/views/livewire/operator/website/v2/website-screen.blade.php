@php
    $siteUrl = $site->primary_url ?: ($site->domain ? 'https://'.$site->domain : null);
    $moreOpen = array_key_exists($tab, \App\Livewire\Operator\Website\V2\WebsiteScreen::MORE);
@endphp
<div class="space-y-5 text-sm dark:text-gray-200" data-website-screen>
    <header class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex min-w-0 flex-wrap items-baseline gap-x-3 gap-y-1">
                <a href="{{ route('operator.websites') }}" wire:navigate class="text-xs text-gray-500 hover:text-brand-600" aria-label="Web siteleri">←</a>
                <h1 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ $site->name }}</h1>
                <p class="text-xs text-gray-500">
                    @if ($site->brand)<a href="{{ route('operator.brand', ['brand' => $site->brand->id]) }}" wire:navigate class="hover:underline">{{ $site->brand->name }}</a>@endif
                    @if ($siteUrl) · <a href="{{ $siteUrl }}" target="_blank" rel="noopener noreferrer" class="hover:underline">siteye git ↗</a>@endif
                </p>
            </div>

            @if ($ranged)
                <x-operator.date-range-picker :range="$range" :last-day="$lastDay" note="Search Console verisi 2–3 gün gecikmeli." />
            @endif
        </div>

        <nav class="flex flex-wrap items-center gap-x-6 border-b border-gray-200 dark:border-gray-800" aria-label="Sekmeler">
            @foreach (\App\Livewire\Operator\Website\V2\WebsiteScreen::TABS as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')" data-tab="{{ $key }}" aria-current="{{ $tab === $key ? 'page' : 'false' }}"
                        @class(['-mb-px h-11 border-b-2 text-sm', 'border-gray-900 font-semibold text-gray-900 dark:border-white dark:text-white' => $tab === $key, 'border-transparent font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400' => $tab !== $key])>{{ $label }}</button>
            @endforeach
            <div class="relative" x-data="{ more: false }" @click.outside="more = false">
                <button type="button" @click="more = !more" data-tab-more :aria-expanded="more.toString()"
                        @class(['-mb-px flex h-11 items-center gap-1 border-b-2 text-sm', 'border-gray-900 font-semibold text-gray-900 dark:border-white dark:text-white' => $moreOpen, 'border-transparent font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400' => ! $moreOpen])>
                    {{ $moreOpen ? \App\Livewire\Operator\Website\V2\WebsiteScreen::MORE[$tab] : 'Diğer' }}
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </button>
                <div x-show="more" x-cloak class="absolute left-0 z-30 mt-1 w-64 rounded-xl border border-gray-200 bg-white p-1 shadow-lg dark:border-gray-700 dark:bg-gray-900">
                    @foreach (\App\Livewire\Operator\Website\V2\WebsiteScreen::MORE as $key => $label)
                        <button type="button" wire:click="setTab('{{ $key }}')" @click="more = false" data-tab="{{ $key }}" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-800">{{ $label }}</button>
                    @endforeach
                </div>
            </div>
        </nav>
    </header>

    <x-operator.asset-context :asset-id="$site->id" />

    @foreach ($banners as $bannerState => $group)
        <section class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200" data-data-status-banner="{{ $bannerState }}">
            <span>{{ __('data_status.banner.'.$bannerState.'_title', ['sources' => $group->map(fn ($status) => $status->sourceLabel())->implode(' / ')], 'tr') }}</span>
            @if ($bannerState === 'not_bound')
                <a href="{{ route('operator.asset.sources', ['assetId' => $site->id]) }}" wire:navigate class="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white">{{ __('data_status.actions.bind', [], 'tr') }}</a>
            @endif
        </section>
    @endforeach

    @forelse ($views as [$component, $params])
        @livewire($component, ['assetId' => $site->id, ...$params], key($component.'-'.$tab.'-'.$site->id.'-'.$rangeKey))
    @empty
        <p class="rounded-xl bg-white p-4 text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-placeholder>Hazırlanıyor</p>
    @endforelse
</div>
