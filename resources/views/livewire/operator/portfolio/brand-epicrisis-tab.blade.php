{{-- Marka epikrizi (yakup, 2026-10-16): the record every AI step reads, by layer, with its open questions. --}}
<div class="space-y-10" data-epicrisis>
    @php
        $counts = (array) ($head?->counts ?? []);
        $confidence = \App\Models\BrandEpicrisisItem::CONFIDENCE_LABELS;
        $badge = fn (string $tone): string => match ($tone) {
            'verified' => 'text-success-700 dark:text-success-400',
            'warn' => 'text-warning-800 dark:text-warning-400',
            'error' => 'text-error-700 dark:text-error-400',
            default => 'text-gray-500 dark:text-gray-400',
        };
        $source = fn (\App\Models\BrandEpicrisisItem $i): ?string => is_string($i->source_ref) && str_starts_with($i->source_ref, 'http') ? $i->source_ref : null;
    @endphp

    <section>
        <div class="section-head">
            <h2 class="section-title">Marka epikrizi <span class="section-count">%{{ (int) ($head?->fill_percent ?? 0) }} dolu</span></h2>
            <p class="sr-only">Yazılar, gönderiler, reklamlar ve yorum cevapları bu kaydı okur. Doğrulanmış ve veriden gelen bilgiler kesin bilgi olarak kullanılır; çelişkili ya da süresi dolmuş bilgi hiçbir işe girmez.</p>
            @if ($isAdmin)
                <button type="button" wire:click="rebuild" wire:loading.attr="disabled" class="btn ml-auto" data-epicrisis-rebuild>Şimdi güncelle</button>
            @endif
        </div>
        <div class="card">
            @if ($head === null)
                <p class="px-4 py-3.5 text-[13px] text-gray-500">Bu markanın epikrizi henüz hazırlanmadı; gece kendiliğinden hazırlanır.</p>
            @else
                <div class="px-4 py-3.5">
                    <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5" aria-hidden="true"><div class="h-full rounded-full bg-gray-900 dark:bg-white" style="width: {{ max(0, min(100, (int) ($head->fill_percent ?? 0))) }}%"></div></div>
                    <p class="mt-2.5 text-[13px] text-gray-500" data-epicrisis-counts>
                        {{ $counts['known'] ?? 0 }}/{{ $counts['required'] ?? 0 }} zorunlu bilgi, {{ $counts['facts'] ?? 0 }} bilgi ({{ $counts['verified'] ?? 0 }} doğrulandı), {{ $counts['synthesis'] ?? 0 }} sentez, {{ $counts['evidence'] ?? 0 }} kanıt, {{ $counts['lessons'] ?? 0 }} ders
                        @if ($head->built_at)
                            · {{ $head->built_at->timezone('Europe/Istanbul')->format('d.m H:i') }}
                        @endif
                    </p>
                    @if ($head->summary)
                        @php $positioning = $synthesis->firstWhere('field', 'positioning'); @endphp
                        @if ($positioning)
                            <p class="mt-2 text-sm text-gray-800 dark:text-gray-100" data-epicrisis-positioning>{{ $positioning->value }}</p>
                        @endif
                    @endif
                </div>
            @endif
            @if ($notice)
                <p class="border-t border-gray-200 px-4 py-2.5 text-[13px] font-medium text-success-700 dark:border-gray-800 dark:text-success-400" role="status">{{ $notice }}</p>
            @elseif ($queuedAt && ($head?->built_at === null || $head->built_at->lt(\Carbon\Carbon::parse($queuedAt))))
                <p class="border-t border-gray-200 px-4 py-2.5 text-[13px] text-gray-500 dark:border-gray-800" role="status">Güncelleme sırada.</p>
            @endif
        </div>
    </section>

    @if ($questions->isNotEmpty())
        <section data-epicrisis-questions>
            <div class="section-head">
                <h3 class="section-title">Sana soracaklarım <span class="section-count">{{ $questions->count() }}</span></h3>
                <span class="text-[13px] text-gray-500">Cevabın doğrulanmış bilgi olur ve tüm AI işlerine girer. Bilmiyorsan “Gerek yok” de, bir daha sorulmaz.</span>
            </div>
            <ul class="card text-xs">
                @foreach ($questions as $question)
                    <li class="border-t border-gray-200 px-4 py-3.5 first:border-t-0 dark:border-gray-800" wire:key="q-{{ $question->id }}" data-question="{{ $question->id }}" data-question-kind="{{ data_get($question->detail, 'kind') }}">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $question->value }}</p>
                        @if ($isAdmin)
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                @foreach ((array) data_get($question->detail, 'options', []) as $option)
                                    <button type="button" wire:click="answer({{ $question->id }}, @js((string) $option['value']))" class="btn h-7! px-2.5! text-xs!">
                                        {{ data_get($question->detail, 'kind') === 'expired' ? 'Hâlâ geçerli' : $option['value'] }}
                                    </button>
                                @endforeach
                                <input type="text" wire:model="answers.{{ $question->id }}" wire:keydown.enter="answer({{ $question->id }})" aria-label="Cevap" placeholder="{{ data_get($question->detail, 'kind') === 'missing' ? 'Cevabı yaz' : 'Ya da doğrusunu yaz' }}"
                                    class="h-7 min-w-40 flex-1 rounded-lg border border-gray-200 bg-white px-2.5 text-xs text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-gray-200">
                                <button type="button" wire:click="answer({{ $question->id }})" class="btn-primary h-7! px-2.5! text-xs!">Kaydet</button>
                                <button type="button" wire:click="dismiss({{ $question->id }})" class="btn-quiet h-7! px-2! text-xs!" data-question-dismiss>Gerek yok</button>
                            </div>
                            @error('answers.'.$question->id)
                                <p class="mt-1 text-error-600">{{ $message }}</p>
                            @enderror
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($head !== null)
        <section data-epicrisis-layer="fact">
            <div class="section-head">
                <h3 class="section-title">Bilgiler <span class="section-count">{{ $facts->count() }}</span></h3>
                @if ($missing->isNotEmpty())
                    <span class="text-[13px] text-warning-700 dark:text-warning-400" data-epicrisis-missing>Eksik: {{ $missing->implode(', ') }}</span>
                @endif
            </div>
            <dl class="card text-xs">
                @foreach ($facts as $field => $rows)
                    <div class="flex flex-col gap-1 border-t border-gray-200 px-4 py-3 first:border-t-0 sm:flex-row sm:gap-4 dark:border-gray-800" wire:key="field-{{ $field }}">
                        <dt class="text-sm font-medium text-gray-900 sm:w-44 sm:shrink-0 dark:text-white">{{ $rows->first()->fieldLabel() }}</dt>
                        <dd class="min-w-0 flex-1 space-y-2">
                            @foreach ($rows as $item)
                                @include('livewire.operator.portfolio.partials.epicrisis-row', ['item' => $item])
                            @endforeach
                        </dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section data-epicrisis-layer="synthesis">
            <div class="section-head">
                <h3 class="section-title">Sentez <span class="section-count">{{ $synthesis->count() }}</span></h3>
            </div>
            <p class="sr-only">AI bilgilerden ve kanıtlardan yazdı; her satır dayandığı kayıtları gösterir ve doğrudan kullanılır. Yanlış bulduğunu düzelt ya da “Yanlış” de.</p>
            @if ($synthesis->isEmpty())
                <p class="card px-4 py-3.5 text-[13px] text-gray-500">Henüz yazılmadı; bilgiler toplanınca AI kuyruğunda yazılır.</p>
            @else
                <ul class="card text-xs">
                    @foreach ($synthesis as $item)
                        <li class="border-t border-gray-200 px-4 py-3 first:border-t-0 dark:border-gray-800" wire:key="s-{{ $item->id }}">@include('livewire.operator.portfolio.partials.epicrisis-row', ['item' => $item, 'label' => true])</li>
                    @endforeach
                </ul>
            @endif
        </section>

        <div class="grid gap-x-6 gap-y-10 lg:grid-cols-2">
            <section data-epicrisis-layer="evidence">
                <div class="section-head">
                    <h3 class="section-title">Kanıt</h3>
                </div>
                <p class="-mt-1 mb-3 text-[13px] text-gray-500">Müşterilerin sorduğu, övdüğü, şikâyet ettiği; reklamda sonuç getiren hizmetler.</p>
                <dl class="card text-xs">
                    @forelse ($evidence as $field => $rows)
                        <div class="border-t border-gray-200 px-4 py-3 first:border-t-0 dark:border-gray-800" wire:key="e-{{ $field }}">
                            <dt class="text-sm font-medium text-gray-900 dark:text-white">{{ $rows->first()->fieldLabel() }}</dt>
                            <dd class="mt-0.5 text-[13px] text-gray-600 dark:text-gray-300">{{ $rows->map(fn ($i): string => $i->value.(data_get($i->detail, 'count') ? ' ('.data_get($i->detail, 'count').')' : ''))->implode(', ') }}</dd>
                        </div>
                    @empty
                        <p class="px-4 py-3.5 text-[13px] text-gray-500">Kanıt yok.</p>
                    @endforelse
                </dl>
            </section>
            <section data-epicrisis-layer="lesson">
                <div class="section-head">
                    <h3 class="section-title">Dersler</h3>
                </div>
                <p class="-mt-1 mb-3 text-[13px] text-gray-500">Reddettiğin öneriler, kontrolde tekrar takılanlar ve işe yarayan yazılar; AI aynı hatayı tekrar etmesin diye.</p>
                <ul class="card text-xs">
                    @forelse ($lessons as $item)
                        <li class="border-t border-gray-200 px-4 py-3 first:border-t-0 dark:border-gray-800" wire:key="l-{{ $item->id }}">@include('livewire.operator.portfolio.partials.epicrisis-row', ['item' => $item, 'label' => true])</li>
                    @empty
                        <li class="px-4 py-3.5 text-[13px] text-gray-500">Henüz ders yok.</li>
                    @endforelse
                </ul>
            </section>
        </div>

        @if ($isAdmin)
            <section data-epicrisis-add>
                <div class="section-head">
                    <h3 class="section-title">Bilgi ekle</h3>
                </div>
                <div class="card p-4">
                    <form wire:submit="add" class="flex flex-wrap items-start gap-2 text-sm">
                        <select wire:model="newField" aria-label="Alan" class="h-9 rounded-lg border border-gray-200 bg-white px-3 text-sm focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-gray-200">
                            <option value="">Alan seç</option>
                            @foreach ($fieldOptions as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <input type="text" wire:model="newScope" aria-label="Hizmet ya da şube" placeholder="Hizmet ya da şube (isteğe bağlı)" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm placeholder:text-gray-400 focus:border-gray-400 focus:outline-none sm:w-56 dark:border-gray-700 dark:bg-transparent dark:text-gray-200">
                        <input type="text" wire:model="newValue" aria-label="Bilgi" placeholder="Bilgi" class="h-9 min-w-48 flex-1 rounded-lg border border-gray-200 bg-white px-3 text-sm placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-gray-200">
                        <button type="submit" class="btn h-9!">Ekle</button>
                    </form>
                    @error('newField') <p class="mt-1 text-xs text-error-600">{{ $message }}</p> @enderror
                    @error('newValue') <p class="mt-1 text-xs text-error-600">{{ $message }}</p> @enderror
                </div>
            </section>
        @endif
    @endif
</div>
