@php
    $pill = 'rounded-lg px-3 py-1.5 text-xs font-semibold';
@endphp
<div class="space-y-4 text-sm dark:text-gray-200" data-website-screen>
    <header class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ $site->name }}</h1>
            <p class="text-xs text-gray-500">
                @if ($site->brand)<a href="{{ route('operator.brand', ['brand' => $site->brand->id]) }}" class="hover:underline">{{ $site->brand->name }}</a> · @endif
                {{ $site->domain ?? $site->primary_url }}
            </p>
        </div>
        <nav class="flex flex-wrap gap-1" aria-label="Sekmeler">
            @foreach (\App\Livewire\Operator\Website\V2\WebsiteScreen::TABS as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')" data-tab="{{ $key }}" @class([$pill, 'bg-brand-500 text-white' => $tab === $key, 'text-gray-600 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700' => $tab !== $key])>{{ $label }}</button>
            @endforeach
        </nav>
    </header>

    @if ($tab === 'seo')
        <nav class="flex flex-wrap gap-1" aria-label="SEO Yapılacaklar">
            @foreach (\App\Livewire\Operator\Website\V2\WebsiteScreen::SEO_TABS as $key => $label)
                <button type="button" wire:click="setSub('{{ $key }}')" data-sub="{{ $key }}" @class([$pill, 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $sub === $key, 'text-gray-600 ring-1 ring-inset ring-gray-200 dark:text-gray-300 dark:ring-gray-700' => $sub !== $key])>{{ $label }}</button>
            @endforeach
        </nav>
    @endif

    @if ($component !== null)
        @livewire($component, ['assetId' => $site->id], key($component.'-'.$site->id))
    @else
        <p class="rounded-xl bg-white p-4 text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-placeholder>Hazırlanıyor</p>
    @endif
</div>
