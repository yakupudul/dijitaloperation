@php
    $siteUrl = $site->primary_url ?: ($site->domain ? 'https://'.$site->domain : null);
    $screen = \App\Livewire\Operator\Website\V2\WebsiteScreen::class;
    $tabGroup = $screen::group($tab);
    $tabViews = $screen::VIEWS[$tabGroup] ?? [];
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
            {{-- One row of seven tabs (admin audit 2026-11-07 T2); on a phone it scrolls sideways. A tab with several views shows them as pills below. --}}
            <div class="flex min-w-0 items-end border-b border-gray-200 dark:border-gray-800">
                <nav class="no-scrollbar -mb-px flex min-w-0 items-end overflow-x-auto" aria-label="Sekmeler">
                    @foreach ($screen::TABS as $key => $label)
                        <button type="button" wire:click="setTab('{{ $key }}')" data-tab="{{ $key }}" aria-current="{{ $tab === $key ? 'page' : 'false' }}" @class(['tab mb-0!', 'tab-active' => $tabGroup === $key])>{{ $label }}</button>
                    @endforeach
                </nav>
            </div>
        </x-slot:tabs>
    </x-operator.asset-context>

    @if (count($tabViews) > 1)
        <nav class="-mt-4 flex flex-wrap items-center gap-1.5" aria-label="Görünümler" data-tab-views="{{ $tabGroup }}">
            @foreach ($tabViews as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')" data-tab="{{ $key }}" aria-current="{{ $tab === $key ? 'page' : 'false' }}" @class(['seg h-8! px-3!', 'seg-active' => $tab === $key])>{{ $label }}</button>
            @endforeach
        </nav>
    @endif

    @forelse ($views as [$component, $params])
        @livewire($component, ['assetId' => $site->id, ...$params], key($component.'-'.$tab.'-'.$site->id.'-'.$rangeKey))
    @empty
        <p class="py-4 text-sm text-gray-500" data-placeholder>Hazırlanıyor</p>
    @endforelse
</div>
