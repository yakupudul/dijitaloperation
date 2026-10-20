{{--
    Data status in the one-line asset page head: a dot and the worst state ("Güncel", "Gecikmiş · 3 gün"); every source's line
    on hover (title) and in the small panel with the reconnect / bind links and (ad accounts) the pause toggle; one "Yenile".
--}}
<div class="flex min-w-0 flex-wrap items-center gap-2 text-xs" @if ($polling) wire:poll.30s @endif data-data-status-line>
    @php
        $dots = ['ok' => 'bg-success-500', 'warn' => 'bg-warning-500', 'bad' => 'bg-error-500'];
        $texts = ['ok' => 'text-gray-700 dark:text-gray-200', 'warn' => 'text-warning-800 dark:text-warning-300', 'bad' => 'text-error-700 dark:text-error-300'];
        $tone = $worst?->tone() ?? 'muted';
        $hover = collect($statuses)->map(fn ($s) => $s->sourceLabel().': '.collect([$s->label(), $s->detail()])->filter()->implode(', '))->implode("\n");
        $collecting = collect($statuses)->contains(fn ($s) => $s->collecting);
        $pause = $asset && in_array((string) $asset->type, ['google_ads', 'meta_ads'], true);
    @endphp
    @if (count($statuses) > 0 || $pause)
        <details class="relative" data-data-status-menu x-data @click.outside="$el.removeAttribute('open')">
            <summary class="flex h-8 cursor-pointer list-none items-center gap-1.5 rounded-md px-2 hover:bg-gray-100 dark:hover:bg-white/[0.06]" title="{{ __('data_status.title', [], 'tr') }}&#10;{{ $hover }}" aria-label="{{ __('data_status.title', [], 'tr') }}">
                <span @class(['h-2 w-2 shrink-0 rounded-full', $dots[$tone] ?? 'bg-gray-300 dark:bg-gray-600', 'animate-pulse' => $collecting]) aria-hidden="true"></span>
                @if ($worst)<span class="font-medium {{ $texts[$tone] ?? 'text-gray-600 dark:text-gray-300' }}">{{ $worst->label() }}</span>@endif
            </summary>
            <div class="absolute right-0 z-40 mt-1 w-80 max-w-[calc(100vw-2rem)] space-y-3 rounded-xl border border-gray-200 bg-white p-3 shadow-lg dark:border-gray-700 dark:bg-gray-900">
                <x-operator.data-status :statuses="$statuses" panel />
                @if ($pause)
                    <div class="border-t border-gray-100 pt-2 dark:border-gray-800"><x-operator.activity-pause :asset="$asset" /></div>
                @endif
            </div>
        </details>
    @endif
    @if ($canRefresh)
        <button type="button" wire:click="refreshAll" wire:loading.attr="disabled" class="btn h-8! px-3! text-[13px]! disabled:opacity-60" title="Bağlı kaynakların verisini şimdi çek" data-data-status-refresh>
            <span wire:loading.remove wire:target="refreshAll">Yenile</span><span wire:loading wire:target="refreshAll">Yenileniyor…</span>
        </button>
    @endif
    @if ($feedback !== '')
        <p @class(['min-w-0 text-xs', 'text-success-700 dark:text-success-300' => $feedbackTone === 'success', 'text-warning-700 dark:text-warning-300' => $feedbackTone !== 'success']) role="status">{{ $feedback }}</p>
    @endif
</div>
