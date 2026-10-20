@php($field = 'mt-1 w-full rounded-lg border border-gray-300 bg-transparent p-2 text-sm dark:border-gray-700')
<section class="space-y-10" data-wa-settings>
    <div class="grid gap-10 xl:grid-cols-2">
        <form wire:submit="saveAiSettings" class="space-y-4" data-wa-ai-settings>
            <div>
                <h2 class="section-title">Yanıt önerileri</h2>
                <p class="mt-1 text-xs text-gray-500">Öneriler OpenAI ile birkaç saniyede hazırlanır; günlük AI bütçesi geçerlidir.</p>
            </div>
            <label class="block text-sm">Model (OpenAI)
                <select wire:model="ai_model" class="{{ $field }}">
                    @foreach($modelOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
                @error('ai_model')<span class="mt-1 block text-xs text-error-600">{{ $message }}</span>@enderror
            </label>
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="automatic_suggestions" class="mt-0.5" />
                <span>Yeni mesaj gelince öneriyi kendiliğinden hazırla<span class="block text-xs text-gray-500">Kapalıyken görüşmedeki "Öneri hazırla" düğmesiyle istersiniz.</span></span>
            </label>
            <p class="text-xs text-gray-500">Fiyatlar, hizmetler ve üslup için talimatlar ana ekrandaki "Beyin" bölümünde.</p>
            <label class="block text-sm">Mesaj metinlerini sakla (gün, KVKK)
                <input type="number" min="30" max="3650" wire:model="retention_days" placeholder="Boş: süresiz" class="{{ $field }} max-w-40" />
                @error('retention_days')<span class="mt-1 block text-xs text-error-600">{{ $message }}</span>@enderror
            </label>
            <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-60">Kaydet</button>
        </form>

        @include('livewire.operator.whatsapp.connection-status')
    </div>

    {{-- Opened sections stay open across refreshes: Livewire leaves these <details> attributes to the browser. --}}
    @if($metaReady || $state === 'connected')
        <details class="border-t border-gray-200 pt-6 dark:border-gray-800" @if(! $metaReady) open @endif wire:ignore.self data-wa-meta-app>
            <summary class="section-title cursor-pointer">Meta uygulama bilgileri</summary>
            <div class="mt-4">@include('livewire.operator.whatsapp.signup-settings')</div>
        </details>
    @endif

    <details class="border-t border-gray-200 pt-6 dark:border-gray-800" @if($manualOpen) open @endif wire:ignore.self x-data x-on:wa-open-manual.window="$el.open = true; $el.scrollIntoView({ block: 'start', behavior: 'smooth' })" data-wa-manual>
        <summary class="section-title cursor-pointer">Elle bağla <span class="text-sm font-normal text-gray-500">kendi hesabınız veya hazır Cloud API bilgileri</span></summary>
        <div class="mt-4">@include('livewire.operator.whatsapp.manual-settings')</div>
    </details>

    <details class="border-t border-gray-200 pt-6 dark:border-gray-800" wire:ignore.self>
        <summary class="section-title cursor-pointer">Son mesaj aktarımları</summary>
        <div class="mt-3 text-sm">
            @forelse($receipts as $receipt)
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 py-2.5 last:border-0 dark:border-gray-800">
                    <span>{{ $when($receipt->created_at) }} · {{ ['completed' => 'İşlendi', 'pending' => 'Sırada', 'failed' => 'Hata'][$receipt->status] ?? $receipt->status }}, {{ $receipt->accepted_count }} yeni mesaj{{ $receipt->ignored_count ? ', '.$receipt->ignored_count.' başka kapsamdaki öğe' : '' }}</span>
                    @if($receipt->status === 'failed')<button type="button" wire:click="retryReceipt({{ $receipt->id }})" class="link-neutral">Tekrar işle</button>@endif
                </div>
            @empty
                <p class="text-gray-500">Meta'dan henüz doğrulanmış mesaj bildirimi gelmedi.</p>
            @endforelse
        </div>
    </details>

    @if($integration)
        <div class="flex flex-wrap items-center justify-between gap-4 border-t border-gray-200 pt-6 dark:border-gray-800" data-wa-reset>
            <div class="min-w-0 flex-1">
                <h2 class="section-title">Bağlantıyı sıfırla</h2>
                <p class="mt-1 text-sm text-gray-500">Meta uygulama bilgileri, numara, erişim anahtarı, App Secret, Verify Token ve bağlantı denemeleri silinir{{ $conversationCount ? '; '.$conversationCount.' görüşme ve mesajları da silinir' : '' }}. Kurulum 1. adımdan başlar. Yanıt önerisi ayarları kalır; Meta tarafında hiçbir şey değişmez.</p>
            </div>
            <button type="button" wire:click="resetConnection" wire:confirm="WhatsApp bağlantısı sıfırlansın mı? {{ $conversationCount ? $conversationCount.' görüşme ve mesajları da silinir. ' : '' }}Bu geri alınamaz." wire:loading.attr="disabled" wire:target="resetConnection" class="shrink-0 rounded-lg px-4 py-2 text-sm font-medium text-error-700 ring-1 ring-inset ring-error-300 hover:bg-error-50 disabled:opacity-60 dark:text-error-300 dark:ring-error-500/40 dark:hover:bg-error-500/10">Bağlantıyı sıfırla</button>
        </div>
    @endif
</section>
