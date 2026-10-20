@php
    // Panel concept 2026-10-20: "Keşfedilen hangi varlık markasız, onu hangi markaya bağlarım?" Brand candidates (one
    // click makes a brand) come first, then ownership problems, then the full list with a bind control per row.
    $field = 'h-9 rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
    $small = 'h-8 rounded-lg border border-gray-200 bg-white px-2.5 text-[13px] text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
@endphp
<div class="space-y-10 dark:text-gray-200">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="page-title">Keşfedilen varlıklar</h1>
            <p class="page-sub">{{ $total }} varlık, {{ $unbound }} markasız</p>
        </div>
        <a href="{{ route('operator.integrations') }}" wire:navigate class="btn-quiet">Entegrasyonlar</a>
    </header>
    @if($message !== '')<p role="status" class="card -mt-4 px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{{ $message }}</p>@endif

    <section data-brand-candidates>
        <div class="section-head items-center">
            <h2 class="section-title" title="Aynı markaya ait görünen keşfedilmiş varlıklar; onaylayınca marka oluşur ve varlıklar ona bağlanır.">Marka adayları <span class="section-count">{{ $candidates->count() }}</span></h2>
            @if($isAdmin)<span class="ml-auto flex items-center gap-2"><button type="button" wire:click="regroup" wire:loading.attr="disabled" class="btn">Yeniden grupla</button><x-operator.ai-prompt-info operation="brand.candidates" /></span>@endif
        </div>
        <ul class="card">
            @forelse($candidates as $candidate)
                @php($types = collect((array) data_get($candidate->signals, 'types', []))->map(fn ($n, $t) => \App\Services\Portfolio\BrandCandidateBuilder::typeLabel((string) $t).($n > 1 ? ' ×'.$n : ''))->implode(', '))
                @php($confidence = (int) round($candidate->confidence * 100))
                <li wire:key="cand-{{ $candidate->id }}" class="border-t border-gray-200 first:border-t-0 dark:border-gray-800">
                    <div class="card-row flex-wrap items-start border-t-0 sm:flex-nowrap sm:items-center">
                        <x-ta.brand-avatar :name="$candidate->name" />
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5">
                                <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $candidate->name }}</span>
                                <x-ta.pill :tone="$confidence >= 80 ? 'ok' : 'look'" title="Varlıkların aynı markaya ait olma güveni">Güven %{{ $confidence }}</x-ta.pill>
                                @if(data_get($candidate->signals, 'existing_brand_id'))<span class="text-xs text-gray-500">mevcut markaya</span>@endif
                            </p>
                            <p class="mt-0.5 truncate text-[13px] text-gray-500 dark:text-gray-400" title="{{ $types }}">
                                {{ $candidate->members->count() }} varlık{{ $types !== '' ? ': '.$types : '' }}.
                                <span @class(['text-warning-800 dark:text-warning-400' => ! $candidate->sector]) title="{{ $candidate->sector_reason }}">{{ $candidate->sector?->name ?? 'Sektör belirlenmedi' }}@if($candidate->sector_signal), {{ mb_strtolower(\App\Models\BrandCandidate::SIGNAL_LABELS[$candidate->sector_signal] ?? $candidate->sector_signal) }}@endif</span>
                            </p>
                        </div>
                        @if($isAdmin)
                            <div class="flex w-full flex-wrap items-center gap-1.5 sm:w-auto sm:flex-nowrap">
                                <button type="button" wire:click="dismiss({{ $candidate->id }})" wire:confirm="Aday yoksayılsın mı?" class="btn-quiet">Yoksay</button>
                                <button type="button" wire:click="edit({{ $candidate->id }})" class="btn-quiet">Düzenle</button>
                                @if(! data_get($candidate->signals, 'existing_brand_id'))
                                    <select wire:model="customerFor.{{ $candidate->id }}" aria-label="Müşteri" class="{{ $small }} max-w-44">
                                        <option value="">Yeni müşteri</option>
                                        @foreach($customers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                                    </select>
                                @endif
                                <button type="button" wire:click="approve({{ $candidate->id }})" wire:loading.attr="disabled" class="btn">Onayla</button>
                            </div>
                        @endif
                    </div>
                    @if($isAdmin && $editing === $candidate->id)
                        <div class="space-y-3 border-t border-gray-200 bg-gray-50/60 px-4 py-3.5 text-[13px] dark:border-gray-800 dark:bg-white/[0.02]">
                            <div class="flex flex-wrap items-center gap-2">
                                <input type="text" wire:model="names.{{ $candidate->id }}" aria-label="Marka adı" class="{{ $small }}">
                                <select wire:model="sectorFor.{{ $candidate->id }}" aria-label="Sektör" class="{{ $small }}">
                                    <option value="">Sektör —</option>
                                    @foreach($sectors as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                                </select>
                                <input type="text" wire:model="customerName.{{ $candidate->id }}" placeholder="Yeni müşteri adı" aria-label="Yeni müşteri adı" class="{{ $small }}">
                                <button type="button" wire:click="saveEdit({{ $candidate->id }})" class="btn-primary">Kaydet</button>
                            </div>
                            <ul class="card">
                                @foreach($candidate->members as $member)
                                    <li wire:key="mem-{{ $member->id }}" class="card-row flex-wrap gap-2 py-2.5">
                                        <span class="w-28 text-gray-500">{{ \App\Services\Portfolio\BrandCandidateBuilder::typeLabel($member->website ? 'website' : (string) $member->resource?->resource_type) }}</span>
                                        <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-white">{{ $member->website?->domain ?? ($member->resource?->display_name ?: $member->resource?->external_id) }} <span class="text-gray-500">{{ $member->reason }}</span></span>
                                        <select wire:model="moveTo.{{ $member->id }}" aria-label="Taşı" class="{{ $small }}">
                                            <option value="">Taşı…</option>
                                            <option value="new">Yeni aday</option>
                                            @foreach($candidates as $other)@if($other->id !== $candidate->id)<option value="{{ $other->id }}">{{ $other->name }}</option>@endif @endforeach
                                        </select>
                                        <button type="button" wire:click="move({{ $member->id }})" class="btn">Taşı</button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </li>
            @empty
                <li><x-ta.empty-state size="sm" title="Bekleyen marka adayı yok." /></li>
            @endforelse
        </ul>
    </section>

    @if($problems !== [])
        <section>
            <div class="section-head items-center">
                <h2 class="section-title">Sahiplik sorunları <span class="section-count">{{ count($problems) }}</span></h2>
                @if($isAdmin && collect($problems)->where('fixable', true)->isNotEmpty())
                    <button type="button" wire:click="fixIntegrity" wire:confirm="Fazla / öksüz bağlantılar kapatılsın mı?" class="btn ml-auto">Güvenli düzelt</button>
                @endif
            </div>
            <ul class="card">
                @foreach(array_slice($problems, 0, 30) as $problem)
                    <li class="card-row flex-wrap sm:flex-nowrap">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>
                        <div class="min-w-0 flex-1">
                            @php($subject = str_replace(' · ', ', ', (string) $problem['subject']))
                            <p class="truncate text-sm text-gray-900 dark:text-white" title="{{ $subject }}">{{ $problem['label'] }}: <span class="font-medium">{{ $subject }}</span></p>
                            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $problem['detail'] }}</p>
                        </div>
                        @if($problem['code'] === 'duplicate_host')<a href="{{ route('operator.integrations.website-duplicates') }}" wire:navigate class="btn">Birleştir</a>@endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section>
        <div class="section-head items-center">
            <h2 class="section-title">Tüm varlıklar <span class="section-count">{{ $rows->total() }}</span></h2>
            <div class="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Ara…" aria-label="Ara" class="{{ $field }} w-full min-w-0 basis-full sm:w-48 sm:basis-auto sm:flex-none">
                <select wire:model.live="kind" aria-label="Tür" class="{{ $field }} min-w-0 flex-1 sm:flex-none"><option value="">Tüm türler</option>@foreach($kinds as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select>
                <select wire:model.live="bound" aria-label="Bağlı" class="{{ $field }} min-w-0 flex-1 sm:flex-none"><option value="">Hepsi</option><option value="yes">Markaya bağlı</option><option value="no">Markasız</option></select>
            </div>
        </div>
        <div class="card overflow-x-auto">
            <table class="data-table">
                <thead><tr><th>Varlık</th><th class="hidden sm:table-cell">Tür</th><th>Marka</th><th class="hidden md:table-cell">Müşteri</th>@if($isAdmin)<th><span class="sr-only">İşlem</span></th>@endif</tr></thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr wire:key="asset-{{ $row['key'] }}">
                            <td class="max-w-0 w-[55%] sm:w-[34%]"><span class="block truncate font-medium text-gray-900 dark:text-white" title="{{ $row['name'] }}">{{ str_replace(' · ', ', ', $row['name']) }}</span>@if($row['host'] && $row['host'] !== $row['name'])<span class="block truncate text-xs text-gray-500">{{ $row['host'] }}</span>@endif<span class="text-xs text-gray-500 sm:hidden">{{ $kinds[$row['kind']] ?? $row['kind'] }}</span></td>
                            <td class="hidden whitespace-nowrap text-[13px] text-gray-600 sm:table-cell dark:text-gray-300">{{ $kinds[$row['kind']] ?? $row['kind'] }}</td>
                            <td class="max-w-0 text-[13px] sm:max-w-none">@if($row['brand'])<span class="block truncate sm:whitespace-normal" title="{{ $row['brand'] }}">{{ $row['brand'] }}</span>@else<x-ta.pill tone="look">Markasız</x-ta.pill>@endif</td>
                            <td class="hidden text-[13px] text-gray-600 md:table-cell dark:text-gray-300">{{ $row['customer'] ?? '—' }}</td>
                            @if($isAdmin)
                                <td class="text-right">
                                    @unless($row['bound'])
                                        <span class="inline-flex items-center gap-1.5">
                                            <select wire:model="bindTo.{{ $row['key'] }}" aria-label="Marka seç" class="{{ $small }} max-w-40">
                                                <option value="">Marka seç…</option>
                                                @foreach($brands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                                            </select>
                                            <button type="button" wire:click="bindToBrand('{{ $row['key'] }}')" wire:loading.attr="disabled" class="btn">Markaya bağla</button>
                                        </span>
                                    @endunless
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-8 text-center text-gray-500">Varlık yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pt-3">{{ $rows->links() }}</div>
    </section>
</div>
