@php
    $siteUrl = $site->primary_url ?: ($site->domain ? 'https://'.$site->domain : null);
    $screen = \App\Livewire\Operator\Website\V2\WebsiteScreen::class;
    $menu = $screen::SETTINGS + $screen::MORE;
    $moreOpen = array_key_exists($tab, $menu);
    $tabClass = fn (bool $active): string => '-mb-px flex h-10 shrink-0 items-center gap-1 whitespace-nowrap border-b-2 text-sm '.($active
        ? 'border-gray-900 font-semibold text-gray-900 dark:border-white dark:text-white'
        : 'border-transparent font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white');
@endphp
<div class="min-w-0 space-y-8 text-sm dark:text-gray-200" data-website-screen>
    <x-operator.asset-context :asset-id="$site->id" :title="$site->name">
        <x-slot:meta>
            @if ($siteUrl)
                <a href="{{ $siteUrl }}" target="_blank" rel="noopener noreferrer" class="link-neutral break-all">{{ $site->domain ?: $siteUrl }} ↗</a>
            @endif
        </x-slot:meta>

        @if ($ranged)
            <x-slot:actions>
                <x-operator.date-range-picker :range="$range" :last-day="$lastDay" note="Search Console verisi 2–3 gün gecikmeli." class="w-full sm:w-auto" />
            </x-slot:actions>
        @endif

        @foreach ($banners as $bannerState => $group)
            <p class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-warning-800 dark:text-warning-200" data-data-status-banner="{{ $bannerState }}">
                <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>
                <span class="min-w-0">{{ __('data_status.banner.'.$bannerState.'_title', ['sources' => $group->map(fn ($status) => $status->sourceLabel())->implode(' / ')], 'tr') }}</span>
                @if ($bannerState === 'not_bound')
                    <a href="{{ route('operator.asset.sources', ['assetId' => $site->id]) }}" wire:navigate class="link-primary font-medium">{{ __('data_status.actions.bind', [], 'tr') }}</a>
                @endif
            </p>
        @endforeach

        <x-slot:tabs>
            <nav class="flex flex-wrap items-center gap-x-5 border-b border-gray-200 dark:border-gray-800" aria-label="Sekmeler">
                @foreach ($screen::TABS as $key => $label)
                    <button type="button" wire:click="setTab('{{ $key }}')" data-tab="{{ $key }}" aria-current="{{ $tab === $key ? 'page' : 'false' }}" class="{{ $tabClass($tab === $key) }}">{{ $label }}</button>
                @endforeach
                <div class="relative" x-data="{ more: false }" @click.outside="more = false">
                    <button type="button" @click="more = !more" data-tab-more data-tab-settings :aria-expanded="more.toString()" class="{{ $tabClass($moreOpen) }}">
                        <span class="max-w-[14rem] truncate" title="{{ $moreOpen && $tab !== 'ayarlar' ? 'Ayarlar · '.$menu[$tab] : 'Ayarlar' }}">{{ $moreOpen && $tab !== 'ayarlar' ? 'Ayarlar · '.$menu[$tab] : 'Ayarlar' }}</span>
                        <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div x-show="more" x-cloak class="absolute right-0 z-30 mt-1 w-64 max-w-[calc(100vw-2rem)] rounded-xl border border-gray-200 bg-white p-1 shadow-lg sm:left-0 sm:right-auto dark:border-gray-700 dark:bg-gray-900">
                        @foreach ($menu as $key => $label)
                            @if ($loop->index === count($screen::SETTINGS))
                                <p class="mt-1 border-t border-gray-100 px-3 pb-1 pt-2 text-xs text-gray-500 dark:border-gray-800">Diğer görünümler</p>
                            @endif
                            <button type="button" wire:click="setTab('{{ $key }}')" @click="more = false" data-tab="{{ $key }}" aria-current="{{ $tab === $key ? 'page' : 'false' }}"
                                    @class(['block w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800', 'font-semibold text-gray-900 dark:text-white' => $tab === $key, 'text-gray-700 dark:text-gray-300' => $tab !== $key])>{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
            </nav>
        </x-slot:tabs>
    </x-operator.asset-context>

    @forelse ($views as [$component, $params])
        @livewire($component, ['assetId' => $site->id, ...$params], key($component.'-'.$tab.'-'.$site->id.'-'.$rangeKey))
    @empty
        <p class="py-4 text-gray-500" data-placeholder>Hazırlanıyor</p>
    @endforelse
</div>
