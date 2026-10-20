{{-- One epikriz line: value, where it comes from, how sure, when seen, how often used; Düzelt / Doğru / Yanlış for the Admin. --}}
@php
    $tone = match (true) {
        $item->status === 'conflict' => 'error',
        $item->status === 'expired' => 'warn',
        $item->confidence === 'verified' => 'verified',
        default => 'neutral',
    };
    $state = match ($item->status) {
        'conflict' => 'Çelişkili',
        'expired' => 'Süresi doldu',
        default => $confidence[$item->confidence] ?? $item->confidence,
    };
    $link = $source($item);
@endphp
<div class="group" data-epicrisis-item="{{ $item->id }}" data-epicrisis-state="{{ $item->status }}" data-epicrisis-confidence="{{ $item->confidence }}">
    @if ($editing === $item->id)
        <div class="flex flex-wrap gap-2">
            <textarea wire:model="editText" rows="2" aria-label="Düzelt" class="min-w-64 flex-1 rounded-lg border border-gray-200 bg-white px-3 py-2 text-[13px] focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-gray-200"></textarea>
            <div class="flex items-start gap-2">
                <button type="button" wire:click="saveEdit" class="btn-primary h-7! px-2.5! text-xs!">Kaydet ve kilitle</button>
                <button type="button" wire:click="cancelEdit" class="btn h-7! px-2.5! text-xs!">Vazgeç</button>
            </div>
        </div>
        @error('editText') <p class="mt-1 text-error-600">{{ $message }}</p> @enderror
    @else
        <p class="text-[13px] text-gray-800 dark:text-gray-200">
            @if (! empty($label))
                <span class="font-medium text-gray-900 dark:text-white">{{ $item->fieldLabel() }}:</span>
            @endif
            @if ($item->scope)
                <span class="text-gray-500">{{ $item->scope }}:</span>
            @endif
            {{ $item->value }}
            @if (data_get($item->detail, 'answer'))
                <span class="block text-gray-600 dark:text-gray-400">Cevap: {{ data_get($item->detail, 'answer') }}</span>
            @endif
        </p>
        <p class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-gray-500">
            <span class="inline-flex items-center gap-1.5 {{ $badge($tone) }}"><span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>{{ $state }}</span>
            <span>
                @if ($link)
                    <a href="{{ $link }}" target="_blank" rel="noopener" class="hover:underline">{{ $item->sourceLabel() }}</a>
                @else
                    {{ $item->sourceLabel() }}{{ $item->source_ref && ! str_starts_with((string) $item->source_ref, 'http') ? ', '.$item->source_ref : '' }}
                @endif
            </span>
            @if ($item->seen_at)
                <span>{{ $item->seen_at->timezone('Europe/Istanbul')->format('d.m.Y') }}</span>
            @endif
            @if ($item->used_count > 0)
                <span>{{ $item->used_count }} kez kullanıldı</span>
            @endif
            @if ($item->locked)
                <span>kilitli</span>
            @endif
            @if ($isAdmin)
                <span class="flex gap-2 opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100 [@media(hover:none)]:opacity-100">
                    <button type="button" wire:click="edit({{ $item->id }})" class="font-medium text-gray-600 hover:underline dark:text-gray-300">Düzelt</button>
                    @if (! $item->locked)
                        <button type="button" wire:click="confirm({{ $item->id }})" class="font-medium text-gray-600 hover:underline dark:text-gray-300" data-epicrisis-confirm>Doğru</button>
                    @endif
                    <button type="button" wire:click="reject({{ $item->id }})" wire:confirm="Bu bilgi bir daha hiçbir AI işinde kullanılmasın mı?" class="font-medium text-gray-500 hover:underline" data-epicrisis-reject>Yanlış</button>
                </span>
            @endif
        </p>
    @endif
</div>
