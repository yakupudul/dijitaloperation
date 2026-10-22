{{-- Akış row icon (prototype): web blue-violet, profile pink, content yellow, ads amber, AI gray. --}}
@php
    $icons = [
        'web' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 010 18M12 3a14 14 0 000 18"/>',
        'gbp' => '<path d="M12 21s-7-6.2-7-11a7 7 0 0114 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'ads' => '<path d="M3 17l6-6 4 4 8-8M15 7h6v6"/>',
        'icerik' => '<path d="M4 4h12l4 4v12H4z"/><path d="M8 12h8M8 16h5M8 8h4"/>',
        'ai' => '<path d="M12 3l1.8 4.6L18.5 9l-4.7 1.4L12 15l-1.8-4.6L5.5 9l4.7-1.4z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>',
    ];
    $tones = [
        'web' => 'bg-live-50 text-live-600 dark:bg-live-500/15 dark:text-live-300',
        'gbp' => 'bg-pink-50 text-pink-600 dark:bg-pink-500/15 dark:text-pink-300',
        'icerik' => 'bg-yellow-50 text-yellow-700 dark:bg-yellow-500/15 dark:text-yellow-300',
        'ads' => 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-300',
        'ai' => 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300',
    ];
@endphp
<span class="grid h-[34px] w-[34px] shrink-0 place-items-center rounded-[10px] {{ $tones[$kind] ?? $tones['web'] }}" aria-hidden="true">
    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $icons[$kind] ?? $icons['web'] !!}</svg>
</span>
