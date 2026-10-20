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
            'verified' => ['ok', 'Doğrulandı, '.$when($config['connection_checked_at'] ?? null)],
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
            ($config['history_sync'] ?? '') === 'requested' => ['warn', 'İstendi, '.$when($config['history_sync_requested_at'] ?? null)],
            ($config['history_sync'] ?? '') === 'failed' => ['bad', 'İstenemedi'],
            $historyDeadline !== null => ['warn', 'Alınmadı, son '.$when($historyDeadline)],
            default => ['muted', '—'],
        }],
        ['Son gelen mesaj', ! empty($config['last_message_received_at']) ? ['ok', $when($config['last_message_received_at'])] : ['muted', 'Henüz yok']],
    ];
@endphp
<section data-wa-connection>
    <div class="section-head"><h2 class="section-title">Bağlantı</h2></div>
    <div class="card">
        <dl>
            @foreach($rowsStatus as [$label, [$rowTone, $value]])
                <div class="card-row justify-between py-3!">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                    <dd class="min-w-0 text-right"><x-ta.pill :tone="$toneDot[$rowTone]" class="text-sm! whitespace-normal!">{{ $value }}</x-ta.pill></dd>
                </div>
            @endforeach
        </dl>
        @foreach(['connection_error' => 'Hesap erişimi', 'subscription_error' => 'Mesaj aboneliği', 'history_sync_error' => 'Geçmiş mesaj aktarımı'] as $key => $label)
            @if(!empty($config[$key]))
                <div class="card-row items-start text-error-800 dark:text-error-300">
                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-error-500" aria-hidden="true"></span>
                    <div class="min-w-0 flex-1">@include('livewire.operator.whatsapp.error-detail', ['error' => $config[$key], 'label' => $label])</div>
                </div>
            @endif
        @endforeach
        <div class="flex flex-wrap items-center gap-2 border-t border-gray-200 bg-gray-50/60 px-4 py-3 dark:border-gray-800 dark:bg-white/[0.02]">
            <button type="button" wire:click="checkConnection" wire:loading.attr="disabled" class="btn"><span wire:loading.remove wire:target="checkConnection">Erişimi kontrol et</span><span wire:loading wire:target="checkConnection">Sıraya alınıyor…</span></button>
            <button type="button" wire:click="subscribeWebhook" wire:loading.attr="disabled" @disabled($attemptBusy) class="btn">Mesaj aboneliğini kur</button>
            @if($state === 'connected')
                <button type="button" wire:click="beginSignup" wire:loading.attr="disabled" @disabled(! $metaReady || $attemptBusy) class="btn">Numarayı yeniden bağla</button>
            @endif
        </div>
    </div>
    <p class="mt-2 text-xs text-gray-500">Webhook adresi: <span class="break-all font-mono">{{ route('api.whatsapp.webhook') }}</span>. Görsel ve ses kayıtlarının içeriği okunmaz; saatler Türkiye saatidir.</p>
</section>
