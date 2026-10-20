@php
    $card = 'min-w-0';
    $input = 'rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-white';
    $ghost = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
@endphp
<div class="space-y-10 text-xs" data-settings>
    @if ($message !== '')
        <p role="status" class="rounded-lg bg-gray-50 p-2 text-gray-800 dark:bg-gray-950 dark:text-gray-200">{{ $message }}</p>
    @endif
    <section class="{{ $card }} flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 pb-4 dark:border-gray-800" data-collection-status>
        <span><span class="font-semibold">Veri toplama</span> <span class="text-gray-500">{{ $collection === null ? 'henüz toplanmadı' : 'son toplama '.$collection['at']?->format('d.m.Y H:i').' ('.$collection['status'].')' }}</span></span>
        <a href="{{ route('operator.integrations.website', ['assetId' => $assetId]) }}" wire:navigate class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600">Şimdi güncelle</a>
    </section>
    <section class="{{ $card }} flex flex-wrap items-center gap-2">
        <label for="sitemap-url" class="font-semibold">Sitemap URL</label>
        <input id="sitemap-url" type="url" wire:model="sitemapUrl" placeholder="https://…/sitemap_index.xml (boş = otomatik)" class="{{ $input }} w-96 max-w-full">
        <button type="button" wire:click="saveSitemap" class="{{ $ghost }}">Kaydet</button>
        @error('sitemapUrl')<span class="text-error-600">{{ $message }}</span>@enderror
        <div class="w-full text-gray-600 dark:text-gray-400" data-gsc-sitemaps>
            @if ($gscSitemaps !== [])
                <p>{{ trim($sitemapUrl) === '' ? 'Boş bırakıldığı için Search Console’a gönderilmiş site haritaları kullanılıyor:' : 'Search Console’a gönderilmiş site haritaları (yukarıdaki adres öncelikli):' }}</p>
                <ul class="mt-1 space-y-0.5">
                    @foreach ($gscSitemaps as $map)
                        <li class="flex flex-wrap gap-2">
                            <a href="{{ $map['path'] }}" target="_blank" rel="noopener" class="font-medium text-brand-600 hover:underline">{{ $map['path'] }}</a>
                            <span @class(['text-error-600' => $map['errors'] > 0, 'text-warning-700' => $map['errors'] === 0 && $map['warnings'] > 0, 'text-gray-500' => $map['errors'] === 0 && $map['warnings'] === 0])>{{ $map['errors'] > 0 ? $map['errors'].' hata' : ($map['warnings'] > 0 ? $map['warnings'].' uyarı' : 'sorun yok') }}{{ $map['is_pending'] ? ', Google işliyor' : '' }}{{ $map['last_downloaded'] ? ', Google son okuma '.\Carbon\CarbonImmutable::parse($map['last_downloaded'])->format('d.m.Y') : '' }}</span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p>Search Console’da kayıtlı site haritası yok; boşsa robots.txt ve bilinen adresler denenir.</p>
            @endif
        </div>
    </section>
    <section class="{{ $card }} flex flex-wrap items-center gap-2">
        <label for="capacity" class="font-semibold" title="Sistem haftalık yeni yazı sayısını sitenin aşamasına göre seçer (1–3); bu sayı yalnız üst sınırdır. Çeviriler sayıya girmez.">Haftalık yeni yazı üst sınırı</label>
        <input id="capacity" type="number" min="1" max="20" wire:model="capacity" class="{{ $input }} w-20">
        <button type="button" wire:click="saveCapacity" class="{{ $ghost }}">Kaydet</button>
        @error('capacity')<span class="text-error-600">{{ $message }}</span>@enderror
        <span class="sr-only">Sistem haftalık yeni yazı sayısını sitenin aşamasına göre kendisi seçer (1–3); bu sayı yalnız üst sınırdır. Çeviriler sayıya girmez.</span>
    </section>
    <section class="{{ $card }} space-y-2" data-clarity>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="section-title">Microsoft Clarity</h2>
            @if ($clarity !== null)
                <x-ta.pill :tone="! $clarity->enabled ? 'info' : match ($clarity->last_status) { 'ok' => 'ok', 'error' => 'critical', 'empty' => 'look', default => 'info' }">
                    {{ ! $clarity->enabled ? 'durduruldu' : match ($clarity->last_status) { 'ok' => 'son çekim '.$clarity->last_pulled_at?->timezone('Europe/Istanbul')->format('d.m H:i'), 'error' => 'hata', 'empty' => 'veri yok', default => 'henüz çekilmedi' } }}
                </x-ta.pill>
            @endif
        </div>
        <p class="max-w-3xl text-gray-500">Ziyaretçinin sitede ne yaşadığı: öfkeli ve çalışmayan tıklamalar, hızlı geri dönüşler, JavaScript hataları, kaydırma. Kötü sayfalar Onarım masası › Diğer işler › Teknik sağlık'a iş olarak düşer. Clarity etiketi sitede yüklü olmalı; token: Clarity › Settings › Data Export › Generate new API token.</p>
        @if ($clarity?->last_error)<p class="text-error-600" data-clarity-error>{{ $clarity->last_error }}</p>@endif
        <div class="flex flex-wrap items-center gap-2">
            <input type="text" wire:model="clarityProjectId" aria-label="Clarity proje kimliği" placeholder="Proje kimliği (ör. abcd1234ef)" class="{{ $input }} w-48">
            <input type="password" wire:model="clarityToken" aria-label="Clarity API token" autocomplete="off" placeholder="{{ $clarity !== null ? 'Token kayıtlı (değiştirmek için yapıştırın)' : 'Data Export API token' }}" class="{{ $input }} w-80 max-w-full">
            <button type="button" wire:click="saveClarity" class="{{ $ghost }}">Kaydet</button>
            @if ($clarity !== null)
                <button type="button" wire:click="pullClarity" class="{{ $ghost }}">Şimdi çek</button>
                <button type="button" wire:click="toggleClarity" class="{{ $ghost }}">{{ $clarity->enabled ? 'Durdur' : 'Aç' }}</button>
                @if ($clarity->dashboardUrl())<a href="{{ $clarity->dashboardUrl() }}" target="_blank" rel="noopener" class="font-medium text-brand-600 hover:underline">Clarity'de aç ↗</a>@endif
            @endif
        </div>
        @error('clarityProjectId')<p class="text-error-600">{{ $message }}</p>@enderror
        @error('clarityToken')<p class="text-error-600">{{ $message }}</p>@enderror
    </section>
    <section class="{{ $card }}" data-corrections>
        <h2 class="mb-2 section-title">Kategori düzeltmeleri</h2>
        <ul class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
            @forelse ($corrections as $page)
                <li class="flex items-center justify-between py-2.5" wire:key="corr-{{ $page->id }}">
                    <span>{{ $page->path }} <span class="text-gray-500">{{ $page->categoryLabel() }}</span></span>
                    <button type="button" wire:click="unlockCategory({{ $page->id }})" class="{{ $ghost }}">Kilidi kaldır</button>
                </li>
            @empty
                <li class="py-1 text-gray-500">Elle düzeltilmiş kategori yok.</li>
            @endforelse
        </ul>
    </section>
</div>
