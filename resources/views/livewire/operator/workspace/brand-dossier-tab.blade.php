<section class="space-y-4" data-brand-dossier>
    @if ($message !== '')
        <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>
    @endif

    <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-brand-care>
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h2 class="font-semibold text-gray-800 dark:text-white/90">Bakım ajanı</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Her pazar gecesi bu dosyayı okur; dosyada değişiklik yoksa çalışmaz (maliyet yok). İşleri iş listesine ekler, dışarıya hiçbir şey yazmaz.
                    @if (! empty($care['reviewed_at']))
                        <span class="text-gray-400">Son inceleme: {{ \Illuminate\Support\Carbon::parse($care['reviewed_at'])->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</span>
                    @endif
                </p>
            </div>
            @if ($operational)
                <button type="button" wire:click="reviewNow" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Şimdi incele</button>
            @endif
        </div>
        @if (! $operational)
            <p class="mt-3 text-sm text-gray-500">Marka aktif değil; bakım ajanı yalnız aktif markalarda çalışır.</p>
        @elseif ($care === null || empty($care['reviewed_at']))
            <p class="mt-3 text-sm text-gray-500">Henüz inceleme yok; ilk inceleme pazar gecesi ya da "Şimdi incele" ile.</p>
        @else
            @if (! empty($care['summary']))
                <p class="mt-3 text-sm text-gray-800 dark:text-white/90">{{ $care['summary'] }}</p>
            @endif
            @if ($careTasks->isNotEmpty())
                <ul class="mt-3 space-y-1 text-sm">
                    @foreach ($careTasks as $task)
                        <li data-care-task="{{ $task->id }}"><span class="text-xs text-gray-500">{{ $task->channelLabel() }}</span> · <span class="text-gray-800 dark:text-white/90">{{ $task->title }}</span>
                            <span class="block text-xs text-gray-500">{{ $task->reason }}</span></li>
                    @endforeach
                </ul>
            @endif
            @if (! empty($care['questions']))
                <div class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                    <p class="font-medium">Ajanın soruları (cevabı aşağıdaki hedefler / kısıtlara ya da ilgili ayara yaz):</p>
                    <ul class="mt-1 list-disc pl-5">
                        @foreach ($care['questions'] as $question)
                            <li>{{ $question }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endif
        @if (! empty($care['failed_at']) && (empty($care['reviewed_at']) || $care['failed_at'] > $care['reviewed_at']))
            <p class="mt-3 text-sm text-rose-600">Son inceleme tamamlanamadı: {{ $care['error'] ?? '' }}</p>
        @endif
    </div>

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
