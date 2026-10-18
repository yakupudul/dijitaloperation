{{-- Marka epikrizi (yakup, 2026-10-16): the record every AI step reads, by layer, with its open questions. --}}
<div class="space-y-4" data-epicrisis>
    @php
        $counts = (array) ($head?->counts ?? []);
        $confidence = \App\Models\BrandEpicrisisItem::CONFIDENCE_LABELS;
        $badge = fn (string $tone): string => match ($tone) {
            'verified' => 'bg-success-50 text-success-700 ring-success-200 dark:bg-success-500/10 dark:text-success-400 dark:ring-success-500/30',
            'warn' => 'bg-warning-50 text-warning-700 ring-warning-200 dark:bg-warning-500/10 dark:text-warning-400 dark:ring-warning-500/30',
            'error' => 'bg-error-50 text-error-700 ring-error-200 dark:bg-error-500/10 dark:text-error-400 dark:ring-error-500/30',
            default => 'bg-gray-50 text-gray-600 ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700',
        };
        $source = fn (\App\Models\BrandEpicrisisItem $i): ?string => is_string($i->source_ref) && str_starts_with($i->source_ref, 'http') ? $i->source_ref : null;
    @endphp

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Marka epikrizi · %{{ (int) ($head?->fill_percent ?? 0) }} dolu</h2>
                <p class="mt-0.5 text-xs text-gray-500">Yazılar, gönderiler, reklamlar ve yorum cevapları bu kaydı okur. Doğrulanmış ve veriden gelen bilgiler kesin bilgi olarak kullanılır; çelişkili ya da süresi dolmuş bilgi hiçbir işe girmez.</p>
                @if ($head !== null)
                    <p class="mt-1 text-xs text-gray-500" data-epicrisis-counts>
                        {{ $counts['known'] ?? 0 }}/{{ $counts['required'] ?? 0 }} zorunlu bilgi · {{ $counts['facts'] ?? 0 }} bilgi ({{ $counts['verified'] ?? 0 }} doğrulandı) · {{ $counts['synthesis'] ?? 0 }} sentez · {{ $counts['evidence'] ?? 0 }} kanıt · {{ $counts['lessons'] ?? 0 }} ders
                        @if ($head->built_at)
                            · {{ $head->built_at->timezone('Europe/Istanbul')->format('d.m H:i') }}
                        @endif
                    </p>
                @endif
            </div>
            @if ($isAdmin)
                <button type="button" wire:click="rebuild" wire:loading.attr="disabled" class="shrink-0 rounded-md px-2.5 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-800" data-epicrisis-rebuild>Şimdi güncelle</button>
            @endif
        </div>
        @if ($notice)
            <p class="mt-2 text-xs font-medium text-success-700 dark:text-success-400" role="status">{{ $notice }}</p>
        @elseif ($queuedAt && ($head?->built_at === null || $head->built_at->lt(\Carbon\Carbon::parse($queuedAt))))
            <p class="mt-2 text-xs text-gray-500" role="status">Güncelleme sırada.</p>
        @endif
        @if ($head === null)
            <p class="mt-3 text-xs text-gray-500">Bu markanın epikrizi henüz hazırlanmadı; gece kendiliğinden hazırlanır.</p>
        @elseif ($head->summary)
            @php $positioning = $synthesis->firstWhere('field', 'positioning'); @endphp
            @if ($positioning)
                <p class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-800 dark:bg-gray-800/60 dark:text-gray-100" data-epicrisis-positioning>{{ $positioning->value }}</p>
            @endif
        @endif
    </section>

    @if ($questions->isNotEmpty())
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-warning-200 dark:bg-gray-900 dark:ring-warning-500/30" data-epicrisis-questions>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Sana soracaklarım · {{ $questions->count() }}</h3>
            <p class="text-xs text-gray-500">Cevabın doğrulanmış bilgi olur ve tüm AI işlerine girer. Bilmiyorsan “Gerek yok” de, bir daha sorulmaz.</p>
            <ul class="mt-2 divide-y divide-gray-100 text-xs dark:divide-gray-800">
                @foreach ($questions as $question)
                    <li class="py-2.5" wire:key="q-{{ $question->id }}" data-question="{{ $question->id }}" data-question-kind="{{ data_get($question->detail, 'kind') }}">
                        <p class="font-medium text-gray-900 dark:text-white">{{ $question->value }}</p>
                        @if ($isAdmin)
                            <div class="mt-1.5 flex flex-wrap items-center gap-2">
                                @foreach ((array) data_get($question->detail, 'options', []) as $option)
                                    <button type="button" wire:click="answer({{ $question->id }}, @js((string) $option['value']))" class="rounded-md bg-white px-2 py-1 font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-200 dark:ring-gray-700">
                                        {{ data_get($question->detail, 'kind') === 'expired' ? 'Hâlâ geçerli' : $option['value'] }}
                                    </button>
                                @endforeach
                                <input type="text" wire:model="answers.{{ $question->id }}" wire:keydown.enter="answer({{ $question->id }})" aria-label="Cevap" placeholder="{{ data_get($question->detail, 'kind') === 'missing' ? 'Cevabı yaz' : 'Ya da doğrusunu yaz' }}"
                                    class="min-w-48 flex-1 rounded-md border-gray-200 py-1 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200">
                                <button type="button" wire:click="answer({{ $question->id }})" class="rounded-md bg-brand-500 px-2.5 py-1 font-semibold text-white hover:bg-brand-600">Kaydet</button>
                                <button type="button" wire:click="dismiss({{ $question->id }})" class="font-medium text-gray-500 hover:underline" data-question-dismiss>Gerek yok</button>
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
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-epicrisis-layer="fact">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">1 · Bilgiler</h3>
            @if ($missing->isNotEmpty())
                <p class="mt-1 text-xs text-warning-700 dark:text-warning-400" data-epicrisis-missing>Eksik: {{ $missing->implode(', ') }}</p>
            @endif
            <dl class="mt-2 divide-y divide-gray-100 text-xs dark:divide-gray-800">
                @foreach ($facts as $field => $rows)
                    <div class="flex flex-wrap gap-x-3 gap-y-1 py-2" wire:key="field-{{ $field }}">
                        <dt class="w-40 shrink-0 font-semibold text-gray-900 dark:text-white">{{ $rows->first()->fieldLabel() }}</dt>
                        <dd class="min-w-0 flex-1 space-y-1.5">
                            @foreach ($rows as $item)
                                @include('livewire.operator.portfolio.partials.epicrisis-row', ['item' => $item])
                            @endforeach
                        </dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-epicrisis-layer="synthesis">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">2 · Sentez</h3>
            <p class="text-xs text-gray-500">AI bilgilerden ve kanıtlardan yazdı; her satır dayandığı kayıtları gösterir ve doğrudan kullanılır. Yanlış bulduğunu düzelt ya da “Yanlış” de.</p>
            @if ($synthesis->isEmpty())
                <p class="mt-2 text-xs text-gray-400">Henüz yazılmadı; bilgiler toplanınca AI kuyruğunda yazılır.</p>
            @else
                <ul class="mt-2 space-y-1.5 text-xs">
                    @foreach ($synthesis as $item)
                        <li wire:key="s-{{ $item->id }}">@include('livewire.operator.portfolio.partials.epicrisis-row', ['item' => $item, 'label' => true])</li>
                    @endforeach
                </ul>
            @endif
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-epicrisis-layer="evidence">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">3 · Kanıt</h3>
                <p class="text-xs text-gray-500">Müşterilerin sorduğu, övdüğü, şikâyet ettiği; reklamda sonuç getiren hizmetler.</p>
                <dl class="mt-2 space-y-2 text-xs">
                    @forelse ($evidence as $field => $rows)
                        <div wire:key="e-{{ $field }}">
                            <dt class="font-semibold text-gray-900 dark:text-white">{{ $rows->first()->fieldLabel() }}</dt>
                            <dd class="text-gray-700 dark:text-gray-300">{{ $rows->map(fn ($i): string => $i->value.(data_get($i->detail, 'count') ? ' ('.data_get($i->detail, 'count').')' : ''))->implode(' · ') }}</dd>
                        </div>
                    @empty
                        <p class="text-gray-400">Kanıt yok.</p>
                    @endforelse
                </dl>
            </section>
            <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-epicrisis-layer="lesson">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">4 · Dersler</h3>
                <p class="text-xs text-gray-500">Reddettiğin öneriler, kontrolde tekrar takılanlar ve işe yarayan yazılar; AI aynı hatayı tekrar etmesin diye.</p>
                <ul class="mt-2 space-y-1.5 text-xs">
                    @forelse ($lessons as $item)
                        <li wire:key="l-{{ $item->id }}">@include('livewire.operator.portfolio.partials.epicrisis-row', ['item' => $item, 'label' => true])</li>
                    @empty
                        <li class="text-gray-400">Henüz ders yok.</li>
                    @endforelse
                </ul>
            </section>
        </div>

        @if ($isAdmin)
            <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-epicrisis-add>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Bilgi ekle</h3>
                <form wire:submit="add" class="mt-2 flex flex-wrap items-start gap-2 text-xs">
                    <select wire:model="newField" aria-label="Alan" class="rounded-md border-gray-200 py-1 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200">
                        <option value="">Alan seç</option>
                        @foreach ($fieldOptions as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <input type="text" wire:model="newScope" aria-label="Hizmet ya da şube" placeholder="Hizmet ya da şube (isteğe bağlı)" class="w-48 rounded-md border-gray-200 py-1 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200">
                    <input type="text" wire:model="newValue" aria-label="Bilgi" placeholder="Bilgi" class="min-w-48 flex-1 rounded-md border-gray-200 py-1 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200">
                    <button type="submit" class="rounded-md bg-brand-500 px-2.5 py-1 font-semibold text-white hover:bg-brand-600">Ekle</button>
                </form>
                @error('newField') <p class="mt-1 text-xs text-error-600">{{ $message }}</p> @enderror
                @error('newValue') <p class="mt-1 text-xs text-error-600">{{ $message }}</p> @enderror
            </section>
        @endif
    @endif
</div>
