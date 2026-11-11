{{-- Tek sağlık lambası (Günlük Düzen 2.4): one light instead of machine counters. Green = quiet, yellow = work is running or
     waiting, red = something failed today. One click opens Akış, where the numbers live. --}}
@php
    $tone = $counts['err'] > 0 ? 'bad' : ($counts['run'] > 0 || $counts['queue'] > 0 ? 'busy' : 'ok');
    $word = ['bad' => 'Hata var', 'busy' => 'Çalışıyor', 'ok' => 'Sakin'][$tone];
    $detail = number_format($counts['run'], 0, ',', '.').' iş çalışıyor, '.number_format($counts['queue'], 0, ',', '.').' sırada, bugün '.number_format($counts['done'], 0, ',', '.').' bitti'
        .($counts['err'] > 0 ? ', '.number_format($counts['err'], 0, ',', '.').' hata' : '');
@endphp
<a href="{{ route('operator.flow') }}" wire:navigate
    class="inline-flex h-[30px] items-center gap-2 rounded-full border border-gray-200 bg-gray-50 px-2.5 text-[13px] text-gray-700 hover:bg-gray-100 sm:pr-3 dark:border-gray-800 dark:bg-white/[0.04] dark:text-gray-300 dark:hover:bg-white/[0.08]"
    aria-label="Akışa git. {{ $word }}: {{ $detail }}" title="{{ $detail }}" data-flow-pill data-flow-light="{{ $tone }}">
    <span @class(['h-2.5 w-2.5 shrink-0 rounded-full', 'bg-error-500' => $tone === 'bad', 'bg-warning-500' => $tone === 'busy', 'animate-pulse' => $tone === 'busy' && $counts['run'] > 0, 'bg-success-500' => $tone === 'ok'])></span>
    <span class="hidden sm:inline">{{ $word }}</span>
</a>
