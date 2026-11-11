@php $field = 'h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white'; @endphp
<div data-start-settings>
    <div class="section-head">
        <h2 class="section-title">Sözleşme ve ödeme</h2>
        <p class="text-sm text-gray-500">Anlaşması girilen müşteriye gönderdiğin başlangıç bağlantısı bu hesabı ve bu metni gösterir. Müşteri bilgilerini yazar, sözleşmeyi onaylar, havaleyi yapıp bildirir.</p>
    </div>
    <form wire:submit="save" class="card space-y-4 p-4">
        <div class="grid gap-3 sm:grid-cols-3">
            <label class="block text-xs text-gray-500">Hesap sahibi<input wire:model="holder" type="text" class="mt-1 {{ $field }}" placeholder="Ad Soyad" /></label>
            <label class="block text-xs text-gray-500">IBAN<input wire:model="iban" type="text" class="mt-1 {{ $field }}" placeholder="TR00 0000 0000 0000 0000 0000 00" data-start-iban /></label>
            <label class="block text-xs text-gray-500">Banka<input wire:model="bank" type="text" class="mt-1 {{ $field }}" /></label>
        </div>
        @error('iban')<p class="text-sm text-error-600">{{ $message }}</p>@enderror
        <label class="block text-xs text-gray-500">Sözleşme metni
            <textarea wire:model="contract" rows="16" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm leading-relaxed text-gray-900 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white" data-start-contract-text></textarea>
        </label>
        <p class="text-[13px] text-gray-500">Süslü parantezli sözcükler her müşteri için anlaşmadan dolar: {hizmet_veren}, {musteri}, {marka}, {hizmetler}, {ucret}, {odeme_gunu}, {baslangic}, {iban}.</p>
        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="btn-dark h-9">Kaydet</button>
            @if ($message !== '')<span class="text-sm text-gray-600 dark:text-gray-300">{{ $message }}</span>@endif
        </div>
    </form>
</div>
