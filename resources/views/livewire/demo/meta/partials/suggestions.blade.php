{{-- Meta suggestions: label, reason, copyable instruction, buttons. $items, $panel, $editId --}}
<section data-testid="meta-suggestions">
    <div class="section-head">
        <h2 class="section-title">Açık öneriler <span class="section-count">{{ count($items) }}</span></h2>
    </div>
    <div class="card">
    @forelse ($items as $s)
        @php
            $action = $s->action ?? [];
            $text = (string) ($action['text'] ?? '');
            $locked = (bool) ($action['locked'] ?? false);
            $quiet = 'btn-quiet h-7! px-2! text-xs!';
        @endphp
        <div class="border-t border-gray-200 px-4 py-3.5 first:border-t-0 dark:border-gray-800" wire:key="sug-{{ $s->id }}">
            <div class="flex flex-wrap items-start gap-x-3.5 gap-y-2 sm:flex-nowrap">
                <div class="min-w-0 flex-1">
                    <p class="break-words text-sm font-medium text-gray-900 dark:text-white">{{ $s->title }}@if ($locked) <span class="ml-1 text-xs font-normal text-gray-500">elle düzenlendi</span>@endif</p>
                    <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $s->reason }}</p>
                </div>
                <div class="flex w-full shrink-0 flex-wrap items-center gap-1 sm:w-auto sm:justify-end">
                    <button type="button" wire:click="approveSuggestion({{ $s->id }})" class="btn h-7! px-2.5! text-xs!">Onayla</button>
                    <button type="button" wire:click="startEdit({{ $s->id }})" class="{{ $quiet }}">Düzenle</button>
                    <button type="button" wire:click="dismissSuggestion({{ $s->id }})" class="{{ $quiet }}">Reddet</button>
                    <button type="button" wire:click="snoozeSuggestion({{ $s->id }})" class="{{ $quiet }}">Ertele</button>
                </div>
            </div>
            @if ($editId === $s->id)
                <div class="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-white/[0.02]">
                    <textarea wire:model="editText" rows="5" aria-label="Metin" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"></textarea>
                    <div class="mt-2 flex gap-2">
                        <button type="button" wire:click="saveEdit" class="btn-primary h-7! px-2.5! text-xs!">Kaydet</button>
                        <button type="button" wire:click="cancelEdit" class="{{ $quiet }}">Vazgeç</button>
                    </div>
                </div>
            @elseif ($text !== '')
                <div class="mt-2.5 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-[13px] dark:border-gray-800 dark:bg-white/[0.02]" x-data="{ text: @js($text) }">
                    <p class="whitespace-pre-line break-words text-gray-800 dark:text-gray-200">{{ $text }}</p>
                    <button type="button" x-on:click="navigator.clipboard.writeText(text)" class="link-neutral mt-1.5 text-xs">Kopyala</button>
                </div>
            @endif
            @if (is_array($action['change_proposal'] ?? null))
                <div class="mt-2.5 text-[13px]">
                    <p class="text-xs text-warning-700 dark:text-warning-300">Değişiklik önerisi</p>
                    <p class="mt-1 whitespace-pre-line text-gray-800 dark:text-gray-200">{{ $action['change_proposal']['text'] ?? '' }}</p>
                    <button type="button" wire:click="takeProposal({{ $s->id }})" class="link-neutral mt-1 text-xs">Öneriyi al</button>
                </div>
            @endif
            @if (($s->evidence ?? []) !== [])
                <details class="mt-1.5 text-xs text-gray-500">
                    <summary class="cursor-pointer hover:text-gray-900 dark:hover:text-white">Kanıt</summary>
                    @foreach ($s->evidence as $row)
                        <p class="mt-0.5">@foreach ((array) $row as $k => $v){{ str_replace('_', ' ', (string) $k) }}: {{ is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE) }}@if (! $loop->last), @endif @endforeach</p>
                    @endforeach
                </details>
            @endif
        </div>
    @empty
        <x-ta.empty-state size="sm" title="Açık öneri yok." />
    @endforelse
    </div>
</section>
