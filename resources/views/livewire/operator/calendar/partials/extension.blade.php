{{-- Hızlı not eklentisi (2026-11-10): "+" in Chrome from any tab, Alt+N; the ZIP carries this panel's address and the key. --}}
<div class="card mt-6 p-4" data-calendar-extension>
    <div class="flex items-start justify-between gap-3">
        <div>
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Her sekmeden hızlı not: Chrome eklentisi</h2>
            <p class="mt-1 text-sm text-gray-500">Tarayıcının sağ üstünde MoxDOP ikonu olur. Ona bas ya da <kbd class="rounded border border-gray-200 px-1 text-xs dark:border-gray-700">Alt</kbd>+<kbd class="rounded border border-gray-200 px-1 text-xs dark:border-gray-700">N</kbd>, yaz, Enter. Hangi sayfadaysan linki de nota eklenir; açık işlerini oradan tikleyebilirsin.</p>
        </div>
        <button type="button" wire:click="showExtension" class="btn-quiet px-2.5" aria-label="Kapat">✕</button>
    </div>
    <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
        <li><a href="{{ route('operator.calendar.extension') }}" class="btn-primary inline-flex" data-calendar-extension-download>Eklentiyi indir</a> ve ZIP'i bir klasöre çıkar (ör. Belgeler › moxdop-hizli-not). Klasörü silme; Chrome oradan çalıştırır.</li>
        <li>Chrome'da adres çubuğuna <span class="font-mono text-xs">chrome://extensions</span> yaz, sağ üstte <span class="font-medium">Geliştirici modu</span>nu aç.</li>
        <li><span class="font-medium">Paketlenmemiş öğe yükle</span>ye bas, çıkardığın <span class="font-mono text-xs">moxdop-hizli-not</span> klasörünü seç.</li>
        <li>Yapboz ikonundan MoxDOP'u <span class="font-medium">sabitle</span>; + artık hep orada. Kısayolu değiştirmek istersen: <span class="font-mono text-xs">chrome://extensions/shortcuts</span>.</li>
    </ol>
    <p class="mt-3 text-xs text-gray-500">İndirdiğin dosyada sana özel bir anahtar var, kimseyle paylaşma. Paylaştıysan ya da bilgisayarı değiştirdiysen <button type="button" wire:click="renewCaptureKey" wire:confirm="Kurulu eklenti kaydetmeyi bırakacak; yenisini indirip kurman gerekecek. Yenilensin mi?" class="link-neutral">anahtarı yenile</button>. Telefonda: MoxDOP uygulama ikonuna basılı tut, "Hızlı not".</p>
</div>
