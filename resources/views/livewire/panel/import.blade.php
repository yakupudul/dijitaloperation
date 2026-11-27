@php
    use App\Panel\Models\PanelImport;
    use App\Panel\Services\PanelSheets;
    use App\Panel\Support\PanelSources;
    $tz = 'Europe/Istanbul';
    $input = 'h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-base text-gray-900 outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10 sm:h-9 sm:text-sm';
    $kinds = [PanelImport::LEADS => 'Aday listesi', PanelImport::CALLS => 'Çağrı listesi'];
@endphp
<div class="space-y-6" data-panel-import>
    <header class="space-y-3">
        <a href="{{ route('panel.leads') }}" wire:navigate class="link-neutral text-[13px]">Adaylar</a>
        <div>
            <h2 class="text-[21px] font-semibold leading-7 tracking-[-0.02em] text-gray-900">Excel'den yükle</h2>
            <p class="page-sub">Elinizdeki listeyi tek seferde alın. Aynı telefon numarası ikinci kez eklenmez, var olan kayıt güncellenir.</p>
        </div>
    </header>

    @if (! $import)
        <section class="card max-w-3xl space-y-5 p-5">
            <div>
                <h2 class="mb-2 text-[13px] font-medium text-gray-700">Ne yüklüyorsunuz?</h2>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($kinds as $key => $name)
                        <button type="button" wire:click="$set('kind', '{{ $key }}')" @class(['seg', 'seg-active' => $kind === $key])>{{ $name }}</button>
                    @endforeach
                </div>
                <p class="mt-2 max-w-[70ch] text-sm leading-6 text-gray-600">
                    {{ $kind === PanelImport::CALLS ? 'Santralinizden ya da telefonunuzdan aldığınız arama dökümü. Her satır, o numaranın geçmişine bir çağrı olarak eklenir; numara kayıtlı değilse yeni aday açılır.' : 'Daha önce Excel\'de, defterde ya da başka bir programda tuttuğunuz kişiler. Her satır bir kişidir.' }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <label class="btn-primary cursor-pointer"><input type="file" wire:model="upload" class="sr-only" accept=".xlsx,.csv">Dosya seç</label>
                <a href="{{ route('panel.import.sample', ['tur' => $kind]) }}" class="btn">Örnek dosyayı indir</a>
                <span wire:loading wire:target="upload" class="text-[13px] text-gray-500">Dosya okunuyor</span>
            </div>
            @error('upload')<p class="text-[13px] text-error-600">{{ $message }}</p>@enderror
            <p class="text-[13px] text-gray-500">Excel (.xlsx) ya da CSV, en çok 5.000 satır. İlk satır sütun başlıkları olmalı. Telefon sütunu zorunlu.</p>
        </section>
    @else
        <section class="space-y-5" data-panel-import-map>
            <div class="card overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-[13px] text-gray-500">
                            <th class="px-4 py-2.5 font-medium">Dosyadaki sütun</th>
                            <th class="px-4 py-2.5 font-medium">İlk satırlardan</th>
                            <th class="w-56 px-4 py-2.5 font-medium">Paneldeki alan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($head as $index => $title)
                            <tr class="border-b border-gray-100 last:border-b-0" wire:key="col-{{ $index }}">
                                <td class="px-4 py-2.5 font-medium text-gray-900">{{ $title !== '' ? $title : 'Sütun '.($index + 1) }}</td>
                                <td class="max-w-72 truncate px-4 py-2.5 text-gray-600">{{ collect($samples)->pluck($index)->filter()->implode(' · ') }}</td>
                                <td class="px-4 py-2">
                                    <select wire:model.live="map.{{ $index }}" class="{{ $input }}" aria-label="{{ $title }} sütununun alanı">
                                        <option value="">Alma</option>
                                        @foreach (PanelSheets::FIELDS as $field => [$name])<option value="{{ $field }}">{{ $name }}</option>@endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @error('map')<p class="text-[13px] text-error-600">{{ $message }}</p>@enderror

            @if ($import->kind === PanelImport::LEADS)
                <label class="block max-w-xs"><span class="mb-1.5 block text-[13px] font-medium text-gray-700">Kaynak sütunu boşsa bu kişiler nereden geldi?</span>
                    <select wire:model="source" class="{{ $input }}">
                        @foreach (PanelSources::labels() as $key => $name)<option value="{{ $key }}">{{ $name }}</option>@endforeach
                    </select>
                </label>
            @endif

            <div class="metric-strip max-w-3xl grid-cols-2 sm:grid-cols-4" data-panel-import-preview>
                @foreach ([['Dosyadaki satır', $count], ['Yeni kişi', $preview['new']], ['Güncellenecek kayıt', $preview['updated']], ['Alınmayacak satır', $preview['bad']]] as [$name, $value])
                    <div class="bg-white px-4 py-3"><p class="text-[13px] text-gray-500">{{ $name }}</p><p class="mt-0.5 text-xl font-semibold tabular-nums text-gray-900">{{ number_format($value, 0, ',', '.') }}</p></div>
                @endforeach
            </div>
            @if ($preview['badRows'] !== [])
                <div class="max-w-3xl text-sm text-gray-700">
                    <p class="font-medium text-gray-900">Alınmayacak satırlardan örnekler</p>
                    <ul class="mt-1 space-y-0.5">
                        @foreach ($preview['badRows'] as $bad)<li>{{ $bad['row'] }}. satır: {{ $bad['why'] }}</li>@endforeach
                    </ul>
                </div>
            @endif
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="run" wire:loading.attr="disabled" class="btn-primary">{{ number_format($preview['new'] + $preview['updated'], 0, ',', '.') }} satırı içe aktar</button>
                <button type="button" wire:click="cancel" class="btn-quiet">Vazgeç</button>
            </div>
        </section>
    @endif

    @if ($history->isNotEmpty())
        <section class="max-w-3xl">
            <h2 class="mb-2 text-[13px] font-medium text-gray-500">Önceki yüklemeler</h2>
            <div class="card">
                @foreach ($history as $row)
                    <div class="card-row" wire:key="hist-{{ $row->id }}">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-gray-900">{{ $row->file_name }}</span>
                            <span class="block text-[13px] text-gray-500">{{ $kinds[$row->kind] ?? '' }}, {{ $row->finished_at?->timezone($tz)->translatedFormat('j F Y, H:i') }}, {{ $row->user?->name }}. {{ (int) ($row->stats['new'] ?? 0) }} yeni, {{ (int) ($row->stats['updated'] ?? 0) }} güncellendi, {{ (int) ($row->stats['bad'] ?? 0) }} alınmadı.</span>
                        </span>
                        @if ($sheets->canUndo($row))
                            <button type="button" wire:click="undo({{ $row->id }})" wire:confirm="Bu dosyayla eklenen {{ (int) ($row->stats['new'] ?? 0) }} kişi, üzerlerindeki not ve hatırlatmalarla birlikte silinecek. Geri alınsın mı?" class="btn-quiet">Geri al</button>
                        @elseif ($row->status === 'undone')
                            <x-ta.pill tone="info">Geri alındı</x-ta.pill>
                        @endif
                    </div>
                @endforeach
            </div>
            <p class="mt-2 text-[13px] text-gray-500">Bir yükleme {{ PanelSheets::UNDO_DAYS }} gün içinde geri alınabilir.</p>
        </section>
    @endif
</div>
