@php
    $field = 'h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white';
    $row = 'card-row flex-wrap items-start sm:flex-nowrap';
    $rowLabel = 'w-full min-w-0 sm:w-auto sm:flex-1 sm:pt-2';
    $collapse = 'card flex cursor-pointer list-none items-center gap-3.5 px-4 py-3.5 hover:bg-gray-50 dark:hover:bg-white/[0.03] [&::-webkit-details-marker]:hidden';
@endphp
<section class="space-y-10" data-wa-settings>
    <form wire:submit="saveAiSettings" data-wa-ai-settings>
        <div class="section-head"><h2 class="section-title">Yanıt önerileri</h2></div>
        <div class="card">
            {{-- Model seçimi kalktı (Aşama 11, 2026-10-22): yanıt önerileri Claude ile hazırlanır. --}}
            <label class="{{ $row }} cursor-pointer">
                <span class="{{ $rowLabel }} sm:pt-0!">
                    <span class="block text-sm font-medium text-gray-900 dark:text-white">Yeni mesaj gelince öneriyi kendiliğinden hazırla</span>
                    <span class="mt-0.5 block text-[13px] text-gray-500 dark:text-gray-400">Kapalıyken görüşmedeki "Öneri hazırla" düğmesiyle istersiniz.</span>
                </span>
                <span class="flex w-full items-center sm:w-80 sm:shrink-0"><input type="checkbox" wire:model="automatic_suggestions" class="size-4 rounded border-gray-300" /></span>
            </label>
            <label class="{{ $row }}">
                <span class="{{ $rowLabel }}">
                    <span class="block text-sm font-medium text-gray-900 dark:text-white">Mesaj metinlerini sakla (gün, KVKK)</span>
                    <span class="mt-0.5 block text-[13px] text-gray-500 dark:text-gray-400">Boş bırakılırsa süresiz saklanır.</span>
                </span>
                <span class="w-full min-w-0 sm:w-80 sm:shrink-0">
                    <input type="number" min="30" max="3650" wire:model="retention_days" placeholder="Boş: süresiz" class="{{ $field }} sm:w-40" />
                    @error('retention_days')<span class="mt-1 block text-xs text-error-600">{{ $message }}</span>@enderror
                </span>
            </label>
            <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-2 border-t border-gray-200 bg-gray-50/60 px-4 py-3 dark:border-gray-800 dark:bg-white/[0.02]">
                <p class="mr-auto text-xs text-gray-500">Fiyatlar, hizmetler ve üslup için talimatlar "Beyin" sekmesinde.</p>
                <button type="submit" wire:loading.attr="disabled" class="btn-primary">Kaydet</button>
            </div>
        </div>
    </form>

    @include('livewire.operator.whatsapp.connection-status')

    {{-- Opened sections stay open across refreshes: Livewire leaves these <details> attributes to the browser. --}}
    <div class="space-y-3">
        @if($metaReady || $state === 'connected')
            <details class="group/metaapp" @if(! $metaReady) open @endif wire:ignore.self data-wa-meta-app>
                <summary class="{{ $collapse }}">
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium text-gray-900 dark:text-white">Meta uygulama bilgileri</span>
                        <span class="mt-0.5 block text-[13px] text-gray-500 dark:text-gray-400">App ID, yapılandırma, App Secret ve Verify Token.</span>
                    </span>
                    <span class="btn-quiet"><span class="group-open/metaapp:hidden">Aç</span><span class="hidden group-open/metaapp:inline">Kapat</span></span>
                </summary>
                <div class="card mt-3 p-5">@include('livewire.operator.whatsapp.signup-settings')</div>
            </details>
        @endif

        <details class="group/manual" @if($manualOpen) open @endif wire:ignore.self x-data x-on:wa-open-manual.window="$el.open = true; $el.scrollIntoView({ block: 'start', behavior: 'smooth' })" data-wa-manual>
            <summary class="{{ $collapse }}">
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-medium text-gray-900 dark:text-white">Elle bağla</span>
                    <span class="mt-0.5 block text-[13px] text-gray-500 dark:text-gray-400">Kendi hesabınız veya hazır Cloud API bilgileri.</span>
                </span>
                <span class="btn-quiet"><span class="group-open/manual:hidden">Aç</span><span class="hidden group-open/manual:inline">Kapat</span></span>
            </summary>
            <div class="card mt-3 p-5">@include('livewire.operator.whatsapp.manual-settings')</div>
        </details>

        <details class="group/receipts" wire:ignore.self>
            <summary class="{{ $collapse }}">
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-medium text-gray-900 dark:text-white">Son mesaj aktarımları</span>
                    <span class="mt-0.5 block text-[13px] text-gray-500 dark:text-gray-400">Meta'dan gelen doğrulanmış mesaj bildirimleri.</span>
                </span>
                <span class="btn-quiet"><span class="group-open/receipts:hidden">Aç</span><span class="hidden group-open/receipts:inline">Kapat</span></span>
            </summary>
            <ul class="card mt-3 text-sm">
                @forelse($receipts as $receipt)
                    <li class="card-row flex-wrap justify-between gap-y-1">
                        <span class="min-w-0 text-gray-700 dark:text-gray-300"><span class="tabular-nums text-gray-500">{{ $when($receipt->created_at) }}</span> <span class="ml-2">{{ ['completed' => 'İşlendi', 'pending' => 'Sırada', 'failed' => 'Hata'][$receipt->status] ?? $receipt->status }}, {{ $receipt->accepted_count }} yeni mesaj{{ $receipt->ignored_count ? ', '.$receipt->ignored_count.' başka kapsamdaki öğe' : '' }}</span></span>
                        @if($receipt->status === 'failed')<button type="button" wire:click="retryReceipt({{ $receipt->id }})" class="btn h-7! px-2.5! text-xs!">Tekrar işle</button>@endif
                    </li>
                @empty
                    <li class="card-row text-gray-500">Meta'dan henüz doğrulanmış mesaj bildirimi gelmedi.</li>
                @endforelse
            </ul>
        </details>
    </div>

    @if($integration)
        <div class="card" data-wa-reset>
            <div class="{{ $row }}">
                <div class="min-w-0 flex-1">
                    <h2 class="text-sm font-medium text-gray-900 dark:text-white">Bağlantıyı sıfırla</h2>
                    <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">Meta uygulama bilgileri, numara, erişim anahtarı, App Secret, Verify Token ve bağlantı denemeleri silinir{{ $conversationCount ? '; '.$conversationCount.' görüşme ve mesajları da silinir' : '' }}. Kurulum 1. adımdan başlar. Yanıt önerisi ayarları kalır; Meta tarafında hiçbir şey değişmez.</p>
                </div>
                <button type="button" wire:click="resetConnection" wire:confirm="WhatsApp bağlantısı sıfırlansın mı? {{ $conversationCount ? $conversationCount.' görüşme ve mesajları da silinir. ' : '' }}Bu geri alınamaz." wire:loading.attr="disabled" wire:target="resetConnection" class="btn w-full text-error-700! sm:w-auto dark:text-error-300!">Bağlantıyı sıfırla</button>
            </div>
        </div>
    @endif
</section>
