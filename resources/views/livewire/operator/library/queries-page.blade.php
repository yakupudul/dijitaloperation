@php
    $card = 'min-w-0 space-y-3';
    $field = 'rounded-lg border border-gray-200 bg-white px-2.5 text-[13px] text-gray-900 placeholder:text-gray-400 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
    $input = $field.' h-8';
    $btn = 'btn-primary';
    $ghost = 'btn h-7! px-2.5! text-xs!';
    $secondary = 'btn';
    $hint = 'text-xs text-gray-500 dark:text-gray-500';
    $chip = 'text-xs';
    $num = fn ($value) => number_format((float) $value, 0, ',', '.');
    $tabClass = fn (bool $active): string => $active ? 'tab tab-active' : 'tab';
    $subTab = fn (bool $active): string => 'inline-flex h-8 items-center gap-1.5 rounded-lg px-3 text-[13px] font-medium '.($active ? 'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white');
    $tableWrap = 'card overflow-x-auto';
    $how = 'inline-flex cursor-pointer list-none items-center gap-1 text-[13px] text-gray-500 hover:text-gray-900 dark:hover:text-white [&::-webkit-details-marker]:hidden';
    $countPill = 'font-normal tabular-nums text-gray-500';
    $deletionsTotal = array_sum($reviewCounts);
@endphp
<div class="space-y-10 text-sm dark:text-gray-200" data-queries-page @if ($polling) wire:poll.3s="{{ $negAwaiting ? 'pollNegatives' : '$refresh' }}" @endif>
    <header>
        <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
            <div class="min-w-0">
                <h1 class="page-title" title="Arama sorgularının ortak kütüphanesi: hizmete atama, kümeler, filtre ve eşleme kelimeleri.">Sorgular</h1>
                <p class="page-sub">{{ $num($pendingCount) }} bekleyen sorgu, {{ $num($deletionsTotal) }} onay bekleyen değişiklik.</p>
            </div>
            <a href="{{ route('operator.library.queries.plan') }}" wire:navigate class="{{ $secondary }} w-full sm:w-auto">AI ile planla</a>
        </div>
    </header>

    @php
        $ap = $autopilot;
        $apTotals = (array) ($ap['totals'] ?? []);
    @endphp
    <section data-autopilot>
        <div class="section-head items-center!">
            <h2 class="section-title">Otomatik pilot</h2>
            @if (! $autopilotReady)
                <x-ta.pill tone="info">başlamadı</x-ta.pill>
            @elseif ($autopilotPaused)
                <x-ta.pill tone="look">durduruldu</x-ta.pill>
            @elseif (($ap['status'] ?? null) === 'claude')
                <x-ta.pill tone="ok" data-autopilot-claude>açık, AI bakışı Claude kuyruğunda</x-ta.pill>
            @elseif (($ap['status'] ?? null) === 'no_provider')
                <x-ta.pill tone="look">açık, AI bağlı değil</x-ta.pill>
            @else
                <x-ta.pill tone="ok">açık</x-ta.pill>
            @endif
            <details class="relative" data-autopilot-info>
                <summary class="flex h-5 w-5 cursor-pointer list-none items-center justify-center rounded-full border border-gray-300 text-[11px] font-semibold text-gray-500 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 [&::-webkit-details-marker]:hidden" title="Otomatik pilot nasıl çalışır?" aria-label="Otomatik pilot nasıl çalışır?">i</summary>
                <div class="fixed inset-x-4 top-20 z-20 mt-2 space-y-1.5 rounded-xl border border-gray-200 bg-white p-3.5 text-[13px] leading-5 text-gray-600 shadow-lg sm:absolute sm:inset-x-auto sm:left-0 sm:top-auto sm:w-96 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                    <p>Her 15 dakikada: AI hizmeti atanmamış sorgulara bir kez bakar (hizmet, filtre kelimesi ya da hiçbiri), sonra Bekleyenler içe alınır; günde bir yeni sorgular kümelenir (yeni kümeler onayınla markalara iner). Filtre sepetine uyan sorgular saatte bir toplu silinir.</p>
                    @if (! $autopilotReady)
                        <p>"AI ile planla" ilk içe aktarımı onaylanınca başlar.</p>
                    @else
                        <p class="text-gray-500">Başlangıçtan beri: {{ $num($apTotals['assigned'] ?? 0) }} atandı, {{ $num($apTotals['filters'] ?? 0) }} filtre kelimesi, {{ $num($apTotals['keywords'] ?? 0) }} eşleme kelimesi, {{ $num($apTotals['imported'] ?? 0) }} bekleyen içe alındı, temizlikte {{ $num($apTotals['deleted'] ?? 0) }} silindi @if (! empty($ap['tick_at'])). Son tur {{ \Illuminate\Support\Carbon::parse($ap['tick_at'])->diffForHumans() }}@endif</p>
                    @endif
                    @if ($finishCheck !== null)<p class="text-gray-500">{{ $finishCheck }}</p>@endif
                </div>
            </details>
            @if ($autopilotReady)
                <button type="button" wire:click="toggleAutopilot" class="btn ml-auto">{{ $autopilotPaused ? 'Başlat' : 'Durdur' }}</button>
            @endif
        </div>
        <div class="metric-strip grid-cols-2 lg:grid-cols-4" data-autopilot-counters>
            <x-ta.metric-card label="Atanan" :value="$num($apTotals['assigned'] ?? 0)" title="Otomatik pilotun başlangıçtan beri hizmete atadığı sorgu" />
            <x-ta.metric-card label="Bekleyen" :value="$num($pendingCount)" title="Kütüphanede olmayan, filtreye takılmayan bekleyen sorgu" />
            <x-ta.metric-card label="AI sırasında" :value="$num($autopilotQueue)" title="AI'ın bakacağı hizmetsiz sorgu" />
            <x-ta.metric-card label="Son kontrol" :value="$finishCheckAt ?? '—'" :muted="$finishCheckAt === null" :title="$finishCheck" :data-finish-check="$finishCheck !== null" />
        </div>
        @if ($finishStage !== null && $finishStage !== 'done')<p class="mt-2 text-[13px] text-gray-500" data-finish-stage>Bitirme zinciri sürüyor: {{ ['queued' => 'kuyrukta', 'clean' => 'katalog temizliği', 'triage' => 'sorgular yeniden soruluyor', 'cluster' => 'kümeleme', 'wait' => 'kümeleme sürüyor', 'content' => 'içerik başlıkları', 'check' => 'kontrol'][$finishStage] ?? $finishStage }}</p>@endif
    </section>

    <div class="space-y-5">
    <nav class="no-scrollbar flex min-w-0 gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Sekmeler">
        @foreach (\App\Livewire\Operator\Library\QueriesPage::TABS as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')" data-tab="{{ $key }}" @if ($tab === $key) aria-current="page" @endif class="{{ $tabClass($tab === $key) }}">{{ $label }}@if ($key === 'pending' && $pendingCount > 0)<span class="{{ $countPill }}" data-pending-count>{{ $pendingCount }}</span>@endif @if ($key === 'deletions' && $deletionsTotal > 0)<span class="font-normal tabular-nums text-warning-700 dark:text-warning-300" data-deletions-count>{{ $deletionsTotal }}</span>@endif</button>
        @endforeach
    </nav>

    @if ($message !== '')
        <p role="status" class="tone-info flex items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm">
            <span>{{ $message }}</span><button type="button" wire:click="$set('message', '')" aria-label="Kapat" class="px-2">×</button>
        </p>
    @endif

    {{-- Filtreler --}}
    <section class="flex flex-wrap items-center gap-2" data-filters>
        <select wire:model.live="sector" aria-label="Sektör" class="{{ $input }} w-full min-w-0 sm:w-auto sm:max-w-56">
            <option value="">Tüm sektörler</option>
            @foreach ($sectors as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
        </select>
        @if (! in_array($tab, ['filters', 'keywords', 'pending', 'deletions', 'forbidden'], true))
            <select wire:model.live="service" aria-label="Hizmet" class="{{ $input }} w-full min-w-0 sm:w-auto sm:max-w-56">
                <option value="">Tüm hizmetler</option>
                @if ($tab === 'queries')<option value="__any">Atanmış</option><option value="__none">Atanmamış</option>@endif
                @foreach ($services as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
        @endif
        @if ($tab === 'queries')
            <select wire:model.live="cluster" aria-label="Küme" class="{{ $input }} w-full min-w-0 sm:w-auto sm:max-w-56">
                <option value="">Tüm kümeler</option>
                <option value="__none">Kümesiz</option>
                @foreach ($clusterOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Ara" aria-label="Ara" class="{{ $input }} w-full min-w-0 sm:w-48">
            <label class="flex h-8 items-center gap-1.5 text-[13px] text-gray-600 dark:text-gray-300"><input type="checkbox" wire:model.live="hidden" data-hidden-filter class="rounded border-gray-300 dark:border-gray-700"> Gizlenenler</label>
            <label class="flex h-8 items-center gap-1.5 text-[13px] text-gray-600 dark:text-gray-300" title="Aynı anlamdaki sorgular (implant = diş implantı) tek satır; toplu işlemler tüm varyantlara uygulanır"><input type="checkbox" wire:model.live="variants" data-variants-toggle class="rounded border-gray-300 dark:border-gray-700"> Varyantları birleştir</label>
        @endif
        @if ($tab === 'deletions')
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Ara" aria-label="Ara" class="{{ $input }} w-full sm:w-48">
        @endif
        @if ($tab === 'queries' || $tab === 'clusters')
            @php $clusterBlocked = ! ctype_digit($service); @endphp
            <span class="inline-flex items-center gap-2 sm:ml-auto">
                @if ($clusterBlocked)<span class="{{ $hint }}" data-cluster-hint>Önce bir hizmet seçin</span>@endif
                <button type="button" wire:click="clusterService" @disabled($clusterBlocked) class="{{ $secondary }}" @if ($clusterBlocked) title="Kümelemek için soldan bir hizmet seçin" @endif>AI ile kümele</button>
            </span>
            <x-operator.ai-prompt-info operation="queries.cluster" />
            @php $runLabel = \App\Services\Queries\QueryClusterer::label($clusterStatus); @endphp
            @if ($runLabel !== null)<span class="text-xs {{ $runLabel['tone'] === 'error' ? 'text-error-600' : 'text-gray-500' }}" data-cluster-run>{{ $runLabel['text'] }}</span>@endif
        @endif
    </section>
    </div>

    {{-- Sorgular --}}
    @if ($tab === 'queries')
        <section class="{{ $card }}" data-section="queries">
            @php
                $selectedCount = $selectAll ? $matchingCount : count($selected);
                $hasSelection = $selectAll || $selected !== [];
                $excludedIds = array_map('intval', $excluded);
                $assignStatus = $assign['status'] ?? null;
            @endphp
            {{-- Hizmet ataması kuyruğu: one compact "bak" line (2026-10-19: was a yellow band with an orange button). --}}
            @php $suggestBlocked = $assignStatus === 'running' || $unassignedCount === 0; @endphp
            <div class="card flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3" data-assign-queue>
                <span @class(['h-2 w-2 shrink-0 rounded-full', 'bg-warning-500' => $unassignedCount > 0, 'bg-success-500' => $unassignedCount === 0]) aria-hidden="true"></span>
                <button type="button" wire:click="$set('service', '__none')" class="min-w-0 flex-1 text-left text-sm font-medium text-gray-900 hover:underline dark:text-white">Hizmet ataması kuyruğu, {{ $num($unassignedCount) }} atanmamış sorgu</button>
                <span class="inline-flex items-center gap-1.5">
                    <button type="button" wire:click="suggestServices" @disabled($suggestBlocked) class="{{ $secondary }}" @if ($suggestBlocked) title="{{ $assignStatus === 'running' ? 'Öneri çalışıyor' : 'Atanmamış sorgu yok' }}" @endif>AI ile hizmet öner</button>
                    <x-operator.ai-prompt-info operation="queries.assign_services" />
                </span>
            </div>
            @if ($assign !== null)
                <div class="card space-y-3 p-4" data-assign-proposal>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="section-title">AI hizmet önerisi</h2>
                        @if ($assignStatus === 'running')
                            <span class="text-xs text-gray-500">@if (! empty($assign['waiting']))Claude bekleniyor (kuyruk iş saatlerinde 2 saatte bir çalışır)… @else çalışıyor… @endif{{ $num($assign['done'] ?? 0) }} / {{ $num($assign['total'] ?? 0) }} sorgu</span>
                        @elseif ($assignStatus === 'ready')
                            <span class="text-xs text-gray-500">{{ $num(count($assign['items'])) }} öneri, {{ $num($assign['done'] ?? 0) }} sorgu incelendi</span>
                            @if (($assign['failed'] ?? 0) > 0)<span class="text-xs text-error-600">{{ $assign['failed'] }} parti yanıt vermedi, tekrar çalıştırın</span>@endif
                        @else
                            <span class="text-xs text-error-600">{{ ['no_provider' => 'AI bağlı değil.', 'nothing' => 'Atanmamış sorgu yok.', 'no_services' => 'Sorguların sektörlerinde hizmet yok.'][$assignStatus] ?? 'Öneri alınamadı.' }}</span>
                        @endif
                        <button type="button" wire:click="closeAssignments" class="{{ $ghost }} ml-auto">Kapat</button>
                    </div>
                    @if ($assignStatus === 'ready')
                        @php
                            $flipIds = array_map('intval', $assignFlip);
                            $skipKeywords = array_map('intval', $assignKeywordSkip);
                            $pages = (int) ceil(count($assign['items']) / \App\Livewire\Operator\Library\QueriesPage::ASSIGN_PER_PAGE);
                        @endphp
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <button type="button" wire:click="setAssignAll(true)" class="{{ $ghost }}">Tümünü seç</button>
                            <button type="button" wire:click="setAssignAll(false)" class="{{ $ghost }}">Hiçbirini seçme</button>
                            @if ($pages > 1)
                                <button type="button" wire:click="assignPageTo({{ $assignPage - 1 }})" @disabled($assignPage === 0) class="{{ $ghost }}">‹</button>
                                <span class="text-gray-500">{{ $assignPage + 1 }} / {{ $pages }}</span>
                                <button type="button" wire:click="assignPageTo({{ $assignPage + 1 }})" @disabled($assignPage + 1 >= $pages) class="{{ $ghost }}">›</button>
                            @endif
                        </div>
                        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800"><table class="data-table">
                            <thead><tr><th class="w-6"></th><th>Sorgu</th><th>Önerilen hizmet</th><th>Neden</th></tr></thead>
                            <tbody>
                                @forelse ($assignRows as $i => $row)
                                    <tr wire:key="as-{{ $i }}">
                                        <td class="py-1"><input type="checkbox" wire:click="toggleAssign({{ $i }})" @checked($assignInvert === in_array((int) $i, $flipIds, true)) aria-label="Seç"></td>
                                        <td class="font-medium">{{ $row['text'] }}</td>
                                        <td>{{ $row['service'] }}</td>
                                        <td class="text-gray-500">{{ $row['reason'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-gray-500">Öneri yok.</td></tr>
                                @endforelse
                            </tbody>
                        </table></div>
                        @if ($assign['keywords'] !== [])
                            <h3 class="text-sm font-medium text-gray-900 dark:text-white">Eklenecek eşleme kelimeleri <span class="font-normal text-gray-500">{{ count($assign['keywords']) }}</span></h3>
                            <ul class="space-y-1.5">
                                @foreach ($assign['keywords'] as $i => $row)
                                    <li wire:key="ak-{{ $i }}" class="flex items-start gap-2 text-[13px]"><input type="checkbox" wire:click="toggleAssignKeyword({{ $i }})" @checked(! in_array((int) $i, $skipKeywords, true)) aria-label="Seç" class="mt-0.5 rounded border-gray-300 dark:border-gray-700">
                                        <span><span class="font-medium text-gray-900 dark:text-white">{{ $row['keyword'] }}</span>, {{ $row['service'] }} hizmetine <span class="block text-xs text-gray-500">{{ $row['reason'] }}</span></span></li>
                                @endforeach
                            </ul>
                        @endif
                        <div class="flex justify-end gap-2">
                            <button type="button" wire:click="closeAssignments" class="{{ $ghost }}">Vazgeç</button>
                            <button type="button" wire:click="approveAssignments" class="{{ $btn }}">Onayla</button>
                        </div>
                    @endif
                </div>
            @endif
            {{-- One wrapping toolbar; the bulk actions appear in a sticky bar only once rows are selected (2026-10-19). --}}
            <div class="flex flex-wrap items-center gap-2 pt-4" data-queries-toolbar>
                <h2 class="section-title mr-1">Sorgu listesi <span class="section-count" data-selection-count>{{ $num($queries->total()) }}, seçili {{ $num($selectedCount) }}</span></h2>
                <button type="button" wire:click="selectPage" class="{{ $ghost }}">Sayfadaki tümünü seç</button>
                @if (! $selectAll)<button type="button" wire:click="selectAllMatching" class="{{ $ghost }}">Filtreye uyan tümünü seç ({{ $num($queries->total()) }})</button>@endif
                @if ($ruleStats !== null)
                    <span class="inline-flex flex-wrap items-center gap-2 text-[13px] text-gray-600 sm:ml-auto dark:text-gray-300" data-rule-stats>
                        <span title="Kural sürümü {{ $ruleVersion }}"><span class="font-medium tabular-nums">{{ $num($ruleStats['queries']) }}</span> sorgu, <span class="font-medium tabular-nums">{{ $num($ruleStats['variants']) }}</span> varyant, <span class="font-medium tabular-nums">{{ $num($ruleStats['topics']) }}</span> konu</span>
                        <button type="button" wire:click="applyRules" class="{{ $ghost }}" data-apply-rules>Kuralları uygula</button>
                        <a href="{{ route('operator.library.queries.export') }}" class="{{ $ghost }}" data-export>CSV indir</a>
                    </span>
                @endif
            </div>
            @unless ($hasSelection)
                <p class="{{ $hint }}" data-rules-hint>Sorgu seçince açılır: Hizmete ata, Hizmeti kaldır, {{ $hidden ? 'Geri al' : 'Sil' }}, Filtreye ekle, AI ile filtre kural üret</p>
            @endunless
            @if ($selectAll)
                <p class="tone-info rounded-lg px-3 py-2 text-[13px]" data-select-all>Filtreye uyan {{ $num($matchingCount) }} sorgunun tümü seçili (tüm sayfalar)@if ($excludedIds !== []), {{ count($excludedIds) }} hariç @endif.</p>
            @endif
            <div class="{{ $tableWrap }}">
                <table class="data-table">
                    <thead><tr>
                        <th class="w-6 pr-0!"></th><th>Sorgu</th><th>Hizmet</th><th class="hidden lg:table-cell">Küme</th>
                        <th class="text-right">Gösterim</th><th class="hidden text-right md:table-cell">Tıklama</th><th class="hidden text-right md:table-cell">Ads maliyet</th><th class="hidden md:table-cell">Kaynaklar</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($queries as $query)
                            <tr wire:key="q-{{ $query->id }}">
                                <td class="pr-0!">@if ($selectAll)<input type="checkbox" wire:click="toggleExcluded({{ $query->id }})" @checked(! in_array((int) $query->id, $excludedIds, true)) aria-label="Seç">@else<input type="checkbox" wire:model.live="selected" value="{{ $query->id }}" aria-label="Seç">@endif</td>
                                @php $group = $variantGroups[(int) $query->sector_id.'|'.$query->variant_key] ?? null; @endphp
                                <td class="min-w-48 font-medium text-gray-900 dark:text-white"><span class="flex items-baseline gap-1.5">@if ($query->service_id === null && ! $query->is_suggested)<span class="inline-block h-1.5 w-1.5 shrink-0 -translate-y-0.5 rounded-full bg-warning-500" title="atanmamış: hizmeti yok" data-unassigned-dot></span><span class="sr-only">atanmamış</span>@else<span class="inline-block h-1.5 w-1.5 shrink-0" aria-hidden="true"></span>@endif<span class="min-w-0">{{ $query->text }}@if ($query->is_suggested) <span class="{{ $chip }} ml-1 font-normal text-live-700 dark:text-live-300">önerilen</span>@endif @if ($group !== null && $group['count'] > 1) <span class="{{ $chip }} ml-1 font-normal text-gray-500" title="Varyantlarla toplam {{ $num($group['impressions']) }} gösterim, {{ $num($group['clicks']) }} tıklama" data-variant-count>+{{ $group['count'] - 1 }} varyant</span>@endif @if ($query->facets)<span class="block text-xs font-normal text-gray-500">{{ str_replace(',', ', ', $query->facets) }}</span>@endif</span></span></td>
                                <td class="whitespace-nowrap">{{ $query->service?->primaryName?->raw_label ?? '—' }}@if ($query->locked)<span class="ml-1 text-gray-500">(elle)</span>@endif</td>
                                <td class="hidden text-[13px] text-gray-600 lg:table-cell dark:text-gray-400">{{ $query->clusterLink?->cluster?->name ?? '—' }}</td>
                                @if ($query->is_suggested)
                                    <td class="text-right text-gray-500">—</td><td class="hidden text-right text-gray-500 md:table-cell">—</td><td class="hidden text-right text-gray-500 md:table-cell">—</td><td class="hidden text-gray-500 md:table-cell">—</td>
                                @else
                                    <td class="whitespace-nowrap text-right tabular-nums">{{ $num($query->impressions) }}</td>
                                    <td class="hidden whitespace-nowrap text-right tabular-nums md:table-cell">{{ $num($query->clicks) }}</td>
                                    <td class="hidden whitespace-nowrap text-right tabular-nums md:table-cell">{{ $query->ads_cost !== null ? number_format($query->ads_cost, 2, ',', '.') : '—' }}</td>
                                    @php $sourceKeys = array_values(array_filter(explode(',', (string) $query->sources))); @endphp
                                    <td class="hidden whitespace-nowrap md:table-cell" data-sources>
                                        @forelse ($sourceKeys as $source)<span class="mr-1 inline-block text-xs leading-4 text-gray-600 dark:text-gray-300" title="{{ \App\Livewire\Operator\Library\QueriesPage::SOURCE_TITLES[$source] ?? $source }}">{{ \App\Livewire\Operator\Library\QueriesPage::SOURCE_LABELS[$source] ?? $source }}</span>@empty<span class="text-gray-500">—</span>@endforelse
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-gray-500">Sorgu yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $queries->links() }}</div>
            @if ($hasSelection)
                <div class="sticky bottom-3 z-30 mt-3 flex flex-wrap items-center gap-2 rounded-xl bg-gray-900 px-4 py-3 text-xs text-white shadow-theme-lg ring-1 ring-white/10 dark:bg-gray-800" data-queries-selection>
                    <span class="text-sm font-semibold">{{ $num($selectedCount) }} sorgu seçili</span>
                    <select wire:model="bulkService" aria-label="Atanacak hizmet" class="h-8 min-w-0 max-w-full rounded-lg border-0 bg-white/10 py-1 text-xs text-white focus:ring-2 focus:ring-white/20">
                        <option value="" class="text-gray-900">Hizmet…</option>
                        @foreach ($services as $id => $name)<option value="{{ $id }}" class="text-gray-900">{{ $name }}</option>@endforeach
                    </select>
                    <button type="button" wire:click="assignSelected" class="inline-flex h-7 items-center rounded-lg border border-white/20 px-2.5 text-xs font-medium hover:bg-white/10">Hizmete ata</button>
                    <button type="button" wire:click="unassignSelected" wire:confirm="Seçili sorguların hizmeti kaldırılsın mı?" class="inline-flex h-7 items-center rounded-lg border border-white/20 px-2.5 text-xs font-medium hover:bg-white/10">Hizmeti kaldır</button>
                    @if ($hidden)
                        <button type="button" wire:click="unhideSelected" class="inline-flex h-7 items-center rounded-lg border border-white/20 px-2.5 text-xs font-medium hover:bg-white/10">Geri al</button>
                    @else
                        <button type="button" wire:click="hideSelected" wire:confirm="Seçili sorgular gizlensin mi?" class="inline-flex h-7 items-center rounded-lg border border-white/20 px-2.5 text-xs font-medium hover:bg-white/10">Sil</button>
                    @endif
                    <button type="button" wire:click="openNegatives" class="inline-flex h-7 items-center rounded-lg border border-white/20 px-2.5 text-xs font-medium hover:bg-white/10">Filtreye ekle</button>
                    <span class="inline-flex items-center gap-1">
                        <button type="button" wire:click="proposeRules" class="inline-flex h-7 items-center rounded-lg border border-white/20 px-2.5 text-xs font-medium hover:bg-white/10">AI ile filtre kural üret</button>
                        <x-operator.ai-prompt-info operation="queries.filter_rules" />
                    </span>
                    <button type="button" wire:click="clearSelection" class="ml-auto text-gray-300 hover:underline">Seçimi temizle</button>
                </div>
            @endif
        </section>
    @endif

    {{-- Bekleyenler --}}
    @if ($tab === 'pending')
        <section class="{{ $card }}" data-section="pending">
            <div class="section-head mb-0! items-center!">
                <h2 class="section-title" title="Kütüphanede olmayan, filtreye takılmayan sorgular">Bekleyen sorgular <span class="section-count">{{ $num($pending->total()) }}</span></h2>
                <span class="flex w-full gap-2 sm:ml-auto sm:w-auto">
                    <button type="button" wire:click="dismissPending" wire:confirm="Seçilen sorgular yoksayılsın mı?" class="btn flex-1 sm:flex-none">Yoksay</button>
                    <button type="button" wire:click="importPending" class="{{ $btn }} flex-1 sm:flex-none">Seçilenleri içe aktar</button>
                </span>
            </div>
            <div class="{{ $tableWrap }}">
                <table class="data-table">
                    <thead><tr><th class="w-6 pr-0!"></th><th>Hesap</th><th>Sorgu</th><th>Önerilen hizmet</th><th class="text-right">Gösterim</th></tr></thead>
                    <tbody>
                        @forelse ($pending as $row)
                            <tr wire:key="p-{{ $row->id }}">
                                <td class="pr-0!"><input type="checkbox" wire:click="togglePending({{ $row->id }})" @checked(! in_array((int) $row->id, array_map('intval', $pendingFlip), true)) aria-label="Seç"></td>
                                <td class="text-[13px] text-gray-600 dark:text-gray-400">{{ $row->brand?->name ?? '—' }}<span class="block text-xs text-gray-500">{{ $row->asset?->name ?? '—' }}</span></td>
                                <td class="font-medium text-gray-900 dark:text-white">{{ $row->text }}</td>
                                <td>{{ $row->service?->primaryName?->raw_label ?? '—' }}</td>
                                <td class="text-right tabular-nums">{{ $num($row->impressions) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-gray-500">Bekleyen sorgu yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $pending->links() }}</div>
        </section>
    @endif

    {{-- Silinecekler: every rescan's proposals in one place (one line per query, the latest scan wins) --}}
    @if ($tab === 'deletions')
        <section class="{{ $card }}" data-section="deletions">
            @php
                $selectedCount = $selectAll ? $matchingCount : count($selected);
                $hasSelection = $selectAll || $selected !== [];
                $excludedIds = array_map('intval', $excluded);
                $isDelete = $reviewKind === \App\Models\QueryReviewItem::DELETE;
                $serviceName = fn ($service) => $service?->primaryName?->raw_label ?? '—';
            @endphp
            <div class="flex flex-wrap items-center gap-2">
                @foreach (['delete' => 'Silinecek sorgular', 'service' => 'Hizmet değişikliği'] as $kind => $label)
                    <button type="button" wire:click="$set('reviewKind', '{{ $kind }}')" data-review-kind="{{ $kind }}" class="{{ $subTab($reviewKind === $kind) }}">{{ $label }} <span class="{{ $countPill }}">{{ $num($reviewCounts[$kind]) }}</span></button>
                @endforeach
                @if ($isDelete && ! $reviewKept)
                    <select wire:model.live="reviewTerm" aria-label="Filtre terimi" data-review-term class="{{ $input }}">
                        <option value="">Tüm terimler</option>
                        @foreach ($reviewTerms as $term => $total)<option value="{{ $term }}">{{ $term }} ({{ $total }})</option>@endforeach
                    </select>
                @endif
                <label class="flex h-8 items-center gap-1.5 text-[13px] text-gray-600 dark:text-gray-300"><input type="checkbox" wire:model.live="reviewKept" data-review-kept class="rounded border-gray-300 dark:border-gray-700"> Tutulanlar ({{ $num($keptCount) }})</label>
                @unless ($reviewRunning)<button type="button" wire:click="rescanLibrary" class="btn sm:ml-auto" data-review-rescan>Kütüphaneyi yeniden tara</button>@endunless
                @if ($reviewRunning)<span class="inline-flex items-center gap-1.5 text-[13px] text-gray-500 sm:ml-auto" data-review-running><svg class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3"/></svg>Tarama sürüyor…</span>@endif
            </div>
            <details class="text-[13px] text-gray-500"><summary class="{{ $how }}">Nasıl çalışır? <svg class="h-3 w-3" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m3 4.5 3 3 3-3"/></svg></summary><p class="mt-1.5 max-w-3xl leading-5">Her filtre / eşleme kelimesi değişikliğinin taraması buraya eklenir; bir sorgu için son öneri geçerlidir. Onaylanmadan hiçbir sorgu silinmez veya değişmez; kilitli sorgular, kilitli kümelerin sorguları ve elle / onaylı AI ile atananlar hiç önerilmez. "Tut": sorgu kalır, aynı öneri tekrar gelmez.</p></details>
            <div class="flex flex-wrap items-center gap-2 pt-2">
                <span class="mr-1 text-[13px] text-gray-500" data-selection-count>{{ $num($reviewItems->total()) }} satır, seçili {{ $num($selectedCount) }}</span>
                <button type="button" wire:click="selectPage" class="{{ $ghost }}">Sayfadaki tümünü seç</button>
                @if (! $selectAll)<button type="button" wire:click="selectAllMatching" class="{{ $ghost }}">Filtreye uyan tümünü seç ({{ $num($reviewItems->total()) }})</button>@endif
                @if ($hasSelection)<button type="button" wire:click="clearSelection" class="{{ $ghost }}">Seçimi temizle</button>@endif
                @if ($reviewKept)
                    <button type="button" wire:click="keepReview" @disabled(! $hasSelection) class="btn sm:ml-auto">Geri al</button>
                @else
                    <button type="button" wire:click="keepReview" @disabled(! $hasSelection) class="btn sm:ml-auto">Tut</button>
                    <button type="button" wire:click="approveReview" wire:confirm="{{ $isDelete ? 'Seçilen sorgular silinsin mi?' : 'Seçilen sorguların hizmeti değişsin mi?' }}" @disabled(! $hasSelection) class="{{ $btn }}">{{ $isDelete ? 'Onayla ve sil' : 'Onayla ve uygula' }}</button>
                @endif
            </div>
            @if ($selectAll)
                <p class="tone-info rounded-lg px-3 py-2 text-[13px]" data-select-all>Filtreye uyan {{ $num($matchingCount) }} satır seçili (tüm sayfalar)@if ($excludedIds !== []), {{ count($excludedIds) }} hariç @endif.</p>
            @endif
            <div class="{{ $tableWrap }}">
                <table class="data-table">
                    <thead><tr>
                        <th class="w-6 pr-0!"></th><th>Sorgu</th>
                        @if ($isDelete)<th>Filtre terimi</th>@else<th>Mevcut</th><th>Yeni</th><th>Değişiklik</th>@endif
                        <th class="text-right">Gösterim</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($reviewItems as $item)
                            <tr wire:key="r-{{ $item->id }}">
                                <td class="pr-0!">@if ($selectAll)<input type="checkbox" wire:click="toggleExcluded({{ $item->id }})" @checked(! in_array((int) $item->id, $excludedIds, true)) aria-label="Seç">@else<input type="checkbox" wire:model.live="selected" value="{{ $item->id }}" aria-label="Seç">@endif</td>
                                <td class="font-medium text-gray-900 dark:text-white">{{ $item->searchQuery?->text }}</td>
                                @if ($isDelete)
                                    <td><span class="text-[13px] font-medium text-error-700 dark:text-error-400">{{ $item->term }}</span></td>
                                @else
                                    <td>{{ $serviceName($item->fromService) }}</td>
                                    <td>@if ($item->to_service_id === null)<span class="text-warning-700 dark:text-warning-300">Hizmetsiz</span>@else{{ $serviceName($item->toService) }}@endif</td>
                                    <td class="min-w-64 text-[13px] text-gray-500" data-review-reason>{{ $item->from_service_id === null ? 'yeni atama' : ($item->to_service_id === null ? ($item->reason === \App\Models\QueryReviewItem::REASON_CONFLICT ? 'hizmetsiz kalacak (iki hizmetin kelimesi eşleşiyor; onaylarsan hizmeti kaldırılır)' : 'hizmetsiz kalacak (artık hiçbir hizmetin kelimesi eşleşmiyor)') : 'değişiyor') }}<span class="block text-xs">{{ $item->reason === \App\Models\QueryReviewItem::REASON_CONFLICT ? 'çakışma: '.$item->term : ($item->term !== null ? 'eşleşen kelime: '.$item->term : 'hiçbir eşleme kelimesi eşleşmiyor') }}{{ $item->reason === \App\Models\QueryReviewItem::REASON_SECTOR ? ', sektör uyuşmuyor (mevcut hizmet başka sektörde)' : '' }}</span></td>
                                @endif
                                <td class="text-right tabular-nums">{{ $num($item->searchQuery?->impressions ?? 0) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-gray-500">{{ $reviewKept ? 'Tutulan satır yok.' : 'Onay bekleyen satır yok.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $reviewItems->links() }}</div>
        </section>
    @endif

    {{-- Kümeler --}}
    @if ($tab === 'clusters')
        <section class="{{ $card }}" data-section="clusters">
            @if ($clusters === null)
                @php $queueRunning = ($clusterQueue['status'] ?? null) === 'running'; @endphp
                <div class="flex flex-wrap items-center gap-2" data-cluster-all>
                    @if ($queueRunning)
                        <span class="live-dot" aria-hidden="true"></span>
                        <span class="text-[13px] text-gray-600 dark:text-gray-300">Toplu kümeleme sürüyor: {{ (int) $clusterQueue['done'] }} / {{ (int) $clusterQueue['total'] }} hizmet bitti, sırada {{ count($clusterQueue['queue']) }}</span>
                        <button type="button" wire:click="stopClusterAll" class="btn">Durdur</button>
                    @else
                        <button type="button" wire:click="clusterAll" wire:confirm="Kümede olmayan sorgusu olan tüm hizmetler sırayla, parça parça kümelenecek (her parça bir AI çağrısı). Başlatılsın mı?" class="{{ $btn }}">Hepsini kümele</button>
                        @if (($clusterQueue['status'] ?? null) === 'ready')<span class="text-[13px] text-gray-500">Son toplu kümeleme bitti, {{ (int) $clusterQueue['done'] }} hizmet</span>
                        @elseif (($clusterQueue['status'] ?? null) === 'stopped')<span class="text-[13px] text-gray-500">Son toplu kümeleme durduruldu, {{ (int) $clusterQueue['done'] }} / {{ (int) $clusterQueue['total'] }} hizmet</span>@endif
                    @endif
                    <details class="w-full text-[13px] text-gray-500"><summary class="{{ $how }}">Nasıl çalışır? <svg class="h-3 w-3" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m3 4.5 3 3 3-3"/></svg></summary><p class="mt-1.5 max-w-3xl leading-5">Hizmetler sırayla işlenir; her hizmette önce iskelet, sonra kalan konular 300'erli parçalar halinde, en sonda gözden geçirme. Onaylı kümelerin tanımı değişmez.</p></details>
                </div>
                @if ($overview === [])
                    <div class="card"><x-ta.empty-state icon="none" size="sm" title="Sorgusu olan hizmet yok." /></div>
                @else
                    <div class="{{ $tableWrap }}"><table class="data-table" data-cluster-overview>
                        <thead><tr><th>Hizmet</th><th>Markalar</th><th class="text-right">Sorgu</th><th class="text-right">Kümede</th><th class="text-right">Küme</th><th>Durum</th><th></th></tr></thead>
                        @foreach ($overview as $sectorName => $rows)
                            <tbody>
                                <tr><td colspan="7" class="bg-gray-50/70 py-2! text-xs font-medium text-gray-500 dark:bg-white/[0.02]">{{ $sectorName }}</td></tr>
                                @foreach ($rows as $row)
                                    @php $rowLabel = \App\Services\Queries\QueryClusterer::label($row['state']); $rowRunning = ($row['state']['status'] ?? null) === 'running'; $open = $row['queries'] - $row['clustered']; @endphp
                                    <tr wire:key="so-{{ $row['id'] }}" data-service-row="{{ $row['id'] }}">
                                        <td class="whitespace-nowrap font-medium text-gray-900 dark:text-white"><button type="button" wire:click="$set('service', '{{ $row['id'] }}')" class="text-left hover:underline">{{ $row['name'] }}</button></td>
                                        <td class="text-[13px] text-gray-600 dark:text-gray-400">{{ $row['brands'] === [] ? '—' : implode(', ', $row['brands']) }}</td>
                                        <td class="text-right tabular-nums">{{ number_format($row['queries'], 0, ',', '.') }}</td>
                                        <td class="text-right tabular-nums">{{ number_format($row['clustered'], 0, ',', '.') }}</td>
                                        <td class="whitespace-nowrap text-right tabular-nums">{{ $row['clusters'] }}@if ($row['approved'] > 0) <span class="text-gray-500">({{ $row['approved'] }} onaylı)</span>@endif</td>
                                        <td class="text-[13px]">
                                            @if ($rowLabel !== null && ($rowRunning || $rowLabel['tone'] === 'error' || ($clusterQueue['current'] ?? null) === $row['id']))
                                                <span class="{{ $rowLabel['tone'] === 'error' ? 'text-error-600' : 'text-gray-600 dark:text-gray-300' }}">{{ $rowLabel['text'] }}</span>
                                            @elseif ($queueRunning && in_array($row['id'], (array) $clusterQueue['queue'], true))
                                                <span class="text-gray-500">sırada</span>
                                            @elseif ($row['clusters'] === 0)
                                                <span class="text-warning-700 dark:text-warning-300">kümelenmedi</span>
                                            @elseif ($open > 0)
                                                <span class="text-warning-700 dark:text-warning-300">{{ number_format($open, 0, ',', '.') }} sorgu kümede değil</span>
                                            @else
                                                <span class="text-success-700 dark:text-success-400">kümelendi</span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap text-right">
                                            @if (! $rowRunning && $open > 0)
                                                <button type="button" wire:click="clusterOne({{ $row['id'] }})" class="{{ $ghost }}">{{ $row['clusters'] === 0 ? 'Kümele' : 'Yerleştir' }}</button>
                                            @endif
                                            <button type="button" wire:click="$set('service', '{{ $row['id'] }}')" class="{{ $ghost }}">Aç</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        @endforeach
                    </table></div>
                @endif
            @else
                @if ($unclustered > 0)
                    @php
                        $skip = (array) ($clusterStatus['skipped'] ?? []);
                        $skipServices = collect((array) ($skip['services'] ?? []))->sortDesc()->take(4)->map(fn ($n, $name) => $name.' '.number_format((int) $n, 0, ',', '.'))->implode(', ');
                    @endphp
                    <p class="text-[13px] text-warning-700 dark:text-warning-300" data-unclustered>Kümede olmayan sorgu: {{ number_format($unclustered, 0, ',', '.') }}@if (($clusterStatus['status'] ?? null) !== 'running' && array_sum(array_map('intval', array_intersect_key($skip, array_flip(['other_service', 'not_relevant', 'unprocessed'])))) > 0). Son kümelemede AI: başka hizmete ait {{ number_format((int) ($skip['other_service'] ?? 0), 0, ',', '.') }}@if ($skipServices !== '') ({{ $skipServices }})@endif, hizmetle ilgisiz {{ number_format((int) ($skip['not_relevant'] ?? 0), 0, ',', '.') }}, işlenemedi {{ number_format((int) ($skip['unprocessed'] ?? 0), 0, ',', '.') }}@endif</p>
                @endif
                <div class="{{ $tableWrap }}"><table class="data-table">
                    <thead><tr><th>Küme</th><th>Ana sorgu</th><th>Niyet</th><th>Sayfa tipi</th><th class="text-right">Talep</th><th class="text-right">Sorgu</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($clusters as $row)
                            <tr wire:key="c-{{ $row->id }}">
                                <td class="font-medium text-gray-900 dark:text-white">
                                    <button type="button" wire:click="openCluster({{ $row->id }})" class="text-left hover:underline">{{ $row->name }}</button>
                                    @if ($row->approved)<span class="ml-1.5 text-xs font-normal text-success-700 dark:text-success-400">onaylı</span>@endif
                                    @if ($row->locked)<span class="ml-1.5 text-xs font-normal text-gray-500">kilitli</span>@endif
                                </td>
                                <td class="text-[13px] text-gray-600 dark:text-gray-400">{{ $row->mainQuery?->text ?? '—' }}</td>
                                <td>{{ \App\Models\Cluster::INTENT_LABELS[$row->intent] ?? $row->intent }}</td>
                                <td>{{ \App\Models\Cluster::PAGE_TYPE_LABELS[$row->page_type] ?? $row->page_type }}</td>
                                <td class="text-right tabular-nums">{{ number_format((int) $row->demand, 0, ',', '.') }}</td>
                                <td class="text-right tabular-nums">{{ $row->cluster_queries_count }}</td>
                                <td class="text-right"><button type="button" wire:click="openCluster({{ $row->id }})" class="{{ $ghost }}">Aç</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-gray-500">Küme yok. "AI ile kümele" ile başlayın.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            @endif
        </section>

        @if ($openCluster)
            @php $members = $openCluster->clusterQueries->sortByDesc(fn ($link) => $link->searchQuery?->impressions ?? 0); $reps = array_map('intval', (array) $openCluster->representative_query_ids); @endphp
            {{-- Above the sticky header (z-99990) and the sidebar: the drawer's own buttons stay reachable. --}}
            <div class="fixed inset-0 z-[100000] flex justify-end" wire:keydown.escape.window="closeCluster">
            <div class="absolute inset-0 bg-gray-900/40" wire:click="closeCluster" aria-hidden="true"></div>
            <aside class="relative h-full w-full max-w-xl space-y-4 overflow-y-auto border-l border-gray-200 bg-white p-5 shadow-xl dark:border-gray-800 dark:bg-gray-900" data-cluster-drawer role="dialog" aria-label="Küme">
                <div class="flex items-center gap-2">
                    <input type="text" wire:model="clusterForm.name" aria-label="Küme adı" class="{{ $input }} flex-1">
                    <span class="text-xs text-gray-500">sürüm {{ $openCluster->version }}</span>
                    <button type="button" wire:click="closeCluster" aria-label="Kapat" class="btn-quiet h-8! w-8! px-0! text-lg">×</button>
                </div>
                @if ($this->message !== '')
                    <p role="status" class="tone-info rounded-lg px-3 py-2 text-[13px]" data-drawer-message>{{ $this->message }}</p>
                @endif
                @error('clusterForm.name')<p class="text-xs text-error-600">{{ $message }}</p>@enderror

                @if ($affectedBrands->isNotEmpty())
                    <label class="tone-look flex items-start gap-2 rounded-lg px-3 py-2 text-[13px]" data-shared-confirm>
                        <input type="checkbox" wire:model="confirmShared" class="mt-0.5 rounded border-gray-300">
                        <span>Ortak kütüphaneyi düzenle. Etkilenen markalar: {{ $affectedBrands->pluck('name')->implode(', ') }}</span>
                    </label>
                    @error('confirmShared')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
                @endif

                <div class="grid gap-3 text-[13px] text-gray-600 sm:grid-cols-2 dark:text-gray-400" data-cluster-form>
                    <label>Niyet
                        <select wire:model="clusterForm.intent" class="{{ $input }} mt-1 w-full">
                            @foreach (\App\Models\Cluster::INTENT_LABELS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                    </label>
                    <label>Sayfa tipi
                        <select wire:model="clusterForm.page_type" class="{{ $input }} mt-1 w-full">
                            @foreach (\App\Models\Cluster::PAGE_TYPE_LABELS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                    </label>
                    <label class="sm:col-span-2">Kullanıcı ihtiyacı
                        <input type="text" wire:model="clusterForm.user_need" class="{{ $input }} mt-1 w-full">
                    </label>
                    <label>Ana sorgu
                        <select wire:model="clusterForm.main_query_id" class="{{ $input }} mt-1 w-full">
                            <option value="">—</option>
                            @foreach ($members as $link)<option value="{{ $link->query_id }}">{{ $link->searchQuery?->text }}</option>@endforeach
                        </select>
                    </label>
                    <label>Temsil sorguları (en çok 3)
                        <select wire:model="clusterForm.representative_query_ids" multiple class="{{ $field }} mt-1 block w-full py-1">
                            @foreach ($members as $link)<option value="{{ $link->query_id }}">{{ $link->searchQuery?->text }}</option>@endforeach
                        </select>
                    </label>
                    <label>Alt konular (satır başına bir)
                        <textarea wire:model="clusterForm.subtopics" rows="3" class="{{ $field }} mt-1 block w-full py-2"></textarea>
                    </label>
                    <label>Dahil edilmeyecekler (satır başına bir)
                        <textarea wire:model="clusterForm.exclusions" rows="3" class="{{ $field }} mt-1 block w-full py-2"></textarea>
                    </label>
                </div>
                @foreach (['clusterForm.intent', 'clusterForm.page_type', 'clusterForm.user_need', 'clusterForm.main_query_id', 'clusterForm.representative_query_ids'] as $field)
                    @error($field)<p class="text-xs text-error-600">{{ $message }}</p>@enderror
                @endforeach
                @if ($openCluster->reasoning)<p class="text-xs text-gray-600 dark:text-gray-400">{{ $openCluster->reasoning }}</p>@endif
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="saveCluster" class="{{ $btn }}">Kaydet</button>
                    @unless ($openCluster->approved)<button type="button" wire:click="approveCluster" class="{{ $secondary }}">Onayla</button>@endunless
                    <button type="button" wire:click="deleteCluster" wire:confirm="Küme silinsin mi?" class="{{ $ghost }}">Sil</button>
                </div>

                <ul class="card">
                    @foreach ($members as $link)
                        <li wire:key="cq-{{ $link->id }}" class="card-row gap-2.5! py-2! text-[13px]">
                            <input type="checkbox" wire:model="selectedClusterQueries" value="{{ $link->query_id }}" aria-label="Seç" class="rounded border-gray-300 dark:border-gray-700">
                            <span class="flex-1">{{ $link->searchQuery?->text }}@if ($link->query_id === $openCluster->main_query_id)<span class="ml-1 text-gray-500">ana</span>@elseif (in_array($link->query_id, $reps, true))<span class="ml-1 text-gray-500">temsil</span>@endif</span>
                            @if ($link->is_suggested)<span class="text-xs text-live-700 dark:text-live-300">önerilen</span>
                            @else<span class="tabular-nums text-gray-500">{{ $num($link->searchQuery?->impressions ?? 0) }}</span>@endif
                        </li>
                    @endforeach
                </ul>
                @error('selectedClusterQueries')<p class="text-xs text-error-600">{{ $message }}</p>@enderror

                <div class="space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <input type="text" wire:model="addQueryText" wire:keydown.enter="addQueryToCluster" placeholder="Sorgu" aria-label="Eklenecek sorgu" class="{{ $input }}">
                        <button type="button" wire:click="addQueryToCluster" class="{{ $ghost }}">Sorgu ekle</button>
                        <button type="button" wire:click="removeClusterQueries" wire:confirm="Seçilen sorgular kümeden ve Sorgular'dan silinsin mi? Bir daha gelmez." class="{{ $ghost }}">Seçilenleri sil</button>
                        @error('addQueryText')<span class="text-xs text-error-600">{{ $message }}</span>@enderror
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <select wire:model="moveTarget" aria-label="Hedef küme" class="{{ $input }}">
                            <option value="">Hedef küme…</option>
                            @foreach ($clusterOptions as $id => $name)@if ($id !== $openCluster->id)<option value="{{ $id }}">{{ $name }}</option>@endif @endforeach
                        </select>
                        <button type="button" wire:click="moveQueries" class="{{ $ghost }}">Seçilenleri taşı</button>
                        @error('moveTarget')<span class="text-xs text-error-600">{{ $message }}</span>@enderror
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <input type="text" wire:model="splitName" placeholder="Yeni küme adı" aria-label="Yeni küme adı" class="{{ $input }}">
                        <button type="button" wire:click="splitCluster" class="{{ $ghost }}">Seçilenlerle ayır</button>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <select wire:model="mergeIds" multiple aria-label="Birleştirilecek kümeler" class="{{ $field }} min-w-0 flex-1 py-1">
                            @foreach ($clusterOptions as $id => $name)@if ($id !== $openCluster->id)<option value="{{ $id }}">{{ $name }}</option>@endif @endforeach
                        </select>
                        <button type="button" wire:click="mergeClusters" class="{{ $ghost }}">Bu kümeye birleştir</button>
                    </div>
                </div>

                @php
                    $scoreState = ['low_data' => 'veri az', 'no_gsc' => 'GSC bağlı değil', 'no_gsc_data' => 'GSC verisi yok', 'no_page' => '—'];
                    $nf = fn ($v): string => number_format((int) $v, 0, ',', '.');
                @endphp
                <div class="space-y-2 border-t border-gray-200 pt-4 text-[13px] dark:border-gray-800" data-cluster-site-pages>
                    <h3 class="section-title">Bu kümeye atanmış sayfalar <span class="section-count">tüm markalar</span></h3>
                    @if ($clusterSitePages === [])
                        <p class="text-gray-500">Henüz hiçbir markada bu kümeye sayfa atanmadı.</p>
                    @else
                        <div class="{{ $tableWrap }}"><table class="data-table text-[13px]!">
                            <thead><tr><th>Marka</th><th>URL</th><th class="text-right">Puan</th><th class="text-right">Ort. sıra</th><th class="text-right">Gösterim</th><th class="text-right">Tıklama</th><th class="text-right">Kapsam</th><th class="text-right">GA4</th></tr></thead>
                            <tbody>
                                @foreach ($clusterSitePages as $sp)
                                    @php $sc = $sp['score']; @endphp
                                    <tr>
                                        <td><a href="{{ route('operator.website', ['assetId' => $sp['site_id'], 'tab' => 'kumeler']) }}" wire:navigate class="hover:underline">{{ $sp['brand'] }}</a></td>
                                        <td class="max-w-[14rem] truncate"><a href="{{ $sp['url'] }}" target="_blank" rel="noopener" class="link-neutral">{{ preg_replace('#^https?://[^/]+#', '', $sp['url']) ?: '/' }}</a></td>
                                        <td class="text-right font-semibold tabular-nums">
                                            @if ($sc?->score !== null){{ $sc->score }}@if ($sc->previous_score !== null && $sc->previous_score != $sc->score) <span class="text-gray-500">{{ $sc->score > $sc->previous_score ? '↑' : '↓' }}</span>@endif
                                            @else<span class="font-normal text-gray-500">{{ $sc !== null ? ($scoreState[$sc->state] ?? $sc->state) : 'hesaplanmadı' }}</span>@endif
                                        </td>
                                        <td class="text-right tabular-nums">{{ $sc?->position !== null ? number_format((float) $sc->position, 1, ',', '.') : '—' }}</td>
                                        <td class="text-right tabular-nums">{{ $sc !== null ? $nf($sc->impressions) : '—' }}</td>
                                        <td class="text-right tabular-nums">{{ $sc !== null ? $nf($sc->clicks) : '—' }}</td>
                                        <td class="text-right tabular-nums">{{ $sc !== null && $sc->cluster_queries > 0 ? $sc->covered_queries.'/'.$sc->cluster_queries : '—' }}</td>
                                        <td class="text-right tabular-nums">{{ $sc?->ga4_sessions !== null ? $nf($sc->ga4_sessions).' / '.number_format((float) $sc->ga4_key_events, 0, ',', '.') : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table></div>
                        <p class="text-xs text-gray-500">Puan (1–100): son 90 günde yalnız bu kümenin sorgularında sıralama (%50), kapsam (%25) ve tıklama oranı (%25), her gece yenilenir. GA4: oturum / dönüşüm (bilgi, puana girmez).</p>
                    @endif
                </div>

                <div class="space-y-2 border-t border-gray-200 pt-4 text-[13px] dark:border-gray-800" data-content-ideas>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="section-title">İçerik fikirleri</h3>
                        <div class="flex items-center gap-2">
                            <select wire:model="ideaCount" aria-label="Fikir sayısı" class="{{ $input }}">
                                @foreach (range(1, \App\Services\Site\ContentIdeaPool::MAX_COUNT) as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach
                            </select>
                            <button type="button" wire:click="generateIdeas" wire:loading.attr="disabled" @disabled(($ideaStatus['status'] ?? null) === 'running') class="{{ $ghost }}">Yeni fikir üret</button>
                        </div>
                    </div>
                    <p class="text-xs text-gray-500">Ana fikir kümenin kendisidir. Buradakiler ayrı sayfa gerektiren ek fikirlerdir; havuz tüm markalar için ortaktır.</p>
                    @if (($ideaStatus['status'] ?? null) === 'running')
                        <p class="flex items-center gap-2 text-gray-600 dark:text-gray-300"><span class="live-dot" aria-hidden="true"></span>Fikirler üretiliyor…</p>
                    @elseif (($ideaStatus['status'] ?? null) === 'done')
                        <p>{{ $ideaStatus['added'] }} fikir havuza eklendi.</p>
                    @elseif (in_array($ideaStatus['status'] ?? null, ['error', 'no_provider'], true))
                        <p class="text-error-600">{{ $ideaStatus['status'] === 'no_provider' ? 'AI sağlayıcısı tanımlı değil.' : 'Fikir üretilemedi, tekrar deneyin.' }}</p>
                    @endif
                    @foreach ((array) ($ideaStatus['rejected'] ?? []) as $rej)
                        <p class="text-warning-700 dark:text-warning-400">Eklenmedi: {{ $rej['title'] }} ({{ $rej['reason'] }})</p>
                    @endforeach
                    @if ($contentIdeas->isEmpty())
                        <p class="text-gray-500">Henüz ek fikir yok.</p>
                    @else
                        <ul class="card">
                            @foreach ($contentIdeas as $idea)
                                <li wire:key="idea-{{ $idea->id }}" class="block space-y-1 border-t border-gray-200 px-4 py-3 first:border-t-0 dark:border-gray-800">
                                    <div class="flex items-start justify-between gap-2">
                                        <p><span class="font-medium text-gray-900 dark:text-white">{{ $idea->title }}</span> <span class="text-xs text-gray-500">{{ \App\Models\ContentIdea::TYPE_LABELS[$idea->type] ?? $idea->type }}</span></p>
                                        <button type="button" wire:click="archiveIdea({{ $idea->id }})" wire:confirm="Bu fikir arşivlensin mi?" class="btn-quiet h-7! px-2! text-xs! hover:text-error-700">Arşivle</button>
                                    </div>
                                    @if ($idea->angle)<p class="text-gray-600 dark:text-gray-400">{{ $idea->angle }}</p>@endif
                                    <p class="text-xs text-gray-500">Hedef: {{ collect($idea->target_queries)->map(fn ($q) => $q['text'].(($q['in_cluster'] ?? false) ? '' : ' (önerilen)'))->join(', ') }}</p>
                                    <p class="text-xs text-gray-500">Üreten: {{ $idea->originBrand?->name ?? 'Sorgular (genel)' }}. Kullanan markalar: {{ $idea->usages->pluck('brand.name')->filter()->unique()->join(', ') ?: '—' }}</p>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                @if ($affectedBrands->isNotEmpty())
                    <div class="space-y-2 border-t border-gray-200 pt-4 text-[13px] dark:border-gray-800" data-brand-edit>
                        <h3 class="section-title">Bu markaya özel düzenle</h3>
                        @foreach ($openCluster->brandPages as $bp)
                            <p wire:key="bp-{{ $bp->id }}" class="text-gray-600 dark:text-gray-400">{{ $bp->brand?->name }}@if ($bp->language) ({{ $bp->language }})@endif, {{ $bp->page?->path ?? 'sayfa yok' }}, {{ $bp->target_query ?? '—' }}@if ($bp->excluded) <span class="text-error-600">hariç</span>@endif</p>
                        @endforeach
                        <div class="flex flex-wrap items-center gap-2">
                            <select wire:model.live="brandId" aria-label="Marka" class="{{ $input }}">
                                <option value="">Marka…</option>
                                @foreach ($affectedBrands as $brand)<option value="{{ $brand->id }}">{{ $brand->name }}</option>@endforeach
                            </select>
                            @if ($brandId !== '')
                                <input type="text" wire:model="brandTarget" placeholder="Hedef sorgu (boş = otomatik)" aria-label="Hedef sorgu" class="{{ $input }}">
                                <select wire:model="brandPage" aria-label="Hedef URL" class="{{ $input }} max-w-[12rem]">
                                    <option value="">URL değişmesin</option>
                                    @foreach ($brandPages as $id => $path)<option value="{{ $id }}">{{ $path }}</option>@endforeach
                                </select>
                                <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="brandExcluded" class="rounded border-gray-300 dark:border-gray-700"> bu markada hariç</label>
                                <button type="button" wire:click="saveBrandCluster" class="{{ $ghost }}">Kaydet</button>
                            @endif
                        </div>
                        @foreach (['brandId', 'brandPage', 'target'] as $field)
                            @error($field)<p class="text-error-600">{{ $message }}</p>@enderror
                        @endforeach
                    </div>
                @endif
            </aside>
            </div>
        @endif
    @endif

    {{-- Filtre sepeti --}}
    @if ($tab === 'filters')
        <section class="{{ $card }}" data-section="filters">
            <div class="flex flex-wrap items-center gap-2">
                <input type="text" wire:model="termText" wire:keydown.enter="addTerm" placeholder="Kelime / ifade" aria-label="Terim" class="{{ $input }} w-full sm:w-60">
                <select wire:model="termSector" aria-label="Kapsam" class="{{ $input }}">
                    <option value="">Genel</option>
                    @foreach ($sectors as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
                <button type="button" wire:click="addTerm" class="{{ $btn }}">Ekle</button>
                @error('termText')<span class="text-xs text-error-600">{{ $message }}</span>@enderror
                <span class="text-[13px] text-gray-500 sm:ml-auto">{{ $num($terms->total()) }} terim</span>
            </div>
            <details data-bulk-terms>
                <summary class="cursor-pointer text-[13px] font-medium text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Toplu ekle</summary>
                <div class="mt-2 space-y-2">
                    <textarea wire:model="bulkTerms" rows="6" placeholder="Her satıra bir terim" aria-label="Toplu terimler" class="{{ $field }} block w-full py-2"></textarea>
                    <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500"><button type="button" wire:click="addBulkTerms" class="{{ $secondary }}">Toplu ekle</button> Yukarıdaki kapsam (Genel / sektör) kullanılır; var olan ve soru kelimesi içeren satırlar atlanır.</div>
                </div>
            </details>
            @php $filterStatus = $filterProposal['status'] ?? null; @endphp
            <div class="card space-y-3 p-4" data-filter-ai>
                <div class="flex flex-wrap items-start gap-2">
                    <textarea wire:model="filterInstruction" rows="2" maxlength="1000" placeholder="AI talimatınız (isteğe bağlı), örn. iş ilanı ve eğitim içerikli kelimeler üret" aria-label="AI talimatı" data-filter-instruction class="{{ $field }} min-w-0 flex-1 basis-64 py-2"></textarea>
                    <button type="button" wire:click="generateFilters" @disabled($filterStatus === 'running') class="{{ $secondary }}">AI ile oluştur</button>
                    <x-operator.ai-prompt-info operation="queries.plan_filters" />
                    <button type="button" wire:click="scanFilters" @disabled(($scan['status'] ?? null) === 'running') class="{{ $secondary }}" data-scan-start>Sorgularda tara</button>
                    <x-operator.ai-prompt-info operation="queries.scan_filters" />
                </div>
                <details class="text-[13px] text-gray-500"><summary class="{{ $how }}">Nasıl çalışır? <svg class="h-3 w-3" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m3 4.5 3 3 3-3"/></svg></summary><p class="mt-1.5 max-w-3xl leading-5">{{ ctype_digit($sector) ? 'Seçili sektör için' : 'Kullanılan her sektör için' }} ayrı çağrı; öneriler kaydedilmeden önce listelenir. <span class="font-medium">Sorgularda tara:</span> kütüphanedeki sorguların kelimelerinden marka / firma, kişi adı, semt ve alakasız kelimeleri bulur (hizmet kelimeleri, kendi marka adlarınız ve soru kelimeleri hiç önerilmez). İl / ilçe / ülke adı içeren sorgular zaten otomatik silinir; nedir / nasıl gibi soru kelimeleri filtreye eklenemez.</p></details>
                @php $scanStatus = $scan['status'] ?? null; @endphp
                @if ($scanStatus === 'running')
                    <p class="flex flex-wrap items-center gap-2 text-[13px] text-gray-500" data-scan-progress><span class="live-dot" aria-hidden="true"></span>{{ ! empty($scan['waiting']) ? 'Claude bekleniyor…' : 'Sorgular taranıyor…' }}@if (($scan['total'] ?? 0) > 0) {{ $scan['done'] }} / {{ $scan['total'] }} sektör tamamlandı @endif <button type="button" wire:click="stopScan" wire:confirm="Tarama durdurulsun mu?" class="btn h-7! px-2.5! text-xs!" data-scan-stop>Durdur</button></p>
                @elseif ($scanStatus === 'ready')
                    @php
                        $scanSkipLines = array_map('intval', $scanSkip);
                        $scanGroups = collect($scan['items'])->map(fn ($row, $i) => $row + ['index' => $i])->groupBy('category');
                    @endphp
                    <div class="border-t border-gray-200 pt-3 dark:border-gray-800" data-scan-result>
                        <h3 class="text-sm font-medium text-gray-900 dark:text-white" title="Seçilenler sepete eklenir, içeren sorgular Silinecekler'e düşer">Tarama sonucu <span class="font-normal text-gray-500">{{ count($scan['items']) }} kelime</span></h3>
                        @if (($scan['failed'] ?? []) !== [])<p class="text-xs text-error-600">Yanıt alınamayan sektörler: {{ implode(', ', $scan['failed']) }}</p>@endif
                        @foreach (\App\Services\Queries\FilterScanner::CATEGORIES as $category => $label)
                            @continue(! $scanGroups->has($category))
                            <div class="mt-3" data-scan-group="{{ $category }}">
                                <div class="flex flex-wrap items-center gap-2 text-[13px]">
                                    <span class="font-medium text-gray-900 dark:text-white">{{ $label }} <span class="font-normal text-gray-500">{{ $scanGroups[$category]->count() }}</span></span>
                                    <button type="button" wire:click="setScanCategory('{{ $category }}', true)" class="btn-quiet h-7! px-2! text-xs!">tümünü seç</button>
                                    <button type="button" wire:click="setScanCategory('{{ $category }}', false)" class="btn-quiet h-7! px-2! text-xs!">hiçbirini</button>
                                </div>
                                <ul class="mt-1 space-y-1">
                                    @foreach ($scanGroups[$category] as $row)
                                        <li wire:key="sc-{{ $row['index'] }}" class="flex items-start gap-2 text-[13px]"><input type="checkbox" wire:click="toggleScanLine({{ $row['index'] }})" @checked(! in_array((int) $row['index'], $scanSkipLines, true)) aria-label="Seç" class="mt-0.5 rounded border-gray-300 dark:border-gray-700">
                                            <span><span class="font-medium">{{ $row['term'] }}</span> <span class="text-gray-500">{{ $num($row['count']) }} sorgu, {{ $num($row['impressions']) }} gösterim, {{ $row['reason'] }}</span>
                                                @if ($row['examples'] !== [])<span class="block text-gray-500">örn. {{ implode(', ', $row['examples']) }}</span>@endif</span></li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                        @if ($scan['items'] === [])<p class="mt-1 text-xs text-gray-500">Filtrelenecek kelime bulunamadı.</p>@endif
                        <div class="mt-2 flex justify-end gap-2">
                            <button type="button" wire:click="closeScan" class="{{ $ghost }}">Vazgeç</button>
                            <button type="button" wire:click="approveScan" @disabled($scan['items'] === []) class="{{ $btn }}" data-scan-approve>Seçilenleri sepete ekle</button>
                        </div>
                    </div>
                @elseif ($scanStatus !== null)
                    <p class="text-xs text-error-600">{{ ['no_provider' => 'AI bağlı değil.', 'nothing' => 'Sektör yok.'][$scanStatus] ?? 'Tarama tamamlanamadı.' }}</p>
                @endif
                @if ($filterStatus === 'running')
                    <p class="flex flex-wrap items-center gap-2 text-[13px] text-gray-500" data-filter-progress><span class="live-dot" aria-hidden="true"></span>{{ ! empty($filterProposal['waiting']) ? 'Claude bekleniyor…' : 'AI çalışıyor…' }}@if (($filterProposal['total'] ?? 0) > 0) {{ $filterProposal['done'] }} / {{ $filterProposal['total'] }} sektör tamamlandı @endif <button type="button" wire:click="stopFilters" wire:confirm="AI adımı durdurulsun mu?" class="btn h-7! px-2.5! text-xs!" data-filter-stop>Durdur</button></p>
                @elseif ($filterStatus === 'ready')
                    @php $skipLines = array_map('intval', $filterSkip); @endphp
                    <div data-filter-proposal>
                        <h3 class="text-sm font-medium text-gray-900 dark:text-white">AI önerisi <span class="font-normal text-gray-500">{{ count($filterProposal['items']) }}</span></h3>
                        @if (($filterProposal['failed'] ?? []) !== [])<p class="text-xs text-error-600">Yanıt alınamayan sektörler: {{ implode(', ', $filterProposal['failed']) }}</p>@endif
                        <ul class="mt-1 space-y-1">
                            @forelse ($filterProposal['items'] as $i => $row)
                                <li wire:key="fp-{{ $i }}" class="flex items-start gap-2 text-[13px]"><input type="checkbox" wire:click="toggleFilterLine({{ $i }})" @checked(! in_array((int) $i, $skipLines, true)) aria-label="Seç" class="mt-0.5 rounded border-gray-300 dark:border-gray-700">
                                    <span><span class="font-medium">{{ $row['term'] }}</span> <span class="text-gray-500">{{ $row['sector'] }}, {{ $row['reason'] }}</span></span></li>
                            @empty
                                <li class="text-[13px] text-gray-500">Öneri yok.</li>
                            @endforelse
                        </ul>
                        <div class="mt-2 flex justify-end gap-2">
                            <button type="button" wire:click="closeFilterProposal" class="{{ $ghost }}">Vazgeç</button>
                            <button type="button" wire:click="approveFilterProposal" class="{{ $btn }}">Seçilenleri kaydet</button>
                        </div>
                    </div>
                @elseif ($filterStatus !== null && $filterStatus !== 'ready')
                    <p class="text-xs text-error-600">{{ ['no_provider' => 'AI bağlı değil.', 'nothing' => 'Sektör yok.'][$filterStatus] ?? 'Öneri alınamadı.' }}</p>
                @endif
            </div>
            <ul class="card" data-filter-terms>
                @forelse ($terms as $term)
                    <li wire:key="t-{{ $term->id }}" class="card-row py-2.5!">
                        <span class="min-w-0 flex-1 font-medium text-gray-900 dark:text-white">{{ $term->term }}</span>
                        <span class="text-[13px] text-gray-500">{{ $term->sector?->name ?? 'Genel' }}@if ($term->source === 'ai'), AI @endif</span>
                        <button type="button" wire:click="deleteTerm({{ $term->id }})" wire:confirm="Terim silinsin mi?" class="{{ $ghost }}">Sil</button>
                    </li>
                @empty
                    <li><x-ta.empty-state icon="none" size="sm" title="Sepet boş." /></li>
                @endforelse
            </ul>
            <div>{{ $terms->links() }}</div>
        </section>
    @endif

    {{-- Eşleme kelimeleri --}}
    @if ($tab === 'keywords')
        <section class="{{ $card }}" data-section="keywords">
            <div class="flex flex-wrap items-center gap-1">
                @foreach (\App\Livewire\Operator\Library\QueriesPage::KEYWORD_VIEWS as $view => $label)
                    <button type="button" wire:click="setKeywordView('{{ $view }}')" data-keyword-view="{{ $view }}" class="{{ $subTab($keywordView === $view) }}">{{ $label }}@if (($keywordCounts[$view] ?? 0) > 0) <span class="{{ $countPill }}">{{ $num($keywordCounts[$view]) }}</span>@endif</button>
                @endforeach
            </div>

            {{-- "Kelime ekle" panel: Kelime önerileri "Ekle" / Çakışmalar "Daha uzun kelime" --}}
            @if ($draftOpen)
                <div class="card space-y-2 p-4" data-keyword-draft>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="section-title mr-1">Kelime ekle</h2>
                        <input type="text" wire:model.live.debounce.400ms="draftKeyword" aria-label="Kelime" class="{{ $input }} w-full sm:w-60">
                        <select wire:model.live="draftService" aria-label="Hizmet" class="{{ $input }}">
                            <option value="">Hizmet…</option>
                            @foreach ($services as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        </select>
                        <button type="button" wire:click="saveDraft" @disabled(! ctype_digit($draftService) || ($impact['error'] ?? null)) class="{{ $btn }}">Kaydet</button>
                        <button type="button" wire:click="closeDraft" class="{{ $ghost }}">Vazgeç</button>
                    </div>
                    @error('draftKeyword')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
                    @if (($impact['source'] ?? null) === 'draft')
                        @include('livewire.operator.library.partials.keyword-impact', ['impact' => $impact])
                    @elseif (! ctype_digit($draftService))
                        <p class="text-xs text-gray-500">Etkisini görmek için hizmet seçin.</p>
                    @endif
                </div>
            @endif

            @if ($keywordView === 'words')
                @if ($keywordServices === null)
                    <div class="card"><x-ta.empty-state icon="none" size="sm" title="Sektör seçin." message="Yukarıdaki sektör seçimiyle başlayın." /></div>
                @else
                    <details class="text-[13px] text-gray-500"><summary class="{{ $how }}">Nasıl çalışır? <svg class="h-3 w-3" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m3 4.5 3 3 3-3"/></svg></summary><p class="mt-1.5 max-w-3xl leading-5">Yazdığınız kelimenin etkisi kaydetmeden önce gösterilir. İki hizmetin kelimesi aynı sorguda geçer ve biri diğerini içermezse sorgu atanmaz (Çakışmalar); içeriyorsa uzun olan kazanır.</p></details>
                    <ul class="card">
                        @forelse ($keywordServices as $item)
                            <li wire:key="ks-{{ $item->id }}" class="block border-t border-gray-200 px-4 py-3 first:border-t-0 dark:border-gray-800">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="w-full text-sm font-medium text-gray-900 sm:w-48 dark:text-white">{{ $item->primaryName->raw_label }}</span>
                                    @foreach ($item->matchingKeywords as $keyword)
                                        <span wire:key="kw-{{ $keyword->id }}" class="inline-flex items-center gap-1 rounded-md border border-gray-200 bg-gray-50 px-1.5 py-0.5 text-xs text-gray-700 dark:border-gray-700 dark:bg-white/[0.04] dark:text-gray-300">{{ $keyword->label }}<button type="button" wire:click="deleteKeyword({{ $keyword->id }})" aria-label="Sil" class="text-gray-400 hover:text-gray-900 dark:hover:text-white">×</button></span>
                                    @endforeach
                                    <input type="text" wire:model.live.debounce.500ms="newKeyword.{{ $item->id }}" wire:keydown.enter="addKeyword({{ $item->id }})" placeholder="Kelime" aria-label="Yeni kelime" class="{{ $input }} h-7 w-36">
                                    <button type="button" wire:click="addKeyword({{ $item->id }})" class="{{ $ghost }}">Ekle</button>
                                </div>
                                @error('newKeyword.'.$item->id)<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                                @if (($impact['source'] ?? null) === 'row' && ($impact['service'] ?? null) === $item->id && trim((string) ($newKeyword[$item->id] ?? '')) !== '')
                                    @include('livewire.operator.library.partials.keyword-impact', ['impact' => $impact])
                                @endif
                            </li>
                        @empty
                            <li><x-ta.empty-state icon="none" size="sm" title="Bu sektörde hizmet yok." /></li>
                        @endforelse
                    </ul>
                @endif
            @endif

            @if ($keywordView === 'suggestions')
                @if ($suggestions === null)
                    <div class="card"><x-ta.empty-state icon="none" size="sm" title="Sektör seçin." message="Yukarıdaki sektör seçimiyle başlayın." /></div>
                @else
                    <div class="flex flex-wrap items-center gap-2">
                        <details class="min-w-0 flex-1 basis-80 text-[13px] text-gray-500"><summary class="{{ $how }}">Nasıl çalışır? <svg class="h-3 w-3" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m3 4.5 3 3 3-3"/></svg></summary><p class="mt-1.5 max-w-3xl leading-5">Sektörün atanmamış sorgularında sık geçen 1–3 kelimelik ifadeler (gösterime göre); niyet kelimeleri (fiyat, nedir, en iyi, yorum…) ve şehir / ilçe adları hariç, sektörde zaten kelime olanlar hariç.</p></details>
                        <button type="button" wire:click="refreshSuggestions" class="btn">Yenile</button>
                    </div>
                    <div class="{{ $tableWrap }}"><table class="data-table" data-suggestions>
                        <thead><tr><th>İfade</th><th class="text-right">Sorgu</th><th class="text-right">Gösterim</th><th>Örnek sorgular</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($suggestions as $row)
                                @php $pickKey = str_replace(' ', '_', $row['ngram']); @endphp
                                <tr wire:key="sg-{{ $pickKey }}" data-suggestion="{{ $row['ngram'] }}">
                                    <td class="font-medium text-gray-900 dark:text-white">{{ $row['label'] }}</td>
                                    <td class="text-right tabular-nums">{{ $num($row['count']) }}</td>
                                    <td class="text-right tabular-nums">{{ $num($row['impressions']) }}</td>
                                    <td class="min-w-56 text-[13px] text-gray-500">{{ implode(', ', $row['examples']) }}</td>
                                    <td class="whitespace-nowrap text-right">
                                        <select wire:model="suggestPick.{{ $pickKey }}" aria-label="Hizmet" class="{{ $input }}">
                                            <option value="">Hizmet…</option>
                                            @foreach ($services as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                                        </select>
                                        <button type="button" wire:click="openSuggestion('{{ $row['ngram'] }}')" class="{{ $ghost }}">Ekle</button>
                                        <button type="button" wire:click="dismissSuggestion('{{ $row['ngram'] }}')" class="{{ $ghost }}">Yok say</button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-gray-500">Öneri yok.</td></tr>
                            @endforelse
                        </tbody>
                    </table></div>
                    @if ($suggestPages > 1)
                        <div class="flex items-center gap-2 text-xs">
                            <button type="button" wire:click="suggestPageTo({{ $suggestPage - 1 }})" @disabled($suggestPage === 0) class="{{ $ghost }}">‹</button>
                            <span class="text-gray-500">{{ min($suggestPage, $suggestPages - 1) + 1 }} / {{ $suggestPages }}</span>
                            <button type="button" wire:click="suggestPageTo({{ $suggestPage + 1 }})" @disabled($suggestPage + 1 >= $suggestPages) class="{{ $ghost }}">›</button>
                        </div>
                    @endif
                @endif
            @endif

            @if ($keywordView === 'conflicts')
                @if ($conflictRows === null)
                    <div class="card"><x-ta.empty-state icon="none" size="sm" title="Sektör seçin." message="Yukarıdaki sektör seçimiyle başlayın." /></div>
                @else
                    <details class="text-[13px] text-gray-500"><summary class="{{ $how }}">Nasıl çalışır? <svg class="h-3 w-3" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m3 4.5 3 3 3-3"/></svg></summary><p class="mt-1.5 max-w-3xl leading-5">İki (veya daha çok) hizmetin kelimesi geçen ve biri diğerini içermeyen sorgular otomatik atanmaz. Satırda hizmet seçin (elle, kilitli) veya daha uzun bir kelime ekleyin.</p></details>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="mr-1 text-[13px] text-gray-500" data-selection-count>{{ $num($conflictRows->total()) }} çakışma, seçili {{ count($selected) }}</span>
                        <button type="button" wire:click="selectPage" class="{{ $ghost }}">Sayfadaki tümünü seç</button>
                        @if ($selected !== [])<button type="button" wire:click="clearSelection" class="{{ $ghost }}">Seçimi temizle</button>@endif
                        <select wire:model="bulkService" aria-label="Atanacak hizmet" class="{{ $input }} sm:ml-auto">
                            <option value="">Hizmet…</option>
                            @foreach ($services as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        </select>
                        <button type="button" wire:click="assignConflicts" @disabled($selected === []) class="btn">Seçilenleri ata</button>
                    </div>
                    <div class="{{ $tableWrap }}"><table class="data-table" data-conflicts>
                        <thead><tr><th class="w-6 pr-0!"></th><th>Sorgu</th><th>Çakışan kelimeler</th><th>Mevcut</th><th class="text-right">Gösterim</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($conflictRows as $query)
                                @php $hits = $conflictKeywords[$query->id] ?? []; @endphp
                                <tr wire:key="cf-{{ $query->id }}">
                                    <td class="pr-0!"><input type="checkbox" wire:model.live="selected" value="{{ $query->id }}" aria-label="Seç"></td>
                                    <td class="font-medium text-gray-900 dark:text-white">{{ $query->text }}</td>
                                    <td class="text-[13px]" data-conflict-keywords>@foreach ($hits as $hit)<span class="block whitespace-nowrap text-warning-800 dark:text-warning-300"><span class="font-medium">{{ $hit['keyword'] }}</span>: {{ $conflictNames[$hit['service']] ?? '#'.$hit['service'] }}</span>@endforeach</td>
                                    <td>{{ $query->service?->primaryName?->raw_label ?? '—' }}</td>
                                    <td class="text-right tabular-nums">{{ $num($query->impressions) }}</td>
                                    <td class="whitespace-nowrap text-right">
                                        <select wire:model="conflictPick.{{ $query->id }}" aria-label="Hizmet" class="{{ $input }}">
                                            <option value="">Hizmet…</option>
                                            @foreach (collect($hits)->pluck('service')->unique() as $serviceId)<option value="{{ $serviceId }}">{{ $conflictNames[$serviceId] ?? '#'.$serviceId }}</option>@endforeach
                                        </select>
                                        <button type="button" wire:click="resolveConflict({{ $query->id }})" class="{{ $ghost }}">Ata</button>
                                        <button type="button" wire:click="openConflictKeyword({{ $query->id }})" class="{{ $ghost }}">Daha uzun kelime</button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-gray-500">Çakışma yok.</td></tr>
                            @endforelse
                        </tbody>
                    </table></div>
                    <div>{{ $conflictRows->links() }}</div>
                @endif
            @endif

            @if ($keywordView === 'sectors')
                <details class="text-[13px] text-gray-500"><summary class="{{ $how }}">Nasıl çalışır? <svg class="h-3 w-3" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m3 4.5 3 3 3-3"/></svg></summary><p class="mt-1.5 max-w-3xl leading-5">Hizmeti, sorgunun sektöründen farklı bir sektöre ait olan sorgular. "Taşı": sorgu hizmetin sektörüne geçer (kaynak hesaplar başka sektördeyse sonraki içe aktarma sektörü geri alabilir). "Hizmeti kaldır": sorgu atanmamış olur; sektörünün eşleme kelimeleri sonraki taramada yeniden atayabilir.</p></details>
                <div class="{{ $tableWrap }}"><table class="data-table" data-sector-mismatches>
                    <thead><tr><th>Sorgu sektörü</th><th>Hizmetin sektörü</th><th class="text-right">Sorgu</th><th>Örnekler</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($mismatches as $pair)
                            <tr wire:key="sm-{{ $pair['sector_id'] }}-{{ $pair['code'] }}">
                                <td class="font-medium text-gray-900 dark:text-white">{{ $pair['sector'] }}</td>
                                <td>{{ $pair['target'] }}</td>
                                <td class="text-right tabular-nums">{{ $num($pair['total']) }}</td>
                                <td class="min-w-56 text-[13px] text-gray-500">{{ implode(', ', $pair['examples']) }}</td>
                                <td class="whitespace-nowrap text-right">
                                    <button type="button" wire:click="moveMismatch({{ $pair['sector_id'] }}, '{{ $pair['code'] }}')" wire:confirm="Sorgular hizmetin sektörüne taşınsın mı?" @disabled($pair['target_id'] === null) class="{{ $ghost }}">Hizmetin sektörüne taşı</button>
                                    <button type="button" wire:click="clearMismatch({{ $pair['sector_id'] }}, '{{ $pair['code'] }}')" wire:confirm="Bu sorguların hizmeti kaldırılsın mı?" class="{{ $ghost }}">Hizmeti kaldır</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-gray-500">Sektör uyumsuzluğu yok.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            @endif
        </section>
    @endif

    {{-- Filtreye ekle --}}
    @if ($negOpen)
        <div class="fixed inset-0 z-[100000] flex items-center justify-center bg-gray-900/40 p-4" data-negative-modal role="dialog" aria-label="Filtreye ekle">
            <div class="max-h-[85vh] w-full max-w-2xl space-y-3 overflow-y-auto rounded-xl border border-gray-200 bg-white p-5 shadow-xl dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between">
                    <h2 class="section-title">Filtreye ekle <span class="section-count">içeren sorgu silinir</span></h2>
                    <button type="button" wire:click="closeNegatives" aria-label="Kapat" class="btn-quiet h-8! w-8! px-0! text-lg">×</button>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <select wire:model="negSector" aria-label="Sektör" class="{{ $input }}">
                        <option value="">Genel</option>
                        @foreach ($sectors as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    <button type="button" wire:click="aiNegatives" @disabled($negAwaiting) class="{{ $ghost }}">AI ile düzenle</button>
                    <x-operator.ai-prompt-info operation="queries.filter_rules" />
                    @if ($negAwaiting)<span class="text-xs text-gray-500">AI çalışıyor…</span>@endif
                </div>
                <textarea wire:model.blur="negText" rows="6" aria-label="Terimler (satır başına bir)" class="{{ $field }} block w-full py-2"></textarea>
                <ul class="space-y-1 text-[13px]" data-negative-catches>
                    @foreach ($negCatches as $term => $catch)
                        <li><span class="font-medium">{{ $term }}</span> <span class="text-gray-500">başka {{ $catch['count'] }} sorgu{{ $catch['examples'] !== [] ? ': '.implode(', ', $catch['examples']) : '' }}</span></li>
                    @endforeach
                </ul>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="closeNegatives" class="{{ $ghost }}">Vazgeç</button>
                    <button type="button" wire:click="saveNegatives" class="{{ $btn }}">Kaydet</button>
                </div>
            </div>
        </div>
    @endif

    {{-- AI ile kural üret --}}
    @if ($rulesOpen)
        <div class="fixed inset-0 z-[100000] flex items-center justify-center bg-gray-900/40 p-4" data-rules-modal role="dialog" aria-label="AI kural önerisi">
            <div class="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-xl border border-gray-200 bg-white p-5 shadow-xl dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between">
                    <h2 class="section-title">AI filtre kural önerisi</h2>
                    <button type="button" wire:click="closeRules" aria-label="Kapat" class="btn-quiet h-8! w-8! px-0! text-lg">×</button>
                </div>
                @php $status = $proposal['status'] ?? 'running'; @endphp
                @if ($status === 'running')
                    <p class="mt-3 text-gray-500">{{ ! empty($proposal['waiting']) ? 'Claude bekleniyor (kuyruk iş saatlerinde 2 saatte bir çalışır)…' : 'Hazırlanıyor…' }}</p>
                @elseif ($status !== 'ready')
                    <p class="mt-3 text-error-600">{{ ['no_provider' => 'AI bağlı değil.', 'no_queries' => 'Sorgu bulunamadı.'][$status] ?? 'Öneri alınamadı.' }}</p>
                @else
                    <h3 class="mt-4 text-sm font-medium text-gray-900 dark:text-white">Filtre sepeti <span class="font-normal text-gray-500">{{ count($proposal['terms']) }}</span></h3>
                    <ul class="mt-1 space-y-1">
                        @forelse ($proposal['terms'] as $i => $row)
                            <li class="flex items-start gap-2 text-[13px]"><input type="checkbox" wire:model="pickTerms.{{ $i }}" aria-label="Seç" class="mt-0.5 rounded border-gray-300 dark:border-gray-700">
                                <span><span class="font-medium">{{ $row['term'] }}</span> <span class="text-gray-500">{{ $row['sector'] ?? 'Genel' }}, {{ $row['reason'] }}</span></span></li>
                        @empty
                            <li class="text-[13px] text-gray-500">Öneri yok.</li>
                        @endforelse
                    </ul>
                    <h3 class="mt-4 text-sm font-medium text-gray-900 dark:text-white">Eşleme kelimeleri <span class="font-normal text-gray-500">{{ count($proposal['keywords']) }}</span></h3>
                    <ul class="mt-1 space-y-1">
                        @forelse ($proposal['keywords'] as $i => $row)
                            <li class="flex items-start gap-2 text-[13px]"><input type="checkbox" wire:model="pickKeywords.{{ $i }}" aria-label="Seç" class="mt-0.5 rounded border-gray-300 dark:border-gray-700">
                                <span><span class="font-medium">{{ $row['keyword'] }}</span>, {{ $row['service'] }} hizmetine <span class="block text-xs text-gray-500">{{ $row['reason'] }}</span></span></li>
                        @empty
                            <li class="text-[13px] text-gray-500">Öneri yok.</li>
                        @endforelse
                    </ul>
                    <div class="mt-4 flex justify-end gap-2">
                        <button type="button" wire:click="closeRules" class="{{ $ghost }}">Vazgeç</button>
                        <button type="button" wire:click="approveRules" class="{{ $btn }}">Seçilenleri kaydet ve tara</button>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Yasaklı ifadeler --}}
    @if ($tab === 'forbidden')
        <section class="{{ $card }} text-[13px]" data-section="forbidden">
            @if ($forbiddenRules === null)
                <p class="card p-4 leading-5 text-gray-500">Bir sektör seçin. Yasaklı ifadeler sektör paketi kuralları ve sektöre eklenenlerle tek kaynaktır; tüm içerik AI işlerine (fikir, SEO analizi, geliştir, üret), kayıttan önceki taramaya ve WordPress kapısına uygulanır. Markaya özel ifadeler Marka › Ayarlar'da. <span class="font-medium">{marka}</span> yazılan ifade her markada o markanın adı (ve alan adı) olur: yalnız <span class="font-medium">{marka}</span> eklemek, içerikte marka adının geçmesini engeller.</p>
            @else
                <div class="flex flex-wrap items-end gap-2">
                    <input type="text" wire:model="forbiddenPhrase" placeholder="İfade (ör. garantili sonuç, {marka})" aria-label="İfade" class="{{ $input }} w-full sm:w-56">
                    <input type="text" wire:model="forbiddenReason" placeholder="Neden" aria-label="Neden" class="{{ $input }} w-full min-w-0 sm:w-72">
                    <select wire:model="forbiddenSeverity" aria-label="Şiddet" class="{{ $input }}"><option value="high">engelle</option><option value="low">uyar</option></select>
                    <button type="button" wire:click="addForbidden" class="{{ $btn }}">Ekle</button>
                    <button type="button" wire:click="suggestForbidden" @disabled(($forbiddenSuggest['status'] ?? null) === 'running') class="{{ $ghost }}">AI ile öner</button>
                    <x-operator.ai-prompt-info operation="compliance.forbidden_terms" />
                    @error('forbiddenPhrase')<span class="text-error-600">{{ $message }}</span>@enderror
                </div>
                @if (($forbiddenSuggest['status'] ?? null) === 'running')<p class="flex items-center gap-2 text-gray-600 dark:text-gray-300"><span class="live-dot" aria-hidden="true"></span>AI aday ifadeler hazırlıyor…</p>
                @elseif (in_array($forbiddenSuggest['status'] ?? null, ['error', 'no_provider'], true))<p class="text-error-600">Öneri alınamadı.</p>@endif
                @if (! empty($forbiddenSuggest['items']))
                    <div class="card" data-forbidden-suggestions>
                        <p class="border-b border-gray-200 px-4 py-2.5 text-sm font-medium text-gray-900 dark:border-gray-800 dark:text-white">AI önerileri <span class="font-normal text-gray-500">tek tek onayla</span></p>
                        @foreach ($forbiddenSuggest['items'] as $i => $item)
                            <div wire:key="fs-{{ $i }}-{{ md5($item['phrase']) }}" class="card-row flex-wrap py-2.5! sm:flex-nowrap">
                                <span class="min-w-0 flex-1 basis-60"><span class="font-medium text-gray-900 dark:text-white">{{ $item['phrase'] }}</span> <span class="block text-xs text-gray-500">{{ $item['reason'] }}, {{ \App\Services\Compliance\ForbiddenTerms::SEVERITY_LABELS[$item['severity']] ?? $item['severity'] }}</span></span>
                                <button type="button" wire:click="decideForbidden({{ $i }}, true)" class="{{ $ghost }}">Onayla</button>
                                <button type="button" wire:click="decideForbidden({{ $i }}, false)" class="btn-quiet h-7! px-2! text-xs! hover:text-error-700">Geç</button>
                            </div>
                        @endforeach
                    </div>
                @endif
                <div class="{{ $tableWrap }}"><table class="data-table">
                    <thead><tr><th>İfade</th><th>Neden</th><th>Şiddet</th><th>Kaynak</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($forbiddenRules as $rule)
                            @php $own = str_starts_with((string) $rule->pack_id, \App\Services\Compliance\SectorPackRegistry::SECTOR_PREFIX); @endphp
                            <tr wire:key="fr-{{ $rule->id }}" @class(['opacity-50' => ! $rule->active])>
                                <td class="min-w-48 font-medium text-gray-900 dark:text-white">{{ implode(', ', array_slice((array) $rule->patterns, 0, 12)) }}{{ count((array) $rule->patterns) > 12 ? ' …' : '' }}</td>
                                <td class="min-w-56 text-[13px] text-gray-500">{{ $own ? $rule->message : $rule->label.': '.$rule->message }}</td>
                                <td>{{ \App\Services\Compliance\ForbiddenTerms::SEVERITY_LABELS[$rule->severity] ?? $rule->severity }}</td>
                                <td>{{ $own ? (['ai' => 'AI', 'operator' => 'elle'][$rule->origin] ?? $rule->origin) : 'sektör paketi' }}</td>
                                <td class="whitespace-nowrap text-right">
                                    <button type="button" wire:click="toggleForbidden({{ $rule->id }})" class="{{ $ghost }}">{{ $rule->active ? 'Kapat' : 'Aç' }}</button>
                                    @if ($own)<button type="button" wire:click="deleteForbidden({{ $rule->id }})" wire:confirm="Silinsin mi?" class="btn-quiet h-7! px-2! text-xs! hover:text-error-700">Sil</button>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-gray-500">Bu sektörde yasaklı ifade yok.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            @endif
        </section>
    @endif
</div>
