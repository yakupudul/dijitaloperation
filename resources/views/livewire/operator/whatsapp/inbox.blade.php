@php
    $card = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:text-gray-200 dark:ring-gray-800';
    $tone = [
        'ok' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20',
        'warn' => 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20',
        'bad' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20',
        'muted' => 'bg-gray-100 text-gray-600 ring-gray-200 dark:bg-white/[0.06] dark:text-gray-300 dark:ring-gray-700',
    ];
    $phoneLabel = fn (?string $number): string => filled($number) ? '+'.$number : '—';
    $when = fn ($value): string => $value ? \Carbon\CarbonImmutable::parse($value)->timezone('Europe/Istanbul')->format('d.m.Y H:i') : '—';
    $statePill = [
        'connected' => ['ok', 'Bağlı · '.$phoneLabel($config['business_phone'] ?? null)],
        'attention' => ['bad', 'Bağlantı sorunu'],
        'disabled' => ['muted', 'Mesaj alımı kapalı'],
        'connect' => ['warn', 'Numara bağlı değil'],
        'setup' => ['warn', 'Kurulum bekliyor'],
    ][$state];
    $metaReady = filled($config['app_id'] ?? null) && filled($config['signup_config_id'] ?? null) && $credentialStatus['app_secret'] && $credentialStatus['verify_token'];
    $attemptBusy = in_array($signupAttempt?->status, ['exchanging', 'queued', 'running'], true);
    $attemptLabels = [
        'prepared' => ['muted', 'Meta penceresi açıldı, sonuç gelmedi'],
        'cancelled' => ['bad', 'Meta penceresinde yarıda kaldı'],
        'exchanging' => ['warn', 'Meta onayı alınıyor…'],
        'queued' => ['warn', 'Bağlanıyor…'],
        'running' => ['warn', 'Bağlanıyor…'],
        'choose_phone' => ['warn', 'Numara seçmeniz gerekiyor'],
        'completed' => ['ok', 'Tamamlandı'],
        'partial' => ['bad', 'Numara bağlandı, mesaj aboneliği kurulamadı'],
        'failed' => ['bad', 'Tamamlanamadı'],
        'expired' => ['muted', 'Süresi doldu'],
        'interrupted' => ['bad', 'Yarıda kesildi; yeniden deneyin'],
    ];
    $runningSteps = ['exchange_code' => 'Meta yetkisi alınıyor', 'verify_token' => 'İzinler kontrol ediliyor', 'verify_phone' => 'Numara kontrol ediliyor', 'subscribe' => 'Mesaj aboneliği kuruluyor'];
    $canContinue = $signupAttempt && in_array($signupAttempt->status, ['prepared', 'cancelled', 'choose_phone'], true)
        && $signupAttempt->expires_at->isFuture() && $signupAttempt->user_id === auth()->id();
    // A reconnect or subscription run from an already connected number reports here, above the inbox.
    $showReconnect = $state === 'connected' && $signupAttempt && ! in_array($signupAttempt->status, ['completed', 'expired'], true)
        && ($attemptBusy || $signupAttempt->updated_at->greaterThan(now()->subDay()));
    $suggestionBusy = $selected && in_array($selected->suggestion_status, ['requested', 'running'], true);
    $showInbox = in_array($state, ['connected', 'attention', 'disabled'], true) || $rows->total() > 0 || trim($q) !== '';
@endphp
<div class="space-y-5">
    {{-- Separate keyed pollers: changing one element's poll interval would leave the old timer running. --}}
    @if($attemptBusy || $suggestionBusy || ($config['connection_check'] ?? '') === 'queued')
        <div wire:key="wa-poll-fast" wire:poll.3s hidden></div>
    @elseif(! $showSettings && $state === 'connected')
        <div wire:key="wa-poll-slow" wire:poll.10s hidden></div>
    @endif
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">WhatsApp</h1>
            <p class="mt-1 text-sm text-gray-500">Gelen mesajları okuyun; AI'nin hazırladığı cevabı kopyalayıp telefondan gönderin. MoxDOP mesaj göndermez.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span data-wa-state="{{ $state }}" class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset {{ $tone[$statePill[0]] }}">
                <span class="h-2 w-2 rounded-full bg-current"></span>{{ $statePill[1] }}
            </span>
            <button type="button" wire:click="$toggle('showSettings')" class="rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-white/[0.04]">{{ $showSettings ? 'Ayarları kapat' : 'Ayarlar' }}</button>
        </div>
    </div>

    @if($notice)
        <p role="status" class="rounded-lg bg-blue-50 px-3 py-2 text-sm text-blue-800 dark:bg-blue-500/10 dark:text-blue-200">{{ $notice }}</p>
    @endif
    @if($errors->any())
        <div role="alert" class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800 dark:bg-rose-500/10 dark:text-rose-200">
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    @if($historyDeadline)
        <section class="rounded-xl p-5 ring-1 ring-inset {{ $tone['warn'] }}" data-wa-history>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 flex-1">
                    <h2 class="font-semibold">Telefondaki geçmiş mesajları alın</h2>
                    <p class="mt-1 text-sm">Meta bu aktarımı yalnız bağlantıdan sonraki 24 saat içinde yapar (son: {{ $when($historyDeadline) }}). Son 6 aya kadar olan sohbetler MoxDOP'a gelir; KVKK saklama süresi bunlar için de geçerlidir.</p>
                    @if(!empty($config['history_sync_error']))
                        <div class="mt-2 text-sm">@include('livewire.operator.whatsapp.error-detail', ['error' => $config['history_sync_error'], 'label' => 'Son deneme'])</div>
                    @endif
                </div>
                <button type="button" wire:click="requestHistory" wire:loading.attr="disabled" wire:target="requestHistory" class="shrink-0 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-60">{{ ($config['history_sync'] ?? '') === 'failed' ? 'Tekrar dene' : 'Geçmiş mesajları al' }}</button>
            </div>
        </section>
    @endif

    @if($showReconnect)
        <section class="{{ $card }} space-y-3 p-5" data-wa-reconnect>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-semibold text-gray-900 dark:text-white">{{ $signupAttempt->mode === 'subscription' ? 'Mesaj aboneliği' : 'Numarayı yeniden bağlama' }}</h2>
                @if($canContinue)
                    <a href="{{ route('operator.whatsapp.connect', ['attempt' => $signupAttempt->id]) }}" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600">{{ $signupAttempt->status === 'choose_phone' ? 'Numarayı seç' : 'Facebook ile devam et' }}</a>
                @endif
            </div>
            @include('livewire.operator.whatsapp.attempt-status')
            @if($signupAttempt->mode !== 'subscription' && in_array($signupAttempt->status, ['prepared', 'cancelled', 'choose_phone', 'failed', 'interrupted'], true))
                <p class="text-xs text-gray-500">Kayıtlı numara bu sırada çalışmaya devam ediyor; yeni bağlantı tamamlanınca onun yerine geçer.</p>
            @endif
        </section>
    @endif

    @if($state !== 'connected')
        @include('livewire.operator.whatsapp.setup')
    @endif

    @if($showSettings)
        @include('livewire.operator.whatsapp.settings')
    @endif

    @if($showInbox)
        @include('livewire.operator.whatsapp.conversations')
    @endif
</div>
