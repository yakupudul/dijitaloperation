@php
    $siteUrl = $site->primary_url ?: ($site->domain ? 'https://'.$site->domain : null);
    $presets = \App\Services\Site\Analysis\SiteRange::PRESETS;
    $rangeLabel = \App\Services\Site\Analysis\SiteRange::format($window['start'], $window['end']);
    $compareLabel = \App\Services\Site\Analysis\SiteRange::format($window['prev_start'], $window['prev_end']);
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
                <div class="relative" data-date-picker
                     x-data="sitePicker({ days: {{ $range->custom() ? 0 : $range->days }}, start: '{{ $window['start'] }}', end: '{{ $window['end'] }}', compare: '{{ $range->compare }}', last: '{{ $lastDay }}' })"
                     @keydown.escape.window="open = false" @click.outside="open = false">
                    <button type="button" @click="open = !open" :aria-expanded="open.toString()" aria-haspopup="dialog"
                            class="flex h-11 items-center gap-3 rounded-lg border border-gray-300 bg-white px-3 text-left hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-800">
                        <svg class="h-4 w-4 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
                        <span class="flex flex-col leading-tight">
                            <span class="text-[11px] text-gray-500">{{ $range->label() }}</span>
                            <span class="text-sm font-semibold text-gray-900 dark:text-white" data-range-label>{{ $rangeLabel }}</span>
                        </span>
                        <span class="hidden border-l border-gray-200 pl-3 text-xs text-gray-500 sm:inline dark:border-gray-700">karşılaştırma: {{ $compareLabel }}</span>
                        <svg class="h-3.5 w-3.5 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </button>

                    <div x-show="open" x-cloak x-transition.opacity role="dialog" aria-label="Tarih aralığı"
                         class="absolute right-0 z-40 mt-2 grid w-[min(760px,92vw)] grid-cols-1 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xl sm:grid-cols-[190px_minmax(0,1fr)] dark:border-gray-700 dark:bg-gray-900">
                        <div class="flex flex-row flex-wrap gap-1 border-b border-gray-200 p-2 sm:flex-col sm:border-b-0 sm:border-r dark:border-gray-700">
                            @foreach ($presets as $presetDays => $presetLabel)
                                <button type="button" @click="preset({{ $presetDays }})" :class="days === {{ $presetDays }} ? 'bg-blue-50 font-semibold text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' : 'text-gray-700 dark:text-gray-300'"
                                        class="h-9 rounded-lg px-3 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800">{{ $presetLabel }}</button>
                            @endforeach
                            <button type="button" @click="lastMonth()" :class="days === -1 ? 'bg-blue-50 font-semibold text-blue-700' : 'text-gray-700 dark:text-gray-300'" class="h-9 rounded-lg px-3 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800">Geçen ay</button>
                            <button type="button" @click="custom()" :class="days === 0 ? 'bg-blue-50 font-semibold text-blue-700' : 'text-gray-700 dark:text-gray-300'" class="h-9 rounded-lg px-3 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800">Özel aralık</button>
                        </div>
                        <div class="space-y-3 p-4">
                            <div class="flex items-end gap-2">
                                <label class="flex-1 text-[11px] text-gray-500">Başlangıç
                                    <input type="date" x-model="start" @change="days = 0" :max="end" class="mt-1 block h-9 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                                </label>
                                <span class="pb-2 text-gray-400">–</span>
                                <label class="flex-1 text-[11px] text-gray-500">Bitiş
                                    <input type="date" x-model="end" @change="days = 0" :min="start" :max="last" class="mt-1 block h-9 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                                </label>
                            </div>
                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <template x-for="month in months()" :key="month.key">
                                    <div>
                                        <p class="mb-2 text-center text-xs font-semibold text-gray-800 dark:text-gray-100" x-text="month.title"></p>
                                        <div class="grid grid-cols-7 text-center text-[11px] text-gray-500">
                                            <template x-for="w in ['Pt','Sa','Ça','Pe','Cu','Ct','Pz']" :key="w"><span class="pb-1" x-text="w"></span></template>
                                            <template x-for="cell in month.cells" :key="cell.key">
                                                <button type="button" :disabled="!cell.day || cell.future" @click="pick(cell.iso)" x-text="cell.day || ''"
                                                        :class="cellClass(cell)" class="h-8 text-xs"></button>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                            <div class="flex flex-wrap items-center gap-3 border-t border-gray-200 pt-3 text-xs dark:border-gray-700">
                                <span class="font-medium text-gray-700 dark:text-gray-300">Karşılaştır:</span>
                                <label class="flex items-center gap-1.5"><input type="radio" value="prev" x-model="compare"> Önceki dönem</label>
                                <label class="flex items-center gap-1.5"><input type="radio" value="year" x-model="compare"> Geçen yıl aynı dönem</label>
                            </div>
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="text-[11px] text-gray-500">Search Console verisi 2–3 gün gecikmeli; son veri {{ $lastDay !== '' ? \Carbon\CarbonImmutable::parse($lastDay)->format('d.m.Y') : '—' }}.</span>
                                <span class="flex gap-2">
                                    <button type="button" @click="open = false" class="h-9 rounded-lg border border-gray-300 px-3 text-sm dark:border-gray-700">İptal</button>
                                    <button type="button" @click="apply()" class="h-9 rounded-lg bg-brand-500 px-3 text-sm font-semibold text-white hover:bg-brand-600" data-date-apply>Uygula</button>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
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
