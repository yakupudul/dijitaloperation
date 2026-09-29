<div class="space-y-5 dark:text-gray-200">
    <header class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('operator.integrations') }}" wire:navigate class="text-xs text-gray-500">← Entegrasyonlar</a>
            <h1 class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">Keşfedilen varlıklar</h1>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="rounded-lg bg-gray-100 px-3 py-1.5 dark:bg-gray-800">{{ $total }} varlık</span>
            <span class="rounded-lg bg-gray-100 px-3 py-1.5 dark:bg-gray-800">{{ $unbound }} markasız</span>
        </div>
    </header>
    @if($message !== '')<p role="status" class="rounded-lg bg-blue-50 p-3 text-sm text-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ $message }}</p>@endif

    @if($groups->isNotEmpty())
        <section class="space-y-2">
            <h2 class="text-sm font-semibold">Marka önerileri</h2>
            @foreach($groups as $group)
                <div wire:key="grp-{{ $group['form_key'] }}" class="flex flex-wrap items-center gap-3 rounded-xl border border-gray-200 bg-white p-3 text-sm dark:border-gray-800 dark:bg-gray-900">
                    <span class="font-medium">{{ $group['existing_brand'] ?? $group['suggested_brand'] }} olabilir</span>
                    <span class="text-gray-500">{{ collect($group['resources'])->where('selected', true)->count() }} varlık</span>
                    <span class="min-w-0 flex-1 truncate text-xs text-gray-500">{{ collect($group['resources'])->where('selected', true)->pluck('type_label')->implode(' · ') }}</span>
                    @if($isAdmin)
                        @if($group['existing_brand_id'] === null)
                            <select wire:model="customerFor.{{ $group['form_key'] }}" aria-label="Müşteri" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950">
                                <option value="">Yeni müşteri</option>
                                @foreach($customers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                        @endif
                        <button type="button" wire:click="approve(@js($group['key']))" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white">Onayla</button>
                        <a href="{{ route('operator.portfolio.discover', ['search' => $group['suggested_brand']]) }}" wire:navigate class="text-xs text-brand-600">Düzenle</a>
                    @endif
                </div>
            @endforeach
        </section>
    @endif

    @if($problems !== [])
        <section class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950/40">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-semibold">{{ count($problems) }} sahiplik sorunu</h2>
                @if($isAdmin && collect($problems)->where('fixable', true)->isNotEmpty())
                    <button type="button" wire:click="fixIntegrity" wire:confirm="Fazla / öksüz bağlantılar kapatılsın mı?" class="rounded-lg border border-amber-400 px-3 py-1 text-xs font-semibold">Güvenli düzelt</button>
                @endif
            </div>
            <ul class="mt-2 space-y-1 text-xs">
                @foreach(array_slice($problems, 0, 30) as $problem)
                    <li>{{ $problem['label'] }}: <span class="font-medium">{{ $problem['subject'] }}</span> · {{ $problem['detail'] }}
                        @if($problem['code'] === 'duplicate_host')<a href="{{ route('operator.integrations.website-duplicates') }}" wire:navigate class="text-brand-600">Birleştir</a>@endif</li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <div class="flex flex-wrap gap-2 border-b border-gray-200 p-3 dark:border-gray-800">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Ara…" aria-label="Ara" class="min-w-40 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
            <select wire:model.live="kind" aria-label="Tür" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Tüm türler</option>@foreach($kinds as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select>
            <select wire:model.live="bound" aria-label="Bağlı" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Hepsi</option><option value="yes">Markaya bağlı</option><option value="no">Markasız</option></select>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-950"><tr><th class="px-3 py-2">Varlık</th><th class="px-3 py-2">Tür</th><th class="px-3 py-2">Marka</th><th class="px-3 py-2">Müşteri</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($rows as $row)
                        <tr wire:key="asset-{{ $row['key'] }}">
                            <td class="px-3 py-2"><span class="font-medium">{{ $row['name'] }}</span>@if($row['host'] && $row['host'] !== $row['name'])<span class="ml-1 text-xs text-gray-500">{{ $row['host'] }}</span>@endif</td>
                            <td class="px-3 py-2 text-xs">{{ $kinds[$row['kind']] ?? $row['kind'] }}</td>
                            <td class="px-3 py-2 text-xs">{{ $row['brand'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs">{{ $row['customer'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-8 text-center text-gray-500">Varlık yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $rows->links() }}</div>
    </section>
</div>
