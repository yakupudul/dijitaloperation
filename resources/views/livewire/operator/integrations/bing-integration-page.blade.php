{{-- Entegrasyonlar › Bing Webmaster: the agency key, the matched sites and their last reading. Read only. --}}
@php
    $card = 'min-w-0';
    $num = fn (int $n): string => number_format($n, 0, ',', '.');
@endphp
<div class="space-y-10 text-sm dark:text-gray-200" data-bing-page>
    @include('livewire.demo.partials.flash')

    <header class="space-y-1">
        <a href="{{ route('operator.integrations') }}" wire:navigate class="text-xs text-gray-500 hover:text-gray-900 dark:hover:text-white">Entegrasyonlar</a>
        <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white">Bing Webmaster</h1>
        <p class="text-sm text-gray-500">Yalnız okunur; Bing'e hiçbir şey gönderilmez.</p>
    </header>

    <section class="{{ $card }}" data-bing-key>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="section-title">API anahtarı</h2>
                <p class="mt-1 text-xs text-gray-500">Bing Webmaster Tools › Ayarlar › API erişimi › API anahtarı. Ajansın tüm doğrulanmış sitelerini görür.</p>
            </div>
            <x-ta.pill :tone="$configured ? 'ok' : 'info'">{{ $configured ? 'Bağlı' : 'Bağlı değil' }}</x-ta.pill>
        </div>
        @if ($isAdmin)
            <form wire:submit="save" class="mt-3 flex flex-wrap items-start gap-2">
                <div class="min-w-0 flex-1">
                    <input type="password" wire:model="apiKey" autocomplete="off" placeholder="{{ $configured ? 'Yeni anahtar (değiştirmek için)' : 'API anahtarı' }}" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white" />
                    @error('apiKey')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                </div>
                <button type="submit" wire:loading.attr="disabled" class="h-9 rounded-lg bg-brand-500 px-3 text-sm font-semibold text-white hover:bg-brand-600">Kaydet ve eşleştir</button>
                @if ($configured)
                    <button type="button" wire:click="collectNow" wire:loading.attr="disabled" class="h-9 rounded-lg px-3 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Şimdi çek</button>
                    <button type="button" wire:click="forget" wire:confirm="Bing anahtarı silinsin mi? Okunmuş veriler kalır." class="h-9 px-2 text-xs text-gray-500 hover:text-error-600">Anahtarı sil</button>
                @endif
            </form>
        @endif
    </section>

    <section class="{{ $card }}" data-bing-sites>
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="section-title">Eşleşen siteler</h2>
            <span class="text-xs text-gray-500">son 4 hafta, her sabah okunur @if ($unmatched > 0)<span title="Bing'de yok ya da doğrulanmamış">· {{ $unmatched }} site eşleşmedi</span>@endif</span>
        </div>
        <ul class="mt-3 divide-y divide-gray-200 dark:divide-gray-800">
            @forelse ($sites as $site)
                <li class="flex flex-wrap items-center gap-x-4 gap-y-1 py-2.5" data-bing-site="{{ $site['site_url'] }}">
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium text-gray-900 dark:text-white">{{ $site['asset']?->brand?->name ?? '—' }} <span class="font-normal text-gray-500">{{ $site['site_url'] }}</span></p>
                        <p class="text-xs text-gray-500">
                            @if (! $site['verified']) Bing'de doğrulanmamış, @endif
                            @if ($site['error'])<span class="text-error-600">{{ $site['error'] }}</span>@elseif ($site['collected_at']) Okundu {{ \Illuminate\Support\Carbon::parse($site['collected_at'])->diffForHumans() }} @else Henüz okunmadı @endif
                        </p>
                    </div>
                    @if ($site['summary'])
                        <span class="text-xs tabular-nums text-gray-600 dark:text-gray-300">{{ $num($site['summary']['impressions']) }} gösterim, {{ $num($site['summary']['clicks']) }} tıklama</span>
                    @endif
                    @if ($site['asset'])
                        <a href="{{ route('operator.website', ['assetId' => $site['asset']->id]) }}" wire:navigate class="text-xs link-neutral">Siteyi aç</a>
                    @endif
                </li>
            @empty
                <li class="py-6 text-center text-gray-500">{{ $configured ? 'Bing\'deki sitelerden hiçbiri MoxDOP sitesiyle eşleşmedi.' : 'Anahtar girilince Bing\'deki siteler burada eşleşir.' }}</li>
            @endforelse
        </ul>
    </section>
</div>
