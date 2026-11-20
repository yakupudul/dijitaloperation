{{-- "İlgilendiği konu": type to filter the brand's topics, pick one, or add what was typed to the list. --}}
<div class="relative" data-panel-interest
    x-data="{
        open: false,
        value: $wire.entangle('{{ $model }}'),
        norm(text) { return (text || '').toLocaleLowerCase('tr').trim() },
        shows(label) { return this.norm(this.value) === '' || this.norm(label).includes(this.norm(this.value)) },
        known() { return [...this.$root.querySelectorAll('[data-option]')].some((option) => this.norm(option.dataset.option) === this.norm(this.value)) },
    }"
    @click.outside="open = false" @keydown.escape="open = false">
    <span class="mb-1.5 block text-[13px] font-medium text-gray-700">İlgilendiği konu</span>
    <input type="text" x-model="value" @focus="open = true" @input="open = true" class="{{ $input }}" autocomplete="off" placeholder="Seçin ya da yazın" aria-label="İlgilendiği konu" role="combobox" :aria-expanded="open">
    <div x-show="open" x-cloak class="absolute inset-x-0 top-full z-30 mt-1 max-h-60 overflow-y-auto rounded-xl bg-white p-1.5 shadow-theme-lg ring-1 ring-gray-200">
        @foreach ($interests as $option)
            <button type="button" data-option="{{ $option }}" x-show="shows($el.dataset.option)" @click="value = $el.dataset.option; open = false"
                class="block w-full truncate rounded-lg px-2.5 py-2 text-left text-sm text-gray-800 hover:bg-gray-100" wire:key="interest-{{ md5($model.$option) }}">{{ $option }}</button>
        @endforeach
        <button type="button" x-show="norm(value) !== '' && ! known()" @click="$wire.addInterest(value); open = false"
            class="block w-full truncate rounded-lg px-2.5 py-2 text-left text-sm font-medium text-gray-900 hover:bg-gray-100" data-panel-interest-add>+ “<span x-text="value.trim()"></span>” listeye ekle</button>
        @if ($interests->isEmpty())
            <p x-show="norm(value) === ''" class="px-2.5 py-2 text-[13px] text-gray-500">Liste boş. Konuyu yazın, “listeye ekle” ile bir daha yazmadan seçersiniz.</p>
        @endif
    </div>
</div>
