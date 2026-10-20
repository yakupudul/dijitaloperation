{{-- Entegrasyonlar › Bing Webmaster: the agency key, the matched sites and their last reading. Read only.
     Panel concept 2026-10-20: "Hangi sitelerin Bing verisi geliyor?" Without a key the key form comes first. --}}
@php
    $num = fn (int $n): string => number_format($n, 0, ',', '.');
@endphp
<div class="flex flex-col gap-10 text-sm dark:text-gray-200" data-bing-page>
    @include('livewire.demo.partials.flash')

    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex min-w-0 items-start gap-3">
            @include('livewire.demo.integrations.partials.provider-mark', ['provider' => 'bing', 'size' => 'lg'])
            <div class="min-w-0">
                <h1 class="page-title">Bing Webmaster</h1>
                <p class="page-sub flex flex-wrap items-center gap-x-3 gap-y-1">
                    <x-ta.pill :tone="$configured ? 'ok' : 'info'">{{ $configured ? 'Bağlı' : 'Bağlı değil' }}</x-ta.pill>
                    <span>Yalnız okunur; Bing'e hiçbir şey gönderilmez.</span>
                </p>
            </div>
        </div>
        <div class="flex w-full flex-wrap gap-2 sm:w-auto">
            <a href="{{ route('operator.integrations') }}" wire:navigate class="btn-quiet">Entegrasyonlar</a>
            @if ($isAdmin && $configured)
                <button type="button" wire:click="collectNow" wire:loading.attr="disabled" class="btn">Şimdi çek</button>
            @endif
        </div>
    </header>

    <section @class(['min-w-0', 'order-last' => $configured]) data-bing-key>
        <div class="section-head">
            <h2 class="section-title" title="Bing Webmaster Tools, Ayarlar, API erişimi, API anahtarı. Ajansın tüm doğrulanmış sitelerini görür.">API anahtarı</h2>
        </div>
        <div class="card">
            <div class="card-row flex-wrap sm:flex-nowrap">
                <span class="row-icon" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="15" r="4"/><path d="M10.8 12.2 20 3M16 7l3 3M14 9l2 2"/></svg></span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Ajans anahtarı</p>
                    <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $configured ? 'Kayıtlı. Ajansın tüm doğrulanmış sitelerini görür.' : 'Anahtar girilince Bing\'deki siteler MoxDOP siteleriyle eşleşir.' }}</p>
                </div>
            </div>
            @if ($isAdmin)
                <form wire:submit="save" class="card-row flex-wrap items-start sm:flex-nowrap">
                    <div class="w-full min-w-0 flex-1">
                        <input type="password" wire:model="apiKey" autocomplete="off" aria-label="API anahtarı" placeholder="{{ $configured ? 'Yeni anahtar (değiştirmek için)' : 'API anahtarı' }}" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-white" />
                        @error('apiKey')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" wire:loading.attr="disabled" class="{{ $configured ? 'btn' : 'btn-primary' }} w-full sm:w-auto">Kaydet ve eşleştir</button>
                    @if ($configured)
                        <button type="button" wire:click="forget" wire:confirm="Bing anahtarı silinsin mi? Okunmuş veriler kalır." class="btn-quiet w-full hover:text-error-600! sm:w-auto dark:hover:text-error-400!">Anahtarı sil</button>
                    @endif
                </form>
            @endif
        </div>
    </section>

    <section class="min-w-0" data-bing-sites>
        <div class="section-head items-center">
            <h2 class="section-title">Eşleşen siteler <span class="section-count">{{ count($sites) }}</span></h2>
            <span class="ml-auto text-[13px] text-gray-500">Son 4 hafta, her sabah okunur.@if ($unmatched > 0) <span title="Bing'de yok ya da doğrulanmamış">{{ $unmatched }} site eşleşmedi.</span>@endif</span>
        </div>
        <ul class="card">
            @forelse ($sites as $site)
                <li class="card-row flex-wrap sm:flex-nowrap" data-bing-site="{{ $site['site_url'] }}">
                    <x-ta.brand-avatar :name="$site['asset']?->brand?->name ?? $site['site_url']" :logo="$site['asset']?->brand?->logo_url" />
                    <div class="min-w-0 flex-1">
                        <p class="flex min-w-0 items-baseline gap-2.5">
                            <span class="shrink-0 text-sm font-medium text-gray-900 dark:text-white">{{ $site['asset']?->brand?->name ?? '—' }}</span>
                            <span class="min-w-0 truncate text-[13px] text-gray-500" title="{{ $site['site_url'] }}">{{ $site['site_url'] }}</span>
                        </p>
                        <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">
                            @if (! $site['verified'])<span class="text-warning-700 dark:text-warning-400">Bing'de doğrulanmamış.</span> @endif
                            @if ($site['error'])<span class="text-error-600 dark:text-error-400">{{ $site['error'] }}</span>@elseif ($site['collected_at'])Okundu {{ \Illuminate\Support\Carbon::parse($site['collected_at'])->diffForHumans() }}@else Henüz okunmadı @endif
                        </p>
                    </div>
                    @if ($site['summary'])
                        <span class="text-[13px] tabular-nums text-gray-700 dark:text-gray-300">{{ $num($site['summary']['impressions']) }} gösterim, {{ $num($site['summary']['clicks']) }} tıklama</span>
                    @endif
                    @if ($site['asset'])
                        <a href="{{ route('operator.website', ['assetId' => $site['asset']->id]) }}" wire:navigate class="btn-quiet w-full sm:w-auto">Siteyi aç</a>
                    @endif
                </li>
            @empty
                <li><x-ta.empty-state icon="none" size="sm" :title="$configured ? 'Bing\'deki sitelerden hiçbiri MoxDOP sitesiyle eşleşmedi.' : 'Anahtar girilince Bing\'deki siteler burada eşleşir.'" /></li>
            @endforelse
        </ul>
    </section>
</div>
