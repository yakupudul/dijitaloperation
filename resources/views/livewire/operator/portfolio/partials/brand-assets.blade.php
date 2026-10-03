{{-- Dijital varlıklar: every asset with its data status, "Hesap ekle", the per-channel setup status and the bindings. --}}
@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $missing = collect($checklist['items'])->whereIn('key', array_keys(\App\Services\Operator\BrandWorkspaceReadService::ACCOUNT_LABELS))->where('done', false)->values();
@endphp
<div class="space-y-4" data-brand-assets-tab>
    @if ($missing->where('required', true)->isNotEmpty() || $missing->contains(fn (array $i): bool => str_contains($i['detail'], 'veri henüz gelmedi')))
        <section class="rounded-xl bg-amber-50 p-3 text-amber-900 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30" data-accounts-missing>
            <p class="font-semibold">Eksik ya da verisi gelmeyen hesaplar</p>
            <ul class="mt-1 space-y-0.5 text-xs">
                @foreach ($missing as $item)
                    @if ($item['required'] || str_contains($item['detail'], 'veri henüz gelmedi'))
                        <li><span class="font-semibold">{{ $item['label'] }}</span> · {{ $item['detail'] }}</li>
                    @endif
                @endforeach
            </ul>
            <p class="mt-1 text-xs text-amber-800 dark:text-amber-300">"Otomatik kur" hesapları adres ve ada göre arar; bulamadığını aşağıdaki "Hesap ekle" listesinden ya da "Varlık ekle" ile bağlayabilirsin.</p>
        </section>
    @endif

    <section class="space-y-2" aria-labelledby="brand-asset-cards-heading">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="brand-asset-cards-heading" class="text-sm font-semibold text-gray-900 dark:text-white">Dijital varlıklar <span class="font-normal text-gray-400">{{ count($assetCards) }}</span></h2>
            <a href="{{ route('operator.assets', ['brand' => $brandModel->id]) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Varlık listesinde aç →</a>
        </div>
        @if ($assetCards === [])
            <p class="{{ $card }} text-gray-500">Henüz dijital varlık yok. "Otomatik kur" ile web sitesinden başla ya da "Varlık ekle".</p>
        @else
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
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
