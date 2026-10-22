{{-- Top bar pill (approved prototype): what runs and what finished today; one click opens Akış. --}}
<a href="{{ route('operator.flow') }}" wire:navigate @if ($counts['run'] > 0) wire:poll.10s.visible @else wire:poll.60s.visible @endif
    class="inline-flex h-[30px] items-center gap-2 rounded-full border border-gray-200 bg-gray-50 pl-2.5 pr-3 text-[13px] text-gray-600 hover:bg-gray-100 dark:border-gray-800 dark:bg-white/[0.04] dark:text-gray-300 dark:hover:bg-white/[0.08]"
    aria-label="Akışa git: {{ $counts['run'] }} iş çalışıyor, bugün {{ $counts['done'] }} iş bitti" data-flow-pill>
    <span @class(['h-2 w-2 shrink-0 rounded-full', 'live-dot' => $counts['run'] > 0, 'bg-error-500' => $counts['run'] === 0 && $counts['err'] > 0, 'bg-success-500' => $counts['run'] === 0 && $counts['err'] === 0])></span>
    <b class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($counts['run'], 0, ',', '.') }}</b><span class="hidden sm:inline">çalışıyor</span>
    <span class="hidden text-gray-300 sm:inline dark:text-gray-600">·</span>
    <span class="hidden tabular-nums sm:inline">{{ number_format($counts['done'], 0, ',', '.') }} bitti</span>
</a>
