{{--
    "Kısayollar" (arayüz kararı 2026-11-17): one menu with everything the operator may open or copy for a brand
    (App\Services\Operator\BrandShortcuts::for()). link = provider screen in a new tab (WordPress signed in),
    page = a MoxDOP screen, copy = clipboard, missing = greyed "bağlı değil". Renders nothing without groups.
--}}
@props(['groups' => [], 'size' => 'button', 'label' => 'Kısayollar'])
@php
    $row = 'flex w-full min-w-0 items-center gap-2 rounded-lg px-2.5 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-white/[0.04] dark:hover:text-white';
@endphp
@if ($groups !== [])
    <details class="relative" data-shortcuts x-data @click.outside="$el.removeAttribute('open')" @keydown.escape="$el.removeAttribute('open')">
        <summary @class([
            'list-none cursor-pointer',
            'btn' => $size === 'button',
            'inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/[0.06] dark:hover:text-white' => $size !== 'button',
        ]) title="Bu markanın sitesi, hesapları ve ekranları">
            {{ $label }}
            <svg class="h-3.5 w-3.5 text-gray-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="m5.5 7.5 4.5 4.5 4.5-4.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </summary>
        <div class="absolute right-0 z-40 mt-1 max-h-[72vh] w-80 max-w-[calc(100vw-2rem)] overflow-y-auto rounded-xl border border-gray-200 bg-white p-1.5 shadow-lg max-sm:left-0 max-sm:right-auto dark:border-gray-700 dark:bg-gray-900">
            @foreach ($groups as $group)
                <p class="px-2.5 pb-0.5 pt-2.5 text-xs text-gray-500 first:pt-1">{{ $group['group'] }}</p>
                @foreach ($group['items'] as $item)
                    @if ($item['kind'] === 'link' && $item['link'] !== null)
                        <x-operator.open-link :link="$item['link']" variant="menu" />
                    @elseif ($item['kind'] === 'link')
                        <a href="{{ $item['url'] }}" target="_blank" rel="noopener noreferrer" title="{{ $item['hint'] }}" class="{{ $row }}" data-shortcut="link"><span class="min-w-0 truncate">{{ $item['label'] }}</span><span aria-hidden="true" class="shrink-0 text-gray-400">↗</span></a>
                    @elseif ($item['kind'] === 'page')
                        <a href="{{ $item['url'] }}" wire:navigate title="{{ $item['hint'] }}" class="{{ $row }}" data-shortcut="page"><span class="min-w-0 truncate">{{ $item['label'] }}</span></a>
                    @elseif ($item['kind'] === 'copy')
                        <button type="button" x-data="{ done: false }" x-on:click="navigator.clipboard.writeText(@js($item['value'])).then(() => { done = true; setTimeout(() => done = false, 1600) })" title="{{ $item['hint'] }}" class="{{ $row }}" data-shortcut="copy">
                            <span class="min-w-0 truncate" x-text="done ? 'Kopyalandı' : @js($item['label'])">{{ $item['label'] }}</span>
                        </button>
                    @else
                        <span class="flex w-full min-w-0 items-center gap-2 rounded-lg px-2.5 py-2 text-sm text-gray-400 dark:text-gray-600" title="{{ $item['hint'] }}" data-shortcut="missing"><span class="min-w-0 truncate">{{ $item['label'] }}</span></span>
                    @endif
                @endforeach
            @endforeach
        </div>
    </details>
@endif
