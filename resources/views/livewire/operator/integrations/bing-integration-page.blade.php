{{-- Entegrasyonlar › Bing Webmaster: the agency key, the matched sites and their last reading. Read only. --}}
@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $num = fn (int $n): string => number_format($n, 0, ',', '.');
@endphp
<div class="space-y-5 text-sm dark:text-gray-200" data-bing-page>
    @include('livewire.demo.partials.flash')

    <header class="space-y-1">
        <a href="{{ route('operator.integrations') }}" wire:navigate class="text-xs text-gray-500 hover:text-brand-600">← Entegrasyonlar</a>
        <h1 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">Bing Webmaster</h1>
        <p class="max-w-3xl text-xs text-gray-500">ChatGPT araması ve Copilot Bing dizinini kullanır. Sitelerin Bing'deki aramaları ve sıraları buradan okunur; Bing'e hiçbir şey gönderilmez.</p>
    </header>

    <section class="{{ $card }}" data-bing-key>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-semibold text-gray-900 dark:text-white">API anahtarı</h2>
                <p class="text-xs text-gray-500">Bing Webmaster Tools › Ayarlar › API erişimi › API anahtarı. Ajansın tüm doğrulanmış sitelerini görür.</p>
            </div>
            <span @class(['rounded-full px-2 py-0.5 text-xs font-medium', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $configured, 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' => ! $configured])>{{ $configured ? 'Bağlı' : 'Bağlı değil' }}</span>
        </div>
        @if ($isAdmin)
            <form wire:submit="save" class="mt-3 flex flex-wrap items-start gap-2">
                <div class="min-w-0 flex-1">
                    <input type="password" wire:model="apiKey" autocomplete="off" placeholder="{{ $configured ? 'Yeni anahtar (değiştirmek için)' : 'API anahtarı' }}" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white" />
                    @error('apiKey')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <button type="submit" wire:loading.attr="disabled" class="h-9 rounded-lg bg-brand-500 px-3 text-sm font-semibold text-white hover:bg-brand-600">Kaydet ve eşleştir</button>
                @if ($configured)
                    <button type="button" wire:click="collectNow" wire:loading.attr="disabled" class="h-9 rounded-lg px-3 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Şimdi çek</button>
                    <button type="button" wire:click="forget" wire:confirm="Bing anahtarı silinsin mi? Okunmuş veriler kalır." class="h-9 px-2 text-xs text-gray-500 hover:text-red-600">Anahtarı sil</button>
                @endif
            </form>
        @endif
    </section>

    <section class="{{ $card }}" data-bing-sites>
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-semibold text-gray-900 dark:text-white">Eşleşen siteler</h2>
            <span class="text-xs text-gray-500">son 4 hafta · her sabah okunur @if ($unmatched > 0) · {{ $unmatched }} MoxDOP sitesi Bing'de yok ya da doğrulanmamış @endif</span>
        </div>
        <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($sites as $site)
                <li class="flex flex-wrap items-center gap-x-4 gap-y-1 py-2" data-bing-site="{{ $site['site_url'] }}">
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium text-gray-900 dark:text-white">{{ $site['asset']?->brand?->name ?? '—' }} · {{ $site['site_url'] }}</p>
                        <p class="text-xs text-gray-500">
                            @if (! $site['verified']) Bing'de doğrulanmamış · @endif
                            @if ($site['error'])<span class="text-red-600">{{ $site['error'] }}</span>@elseif ($site['collected_at']) Okundu {{ \Illuminate\Support\Carbon::parse($site['collected_at'])->diffForHumans() }} @else Henüz okunmadı @endif
                        </p>
                    </div>
                    @if ($site['summary'])
                        <span class="text-xs tabular-nums text-gray-600 dark:text-gray-300">{{ $num($site['summary']['impressions']) }} gösterim · {{ $num($site['summary']['clicks']) }} tıklama</span>
                    @endif
                    @if ($site['asset'])
                        <a href="{{ route('operator.website', ['assetId' => $site['asset']->id]) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">Siteyi aç</a>
                    @endif
                </li>
            @empty
                <li class="py-6 text-center text-gray-500">{{ $configured ? 'Bing\'deki sitelerden hiçbiri MoxDOP sitesiyle eşleşmedi.' : 'Anahtar girilince Bing\'deki siteler burada eşleşir.' }}</li>
            @endforelse
        </ul>
    </section>
</div>
