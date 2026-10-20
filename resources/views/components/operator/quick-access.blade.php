{{--
    "Hızlı giriş": one button that opens the brand's or asset's own accounts (site, WordPress signed in, Google Ads, Meta,
    Business Profile, Search Console, GA4) from App\Services\Operator\ExternalLinks. `groups` = forBrand() output,
    or pass `links` (forAsset()) for one flat list. Renders nothing when there is no link.
--}}
@props(['groups' => null, 'links' => null, 'size' => 'button'])
@php
    $groups = $groups ?? (filled($links) ? [['group' => null, 'links' => $links]] : []);
@endphp
@if ($groups !== [])
    <details class="relative" data-quick-access x-data @click.outside="$el.removeAttribute('open')">
        <summary @class([
            'list-none cursor-pointer',
            'btn' => $size === 'button',
            'rounded-md px-2 py-1 text-xs text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/[0.06] dark:hover:text-white inline-flex items-center gap-1' => $size !== 'button',
        ]) title="Bu varlıkların kendi hesaplarına yeni sekmede geç">
            Hızlı giriş <span aria-hidden="true" class="text-gray-400">↗</span>
        </summary>
        <div class="absolute right-0 z-40 mt-1 max-h-[70vh] w-72 max-w-[calc(100vw-2rem)] overflow-y-auto rounded-xl border border-gray-200 bg-white p-1.5 shadow-lg dark:border-gray-700 dark:bg-gray-900">
            @foreach ($groups as $group)
                @if ($group['group'])
                    <p class="px-2.5 pb-0.5 pt-2 text-xs text-gray-500 first:pt-1">{{ $group['group'] }}</p>
                @endif
                @foreach ($group['links'] as $link)
                    <x-operator.open-link :link="$link" variant="menu" />
                @endforeach
            @endforeach
        </div>
    </details>
@endif
