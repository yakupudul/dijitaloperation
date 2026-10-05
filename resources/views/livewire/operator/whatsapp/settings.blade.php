@php($field = 'mt-1 w-full rounded-lg border border-gray-300 bg-transparent p-2 text-sm dark:border-gray-700')
<section class="space-y-5" data-wa-settings>
    <div class="grid gap-5 xl:grid-cols-2">
        <form wire:submit="saveAiSettings" class="{{ $card }} space-y-4 p-5" data-wa-ai-settings>
            <div>
                <h2 class="font-semibold text-gray-900 dark:text-white">Yanıt önerileri</h2>
                <p class="mt-1 text-xs text-gray-500">Öneriler OpenAI ile birkaç saniyede hazırlanır; günlük AI bütçesi geçerlidir.</p>
            </div>
            <label class="block text-sm">Model (OpenAI)
                <select wire:model="ai_model" class="{{ $field }}">
                    @foreach($modelOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
                @error('ai_model')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
            </label>
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="automatic_suggestions" class="mt-0.5" />
                <span>Yeni mesaj gelince öneriyi kendiliğinden hazırla<span class="block text-xs text-gray-500">Kapalıyken görüşmedeki "Öneri hazırla" düğmesiyle istersiniz.</span></span>
            </label>
            <p class="text-xs text-gray-500">Fiyatlar, hizmetler ve üslup için talimatlar ana ekrandaki "Beyin" bölümünde.</p>
            <label class="block text-sm">Mesaj metinlerini sakla (gün, KVKK)
                <input type="number" min="30" max="3650" wire:model="retention_days" placeholder="Boş: süresiz" class="{{ $field }} max-w-40" />
                @error('retention_days')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
            </label>
            <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-60">Kaydet</button>
        </form>

        @include('livewire.operator.whatsapp.connection-status')
    </div>

    {{-- Opened sections stay open across refreshes: Livewire leaves these <details> attributes to the browser. --}}
    @if($metaReady || $state === 'connected')
        <details class="{{ $card }} p-5" @if(! $metaReady) open @endif wire:ignore.self data-wa-meta-app>
            <summary class="cursor-pointer font-semibold text-gray-900 dark:text-white">Meta uygulama bilgileri</summary>
            <div class="mt-4">@include('livewire.operator.whatsapp.signup-settings')</div>
        </details>
    @endif

    <details class="{{ $card }} p-5" @if($manualOpen) open @endif wire:ignore.self x-data x-on:wa-open-manual.window="$el.open = true; $el.scrollIntoView({ block: 'start', behavior: 'smooth' })" data-wa-manual>
        <summary class="cursor-pointer font-semibold text-gray-900 dark:text-white">Elle bağla <span class="font-normal text-gray-500">— kendi WhatsApp hesabınız veya hazır Cloud API bilgileri için</span></summary>
        <div class="mt-4">@include('livewire.operator.whatsapp.manual-settings')</div>
    </details>

    <details class="{{ $card }} p-5" wire:ignore.self>
        <summary class="cursor-pointer font-semibold text-gray-900 dark:text-white">Son mesaj aktarımları</summary>
        <div class="mt-3 text-sm">
            @forelse($receipts as $receipt)
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 py-2 last:border-0 dark:border-gray-800">
                    <span>{{ $when($receipt->created_at) }} · {{ ['completed' => 'İşlendi', 'pending' => 'Sırada', 'failed' => 'Hata'][$receipt->status] ?? $receipt->status }} · {{ $receipt->accepted_count }} yeni mesaj{{ $receipt->ignored_count ? ' · '.$receipt->ignored_count.' başka kapsamdaki öğe' : '' }}</span>
                    @if($receipt->status === 'failed')<button type="button" wire:click="retryReceipt({{ $receipt->id }})" class="text-brand-600 hover:underline">Tekrar işle</button>@endif
                </div>
            @empty
                <p class="text-gray-500">Meta'dan henüz doğrulanmış mesaj bildirimi gelmedi.</p>
            @endforelse
        </div>
    </details>

    @if($integration)
        <div class="{{ $card }} flex flex-wrap items-center justify-between gap-4 p-5" data-wa-reset>
            <div class="min-w-0 flex-1">
                <h2 class="font-semibold text-gray-900 dark:text-white">Bağlantıyı sıfırla</h2>
                <p class="mt-1 text-sm text-gray-500">Meta uygulama bilgileri, numara, erişim anahtarı, App Secret, Verify Token ve bağlantı denemeleri silinir{{ $conversationCount ? '; '.$conversationCount.' görüşme ve mesajları da silinir' : '' }}. Kurulum 1. adımdan başlar. Yanıt önerisi ayarları kalır; Meta tarafında hiçbir şey değişmez.</p>
            </div>
            <button type="button" wire:click="resetConnection" wire:confirm="WhatsApp bağlantısı sıfırlansın mı? {{ $conversationCount ? $conversationCount.' görüşme ve mesajları da silinir. ' : '' }}Bu geri alınamaz." wire:loading.attr="disabled" wire:target="resetConnection" class="shrink-0 rounded-lg px-4 py-2 text-sm font-medium text-rose-700 ring-1 ring-inset ring-rose-300 hover:bg-rose-50 disabled:opacity-60 dark:text-rose-300 dark:ring-rose-500/40 dark:hover:bg-rose-500/10">Bağlantıyı sıfırla</button>
        </div>
    @endif
</section>
