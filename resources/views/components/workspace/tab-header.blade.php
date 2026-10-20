@props(['channel', 'lastRun' => null, 'notice' => '', 'noticeTone' => 'success', 'missing' => null, 'canRun' => true])
{{-- One line: last analysis + "Yeniden analiz et"; then the result line and the one-line "Veri yok" state. --}}
<div class="space-y-2">
    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
        <span data-workspace-last-run>
            @if ($lastRun === null)
                Henüz analiz yok.
            @elseif ($lastRun['active'])
                Analiz sırada…
            @elseif ($lastRun['status'] === 'failed')
                Son analiz başarısız ({{ $lastRun['at'] }}).
            @elseif ($lastRun['status'] === 'skipped')
                Son analiz atlandı ({{ $lastRun['at'] }}): {{ $lastRun['error'] }}
            @else
                Son analiz: {{ $lastRun['at'] }}
            @endif
        </span>
        @if ($canRun)
            <button type="button" wire:click="reanalyze('{{ $channel }}')" wire:loading.attr="disabled" class="inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/5">Yeniden analiz et</button>
        @endif
    </div>
    @if ($notice !== '')
        <p @class(['rounded-lg px-3 py-2 text-sm', 'tone-ok' => $noticeTone !== 'error', 'tone-critical' => $noticeTone === 'error']) role="status">{{ $notice }}</p>
    @endif
    @if (filled($missing))
        <p class="text-sm text-gray-500 dark:text-gray-400" data-workspace-missing>{{ $missing }}</p>
    @endif
</div>
