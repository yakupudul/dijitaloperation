@props(['conflict', 'action', 'canTransfer' => false, 'cancel' => 'cancelOwnershipTransfer', 'button' => 'Devret'])
@php
    $bold = fn (string $text): string => preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', e($text)) ?? e($text);
    $isResource = ($conflict['subject_type'] ?? '') === 'resource';
@endphp
<div class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm text-warning-900 dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-100" data-ownership-transfer-panel>
    <p class="font-semibold">{{ $conflict['same_customer'] ? 'Aynı müşteri içinde taşıma' : 'Yetki devri gerekiyor' }}</p>
    <p class="mt-1">{!! $bold((string) $conflict['message']) !!}</p>

    <dl class="mt-3 grid gap-2 sm:grid-cols-2">
        <div class="rounded-lg bg-white/70 p-3 dark:bg-white/[0.04]">
            <dt class="text-xs font-medium uppercase tracking-wide text-warning-700 dark:text-warning-300">Şu an</dt>
            <dd class="mt-1">{{ $conflict['from_label'] }}</dd>
        </div>
        <div class="rounded-lg bg-white/70 p-3 dark:bg-white/[0.04]">
            <dt class="text-xs font-medium uppercase tracking-wide text-warning-700 dark:text-warning-300">Devredildikten sonra</dt>
            <dd class="mt-1">{{ $conflict['to_label'] }}</dd>
        </div>
    </dl>

    @if ($canTransfer)
        <p class="mt-3 font-medium">{{ $isResource ? 'Devredince ne olur?' : 'Taşıyınca ne olur?' }}</p>
        <ul class="mt-1 list-disc space-y-1 pl-5">
            @foreach ($conflict['consequences'] as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>

        <label class="mt-3 flex items-start gap-2">
            <input type="checkbox" wire:model="transferAcknowledged" class="mt-0.5 rounded border-warning-400">
            <span>Yetki devrini onaylıyorum</span>
        </label>
        @error('transferAcknowledged')
            <p class="mt-1 text-xs text-error-600">{{ $message }}</p>
        @enderror
        <input type="text" wire:model="transferNote" maxlength="500" placeholder="Not (isteğe bağlı): neden devrediliyor?" aria-label="Devir notu"
            class="mt-2 w-full rounded-lg border border-warning-200 bg-white px-3 py-2 text-sm text-gray-900 dark:border-warning-500/30 dark:bg-gray-900 dark:text-white">

        <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" wire:click="{{ $action }}" wire:loading.attr="disabled" class="rounded-lg bg-warning-600 px-3 py-2 text-sm font-semibold text-white hover:bg-warning-700 disabled:opacity-60">{{ $button }}</button>
            <button type="button" wire:click="{{ $cancel }}" class="rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-warning-300 hover:bg-warning-100 dark:ring-warning-500/40">Vazgeç</button>
        </div>
    @else
        <p class="mt-3">Yetki devrini yalnız Admin yapabilir.</p>
        <button type="button" wire:click="{{ $cancel }}" class="mt-2 rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-warning-300">Kapat</button>
    @endif
</div>
