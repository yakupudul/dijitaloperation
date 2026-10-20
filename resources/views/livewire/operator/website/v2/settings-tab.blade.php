@php
    $card = 'min-w-0';
    $input = 'h-8 rounded-lg border border-gray-200 bg-white px-2.5 text-[13px] text-gray-700 shadow-card focus:border-gray-300 focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
    $ghost = 'btn';
    $label = 'text-sm font-medium text-gray-900 dark:text-white';
    $line = 'mt-0.5 text-[13px] text-gray-500 dark:text-gray-400';
@endphp
<div class="min-w-0 space-y-10 text-sm" data-settings>
    @if ($message !== '')
        <p role="status" class="rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-800 shadow-card dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-200">{{ $message }}</p>
    @endif

    {{-- 1. How the site's data is collected and what the system may do on its own. --}}
    <section class="min-w-0">
        <div class="section-head"><h2 class="section-title">Site ayarları</h2></div>
        <div class="card">
            <div class="card-row flex-wrap sm:flex-nowrap" data-collection-status>
                <div class="min-w-0 flex-[1_1_16rem]">
                    <p class="{{ $label }}">Veri toplama</p>
                    <p class="{{ $line }}">{{ $collection === null ? 'henüz toplanmadı' : 'son toplama '.$collection['at']?->format('d.m.Y H:i').' ('.$collection['status'].')' }}</p>
                </div>
                <a href="{{ route('operator.integrations.website', ['assetId' => $assetId]) }}" wire:navigate class="btn w-full sm:w-auto">Şimdi güncelle</a>
            </div>

            <div class="border-t border-gray-200 px-4 py-3.5 dark:border-gray-800">
                <div class="flex min-w-0 flex-wrap items-center gap-x-3.5 gap-y-2">
                    <div class="min-w-0 flex-[1_1_16rem]">
                        <label for="sitemap-url" class="{{ $label }}">Site haritası adresi</label>
                        <p class="{{ $line }}">Boş bırakılırsa otomatik bulunur.</p>
                    </div>
                    <div class="flex w-full min-w-0 items-center gap-2 sm:w-auto">
                        <input id="sitemap-url" type="url" wire:model="sitemapUrl" placeholder="https://…/sitemap_index.xml" class="{{ $input }} min-w-0 flex-1 sm:w-80 sm:flex-none">
                        <button type="button" wire:click="saveSitemap" class="{{ $ghost }}">Kaydet</button>
                    </div>
                </div>
                @error('sitemapUrl')<p class="mt-1.5 text-xs text-error-600">{{ $message }}</p>@enderror
                <div class="mt-3 text-[13px] text-gray-600 dark:text-gray-400" data-gsc-sitemaps>
                    @if ($gscSitemaps !== [])
                        <p class="text-xs text-gray-500">{{ trim($sitemapUrl) === '' ? 'Boş bırakıldığı için Search Console’a gönderilmiş site haritaları kullanılıyor:' : 'Search Console’a gönderilmiş site haritaları (yukarıdaki adres öncelikli):' }}</p>
                        <ul class="mt-1.5 space-y-1">
                            @foreach ($gscSitemaps as $map)
                                <li class="flex min-w-0 flex-wrap gap-x-2">
                                    <a href="{{ $map['path'] }}" target="_blank" rel="noopener" class="link-primary min-w-0 [overflow-wrap:anywhere]">{{ $map['path'] }}</a>
                                    <span @class(['text-error-600' => $map['errors'] > 0, 'text-warning-700' => $map['errors'] === 0 && $map['warnings'] > 0, 'text-gray-500' => $map['errors'] === 0 && $map['warnings'] === 0])>{{ $map['errors'] > 0 ? $map['errors'].' hata' : ($map['warnings'] > 0 ? $map['warnings'].' uyarı' : 'sorun yok') }}{{ $map['is_pending'] ? ', Google işliyor' : '' }}{{ $map['last_downloaded'] ? ', Google son okuma '.\Carbon\CarbonImmutable::parse($map['last_downloaded'])->format('d.m.Y') : '' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-xs text-gray-500">Search Console’da kayıtlı site haritası yok; boşsa robots.txt ve bilinen adresler denenir.</p>
                    @endif
                </div>
            </div>

            <div class="card-row flex-wrap sm:flex-nowrap">
                <div class="min-w-0 flex-[1_1_16rem]">
                    <label for="capacity" class="{{ $label }}">Haftalık yeni yazı üst sınırı</label>
                    <p class="{{ $line }}">Sistem sayıyı sitenin aşamasına göre 1 ile 3 arasında seçer; bu yalnız üst sınır. Çeviriler sayılmaz.</p>
                    @error('capacity')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                </div>
                <div class="flex items-center gap-2">
                    <input id="capacity" type="number" min="1" max="20" wire:model="capacity" class="{{ $input }} w-20">
                    <button type="button" wire:click="saveCapacity" class="{{ $ghost }}">Kaydet</button>
                </div>
            </div>
        </div>
    </section>

    {{-- 2. Microsoft Clarity: what visitors live through on the site. --}}
    <section class="min-w-0" data-clarity>
        <div class="section-head justify-between">
            <h2 class="section-title">Microsoft Clarity</h2>
            @if ($clarity !== null)
                <x-ta.pill :tone="! $clarity->enabled ? 'info' : match ($clarity->last_status) { 'ok' => 'ok', 'error' => 'critical', 'empty' => 'look', default => 'info' }">
                    {{ ! $clarity->enabled ? 'durduruldu' : match ($clarity->last_status) { 'ok' => 'son çekim '.$clarity->last_pulled_at?->timezone('Europe/Istanbul')->format('d.m H:i'), 'error' => 'hata', 'empty' => 'veri yok', default => 'henüz çekilmedi' } }}
                </x-ta.pill>
            @endif
        </div>
        <div class="card px-4 py-3.5">
            <p class="max-w-3xl text-[13px] text-gray-500 dark:text-gray-400">Öfkeli ve çalışmayan tıklamalar, hızlı geri dönüşler, JavaScript hataları ve kaydırma. Kötü sayfalar Onarım masasına teknik sağlık işi olarak düşer. Clarity etiketi sitede yüklü olmalı; token Clarity'de Settings, Data Export bölümünden alınır.</p>
            @if ($clarity?->last_error)<p class="mt-2 text-[13px] text-error-600" data-clarity-error>{{ $clarity->last_error }}</p>@endif
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <input type="text" wire:model="clarityProjectId" aria-label="Clarity proje kimliği" placeholder="Proje kimliği (ör. abcd1234ef)" class="{{ $input }} w-full sm:w-52">
                <input type="password" wire:model="clarityToken" aria-label="Clarity API token" autocomplete="off" placeholder="{{ $clarity !== null ? 'Token kayıtlı (değiştirmek için yapıştırın)' : 'Data Export API token' }}" class="{{ $input }} w-full sm:w-80">
                <button type="button" wire:click="saveClarity" class="{{ $ghost }}">Kaydet</button>
                @if ($clarity !== null)
                    <button type="button" wire:click="pullClarity" class="btn-quiet">Şimdi çek</button>
                    <button type="button" wire:click="toggleClarity" class="btn-quiet">{{ $clarity->enabled ? 'Durdur' : 'Aç' }}</button>
                    @if ($clarity->dashboardUrl())<a href="{{ $clarity->dashboardUrl() }}" target="_blank" rel="noopener" class="link-primary text-[13px]">Clarity'de aç ↗</a>@endif
                @endif
            </div>
            @error('clarityProjectId')<p class="mt-1.5 text-xs text-error-600">{{ $message }}</p>@enderror
            @error('clarityToken')<p class="mt-1.5 text-xs text-error-600">{{ $message }}</p>@enderror
        </div>
    </section>

    {{-- 3. Page categories set by hand (the rules leave them alone). --}}
    <section class="min-w-0" data-corrections>
        <div class="section-head">
            <h2 class="section-title">Kategori düzeltmeleri</h2>
            <span class="section-count">{{ count($corrections) }}</span>
        </div>
        <ul class="card">
            @forelse ($corrections as $page)
                <li class="card-row" wire:key="corr-{{ $page->id }}">
                    <span class="min-w-0 flex-1"><span class="block truncate text-sm text-gray-900 dark:text-white">{{ $page->path }}</span><span class="block text-xs text-gray-500">{{ $page->categoryLabel() }}</span></span>
                    <button type="button" wire:click="unlockCategory({{ $page->id }})" class="btn h-7! px-2.5! text-xs!">Kilidi kaldır</button>
                </li>
            @empty
                <li><x-ta.empty-state size="sm" title="Elle düzeltilmiş kategori yok." /></li>
            @endforelse
        </ul>
    </section>
</div>
