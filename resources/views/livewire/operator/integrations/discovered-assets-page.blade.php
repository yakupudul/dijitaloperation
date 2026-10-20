<div class="space-y-10 dark:text-gray-200">
    <header class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('operator.integrations') }}" wire:navigate class="text-xs text-gray-500 hover:text-gray-900 dark:hover:text-white">Entegrasyonlar</a>
            <h1 class="mt-1 text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white">Keşfedilen varlıklar</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $total }} varlık, {{ $unbound }} markasız</p>
        </div>
    </header>
    @if($message !== '')<p role="status" class="rounded-lg p-3 text-sm tone-info">{{ $message }}</p>@endif

    <section data-brand-candidates>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="section-title">Marka adayları <span class="text-sm font-normal text-gray-500">{{ $candidates->count() }}</span></h2>
            @if($isAdmin)<span class="flex items-center gap-2"><button type="button" wire:click="regroup" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-xs font-semibold ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Yeniden grupla</button><x-operator.ai-prompt-info operation="brand.candidates" /></span>@endif
        </div>
        @foreach($candidates as $candidate)
            @php($types = collect((array) data_get($candidate->signals, 'types', []))->map(fn ($n, $t) => \App\Services\Portfolio\BrandCandidateBuilder::typeLabel((string) $t).($n > 1 ? ' ×'.$n : ''))->implode(' · '))
            <div wire:key="cand-{{ $candidate->id }}" class="border-b border-gray-200 py-3 text-sm dark:border-gray-800">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="font-medium">{{ $candidate->name }}</span>
                    <span class="text-gray-500">{{ $candidate->members->count() }} varlık</span>
                    <span @class(['text-xs', 'text-gray-500' => $candidate->sector, 'text-warning-800 dark:text-warning-400' => ! $candidate->sector]) title="{{ $candidate->sector_reason }}">{{ $candidate->sector?->name ?? 'Sektör belirlenmedi' }}@if($candidate->sector_signal) · {{ \App\Models\BrandCandidate::SIGNAL_LABELS[$candidate->sector_signal] ?? $candidate->sector_signal }}@endif</span>
                    @php($confidence = (int) round($candidate->confidence * 100))
                    <x-ta.pill :tone="$confidence >= 80 ? 'ok' : 'look'" title="Varlıkların aynı markaya ait olma güveni">Güven %{{ $confidence }}</x-ta.pill>
                    @if(data_get($candidate->signals, 'existing_brand_id'))<span class="text-xs text-gray-500">mevcut markaya</span>@endif
                </div>
                @if($types !== '')<p class="mt-1 truncate text-xs text-gray-500">{{ $types }}</p>@endif
                @if($isAdmin)
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        @if(! data_get($candidate->signals, 'existing_brand_id'))
                            <select wire:model="customerFor.{{ $candidate->id }}" aria-label="Müşteri" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950">
                                <option value="">Yeni müşteri</option>
                                @foreach($customers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                        @endif
                        <button type="button" wire:click="approve({{ $candidate->id }})" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-xs font-semibold ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:ring-gray-700 dark:hover:bg-white/[0.04]">Onayla</button>
                        <button type="button" wire:click="edit({{ $candidate->id }})" class="text-xs link-neutral">Düzenle</button>
                        <button type="button" wire:click="dismiss({{ $candidate->id }})" wire:confirm="Aday yoksayılsın mı?" class="text-xs text-gray-500">Yoksay</button>
                    </div>
                @endif
                @if($isAdmin && $editing === $candidate->id)
                    <div class="surface mt-3 space-y-2 p-3 text-xs">
                        <div class="flex flex-wrap items-center gap-2">
                            <input type="text" wire:model="names.{{ $candidate->id }}" aria-label="Marka adı" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950">
                            <select wire:model="sectorFor.{{ $candidate->id }}" aria-label="Sektör" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950">
                                <option value="">Sektör —</option>
                                @foreach($sectors as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                            <input type="text" wire:model="customerName.{{ $candidate->id }}" placeholder="Yeni müşteri adı" aria-label="Yeni müşteri adı" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950">
                            <button type="button" wire:click="saveEdit({{ $candidate->id }})" class="rounded-lg bg-brand-500 px-3 py-1 font-semibold text-white">Kaydet</button>
                        </div>
                        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach($candidate->members as $member)
                                <li wire:key="mem-{{ $member->id }}" class="flex flex-wrap items-center gap-2 py-1">
                                    <span class="w-28 text-gray-500">{{ \App\Services\Portfolio\BrandCandidateBuilder::typeLabel($member->website ? 'website' : (string) $member->resource?->resource_type) }}</span>
                                    <span class="min-w-0 flex-1 truncate">{{ $member->website?->domain ?? ($member->resource?->display_name ?: $member->resource?->external_id) }} <span class="text-gray-500">{{ $member->reason }}</span></span>
                                    <select wire:model="moveTo.{{ $member->id }}" aria-label="Taşı" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950">
                                        <option value="">Taşı…</option>
                                        <option value="new">Yeni aday</option>
                                        @foreach($candidates as $other)@if($other->id !== $candidate->id)<option value="{{ $other->id }}">{{ $other->name }}</option>@endif @endforeach
                                    </select>
                                    <button type="button" wire:click="move({{ $member->id }})" class="rounded-lg px-2 py-1 ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Taşı</button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endforeach
    </section>

    @if($problems !== [])
        <section class="text-sm">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="section-title flex items-center gap-2"><span class="h-1.5 w-1.5 rounded-full bg-warning-500" aria-hidden="true"></span>{{ count($problems) }} sahiplik sorunu</h2>
                @if($isAdmin && collect($problems)->where('fixable', true)->isNotEmpty())
                    <button type="button" wire:click="fixIntegrity" wire:confirm="Fazla / öksüz bağlantılar kapatılsın mı?" class="rounded-lg px-3 py-1 text-xs font-semibold ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:ring-gray-700">Güvenli düzelt</button>
                @endif
            </div>
            <ul class="mt-2 divide-y divide-gray-200 text-sm dark:divide-gray-800">
                @foreach(array_slice($problems, 0, 30) as $problem)
                    <li class="py-2.5">{{ $problem['label'] }}: <span class="font-medium">{{ $problem['subject'] }}</span> <span class="text-xs text-gray-500">{{ $problem['detail'] }}</span>
                        @if($problem['code'] === 'duplicate_host')<a href="{{ route('operator.integrations.website-duplicates') }}" wire:navigate class="ml-2 text-xs link-neutral">Birleştir</a>@endif</li>
                @endforeach
            </ul>
        </section>
    @endif

    <section>
        <div class="flex flex-wrap gap-2 pb-3">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Ara…" aria-label="Ara" class="w-full min-w-0 flex-1 rounded-lg border-gray-300 text-sm sm:min-w-40 dark:border-gray-700 dark:bg-gray-950">
            <select wire:model.live="kind" aria-label="Tür" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Tüm türler</option>@foreach($kinds as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select>
            <select wire:model.live="bound" aria-label="Bağlı" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Hepsi</option><option value="yes">Markaya bağlı</option><option value="no">Markasız</option></select>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-gray-200 text-xs text-gray-500 dark:border-gray-800"><tr><th class="px-3 py-2 font-normal">Varlık</th><th class="px-3 py-2 font-normal">Tür</th><th class="px-3 py-2 font-normal">Marka</th><th class="px-3 py-2 font-normal">Müşteri</th>@if($isAdmin)<th class="px-3 py-2 font-normal"><span class="sr-only">İşlem</span></th>@endif</tr></thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    @forelse($rows as $row)
                        <tr wire:key="asset-{{ $row['key'] }}" class="hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                            <td class="px-3 py-2"><span class="font-medium">{{ $row['name'] }}</span>@if($row['host'] && $row['host'] !== $row['name'])<span class="ml-1 text-xs text-gray-500">{{ $row['host'] }}</span>@endif</td>
                            <td class="px-3 py-2 text-xs">{{ $kinds[$row['kind']] ?? $row['kind'] }}</td>
                            <td class="px-3 py-2 text-xs">{{ $row['brand'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs">{{ $row['customer'] ?? '—' }}</td>
                            @if($isAdmin)
                                <td class="px-3 py-2 text-right">
                                    @unless($row['bound'])
                                        <span class="inline-flex items-center gap-1">
                                            <select wire:model="bindTo.{{ $row['key'] }}" aria-label="Marka seç" class="max-w-40 rounded-lg border-gray-300 py-1 text-xs dark:border-gray-700 dark:bg-gray-950">
                                                <option value="">Marka seç…</option>
                                                @foreach($brands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                                            </select>
                                            <button type="button" wire:click="bindToBrand('{{ $row['key'] }}')" wire:loading.attr="disabled" class="whitespace-nowrap rounded-lg px-2 py-1 text-xs font-semibold ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Markaya bağla</button>
                                        </span>
                                    @endunless
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-8 text-center text-gray-500">Varlık yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pt-3">{{ $rows->links() }}</div>
    </section>
</div>
