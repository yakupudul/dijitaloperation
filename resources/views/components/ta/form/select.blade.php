@props([
    'options' => [],
    'placeholder' => 'Select…',
    'searchable' => true,
    'nullable' => true,
    'disabled' => false,
    'allowCustom' => false,
    'id' => null,
])

@php
    $fieldId = $id ?? 'select-'.uniqid();
    $optionsJson = collect($options)->map(fn ($label, $value) => ['value' => (string) $value, 'label' => (string) $label])->values()->all();
@endphp

<div
    {{ $attributes->whereStartsWith('class')->merge(['class' => 'relative']) }}
    x-data="{
        open: false,
        search: '',
        options: {{ Js::from($optionsJson) }},
        searchable: {{ $searchable ? 'true' : 'false' }},
        allowCustom: {{ $allowCustom ? 'true' : 'false' }},
        nullable: {{ $nullable ? 'true' : 'false' }},
        disabled: {{ $disabled ? 'true' : 'false' }},
        placeholder: {{ Js::from($placeholder) }},
        value: @entangle($attributes->wire('model')).live,
        get selectedLabel() {
            if (this.value === null || this.value === '') return '';
            const hit = this.options.find(o => o.value === String(this.value));
            return hit ? hit.label : String(this.value);
        },
        get filtered() {
            const q = this.search.trim().toLowerCase();
            if (!q) return this.options;
            return this.options.filter(o =>
                o.label.toLowerCase().includes(q) || o.value.toLowerCase().includes(q)
            );
        },
        select(val) {
            this.value = val;
            this.open = false;
            this.search = '';
        },
        clear() {
            if (!this.nullable || this.disabled) return;
            this.value = '';
            this.search = '';
        },
        commitCustom() {
            if (!this.allowCustom) return;
            const q = this.search.trim();
            if (q === '') return;
            this.value = q;
            this.open = false;
            this.search = '';
        },
        onKey(e) {
            if (e.key === 'Escape') { this.open = false; return; }
            if (e.key === 'Enter' && this.allowCustom && this.search.trim() !== '') {
                e.preventDefault();
                this.commitCustom();
            }
        }
    }"
    @click.outside="open = false"
    wire:ignore.self
>
    <button
        type="button"
        id="{{ $fieldId }}"
        @click="if (!disabled) open = !open"
        @keydown="onKey"
        :disabled="disabled"
        :aria-expanded="open.toString()"
        class="flex w-full items-center justify-between gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-left text-sm text-gray-800 shadow-theme-xs outline-none transition focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800"
    >
        <span class="truncate" :class="selectedLabel ? 'text-gray-800 dark:text-white/90' : 'text-gray-400'">
            <span x-text="selectedLabel || placeholder"></span>
        </span>
        <span class="flex shrink-0 items-center gap-1 text-gray-400">
            <template x-if="nullable && selectedLabel && !disabled">
                <span
                    role="button"
                    tabindex="0"
                    class="rounded p-0.5 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/10"
                    @click.stop="clear()"
                    @keydown.enter.stop.prevent="clear()"
                    aria-label="Clear selection"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 14 14" fill="none"><path d="M3.5 3.5l7 7M10.5 3.5l-7 7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                </span>
            </template>
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
        </span>
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.100ms
        class="absolute z-40 mt-1 max-h-64 w-full overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-900"
        role="listbox"
    >
        <template x-if="searchable">
            <div class="border-b border-gray-100 p-2 dark:border-gray-800">
                <input
                    type="search"
                    x-model="search"
                    @keydown="onKey"
                    placeholder="Search…"
                    class="w-full rounded-md border border-gray-200 bg-transparent px-2.5 py-1.5 text-sm outline-none focus:border-brand-300 dark:border-gray-700 dark:text-white/90"
                />
            </div>
        </template>
        <ul class="max-h-52 overflow-y-auto py-1">
            <template x-for="opt in filtered" :key="opt.value">
                <li>
                    <button
                        type="button"
                        class="flex w-full items-center justify-between px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/[0.04]"
                        :class="String(value) === String(opt.value) ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300' : ''"
                        @click="select(opt.value)"
                        role="option"
                    >
                        <span x-text="opt.label"></span>
                        <span class="ml-2 shrink-0 text-[10px] uppercase text-gray-400" x-text="opt.value !== opt.label ? opt.value : ''"></span>
                    </button>
                </li>
            </template>
            <li x-show="filtered.length === 0 && !allowCustom" class="px-3 py-2 text-sm text-gray-400">No matches</li>
            <li x-show="filtered.length === 0 && allowCustom && search.trim() !== ''">
                <button type="button" class="w-full px-3 py-2 text-left text-sm text-brand-600 hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-500/10" @click="commitCustom()">
                    Use “<span x-text="search.trim()"></span>”
                </button>
            </li>
        </ul>
    </div>
</div>
