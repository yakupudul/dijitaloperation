{{-- Hesap ekle: unbound accounts of the brand's MCC / Meta Business or matching the brand's name; one click = one new asset. --}}
@php $suggested = collect($candidates)->where('strong', true); @endphp
<section id="hesap-ekle" data-add-account>
    <div class="section-head">
        <h2 class="section-title" title="Markanın MCC / Meta Business'ında ya da adıyla eşleşen, henüz hiçbir varlığa bağlı olmayan hesaplar. Her hesap ayrı bir varlık olur; verisi birkaç dakika içinde çekilmeye başlar ve marka toplamlarına girer.">Hesap ekle <span class="section-count">{{ count($candidates) }}</span></h2>
        @if ($isAdmin && $suggested->count() > 1)
            <button type="button" wire:click="addSuggestedAccounts" wire:loading.attr="disabled" class="btn ml-auto">Önerilenlerin hepsini bağla ({{ $suggested->count() }})</button>
        @endif
    </div>
    <ul class="card">
        @forelse ($candidates as $candidate)
            <li wire:key="candidate-{{ $candidate['resource_id'] }}" data-candidate="{{ $candidate['resource_id'] }}" class="card-row flex-wrap sm:flex-nowrap">
                <div class="min-w-0 flex-1">
                    <p class="break-words text-sm font-medium text-gray-900 dark:text-white">{{ $candidate['name'] }} <span class="break-all font-normal text-gray-500">{{ $candidate['type_label'] }}, {{ $candidate['external_id'] }}</span></p>
                    <p class="mt-0.5 text-[13px] text-gray-500">
                        @if ($candidate['strong'])<span class="font-medium text-gray-900 dark:text-white">Önerilen</span>, @endif
                        {{ $candidate['reason'] === 'same_business' ? 'Aynı işletmede' : 'Adı markaya benziyor' }}@if ($candidate['container_label']), {{ $candidate['container_label'] }}@endif @if ($candidate['currency']), {{ $candidate['currency'] }}@endif
                    </p>
                </div>
                @if ($isAdmin)
                    <div class="flex w-full gap-2 sm:w-auto">
                        <button type="button" wire:click="addAccount({{ $candidate['resource_id'] }})" wire:loading.attr="disabled" class="btn flex-1 sm:flex-none">Bağla</button>
                        <button type="button" wire:click="dismissAccount({{ $candidate['resource_id'] }})" wire:loading.attr="disabled" class="btn-quiet flex-1 sm:flex-none" title="Bu hesap bu markaya ait değil; bu marka için bir daha önerilmez.">Bu markanın değil</button>
                        <button type="button" wire:click="dismissAccount({{ $candidate['resource_id'] }}, true)" wire:loading.attr="disabled" class="btn-quiet flex-1 sm:flex-none" title="Hizmet vermediğimiz bir işletme; hiçbir markaya önerilmez.">Kullanılmıyor</button>
                    </div>
                @endif
            </li>
        @empty
            <li><x-ta.empty-state size="sm" title="Bağlanmamış hesap yok" message="Markanın işletmesinde bağlanmamış hesap yok. Hesap görünmüyorsa Google / Meta sayfasından hesapları yeniden listeleyin; Meta'da müşterinin Business'ının seçili olduğundan emin olun." /></li>
        @endforelse
        @unless ($isAdmin)
            <li class="border-t border-gray-200 px-4 py-2.5 text-[13px] text-gray-500 dark:border-gray-800">Hesap bağlamayı yalnız Admin onaylayabilir.</li>
        @endunless
    </ul>
</section>
