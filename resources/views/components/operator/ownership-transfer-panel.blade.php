@props(['conflict', 'action', 'canTransfer' => false, 'cancel' => 'cancelOwnershipTransfer', 'button' => 'Devret'])
@php
    $bold = fn (string $text): string => preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', e($text)) ?? e($text);
    $isResource = ($conflict['subject_type'] ?? '') === 'resource';
@endphp
<div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100" data-ownership-transfer-panel>
    <p class="font-semibold">{{ $conflict['same_customer'] ? 'Aynı müşteri içinde taşıma' : 'Yetki devri gerekiyor' }}</p>
    <p class="mt-1">{!! $bold((string) $conflict['message']) !!}</p>

    <dl class="mt-3 grid gap-2 sm:grid-cols-2">
        <div class="rounded-lg bg-white/70 p-3 dark:bg-white/[0.04]">
            <dt class="text-xs font-medium uppercase tracking-wide text-amber-700 dark:text-amber-300">Şu an</dt>
            <dd class="mt-1">{{ $conflict['from_label'] }}</dd>
        </div>
        <div class="rounded-lg bg-white/70 p-3 dark:bg-white/[0.04]">
            <dt class="text-xs font-medium uppercase tracking-wide text-amber-700 dark:text-amber-300">Devredildikten sonra</dt>
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
            <input type="checkbox" wire:model="transferAcknowledged" class="mt-0.5 rounded border-amber-400">
            <span>Yetki devrini onaylıyorum</span>
        </label>
        @error('transferAcknowledged')
            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
        @enderror
        <input type="text" wire:model="transferNote" maxlength="500" placeholder="Not (isteğe bağlı): neden devrediliyor?" aria-label="Devir notu"
            class="mt-2 w-full rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm text-gray-900 dark:border-amber-500/30 dark:bg-gray-900 dark:text-white">

        <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" wire:click="{{ $action }}" wire:loading.attr="disabled" class="rounded-lg bg-amber-600 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-700 disabled:opacity-60">{{ $button }}</button>
            <button type="button" wire:click="{{ $cancel }}" class="rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-amber-300 hover:bg-amber-100 dark:ring-amber-500/40">Vazgeç</button>
        </div>
    @else
        <p class="mt-3">Yetki devrini yalnız Admin yapabilir.</p>
        <button type="button" wire:click="{{ $cancel }}" class="mt-2 rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-amber-300">Kapat</button>
    @endif
</div>
