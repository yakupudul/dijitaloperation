@php
    $num = fn (int $n): string => number_format($n, 0, ',', '.');
    $day = fn (string $d): string => \Carbon\CarbonImmutable::parse($d)->locale('tr')->isoFormat('D MMMM');
    $lessons = collect($books)->sum(fn (array $b): int => count($b['lessons']));
@endphp
<div class="space-y-8 dark:text-gray-200" data-books>
    <header>
        <h1 class="page-title">Kitaplar</h1>
        <p class="page-sub">Claude bir markaya bakmadan önce o işin kitabını buradan okur: {{ count($books) }} kitap, {{ $lessons }} ders. Metni sen düzenlersin; Claude yalnız senin düzeltmenden ya da ölçülmüş bir sonuçtan ders ekleyebilir.</p>
    </header>

    <div class="-mt-2 flex flex-wrap gap-2" role="tablist" aria-label="Kitaplar">
        @foreach ($books as $item)
            <button type="button" wire:click="open('{{ $item['key'] }}')" role="tab" aria-selected="{{ $item['key'] === $book['key'] ? 'true' : 'false' }}" @class(['seg', 'seg-active' => $item['key'] === $book['key']]) data-book-tab="{{ $item['key'] }}">
                {{ \Illuminate\Support\Str::of($item['title'])->replace([' usta kitabı', 'Sektör kitabı: '], '')->before(' (')->ucfirst() }}
                @if (count($item['lessons']) > 0)<span class="tabular-nums {{ $item['key'] === $book['key'] ? 'text-accent' : 'text-gray-400' }}">{{ count($item['lessons']) }}</span>@endif
            </button>
        @endforeach
    </div>

    @if ($message !== '')
        <p class="card px-4 py-3 text-sm text-gray-700 dark:text-gray-300" role="status" data-books-message>{{ $message }}</p>
    @endif

    <section class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_20rem]" wire:key="book-{{ $book['key'] }}">
        <div class="min-w-0">
            <div class="section-head">
                <h2 class="section-title">{{ $book['title'] }}</h2>
                <span class="text-[13px] tabular-nums text-gray-500">{{ $num($book['size']) }} / {{ $num($max) }} karakter</span>
            </div>
            @if ($book['when'] !== '')<p class="mb-3 text-[13px] text-gray-500">Ne zaman yüklenir: {{ $book['when'] }}</p>@endif

            @if ($editing)
                <div x-data="{ n: $wire.entangle('body') }">
                    <textarea wire:model="body" rows="28" spellcheck="false" aria-label="Kitabın metni"
                        class="block w-full rounded-xl border border-gray-200 bg-white px-4 py-3 font-mono text-[13px] leading-relaxed text-gray-800 focus:border-gray-900 focus:ring-0 dark:border-gray-700 dark:bg-white/[0.04] dark:text-gray-200" data-book-body></textarea>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <button type="button" wire:click="save" wire:loading.attr="disabled" class="btn-dark" data-book-save>Kaydet</button>
                        <button type="button" wire:click="$set('editing', false)" class="btn-quiet">Vazgeç</button>
                        <span class="text-[13px] tabular-nums text-gray-500" :class="(n || '').length > {{ $max }} && 'font-semibold text-gray-900 dark:text-white'" x-text="(n || '').length.toLocaleString('tr-TR') + ' karakter'"></span>
                    </div>
                </div>
            @else
                <div class="card px-5 py-4" data-book-text>
                    @foreach (preg_split('/\n(?=## )/', $book['body']) as $part)
                        @php([$head, $rest] = str_starts_with($part, '## ') ? array_pad(explode("\n", $part, 2), 2, '') : ['', $part])
                        @if ($head !== '')<h3 class="mt-5 text-sm font-semibold text-gray-900 first:mt-0 dark:text-white">{{ mb_substr($head, 3) }}</h3>@endif
                        <div class="mt-1.5 whitespace-pre-line text-[14px] leading-relaxed text-gray-700 dark:text-gray-300">{{ trim($rest) }}</div>
                    @endforeach
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2">
                    <button type="button" wire:click="edit" class="btn" data-book-edit>Metni düzenle</button>
                    @if ($book['can_undo'])<button type="button" wire:click="undo" wire:confirm="Bir önceki metne dönülsün mü?" class="btn-quiet" data-book-undo>Önceki metne dön</button>@endif
                    <span class="text-[13px] text-gray-500">@if ($book['edited']) Son düzenleyen {{ $book['edited_by'] }} · {{ $book['updated_at'] }} @else İlk sürüm, henüz düzenlenmedi. @endif</span>
                </div>
            @endif
        </div>

        <aside class="min-w-0" data-book-lessons>
            <div class="section-head"><h2 class="section-title">Dersler <span class="ml-1 font-normal tabular-nums text-gray-400">{{ count($book['lessons']) }}</span></h2></div>
            @if (count($book['lessons']) > 0)
                <ul class="card divide-y divide-gray-200 dark:divide-gray-800">
                    @foreach ($book['lessons'] as $item)
                        <li wire:key="lesson-{{ $item['id'] }}" class="px-4 py-3">
                            <p class="text-sm text-gray-900 dark:text-white">{{ $item['text'] }}</p>
                            <p class="mt-1 text-[12px] text-gray-500">{{ \App\Services\Brain\Playbooks::SOURCES[$item['source']] ?? '' }} · {{ $day($item['at']) }} · {{ $item['evidence'] }}</p>
                            <button type="button" wire:click="dropLesson('{{ $item['id'] }}')" wire:confirm="Bu ders kitaptan kaldırılsın mı?" class="mt-1.5 text-[12px] text-gray-400 underline decoration-gray-300 underline-offset-2 hover:text-gray-700">Kaldır</button>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-[13px] leading-relaxed text-gray-500">Bu kitapta henüz ders yok. Claude'u bir konuda düzelttiğinde ya da bir işin aylık sonucu ölçüldüğünde ders buraya düşer.</p>
            @endif
            <form wire:submit="addLesson" class="mt-4">
                <label for="book-lesson" class="text-[13px] font-medium text-gray-900 dark:text-white">Kendin bir ders ekle</label>
                <textarea id="book-lesson" wire:model="lesson" rows="3" maxlength="400" placeholder="Bir iki cümlelik kural"
                    class="mt-1.5 block w-full rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:border-gray-900 focus:ring-0 dark:border-gray-700 dark:bg-white/[0.04] dark:text-gray-200"></textarea>
                <button type="submit" class="btn mt-2" data-book-lesson-add>Dersi ekle</button>
            </form>
        </aside>
    </section>
</div>
