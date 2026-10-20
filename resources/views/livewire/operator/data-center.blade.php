@php
    $fmt = fn (int $n): string => number_format($n, 0, ',', '.');
    $input = 'h-9 rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white';
@endphp
<div class="space-y-10">
    @include('livewire.demo.partials.flash')

    <div>
        <h1 class="page-title" title="Hangi kaynaktan (hesap ya da web sitesi) ne çekildiği ve sistemde ne saklandığı. Müşteri ya da marka silinse de veri kalır; burada kaynak seçip veri setlerini silebilirsiniz.">Veri merkezi</h1>
        <p class="page-sub">Sorgular, arama terimleri ve anahtar kelimeler Hizmet Beyni'ni beslediği için korunur ve silinmez.</p>
    </div>

    <div class="metric-strip -mt-4 grid-cols-2 sm:grid-cols-3">
        <x-ta.metric-card label="Kaynak" :value="$fmt($totals['sources'])" />
        <x-ta.metric-card label="Hiçbir markaya bağlı olmayan" :value="$fmt($totals['unbound'])" />
        <x-ta.metric-card label="Saklanan satır (yaklaşık)" :value="$fmt($totals['rows'])" class="col-span-2 sm:col-span-1" />
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <select wire:model.live="provider" aria-label="Kaynak türü" class="{{ $input }} w-full sm:w-48">
            <option value="">Tüm kaynak türleri</option>
            @foreach ($providers as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach
        </select>
        <input type="search" wire:model.live.debounce.400ms="q" placeholder="Hesap, site ya da marka" aria-label="Ara" class="{{ $input }} w-full min-w-0 sm:w-72">
        <label class="inline-flex h-9 items-center gap-2 text-sm text-gray-700 sm:ml-2 dark:text-gray-300"><input type="checkbox" wire:model.live="unbound" class="size-4 rounded border-gray-300"> Yalnızca bağlı olmayanlar</label>
    </div>

    @php
        // Unconnected sources are grouped once (2026-10-19: 109 rows each carried the same yellow badge).
        $boundSources = $sources->filter(fn (array $source): bool => (bool) $source['bound'])->values();
        $unboundSources = $sources->reject(fn (array $source): bool => (bool) $source['bound'])->values();
        $unboundOpen = $unbound || $boundSources->isEmpty() || $unboundSources->contains(fn (array $source): bool => $source['key'] === $open);
    @endphp
    @if ($sources->isEmpty())
        <div class="card -mt-4">
            <x-ta.empty-state title="Bu filtrede kaynak yok." size="sm" />
        </div>
    @else
        @foreach ([['bound', $boundSources], ['unbound', $unboundSources]] as [$group, $list])
            @continue($list->isEmpty())
            @if ($group === 'unbound')
                <details class="group/unb" @if ($unboundOpen) open @endif data-unbound-sources>
                    <summary class="section-head cursor-pointer list-none items-center! [&::-webkit-details-marker]:hidden">
                        <svg class="h-4 w-4 shrink-0 text-gray-400 transition group-open/unb:rotate-90" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                        <h2 class="section-title -ml-1.5">Bağlı olmayan kaynaklar <span class="section-count">{{ $fmt($list->count()) }}</span></h2>
                        <span class="w-full text-[13px] text-gray-500 sm:w-auto">Bağlı değil, veri çekimi durdu; saklanan veri burada silinebilir.</span>
                    </summary>
                    <ul class="card">
            @else
                <section>
                    <div class="section-head"><h2 class="section-title">Bağlı kaynaklar <span class="section-count">{{ $fmt($list->count()) }}</span></h2></div>
                    <ul class="card">
            @endif
                @foreach ($list as $source)
                    @php($isOpen = $open === $source['key'])
                    <li wire:key="src-{{ $source['key'] }}" class="border-t border-gray-200 first:border-t-0 dark:border-gray-800">
                        <button type="button" wire:click="toggle('{{ $source['key'] }}')" aria-expanded="{{ $isOpen ? 'true' : 'false' }}" class="flex w-full min-w-0 items-center gap-3.5 px-4 py-3.5 text-left hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                            <span class="min-w-0 flex-1">
                                <span class="block break-all text-sm font-medium text-gray-900 dark:text-white">{{ $source['name'] }}</span>
                                <span class="mt-0.5 flex flex-wrap gap-x-3 text-[13px] text-gray-500 dark:text-gray-400">
                                    <span>{{ $source['provider'] }}</span>
                                    @if ($source['feeds'] !== [])<span class="min-w-0 break-words">Besler: {{ implode(', ', $source['feeds']) }}</span>@endif
                                    @if ($source['collected_at'])<span class="tabular-nums">Son çekim {{ $source['collected_at'] }}</span>@endif
                                </span>
                            </span>
                            <span class="shrink-0 text-right tabular-nums">
                                <span class="block text-sm text-gray-900 dark:text-white">{{ $fmt($source['rows']) }} satır</span>
                                <span class="block text-xs text-gray-500">{{ count($source['datasets']) }} veri seti</span>
                            </span>
                            <svg @class(['h-4 w-4 shrink-0 text-gray-400 transition', 'rotate-90' => $isOpen]) viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                        </button>

                        @if ($isOpen)
                            <div class="overflow-x-auto border-t border-gray-200 dark:border-gray-800">
                                <table class="data-table">
                                    <thead>
                                        <tr><th class="w-8 pr-0!"><span class="sr-only">Seç</span></th><th>Veri seti</th><th class="text-right!">Satır</th><th>Tarih aralığı</th><th class="hidden sm:table-cell">Son çekim</th></tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($source['datasets'] as $dataset)
                                            <tr wire:key="ds-{{ $source['key'] }}-{{ $dataset['dataset'] }}">
                                                <td class="pr-0!">
                                                    @if ($dataset['protected'])
                                                        <span title="Korunur: Hizmet Beyni'ni besler" aria-label="Korunur"><svg class="h-3.5 w-3.5 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1Zm3 8V5.5a3 3 0 1 0-6 0V9h6Z" clip-rule="evenodd"/></svg></span>
                                                    @elseif ($isAdmin)
                                                        <input type="checkbox" value="{{ $dataset['dataset'] }}" wire:model.live="picked.{{ $source['key'] }}" aria-label="Seç" class="size-4 rounded border-gray-300">
                                                    @endif
                                                </td>
                                                <td class="text-gray-900 dark:text-white">{{ $dataset['label'] }} @if ($dataset['protected'])<span class="text-xs text-gray-500">korunur</span>@endif</td>
                                                <td class="text-right tabular-nums">{{ $fmt($dataset['rows']) }}</td>
                                                <td class="whitespace-nowrap text-gray-500 tabular-nums">{{ $dataset['from'] ? $dataset['from'].' – '.$dataset['through'] : '—' }}</td>
                                                <td class="hidden whitespace-nowrap text-gray-500 tabular-nums sm:table-cell">{{ $dataset['collected_at'] ?? '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if ($isAdmin)
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-gray-200 bg-gray-50/60 px-4 py-3 dark:border-gray-800 dark:bg-white/[0.02]">
                                    <button type="button" wire:click="pickAll('{{ $source['key'] }}')" class="btn-quiet">Korunanlar dışındakilerin hepsini seç</button>
                                    @php($pickedCount = count(\App\Livewire\Operator\DataCenterPage::selection($picked[$source['key']] ?? [])))
                                    @if ($pickedCount > 0)
                                        <button type="button" wire:click="erase('{{ $source['key'] }}')" wire:confirm="Seçili {{ $pickedCount }} veri seti kalıcı olarak silinsin mi? Geri alınamaz. Kaynak hâlâ bir markaya bağlıysa yeni günler çekilmeye devam eder."
                                            class="btn">Seçili {{ $pickedCount }} veri setini sil</button>
                                    @endif
                                    @if ($source['bound'])
                                        <span class="w-full text-xs text-gray-500 sm:ml-auto sm:w-auto sm:text-right">Bu kaynak bağlı: silinse de yeni günler çekilmeye devam eder. Durdurmak için bağlantıyı kaldırın.</span>
                                    @endif
                                </div>
                            @endif
                        @endif
                    </li>
                @endforeach
                    </ul>
            @if ($group === 'unbound')
                </details>
            @else
                </section>
            @endif
        @endforeach
    @endif
</div>
