<section @if(in_array($signupAttempt?->status, ['queued', 'running'], true)) wire:poll.5s @endif class="space-y-3 rounded-xl border border-gray-200 bg-white p-5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
    <h2 class="font-semibold">Bağlantı durumu</h2>
    <div class="grid gap-3 md:grid-cols-3">
        <div><p class="text-gray-500">Hesap / numara erişimi</p><p class="mt-1 font-medium">{{ ($config['connection_check'] ?? '') === 'verified' ? 'Doğrulandı' : (($config['connection_check'] ?? '') === 'queued' ? 'Kontrol ediliyor' : 'Doğrulanmadı') }}</p></div>
        <div><p class="text-gray-500">WABA webhook aboneliği</p><p class="mt-1 font-medium">{{ ['verified' => 'Doğrulandı', 'missing' => 'Uygulama abone değil', 'failed' => 'Doğrulanamadı', 'app_id_missing' => 'Meta App ID gerekli'][$config['subscription_state'] ?? ''] ?? 'Henüz kontrol edilmedi' }}</p></div>
        <div><p class="text-gray-500">Gerçek mesaj kaydı</p><p class="mt-1 font-medium">{{ !empty($config['last_message_received_at']) ? 'Mesaj kaydedildi' : 'Henüz mesaj kaydı doğrulanmadı' }}</p></div>
    </div>
    @foreach(['connection_error' => 'API erişimi', 'subscription_error' => 'Webhook aboneliği'] as $key => $label)
        @if(!empty($config[$key]))
            @include('livewire.operator.whatsapp.error-detail', ['error' => $config[$key], 'label' => $label])
        @endif
    @endforeach
    @if($signupAttempt)
        <p>Son hesap bağlantısı: <strong>{{ ['prepared' => 'Meta onayı bekleniyor', 'queued' => 'Sırada', 'running' => 'Tamamlanıyor', 'choose_phone' => 'Numara seçimi gerekiyor', 'completed' => 'Hesap ve abonelik doğrulandı', 'partial' => 'Webhook aboneliği tamamlanamadı', 'failed' => 'Tamamlanamadı', 'expired' => 'Süresi doldu', 'interrupted' => 'İşlem kesildi; yeniden deneyin'][$signupAttempt->status] ?? $signupAttempt->status }}</strong></p>
        @if($signupAttempt->status === 'running')
            <p class="text-xs text-gray-500">{{ ['exchange_code' => 'Meta yetkisi alınıyor', 'verify_token' => 'Uygulama ve izinler kontrol ediliyor', 'verify_phone' => 'İşletme numarası kontrol ediliyor', 'subscribe' => 'Webhook aboneliği kuruluyor'][$signupAttempt->step] ?? 'Bağlantı hazırlanıyor' }}. Sayfayı açık tutmanız gerekmez.</p>
        @endif
        @if(!empty($signupAttempt->details))
            @include('livewire.operator.whatsapp.error-detail', ['error' => $signupAttempt->details, 'label' => 'Bağlantı sonucu'])
        @endif
        @if(in_array($signupAttempt->status, ['prepared', 'choose_phone'], true) && $signupAttempt->expires_at->isFuture() && $signupAttempt->user_id === auth()->id())
            <a href="{{ route('operator.whatsapp.connect', ['attempt' => $signupAttempt->id]) }}" class="inline-block rounded-lg bg-brand-500 px-4 py-2 text-white">{{ $signupAttempt->status === 'choose_phone' ? 'İşletme numarasını seç' : 'Meta bağlantısına devam et' }}</a>
        @endif
        @if(in_array($signupAttempt->status, ['queued', 'running'], true) && $signupAttempt->updated_at->lessThan(now()->subMinutes(3)))
            <p class="text-amber-700">Bağlantı beklenenden uzun sürdü. Horizon ve dakika zamanlayıcısını kontrol edin.</p>
        @endif
    @endif
    <p class="text-xs text-gray-500">Callback doğrulaması: {{ !empty($config['webhook_verified_at']) ? 'Meta doğrulama isteği başarıyla yanıtlandı.' : 'Henüz kaydedilmedi. Meta webhook ekranından doğrulayın.' }}</p>
    <p class="text-xs text-gray-500">Abonelik doğrulaması mesaj alındığı anlamına gelmez. Meta webhook ekranında callback adresi, Verify Token ve messages alanı da ayarlanmış olmalı.</p>
    <button type="button" wire:click="subscribeWebhook" wire:loading.attr="disabled" @disabled(in_array($signupAttempt?->status, ['queued', 'running'], true)) class="rounded-lg border border-gray-300 px-4 py-2 text-sm disabled:opacity-50">Webhook aboneliğini kur / yeniden dene</button>
</section>
