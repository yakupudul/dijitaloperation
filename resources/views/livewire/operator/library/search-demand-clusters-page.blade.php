<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-600">Arama talebi</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Sorgu Kümeleri</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">AI; talep ailesi, SERP niyeti ve içerik hedefi için açıklanabilir öneriler üretir. Kilit, taşıma, birleştirme ve ayırma kararları operatördedir.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('operator.library.search-queries') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Global sorgular</a>
            <a href="{{ route('operator.library.brand-query-portfolios', ['brand' => $selectedBrandId]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Marka portföyü</a>
            <a href="{{ route('operator.library.search-demand-visibility') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Görünürlük haritası</a>
            <a href="{{ route('operator.library.search-demand-enrichment') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">SERP zenginleştirme</a>
        </div>
    </div>

    @if ($message !== '')
        <div class="rounded-lg border px-4 py-3 text-sm {{ $messageTone === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' }}">{{ $message }}</div>
    @endif

    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="grid gap-4 lg:grid-cols-[1.2fr_1fr_auto] lg:items-end">
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-gray-500">Marka</span>
                <select wire:model.live="selectedBrandId" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                    <option value="">Marka seçin</option>
                    @foreach ($brands as $brandOption)
                        <option value="{{ $brandOption->id }}">{{ $brandOption->name }}</option>
                    @endforeach
                </select>
            </label>
            <div class="text-sm text-gray-500">
                @if ($brand)
                    <p><span class="font-medium text-gray-800 dark:text-gray-200">{{ $brand->name }}</span> · {{ $portfolioItems->count() }} etkin portföy sorgusu</p>
                    <p class="mt-1">{{ $clusters->count() }} etkin küme · {{ $unclusteredCount }} kümelenmemiş sorgu</p>
                @else
                    Kümeleri yönetmek için bir marka seçin.
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                <button wire:click="queueClustering('incremental')" wire:loading.attr="disabled" @disabled(! $brand || $unclusteredCount === 0) type="button" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">Yeni sorguları kümele</button>
                <button wire:click="queueClustering('review')" wire:loading.attr="disabled" @disabled(! $brand || $portfolioItems->isEmpty()) type="button" class="rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50 dark:bg-white dark:text-gray-900">Kümeleri gözden geçir</button>
            </div>
        </div>
        @error('clusteringMode') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
        <p class="mt-3 text-xs text-gray-400">AI ile onaylanan yeni kümeler <code>ai_prediction</code> başlar. Faz 7 SERP kanıtı ayrı ücretli çalıştırılır ve doğrulama önerisi yine insan onayı olmadan uygulanmaz.</p>
    </section>

    @if ($brand)
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([['Etkin küme', $clusters->count()], ['Kümelenmiş sorgu', $clusters->sum('memberships_count')], ['Kümelenmemiş', $unclusteredCount], ['Kilitli küme', $clusters->where('is_locked', true)->count()]] as [$label, $value])
                <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><p class="text-xs text-gray-500">{{ $label }}</p><p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($value, 0, ',', '.') }}</p></div>
            @endforeach
        </div>

        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h2 class="text-base font-semibold text-gray-900 dark:text-white">AI kümeleme çalışmaları</h2><p class="mt-1 text-sm text-gray-500">Agent, Skill ve model parmak izi değişmedikçe aynı girdi için tamamlanmış sonuç yeniden kullanılır.</p></div>
                <select wire:change="openClusteringRun($event.target.value)" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                    <option value="">Son çalışmayı açın</option>
                    @foreach ($clusteringRuns as $runOption)
                        <option value="{{ $runOption->id }}" @selected($clusteringRun?->id === $runOption->id)>#{{ $runOption->id }} · {{ $runOption->mode }} · {{ $runOption->status }}</option>
                    @endforeach
                </select>
            </div>

            @if ($clusteringRun)
                <div @if(in_array($clusteringRun->status, ['queued', 'running'], true)) wire:poll.4s="refreshClusteringRun" @endif class="mt-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <div><span class="font-semibold text-gray-900 dark:text-white">Çalışma #{{ $clusteringRun->id }}</span> · {{ $clusteringRun->mode }} · <span class="font-medium">{{ $clusteringRun->status }}</span></div>
                        <div class="text-xs text-gray-500">{{ $clusteringRun->provider ?: 'sağlayıcı bekleniyor' }} / {{ $clusteringRun->model ?: 'model bekleniyor' }}</div>
                    </div>
                    @if ($clusteringRun->abstained)
                        <p class="mt-3 rounded bg-amber-50 px-3 py-2 text-xs text-amber-800">Agent çekimser kaldı: {{ $clusteringRun->abstention_reason ?: 'Gerekçe belirtilmedi.' }}</p>
                    @endif
                    @if ($clusteringRun->status === 'failed')
                        <p class="mt-3 rounded bg-red-50 px-3 py-2 text-xs text-red-800">{{ $clusteringRun->error_code }} · {{ $clusteringRun->error_summary }}</p>
                    @endif

                    @if ($clusteringRun->candidates->isNotEmpty())
                        <div class="mt-4 flex flex-wrap gap-2">
                            <button wire:click="selectPendingCandidates" type="button" class="rounded-lg border border-gray-200 px-3 py-2 text-xs font-medium text-gray-700 dark:border-gray-700 dark:text-gray-200">Bekleyenleri seç</button>
                            <button wire:click="reviewCandidates('approve')" wire:loading.attr="disabled" type="button" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Seçilenleri onayla</button>
                            <button wire:click="reviewCandidates('reject')" wire:loading.attr="disabled" type="button" class="rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Seçilenleri reddet</button>
                        </div>
                        <div class="mt-3 space-y-3">
                            @foreach ($clusteringRun->candidates as $candidate)
                                <article class="rounded-lg border p-4 {{ $candidate->uncertain ? 'border-amber-200 bg-amber-50/40' : 'border-gray-200 dark:border-gray-700' }}">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <label class="flex items-start gap-3">
                                            <input wire:model="selectedCandidateIds" value="{{ $candidate->id }}" type="checkbox" @disabled($candidate->status !== 'pending') class="mt-1 rounded border-gray-300 text-brand-500" />
                                            <span><span class="font-medium text-gray-900 dark:text-white">{{ str_replace('_', ' ', $candidate->action_type) }}</span><span class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-600 dark:bg-gray-800">{{ $candidate->status }}</span></span>
                                        </label>
                                        <div class="text-xs text-gray-500">{{ count($candidate->member_portfolio_item_ids ?? []) }} sorgu · güven {{ $candidate->confidence === null ? '—' : $candidate->confidence.'%' }}</div>
                                    </div>
                                    @if ($candidate->uncertain)<p class="mt-2 text-xs text-amber-800">Belirsiz: {{ $candidate->uncertainty_reason ?: 'Açıklama verilmedi.' }}</p>@endif
                                    @if ($candidate->rationale)<p class="mt-2 text-xs text-gray-500">{{ $candidate->rationale }}</p>@endif
                                    @if ($candidate->status === 'pending')
                                        <div class="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-5">
                                            <input wire:model="candidateEdits.{{ $candidate->id }}.cluster_name" type="text" placeholder="Küme adı" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950" />
                                            <input wire:model="candidateEdits.{{ $candidate->id }}.demand_family" type="text" placeholder="Talep ailesi" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950" />
                                            <input wire:model="candidateEdits.{{ $candidate->id }}.serp_intent_group" type="text" placeholder="SERP niyet grubu" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950" />
                                            <input wire:model="candidateEdits.{{ $candidate->id }}.content_target_cluster" type="text" placeholder="İçerik hedef kümesi" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950" />
                                            <input wire:model="candidateEdits.{{ $candidate->id }}.suggested_content_type" type="text" placeholder="İçerik tipi" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950" />
                                        </div>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    @elseif($clusteringRun->status === 'completed')
                        <p class="mt-4 text-sm text-gray-500">Bu çalışma uygulanabilir bir küme önerisi üretmedi.</p>
                    @else
                        <p class="mt-4 text-sm text-gray-500">Öneriler hazırlanıyor; bu panel otomatik yenilenir.</p>
                    @endif
                </div>
            @endif
        </section>

        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">İnsan kontrollü düzenleme</h2>
            <div class="mt-4 grid gap-5 xl:grid-cols-3">
                <form wire:submit="moveQuery" class="space-y-2">
                    <h3 class="text-sm font-medium text-gray-800 dark:text-gray-200">Sorgu taşı</h3>
                    <select wire:model="movePortfolioItemId" class="w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950"><option value="">Sorgu seçin</option>@foreach($portfolioItems as $item)<option value="{{ $item->id }}">{{ $item->effectiveQueryText() }}</option>@endforeach</select>
                    <select wire:model="moveTargetClusterId" class="w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950"><option value="">Hedef küme</option>@foreach($clusters as $cluster)<option value="{{ $cluster->id }}">#{{ $cluster->id }} · {{ $cluster->name }}{{ $cluster->is_locked ? ' (kilitli)' : '' }}</option>@endforeach</select>
                    <button type="submit" class="rounded-lg bg-sky-600 px-3 py-2 text-xs font-semibold text-white">Sorguyu taşı</button>
                </form>
                <div class="space-y-2">
                    <h3 class="text-sm font-medium text-gray-800 dark:text-gray-200">Kümeleri birleştir</h3>
                    <p class="text-xs text-gray-500">Aşağıdaki listeden en az iki kilitsiz küme seçin. En düşük ID hedef olur.</p>
                    <button wire:click="mergeSelectedClusters" type="button" class="rounded-lg bg-violet-600 px-3 py-2 text-xs font-semibold text-white">Seçili kümeleri birleştir</button>
                    @error('selectedClusterIds') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <form wire:submit="splitCluster" class="space-y-2">
                    <h3 class="text-sm font-medium text-gray-800 dark:text-gray-200">Kümeyi ayır</h3>
                    <select wire:model.live="splitSourceClusterId" class="w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950"><option value="">Kaynak küme</option>@foreach($clusters as $cluster)<option value="{{ $cluster->id }}">#{{ $cluster->id }} · {{ $cluster->name }}{{ $cluster->is_locked ? ' (kilitli)' : '' }}</option>@endforeach</select>
                    @if ($splitSource)
                        <div class="max-h-36 space-y-1 overflow-y-auto rounded-lg border border-gray-200 p-2 dark:border-gray-700">@foreach($splitSource->memberships as $membership)<label class="flex gap-2 text-xs"><input wire:model="splitMemberIds" value="{{ $membership->brand_query_portfolio_item_id }}" type="checkbox" /> {{ $membership->portfolioItem?->effectiveQueryText() }}</label>@endforeach</div>
                    @endif
                    <input wire:model="splitClusterName" type="text" placeholder="Yeni küme adı" class="w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950" />
                    <button type="submit" class="rounded-lg bg-amber-600 px-3 py-2 text-xs font-semibold text-white">Seçilenleri ayır</button>
                </form>
            </div>
        </section>

        <section class="space-y-3">
            @forelse ($clusters as $cluster)
                <article class="rounded-xl bg-white p-5 ring-1 ring-inset {{ $cluster->is_locked ? 'ring-amber-300' : 'ring-gray-200' }} dark:bg-gray-900 dark:ring-gray-800">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <label class="flex items-start gap-3">
                            <input wire:model="selectedClusterIds" value="{{ $cluster->id }}" type="checkbox" @disabled($cluster->is_locked) class="mt-1 rounded border-gray-300 text-brand-500" />
                            <span><span class="font-semibold text-gray-900 dark:text-white">#{{ $cluster->id }} · {{ $cluster->name }}</span><span class="mt-1 block text-xs text-gray-400">{{ $cluster->cluster_key }} · v{{ $cluster->version }}</span></span>
                        </label>
                        <div class="flex items-center gap-2">
                            <span class="rounded px-2 py-1 text-[10px] font-medium {{ $cluster->validation_status === 'serp_validated' ? 'bg-emerald-50 text-emerald-700' : ($cluster->validation_status === 'serp_conflict' ? 'bg-red-50 text-red-700' : 'bg-violet-50 text-violet-700') }}">{{ $cluster->validation_status }}</span>
                            <button wire:click="toggleClusterLock({{ $cluster->id }})" type="button" class="rounded-lg border px-3 py-1.5 text-xs font-medium {{ $cluster->is_locked ? 'border-amber-200 text-amber-700' : 'border-gray-200 text-gray-600 dark:border-gray-700' }}">{{ $cluster->is_locked ? 'Kilidi aç' : 'Kilitle' }}</button>
                        </div>
                    </div>
                    <div class="mt-4 grid gap-3 text-xs sm:grid-cols-2 xl:grid-cols-5">
                        <div><span class="block text-gray-400">Talep ailesi</span><span class="font-medium text-gray-700 dark:text-gray-200">{{ $cluster->demand_family ?: '—' }}</span></div>
                        <div><span class="block text-gray-400">SERP niyet grubu</span><span class="font-medium text-gray-700 dark:text-gray-200">{{ $cluster->serp_intent_group ?: '—' }}</span></div>
                        <div><span class="block text-gray-400">İçerik hedefi</span><span class="font-medium text-gray-700 dark:text-gray-200">{{ $cluster->content_target_cluster ?: '—' }}</span></div>
                        <div><span class="block text-gray-400">Önerilen içerik</span><span class="font-medium text-gray-700 dark:text-gray-200">{{ $cluster->suggested_content_type ?: '—' }}</span></div>
                        <div><span class="block text-gray-400">Güven</span><span class="font-medium text-gray-700 dark:text-gray-200">{{ $cluster->confidence === null ? '—' : $cluster->confidence.'%' }}</span></div>
                    </div>
                    <div class="mt-4 grid gap-4 lg:grid-cols-[1.5fr_1fr]">
                        <div><p class="text-xs font-medium text-gray-500">Sorgular ({{ $cluster->memberships_count }})</p><div class="mt-2 flex flex-wrap gap-1.5">@foreach($cluster->memberships as $membership)<span class="rounded bg-gray-100 px-2 py-1 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ $membership->portfolioItem?->effectiveQueryText() }}</span>@endforeach</div></div>
                        <div><p class="text-xs font-medium text-gray-500">Temsilci ve sürüm geçmişi</p><p class="mt-2 text-xs text-gray-700 dark:text-gray-200">{{ $cluster->representativeItem?->effectiveQueryText() ?: 'Temsilci sorgu yok' }}</p><div class="mt-2 space-y-1 text-[10px] text-gray-400">@forelse($cluster->versions as $version)<div>v{{ $version->version }} · {{ $version->change_type }} · {{ $version->created_at?->format('d.m.Y H:i') }}</div>@empty<div>Henüz sürüm kaydı yok.</div>@endforelse</div></div>
                    </div>
                    @if ($cluster->rationale)<p class="mt-3 border-t border-gray-100 pt-3 text-xs text-gray-500 dark:border-gray-800">{{ $cluster->rationale }}</p>@endif
                </article>
            @empty
                <div class="rounded-xl bg-white px-5 py-10 text-center text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">Bu marka için henüz etkin sorgu kümesi yok.</div>
            @endforelse
        </section>
    @endif
</div>
