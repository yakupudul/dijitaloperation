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
    $suggestionBusy = $selected && in_array($selected->suggestion_status, ['requested', 'running'], true);
    $showInbox = in_array($state, ['connected', 'attention', 'disabled'], true) || $rows->total() > 0 || trim($q) !== '';
@endphp
<div class="space-y-5" @if($attemptBusy || $suggestionBusy || ($config['connection_check'] ?? '') === 'queued') wire:poll.3s @elseif(!$showSettings && $state === 'connected') wire:poll.10s @endif>
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
