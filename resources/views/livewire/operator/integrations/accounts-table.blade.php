@php
    use App\Livewire\Operator\Integrations\AccountsTable;

    $small = 'h-8 min-w-0 rounded-lg border border-gray-200 bg-white px-2.5 text-[13px] text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
@endphp
{{-- Hesaplar (Aşama 6): one table for every ad and profile account; the brand page shows the same table for its own accounts. --}}
<section class="min-w-0 space-y-4" data-accounts-table>
    <div class="flex flex-wrap items-center gap-2">
        <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Durum">
            <button type="button" wire:click="$set('state', '')" aria-pressed="{{ $state === '' ? 'true' : 'false' }}" class="{{ $state === '' ? 'seg seg-active' : 'seg' }} h-8! px-3!">Tümü <span class="tabular-nums text-gray-500">{{ $counts[''] }}</span></button>
            @foreach (AccountsTable::STATES as $key => [$label])
                @if ($counts[$key] > 0)
                    <button type="button" wire:click="$set('state', '{{ $key }}')" aria-pressed="{{ $state === $key ? 'true' : 'false' }}" class="{{ $state === $key ? 'seg seg-active' : 'seg' }} h-8! px-3!" data-account-state-filter="{{ $key }}">{{ $label }} <span class="tabular-nums text-gray-500">{{ $counts[$key] }}</span></button>
                @endif
            @endforeach
        </div>
        <span class="ml-auto flex flex-wrap items-center gap-2">
            <select wire:model.live="type" class="{{ $small }}" aria-label="Hesap türü">
                <option value="">Tüm türler</option>
                @foreach (AccountsTable::TYPES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
            @if ($brandId === 0)
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Hesap veya marka ara" aria-label="Ara" class="{{ $small }} w-full sm:w-56">
            @endif
        </span>
    </div>

    @if ($message !== '')
        <p role="status" class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300" data-accounts-message>{{ $message }}</p>
    @endif

    <div class="card overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr><th>Hesap</th><th>Marka</th><th>Durum</th><th class="hidden md:table-cell">Son veri</th><th class="text-right!"><span class="sr-only">İşlem</span></th></tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php [$stateLabel, $chip] = AccountsTable::STATES[$row['state']]; @endphp
                    <tr wire:key="account-{{ $row['id'] }}" data-account-row="{{ $row['id'] }}" data-account-state="{{ $row['state'] }}">
                        <td class="min-w-0 align-top">
                            <p class="font-medium text-gray-900 [overflow-wrap:anywhere] dark:text-white">{{ $row['name'] }}</p>
                            <p class="text-[13px] text-gray-500">{{ $row['type_label'] }}@if ($row['external_id'] !== '' && $row['external_id'] !== $row['name']) · <span class="[overflow-wrap:anywhere]">{{ $row['external_id'] }}</span>@endif</p>
                        </td>
                        <td class="align-top">
                            @if ($row['brand_id'] !== null)
                                <a href="{{ route('operator.brand', ['brand' => $row['brand_id']]) }}" wire:navigate class="break-words hover:underline">{{ $row['brand'] }}</a>
                            @elseif ($isAdmin && $brandId === 0 && $row['state'] === 'unbound')
                                <span class="flex flex-wrap items-center gap-1.5">
                                    <select wire:model="bindTo.{{ $row['id'] }}" class="{{ $small }} max-w-44" aria-label="Marka seç: {{ $row['name'] }}">
                                        <option value="">Marka seç</option>
                                        @foreach ($brands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                                    </select>
                                    <button type="button" wire:click="bind({{ $row['id'] }})" wire:loading.attr="disabled" class="btn h-8!" data-account-bind="{{ $row['id'] }}">Bağla</button>
                                </span>
                            @else
                                <span class="text-gray-500">—</span>
                            @endif
                        </td>
                        <td class="align-top"><span class="chip {{ $chip }} whitespace-nowrap" @if ($row['error']) title="{{ \Illuminate\Support\Str::limit($row['error'], 200) }}" @endif>{{ $stateLabel }}</span></td>
                        <td class="hidden align-top whitespace-nowrap text-gray-700 md:table-cell dark:text-gray-300">{{ $row['last']?->timezone('Europe/Istanbul')->format('d.m.Y H:i') ?? '—' }}</td>
                        <td class="align-top text-right whitespace-nowrap">
                            @if ($row['brand_id'] !== null && $row['state'] !== 'not_served')
                                <button type="button" wire:click="refreshNow({{ $row['id'] }})" wire:loading.attr="disabled" class="btn-quiet h-8!" data-account-refresh="{{ $row['id'] }}">Şimdi güncelle</button>
                            @elseif ($isAdmin && $row['brand_id'] === null)
                                <button type="button" wire:click="notServed({{ $row['id'] }})" class="btn-quiet h-8!" data-account-not-served="{{ $row['id'] }}">{{ $row['state'] === 'not_served' ? 'Yeniden öner' : 'Kullanılmıyor' }}</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6! text-[13px] text-gray-500">{{ $brandId === 0 ? 'Bu süzgeçte hesap yok.' : 'Bu markaya bağlı reklam ya da profil hesabı yok.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
