@php
    $card = 'rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900';
@endphp
<div class="space-y-5">
    <div>
        <a href="{{ route('operator.settings', ['section' => 'operations']) }}" wire:navigate class="text-sm text-gray-500 hover:text-brand-600">← Ayarlar</a>
        <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">KVKK</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Ajans, müşterilerin hesaplarındaki kişisel veriyi (form, telefon, yorum, WhatsApp) müşteri adına işler; her müşteriyle veri işleme sözleşmesi olmalı. Sağlık verisi işleniyorsa (ör. WhatsApp'ta hasta mesajları) işaretleyin. Bu ekran hukuki danışmanlığın yerini tutmaz; kayıt ve hatırlatma içindir.</p>
    </div>
    @if ($message !== '')<p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>@endif

    <section class="{{ $card }}">
        <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Veri işleme sözleşmeleri <span class="font-normal text-gray-500">· {{ $missing }} aktif müşteride eksik</span></h2>
        <table class="mt-2 w-full text-sm">
            <thead><tr class="text-left text-xs text-gray-500"><th class="py-1">Müşteri</th><th>İmza tarihi</th><th>Not / dosya yeri</th><th>Sağlık verisi</th></tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($customers as $customer)
                    <tr wire:key="kvkk-{{ $customer->id }}">
                        <td class="py-1.5">{{ $customer->name }}@if (blank($rows[$customer->id]['signed_on'] ?? '')) <span class="text-xs text-amber-700">eksik</span>@endif</td>
                        <td><input type="date" wire:model="rows.{{ $customer->id }}.signed_on" class="{{ $input }}"></td>
                        <td><input type="text" wire:model="rows.{{ $customer->id }}.note" class="{{ $input }} w-full"></td>
                        <td><input type="checkbox" wire:model="rows.{{ $customer->id }}.health" class="rounded border-gray-300"></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section class="{{ $card }}">
        <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">WhatsApp mesaj saklama süresi</h2>
        <p class="mt-1 text-xs text-gray-500">Doluysa, bu kadar günden eski mesajların metni her gece silinir; konuşma, yön ve tarih kalır. Boş = süresiz saklama. En az 30 gün.</p>
        <label class="mt-2 inline-flex items-center gap-2 text-sm"><input type="number" min="30" wire:model="retention" class="{{ $input }} w-28"> gün</label>
        @error('retention')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
    </section>

    <x-ta.button type="button" wire:click="save" size="sm">Kaydet</x-ta.button>
</div>
