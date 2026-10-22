@php
    $labels = ['queue' => 'Sırada', 'run' => 'Çalışıyor', 'done' => 'Bitti', 'err' => 'Hata var'];
    $tones = [
        'queue' => 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300',
        'run' => 'bg-live-50 text-live-700 dark:bg-live-500/15 dark:text-live-300',
        'done' => 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-400',
        'err' => 'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-300',
    ];
@endphp
<span class="inline-flex h-6 items-center gap-1.5 justify-self-start whitespace-nowrap rounded-full px-2.5 text-xs font-semibold {{ $tones[$state] ?? $tones['queue'] }}" data-flow-state="{{ $state }}">
    <i @class(['h-1.5 w-1.5 rounded-full bg-current', 'animate-pulse' => $state === 'run'])></i>{{ $label ?? $labels[$state] ?? $state }}
</span>
