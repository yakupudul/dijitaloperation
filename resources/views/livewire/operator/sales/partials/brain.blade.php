{{-- Satış beyni (yakup 2026-11-08): everything the sales work decided and learned, editable and approvable in one place.
     Sabit kararlar win; approved lessons and answered questions follow them in every sales text. --}}
@php
    $area = 'w-full rounded-lg border border-gray-200 bg-white p-2.5 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white';
@endphp
<div class="space-y-6" data-sales-brain>
    <div class="section-head">
        <h2 class="section-title">Satış beyni</h2>
        <p class="text-sm text-gray-500">Her kapanan görüşmeden (kazanılan ya da olumsuz) Claude ders ve soru çıkarır. Sen onaylarsan bundan sonraki her aday mesajı ve analiz bunlara göre yazılır. Sabit kararlar her şeyin önündedir.</p>
    </div>
    @error('brain')<p role="alert" class="rounded-lg px-3 py-2 text-sm tone-critical">{{ $message }}</p>@enderror

    @if ($brain['questions']->isNotEmpty() || $brain['waQuestions'] !== [])
        <div class="card" data-sales-brain-questions>
            <div class="card-row">
                <span class="h-2 w-2 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Claude'un soruları</p>
                    <p class="text-[13px] text-gray-500">Cevabını yaz; mesajlar bundan sonra buna göre yazılır.</p>
                </div>
            </div>
            @foreach ($brain['questions'] as $q)
                <div class="card-row block" wire:key="brain-q-{{ $q->id }}" data-sales-brain-question="{{ $q->id }}">
                    <p class="text-sm text-gray-900 dark:text-white">{{ $q->text }}@if ($q->lead)<a href="{{ route('operator.sales.lead', ['lead' => $q->lead_id]) }}" wire:navigate class="ml-2 text-xs text-gray-400 hover:text-gray-700">{{ $q->lead->title() }}</a>@endif</p>
                    <div class="mt-2 flex gap-2">
                        <textarea wire:model="lessonAnswers.{{ $q->id }}" rows="1" placeholder="Cevabın" class="{{ $area }}"></textarea>
                        <div class="flex shrink-0 flex-col gap-1">
                            <button type="button" wire:click="answerLesson({{ $q->id }})" class="btn-dark h-8 px-3 text-[13px]">Kaydet</button>
                            <button type="button" wire:click="rejectLesson({{ $q->id }})" class="btn-quiet h-7 px-2 text-[12px] text-gray-500">Gereksiz</button>
                        </div>
                    </div>
                </div>
            @endforeach
            @foreach ($brain['waQuestions'] as $index => $question)
                <div class="card-row block" wire:key="brain-wa-{{ md5($question) }}" data-sales-brain-wa-question>
                    <p class="text-sm text-gray-900 dark:text-white">{{ $question }} <span class="ml-1 text-xs text-gray-400">WhatsApp görüşmelerinden</span></p>
                    <div class="mt-2 flex gap-2">
                        <textarea wire:model="waAnswers.{{ $index }}" rows="1" placeholder="Cevabın (Sabit kararlara eklenir)" class="{{ $area }}"></textarea>
                        <button type="button" wire:click="answerBrainQuestion({{ $index }})" class="btn-dark h-8 shrink-0 px-3 text-[13px]">Kaydet</button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($brain['proposed']->isNotEmpty())
        <div class="card" data-sales-brain-proposed>
            <div class="card-row">
                <span class="h-2 w-2 shrink-0 rounded-full bg-accent" aria-hidden="true"></span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Onay bekleyen öneriler <span class="tabular-nums text-gray-500">{{ $brain['proposed']->count() }}</span></p>
                    <p class="text-[13px] text-gray-500">İstersen metni düzelt, sonra onayla. Reddettiğin bir daha önerilmez.</p>
                </div>
            </div>
            @foreach ($brain['proposed'] as $l)
                <div class="card-row block" wire:key="brain-p-{{ $l->id }}" data-sales-brain-lesson="{{ $l->id }}">
                    <p class="text-xs text-gray-500">{{ $l->kindLabel() }}@if ($l->lead) · <a href="{{ route('operator.sales.lead', ['lead' => $l->lead_id]) }}" wire:navigate class="hover:text-gray-900">{{ $l->lead->title() }}</a> ({{ $l->lead->stage === 'won' ? 'kazanıldı' : 'olumsuz' }})@endif</p>
                    <textarea wire:model="lessonEdits.{{ $l->id }}" rows="2" class="mt-1 {{ $area }}"></textarea>
                    <div class="mt-1.5 flex gap-2">
                        <button type="button" wire:click="approveLesson({{ $l->id }})" class="btn-dark h-8 px-3 text-[13px]" data-sales-brain-approve>Onayla</button>
                        <button type="button" wire:click="rejectLesson({{ $l->id }})" class="btn-quiet h-8 px-2 text-[13px] text-gray-500" data-sales-brain-reject>Reddet</button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="card" data-sales-brain-approved>
        <div class="card-row">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-gray-900 dark:text-white">Öğrenilenler <span class="tabular-nums text-gray-500">{{ $brain['approved']->count() }}</span></p>
                <p class="text-[13px] text-gray-500">Onayladığın kararlar ve yöntemler, cevapladığın sorular. Düzenle ya da kaldır.</p>
            </div>
        </div>
        @forelse ($brain['approved'] as $l)
            <div class="card-row block" wire:key="brain-a-{{ $l->id }}" x-data="{ edit: false }" data-sales-brain-known="{{ $l->id }}">
                <div x-show="! edit" class="flex items-start justify-between gap-3">
                    <p class="text-sm text-gray-800 dark:text-gray-200"><span class="mr-1 text-xs text-gray-500">{{ $l->kindLabel() }}</span>{{ $l->text }}@if ($l->kind === 'soru' && $l->answer)<span class="block text-gray-600 dark:text-gray-400">→ {{ $l->answer }}</span>@endif</p>
                    <span class="flex shrink-0 gap-1">
                        @if ($l->kind !== 'soru')<button type="button" x-on:click="edit = true" class="btn-quiet h-7 px-2 text-[12px]">Düzenle</button>@endif
                        <button type="button" wire:click="rejectLesson({{ $l->id }})" wire:confirm="Bu ders kaldırılsın mı? Bundan sonraki mesajlar bunu okumaz." class="btn-quiet h-7 px-2 text-[12px] text-gray-500">Kaldır</button>
                    </span>
                </div>
                @if ($l->kind !== 'soru')
                    <div x-show="edit" x-cloak>
                        <textarea wire:model="lessonEdits.{{ $l->id }}" rows="2" class="{{ $area }}"></textarea>
                        <div class="mt-1.5 flex gap-2"><button type="button" wire:click="approveLesson({{ $l->id }})" x-on:click="edit = false" class="btn-dark h-8 px-3 text-[13px]">Kaydet</button><button type="button" x-on:click="edit = false" class="btn-quiet h-8 px-2 text-[13px]">Vazgeç</button></div>
                    </div>
                @endif
            </div>
        @empty
            <p class="card-row text-sm text-gray-500">Henüz onaylı ders yok. Görüşmeler kapandıkça Claude önerir; kendin de ekleyebilirsin.</p>
        @endforelse
        <form wire:submit="addLesson" class="flex flex-col gap-2 border-t border-gray-200 bg-gray-50/60 px-4 py-3 sm:flex-row sm:items-start dark:border-gray-800 dark:bg-white/[0.02]" data-sales-brain-add>
            <select wire:model="newLesson.kind" class="h-9 rounded-lg border border-gray-200 bg-white px-2 text-sm sm:w-32 dark:border-gray-700 dark:bg-transparent dark:text-white" aria-label="Tür">
                <option value="yontem">Yöntem</option><option value="karar">Karar</option><option value="soru">Soru</option>
            </select>
            <textarea wire:model="newLesson.text" rows="1" placeholder="Örn. Fiyat sorana önce 6 aylık planın web sitesi hediyesini yaz." class="{{ $area }}"></textarea>
            <button type="submit" class="btn h-9 shrink-0">Ekle</button>
        </form>
    </div>

    <form wire:submit="saveRules" class="card" data-sales-brain-rules>
        <label class="block px-4 pt-3.5">
            <span class="block text-sm font-medium text-gray-900 dark:text-white">Sabit kararlar</span>
            <span class="mt-0.5 block text-[13px] text-gray-500">Fiyatlar, yapılmayanlar, aday mesajı kuralları. Her WhatsApp cevabı, aday mesajı ve analiz önce bunu okur. WhatsApp › Beyin'deki metnin aynısıdır. Müşterilerin ödediği ücretleri buraya yazma.</span>
            <textarea wire:model="rulesText" rows="12" maxlength="12000" class="mt-3 {{ $area }}"></textarea>
        </label>
        @error('rules')<p class="px-4 pt-1 text-xs text-error-600">{{ $message }}</p>@enderror
        <div class="mt-3 flex justify-end border-t border-gray-200 bg-gray-50/60 px-4 py-3 dark:border-gray-800 dark:bg-white/[0.02]">
            <button type="submit" class="btn-dark">Kaydet</button>
        </div>
    </form>
</div>
