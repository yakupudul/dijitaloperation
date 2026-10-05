@php [$attemptTone, $attemptText] = $attemptLabels[$signupAttempt->status] ?? ['muted', $signupAttempt->status]; @endphp
<div class="rounded-lg p-3 text-sm ring-1 ring-inset {{ $tone[$attemptTone] }}" data-wa-attempt="{{ $signupAttempt->status }}">
    <p class="font-medium">Son deneme: {{ $attemptText }} <span class="font-normal opacity-75">· {{ $when($signupAttempt->updated_at) }}</span></p>
    @if($signupAttempt->status === 'running')
        <p class="mt-1">{{ $runningSteps[$signupAttempt->step] ?? 'Bağlantı hazırlanıyor' }}. Sayfayı açık tutmanız gerekmez.</p>
    @elseif($signupAttempt->status === 'prepared')
        <p class="mt-1">Facebook penceresinden MoxDOP'a hiçbir sonuç dönmedi (pencere kapatıldı ya da adımlar bitmedi). Tekrar deneyin; bu kez pencere kapanırsa sebebi burada yazar.</p>
    @endif
    @if(!empty($signupAttempt->details) && $signupAttempt->status !== 'running')
        <div class="mt-1">@include('livewire.operator.whatsapp.error-detail', ['error' => $signupAttempt->details, 'label' => null])</div>
    @endif
    @if($attemptBusy && $signupAttempt->updated_at->lessThan(now()->subMinutes(3)))
        <p class="mt-1">Beklenenden uzun sürdü. Kuyruk hizmetini (Horizon) kontrol edin.</p>
    @endif
</div>
