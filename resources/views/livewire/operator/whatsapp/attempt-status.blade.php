@php
    [$attemptTone, $attemptText] = $attemptLabels[$signupAttempt->status] ?? ['muted', $signupAttempt->status];
    $attemptTrace = collect($signupAttempt->trace ?? [])->filter(fn ($entry) => is_array($entry));
    $lastTraceEvent = $attemptTrace->last()['event'] ?? null;
@endphp
<div class="rounded-lg p-3 text-sm ring-1 ring-inset {{ $tone[$attemptTone] }}" data-wa-attempt="{{ $signupAttempt->status }}">
    <p class="font-medium">Son deneme: {{ $attemptText }} <span class="font-normal opacity-75">· {{ $when($signupAttempt->updated_at) }}</span></p>
    @if($signupAttempt->status === 'running')
        <p class="mt-1">{{ $runningSteps[$signupAttempt->step] ?? 'Bağlantı hazırlanıyor' }}. Sayfayı açık tutmanız gerekmez.</p>
    @elseif($signupAttempt->status === 'prepared' && ! $signupAttempt->launched_at)
        <p class="mt-1">Bağlantı sayfası açıldı ama oradaki "Facebook ile bağla" düğmesine basılmadı ya da Facebook penceresi açılamadı.</p>
    @elseif($signupAttempt->status === 'prepared' && $lastTraceEvent === 'PAGE_LEFT')
        <p class="mt-1">Meta penceresi açıkken bağlantı sayfasından çıkıldı; Meta'nın sonucu bu yüzden alınamadı. Bağlantı sayfasını açıp yeniden deneyin ve pencere bitene kadar o sayfayı kapatmayın.</p>
    @elseif($signupAttempt->status === 'prepared')
        <p class="mt-1">Meta penceresi {{ $when($signupAttempt->launched_at) }} açıldı, henüz sonuç gelmedi. Pencere hâlâ açıksa adımları bitirin: portföy, WhatsApp Business'taki numara, telefondaki onay ve son ekranda "Bitti". Sonuç gelince burası kendiliğinden güncellenir.</p>
    @endif
    @if(filled($signupAttempt->details['message'] ?? null) && $signupAttempt->status !== 'running')
        <div class="mt-1">@include('livewire.operator.whatsapp.error-detail', ['error' => $signupAttempt->details, 'label' => null])</div>
    @endif
    @if($attemptBusy && $signupAttempt->updated_at->lessThan(now()->subMinutes(3)))
        <p class="mt-1">Beklenenden uzun sürdü. Kuyruk hizmetini (Horizon) kontrol edin.</p>
    @endif
    @if($attemptTrace->isNotEmpty())
        <details class="mt-2 text-xs opacity-90" wire:ignore.self data-wa-trace>
            <summary class="cursor-pointer">Bağlantı sayfasının kaydı ({{ $attemptTrace->count() }})</summary>
            <ol class="mt-1 space-y-0.5">
                @foreach($attemptTrace as $entry)
                    <li><span class="font-mono">{{ filled($entry['at'] ?? null) ? \Carbon\CarbonImmutable::parse($entry['at'])->timezone('Europe/Istanbul')->format('H:i:s') : '—' }}</span> · {{ \App\Services\WhatsApp\WhatsAppErrorText::TRACE[$entry['event'] ?? ''] ?? ($entry['event'] ?? '') }}@if(filled($entry['note'] ?? null)) <span class="opacity-75">({{ $entry['note'] }})</span>@endif</li>
                @endforeach
            </ol>
        </details>
    @endif
</div>
