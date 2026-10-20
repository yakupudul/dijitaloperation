{{-- Meta suggestions: label, reason, copyable instruction, buttons. $items, $panel, $editId --}}
<section class="divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800" data-testid="meta-suggestions">
    @forelse ($items as $s)
        @php
            $action = $s->action ?? [];
            $text = (string) ($action['text'] ?? '');
            $locked = (bool) ($action['locked'] ?? false);
        @endphp
        <div class="py-3" wire:key="sug-{{ $s->id }}">
            <div class="flex flex-wrap items-start gap-2">
                <div class="min-w-0 flex-1">
                    <p class="break-words text-sm font-medium text-gray-900 dark:text-white">{{ $s->title }}@if ($locked) <span class="ml-1 text-xs font-normal text-gray-500">elle düzenlendi</span>@endif</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $s->reason }}</p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-1.5">
                    <button type="button" wire:click="approveSuggestion({{ $s->id }})" class="rounded-md px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Onayla</button>
                    <button type="button" wire:click="startEdit({{ $s->id }})" class="rounded-md px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Düzenle</button>
                    <button type="button" wire:click="dismissSuggestion({{ $s->id }})" class="rounded-md px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Reddet</button>
                    <button type="button" wire:click="snoozeSuggestion({{ $s->id }})" class="rounded-md px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Ertele</button>
                </div>
            </div>
            @if ($editId === $s->id)
                <div class="mt-2">
                    <textarea wire:model="editText" rows="5" aria-label="Metin" class="w-full rounded-lg border-gray-200 text-sm dark:border-gray-700 dark:bg-gray-900"></textarea>
                    <div class="mt-1 flex gap-2">
                        <button type="button" wire:click="saveEdit" class="rounded-md bg-brand-600 px-2 py-1 text-xs font-medium text-white hover:bg-brand-500">Kaydet</button>
                        <button type="button" wire:click="cancelEdit" class="rounded-md px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Vazgeç</button>
                    </div>
                </div>
            @elseif ($text !== '')
                <div class="mt-2 text-sm" x-data="{ text: @js($text) }">
                    <p class="whitespace-pre-line break-words text-gray-800 dark:text-gray-200">{{ $text }}</p>
                    <button type="button" x-on:click="navigator.clipboard.writeText(text)" class="link-neutral mt-1 text-xs">Kopyala</button>
                </div>
            @endif
            @if (is_array($action['change_proposal'] ?? null))
                <div class="mt-2 text-sm">
                    <p class="text-xs text-warning-800 dark:text-warning-300">Değişiklik önerisi</p>
                    <p class="mt-1 whitespace-pre-line text-gray-800 dark:text-gray-200">{{ $action['change_proposal']['text'] ?? '' }}</p>
                    <button type="button" wire:click="takeProposal({{ $s->id }})" class="link-neutral mt-1 text-xs">Öneriyi al</button>
                </div>
            @endif
            @if (($s->evidence ?? []) !== [])
                <details class="mt-1 text-xs text-gray-500">
                    <summary class="cursor-pointer">Kanıt</summary>
                    @foreach ($s->evidence as $row)
                        <p class="mt-0.5">@foreach ((array) $row as $k => $v){{ str_replace('_', ' ', (string) $k) }}: {{ is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE) }}@if (! $loop->last), @endif @endforeach</p>
                    @endforeach
                </details>
            @endif
        </div>
    @empty
        <x-ta.empty-state size="sm" title="Açık öneri yok." />
    @endforelse
</section>
