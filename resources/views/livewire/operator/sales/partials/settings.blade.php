{{-- Satış › Ayarlar (yakup 2026-11-08): Satış beyni (what the sales work learned) and the Referanslar list. --}}
@php $field = 'h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white'; @endphp
<section class="mt-5 space-y-8" data-sales-settings>
    @if (! empty($brain))
        @include('livewire.operator.sales.partials.brain')
    @endif

    <div data-sales-referrers>
        <div class="section-head">
            <h2 class="section-title">Referanslar</h2>
            <p class="text-sm text-gray-500">Müşterimiz olmayan ama bize aday gönderen kişiler. Mevcut müşteriler ayrıca eklenmez, Kimden alanında kendiliğinden listelenir.</p>
        </div>
        <form wire:submit="addReferrer" class="card flex flex-col gap-2 p-4 sm:flex-row sm:items-end">
            <label class="block flex-1 text-xs text-gray-500">Ad soyad<input wire:model="newReferrer" type="text" class="mt-1 {{ $field }}" placeholder="Eyüp Bey" /></label>
            <label class="block text-xs text-gray-500 sm:w-48">Telefon<input wire:model="newReferrerPhone" type="tel" class="mt-1 {{ $field }}" placeholder="0532 000 00 00" /></label>
            <button type="submit" class="btn-dark h-9">Ekle</button>
        </form>
        @error('newReferrer')<p class="mt-1 text-sm text-error-600">{{ $message }}</p>@enderror
        <div class="card mt-3 divide-y divide-gray-200 dark:divide-gray-800">
            @forelse ($referrers as $r)
                @php $sent = \App\Models\SalesLead::query()->where('referrer_id', $r->id)->count(); @endphp
                <div class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm" wire:key="referrer-{{ $r->id }}">
                    <span class="font-medium text-gray-900 dark:text-white">{{ $r->name }}@if ($r->phone)<span class="ml-2 font-normal tabular-nums text-gray-500">{{ $r->phone }}</span>@endif</span>
                    <span class="flex items-center gap-3 text-[13px] text-gray-500">{{ $sent }} kayıt
                        @if ($sent === 0)<button type="button" wire:click="removeReferrer({{ $r->id }})" wire:confirm="{{ $r->name }} listeden silinsin mi?" class="btn-quiet h-7 px-2 text-[12px]">Sil</button>@endif
                    </span>
                </div>
            @empty
                <p class="px-4 py-3 text-sm text-gray-500">Liste boş. Bir kaydın Kimden alanından da yeni referans ekleyebilirsin.</p>
            @endforelse
        </div>
    </div>

    @include('livewire.operator.sales.partials.rewards')
</section>
