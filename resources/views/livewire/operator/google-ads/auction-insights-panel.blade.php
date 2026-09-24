<div class="space-y-4">
    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Açık artırma analizi</h3>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Google Ads API bu raporu vermiyor. Google Ads → Kampanyalar → Açık artırma analizi → İndir (CSV) ile aldığın dosyayı buraya yükle. Türkçe ya da İngilizce başlıklar okunur; aynı dönemi tekrar yüklersen eskisinin yerine geçer.</p>
        @if ($message !== '')<p class="mt-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>@endif
        @if ($error !== '')<p class="mt-2 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ $error }}</p>@endif
        <form wire:submit="upload" class="mt-3 flex flex-wrap items-end gap-3">
            <label class="text-sm"><span class="text-xs text-gray-500">CSV dosyası</span>
                <input type="file" wire:model="report" accept=".csv,.tsv,.txt" class="mt-1 block text-sm">
                @error('report')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror
            </label>
            <label class="text-sm"><span class="text-xs text-gray-500">Dönem başı (isteğe bağlı)</span>
                <input type="date" wire:model="periodStart" class="mt-1 block rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
            </label>
            <label class="text-sm"><span class="text-xs text-gray-500">Dönem sonu</span>
                <input type="date" wire:model="periodEnd" class="mt-1 block rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                @error('periodEnd')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror
            </label>
            <x-ta.button type="submit" size="sm">Yükle</x-ta.button>
        </form>
    </section>
    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        @include('livewire.operator.google-ads.partials.auction-insights-table', ['latest' => $latest])
    </section>
</div>
