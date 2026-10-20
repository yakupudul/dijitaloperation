{{-- Dijital varlıklar: what is missing first, then every asset with its data status, "Hesap ekle", the per-channel setup status and the bindings. --}}
@php
    $missing = collect($checklist['items'])->whereIn('key', array_keys(\App\Services\Operator\BrandWorkspaceReadService::ACCOUNT_LABELS))->where('done', false)->values();
@endphp
<div class="space-y-10" data-brand-assets-tab>
    @if ($missing->where('required', true)->isNotEmpty() || $missing->contains(fn (array $i): bool => str_contains($i['detail'], 'veri henüz gelmedi')))
        <section data-accounts-missing>
            <div class="section-head">
                <h2 class="section-title">Eksik ya da verisi gelmeyen hesaplar</h2>
            </div>
            {{-- Canlı panel (2026-10-20): each missing account as a tinted card; the button goes to "Hesap ekle" below. --}}
            <ul class="grid grid-cols-2 gap-3 lg:grid-cols-3">
                @foreach ($missing as $item)
                    @if ($item['required'] || str_contains($item['detail'], 'veri henüz gelmedi'))
                        <li class="flex">
                            <div class="soft-card soft-amber w-full" data-account-missing="{{ $item['key'] }}">
                                <span class="soft-icon text-warning-600 max-sm:hidden dark:text-warning-400" aria-hidden="true"><svg class="h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M9 15l6-6"/><path d="M11 6l.5-.5a5 5 0 0 1 7 7l-.5.5M13 18l-.5.5a5 5 0 0 1-7-7l.5-.5"/></svg></span>
                                <p class="text-[15px] font-semibold tracking-[-0.01em] text-gray-900 dark:text-white">{{ $item['label'] }}</p>
                                <p class="text-[13px] text-gray-600 dark:text-gray-300">{{ $item['detail'] }}</p>
                                @if ($setup !== null)<div class="mt-auto pt-1"><a href="#hesap-ekle" class="btn">Hesap ekle</a></div>@endif
                            </div>
                        </li>
                    @endif
                @endforeach
            </ul>
            <p class="mt-2.5 text-[13px] text-gray-500">"Otomatik kur" bulamadığını aşağıdaki "Hesap ekle" listesinden ya da "Varlık ekle" ile bağlayabilirsin.</p>
        </section>
    @endif

    <section aria-labelledby="brand-asset-cards-heading">
        <div class="section-head">
            <h2 id="brand-asset-cards-heading" class="section-title">Dijital varlıklar <span class="section-count">{{ count($assetCards) }}</span></h2>
            <a href="{{ route('operator.assets', ['brand' => $brandModel->id]) }}" wire:navigate class="ml-auto text-[13px] font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">Varlık listesi</a>
        </div>
        @if ($assetCards === [])
            <div class="card"><div class="card-row"><span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span><div class="min-w-0 flex-1"><p class="text-sm font-medium text-gray-900 dark:text-white">Henüz dijital varlık yok</p><p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">"Otomatik kur" ile web sitesinden başla ya da "Varlık ekle".</p></div></div></div>
        @else
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($assetCards as $asset)
                    @include('livewire.operator.portfolio.partials.asset-card', ['asset' => $asset])
                @endforeach
            </div>
        @endif
    </section>

    @if ($setup !== null)
        @include('livewire.operator.portfolio.partials.add-account', ['candidates' => $setup['candidates']])
        @include('livewire.operator.portfolio.partials.setup-status')
    @endif

    <livewire:operator.portfolio.brand-settings :brand-id="$brandModel->id" part="assets" :key="'brand-settings-assets-'.$brandModel->id" />
</div>
