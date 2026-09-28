<div class="space-y-4 dark:text-gray-200">
    <header class="flex flex-wrap items-center justify-between gap-3">
        <div><p class="text-xs text-gray-500">Pazar</p><h1 class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">Sorgular</h1></div>
        <div class="flex flex-wrap gap-2 text-sm">
            <a href="{{ route('operator.integrations.discovered') }}" wire:navigate class="rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700">Keşfedilen varlıklar</a>
            <a href="{{ $exportUrl }}" class="rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700">İndir</a>
        </div>
    </header>

    <nav class="flex flex-wrap gap-1 border-b border-gray-200 text-sm dark:border-gray-800" aria-label="Sekmeler">
        @foreach(['queries' => 'Sorgular', 'clusters' => 'Kümeler', 'competitor' => 'Rakip marka', 'irrelevant' => 'Alakasız / yasaklı', 'products' => 'Ürün markaları'] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['-mb-px border-b-2 px-3 py-2', 'border-brand-500 font-semibold text-brand-600' => $tab === $key, 'border-transparent text-gray-500' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </nav>

    @if($message !== '')<p role="status" class="rounded-lg bg-blue-50 p-3 text-sm text-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ $message }}</p>@endif
    @if($errors->any())<p role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif

    @if($tab !== 'products')
        <div class="flex flex-wrap gap-2">
            <input type="search" wire:model.live.debounce.350ms="search" placeholder="Ara…" aria-label="Ara" class="min-w-40 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
            <select wire:model.live="sector" aria-label="Sektör" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Tüm sektörler</option>@foreach($sectors as $s)<option value="{{ $s->code }}">{{ $s->name }}</option>@endforeach</select>
            @if(in_array($tab, ['queries', 'clusters'], true))
                <select wire:model.live="service" aria-label="Hizmet" class="max-w-xs rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Tüm hizmetler</option>@if($tab === 'queries')<option value="none">Eşleşmemiş</option>@endif @foreach($services as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
            @endif
            @if($tab === 'queries' && $clusterOptions->isNotEmpty())
                <select wire:model.live="cluster" aria-label="Küme" class="max-w-xs rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Tüm kümeler</option>@foreach($clusterOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
            @endif
            @if($tab !== 'clusters')
                <select wire:model.live="source" aria-label="Kaynak" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Tüm kaynaklar</option>@foreach($sources as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select>
            @endif
        </div>
    @endif

    @if($tab === 'queries')
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="text-gray-500">{{ $queries->total() }} sorgu · {{ count($selected) }} seçili</span>
            <select wire:model="targetService" aria-label="Taşınacak hizmet" class="max-w-xs rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Hizmet seç</option>@foreach($services as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
            <button type="button" wire:click="assignSelected" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-3 py-1.5 font-semibold text-white">Hizmete taşı</button>
            <button type="button" wire:click="irrelevantSelected" wire:loading.attr="disabled" class="rounded-lg border border-gray-300 px-3 py-1.5 dark:border-gray-700">Alakasız</button>
        </div>
        <section class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-950"><tr><th class="w-8 px-3 py-2"><span class="sr-only">Seç</span></th><th class="px-3 py-2">Sorgu</th><th class="px-3 py-2">Hizmet</th><th class="px-3 py-2">Küme</th><th class="px-3 py-2 text-right">Gösterim / tık</th><th class="px-3 py-2 text-right">Ads</th><th class="px-3 py-2 text-right">Varyant</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($queries as $query)
                        <tr wire:key="q-{{ $query->id }}" class="align-top">
                            <td class="px-3 py-2"><input type="checkbox" wire:model.live="selected" value="{{ $query->id }}" aria-label="{{ $query->canonical_text }}" class="rounded border-gray-300 text-brand-500"></td>
                            <td class="px-3 py-2 font-medium">{{ $query->canonical_text }}</td>
                            <td class="px-3 py-2 text-xs">{{ $query->services->map(fn ($s) => $s->primaryName?->raw_label)->filter()->implode(', ') ?: '—' }}</td>
                            <td class="px-3 py-2 text-xs">{{ $clusterNames[$query->id] ?? '—' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($query->gsc_impressions + $query->gbp_impressions, 0, ',', '.') }} / {{ number_format($query->gsc_clicks, 0, ',', '.') }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $query->ads_impressions ? number_format($query->ads_impressions, 0, ',', '.').' / '.number_format($query->ads_clicks, 0, ',', '.') : '—' }}</td>
                            <td class="px-3 py-2 text-right"><button type="button" wire:click="showVariants({{ $query->id }})" class="text-brand-600 underline">{{ $query->variant_count }}</button></td>
                        </tr>
                        @if($variantsOf === $query->id)
                            <tr wire:key="qv-{{ $query->id }}"><td></td><td colspan="6" class="bg-gray-50 px-3 py-2 text-xs dark:bg-gray-950">
                                @foreach($variants as $variant)
                                    <div class="flex flex-wrap gap-2 py-0.5"><span class="font-medium">{{ $variant->raw_text }}</span><span class="text-gray-500">{{ $sources[$variant->source] ?? $variant->source }} · {{ $variant->resource?->display_name ?? $variant->resource?->external_id ?? '—' }} · {{ number_format($variant->impressions, 0, ',', '.') }}</span>
                                        @if($variant->had_location)<span class="rounded bg-gray-200 px-1 dark:bg-gray-800">yer</span>@endif
                                        @if($variant->had_own_brand)<span class="rounded bg-gray-200 px-1 dark:bg-gray-800">marka</span>@endif
                                        @if($variant->had_product_brand)<span class="rounded bg-gray-200 px-1 dark:bg-gray-800">ürün</span>@endif
                                    </div>
                                @endforeach
                            </td></tr>
                        @endif
                    @empty
                        <tr><td colspan="7" class="px-3 py-8 text-center text-gray-500">Sorgu yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
        {{ $queries->links() }}
        <details class="rounded-xl border border-gray-200 p-3 text-sm dark:border-gray-800">
            <summary class="cursor-pointer font-medium">Elle ekle</summary>
            <div class="mt-2 flex flex-wrap items-start gap-2">
                <textarea wire:model="pasteText" rows="3" aria-label="Sorgular" placeholder="Her satıra bir sorgu" class="min-w-64 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"></textarea>
                <button type="button" wire:click="addQueries" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-3 py-2 font-semibold text-white">Ekle</button>
            </div>
        </details>
    @endif

    @if($tab === 'clusters')
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="text-gray-500">{{ $clusters->total() }} küme</span>
            <button type="button" wire:click="clusterNow" wire:loading.attr="disabled" @disabled($clusteringQueued) class="rounded-lg bg-brand-500 px-3 py-1.5 font-semibold text-white disabled:opacity-50">{{ $clusteringQueued ? 'Kümeleniyor…' : 'Şimdi kümele' }}</button>
        </div>
        <section class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-950"><tr><th class="px-3 py-2">Küme</th><th class="px-3 py-2">Hizmet</th><th class="px-3 py-2 text-right">Sorgu</th><th class="px-3 py-2 text-right">Talep</th><th class="px-3 py-2">Sayfa</th><th class="px-3 py-2">Kanıt</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($clusters as $c)
                        @php($evidence = $c->serp_evidence ? json_decode($c->serp_evidence, true) : null)
                        <tr wire:key="c-{{ $c->id }}" class="align-top">
                            <td class="px-3 py-2"><a href="{{ route('operator.library.search-queries', ['service' => $c->service_id, 'cluster' => $c->id]) }}" wire:navigate class="font-medium hover:text-brand-600">{{ $c->name }}</a>@if($c->head_query)<p class="text-xs text-gray-500">{{ $c->head_query }}</p>@endif</td>
                            <td class="px-3 py-2 text-xs">{{ $services[$c->service_id] ?? '#'.$c->service_id }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $c->query_count }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format((int) $c->demand, 0, ',', '.') }}</td>
                            <td class="px-3 py-2">
                                <select wire:change="setDecision({{ $c->id }}, $event.target.value)" aria-label="Sayfa türü: {{ $c->name }}" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950">
                                    <option value="">—</option>
                                    @foreach($decisions as $code => $label)<option value="{{ $code }}" @selected($c->page_decision === $code)>{{ $label }}</option>@endforeach
                                </select>
                            </td>
                            <td class="px-3 py-2 text-xs">
                                @if($c->decision_source === 'manual')
                                    Manuel
                                @elseif($c->decision_source === 'serp' && $evidence)
                                    <details><summary class="cursor-pointer">Google ilk 10: {{ collect($evidence['counts'] ?? [])->map(fn ($n, $t) => $n.' '.($decisions[$t] ?? ($t === 'rehber' ? 'rehber' : $t)))->implode(', ') }}</summary>
                                        <ol class="mt-1 list-decimal pl-4">@foreach($evidence['results'] ?? [] as $r)<li><span class="text-gray-500">{{ $decisions[$r['type']] ?? $r['type'] }}</span> · {{ $r['domain'] }}</li>@endforeach</ol>
                                    </details>
                                @else
                                    <span class="text-amber-700">kanıt yok</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-8 text-center text-gray-500">Küme yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
        {{ $clusters->links() }}
    @endif

    @if($tab === 'competitor')
        @include('livewire.operator.library.partials.query-variant-table', ['rows' => $competitors, 'empty' => 'Rakip marka sorgusu yok.'])
    @endif

    @if($tab === 'irrelevant')
        <section class="space-y-2">
            <h2 class="text-sm font-semibold">Alakasız</h2>
            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-950"><tr><th class="px-3 py-2">Sorgu</th><th class="px-3 py-2">Sektör</th><th class="px-3 py-2">Karar</th><th class="px-3 py-2 text-right">Talep</th><th></th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse($irrelevant as $row)
                            <tr wire:key="irr-{{ $row->id }}-{{ $row->code }}"><td class="px-3 py-2">{{ $row->canonical_text }}</td><td class="px-3 py-2 text-xs">{{ $row->sector_name }}</td><td class="px-3 py-2 text-xs">{{ $row->match_method === 'manual' ? 'Manuel' : 'AI' }}</td><td class="px-3 py-2 text-right tabular-nums">{{ number_format((int) $row->demand, 0, ',', '.') }}</td>
                                <td class="px-3 py-2 text-right"><button type="button" wire:click="restoreIrrelevant({{ $row->id }}, @js($row->code))" class="text-xs text-brand-600">Geri al</button></td></tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">Yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <h2 class="pt-2 text-sm font-semibold">Yasaklı (kural)</h2>
            @include('livewire.operator.library.partials.query-variant-table', ['rows' => $banned, 'empty' => 'Yok.'])
            <livewire:operator.library.query-exclusions />
        </section>
    @endif

    @if($tab === 'products')
        <section class="max-w-xl space-y-2 text-sm">
            <select wire:model.live="productSector" aria-label="Sektör" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Sektör seç</option>@foreach($sectors as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>
            @if($productSector !== '')
                <textarea wire:model="productList" rows="10" aria-label="Ürün markaları" placeholder="Her satıra bir marka" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"></textarea>
                <button type="button" wire:click="saveProducts" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-3 py-2 font-semibold text-white">Kaydet</button>
            @endif
        </section>
    @endif
</div>
