<section class="space-y-4" data-brand-dossier>
    <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h2 class="font-semibold text-gray-800 dark:text-white/90">Marka dosyası</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    AI ajanlarının her işte ilk okuduğu kısa özet. Sistem verilerden kendisi derler (AI kullanmaz); her gece ve Otomatik kur sonrası yenilenir.
                    @if ($dossier['built_at'])
                        <span class="text-gray-400">Son derleme: {{ \Illuminate\Support\Carbon::parse($dossier['built_at'])->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</span>
                    @endif
                </p>
            </div>
            <button type="button" wire:click="rebuild" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Yenile</button>
        </div>

        @if ($message !== '')
            <p class="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>
        @endif

        <div class="mt-4 space-y-4">
            @foreach ($dossier['sections'] as $key => $section)
                <div data-dossier-section="{{ $key }}">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ $section['title'] }}</h3>
                    <div class="prose prose-sm mt-1 max-w-none text-gray-700 dark:prose-invert dark:text-gray-300">{!! \Illuminate\Support\Str::markdown($section['markdown'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
                </div>
            @endforeach
        </div>
    </div>

    <form wire:submit="saveNotes" class="space-y-3 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div>
            <h2 class="font-semibold text-gray-800 dark:text-white/90">Hedefler ve kısıtlar</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Dosyada elle yazılan tek kısım. Ajanlar hedefe göre öncelik verir, kısıtlardaki şeyleri asla önermez.</p>
        </div>
        <label class="block text-sm text-gray-700 dark:text-gray-300">Hedefler
            <textarea wire:model="goals" rows="3" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900" placeholder="Örn. implant hastası sayısını artırmak; Kadıköy şubesini öne çıkarmak"></textarea>
        </label>
        <label class="block text-sm text-gray-700 dark:text-gray-300">Kısıtlar
            <textarea wire:model="constraints" rows="3" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900" placeholder="Örn. fiyat yazma; rakip adı geçirme"></textarea>
        </label>
        @error('goals') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror
        @error('constraints') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror
        <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-600">Kaydet</button>
    </form>
</section>
