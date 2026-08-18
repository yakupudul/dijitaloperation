@php
    $website = collect($assets ?? [])->first(function ($asset) {
        $type = strtolower((string) ($asset['type'] ?? $asset['asset_type'] ?? ''));
        return $type === 'website';
    });
    $websiteId = is_array($website) && ctype_digit((string) ($website['id'] ?? ''))
        ? (int) $website['id']
        : null;
@endphp

<div class="space-y-5">
    <section class="rounded-xl bg-white p-6 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
            <div class="max-w-3xl">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ app()->getLocale() === 'tr' ? 'Kamu Keşif' : 'Public Discovery' }}</h2>
                    <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">REAL ENGINE</span>
                </div>
                <p class="mt-3 text-sm leading-6 text-gray-600 dark:text-gray-300">
                    Eski Brand içi demo keşif tabloları kullanımdan kaldırıldı. Kamu Keşif artık Website üzerinden public sayfaları gerçekten tarar, Evidence üretir ve Brand Context'e aktarılmadan önce insan onayı bekleyen adaylar oluşturur.
                </p>
                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                        <p class="text-xs font-medium text-gray-400">Kaynak</p>
                        <p class="mt-1 text-sm font-semibold text-gray-800 dark:text-white">Public Website</p>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                        <p class="text-xs font-medium text-gray-400">Çıktı</p>
                        <p class="mt-1 text-sm font-semibold text-gray-800 dark:text-white">Evidence + Candidates</p>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                        <p class="text-xs font-medium text-gray-400">Canonical yazım</p>
                        <p class="mt-1 text-sm font-semibold text-gray-800 dark:text-white">Operator acceptance</p>
                    </div>
                </div>
            </div>

            <div class="flex shrink-0 flex-col gap-2 sm:flex-row lg:flex-col">
                @if ($websiteId)
                    <a href="{{ route('operator.website.discovery', ['assetId' => $websiteId]) }}" wire:navigate class="inline-flex justify-center rounded-lg bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-violet-700">Bu markanın keşfini aç</a>
                    <a href="{{ route('operator.website.sources', ['assetId' => $websiteId]) }}" wire:navigate class="inline-flex justify-center rounded-lg bg-white px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700">Website veri kaynakları</a>
                @else
                    <a href="{{ route('operator.asset.create', ['brandId' => $brandRow['id']]) }}" wire:navigate class="inline-flex justify-center rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600">Website varlığı ekle</a>
                @endif
                <a href="{{ route('operator.public-discovery') }}" wire:navigate class="inline-flex justify-center rounded-lg px-4 py-2.5 text-sm font-medium text-brand-700 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:text-brand-300 dark:ring-brand-500/30">Tüm Kamu Keşif merkezi</a>
            </div>
        </div>
    </section>

    @if (! $websiteId)
        <section class="rounded-xl bg-amber-50 p-5 text-sm text-amber-800 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20">
            Bu markada Website Digital Asset bulunamadığı için Public Discovery başlatılamaz. Önce markaya gerçek Website varlığını ekleyin ve domain/primary URL bilgisini girin.
        </section>
    @endif
</div>
