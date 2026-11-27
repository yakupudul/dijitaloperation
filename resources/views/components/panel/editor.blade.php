@props(['model', 'placeholder' => 'Mesajınızı yazın', 'label' => 'Mesaj'])
{{-- The message editor: bold, italic, lists, links. Writes HTML into the Livewire property named by `model`. --}}
<div x-data="richEditor(@js($model))" wire:ignore {{ $attributes->class('rounded-[10px] border border-line bg-white focus-within:border-primary') }} data-rich-editor>
    <div class="flex flex-wrap items-center gap-0.5 border-b border-line px-1.5 py-1">
        <button type="button" x-on:mousedown.prevent x-on:click="run('bold')" class="grid h-8 w-8 place-items-center rounded-md text-[15px] font-bold text-gray-700 hover:bg-soft" aria-label="Kalın">K</button>
        <button type="button" x-on:mousedown.prevent x-on:click="run('italic')" class="grid h-8 w-8 place-items-center rounded-md text-[15px] italic text-gray-700 hover:bg-soft" aria-label="Eğik">E</button>
        <button type="button" x-on:mousedown.prevent x-on:click="run('insertUnorderedList')" class="grid h-8 w-8 place-items-center rounded-md text-gray-700 hover:bg-soft" aria-label="Madde listesi">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 7h11M9 12h11M9 17h11M4.5 7h.01M4.5 12h.01M4.5 17h.01"/></svg>
        </button>
        <button type="button" x-on:mousedown.prevent x-on:click="run('insertOrderedList')" class="grid h-8 w-8 place-items-center rounded-md text-gray-700 hover:bg-soft" aria-label="Numaralı liste">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 7h10M10 12h10M10 17h10M4 5.5h1.500v4M4 14.500h2l-2 3h2"/></svg>
        </button>
        <button type="button" x-on:mousedown.prevent x-on:click="askLink()" class="grid h-8 w-8 place-items-center rounded-md text-gray-700 hover:bg-soft" aria-label="Bağlantı ekle">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.700 0l3-3a4 4 0 0 0-5.700-5.700l-1 1"/><path d="M14 10a4 4 0 0 0-5.700 0l-3 3a4 4 0 0 0 5.700 5.700l1-1"/></svg>
        </button>
        {{ $slot }}
    </div>
    <div x-show="linking" x-cloak class="flex items-center gap-2 border-b border-line bg-soft px-2 py-1.5">
        <input type="url" x-ref="url" x-model="url" x-on:keydown.enter.prevent="addLink()" x-on:keydown.escape.prevent="linking = false" placeholder="https://" class="h-8 min-w-0 flex-1 rounded-md border border-line bg-white px-2 text-sm outline-none focus:border-primary" aria-label="Bağlantı adresi">
        <button type="button" x-on:click="addLink()" class="btn h-8!">Ekle</button>
    </div>
    <div x-ref="box" contenteditable="true" role="textbox" aria-multiline="true" aria-label="{{ $label }}" data-placeholder="{{ $placeholder }}"
        x-on:input="sync()" x-on:blur="sync()" x-on:paste.prevent="paste($event)"
        class="rich-text min-h-[96px] px-3 py-2.5 text-base text-gray-900 outline-none sm:text-[15px]"></div>
</div>
