@php
    $check = $config['connection_check'] ?? '';
    $subscription = $config['subscription_state'] ?? '';
    $coexistence = $config['coexistence_state'] ?? '';
    $rowsStatus = [
        ['Numara', filled($config['phone_number_id'] ?? null) ? [$check === 'verified' ? 'ok' : 'muted', $phoneLabel($config['business_phone'] ?? null)] : ['muted', 'Bağlı değil']],
        ['Bağlantı yolu', match (true) {
            ($config['connected_via'] ?? '') !== 'embedded_signup' && filled($config['phone_number_id'] ?? null) => ['muted', 'Elle girilen bilgilerle'],
            $coexistence === 'signup_reported' => ['ok', 'WhatsApp Business uygulamasıyla birlikte'],
            $coexistence === 'not_used' => ['warn', 'Cloud API (uygulamayla birlikte kullanım seçilmedi)'],
            $coexistence === 'not_requested' => ['muted', 'Cloud API'],
            filled($config['phone_number_id'] ?? null) => ['muted', 'Meta ile'],
            default => ['muted', '—'],
        }],
        ['Hesap erişimi', match ($check) {
            'verified' => ['ok', 'Doğrulandı · '.$when($config['connection_checked_at'] ?? null)],
            'queued' => ['warn', 'Kontrol ediliyor…'],
            'failed', 'phone_mismatch' => ['bad', 'Sorun var'],
            'dispatch_failed' => ['bad', 'Kontrol başlatılamadı'],
            default => ['muted', 'Kontrol edilmedi'],
        }],
        ['Mesaj aboneliği', match ($subscription) {
            'verified' => ['ok', 'Kurulu'],
            'missing' => ['bad', 'Uygulama abone değil'],
            'failed' => ['bad', 'Doğrulanamadı'],
            'app_id_missing' => ['warn', 'Meta App ID gerekli'],
            default => ['muted', 'Kontrol edilmedi'],
        }],
        ['Webhook adresi', ! empty($config['webhook_verified_at']) ? ['ok', 'Meta doğruladı'] : ['warn', 'Meta doğrulaması bekleniyor']],
        ['Geçmiş mesajlar', match (true) {
            ($config['history_state'] ?? '') === 'received_partial' => ['ok', 'Alındı'],
            ($config['history_state'] ?? '') === 'provider_error' => ['bad', 'Meta geçmişi paylaşamadı'],
            ($config['history_sync'] ?? '') === 'requested' => ['warn', 'İstendi · '.$when($config['history_sync_requested_at'] ?? null)],
            ($config['history_sync'] ?? '') === 'failed' => ['bad', 'İstenemedi'],
            default => ['muted', '—'],
        }],
        ['Son gelen mesaj', ! empty($config['last_message_received_at']) ? ['ok', $when($config['last_message_received_at'])] : ['muted', 'Henüz yok']],
    ];
@endphp
<section class="{{ $card }} space-y-4 p-5" data-wa-connection>
    <h2 class="font-semibold text-gray-900 dark:text-white">Bağlantı</h2>
    <dl class="divide-y divide-gray-100 text-sm dark:divide-gray-800">
        @foreach($rowsStatus as [$label, [$rowTone, $value]])
            <div class="flex items-center justify-between gap-3 py-2">
                <dt class="text-gray-500">{{ $label }}</dt>
                <dd class="flex items-center gap-2 text-right font-medium"><span class="h-2 w-2 shrink-0 rounded-full {{ ['ok' => 'bg-emerald-500', 'warn' => 'bg-amber-500', 'bad' => 'bg-rose-500', 'muted' => 'bg-gray-300 dark:bg-gray-600'][$rowTone] }}"></span>{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
    @foreach(['connection_error' => 'Hesap erişimi', 'subscription_error' => 'Mesaj aboneliği', 'history_sync_error' => 'Geçmiş mesaj aktarımı'] as $key => $label)
        @if(!empty($config[$key]))
            <div class="rounded-lg p-3 ring-1 ring-inset {{ $tone['bad'] }}">@include('livewire.operator.whatsapp.error-detail', ['error' => $config[$key], 'label' => $label])</div>
        @endif
    @endforeach
    <div class="flex flex-wrap gap-2">
        <button type="button" wire:click="checkConnection" wire:loading.attr="disabled" class="rounded-lg px-3 py-2 text-sm ring-1 ring-inset ring-gray-300 disabled:opacity-50 dark:ring-gray-700"><span wire:loading.remove wire:target="checkConnection">Erişimi kontrol et</span><span wire:loading wire:target="checkConnection">Sıraya alınıyor…</span></button>
        <button type="button" wire:click="subscribeWebhook" wire:loading.attr="disabled" @disabled($attemptBusy) class="rounded-lg px-3 py-2 text-sm ring-1 ring-inset ring-gray-300 disabled:opacity-50 dark:ring-gray-700">Mesaj aboneliğini kur</button>
        @if($state === 'connected')
            <button type="button" wire:click="beginSignup" wire:loading.attr="disabled" @disabled(! $metaReady || $attemptBusy) class="rounded-lg px-3 py-2 text-sm ring-1 ring-inset ring-gray-300 disabled:opacity-50 dark:ring-gray-700">Numarayı yeniden bağla</button>
        @endif
    </div>
    <p class="text-xs text-gray-500">Webhook adresi: <span class="font-mono">{{ route('api.whatsapp.webhook') }}</span>. Görsel ve ses kayıtlarının içeriği okunmaz; saatler Türkiye saatidir.</p>
</section>
