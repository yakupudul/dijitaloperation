@php
    $card = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $fmt = fn (int $n): string => number_format($n, 0, ',', '.');
@endphp
<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Veri merkezi</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Hangi kaynaktan (hesap ya da web sitesi) ne çekildiği ve sistemde ne saklandığı. Müşteri ya da marka silinse de veri kalır; burada kaynak seçip veri setlerini silebilirsiniz. Sorgular, arama terimleri ve anahtar kelimeler Hizmet Beyni'ni beslediği için korunur ve silinmez.</p>
    </div>

    <div class="grid gap-3 sm:grid-cols-3">
        <div class="{{ $card }} p-4"><div class="text-xs text-gray-500">Kaynak</div><div class="text-xl font-semibold">{{ $fmt($totals['sources']) }}</div></div>
        <div class="{{ $card }} p-4"><div class="text-xs text-gray-500">Hiçbir markaya bağlı olmayan</div><div class="text-xl font-semibold">{{ $fmt($totals['unbound']) }}</div></div>
        <div class="{{ $card }} p-4"><div class="text-xs text-gray-500">Saklanan satır (yaklaşık)</div><div class="text-xl font-semibold">{{ $fmt($totals['rows']) }}</div></div>
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <label class="text-sm"><span class="block text-xs text-gray-500">Kaynak türü</span>
            <select wire:model.live="provider" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                <option value="">Tümü</option>
                @foreach ($providers as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach
            </select>
        </label>
        <label class="text-sm"><span class="block text-xs text-gray-500">Ara</span>
            <input type="search" wire:model.live.debounce.400ms="q" placeholder="Hesap, site ya da marka" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
        </label>
        <label class="flex items-center gap-2 pb-2 text-sm"><input type="checkbox" wire:model.live="unbound" class="size-4 rounded border-gray-300"> Yalnızca bağlı olmayanlar</label>
    </div>

    <section class="{{ $card }}">
        @if ($sources->isEmpty())
            <p class="p-5 text-sm text-gray-500">Bu filtrede kaynak yok.</p>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($sources as $source)
                    <li wire:key="src-{{ $source['key'] }}" class="p-4">
                        <button type="button" wire:click="toggle('{{ $source['key'] }}')" class="flex w-full flex-wrap items-center gap-x-3 gap-y-1 text-left">
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ $source['provider'] }}</span>
                            <span class="font-medium text-gray-800 dark:text-gray-200">{{ $source['name'] }}</span>
                            @if ($source['feeds'] !== [])
                                <span class="text-xs text-gray-500">→ {{ implode(', ', $source['feeds']) }}</span>
                            @endif
                            @unless ($source['bound'])
                                <span class="rounded-full bg-warning-50 px-2 py-0.5 text-xs text-warning-700">Bağlı değil · veri çekimi durdu</span>
                            @endunless
                            <span class="ml-auto text-xs text-gray-500">{{ count($source['datasets']) }} veri seti · {{ $fmt($source['rows']) }} satır @if ($source['collected_at']) · son çekim {{ $source['collected_at'] }} @endif</span>
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
                                    <button type="button" wire:click="pickAll('{{ $source['key'] }}')" class="text-xs text-brand-600 hover:underline">Korunanlar dışındakilerin hepsini seç</button>
                                    @if (($picked[$source['key']] ?? []) !== [])
                                        <button type="button" wire:click="erase('{{ $source['key'] }}')" wire:confirm="Seçili {{ count($picked[$source['key']]) }} veri seti kalıcı olarak silinsin mi? Geri alınamaz. Kaynak hâlâ bir markaya bağlıysa yeni günler çekilmeye devam eder."
                                            class="rounded-lg bg-error-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-error-600">Seçili {{ count($picked[$source['key']]) }} veri setini sil</button>
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
        @endif
    </section>
</div>
