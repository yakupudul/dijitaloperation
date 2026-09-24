@php
    $input = 'mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700 dark:text-white';
    $select = 'rounded-lg border border-gray-200 bg-transparent px-3 py-1.5 text-sm dark:border-gray-700 dark:text-white';
    $sourceLabels = ['manual' => 'elle', 'rdap' => 'RDAP', 'tls' => 'sertifika'];
@endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Yenilemeler</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500">Alan adı, hosting, SSL ve diğer yenilemeler; maliyet, müşteriden alınan ücret ve tahsilat durumu. Alan adı bitişi RDAP'tan, SSL bitişi toplanan sertifikadan her gün güncellenir; elle girilen tarih korunur. 30 / 14 / 7 / 1 gün kala telefona bildirim gelir.</p>
        </div>
        @if ($isAdmin)
            <div class="flex gap-2">
                <button type="button" wire:click="refresh" wire:loading.attr="disabled" class="rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700">Tarihleri güncelle</button>
                <button type="button" wire:click="create" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600">Yenileme ekle</button>
            </div>
        @endif
    </div>

    @if ($message !== '')
        <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>
    @endif

    <div class="flex flex-wrap items-center gap-2">
        <select wire:model.live="brand" class="{{ $select }}"><option value="">Tüm markalar</option>@foreach ($brands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
        <select wire:model.live="window" class="{{ $select }}"><option value="30">30 gün</option><option value="90">90 gün</option><option value="365">1 yıl</option><option value="all">Hepsi</option></select>
        @if ($upcomingCharge->isNotEmpty())
            <span class="text-sm text-gray-600 dark:text-gray-300">30 gün içinde tahsil edilecek: @foreach ($upcomingCharge as $currency => $sum){{ number_format($sum, 0, ',', '.') }} {{ $currency }}@if (! $loop->last), @endif @endforeach</span>
        @endif
    </div>

    @if ($editingId !== null)
        <section class="grid gap-3 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 sm:grid-cols-3 dark:bg-gray-900 dark:ring-gray-800">
            <label class="text-sm text-gray-600">Marka<select wire:model.live="form.brand_id" class="{{ $input }}"><option value="">Seç…</option>@foreach ($brands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>@error('form.brand_id')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror</label>
            <label class="text-sm text-gray-600">Varlık (isteğe bağlı)<select wire:model="form.digital_asset_id" class="{{ $input }}"><option value="">—</option>@foreach ($assets as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
            <label class="text-sm text-gray-600">Tür<select wire:model="form.kind" class="{{ $input }}">@foreach (\App\Models\AssetRenewal::KINDS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            <label class="text-sm text-gray-600">Ad<input type="text" wire:model="form.label" placeholder="ornek.com hosting" class="{{ $input }}" />@error('form.label')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror</label>
            <label class="text-sm text-gray-600">Sağlayıcı<input type="text" wire:model="form.provider" class="{{ $input }}" /></label>
            <label class="text-sm text-gray-600">Bitiş tarihi<input type="date" wire:model="form.expires_on" class="{{ $input }}" /></label>
            <label class="text-sm text-gray-600">Maliyet<input type="text" wire:model="form.cost_amount" class="{{ $input }}" /></label>
            <label class="text-sm text-gray-600">Müşteriden alınan<input type="text" wire:model="form.charge_amount" class="{{ $input }}" /></label>
            <label class="text-sm text-gray-600">Para birimi<select wire:model="form.currency" class="{{ $input }}"><option>TRY</option><option>USD</option><option>EUR</option></select></label>
            <label class="text-sm text-gray-600">Tahsilat<select wire:model="form.collection_status" class="{{ $input }}">@foreach (\App\Models\AssetRenewal::COLLECTION as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            <label class="flex items-center gap-2 text-sm text-gray-600"><input type="checkbox" wire:model="form.auto_renew" class="rounded border-gray-300" /> Otomatik yenileniyor</label>
            <label class="text-sm text-gray-600 sm:col-span-3">Not<textarea wire:model="form.notes" rows="2" class="{{ $input }}"></textarea></label>
            <div class="flex gap-2 sm:col-span-3">
                <button type="button" wire:click="save" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white">Kaydet</button>
                <button type="button" wire:click="$set('editingId', null)" class="rounded-lg px-3 py-2 text-sm ring-1 ring-inset ring-gray-300">Vazgeç</button>
            </div>
        </section>
    @endif

    <section class="overflow-x-auto rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-gray-400"><tr><th class="py-2 pr-3">Bitiş</th><th class="py-2 pr-3">Yenileme</th><th class="py-2 pr-3">Marka</th><th class="py-2 pr-3">Sağlayıcı</th><th class="py-2 pr-3 text-right">Maliyet / ücret</th><th class="py-2 pr-3">Tahsilat</th><th class="py-2"></th></tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($rows as $row)
                    @php
                        $days = $row->daysLeft();
                    @endphp
                    <tr wire:key="ren-{{ $row->id }}">
                        <td @class(['py-2 pr-3 tabular-nums', 'font-semibold text-rose-600' => $days !== null && $days <= 7, 'text-amber-600' => $days !== null && $days > 7 && $days <= 30])>
                            {{ $row->expires_on?->format('d.m.Y') ?? 'bilinmiyor' }}
                            @if ($days !== null)<span class="block text-xs">{{ $days < 0 ? abs($days).' gün geçti' : $days.' gün' }}{{ $row->auto_renew ? ' · otomatik' : '' }}</span>@endif
                        </td>
                        <td class="py-2 pr-3 text-gray-800 dark:text-gray-200">{{ $row->label }} <span class="block text-xs text-gray-400">{{ \App\Models\AssetRenewal::KINDS[$row->kind] ?? $row->kind }} · tarih: {{ $sourceLabels[$row->expires_source] ?? $row->expires_source }}</span></td>
                        <td class="py-2 pr-3 text-xs">{{ $row->brand?->name }}</td>
                        <td class="py-2 pr-3 text-xs">{{ $row->provider ?? '—' }}</td>
                        <td class="py-2 pr-3 text-right text-xs tabular-nums">{{ $row->cost_amount !== null ? number_format($row->cost_amount, 0, ',', '.') : '—' }} / {{ $row->charge_amount !== null ? number_format($row->charge_amount, 0, ',', '.').' '.$row->currency : '—' }}</td>
                        <td class="py-2 pr-3 text-xs">
                            @if ($isAdmin)
                                <select wire:change="setCollection({{ $row->id }}, $event.target.value)" class="rounded border border-gray-200 bg-transparent px-1.5 py-0.5 text-xs dark:border-gray-700">
                                    @foreach (\App\Models\AssetRenewal::COLLECTION as $value => $label)<option value="{{ $value }}" @selected($row->collection_status === $value)>{{ $label }}</option>@endforeach
                                </select>
                            @else
                                {{ \App\Models\AssetRenewal::COLLECTION[$row->collection_status] ?? $row->collection_status }}
                            @endif
                        </td>
                        <td class="py-2 text-right text-xs">
                            @if ($isAdmin)
                                <button type="button" wire:click="edit({{ $row->id }})" class="text-brand-600 hover:underline">Düzenle</button>
                                @if ($row->kind !== 'ssl')<button type="button" wire:click="renewed({{ $row->id }})" wire:confirm="Bitiş tarihi bir yıl ileri alınsın mı?" class="ml-2 text-brand-600 hover:underline">Yenilendi</button>@endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-3 text-gray-500">Bu aralıkta yenileme yok. Web siteleri için alan adı ve SSL satırları her gün otomatik eklenir.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
