@props([
    'options' => [],
    'placeholder' => 'Select…',
    'searchable' => true,
    'disabled' => false,
    'id' => null,
])

@php
    $fieldId = $id ?? 'multiselect-'.uniqid();
    $optionsJson = collect($options)->map(fn ($label, $value) => ['value' => (string) $value, 'label' => (string) $label])->values()->all();
@endphp

<div
    {{ $attributes->whereStartsWith('class')->merge(['class' => 'relative']) }}
    x-data="{
        open: false,
        search: '',
        options: {{ Js::from($optionsJson) }},
        searchable: {{ $searchable ? 'true' : 'false' }},
        disabled: {{ $disabled ? 'true' : 'false' }},
        placeholder: {{ Js::from($placeholder) }},
        value: @entangle($attributes->wire('model')).live,
        ensureArray() {
            if (!Array.isArray(this.value)) this.value = [];
        },
        labelFor(val) {
            const hit = this.options.find(o => o.value === String(val));
            return hit ? hit.label : String(val);
        },
        get filtered() {
            this.ensureArray();
            const q = this.search.trim().toLowerCase();
            const selected = this.value.map(String);
            return this.options.filter(o => {
                if (selected.includes(String(o.value))) return false;
                if (!q) return true;
                return o.label.toLowerCase().includes(q) || o.value.toLowerCase().includes(q);
            });
        },
        add(val) {
            this.ensureArray();
            if (!this.value.map(String).includes(String(val))) {
                this.value = [...this.value, String(val)];
            }
            this.search = '';
        },
        remove(val) {
            this.ensureArray();
            this.value = this.value.filter(v => String(v) !== String(val));
        },
        onKey(e) {
            if (e.key === 'Escape') this.open = false;
        }
    }"
    @click.outside="open = false"
    wire:ignore.self
>
    <div
        class="min-h-[42px] w-full rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 shadow-theme-xs outline-none transition focus-within:border-brand-300 focus-within:ring-3 focus-within:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900"
        :class="disabled ? 'opacity-60' : ''"
        @click="if (!disabled) open = true"
    >
        <div class="flex flex-wrap items-center gap-1.5">
            <template x-for="val in (Array.isArray(value) ? value : [])" :key="val">
                <span class="inline-flex max-w-full items-center gap-1 rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200">
                    <span class="truncate" x-text="labelFor(val)"></span>
                    <button type="button" class="shrink-0 text-gray-400 hover:text-gray-700 dark:hover:text-white" @click.stop="remove(val)" aria-label="Remove">
                        <svg class="h-3 w-3" viewBox="0 0 14 14" fill="none"><path d="M3.5 3.5l7 7M10.5 3.5l-7 7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                    </button>
                </span>
            </template>
            <input
                type="search"
                id="{{ $fieldId }}"
                x-model="search"
                @focus="if (!disabled) open = true"
                @keydown="onKey"
                :disabled="disabled"
                :placeholder="(Array.isArray(value) && value.length) ? '' : placeholder"
                class="min-w-[8rem] flex-1 border-0 bg-transparent px-1 py-1 text-sm text-gray-800 outline-none placeholder:text-gray-400 dark:text-white/90"
            />
        </div>
    </div>

    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.100ms
        class="absolute z-40 mt-1 max-h-64 w-full overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-900"
        role="listbox"
    >
        <ul class="max-h-60 overflow-y-auto py-1">
            <template x-for="opt in filtered" :key="opt.value">
                <li>
                    <button
                        type="button"
                        class="flex w-full px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/[0.04]"
                        @click="add(opt.value)"
                        role="option"
                    >
                        <span x-text="opt.label"></span>
                    </button>
                </li>
            </template>
            <li x-show="filtered.length === 0" class="px-3 py-2 text-sm text-gray-400">No matches</li>
        </ul>
    </div>
</div>
