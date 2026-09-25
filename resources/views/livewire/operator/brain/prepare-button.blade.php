<div class="inline-flex flex-wrap items-center gap-2 text-xs">
    <button type="button" wire:click="prepare" wire:loading.attr="disabled" title="{{ $label }}"
        class="inline-flex items-center gap-1 rounded-lg bg-brand-50 px-3 py-1.5 font-medium text-brand-700 ring-1 ring-inset ring-brand-200 hover:bg-brand-100 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/30">
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l1.8 4.2L18 9l-4.2 1.8L12 15l-1.8-4.2L6 9l4.2-1.8z"/></svg>
        {{ $ai ? 'AI ile hazırla' : 'Hazırla' }}: {{ $label }}
    </button>
    @if ($message || $state === 'running')
        <span class="text-gray-500">{{ $message ?? 'Hazırlanıyor…' }}</span>
    @elseif (str_starts_with((string) $state, 'done: '))
        <span class="text-success-600">{{ substr($state, 6) }} {{ $noun }}</span>
    @elseif (str_starts_with((string) $state, 'failed: '))
        <span class="text-error-600">Hata: {{ \Illuminate\Support\Str::limit(substr($state, 8), 80) }}</span>
    @endif
    <a href="{{ route('operator.brain.proposals', ['kind' => $kind]) }}" wire:navigate class="text-brand-600 hover:underline">Onay kuyruğu →</a>
</div>
