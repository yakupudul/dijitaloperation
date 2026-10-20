@php
    // Panel concept (2026-10-20): content sits in white cards; the two chat panes are cards too ($pane).
    $card = 'min-w-0';
    $pane = 'card dark:text-gray-200';
    $tone = [
        'ok' => 'tone-ok',
        'warn' => 'tone-look',
        'bad' => 'tone-critical',
        'muted' => 'tone-info',
    ];
    $toneDot = ['ok' => 'ok', 'warn' => 'look', 'bad' => 'critical', 'muted' => 'info'];
    // Backup chats can come with a hidden number (lid…) instead of a phone number.
    $phoneLabel = fn (?string $number): string => match (true) {
        filled($number) && ctype_digit($number) => '+'.$number,
        filled($number) => 'Gizli numara',
        default => '—',
    };
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
    if ($signupAttempt?->status === 'prepared') {
        $attemptLabels['prepared'] = $signupAttempt->launched_at ? ['warn', 'Meta penceresi açıldı, sonuç bekleniyor'] : ['muted', 'Facebook penceresi açılmadı'];
    }
    $canContinue = $attemptOwned && in_array($signupAttempt->status, ['prepared', 'cancelled', 'choose_phone'], true) && $signupAttempt->expires_at->isFuture();
    // The Meta popup is open somewhere: refresh so its result shows up here without reloading.
    $attemptWaiting = $signupAttempt?->status === 'prepared' && $signupAttempt->launched_at && $signupAttempt->expires_at->isFuture();
    // A reconnect or subscription run from an already connected number reports here, above the inbox.
    $showReconnect = $state === 'connected' && $signupAttempt && ! in_array($signupAttempt->status, ['completed', 'expired'], true)
        && ($attemptBusy || $signupAttempt->updated_at->greaterThan(now()->subDay()));
    $suggestionBusy = $selected && in_array($selected->suggestion_status, ['requested', 'running'], true);
    $backgroundBusy = in_array($backupImport?->status, ['queued', 'running'], true) || in_array($config['brain_status'] ?? null, ['queued', 'running'], true);
@endphp
<div class="space-y-10">
    {{-- Separate keyed pollers: changing one element's poll interval would leave the old timer running. --}}
    @if($attemptBusy || $suggestionBusy || ($config['connection_check'] ?? '') === 'queued')
        <div wire:key="wa-poll-fast" wire:poll.3s hidden></div>
    @elseif((! $showSettings && $state === 'connected') || $attemptWaiting || $backgroundBusy)
        <div wire:key="wa-poll-slow" wire:poll.10s hidden></div>
    @endif
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="page-title">WhatsApp</h1>
            <p class="page-sub">MoxDOP mesaj göndermez; cevabı kopyalayıp telefondan gönderin.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <x-ta.pill :tone="$toneDot[$statePill[0]]" data-wa-state="{{ $state }}" class="text-sm">{{ $statePill[1] }}</x-ta.pill>
            <button type="button" wire:click="$toggle('showSettings')" class="btn">{{ $showSettings ? 'Ayarları kapat' : 'Ayarlar' }}</button>
        </div>
    </div>

    @if($notice || $errors->any())
        <div class="-mt-4 space-y-2">
            @if($notice)
                <p role="status" class="card px-4 py-3 text-sm text-gray-800 dark:text-gray-200">{{ $notice }}</p>
            @endif
            @if($errors->any())
                <div role="alert" class="rounded-xl px-4 py-3 text-sm tone-critical">
                    @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
                </div>
            @endif
        </div>
    @endif

    @if($historyDeadline)
        {{-- Canlı panel (2026-10-20): time-limited or failed steps are tinted cards (amber: do it soon, red: it failed). --}}
        <section class="relative min-w-0 overflow-hidden rounded-3xl border soft-amber" data-wa-history>
            <div class="card-row flex-wrap items-start sm:flex-nowrap">
                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-sm font-medium text-gray-900 dark:text-white">Telefondaki geçmiş mesajları alın</h2>
                    <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">Meta bu aktarımı yalnız bağlantıdan sonraki 24 saat içinde yapar (son: {{ $when($historyDeadline) }}). Son 6 aya kadar olan sohbetler MoxDOP'a gelir; KVKK saklama süresi bunlar için de geçerlidir.</p>
                    @if(!empty($config['history_sync_error']))
                        <div class="mt-2 text-error-700 dark:text-error-300">@include('livewire.operator.whatsapp.error-detail', ['error' => $config['history_sync_error'], 'label' => 'Son deneme'])</div>
                    @endif
                </div>
                <button type="button" wire:click="requestHistory" wire:loading.attr="disabled" wire:target="requestHistory" class="btn-primary w-full sm:w-auto">{{ ($config['history_sync'] ?? '') === 'failed' ? 'Tekrar dene' : 'Geçmiş mesajları al' }}</button>
            </div>
        </section>
    @endif

    @if($showReconnect)
        <section data-wa-reconnect>
            <div class="section-head items-center!">
                <h2 class="section-title">{{ $signupAttempt->mode === 'subscription' ? 'Mesaj aboneliği' : 'Numarayı yeniden bağlama' }}</h2>
                @if($canContinue)
                    <a href="{{ route('operator.whatsapp.connect', ['attempt' => $signupAttempt->id]) }}" class="btn-primary ml-auto">{{ $signupAttempt->status === 'choose_phone' ? 'Numarayı seç' : 'Bağlantı sayfasını aç' }}</a>
                @endif
            </div>
            @include('livewire.operator.whatsapp.attempt-status')
            @if($signupAttempt->mode !== 'subscription' && in_array($signupAttempt->status, ['prepared', 'cancelled', 'choose_phone', 'failed', 'interrupted'], true))
                <p class="mt-2 text-xs text-gray-500">Kayıtlı numara bu sırada çalışmaya devam ediyor; yeni bağlantı tamamlanınca onun yerine geçer.</p>
            @endif
        </section>
    @endif

    @php
        $activeTab = in_array($tab, ['inbox', 'brain', 'setup'], true) ? $tab : (($totalConversations > 0 || $state === 'connected') ? 'inbox' : 'setup');
        $openQuestions = count(array_filter((array) data_get($config, 'brain.open_questions', []), fn ($question) => is_string($question) && trim($question) !== ''));
        $tabs = [
            'inbox' => ['Gelen kutusu', $totalConversations ? (string) $totalConversations : null, false],
            'brain' => ['Beyin', $openQuestions ? $openQuestions.' soru' : null, true],
            'setup' => [$state === 'connected' ? 'Bağlantı' : 'Yedek ve bağlantı', null, false],
        ];
    @endphp
    <nav class="flex gap-1 overflow-x-auto border-b border-gray-200 no-scrollbar dark:border-gray-800" aria-label="WhatsApp bölümleri" data-wa-tabs>
        @foreach($tabs as $key => [$label, $count, $asks])
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['tab', 'tab-active' => $activeTab === $key]) @if($activeTab === $key) aria-current="page" @endif>{{ $label }}@if($count !== null) <span @class(['text-xs font-normal tabular-nums', 'text-warning-700 dark:text-warning-300' => $asks, 'text-gray-500' => ! $asks]) data-wa-tab-count="{{ $key }}">{{ $count }}</span>@endif</button>
        @endforeach
    </nav>

    @if($showSettings)
        @include('livewire.operator.whatsapp.settings')
    @endif

    @if($activeTab === 'inbox')
        @php
            $inboxNotes = $backupImport?->isBusy()
                || ($backupImport?->status === 'failed' && $backupImport->updated_at?->greaterThan(now()->subDays(3)))
                || $backupImport?->status === 'uploaded'
                || $openQuestions > 0;
        @endphp
        @if($inboxNotes)
            <div class="-mt-4 space-y-2">
                @if($backupImport?->isBusy())
                    @include('livewire.operator.whatsapp.backup-progress')
                @elseif($backupImport?->status === 'failed' && $backupImport->updated_at?->greaterThan(now()->subDays(3)))
                    <div role="alert" class="relative min-w-0 overflow-hidden rounded-3xl border soft-red" data-wa-backup-status="failed">
                        <div class="card-row flex-wrap items-start sm:flex-nowrap">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-error-500" aria-hidden="true"></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">Son yedek çıkarılamadı</p>
                                <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $backupImport->error }}</p>
                            </div>
                            <button type="button" wire:click="$set('tab', 'setup')" class="btn w-full sm:w-auto">Yedek yükle</button>
                        </div>
                    </div>
                @elseif($backupImport?->status === 'uploaded')
                    <div class="relative min-w-0 overflow-hidden rounded-3xl border soft-amber" data-wa-backup-status="uploaded">
                        <div class="card-row flex-wrap items-start sm:flex-nowrap">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>
                            <p class="min-w-0 flex-1 text-sm text-gray-800 dark:text-gray-200">{{ $backupImport->file_name }} yüklendi ama çıkarılmadı{{ $backupImport->error ? ': '.$backupImport->error : '.' }}</p>
                            <button type="button" wire:click="$set('tab', 'setup')" class="btn w-full sm:w-auto">Çıkar</button>
                        </div>
                    </div>
                @endif
                @if($openQuestions > 0)
                    <div class="relative min-w-0 overflow-hidden rounded-3xl border soft-amber" data-wa-questions-hint>
                        <div class="card-row flex-wrap items-start sm:flex-nowrap">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>
                            <p class="min-w-0 flex-1 text-sm text-gray-800 dark:text-gray-200">Beyin senden {{ $openQuestions }} konuda bilgi istiyor; cevaplarsan cevaplar daha isabetli olur.</p>
                            <button type="button" wire:click="$set('tab', 'brain')" class="btn w-full sm:w-auto">Cevapla</button>
                        </div>
                    </div>
                @endif
            </div>
        @endif
        <div @class(['-mt-4' => ! $inboxNotes])>
            @include('livewire.operator.whatsapp.conversations')
        </div>
    @elseif($activeTab === 'brain')
        <div class="-mt-4">@include('livewire.operator.whatsapp.brain')</div>
    @else
        @if($state !== 'connected')
            <div class="-mt-4">@include('livewire.operator.whatsapp.backup')</div>
            {{-- The live Meta connection waits (yakup, 2026-10-05); kept one click away. --}}
            <details class="group/meta" wire:ignore.self data-wa-meta-setup @if($signupAttempt && ! in_array($signupAttempt->status, ['completed', 'expired'], true)) open @endif>
                <summary class="card flex cursor-pointer list-none items-center gap-3.5 px-4 py-3.5 hover:bg-gray-50 dark:hover:bg-white/[0.03] [&::-webkit-details-marker]:hidden">
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium text-gray-900 dark:text-white">Canlı bağlantı (Meta)</span>
                        <span class="mt-0.5 block text-[13px] text-gray-500 dark:text-gray-400">Askıda, Meta onayı gerekir.</span>
                    </span>
                    <span class="btn-quiet"><span class="group-open/meta:hidden">Aç</span><span class="hidden group-open/meta:inline">Kapat</span></span>
                </summary>
                <div class="mt-4">@include('livewire.operator.whatsapp.setup', ['card' => 'card p-5'])</div>
            </details>
        @else
            <p class="-mt-4 text-sm text-gray-500">Numara Meta ile bağlı; mesajlar anında gelir. Bağlantı ayarları için sağ üstteki Ayarlar'ı açın.</p>
        @endif
    @endif
</div>
