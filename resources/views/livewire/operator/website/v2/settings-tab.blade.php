@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-white';
    $ghost = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
@endphp
<div class="space-y-4 text-xs" data-settings>
    @if ($message !== '')
        <p role="status" class="rounded-lg bg-blue-50 p-2 text-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ $message }}</p>
    @endif
    <section class="{{ $card }} flex flex-wrap items-center gap-2">
        <label for="sitemap-url" class="font-semibold">Sitemap URL</label>
        <input id="sitemap-url" type="url" wire:model="sitemapUrl" placeholder="https://…/sitemap_index.xml (boş = otomatik)" class="{{ $input }} w-96 max-w-full">
        <button type="button" wire:click="saveSitemap" class="{{ $ghost }}">Kaydet</button>
        @error('sitemapUrl')<span class="text-rose-600">{{ $message }}</span>@enderror
    </section>
    <section class="{{ $card }} flex flex-wrap items-center gap-2">
        <label for="capacity" class="font-semibold">Haftalık içerik kapasitesi</label>
        <input id="capacity" type="number" min="1" max="20" wire:model="capacity" class="{{ $input }} w-20">
        <button type="button" wire:click="saveCapacity" class="{{ $ghost }}">Kaydet</button>
        @error('capacity')<span class="text-rose-600">{{ $message }}</span>@enderror
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
