@php
    $siteUrl = $site->primary_url ?: ($site->domain ? 'https://'.$site->domain : null);
    $screen = \App\Livewire\Operator\Website\V2\WebsiteScreen::class;
    $menu = $screen::SETTINGS + $screen::MORE;
    $moreOpen = array_key_exists($tab, $menu);
    // The menu button names the view it holds open ("Rakipler"), otherwise "Ayarlar".
    $moreLabel = $moreOpen && $tab !== 'ayarlar' ? $menu[$tab] : 'Ayarlar';
@endphp
<div class="min-w-0 space-y-8" data-website-screen>
    <x-operator.asset-context :asset-id="$site->id" :title="$pageTitle">
        <x-slot:meta>
            @if ($siteUrl)
                <a href="{{ $siteUrl }}" target="_blank" rel="noopener noreferrer" class="hover:text-gray-900 hover:underline dark:hover:text-white">{{ $site->domain ?: $siteUrl }} ↗</a>
            @endif
        </x-slot:meta>

        @if ($ranged)
            <x-slot:actions>
                <x-operator.date-range-picker :range="$range" :last-day="$lastDay" note="Search Console verisi 2–3 gün gecikmeli." class="w-full sm:w-auto" />
            </x-slot:actions>
        @endif

        @foreach ($banners as $bannerState => $group)
            <p class="min-w-0 basis-full text-warning-800 [overflow-wrap:anywhere] dark:text-warning-300" data-data-status-banner="{{ $bannerState }}">
                <span class="mr-1.5 inline-block h-1.5 w-1.5 rounded-full bg-warning-500 align-middle" aria-hidden="true"></span>{{ __('data_status.banner.'.$bannerState.'_title', ['sources' => $group->map(fn ($status) => $status->sourceLabel())->implode(' / ')], 'tr') }}
                @if ($bannerState === 'not_bound')
                    <a href="{{ route('operator.asset.sources', ['assetId' => $site->id]) }}" wire:navigate class="link-primary ml-1">{{ __('data_status.actions.bind', [], 'tr') }}</a>
                @endif
            </p>
        @endforeach

        <x-slot:tabs>
            {{-- One row of tabs; on a phone it scrolls sideways. The settings menu sits outside the scroller so its list is not clipped. --}}
            <div class="flex min-w-0 items-end border-b border-gray-200 dark:border-gray-800">
                <nav class="no-scrollbar -mb-px flex min-w-0 items-end overflow-x-auto" aria-label="Sekmeler">
                    @foreach ($screen::TABS as $key => $label)
                        <button type="button" wire:click="setTab('{{ $key }}')" data-tab="{{ $key }}" aria-current="{{ $tab === $key ? 'page' : 'false' }}" @class(['tab mb-0!', 'tab-active' => $tab === $key])>{{ $label }}</button>
                    @endforeach
                </nav>
                <div class="relative -mb-px shrink-0" x-data="{ more: false }" @click.outside="more = false">
                    <button type="button" @click="more = !more" data-tab-more data-tab-settings :aria-expanded="more.toString()" @class(['tab mb-0!', 'tab-active' => $moreOpen])>
                        <span class="max-w-[9rem] truncate sm:max-w-[14rem]" title="{{ $moreLabel }}">{{ $moreLabel }}</span>
                        <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div x-show="more" x-cloak class="absolute right-0 z-30 mt-1 w-64 max-w-[calc(100vw-2rem)] rounded-xl border border-gray-200 bg-white p-1 shadow-lg dark:border-gray-700 dark:bg-gray-900">
                        @foreach ($menu as $key => $label)
                            @if ($loop->index === count($screen::SETTINGS))
                                <p class="mt-1 border-t border-gray-100 px-3 pb-1 pt-2 text-xs text-gray-500 dark:border-gray-800">Diğer görünümler</p>
                            @endif
                            <button type="button" wire:click="setTab('{{ $key }}')" @click="more = false" data-tab="{{ $key }}" aria-current="{{ $tab === $key ? 'page' : 'false' }}"
                                    @class(['block w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/[0.04]', 'bg-gray-50 font-medium text-gray-900 dark:bg-white/[0.06] dark:text-white' => $tab === $key, 'text-gray-700 dark:text-gray-300' => $tab !== $key])>{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-slot:tabs>
    </x-operator.asset-context>

    @forelse ($views as [$component, $params])
        @livewire($component, ['assetId' => $site->id, ...$params], key($component.'-'.$tab.'-'.$site->id.'-'.$rangeKey))
    @empty
        <p class="py-4 text-sm text-gray-500" data-placeholder>Hazırlanıyor</p>
    @endforelse
</div>
