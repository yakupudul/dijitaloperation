{{-- Bugün › markalara dair sorular (marka epikrizi): answered here, they become verified facts every AI step uses. --}}
<div>
    @if ($total > 0)
        <section class="rounded-xl bg-white px-4 py-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-epicrisis-inbox>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div class="flex flex-wrap items-baseline gap-x-3 text-sm">
                    <h2 class="font-semibold text-gray-800 dark:text-white/90">Markalara dair sorularım · {{ $total }}</h2>
                    @if ($fill !== null)
                        <span class="text-xs text-gray-500" data-epicrisis-fill>Epikrizler ortalama %{{ $fill }} dolu{{ $thin > 0 ? ' · '.$thin.' marka %60\'ın altında' : '' }}</span>
                    @endif
                </div>
            </div>
            <ul class="mt-2 divide-y divide-gray-100 text-xs dark:divide-gray-800">
                @foreach ($questions as $question)
                    <li class="py-2" wire:key="iq-{{ $question->id }}" data-inbox-question="{{ $question->id }}">
                        <p class="text-gray-800 dark:text-gray-200">
                            <a href="{{ route('operator.brand', ['brand' => $question->brand_id, 'tab' => 'dosya']) }}" wire:navigate class="font-semibold text-gray-900 hover:underline dark:text-white">{{ $brands[$question->brand_id] ?? '' }}</a>
                            · {{ $question->value }}
                        </p>
                        @if ($isAdmin)
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                @foreach ((array) data_get($question->detail, 'options', []) as $option)
                                    <button type="button" wire:click="answer({{ $question->id }}, @js((string) $option['value']))" class="rounded-md px-2 py-0.5 font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-800">
                                        {{ data_get($question->detail, 'kind') === 'expired' ? 'Hâlâ geçerli' : $option['value'] }}
                                    </button>
                                @endforeach
                                <input type="text" wire:model="answers.{{ $question->id }}" wire:keydown.enter="answer({{ $question->id }})" aria-label="Cevap" placeholder="Cevabı yaz"
                                    class="min-w-40 flex-1 rounded-md border-gray-200 py-0.5 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200">
                                <button type="button" wire:click="answer({{ $question->id }})" class="font-semibold text-brand-600 hover:underline dark:text-brand-400">Kaydet</button>
                                <button type="button" wire:click="dismiss({{ $question->id }})" class="font-medium text-gray-500 hover:underline">Gerek yok</button>
                            </div>
                            @error('answers.'.$question->id)
                                <p class="mt-1 text-error-600">{{ $message }}</p>
                            @enderror
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($total > $questions->count() || $all)
                <button type="button" wire:click="$set('all', {{ $all ? 'false' : 'true' }})" class="link-neutral mt-1 text-xs">{{ $all ? 'Daha az' : 'Tümünü göster ('.$total.')' }}</button>
            @endif
        </section>
    @endif
</div>
