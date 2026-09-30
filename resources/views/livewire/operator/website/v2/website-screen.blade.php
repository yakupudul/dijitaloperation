@php
    $pill = 'rounded-lg px-3 py-1.5 text-xs font-semibold';
    $subs = \App\Livewire\Operator\Website\V2\WebsiteScreen::SUBS[$tab] ?? [];
    $siteUrl = $site->primary_url ?: ($site->domain ? 'https://'.$site->domain : null);
@endphp
<div class="space-y-4 text-sm dark:text-gray-200" data-website-screen>
    <header class="space-y-3">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <a href="{{ route('operator.websites') }}" wire:navigate class="text-xs text-gray-500 hover:text-brand-600">← Web siteleri</a>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ $site->name }}</h1>
                <p class="text-xs text-gray-500">
                    @if ($site->brand)<a href="{{ route('operator.brand', ['brand' => $site->brand->id]) }}" wire:navigate class="hover:underline">{{ $site->brand->name }}</a> · @endif
                    @if ($siteUrl)<a href="{{ $siteUrl }}" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ $site->domain ?? $site->primary_url }} ↗</a>@endif
                </p>
            </div>
        </div>
        <nav class="flex flex-wrap gap-1 border-b border-gray-200 dark:border-gray-800" aria-label="Sekmeler">
            @foreach (\App\Livewire\Operator\Website\V2\WebsiteScreen::TABS as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')" data-tab="{{ $key }}" aria-current="{{ $tab === $key ? 'page' : 'false' }}" @class(['-mb-px border-b-2 px-3 py-2 text-sm font-medium', 'border-brand-500 text-brand-600 dark:text-brand-400' => $tab === $key, 'border-transparent text-gray-600 hover:text-gray-900 dark:text-gray-400' => $tab !== $key])>{{ $label }}</button>
            @endforeach
        </nav>
    </header>

    <x-operator.asset-context :asset-id="$site->id" />

    @if ($subs !== [])
        <nav class="flex flex-wrap gap-1" aria-label="{{ \App\Livewire\Operator\Website\V2\WebsiteScreen::TABS[$tab] }}">
            @foreach ($subs as $key => $label)
                <button type="button" wire:click="setSub('{{ $key }}')" data-sub="{{ $key }}" @class([$pill, 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $sub === $key, 'text-gray-600 ring-1 ring-inset ring-gray-200 dark:text-gray-300 dark:ring-gray-700' => $sub !== $key])>{{ $label }}</button>
            @endforeach
        </nav>
    @endif

    @forelse ($views as [$component, $params])
        @livewire($component, ['assetId' => $site->id, ...$params], key($component.'-'.$tab.'-'.$sub.'-'.$site->id))
    @empty
        <p class="rounded-xl bg-white p-4 text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-placeholder>Hazırlanıyor</p>
    @endforelse
</div>
