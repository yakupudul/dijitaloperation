{{-- Hesap ekle: unbound accounts of the brand's MCC / Meta Business or matching the brand's name; one click = one new asset. --}}
@php $suggested = collect($candidates)->where('strong', true); @endphp
<section id="hesap-ekle" data-add-account>
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 pb-2 dark:border-gray-800">
        <h2 class="section-title" title="Markanın MCC / Meta Business'ında ya da adıyla eşleşen, henüz hiçbir varlığa bağlı olmayan hesaplar. Her hesap ayrı bir varlık olur; verisi birkaç dakika içinde çekilmeye başlar ve marka toplamlarına girer.">Hesap ekle <span class="text-sm font-normal text-gray-500">{{ count($candidates) }}</span></h2>
        @if ($isAdmin && $suggested->count() > 1)
            <button type="button" wire:click="addSuggestedAccounts" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/5">Önerilenlerin hepsini bağla ({{ $suggested->count() }})</button>
        @endif
    </div>
    @forelse ($candidates as $candidate)
        <div wire:key="candidate-{{ $candidate['resource_id'] }}" data-candidate="{{ $candidate['resource_id'] }}" class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 py-2.5 dark:border-gray-800">
            <div class="min-w-0">
                <p class="break-words text-sm font-medium text-gray-800 dark:text-white/90">{{ $candidate['name'] }} <span class="break-all font-normal text-gray-500">{{ $candidate['type_label'] }}, {{ $candidate['external_id'] }}</span></p>
                <p class="text-xs text-gray-500">
                    @if ($candidate['strong'])<span class="font-medium text-gray-900 dark:text-white">Önerilen</span>, @endif
                    {{ $candidate['reason'] === 'same_business' ? 'Aynı işletmede' : 'Adı markaya benziyor' }}@if ($candidate['container_label']), {{ $candidate['container_label'] }}@endif @if ($candidate['currency']), {{ $candidate['currency'] }}@endif
                </p>
            </div>
            @if ($isAdmin)
                <button type="button" wire:click="addAccount({{ $candidate['resource_id'] }})" wire:loading.attr="disabled" class="w-full rounded-lg px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 sm:w-auto dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/5">Bağla</button>
            @endif
        </div>
    @empty
        <p class="py-2.5 text-sm text-gray-500">Markanın işletmesinde bağlanmamış hesap yok. Hesap görünmüyorsa Google / Meta sayfasından hesapları yeniden listeleyin; Meta'da müşterinin Business'ının seçili olduğundan emin olun.</p>
    @endforelse
    @unless ($isAdmin)
        <p class="pt-2 text-xs text-gray-500">Hesap bağlamayı yalnız Admin onaylayabilir.</p>
    @endunless
</section>
