@php
    $connectionProblem = $config['connection_error'] ?? (($config['subscription_state'] ?? '') === 'failed' ? ($config['subscription_error'] ?? null) : null);
    $tokenExpired = \App\Services\WhatsApp\WhatsAppErrorText::tokenExpired($config['connection_error'] ?? null);
    $webhookVerified = ! empty($config['webhook_verified_at']);
    $subscribed = ($config['subscription_state'] ?? '') === 'verified';
    $firstMessage = ! empty($config['last_message_received_at']);
    $stepBadge = fn (bool $done, string $doneText = 'Tamam', string $todoText = 'Yapılacak'): string => '<span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset '.($done ? $tone['ok'] : $tone['muted']).'">'.e($done ? $doneText : $todoText).'</span>';
@endphp
<section class="{{ $card }} p-5" data-wa-setup>
    @if($state === 'attention')
        <div role="alert" class="mb-5 rounded-lg p-4 ring-1 ring-inset {{ $tone['bad'] }}">
            <p class="font-semibold">Kayıtlı WhatsApp numarası şu an çalışmıyor</p>
            @if($connectionProblem)
                <div class="mt-1">@include('livewire.operator.whatsapp.error-detail', ['error' => $connectionProblem, 'label' => null])</div>
            @elseif(($config['subscription_state'] ?? '') === 'missing')
                <p class="mt-1 text-sm">Meta uygulaması bu WhatsApp hesabına abone değil; mesajlar gelmez. Aşağıdan aboneliği kurun.</p>
            @endif
            <p class="mt-2 text-sm">{{ $tokenExpired ? 'Çözüm: aşağıdaki 2. adımdan numarayı Meta ile yeniden bağlayın; erişim anahtarı yenilenir, mesajlar kaybolmaz.' : 'Aşağıdaki adımları kontrol edin.' }}</p>
        </div>
    @elseif($state === 'disabled')
        <div class="mb-5 rounded-lg p-4 text-sm ring-1 ring-inset {{ $tone['muted'] }}">Mesaj alımı kapalı. Ayarlar → Elle bağla bölümünden "Mesaj alımı açık" kutusunu işaretleyip kaydedin.</div>
    @endif

    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $state === 'attention' ? 'Numarayı yeniden bağlayın' : 'WhatsApp numaranızı bağlayın' }}</h2>
    <p class="mt-1 text-sm text-gray-500">Üç adım. Telefonunuzdaki WhatsApp Business uygulaması çalışmaya devam eder.</p>

    <ol class="mt-5 space-y-5">
        <li class="flex gap-4">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-sm font-semibold {{ $metaReady ? 'bg-emerald-500 text-white' : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200' }}">{{ $metaReady ? '✓' : '1' }}</span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2"><h3 class="font-medium text-gray-900 dark:text-white">Meta uygulama bilgileri</h3>{!! $stepBadge($metaReady, 'Kayıtlı', 'Eksik') !!}</div>
                @if($metaReady)
                    <p class="mt-1 text-sm text-gray-500">App ID {{ $config['app_id'] }} · Yapılandırma {{ $config['signup_config_id'] }} · {{ ($config['signup_mode'] ?? 'coexistence') === 'coexistence' ? 'WhatsApp Business uygulamasıyla birlikte' : 'Cloud API' }}
                        · <button type="button" wire:click="$set('showSettings', true)" class="text-brand-600 hover:underline">Değiştir</button></p>
                @else
                    <p class="mt-1 text-sm text-gray-500">Meta for Developers'taki WhatsApp uygulamanızın bilgileri. Bir kez girilir.</p>
                    <div class="mt-3">@include('livewire.operator.whatsapp.signup-settings')</div>
                @endif
            </div>
        </li>

        <li class="flex gap-4">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gray-200 text-sm font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-200">2</span>
            <div class="min-w-0 flex-1 space-y-3">
                <div class="flex flex-wrap items-center gap-2"><h3 class="font-medium text-gray-900 dark:text-white">Numarayı Meta ile bağla</h3></div>
                <p class="text-sm text-gray-500">Facebook penceresinde işletme portföyünü seçin, "Mevcut WhatsApp Business uygulamanızı bağlayın" deyin ve telefonda gelen QR kodu okutun.</p>
                <div class="flex flex-wrap items-center gap-3">
                    @if($canContinue)
                        <a href="{{ route('operator.whatsapp.connect', ['attempt' => $signupAttempt->id]) }}" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600">{{ $signupAttempt->status === 'choose_phone' ? 'Numarayı seç' : 'Facebook ile bağla' }}</a>
                        <button type="button" wire:click="beginSignup" class="text-sm text-gray-500 hover:text-brand-600">Baştan başlat</button>
                    @else
                        <button type="button" wire:click="beginSignup" wire:loading.attr="disabled" @disabled(! $metaReady || $attemptBusy) class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50">Facebook ile bağla</button>
                    @endif
                </div>
                @if($signupAttempt && $signupAttempt->status !== 'completed')
                    @include('livewire.operator.whatsapp.attempt-status')
                @endif
                <p class="text-xs text-gray-500">Kendi numaranız mı (Meta uygulamasının sahibi olan portföy)? Meta o portföyün bu pencerede seçilmesine izin vermez. <button type="button" wire:click="openManual" class="font-medium text-brand-600 hover:underline">Elle bağlayın</button>.</p>
            </div>
        </li>

        <li class="flex gap-4">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-sm font-semibold {{ $webhookVerified && $subscribed ? 'bg-emerald-500 text-white' : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200' }}">{{ $webhookVerified && $subscribed ? '✓' : '3' }}</span>
            <div class="min-w-0 flex-1 space-y-2">
                <h3 class="font-medium text-gray-900 dark:text-white">Mesajların MoxDOP'a gelmesi</h3>
                <p class="text-sm text-gray-500">Meta uygulamasında WhatsApp → Yapılandırma → Webhook bölümüne bu adresi ve kayıtlı Verify Token'ı girin; <code class="text-xs">messages</code>, <code class="text-xs">history</code> ve <code class="text-xs">smb_message_echoes</code> alanlarına abone olun.</p>
                <div class="flex gap-2" x-data="{ copied: false }">
                    <input aria-label="Webhook adresi" readonly value="{{ route('api.whatsapp.webhook') }}" onclick="this.select()" class="min-w-0 flex-1 rounded-lg border border-gray-300 bg-gray-50 p-2 font-mono text-xs dark:border-gray-700 dark:bg-white/[0.03]" />
                    <button type="button" @click="navigator.clipboard.writeText('{{ route('api.whatsapp.webhook') }}').then(() => { copied = true; setTimeout(() => copied = false, 1500) })" class="rounded-lg px-3 py-2 text-xs ring-1 ring-inset ring-gray-300 dark:ring-gray-700" x-text="copied ? 'Kopyalandı' : 'Kopyala'">Kopyala</button>
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
