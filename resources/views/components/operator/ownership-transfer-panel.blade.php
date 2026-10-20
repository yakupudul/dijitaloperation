@props(['conflict', 'action', 'canTransfer' => false, 'cancel' => 'cancelOwnershipTransfer', 'button' => 'Devret'])
@php
    $bold = fn (string $text): string => preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', e($text)) ?? e($text);
    $isResource = ($conflict['subject_type'] ?? '') === 'resource';
@endphp
<div class="tone-look rounded-lg p-4 text-sm" data-ownership-transfer-panel>
    <p class="font-semibold">{{ $conflict['same_customer'] ? 'Aynı müşteri içinde taşıma' : 'Yetki devri gerekiyor' }}</p>
    <p class="mt-1">{!! $bold((string) $conflict['message']) !!}</p>

    <dl class="mt-3 grid gap-2 sm:grid-cols-2">
        <div>
            <dt class="text-xs opacity-80">Şu an</dt>
            <dd class="mt-0.5 font-medium">{{ $conflict['from_label'] }}</dd>
        </div>
        <div>
            <dt class="text-xs opacity-80">Devredildikten sonra</dt>
            <dd class="mt-0.5 font-medium">{{ $conflict['to_label'] }}</dd>
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
            <button type="button" wire:click="{{ $action }}" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-60">{{ $button }}</button>
            <button type="button" wire:click="{{ $cancel }}" class="rounded-lg px-3 py-2 text-sm font-medium hover:underline">Vazgeç</button>
        </div>
    @else
        <p class="mt-3">Yetki devrini yalnız Admin yapabilir.</p>
        <button type="button" wire:click="{{ $cancel }}" class="mt-2 rounded-lg px-3 py-2 text-sm font-medium hover:underline">Kapat</button>
    @endif
</div>
