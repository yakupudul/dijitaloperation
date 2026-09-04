@php
    $number = static fn ($value, int $decimals = 0): string => $value === null ? '—' : number_format((float) $value, $decimals, ',', '.');
    $percent = static fn ($value): string => $value === null ? '—' : number_format((float) $value * 100, 1, ',', '.').'%';
    $statusLabels = [
        'unassigned' => 'Atanmamış', 'verified_owner' => 'Doğrulanmış sahip', 'ai_suggested' => 'AI tarafından önerildi',
        'wrong_url_candidate' => 'Yanlış URL adayı', 'multiple_urls' => 'Birden fazla URL',
        'no_suitable_url' => 'Uygun URL yok', 'review_required' => 'İnceleme gerekli', 'excluded' => 'Hariç tutuldu',
        'recommend_owner' => 'URL sahibi önerildi',
    ];
    $contentLabels = [
        'improve_existing' => 'Mevcut URL’yi geliştir', 'new_service_page' => 'Yeni hizmet sayfası',
        'blog' => 'Blog', 'faq' => 'SSS', 'merge_review' => 'Birleştirme incelemesi', 'none' => 'İşlem önerisi yok',
    ];
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-600">Arama talebi · Faz 8</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">URL Sahipliği ve Page Relevance</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Bir içerik kümesi için teknik olarak uygun Website URL’lerini GSC, SERP ve sayfa bağlamıyla karşılaştırır. AI yalnız öneri üretir; sahiplik ancak insan onayıyla kaydedilir.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('operator.library.search-demand-clusters', ['brand' => $website?->brand_id]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Sorgu kümeleri</a>
            <a href="{{ route('operator.library.search-demand-visibility', ['website' => $website?->id]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Görünürlük haritası</a>
            <a href="{{ route('operator.library.search-demand-enrichment', ['website' => $website?->id]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">SERP zenginleştirme</a>
        </div>
    </div>

    @if($message !== '')<div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $message }}</div>@endif

    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="grid gap-3 lg:grid-cols-6">
            <label class="block lg:col-span-2"><span class="mb-1 block text-xs font-medium text-gray-500">Website</span><select wire:model.live="selectedWebsiteId" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Website seçin</option>@foreach($websites as $option)<option value="{{ $option->id }}">{{ $option->name }} · {{ $option->brand?->name }}</option>@endforeach</select></label>
            <label class="block lg:col-span-2"><span class="mb-1 block text-xs font-medium text-gray-500">İçerik hedef kümesi</span><select wire:model.live="clusterId" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Küme seçin</option>@foreach($clusters as $cluster)<option value="{{ $cluster->id }}">{{ $cluster->name }} · {{ $cluster->memberships_count }} sorgu</option>@endforeach</select></label>
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Dönem başlangıcı</span><input wire:model="periodStart" type="date" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" /></label>
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Dönem sonu</span><input wire:model="periodEnd" type="date" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" /></label>
            <label class="block lg:col-start-5"><span class="mb-1 block text-xs font-medium text-gray-500">Karşılaştırma başlangıcı</span><input wire:model="comparisonStart" type="date" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" /></label>
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Karşılaştırma sonu</span><input wire:model="comparisonEnd" type="date" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" /></label>
        </div>
        <p class="mt-3 text-xs text-gray-500">Teknik kapı: aynı Website, public gözlem, başarılı HTTP, indexlenebilirlik, başka URL’ye canonical olmama, doğru dil ve içerik URL türü. Eksik kanıt “uygun” sayılmaz.</p>
        <button wire:click="queueReview" wire:loading.attr="disabled" type="button" class="mt-4 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">URL adaylarını incele</button>
        @error('clusterId')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
    </section>

    @if($website && $clusterId !== '')
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div><h2 class="text-base font-semibold text-gray-900 dark:text-white">Geçerli insan kararı</h2><p class="mt-1 text-sm text-gray-500">Kilitli karar, yeni AI önerilerinden bağımsız olarak otorite olmaya devam eder.</p></div>
                @if($ownership)<span class="rounded-full px-3 py-1 text-xs font-semibold {{ $ownership->is_locked ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-700' }}">{{ $ownership->is_locked ? 'Kilitli' : 'Açık' }}</span>@endif
            </div>
            @if(!$ownership)
                <p class="mt-4 text-sm text-gray-500">Bu küme için henüz URL sahipliği kararı yok.</p>
            @else
                <div class="mt-4 grid gap-3 md:grid-cols-3"><div><p class="text-xs text-gray-500">Durum</p><p class="font-semibold">{{ $statusLabels[$ownership->status] ?? $ownership->status }}</p></div><div class="md:col-span-2"><p class="text-xs text-gray-500">Hedef URL</p><p class="break-all font-medium">{{ $ownership->target_url ?: '—' }}</p></div></div>
                @if($ownership->rationale)<p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ $ownership->rationale }}</p>@endif
                @if($ownership->status === 'verified_owner')<button wire:click="toggleLock" type="button" class="mt-3 rounded-lg px-3 py-2 text-xs font-semibold text-brand-600 ring-1 ring-inset ring-brand-200">{{ $ownership->is_locked ? 'Kilidi aç' : 'Kilitle' }}</button>@endif
            @endif
            @if(!$ownership?->is_locked)
                <div class="mt-5 border-t border-gray-100 pt-4 dark:border-gray-800"><label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Karar notu</span><textarea wire:model="decisionNote" rows="2" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" placeholder="Neden uygun URL yok, neden hariç veya neden insan incelemesi gerekli?"></textarea></label><div class="mt-2 flex flex-wrap gap-2"><button wire:click="setDecision('no_suitable_url')" class="rounded bg-gray-800 px-3 py-2 text-xs font-semibold text-white">Uygun URL yok</button><button wire:click="setDecision('review_required')" class="rounded bg-amber-600 px-3 py-2 text-xs font-semibold text-white">İnceleme gerekli</button><button wire:click="setDecision('excluded')" class="rounded bg-red-600 px-3 py-2 text-xs font-semibold text-white">Hariç tut</button></div></div>
            @endif
        </section>

        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-base font-semibold text-gray-900 dark:text-white">Page Relevance çalışmaları</h2><p class="mt-1 text-sm text-gray-500">GSC ve SERP yalnız gözlemdir; AI önerisi sahiplik değildir.</p></div><select wire:change="openRun($event.target.value)" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Çalışma seçin</option>@foreach($runs as $option)<option value="{{ $option->id }}" @selected($run?->id === $option->id)>#{{ $option->id }} · {{ $option->status }} · {{ $option->deterministic_state }}</option>@endforeach</select></div>
            @if($run)
                <div @if(in_array($run->status, ['queued', 'running'], true)) wire:poll.5s="refreshRun" @endif class="mt-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <div class="flex flex-wrap justify-between gap-3"><p class="text-sm"><strong>Çalışma #{{ $run->id }}</strong> · {{ $run->status }}</p><p class="text-xs text-gray-500">{{ $run->candidate_count }} aday · {{ $run->eligible_candidate_count }} teknik uygun</p></div>
                    <div class="mt-3 flex flex-wrap gap-2 text-xs"><span class="rounded bg-gray-100 px-2 py-1 dark:bg-gray-800">Kurallı: {{ $statusLabels[$run->deterministic_state] ?? $run->deterministic_state }}</span>@if($run->ai_decision_state)<span class="rounded bg-brand-100 px-2 py-1 text-brand-800">AI: {{ $statusLabels[$run->ai_decision_state] ?? $run->ai_decision_state }}</span>@endif @if($run->wrong_url_candidate)<span class="rounded bg-amber-100 px-2 py-1 text-amber-800">Hedef URL’den farklı URL gözleniyor</span>@endif @if($run->cannibalization_candidate)<span class="rounded bg-red-100 px-2 py-1 text-red-800">Cannibalization inceleme adayı</span>@endif</div>
                    @if($run->recommended_content_type)<p class="mt-3 text-sm">İçerik türü önerisi: <strong>{{ $contentLabels[$run->recommended_content_type] ?? $run->recommended_content_type }}</strong></p>@endif
                    @if($run->rationale)<p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $run->rationale }}</p>@endif
                    @if($run->abstained && $run->abstention_reason)<p class="mt-2 rounded bg-amber-50 px-3 py-2 text-xs text-amber-800">Çekimser: {{ $run->abstention_reason }}</p>@endif
                    @if($run->error_code)<p class="mt-2 rounded bg-red-50 px-3 py-2 text-xs text-red-800">{{ $run->error_code }} · {{ $run->error_summary }}</p>@endif
                </div>

                <label class="mt-4 flex items-center gap-2 text-sm"><input wire:model="lockOnApproval" type="checkbox" class="rounded border-gray-300 text-brand-500" /> İnsan onayından sonra sahipliği kilitle</label>
                <div class="mt-4 space-y-3">
                    @forelse($run->candidates->sortByDesc(fn($candidate) => [$candidate->ai_recommended ? 1 : 0, $candidate->gsc_impressions ?? 0]) as $candidate)
                        <article class="rounded-lg border p-4 {{ $candidate->ai_recommended ? 'border-brand-300 bg-brand-50/50' : 'border-gray-200' }} dark:border-gray-700 dark:bg-gray-900">
                            <div class="flex flex-wrap items-start justify-between gap-3"><div class="min-w-0"><p class="break-all font-medium text-gray-900 dark:text-white">{{ $candidate->url }}</p><p class="mt-1 text-xs text-gray-500">Kaynak: {{ collect($candidate->candidate_sources)->join(', ') ?: '—' }}</p></div><div class="flex gap-2"><span class="rounded px-2 py-1 text-[10px] font-semibold {{ $candidate->technical_eligibility === 'eligible' ? 'bg-emerald-100 text-emerald-800' : ($candidate->technical_eligibility === 'ineligible' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800') }}">{{ $candidate->technical_eligibility }}</span>@if($candidate->ai_recommended)<span class="rounded bg-brand-100 px-2 py-1 text-[10px] font-semibold text-brand-800">AI önerisi</span>@endif</div></div>
                            <div class="mt-3 grid gap-2 text-xs sm:grid-cols-4"><p>GSC gösterim: <strong>{{ $number($candidate->gsc_impressions) }}</strong></p><p>GSC pay: <strong>{{ $percent($candidate->gsc_impression_share) }}</strong></p><p>Önceki pay: <strong>{{ $percent($candidate->comparison_impression_share) }}</strong></p><p>SERP desteği: <strong>{{ $candidate->serp_supporting_queries === null ? '—' : $candidate->serp_supporting_queries.'/'.$candidate->serp_observed_queries }}</strong></p></div>
                            <div class="mt-3 flex flex-wrap gap-1">@foreach($candidate->technical_gate as $key => $gate)<span class="rounded px-2 py-1 text-[10px] {{ data_get($gate, 'state') === 'pass' ? 'bg-emerald-50 text-emerald-700' : (data_get($gate, 'state') === 'fail' ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700') }}">{{ $key }}: {{ data_get($gate, 'state') }}</span>@endforeach</div>
                            @if($candidate->semantic_fit)<p class="mt-3 text-xs"><strong>Semantik:</strong> {{ $candidate->semantic_fit }} · güven {{ $number($candidate->semantic_confidence) }}/100 — {{ $candidate->semantic_rationale }}</p>@endif
                            <p class="mt-2 text-[11px] text-gray-400">Eşleşen terimler: {{ collect($candidate->matched_terms)->join(', ') ?: '—' }}</p>
                            @if($candidate->review_status === 'pending')<div class="mt-3 flex gap-2"><button wire:click="verifyCandidate({{ $candidate->id }})" @disabled($candidate->technical_eligibility !== 'eligible' || $ownership?->is_locked) class="rounded bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-40">Sahip olarak doğrula</button><button wire:click="rejectCandidate({{ $candidate->id }})" class="rounded bg-red-600 px-3 py-1.5 text-xs font-semibold text-white">Reddet</button></div>@else<p class="mt-3 text-xs font-semibold">İnsan kararı: {{ $candidate->review_status }}</p>@endif
                        </article>
                    @empty
                        <p class="text-sm text-gray-500">Bu çalışmada aday URL yok.</p>
                    @endforelse
                </div>
            @endif
        </section>
    @endif
</div>
