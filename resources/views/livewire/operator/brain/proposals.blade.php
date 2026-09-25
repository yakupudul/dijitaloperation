@php
    $card = 'rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $sourceLabels = ['rule' => 'Kural', 'system' => 'Sistem', 'vector' => 'Benzerlik', 'ai' => 'AI'];
    $statusLabels = ['pending' => 'Bekliyor', 'applied' => 'Uygulandı', 'rejected' => 'Reddedildi', 'failed' => 'Uygulanamadı', 'stale' => 'Eskidi', 'nothing' => 'Uygun hizmet yok'];
@endphp
<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Onay kuyruğu</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Sistem ve AI yorucu işleri hazırlar, burada toplu incelenir. Onaylanan öneri sistem tarafından uygulanır; reddedilen tekrar önerilmez. Hiçbir öneri onaysız bir şeyi değiştirmez.</p>
    </div>

    <section class="{{ $card }}">
        <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Hazırlat</h2>
        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($kinds as $key => $info)
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ $info['label'] }}</span>
                        @if ($info['pending'] > 0)
                            <button type="button" wire:click="$set('kind', '{{ $key }}')" class="text-xs text-brand-600 hover:underline">{{ $info['pending'] }} bekliyor</button>
                        @endif
                    </div>
                    <div class="mt-2 flex items-center gap-2">
                        <x-ta.button size="sm" variant="outline" wire:click="prepare('{{ $key }}')" wire:loading.attr="disabled">{{ $info['ai'] ? 'AI ile hazırla' : 'Hazırla' }}</x-ta.button>
                        @if ($info['state'] === 'running')
                            <span class="text-xs text-gray-500">Hazırlanıyor…</span>
                        @elseif (str_starts_with((string) $info['state'], 'done: '))
                            <span class="text-xs text-success-600">{{ substr($info['state'], 6) }} yeni öneri</span>
                        @elseif (str_starts_with((string) $info['state'], 'failed: '))
                            <span class="text-xs text-error-600" title="{{ substr($info['state'], 8) }}">Hata: {{ \Illuminate\Support\Str::limit(substr($info['state'], 8), 60) }}</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <div class="flex flex-wrap items-end gap-3">
        <label class="text-sm">
            <span class="block text-xs text-gray-500">Tür</span>
            <select wire:model.live="kind" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                <option value="">Tümü</option>
                @foreach ($kinds as $key => $info)
                    <option value="{{ $key }}">{{ $info['label'] }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm">
            <span class="block text-xs text-gray-500">Durum</span>
            <select wire:model.live="status" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                @foreach ($statusLabels as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
                <option value="all">Tümü</option>
            </select>
        </label>
        <label class="text-sm">
            <span class="block text-xs text-gray-500">En az güven</span>
            <select wire:model.live="min" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                @foreach ([0, 50, 70, 80, 90] as $value)
                    <option value="{{ $value }}">{{ $value === 0 ? 'Hepsi' : '%'.$value }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm">
            <span class="block text-xs text-gray-500">Ara</span>
            <input type="search" wire:model.live.debounce.400ms="search" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900" placeholder="sorgu, hizmet, hesap…">
        </label>
        @if ($status === 'pending' && $min >= 50)
            <x-ta.button size="sm" wire:click="approveAboveMin" wire:confirm="Bu filtredeki güveni en az %{{ $min }} olan TÜM bekleyen öneriler uygulanacak. Devam edilsin mi?">Güveni ≥ %{{ $min }} olanların hepsini onayla</x-ta.button>
        @endif
    </div>

    @if ($selected !== [])
        <div class="flex flex-wrap items-center gap-3 rounded-lg bg-brand-50 px-4 py-2 text-sm dark:bg-brand-500/10">
            <span class="font-medium text-brand-700 dark:text-brand-300">{{ count($selected) }} öneri seçili</span>
            <x-ta.button size="sm" wire:click="approveSelected">Seçilenleri onayla</x-ta.button>
            <x-ta.button size="sm" variant="outline" wire:click="rejectSelected">Seçilenleri reddet</x-ta.button>
        </div>
    @endif

    <section class="{{ $card }} overflow-x-auto p-0">
        @if ($rows->isEmpty())
            <p class="p-5 text-sm text-gray-500">Bu filtrede öneri yok. Yukarıdan bir iş hazırlatabilirsin.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-left text-xs text-gray-500 dark:border-gray-800">
                        <th class="w-8 px-4 py-2">
                            @if ($visibleIds !== [])
                                <input type="checkbox" wire:click="toggleAll(@js($visibleIds))" @checked(count(array_intersect($selected, $visibleIds)) === count($visibleIds)) class="rounded border-gray-300">
                            @endif
                        </th>
                        <th class="py-2">Öneri</th>
                        <th class="py-2">Güven</th>
                        <th class="py-2">Kaynak</th>
                        <th class="px-4 py-2">Durum</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($rows as $row)
                        @php
                            $pct = $row->confidence !== null ? (int) round($row->confidence * 100) : null;
                            $tone = $pct === null ? 'text-gray-500' : ($pct >= 80 ? 'text-success-600' : ($pct >= 60 ? 'text-warning-700' : 'text-error-600'));
                        @endphp
                        <tr wire:key="proposal-{{ $row->id }}" class="align-top">
                            <td class="px-4 py-2">
                                @if ($row->status === 'pending')
                                    <input type="checkbox" value="{{ $row->id }}" wire:model.live="selected" class="rounded border-gray-300">
                                @endif
                            </td>
                            <td class="py-2 pr-3">
                                <div class="font-medium text-gray-800 dark:text-gray-200">{{ $row->title }}</div>
                                @if ($row->brand)
                                    <div class="text-xs text-gray-500">{{ $row->brand->name }}</div>
                                @endif
                                @if ($row->reason)
                                    <div class="mt-0.5 text-xs text-gray-500">{{ $row->reason }}</div>
                                @endif
                                @if (! empty($row->proposed['items']) && is_array($row->proposed['items']))
                                    <div class="mt-1 text-xs text-gray-600 dark:text-gray-400">{{ \Illuminate\Support\Str::limit(implode(' · ', array_map('strval', array_slice($row->proposed['items'], 0, 12))), 300) }}</div>
                                @endif
                                @if ($row->error)
                                    <div class="mt-0.5 text-xs text-error-600">{{ $row->error }}</div>
                                @endif
                            </td>
                            <td class="py-2 {{ $tone }}">{{ $pct !== null ? '%'.$pct : '—' }}</td>
                            <td class="py-2 text-xs text-gray-500">{{ $sourceLabels[$row->source] ?? $row->source }}</td>
                            <td class="px-4 py-2 text-xs text-gray-500">{{ $statusLabels[$row->status] ?? $row->status }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="p-4">{{ $rows->links() }}</div>
        @endif
    </section>
</div>
