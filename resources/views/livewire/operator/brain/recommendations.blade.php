@php
    use App\Services\Brain\BrainLabels;
    $card = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $basisTone = ['validated' => 'bg-success-50 text-success-700', 'observational' => 'bg-blue-50 text-blue-700', 'rule' => 'bg-gray-100 text-gray-600'];
    $statusLabels = ['open' => 'Açık', 'done' => 'Yapıldı', 'dismissed' => 'Kapatıldı', 'resolved' => 'Kendiliğinden kapandı'];
@endphp
<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Beyin önerileri</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Hizmet haritası ve başarılı markaların yöntemlerinden çıkan öneriler. "Etkisi kanıtlandı" önerilerin arkasındaki yöntem, uygulandığı markalarda uygulanmayanlara göre ölçülerek doğrulandı; "gözlendi" olanlar başarılı markalarda sık görülen, henüz ölçülmemiş yöntemlerdir.</p>
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <label class="text-sm"><span class="block text-xs text-gray-500">Kanal</span>
            <select wire:model.live="channel" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                <option value="">Tümü</option>
                @foreach ($channels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        <label class="text-sm"><span class="block text-xs text-gray-500">Marka</span>
            <select wire:model.live="brand" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                <option value="">Tümü</option>
                @foreach ($brands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
        </label>
        <label class="text-sm"><span class="block text-xs text-gray-500">Durum</span>
            <select wire:model.live="status" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                @foreach ($statusLabels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                <option value="all">Hepsi</option>
            </select>
        </label>
        @if ($visibleIds !== [])
            <button type="button" wire:click="$set('bulkIds', @js($visibleIds))" class="text-xs text-brand-600 hover:underline">Görünenlerin hepsini seç</button>
        @endif
    </div>

    @if ($bulkIds !== [])
        <div class="flex flex-wrap items-center gap-3 rounded-lg bg-brand-50 px-4 py-2 text-sm dark:bg-brand-500/10">
            <span class="font-medium text-brand-700 dark:text-brand-300">{{ count($bulkIds) }} öneri seçili</span>
            <button type="button" wire:click="markDone" class="rounded-lg bg-success-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-success-600">✓ Yapıldı</button>
            <button type="button" wire:click="dismiss" wire:confirm="Seçili öneriler kapatılsın mı? Tekrar gösterilmez." class="rounded-lg bg-white px-3 py-1.5 text-xs ring-1 ring-inset ring-gray-300 dark:bg-gray-800">Kapat</button>
            <button type="button" wire:click="$set('bulkIds', [])" class="text-xs text-gray-500 hover:underline">Seçimi kaldır</button>
        </div>
    @endif

    <section class="{{ $card }}">
        @if ($rows->isEmpty())
            <p class="p-5 text-sm text-gray-500">Bu filtrede öneri yok. Öneriler haftalık hesaplanır (hizmet haritası, reklam grupları, sayfa özellikleri ve yöntemler).</p>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($rows as $row)
                    @php $evidence = (array) json_decode((string) $row->evidence, true); @endphp
                    <li wire:key="rec-{{ $row->id }}" class="flex gap-3 p-4">
                        @if ($row->status === 'open')
                            <input type="checkbox" value="{{ $row->id }}" wire:model.live="bulkIds" aria-label="Seç" class="mt-1 size-4 shrink-0 rounded border-gray-300">
                        @endif
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                <span class="rounded-full px-2 py-0.5 {{ $basisTone[$row->basis] ?? 'bg-gray-100 text-gray-600' }}">{{ BrainLabels::basis($row->basis) }}</span>
                                <span>{{ BrainLabels::channel($row->channel) }}</span>
                                @if ($row->brand_name)<span>· {{ $row->brand_name }}</span>@endif
                                @if ($row->service_name)<span>· {{ $row->service_name }}</span>@endif
                                @if ($row->status !== 'open')<span>· {{ $statusLabels[$row->status] ?? $row->status }}</span>@endif
                            </div>
                            <button type="button" wire:click="toggle({{ $row->id }})" class="mt-1 block text-left font-medium text-gray-800 hover:text-brand-600 dark:text-gray-200">{{ $row->title }}</button>
                            @if ($row->detail)<p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $row->detail }}</p>@endif
                            @if ($expanded === $row->id && $evidence !== [])
                                <pre class="mt-2 max-h-64 overflow-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-700 dark:bg-white/5 dark:text-gray-300">{{ json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                            @endif
                            @if ($row->outcome)
                                @php $outcome = (array) json_decode((string) $row->outcome, true); @endphp
                                <p class="mt-1 text-xs text-gray-500">Ölçüm: {{ $outcome['summary'] ?? '' }}</p>
                            @endif
                        </div>
                        @if ($row->status === 'open')
                            <div class="flex shrink-0 flex-col gap-1">
                                <button type="button" wire:click="markDone({{ $row->id }})" class="rounded-lg bg-success-500 px-3 py-1 text-xs font-semibold text-white hover:bg-success-600">✓ Yapıldı</button>
                                <button type="button" wire:click="dismiss({{ $row->id }})" class="text-xs text-gray-500 hover:underline">Kapat</button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
            <div class="p-4">{{ $rows->links() }}</div>
        @endif
    </section>
</div>
