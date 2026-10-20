{{-- Bugün › markalara dair sorular (marka epikrizi): answered here, they become verified facts every AI step uses. --}}
<div>
    @if ($total > 0)
        <section data-epicrisis-inbox>
            <div class="section-head">
                <h2 class="section-title">Markalara dair sorularım <span class="section-count">{{ $total }}</span></h2>
                @if ($fill !== null)
                    <span class="text-[13px] text-gray-500" data-epicrisis-fill>Epikrizler ortalama %{{ $fill }} dolu{{ $thin > 0 ? ', '.$thin.' marka %60\'ın altında' : '' }}</span>
                @endif
            </div>
            <ul class="card text-xs">
                @foreach ($questions as $question)
                    <li class="border-t border-gray-200 px-4 py-3.5 first:border-t-0 dark:border-gray-800" wire:key="iq-{{ $question->id }}" data-inbox-question="{{ $question->id }}">
                        <p class="text-sm text-gray-800 dark:text-gray-200">
                            <a href="{{ route('operator.brand', ['brand' => $question->brand_id, 'tab' => 'dosya']) }}" wire:navigate class="font-medium text-gray-900 hover:underline dark:text-white">{{ $brands[$question->brand_id] ?? '' }}</a>:
                            {{ $question->value }}
                        </p>
                        @if ($isAdmin)
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                @foreach ((array) data_get($question->detail, 'options', []) as $option)
                                    <button type="button" wire:click="answer({{ $question->id }}, @js((string) $option['value']))" class="btn h-7! px-2.5! text-xs!">
                                        {{ data_get($question->detail, 'kind') === 'expired' ? 'Hâlâ geçerli' : $option['value'] }}
                                    </button>
                                @endforeach
                                <input type="text" wire:model="answers.{{ $question->id }}" wire:keydown.enter="answer({{ $question->id }})" aria-label="Cevap" placeholder="Cevabı yaz"
                                    class="h-7 min-w-40 flex-1 rounded-lg border-gray-200 py-0 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200">
                                <button type="button" wire:click="answer({{ $question->id }})" class="btn-primary h-7! px-2.5! text-xs!">Kaydet</button>
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
                <button type="button" wire:click="$set('all', {{ $all ? 'false' : 'true' }})" class="link-neutral mt-2 text-[13px]">{{ $all ? 'Daha az' : 'Tümünü göster ('.$total.')' }}</button>
            @endif
        </section>
    @endif
</div>
