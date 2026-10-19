@php
    $card = 'surface';
    $fmt = fn (int $n): string => number_format($n, 0, ',', '.');
@endphp
<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Veri merkezi</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Hangi kaynaktan (hesap ya da web sitesi) ne çekildiği ve sistemde ne saklandığı. Müşteri ya da marka silinse de veri kalır; burada kaynak seçip veri setlerini silebilirsiniz. Sorgular, arama terimleri ve anahtar kelimeler Hizmet Beyni'ni beslediği için korunur ve silinmez.</p>
    </div>

    <div class="grid gap-3 sm:grid-cols-3">
        <div class="{{ $card }} p-4"><div class="text-xs text-gray-500">Kaynak</div><div class="text-xl font-semibold tabular-nums">{{ $fmt($totals['sources']) }}</div></div>
        <div class="{{ $card }} p-4"><div class="text-xs text-gray-500">Hiçbir markaya bağlı olmayan</div><div class="text-xl font-semibold tabular-nums">{{ $fmt($totals['unbound']) }}</div></div>
        <div class="{{ $card }} p-4"><div class="text-xs text-gray-500">Saklanan satır (yaklaşık)</div><div class="text-xl font-semibold tabular-nums">{{ $fmt($totals['rows']) }}</div></div>
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
        <section class="{{ $card }}"><p class="p-5 text-sm text-gray-500">Bu filtrede kaynak yok.</p></section>
    @else
        @foreach ([['bound', $boundSources], ['unbound', $unboundSources]] as [$group, $list])
            @continue($list->isEmpty())
            @if ($group === 'unbound')
                <details class="{{ $card }} group" @if ($unboundOpen) open @endif data-unbound-sources>
                    <summary class="flex cursor-pointer list-none flex-wrap items-center gap-x-2 gap-y-1 px-4 py-3 text-sm [&::-webkit-details-marker]:hidden">
                        <svg class="h-4 w-4 shrink-0 text-gray-400 transition group-open:rotate-90" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                        <span class="font-semibold text-gray-900 dark:text-white">Bağlı olmayan kaynaklar <span class="tabular-nums text-gray-500">({{ $fmt($list->count()) }})</span></span>
                        <span class="text-xs text-gray-500">Bağlı değil · veri çekimi durdu; saklanan veri burada silinebilir.</span>
                    </summary>
                    <ul class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-800 dark:border-gray-800">
            @else
                <section class="{{ $card }}">
                    <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            @endif
                @foreach ($list as $source)
                    <li wire:key="src-{{ $source['key'] }}" class="p-4">
                        <button type="button" wire:click="toggle('{{ $source['key'] }}')" class="flex w-full min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-left">
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ $source['provider'] }}</span>
                            <span class="min-w-0 break-all font-medium text-gray-800 dark:text-gray-200">{{ $source['name'] }}</span>
                            @if ($source['feeds'] !== [])
                                <span class="min-w-0 break-words text-xs text-gray-500">→ {{ implode(', ', $source['feeds']) }}</span>
                            @endif
                            <span class="text-xs tabular-nums text-gray-500 sm:ml-auto">{{ count($source['datasets']) }} veri seti · {{ $fmt($source['rows']) }} satır @if ($source['collected_at']) · son çekim {{ $source['collected_at'] }} @endif</span>
                        </button>

                        @if ($open === $source['key'])
                            <div class="mt-3 overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="text-left text-xs text-gray-500">
                                        <tr><th class="w-8 py-1"></th><th class="py-1">Veri seti</th><th class="py-1 text-right">Satır</th><th class="py-1">Tarih aralığı</th><th class="py-1">Son çekim</th></tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-50 dark:divide-gray-800">
                                        @foreach ($source['datasets'] as $dataset)
                                            <tr wire:key="ds-{{ $source['key'] }}-{{ $dataset['dataset'] }}">
                                                <td class="py-1.5">
                                                    @if ($dataset['protected'])
                                                        <span title="Korunur: Hizmet Beyni'ni besler" aria-label="Korunur">🔒</span>
                                                    @elseif ($isAdmin)
                                                        <input type="checkbox" value="{{ $dataset['dataset'] }}" wire:model.live="picked.{{ $source['key'] }}" aria-label="Seç" class="size-4 rounded border-gray-300">
                                                    @endif
                                                </td>
                                                <td class="py-1.5">{{ $dataset['label'] }} @if ($dataset['protected'])<span class="text-xs text-gray-500">· korunur</span>@endif</td>
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
                                            class="rounded-lg px-3 py-1.5 text-xs font-semibold text-error-700 ring-1 ring-inset ring-error-200 hover:bg-error-50 dark:text-error-300 dark:ring-error-500/30">Seçili {{ $pickedCount }} veri setini sil</button>
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
