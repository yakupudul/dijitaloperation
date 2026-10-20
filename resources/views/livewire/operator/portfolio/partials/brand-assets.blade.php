{{-- Dijital varlıklar: every asset with its data status, "Hesap ekle", the per-channel setup status and the bindings. --}}
@php
    $missing = collect($checklist['items'])->whereIn('key', array_keys(\App\Services\Operator\BrandWorkspaceReadService::ACCOUNT_LABELS))->where('done', false)->values();
@endphp
<div class="space-y-10" data-brand-assets-tab>
    @if ($missing->where('required', true)->isNotEmpty() || $missing->contains(fn (array $i): bool => str_contains($i['detail'], 'veri henüz gelmedi')))
        <section data-accounts-missing>
            <h2 class="section-title">Eksik ya da verisi gelmeyen hesaplar</h2>
            <ul class="mt-2 divide-y divide-gray-200 border-b border-gray-200 text-sm dark:divide-gray-800 dark:border-gray-800">
                @foreach ($missing as $item)
                    @if ($item['required'] || str_contains($item['detail'], 'veri henüz gelmedi'))
                        <li class="flex flex-wrap items-center gap-x-3 py-2.5"><span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span><span class="font-medium text-gray-900 dark:text-white">{{ $item['label'] }}</span><span class="text-warning-800 dark:text-warning-400">{{ $item['detail'] }}</span></li>
                    @endif
                @endforeach
            </ul>
            <p class="mt-2 text-xs text-gray-500">"Otomatik kur" bulamadığını aşağıdaki "Hesap ekle" listesinden ya da "Varlık ekle" ile bağlayabilirsin.</p>
        </section>
    @endif

    <section aria-labelledby="brand-asset-cards-heading">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="brand-asset-cards-heading" class="section-title">Dijital varlıklar <span class="text-sm font-normal text-gray-500">{{ count($assetCards) }}</span></h2>
            <a href="{{ route('operator.assets', ['brand' => $brandModel->id]) }}" wire:navigate class="link-neutral text-xs">Varlık listesi</a>
        </div>
        @if ($assetCards === [])
            <p class="mt-3 text-gray-500">Henüz dijital varlık yok. "Otomatik kur" ile web sitesinden başla ya da "Varlık ekle".</p>
        @else
            <div class="mt-2 grid gap-x-8 sm:grid-cols-2 xl:grid-cols-3">
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
