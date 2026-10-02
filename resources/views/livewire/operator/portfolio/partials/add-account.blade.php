{{-- Hesap ekle: unbound accounts of the brand's MCC / Meta Business or matching the brand's name; one click = one new asset. --}}
@php $suggested = collect($candidates)->where('strong', true); @endphp
<section id="hesap-ekle" class="{{ $card }}" data-add-account>
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-5 py-3 dark:border-gray-800">
        <div>
            <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Hesap ekle</h2>
            <p class="text-xs text-gray-500">Markanın MCC / Meta Business'ında ya da adıyla eşleşen, henüz hiçbir varlığa bağlı olmayan hesaplar. Her hesap ayrı bir varlık olur; verisi birkaç dakika içinde çekilmeye başlar ve marka toplamlarına girer.</p>
        </div>
        @if ($isAdmin && $suggested->count() > 1)
            <button type="button" wire:click="addSuggestedAccounts" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white hover:bg-brand-600">Önerilenlerin hepsini bağla ({{ $suggested->count() }})</button>
        @endif
    </div>
    @forelse ($candidates as $candidate)
        <div wire:key="candidate-{{ $candidate['resource_id'] }}" data-candidate="{{ $candidate['resource_id'] }}" class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-3 last:border-0 dark:border-gray-800">
            <div class="min-w-0">
                <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $candidate['name'] }} <span class="font-normal text-gray-500">· {{ $candidate['type_label'] }} · {{ $candidate['external_id'] }}</span></p>
                <p class="text-xs text-gray-500">
                    @if ($candidate['strong'])<span class="font-medium text-success-700">Önerilen</span> · @endif
                    {{ $candidate['reason'] === 'same_business' ? 'Aynı işletmede' : 'Adı markaya benziyor' }}@if ($candidate['container_label']) · {{ $candidate['container_label'] }}@endif @if ($candidate['currency']) · {{ $candidate['currency'] }}@endif
                </p>
            </div>
            @if ($isAdmin)
                <button type="button" wire:click="addAccount({{ $candidate['resource_id'] }})" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:ring-brand-500/30">Bağla</button>
            @endif
        </div>
    @empty
        <p class="px-5 py-4 text-sm text-gray-500">Markanın işletmesinde bağlanmamış hesap yok. Hesap görünmüyorsa Google / Meta sayfasından hesapları yeniden listeleyin; Meta'da müşterinin Business'ının seçili olduğundan emin olun.</p>
    @endforelse
    @unless ($isAdmin)
        <p class="px-5 pb-3 text-xs text-gray-400">Hesap bağlamayı yalnız Admin onaylayabilir.</p>
    @endunless
</section>
