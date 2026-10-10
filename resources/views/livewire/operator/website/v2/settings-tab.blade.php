@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-white';
    $ghost = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
@endphp
<div class="space-y-4 text-xs" data-settings>
    @if ($message !== '')
        <p role="status" class="rounded-lg bg-blue-50 p-2 text-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ $message }}</p>
    @endif
    <section class="{{ $card }} flex flex-wrap items-center justify-between gap-2" data-collection-status>
        <span><span class="font-semibold">Veri toplama</span> · {{ $collection === null ? 'henüz toplanmadı' : 'son toplama '.$collection['at']?->format('d.m.Y H:i').' ('.$collection['status'].')' }}</span>
        <a href="{{ route('operator.integrations.website', ['assetId' => $assetId]) }}" wire:navigate class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600">Şimdi güncelle</a>
    </section>
    <section class="{{ $card }} flex flex-wrap items-center gap-2">
        <label for="sitemap-url" class="font-semibold">Sitemap URL</label>
        <input id="sitemap-url" type="url" wire:model="sitemapUrl" placeholder="https://…/sitemap_index.xml (boş = otomatik)" class="{{ $input }} w-96 max-w-full">
        <button type="button" wire:click="saveSitemap" class="{{ $ghost }}">Kaydet</button>
        @error('sitemapUrl')<span class="text-rose-600">{{ $message }}</span>@enderror
        <div class="w-full text-gray-600 dark:text-gray-400" data-gsc-sitemaps>
            @if ($gscSitemaps !== [])
                <p>{{ trim($sitemapUrl) === '' ? 'Boş bırakıldığı için Search Console’a gönderilmiş site haritaları kullanılıyor:' : 'Search Console’a gönderilmiş site haritaları (yukarıdaki adres öncelikli):' }}</p>
                <ul class="mt-1 space-y-0.5">
                    @foreach ($gscSitemaps as $map)
                        <li class="flex flex-wrap gap-2">
                            <a href="{{ $map['path'] }}" target="_blank" rel="noopener" class="font-medium text-brand-600 hover:underline">{{ $map['path'] }}</a>
                            <span @class(['text-rose-600' => $map['errors'] > 0, 'text-amber-700' => $map['errors'] === 0 && $map['warnings'] > 0, 'text-gray-500' => $map['errors'] === 0 && $map['warnings'] === 0])>{{ $map['errors'] > 0 ? $map['errors'].' hata' : ($map['warnings'] > 0 ? $map['warnings'].' uyarı' : 'sorun yok') }}{{ $map['is_pending'] ? ' · Google işliyor' : '' }}{{ $map['last_downloaded'] ? ' · Google son okuma '.\Carbon\CarbonImmutable::parse($map['last_downloaded'])->format('d.m.Y') : '' }}</span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p>Search Console’da kayıtlı site haritası yok; boşsa robots.txt ve bilinen adresler denenir.</p>
            @endif
        </div>
    </section>
    <section class="{{ $card }} flex flex-wrap items-center gap-2">
        <label for="capacity" class="font-semibold">Haftalık içerik kapasitesi</label>
        <input id="capacity" type="number" min="1" max="20" wire:model="capacity" class="{{ $input }} w-20">
        <button type="button" wire:click="saveCapacity" class="{{ $ghost }}">Kaydet</button>
        @error('capacity')<span class="text-rose-600">{{ $message }}</span>@enderror
    </section>
    <section class="{{ $card }} space-y-2" data-clarity>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold">Microsoft Clarity</h2>
            @if ($clarity !== null)
                <span @class(['rounded-full px-2 py-0.5', 'bg-emerald-50 text-emerald-700' => $clarity->enabled && $clarity->last_status === 'ok', 'bg-rose-50 text-rose-700' => $clarity->last_status === 'error', 'bg-amber-50 text-amber-800' => $clarity->last_status === 'empty', 'bg-gray-100 text-gray-600' => ! $clarity->enabled || $clarity->last_status === null])>
                    {{ ! $clarity->enabled ? 'durduruldu' : match ($clarity->last_status) { 'ok' => 'son çekim '.$clarity->last_pulled_at?->timezone('Europe/Istanbul')->format('d.m H:i'), 'error' => 'hata', 'empty' => 'veri yok', default => 'henüz çekilmedi' } }}
                </span>
            @endif
        </div>
        <p class="text-gray-600 dark:text-gray-400">Ziyaretçinin sitede ne yaşadığı: öfkeli ve çalışmayan tıklamalar, hızlı geri dönüşler, JavaScript hataları, kaydırma. Kötü sayfalar Onarım masası › Diğer işler › Teknik sağlık'a iş olarak düşer. Clarity etiketi sitede yüklü olmalı; token: Clarity › Settings › Data Export › Generate new API token.</p>
        @if ($clarity?->last_error)<p class="text-rose-600" data-clarity-error>{{ $clarity->last_error }}</p>@endif
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
        @error('clarityProjectId')<p class="text-rose-600">{{ $message }}</p>@enderror
        @error('clarityToken')<p class="text-rose-600">{{ $message }}</p>@enderror
    </section>
    <section class="{{ $card }}" data-corrections>
        <h2 class="mb-2 text-sm font-semibold">Kategori düzeltmeleri</h2>
        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($corrections as $page)
                <li class="flex items-center justify-between py-1" wire:key="corr-{{ $page->id }}">
                    <span>{{ $page->path }} · {{ $page->categoryLabel() }}</span>
                    <button type="button" wire:click="unlockCategory({{ $page->id }})" class="{{ $ghost }}">Kilidi kaldır</button>
                </li>
            @empty
                <li class="py-1 text-gray-500">Elle düzeltilmiş kategori yok.</li>
            @endforelse
        </ul>
    </section>
</div>
