@php
    $fmt = fn (int $n): string => number_format($n, 0, ',', '.');
@endphp
<div class="space-y-10">
    @include('livewire.demo.partials.flash')

    <div>
        <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white" title="Hangi kaynaktan (hesap ya da web sitesi) ne çekildiği ve sistemde ne saklandığı. Müşteri ya da marka silinse de veri kalır; burada kaynak seçip veri setlerini silebilirsiniz.">Veri merkezi</h1>
        <p class="mt-1 text-sm text-gray-500">Sorgular, arama terimleri ve anahtar kelimeler Hizmet Beyni'ni beslediği için korunur ve silinmez.</p>
    </div>

    <div class="grid grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-3 lg:grid-cols-4">
        <x-ta.metric-card label="Kaynak" :value="$fmt($totals['sources'])" />
        <x-ta.metric-card label="Hiçbir markaya bağlı olmayan" :value="$fmt($totals['unbound'])" />
        <x-ta.metric-card label="Saklanan satır (yaklaşık)" :value="$fmt($totals['rows'])" />
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <label class="text-sm"><span class="block text-xs text-gray-500">Kaynak türü</span>
            <select wire:model.live="provider" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                <option value="">Tümü</option>
                @foreach ($providers as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach
            </select>
        </label>
        <label class="min-w-0 flex-1 text-sm sm:flex-none"><span class="block text-xs text-gray-500">Ara</span>
            <input type="search" wire:model.live.debounce.400ms="q" placeholder="Hesap, site ya da marka" class="mt-1 w-full min-w-0 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
        </label>
        <label class="flex items-center gap-2 pb-2 text-sm"><input type="checkbox" wire:model.live="unbound" class="size-4 rounded border-gray-300"> Yalnızca bağlı olmayanlar</label>
    </div>

    @php
        // Unconnected sources are grouped once (2026-10-19: 109 rows each carried the same yellow badge).
        $boundSources = $sources->filter(fn (array $source): bool => (bool) $source['bound'])->values();
        $unboundSources = $sources->reject(fn (array $source): bool => (bool) $source['bound'])->values();
        $unboundOpen = $unbound || $boundSources->isEmpty() || $unboundSources->contains(fn (array $source): bool => $source['key'] === $open);
    @endphp
    @if ($sources->isEmpty())
        <p class="text-sm text-gray-500">Bu filtrede kaynak yok.</p>
    @else
        @foreach ([['bound', $boundSources], ['unbound', $unboundSources]] as [$group, $list])
            @continue($list->isEmpty())
            @if ($group === 'unbound')
                <details class="group" @if ($unboundOpen) open @endif data-unbound-sources>
                    <summary class="flex cursor-pointer list-none flex-wrap items-center gap-x-2 gap-y-1 py-3 text-sm [&::-webkit-details-marker]:hidden">
                        <svg class="h-4 w-4 shrink-0 text-gray-400 transition group-open:rotate-90" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                        <span class="section-title">Bağlı olmayan kaynaklar <span class="text-sm font-normal tabular-nums text-gray-500">{{ $fmt($list->count()) }}</span></span>
                        <span class="text-xs text-gray-500">Bağlı değil, veri çekimi durdu; saklanan veri burada silinebilir.</span>
                    </summary>
                    <ul class="divide-y divide-gray-200 border-t border-gray-200 dark:divide-gray-800 dark:border-gray-800">
            @else
                <section>
                    <ul class="divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800">
            @endif
                @foreach ($list as $source)
                    <li wire:key="src-{{ $source['key'] }}" class="py-2.5">
                        <button type="button" wire:click="toggle('{{ $source['key'] }}')" class="-mx-2 flex w-[calc(100%+1rem)] min-w-0 flex-wrap items-center gap-x-3 gap-y-1 rounded-md px-2 py-1 text-left hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                            <x-ta.pill tone="info">{{ $source['provider'] }}</x-ta.pill>
                            <span class="min-w-0 break-all font-medium text-gray-800 dark:text-gray-200">{{ $source['name'] }}</span>
                            @if ($source['feeds'] !== [])
                                <span class="min-w-0 break-words text-xs text-gray-500">Besler: {{ implode(', ', $source['feeds']) }}</span>
                            @endif
                            <span class="text-xs tabular-nums text-gray-500 sm:ml-auto">{{ count($source['datasets']) }} veri seti, {{ $fmt($source['rows']) }} satır @if ($source['collected_at']) · son çekim {{ $source['collected_at'] }} @endif</span>
                        </button>

                        @if ($open === $source['key'])
                            <div class="mt-3 overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-gray-800">
                                        <tr><th class="w-8 py-1.5 font-normal"></th><th class="py-1.5 font-normal">Veri seti</th><th class="py-1.5 text-right font-normal">Satır</th><th class="py-1.5 font-normal">Tarih aralığı</th><th class="py-1.5 font-normal">Son çekim</th></tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                                        @foreach ($source['datasets'] as $dataset)
                                            <tr wire:key="ds-{{ $source['key'] }}-{{ $dataset['dataset'] }}">
                                                <td class="py-1.5">
                                                    @if ($dataset['protected'])
                                                        <span title="Korunur: Hizmet Beyni'ni besler" aria-label="Korunur"><svg class="h-3.5 w-3.5 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1Zm3 8V5.5a3 3 0 1 0-6 0V9h6Z" clip-rule="evenodd"/></svg></span>
                                                    @elseif ($isAdmin)
                                                        <input type="checkbox" value="{{ $dataset['dataset'] }}" wire:model.live="picked.{{ $source['key'] }}" aria-label="Seç" class="size-4 rounded border-gray-300">
                                                    @endif
                                                </td>
                                                <td class="py-1.5">{{ $dataset['label'] }} @if ($dataset['protected'])<span class="text-xs text-gray-500">korunur</span>@endif</td>
                                                <td class="py-1.5 text-right tabular-nums">{{ $fmt($dataset['rows']) }}</td>
                                                <td class="py-1.5 text-gray-500">{{ $dataset['from'] ? $dataset['from'].' – '.$dataset['through'] : '—' }}</td>
                                                <td class="py-1.5 text-gray-500">{{ $dataset['collected_at'] ?? '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if ($isAdmin)
                                <div class="mt-3 flex flex-wrap items-center gap-3">
                                    <button type="button" wire:click="pickAll('{{ $source['key'] }}')" class="link-neutral text-xs">Korunanlar dışındakilerin hepsini seç</button>
                                    @php($pickedCount = count(\App\Livewire\Operator\DataCenterPage::selection($picked[$source['key']] ?? [])))
                                    @if ($pickedCount > 0)
                                        <button type="button" wire:click="erase('{{ $source['key'] }}')" wire:confirm="Seçili {{ $pickedCount }} veri seti kalıcı olarak silinsin mi? Geri alınamaz. Kaynak hâlâ bir markaya bağlıysa yeni günler çekilmeye devam eder."
                                            class="rounded-lg px-3 py-1.5 text-xs font-semibold text-error-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-error-300 dark:ring-gray-700">Seçili {{ $pickedCount }} veri setini sil</button>
                                    @endif
                                    @if ($source['bound'])
                                        <span class="text-xs text-gray-500">Bu kaynak bağlı: silinse de yeni günler çekilmeye devam eder. Durdurmak için bağlantıyı kaldırın.</span>
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
