{{-- Meta suggestions: label · reason · copyable instruction · buttons. $items, $panel, $editId --}}
<section class="{{ $panel }} divide-y divide-gray-100 dark:divide-gray-700" data-testid="meta-suggestions">
    @forelse ($items as $s)
        @php
            $action = $s->action ?? [];
            $text = (string) ($action['text'] ?? '');
            $locked = (bool) ($action['locked'] ?? false);
        @endphp
        <div class="px-4 py-3" wire:key="sug-{{ $s->id }}">
            <div class="flex flex-wrap items-start gap-2">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $s->title }}@if ($locked) <span class="ml-1 rounded bg-gray-100 px-1.5 text-[11px] font-medium text-gray-600 dark:bg-white/[0.06] dark:text-gray-300">elle düzenlendi</span>@endif</p>
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ $s->reason }}</p>
                </div>
                <div class="flex shrink-0 gap-1.5">
                    <button type="button" wire:click="approveSuggestion({{ $s->id }})" class="rounded bg-success-500 px-2 py-1 text-xs font-semibold text-white hover:bg-success-600">Onayla</button>
                    <button type="button" wire:click="startEdit({{ $s->id }})" class="rounded px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Düzenle</button>
                    <button type="button" wire:click="dismissSuggestion({{ $s->id }})" class="rounded px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Reddet</button>
                    <button type="button" wire:click="snoozeSuggestion({{ $s->id }})" class="rounded px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Ertele</button>
                </div>
            </div>
            @if ($editId === $s->id)
                <div class="mt-2">
                    <textarea wire:model="editText" rows="5" aria-label="Metin" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></textarea>
                    <div class="mt-1 flex gap-2">
                        <button type="button" wire:click="saveEdit" class="rounded bg-brand-500 px-2 py-1 text-xs font-semibold text-white">Kaydet</button>
                        <button type="button" wire:click="cancelEdit" class="rounded px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700">Vazgeç</button>
                    </div>
                </div>
            @elseif ($text !== '')
                <div class="mt-2 rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/[0.03]" x-data="{ text: @js($text) }">
                    <p class="whitespace-pre-line text-gray-800 dark:text-gray-200">{{ $text }}</p>
                    <button type="button" x-on:click="navigator.clipboard.writeText(text)" class="mt-1 text-xs font-semibold text-brand-600 hover:underline">Kopyala</button>
                </div>
            @endif
            @if (is_array($action['change_proposal'] ?? null))
                <div class="mt-2 rounded-lg bg-amber-50 p-3 text-sm dark:bg-amber-500/10">
                    <p class="text-xs font-semibold text-amber-800 dark:text-amber-200">Değişiklik önerisi</p>
                    <p class="mt-1 whitespace-pre-line text-gray-800 dark:text-gray-200">{{ $action['change_proposal']['text'] ?? '' }}</p>
                    <button type="button" wire:click="takeProposal({{ $s->id }})" class="mt-1 text-xs font-semibold text-brand-600 hover:underline">Öneriyi al</button>
                </div>
            @endif
            @if (($s->evidence ?? []) !== [])
                <details class="mt-1 text-xs text-gray-500">
                    <summary class="cursor-pointer">Kanıt</summary>
                    @foreach ($s->evidence as $row)
                        <p class="mt-0.5">@foreach ((array) $row as $k => $v){{ str_replace('_', ' ', (string) $k) }}: {{ is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE) }}@if (! $loop->last) · @endif @endforeach</p>
                    @endforeach
                </details>
            @endif
        </div>
    @empty
        <p class="px-4 py-5 text-sm text-gray-500">Açık öneri yok.</p>
    @endforelse
</section>
