@php
    $card = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900';
    $btn = 'rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $btnSecondary = 'rounded-lg bg-white px-3 py-1.5 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700';
    $verdictStyle = [
        'none' => 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-300',
        'strengthen' => 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300',
        'new' => 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300',
        'merge' => 'bg-rose-50 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300',
    ];
    $articleStyle = [
        'writing' => 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300',
        'ready' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
        'needs_fix' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
        'failed' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
        'sent' => 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300',
        'exported' => 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300',
        'published' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
    ];
    $path = static fn (?string $url): string => $url ? (parse_url($url, PHP_URL_PATH) ?: $url) : '—';
    $tz = (string) config('moxdop-content.schedule.timezone', 'Europe/Istanbul');
@endphp
<div class="space-y-5" @if ($polling) wire:poll.4s @endif>
    <section class="{{ $card }} p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="max-w-3xl">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">İçerik Stüdyosu</h2>
                <p class="mt-1 text-sm text-gray-500">Markanın sorguları hizmetlerle birleşir, konu bütünlüğüne göre kümelenir; her küme için tek karar verilir. Eksik konulardan yazı fikirleri çıkar, seçtiklerini AI yazar, uyum kontrolünden geçer, tarih atanır ve WordPress’e taslak olarak gider ya da XML olarak indirilir.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($build)
                    <span class="text-xs text-gray-500">
                        @if ($build->isRunning()) Konu haritası yenileniyor…
                        @elseif ($build->status === 'failed') Son yenileme başarısız: {{ \Illuminate\Support\Str::limit((string) $build->error, 120) }}
                        @else Harita sürüm {{ $build->version }} · {{ $build->finished_at?->timezone($tz)->format('d.m.Y H:i') }}
                        @endif
                    </span>
                @endif
                <button type="button" wire:click="rebuildMap" class="{{ $btnSecondary }}" @disabled(! $served || ($build?->isRunning() ?? false))>Konu haritasını yenile</button>
            </div>
        </div>
        @if (! $served)
            <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">Bu site hizmet kapsamında değil (varlık, marka ya da müşteri pasif). Konu haritası ve yazı üretimi kapalı.</p>
        @endif
        @if ($message !== '')
            <p @class(['mt-3 rounded-lg px-3 py-2 text-sm', 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300' => $tone === 'success', 'bg-rose-50 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300' => $tone !== 'success'])>{{ $message }}</p>
        @endif
        @if ($writing > 0 && $batchTotal > 0)
            <div class="mt-3">
                <p class="text-xs text-gray-600 dark:text-gray-300">Yazılıyor: {{ $batchDone }} / {{ $batchTotal }} hazır · {{ $writing }} yazı sırada</p>
                <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/5"><div class="h-2 rounded-full bg-brand-500" style="width: {{ $batchTotal > 0 ? (int) round($batchDone / $batchTotal * 100) : 0 }}%"></div></div>
            </div>
        @endif
    </section>

    <nav class="flex flex-wrap items-center gap-2" aria-label="İçerik Stüdyosu">
        @foreach (['topics' => 'Konu haritası', 'ideas' => 'Konu fikirleri ('.$ideas->where('status', 'open')->count().')', 'articles' => 'Yazılar ('.$articles->count().')'] as $key => $label)
            <button type="button" wire:click="setView('{{ $key }}')" @class(['rounded-full px-3 py-1 text-sm ring-1 ring-inset', 'bg-brand-50 text-brand-700 ring-brand-300' => $view === $key, 'text-gray-600 ring-gray-300 dark:text-gray-300 dark:ring-gray-700' => $view !== $key])>{{ $label }}</button>
        @endforeach
        <select wire:model.live="serviceFilter" class="ml-auto {{ $input }}" aria-label="Hizmet">
            <option value="">Tüm hizmetler</option>
            @foreach ($services as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            <option value="none">Hizmetsiz</option>
        </select>
    </nav>

    {{-- ============================================================ TOPIC MAP --}}
    @if ($view === 'topics')
        <div class="flex flex-wrap items-center gap-2 text-xs">
            @foreach (['actionable' => 'Yapılacaklar', 'new' => 'Yeni içerik', 'strengthen' => 'Güçlendir', 'merge' => 'Birleştir', 'none' => 'Gerek yok', 'all' => 'Hepsi', 'skipped' => 'Atlananlar'] as $key => $label)
                <button type="button" wire:click="$set('verdictFilter', '{{ $key }}')" @class(['rounded-full px-3 py-1 ring-1 ring-inset', 'bg-gray-900 text-white ring-gray-900 dark:bg-white dark:text-gray-900' => $verdictFilter === $key, 'text-gray-600 ring-gray-300 dark:text-gray-300 dark:ring-gray-700' => $verdictFilter !== $key])>{{ $label }}@if (isset($verdictCounts[$key])) ({{ $verdictCounts[$key] }})@endif</button>
            @endforeach
            <button type="button" wire:click="proposeIdeas" class="ml-auto {{ $btn }}" @disabled(! $served)>Eksik konulardan fikir çıkar</button>
        </div>

        <section class="space-y-3">
            @forelse ($clusters as $cluster)
                @php $detail = (array) $cluster->verdict_detail; @endphp
                <article wire:key="cluster-{{ $cluster->id }}" class="{{ $card }} p-4" data-cluster="{{ $cluster->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5 text-xs">
                                <span class="rounded px-2 py-0.5 font-semibold {{ $verdictStyle[$cluster->verdict] ?? '' }}">{{ $cluster->verdictLabel() }}</span>
                                <span class="rounded bg-gray-100 px-2 py-0.5 text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ $services[$cluster->brand_offering_id] ?? 'Hizmetsiz' }}</span>
                                <span class="rounded bg-gray-100 px-2 py-0.5 text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ $cluster->intentLabel() }}</span>
                                <span class="rounded bg-gray-100 px-2 py-0.5 text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ $cluster->pageTypeLabel() }}</span>
                                <span class="text-gray-500">{{ $cluster->query_count }} sorgu · talep {{ number_format((float) $cluster->demand_score, 0, ',', '.') }}@if ($cluster->impressions > 0) · {{ number_format($cluster->impressions, 0, ',', '.') }} gösterim @endif</span>
                                @if ($cluster->status === 'skipped')<span class="rounded bg-gray-200 px-2 py-0.5 text-gray-600">Atlandı</span>@endif
                            </div>
                            @if ($renaming === $cluster->id)
                                <div class="mt-2 flex gap-2"><input type="text" wire:model="clusterLabel" class="{{ $input }} w-full max-w-md" aria-label="Küme adı"><button type="button" wire:click="saveRename" class="{{ $btn }}">Kaydet</button><button type="button" wire:click="$set('renaming', null)" class="{{ $btnSecondary }}">Vazgeç</button></div>
                            @else
                                <h3 class="mt-1.5 text-base font-semibold text-gray-900 dark:text-white">{{ $cluster->label }}</h3>
                            @endif
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $detail['reason'] ?? '' }}</p>
                            @if ($cluster->owner_url)
                                <p class="mt-1 text-xs text-gray-500">Sahip sayfa: <a href="{{ $cluster->owner_url }}" target="_blank" rel="noopener" class="text-brand-600 hover:underline">{{ $cluster->owner_title ?: $path($cluster->owner_url) }} ↗</a> · {{ $cluster->coverageLabel() }}@if ($cluster->owner_position) · ortalama sıra {{ number_format($cluster->owner_position, 1, ',', '') }}@endif</p>
                            @endif
                            @if ($cluster->verdict === 'new' && ! empty($cluster->similar_existing))
                                <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">Benzer mevcut yazı: {{ collect($cluster->similar_existing)->map(fn ($s) => ($s['title'] ?? '').' ('.$path($s['url'] ?? '').')')->implode(', ') }}</p>
                            @endif
                            @if ($cluster->verdict === 'strengthen' && ! empty($detail['missing_queries']))
                                <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">Eklenecek: {{ implode(', ', array_slice($detail['missing_queries'], 0, 6)) }}@if (! empty($detail['faq'])) · SSS: {{ implode(', ', array_slice($detail['faq'], 0, 3)) }}@endif</p>
                            @endif
                            @if ($cluster->verdict === 'merge' && ! empty($cluster->cannibal_urls))
                                <p class="mt-1 text-xs text-rose-700 dark:text-rose-300">Yarışan sayfalar: {{ collect($cluster->cannibal_urls)->map(fn ($c) => $path($c['url']).' (%'.(int) round(($c['share'] ?? 0) * 100).')')->implode(', ') }}</p>
                            @endif
                            <p class="mt-2 text-xs text-gray-500">{{ $cluster->queries->take(6)->pluck('query')->implode(' · ') }}@if ($cluster->queries->count() > 6) · …@endif</p>
                        </div>
                        <div class="flex shrink-0 flex-col gap-2">
                            @if ($cluster->verdict === 'new')
                                <button type="button" wire:click="ideaFromCluster({{ $cluster->id }})" class="{{ $btn }}" @disabled(! $served)>Fikir oluştur</button>
                            @elseif ($cluster->verdict === 'strengthen')
                                <button type="button" wire:click="prepareUpdate({{ $cluster->id }})" class="{{ $btn }}" @disabled(! $served)>Güncelleme taslağı hazırla</button>
                            @elseif ($cluster->verdict === 'merge')
                                <a wire:navigate href="{{ route('operator.website', ['assetId' => $site->id, 'tab' => 'scorecard']) }}" class="{{ $btn }} text-center">Sayfa ekranında incele</a>
                            @endif
                            <button type="button" wire:click="toggleCluster({{ $cluster->id }})" class="{{ $btnSecondary }}">{{ $openCluster === $cluster->id ? 'Kapat' : 'Sorgular ve düzenle' }}</button>
                        </div>
                    </div>

                    @if ($openCluster === $cluster->id)
                        <div class="mt-4 grid gap-4 border-t border-gray-100 pt-4 lg:grid-cols-3 dark:border-gray-800">
                            <div class="lg:col-span-2">
                                <table class="w-full text-left text-xs">
                                    <thead class="text-gray-500"><tr><th class="py-1 pr-2">Ayır</th><th class="py-1 pr-2">Sorgu</th><th class="py-1 pr-2">Gösterim</th><th class="py-1 pr-2">Sıra</th><th class="py-1">Başka kümeye taşı</th></tr></thead>
                                    <tbody class="text-gray-700 dark:text-gray-300">
                                        @foreach ($cluster->queries as $row)
                                            <tr wire:key="cq-{{ $row->id }}">
                                                <td class="py-1 pr-2"><input type="checkbox" wire:model="splitSelection.{{ $cluster->id }}.{{ $row->id }}" class="rounded border-gray-300" aria-label="Ayır"></td>
                                                <td class="py-1 pr-2">{{ $row->query }}@if ($row->pinned) <span class="text-gray-400" title="Operatör yerleştirdi">📌</span>@endif</td>
                                                <td class="py-1 pr-2">{{ number_format($row->impressions, 0, ',', '.') }}</td>
                                                <td class="py-1 pr-2">{{ $row->position !== null ? number_format($row->position, 1, ',', '') : '—' }}</td>
                                                <td class="py-1">
                                                    <div class="flex gap-1">
                                                        <select wire:model="moveTarget.{{ $row->id }}" class="rounded border-gray-300 py-0.5 text-xs dark:border-gray-700 dark:bg-gray-900" aria-label="Hedef küme">
                                                            <option value="">—</option>
                                                            @foreach ($allClusters->where('id', '!=', $cluster->id)->take(200) as $other)<option value="{{ $other->id }}">{{ \Illuminate\Support\Str::limit($other->label, 40) }}</option>@endforeach
                                                        </select>
                                                        <button type="button" wire:click="moveQuery({{ $row->id }})" class="text-brand-600 hover:underline">Taşı</button>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <input type="text" wire:model="splitLabel.{{ $cluster->id }}" placeholder="Yeni kümenin adı" class="{{ $input }} text-xs" aria-label="Yeni küme adı">
                                    <button type="button" wire:click="splitCluster({{ $cluster->id }})" class="{{ $btnSecondary }}">Seçilenleri ayrı küme yap</button>
                                </div>
                            </div>
                            <div class="space-y-2 text-xs">
                                <button type="button" wire:click="startRename({{ $cluster->id }})" class="{{ $btnSecondary }} w-full">Yeniden adlandır</button>
                                <button type="button" wire:click="skipCluster({{ $cluster->id }})" class="{{ $btnSecondary }} w-full">{{ $cluster->status === 'skipped' ? 'Haritaya geri al' : 'Atla (içerik gerekmez)' }}</button>
                                <div class="flex gap-1">
                                    <select wire:model="mergeTarget.{{ $cluster->id }}" class="w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" aria-label="Birleştirilecek küme">
                                        <option value="">Birleştirilecek küme…</option>
                                        @foreach ($allClusters->where('id', '!=', $cluster->id)->take(200) as $other)<option value="{{ $other->id }}">{{ \Illuminate\Support\Str::limit($other->label, 40) }}</option>@endforeach
                                    </select>
                                    <button type="button" wire:click="mergeCluster({{ $cluster->id }})" class="{{ $btnSecondary }}">Birleştir</button>
                                </div>
                                <p class="text-gray-400">Taşınan, ayrılan ve birleştirilen sorgular haftalık yenilemede yerinde kalır.</p>
                            </div>
                        </div>
                    @endif
                </article>
            @empty
                <p class="{{ $card }} p-5 text-sm text-gray-500">
                    @if ($build === null) Konu haritası henüz kurulmadı. Markanın sorgu merkezi dolu ise “Konu haritasını yenile” ile kur; haftalık yenileme de otomatik çalışır.
                    @else Bu filtrede küme yok.
                    @endif
                </p>
            @endforelse
        </section>
    @endif

    {{-- ============================================================ IDEAS --}}
    @if ($view === 'ideas')
        <section class="{{ $card }} p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Konu üret</h3>
            <p class="mt-1 text-xs text-gray-500">Eksenleri (hizmetleri) ve sayıyı seç. Önce konu haritasındaki karşılanmayan konular gelir; kalanı AI tek istekte önerir. Sitede yazılı başlıklar ve listedeki fikirler tekrar önerilmez.</p>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                @foreach ($services as $id => $name)
                    <label class="inline-flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model="axisOfferings.{{ $id }}" class="rounded border-gray-300"> {{ $name }}</label>
                @endforeach
                <label class="inline-flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300">Adet <input type="number" min="1" max="{{ (int) config('moxdop-content.ideas.max_per_request', 60) }}" wire:model="axisCount" class="{{ $input }} w-20"></label>
                <button type="button" wire:click="generateIdeas" class="{{ $btn }}" @disabled(! $served || $ideaState === 'running')>{{ $ideaState === 'running' ? 'Konular hazırlanıyor…' : 'Konu üret' }}</button>
                @if ($ideaEstimate)<span class="text-xs text-gray-400">AI tahmini: {{ $ideaEstimate }}</span>@endif
            </div>
            @if (is_string($ideaState) && $ideaState !== 'running')
                <p @class(['mt-2 text-xs', 'text-rose-600' => str_starts_with($ideaState, 'failed'), 'text-emerald-700' => ! str_starts_with($ideaState, 'failed')])>{{ \Illuminate\Support\Str::after(\Illuminate\Support\Str::after($ideaState, 'failed: '), 'done: ') }}</p>
            @endif
            <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                <input type="text" wire:model="newIdeaTitle" placeholder="Başlık yaz (ör. İmplant sonrası beslenme)" class="{{ $input }} w-full max-w-md" aria-label="Yeni fikir başlığı">
                <select wire:model="newIdeaOffering" class="{{ $input }}" aria-label="Fikrin hizmeti"><option value="">Hizmet (isteğe bağlı)</option>@foreach ($services as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                <button type="button" wire:click="addIdea" class="{{ $btnSecondary }}" @disabled(! $served)>Fikir ekle</button>
            </div>
        </section>

        <section class="{{ $card }}">
            <div class="flex flex-wrap items-center gap-3 border-b border-gray-100 px-5 py-3 dark:border-gray-800">
                <span class="text-sm text-gray-600 dark:text-gray-300">{{ $selectedCount }} konu seçili</span>
                @if ($languages['targets'] !== [])
                    <label class="inline-flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model.live="translate" class="rounded border-gray-300"> Diğer dillerde de hazırla ({{ implode(', ', array_column($languages['targets'], 'name')) }})</label>
                @endif
                <button type="button" wire:click="confirmBulk" class="ml-auto {{ $btn }}" @disabled(! $served || $selectedCount === 0)>Seçilenleri hazırla ({{ $selectedCount }})</button>
            </div>
            @if ($confirmingBulk)
                <div class="border-b border-gray-100 bg-brand-50/50 px-5 py-3 text-sm dark:border-gray-800 dark:bg-brand-500/10">
                    <p class="text-gray-800 dark:text-gray-200">{{ $selectedCount }} yazı AI ile yazılacak{{ $translate && $languages['targets'] !== [] ? ' ve '.count($languages['targets']).' dile yerelleştirilecek' : '' }}. Tahmini AI maliyeti: <strong>{{ $estimate['label'] ?? 'bilinmiyor' }}</strong>. Her yazı sektör uyum kontrolünden geçer; takılan yazı “uyum sorunu var” olarak kalır.</p>
                    <div class="mt-2 flex gap-2"><button type="button" wire:click="writeSelected" class="{{ $btn }}">Onayla ve hazırla</button><button type="button" wire:click="$set('confirmingBulk', false)" class="{{ $btnSecondary }}">Vazgeç</button></div>
                </div>
            @endif
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($ideas as $idea)
                    <li wire:key="idea-{{ $idea->id }}" @class(['px-5 py-4', 'bg-brand-50/60 dark:bg-brand-500/10' => $highlightIdea === $idea->id]) data-idea="{{ $idea->id }}">
                        <div class="flex items-start gap-3">
                            @if ($idea->status === 'open')
                                <input type="checkbox" wire:model.live="selectedIdeas.{{ $idea->id }}" class="mt-1 rounded border-gray-300" aria-label="Seç">
                            @endif
                            <div class="min-w-0 flex-1">
                                @if (isset($ideaEdits[$idea->id]))
                                    <div class="flex flex-wrap gap-2">
                                        <input type="text" wire:model="ideaEdits.{{ $idea->id }}.title" class="{{ $input }} w-full max-w-lg" aria-label="Başlık">
                                        <input type="text" wire:model="ideaEdits.{{ $idea->id }}.focus" class="{{ $input }} w-60" aria-label="Odak anahtar kelime">
                                        <button type="button" wire:click="saveIdea({{ $idea->id }})" class="{{ $btn }}">Kaydet</button>
                                    </div>
                                @else
                                    <p class="font-medium text-gray-900 dark:text-white">{{ $idea->title }}</p>
                                @endif
                                <p class="mt-1 text-xs text-gray-500">
                                    {{ \App\Models\TopicCluster::PAGE_TYPE_LABELS[$idea->page_type] ?? $idea->page_type }} · odak: “{{ $idea->focus_keyword }}” · {{ $services[$idea->brand_offering_id] ?? 'Hizmetsiz' }} · {{ $idea->sourceLabel() }}
                                    @if ($idea->target_url) · hedef adres: {{ $path($idea->target_url) }}@endif
                                    @if ($idea->status !== 'open') · <span class="font-medium">{{ ['writing' => 'yazılıyor', 'written' => 'yazıldı'][$idea->status] ?? $idea->status }}</span>@endif
                                </p>
                                @if (! empty($idea->similar_existing))
                                    <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">Benzer mevcut yazı: <a href="{{ $idea->similar_existing['url'] ?? '#' }}" target="_blank" rel="noopener" class="underline">{{ $idea->similar_existing['title'] ?? '' }}</a> (benzerlik %{{ (int) round(($idea->similar_existing['score'] ?? 0) * 100) }})</p>
                                @endif
                                <details class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                                    <summary class="cursor-pointer text-brand-600">Taslak plan, SSS ve iç bağlantılar</summary>
                                    <ul class="mt-1 list-disc pl-5">@foreach ((array) $idea->outline as $section)<li>{{ $section['h2'] ?? '' }}@if (! empty($section['h3'])) <span class="text-gray-400">({{ implode(' · ', $section['h3']) }})</span>@endif</li>@endforeach</ul>
                                    @if (! empty($idea->faq))<p class="mt-1">SSS: {{ implode(' · ', (array) $idea->faq) }}</p>@endif
                                    @if (! empty($idea->internal_links))<p class="mt-1">İç bağlantılar: @foreach ((array) $idea->internal_links as $link)<a href="{{ $link['url'] }}" target="_blank" rel="noopener" class="text-brand-600 hover:underline">{{ $link['title'] ?: $path($link['url']) }}</a>@if (! $loop->last), @endif @endforeach</p>@endif
                                    @if (! empty($idea->queries))<p class="mt-1 text-gray-400">Hedef sorgular: {{ implode(', ', (array) $idea->queries) }}</p>@endif
                                </details>
                            </div>
                            @if ($idea->status === 'open')
                                <div class="flex shrink-0 flex-col gap-1.5">
                                    <button type="button" wire:click="writeIdea({{ $idea->id }})" class="{{ $btn }}" @disabled(! $served)>Hazırla</button>
                                    <button type="button" wire:click="editIdea({{ $idea->id }})" class="{{ $btnSecondary }}">Düzenle</button>
                                    <button type="button" wire:click="removeIdea({{ $idea->id }})" class="{{ $btnSecondary }}">Kaldır</button>
                                </div>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="px-5 py-6 text-sm text-gray-500">Henüz fikir yok. “Konu haritası” sekmesinde “Eksik konulardan fikir çıkar”ı kullan ya da yukarıdan konu üret.</li>
                @endforelse
            </ul>
        </section>
    @endif

    {{-- ============================================================ ARTICLES --}}
    @if ($view === 'articles')
        <section class="{{ $card }} p-4">
            <div class="flex flex-wrap items-end gap-3 text-sm">
                <label class="flex flex-col text-xs text-gray-500">Başlangıç tarihi<input type="date" wire:model="scheduleStart" class="{{ $input }}"></label>
                <label class="flex flex-col text-xs text-gray-500">Saat<input type="time" wire:model="scheduleTime" class="{{ $input }}"></label>
                <label class="flex flex-col text-xs text-gray-500">Günde<input type="number" min="1" max="10" wire:model="schedulePerDay" class="{{ $input }} w-20"></label>
                <button type="button" wire:click="scheduleSelected" class="{{ $btnSecondary }}">Seçilenlere tarih ata</button>
                <span class="grow"></span>
                @if ($canWrite)
                    <button type="button" wire:click="publishSelected" wire:confirm="Seçilen yazılar (ve dil sürümleri) WordPress’e taslak olarak gönderilsin mi?" class="{{ $btn }}">WordPress’e taslak gönder</button>
                    @if ($exportUrl)
                        <a href="{{ $exportUrl }}" class="{{ $btnSecondary }}">XML indir</a>
                    @else
                        <span class="text-xs text-gray-400">XML için yazı seç</span>
                    @endif
                @else
                    <span class="text-xs text-gray-400">WordPress’e gönderme ve XML indirme yalnız Admin’dedir.</span>
                @endif
            </div>
            <p class="mt-2 text-xs text-gray-400">Tarihler taslağın yayın tarihi olarak gider; yazılar taslak kalır, yayınlamak WordPress’te sende. Uyum sorunu olan yazı gönderilmez ve indirilmez.</p>
        </section>

        <section class="{{ $card }}">
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($articles as $article)
                    @php $quality = (array) $article->quality; @endphp
                    <li wire:key="article-{{ $article->id }}" class="px-5 py-4" data-article="{{ $article->id }}">
                        <div class="flex items-start gap-3">
                            <input type="checkbox" wire:model.live="selectedArticles.{{ $article->id }}" class="mt-1 rounded border-gray-300" aria-label="Seç">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $articleStyle[$article->status] ?? '' }}">{{ $article->statusLabel() }}</span>
                                    <p class="font-medium text-gray-900 dark:text-white">{{ $article->title }}</p>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">
                                    @if ($article->language){{ strtoupper($article->language) }} · @endif
                                    @if ($article->word_count){{ $article->word_count }} kelime · @endif
                                    @if ($article->scheduled_at)tarih: {{ $article->scheduled_at->timezone($tz)->format('d.m.Y H:i') }} · @endif
                                    @if ($article->writeAction)WordPress: {{ $article->writeAction->statusLabel() }} · @endif
                                    @foreach ($article->translations as $translation)
                                        <span class="rounded bg-gray-100 px-1.5 dark:bg-white/5">{{ strtoupper((string) $translation->language) }}: {{ $translation->statusLabel() }}</span>
                                    @endforeach
                                </p>
                                @if ($article->error)<p class="mt-1 text-xs text-rose-600">{{ \Illuminate\Support\Str::limit($article->error, 200) }}</p>@endif
                                @if ($article->blockingViolations() !== [])
                                    <p class="mt-1 text-xs text-rose-700 dark:text-rose-300">Sektör uyum kuralına takılan ifadeler: {{ \App\Services\ContentDelivery\ContentComplianceGate::summary($article->blockingViolations()) }}</p>
                                @endif
                                @foreach ($article->translations as $translation)
                                    @if ($translation->blockingViolations() !== [])<p class="mt-1 text-xs text-rose-700 dark:text-rose-300">{{ strtoupper((string) $translation->language) }}: {{ \App\Services\ContentDelivery\ContentComplianceGate::summary($translation->blockingViolations()) }}</p>@endif
                                @endforeach
                                @if (! empty($quality['issues']))<p class="mt-1 text-xs text-amber-700 dark:text-amber-300">Kontrol: {{ implode(' ', $quality['issues']) }}</p>@endif

                                @if ($previewArticle === $article->id)
                                    <div class="mt-3 grid gap-3 lg:grid-cols-2">
                                        <div class="space-y-2">
                                            <label class="block text-xs text-gray-500">Başlık<input type="text" wire:model="articleEdit.title" class="{{ $input }} mt-1 w-full"></label>
                                            <label class="block text-xs text-gray-500">SEO başlığı (≤60)<input type="text" wire:model="articleEdit.meta_title" maxlength="70" class="{{ $input }} mt-1 w-full"></label>
                                            <label class="block text-xs text-gray-500">Meta açıklama (≤155)<textarea wire:model="articleEdit.meta_description" rows="2" class="{{ $input }} mt-1 w-full"></textarea></label>
                                            <label class="block text-xs text-gray-500">Metin (HTML)<textarea wire:model="articleEdit.html" rows="14" class="{{ $input }} mt-1 w-full font-mono text-xs"></textarea></label>
                                            <button type="button" wire:click="saveArticle({{ $article->id }})" class="{{ $btn }}">Kaydet ve uyumu kontrol et</button>
                                        </div>
                                        <div class="prose prose-sm max-w-none rounded-lg bg-gray-50 p-4 dark:prose-invert dark:bg-white/5">
                                            <h1>{{ data_get($article->payload, 'title') }}</h1>
                                            {!! \App\Services\SiteFixes\SiteFixAi::cleanHtml((string) data_get($article->payload, 'html', '')) !!}
                                        </div>
                                    </div>
                                @endif
                            </div>
                            <div class="flex shrink-0 flex-col gap-1.5">
                                @if ($article->hasDraft())
                                    <button type="button" wire:click="openArticle({{ $article->id }})" class="{{ $btnSecondary }}">{{ $previewArticle === $article->id ? 'Kapat' : 'Önizle / düzenle' }}</button>
                                @endif
                                @if (in_array($article->status, ['ready', 'needs_fix', 'failed'], true))
                                    <button type="button" wire:click="rewriteArticle({{ $article->id }})" class="{{ $btnSecondary }}">Yeniden yaz</button>
                                @endif
                                @if ($languages['targets'] !== [] && $article->hasDraft() && $article->translations->isEmpty())
                                    <button type="button" wire:click="translateArticle({{ $article->id }})" class="{{ $btnSecondary }}">Diğer dillerde hazırla</button>
                                @endif
                                @if ($canWrite && in_array($article->status, ['ready', 'exported', 'sent'], true))
                                    <button type="button" wire:click="publishArticle({{ $article->id }})" wire:confirm="Yazı WordPress’e taslak olarak gönderilsin mi?" class="{{ $btn }}">WordPress’e taslak gönder</button>
                                @endif
                            </div>
                        </div>
                    </li>
                @empty
                    <li class="px-5 py-6 text-sm text-gray-500">Henüz yazı yok. “Konu fikirleri” sekmesinde konu seçip “Hazırla” de.</li>
                @endforelse
            </ul>
        </section>
    @endif
</div>
