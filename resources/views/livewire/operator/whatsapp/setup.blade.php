@php
    $connectionProblem = $config['connection_error'] ?? (($config['subscription_state'] ?? '') === 'failed' ? ($config['subscription_error'] ?? null) : null);
    $tokenExpired = \App\Services\WhatsApp\WhatsAppErrorText::tokenExpired($config['connection_error'] ?? null);
    $webhookVerified = ! empty($config['webhook_verified_at']);
    $subscribed = ($config['subscription_state'] ?? '') === 'verified';
    $firstMessage = ! empty($config['last_message_received_at']);
    $stepBadge = fn (bool $done, string $doneText = 'Tamam', string $todoText = 'Yapılacak'): string => '<span class="inline-flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300"><span class="h-1.5 w-1.5 rounded-full '.($done ? 'bg-success-500' : 'bg-gray-400').'"></span>'.e($done ? $doneText : $todoText).'</span>';
@endphp
<section class="{{ $card }}" data-wa-setup>
    @if($state === 'attention')
        <div role="alert" class="mb-5 rounded-lg p-4 tone-critical">
            <p class="font-semibold">Kayıtlı WhatsApp numarası şu an çalışmıyor</p>
            @if($connectionProblem)
                <div class="mt-1">@include('livewire.operator.whatsapp.error-detail', ['error' => $connectionProblem, 'label' => null])</div>
            @elseif(($config['subscription_state'] ?? '') === 'missing')
                <p class="mt-1 text-sm">Meta uygulaması bu WhatsApp hesabına abone değil; mesajlar gelmez. Aşağıdan aboneliği kurun.</p>
            @endif
            <p class="mt-2 text-sm">{{ $tokenExpired ? 'Çözüm: aşağıdaki 2. adımdan numarayı Meta ile yeniden bağlayın; erişim anahtarı yenilenir, mesajlar kaybolmaz.' : 'Aşağıdaki adımları kontrol edin.' }}</p>
        </div>
    @elseif($state === 'disabled')
        <div class="mb-5 rounded-lg p-4 text-sm tone-info">Mesaj alımı kapalı. Ayarlar › Elle bağla bölümünden "Mesaj alımı açık" kutusunu işaretleyip kaydedin.</div>
    @endif

    <h2 class="section-title">{{ $state === 'attention' ? 'Numarayı yeniden bağlayın' : 'WhatsApp numaranızı bağlayın' }}</h2>
    <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">Üç adım. Telefonunuzdaki WhatsApp Business uygulaması çalışmaya devam eder.</p>

    <ol class="mt-5 space-y-5">
        <li class="flex gap-4">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-sm font-semibold {{ $metaReady ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'text-gray-600 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700' }}">{{ $metaReady ? '✓' : '1' }}</span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2"><h3 class="text-sm font-medium text-gray-900 dark:text-white">Meta uygulama bilgileri</h3>{!! $stepBadge($metaReady, 'Kayıtlı', 'Eksik') !!}</div>
                @if($metaReady)
                    <p class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-[13px] text-gray-500 dark:text-gray-400"><span>App ID {{ $config['app_id'] }}</span><span>Yapılandırma {{ $config['signup_config_id'] }}</span><span>{{ ($config['signup_mode'] ?? 'coexistence') === 'coexistence' ? 'WhatsApp Business uygulamasıyla birlikte' : 'Cloud API' }}</span>
                        <button type="button" wire:click="$set('showSettings', true)" class="link-neutral">Değiştir</button></p>
                @else
                    <p class="mt-1 text-[13px] text-gray-500 dark:text-gray-400">Meta for Developers'taki WhatsApp uygulamanızın bilgileri. Bir kez girilir.</p>
                    <div class="mt-3">@include('livewire.operator.whatsapp.signup-settings')</div>
                @endif
            </div>
        </li>

        <li class="flex gap-4">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gray-200 text-sm font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-200">2</span>
            <div class="min-w-0 flex-1 space-y-3">
                <div class="flex flex-wrap items-center gap-2"><h3 class="text-sm font-medium text-gray-900 dark:text-white">Numarayı Meta ile bağla</h3></div>
                <p class="text-[13px] text-gray-500 dark:text-gray-400">Bağlantı sayfasında "Facebook ile bağla"ya basın. Açılan pencerede işletme portföyünü seçin, WhatsApp Business'ta kullandığınız numarayı girin, telefonda onay verin ve son ekranda "Bitti" deyin. Pencere bitene kadar bağlantı sayfasını kapatmayın.</p>
                <div class="flex flex-wrap items-center gap-3">
                    @if($canContinue)
                        <a href="{{ route('operator.whatsapp.connect', ['attempt' => $signupAttempt->id]) }}" class="btn-primary">{{ $signupAttempt->status === 'choose_phone' ? 'Numarayı seç' : 'Bağlantı sayfasını aç' }}</a>
                        <button type="button" wire:click="beginSignup" wire:loading.attr="disabled" class="btn-quiet">Baştan başlat</button>
                    @else
                        <button type="button" wire:click="beginSignup" wire:loading.attr="disabled" @disabled(! $metaReady || $attemptBusy) class="btn-primary">Bağlamaya başla</button>
                    @endif
                </div>
                @if($signupAttempt && $signupAttempt->status !== 'completed')
                    @include('livewire.operator.whatsapp.attempt-status')
                @endif
                <p class="text-xs text-gray-500">Kendi numaranız mı (Meta uygulamasının sahibi olan portföy)? Meta o portföyün bu pencerede seçilmesine izin vermez. <button type="button" wire:click="openManual" class="link-primary">Elle bağlayın</button>.</p>
            </div>
        </li>

        <li class="flex gap-4">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-sm font-semibold {{ $webhookVerified && $subscribed ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'text-gray-600 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700' }}">{{ $webhookVerified && $subscribed ? '✓' : '3' }}</span>
            <div class="min-w-0 flex-1 space-y-2">
                <h3 class="text-sm font-medium text-gray-900 dark:text-white">Mesajların MoxDOP'a gelmesi</h3>
                <p class="text-[13px] text-gray-500 dark:text-gray-400">Meta uygulamasında WhatsApp › Yapılandırma › Webhook bölümüne bu adresi ve kayıtlı Verify Token'ı girin; <code class="text-xs">messages</code>, <code class="text-xs">history</code> ve <code class="text-xs">smb_message_echoes</code> alanlarına abone olun.</p>
                <div class="flex gap-2" x-data="{ copied: false }">
                    <input aria-label="Webhook adresi" readonly value="{{ route('api.whatsapp.webhook') }}" onclick="this.select()" class="h-9 min-w-0 flex-1 rounded-lg border border-gray-200 bg-gray-50 px-3 font-mono text-xs text-gray-700 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-300" />
                    <button type="button" @click="navigator.clipboard.writeText('{{ route('api.whatsapp.webhook') }}').then(() => { copied = true; setTimeout(() => copied = false, 1500) })" class="btn h-9!" x-text="copied ? 'Kopyalandı' : 'Kopyala'">Kopyala</button>
                </div>
                <div class="flex flex-wrap gap-2 text-xs">
                    {!! $stepBadge($webhookVerified, 'Meta adresi doğruladı', 'Adres doğrulaması bekleniyor') !!}
                    {!! $stepBadge($subscribed, 'Mesaj aboneliği kurulu', 'Mesaj aboneliği yok') !!}
                    {!! $stepBadge($firstMessage, 'İlk mesaj geldi', 'İlk mesaj bekleniyor') !!}
                </div>
            </div>
        </li>
    </ol>
</section>
