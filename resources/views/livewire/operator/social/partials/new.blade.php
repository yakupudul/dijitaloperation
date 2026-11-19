{{-- Yeni gönderi: a post uploaded by hand; it waits for approval like every other draft. --}}
<form wire:submit="createPost" class="card max-w-3xl space-y-4 p-5" data-social-new-form>
    <h2 class="section-title">Yeni gönderi</h2>
    <div class="grid gap-4 sm:grid-cols-3">
        <label class="block text-sm">Tür
            <select wire:model.live="newKind" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                <option value="image">Gönderi (tek görsel)</option><option value="carousel">Kaydırmalı (2-10)</option><option value="reels">Reels (video)</option><option value="story">Story</option><option value="text">Yalnız metin (Threads, LinkedIn)</option>
            </select>
        </label>
        <label class="block text-sm">Başlık
            <select wire:model="newPillar" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                <option value="">—</option><option value="rehber">Rehber</option><option value="vaka">Vaka</option><option value="gundem">Gündem</option><option value="anlatim">Yakup anlatıyor</option><option value="guven">Güven</option>
            </select>
        </label>
        <label class="block text-sm">Yayın zamanı
            <input type="datetime-local" wire:model="newTime" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
        </label>
    </div>
    <label class="block text-sm">Kısa ad (yalnız burada görünür)
        <input type="text" wire:model="newTitle" maxlength="190" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
    </label>
    @if ($newKind === 'text')
        <p class="tone-info rounded-lg px-3.5 py-2.5 text-[13px]">Yalnız metin gönderisi Threads’e, LinkedIn sayfasına ve kişisel LinkedIn profilinize gider, Instagram ve Facebook’a gitmez. Onaylanınca metin sırasına girer ve Ayarlar’daki metin saatlerinde çıkar; tarihi sabitlerseniz seçtiğiniz saatte çıkar.</p>
    @else
    <label class="block text-sm">Görsel ya da video (jpg, png, mp4; kaydırmalıda sırayla; Reels’te videodan sonra eklenen görsel kapak olur)
        <input type="file" wire:model="newFiles" multiple accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime" class="mt-1 block w-full text-sm">
    </label>
    @error('newFiles')<p class="text-sm text-error-600">{{ $message }}</p>@enderror
    @error('newFiles.*')<p class="text-sm text-error-600">{{ $message }}</p>@enderror
    <label class="block text-sm">Instagram ve Facebook metni
        <textarea wire:model="newCaption" rows="6" maxlength="2200" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900"></textarea>
    </label>
    @endif
    <label class="block text-sm">LinkedIn metni (boş bırakılırsa LinkedIn’e gitmez)
        <textarea wire:model="newLinkedin" rows="6" maxlength="3000" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900"></textarea>
    </label>
    <label class="block text-sm">Threads metni (en çok 500 karakter; boş bırakılırsa Threads’e gitmez, yazılırsa yalnız metin olarak paylaşılır)
        <textarea wire:model="newThreads" rows="4" maxlength="500" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900"></textarea>
    </label>
    <label class="block text-sm">Kişisel LinkedIn metni (kendi profilinizde, sizin ağzınızdan; boş bırakılırsa profilinize gitmez)
        <textarea wire:model="newPersonal" rows="5" maxlength="3000" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900" data-social-new-personal></textarea>
    </label>
    @error('newThreads')<p class="text-sm text-error-600">{{ $message }}</p>@enderror
    <div class="flex gap-2"><button type="submit" class="btn-primary" wire:loading.attr="disabled">Plana ekle</button><span wire:loading wire:target="newFiles" class="text-sm text-gray-500">Dosyalar yükleniyor…</span></div>
</form>
