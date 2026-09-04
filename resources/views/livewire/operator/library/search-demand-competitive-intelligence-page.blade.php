@php
    $statusLabels = ['queued' => 'Kuyrukta', 'running' => 'Çalışıyor', 'completed' => 'Tamamlandı', 'failed' => 'Başarısız'];
    $reviewLabels = ['pending' => 'İnceleme bekliyor', 'approved' => 'Kabul edildi', 'rejected' => 'Reddedildi'];
    $entityLabels = ['unknown' => 'Belirsiz', 'business' => 'İşletme', 'directory' => 'Dizin', 'platform' => 'Platform', 'authority' => 'Otorite'];
    $intentLabels = ['service' => 'Hizmet', 'commercial_landing' => 'Ticari açılış', 'guide' => 'Rehber', 'article' => 'Makale', 'directory' => 'Dizin', 'listing' => 'Listeleme', 'tool' => 'Araç', 'homepage' => 'Ana sayfa', 'other' => 'Diğer', 'unclear' => 'Belirsiz'];
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-600">Arama talebi · Faz 11</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Competitive Intelligence</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Faz 10 rakip sayfa gözlemlerini doğrulanmış marka URL’siyle karşılaştırır. Eksikleri kullanıcı soruları olarak açıklar; kelime sayısı yarışı, canlı web taraması ve otomatik gerçek değişikliği yapmaz.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('operator.library.search-demand-competitor-pages', ['brand' => $selectedBrandId, 'website' => $selectedWebsiteId, 'cluster' => $selectedClusterId]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Rakip sayfaları</a>
            <a href="{{ route('operator.library.search-demand-ownership', ['website' => $selectedWebsiteId, 'cluster' => $selectedClusterId]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">URL sahipliği</a>
            <a href="{{ route('operator.library.search-demand-improvements', ['brand' => $selectedBrandId, 'website' => $selectedWebsiteId, 'cluster' => $selectedClusterId]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Faz 12 bulgu ve öneriler</a>
            <a href="{{ route('operator.activity') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Activity</a>
        </div>
    </div>

    @if($message !== '')
        <div class="rounded-lg border px-4 py-3 text-sm {{ $messageTone === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' }}">{{ $message }}</div>
    @endif

    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="grid gap-3 lg:grid-cols-3">
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Marka</span><select wire:model.live="selectedBrandId" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Marka seçin</option>@foreach($brands as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach</select></label>
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Website</span><select wire:model.live="selectedWebsiteId" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Website seçin</option>@foreach($websites as $website)<option value="{{ $website->id }}">{{ $website->name }}</option>@endforeach</select></label>
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">İçerik hedef kümesi</span><select wire:model.live="selectedClusterId" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Küme seçin</option>@foreach($clusters as $cluster)<option value="{{ $cluster->id }}">{{ $cluster->name }}</option>@endforeach</select></label>
        </div>
        @error('selectedWebsiteId')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
        @error('selectedClusterId')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror

        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <div class="rounded-lg border p-3 {{ $readiness['verified_owner'] ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50' }}"><p class="text-xs font-semibold {{ $readiness['verified_owner'] ? 'text-emerald-800' : 'text-amber-800' }}">Doğrulanmış marka URL’si</p><p class="mt-1 text-xs text-gray-600">{{ $readiness['verified_owner'] ? 'Hazır; kuyruk sırasında saklı HTML bütünlüğü de doğrulanır.' : 'Eksik; Faz 8 URL sahipliğinde bir sayfayı doğrulayın.' }}</p></div>
            <div class="rounded-lg border p-3 {{ $readiness['competitor_observations'] > 0 ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50' }}"><p class="text-xs font-semibold {{ $readiness['competitor_observations'] > 0 ? 'text-emerald-800' : 'text-amber-800' }}">Faz 10 gözlemleri</p><p class="mt-1 text-xs text-gray-600">{{ $readiness['competitor_observations'] }} benzersiz rakip URL kanıtı; bir çalışmada en yeni en fazla 8 sayfa kullanılır.</p></div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <button wire:click="queueAnalysis" wire:loading.attr="disabled" type="button" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">Kanıt paketini analiz et</button>
            <p class="text-xs text-gray-500">Aynı kanıt + agent + skill + model rotası imzası yeniden ücret oluşturmaz; tamamlanmış run kullanılır.</p>
        </div>
    </section>

    @if($runs->isNotEmpty())
        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><h2 class="font-semibold text-gray-900 dark:text-white">Analiz geçmişi</h2></div>
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach($runs as $history)
                    <button wire:click="openRun({{ $history->id }})" type="button" class="grid w-full gap-2 px-5 py-3 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-950 md:grid-cols-[5rem_1fr_8rem_10rem]">
                        <span class="font-semibold text-gray-800 dark:text-gray-200">#{{ $history->id }}</span>
                        <span class="text-gray-500">{{ $history->page_count }} sayfa · {{ $history->summary ? \Illuminate\Support\Str::limit($history->summary, 100) : 'Sonuç bekleniyor' }}</span>
                        <span class="text-gray-500">Güven {{ $history->confidence ?? '—' }}</span>
                        <span class="text-right font-medium">{{ $statusLabels[$history->status] ?? $history->status }}</span>
                    </button>
                @endforeach
            </div>
        </section>
    @endif

    @if($run)
        <section @if(in_array($run->status, ['queued', 'running'])) wire:poll.5s="refreshRun" @endif class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div><p class="text-xs font-semibold text-gray-500">Competitive Intelligence #{{ $run->id }}</p><h2 class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $statusLabels[$run->status] ?? $run->status }}</h2></div>
                <div class="text-right text-xs text-gray-500"><p>{{ $run->page_count }} sayfa · güven {{ $run->confidence ?? '—' }}/100</p><p class="mt-1">{{ $run->provider ?: '—' }} / {{ $run->model ?: '—' }}</p></div>
            </div>
            @if($run->error_summary)<p class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ $run->error_summary }}</p>@endif
            @if($run->summary)<p class="mt-4 text-sm leading-6 text-gray-700 dark:text-gray-300">{{ $run->summary }}</p>@endif
            @if($run->abstained)<p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">Çekimser: {{ $run->abstention_reason ?: 'Kanıt paketi genel sonuç için yeterli görülmedi.' }}</p>@endif
            @if(is_array($run->portfolio_gap_themes) && $run->portfolio_gap_themes !== [])
                <div class="mt-5 grid gap-5 lg:grid-cols-3">
                    <div><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Ortak eksik kullanıcı ihtiyaçları</h3><ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">@foreach($run->portfolio_gap_themes as $item)<li>• {{ $item }}</li>@endforeach</ul></div>
                    <div><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Farklılaşma yönleri</h3><ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">@foreach($run->differentiation_strategy ?? [] as $item)<li>• {{ $item }}</li>@endforeach</ul></div>
                    <div><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Sınırlar</h3><ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">@forelse($run->caveats ?? [] as $item)<li>• {{ $item }}</li>@empty<li>• Ek sınırlama bildirilmedi.</li>@endforelse</ul></div>
                </div>
            @endif
            <p class="mt-4 break-all text-[10px] text-gray-400">Girdi {{ $run->input_fingerprint }} · agent {{ $run->agent_signature }} · skill {{ $run->skill_signature }}</p>
        </section>

        <div class="space-y-5">
            @foreach($run->analyses as $analysis)
                <article class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div><h2 class="font-semibold text-gray-900 dark:text-white">{{ $analysis->competitor?->display_name ?: 'Rakip' }}</h2><p class="mt-1 break-all text-xs text-gray-500">{{ $analysis->observation?->final_url ?: $analysis->observation?->requested_url }}</p></div>
                        <div class="text-right"><span class="rounded bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ $reviewLabels[$analysis->review_status] ?? $analysis->review_status }}</span><p class="mt-1 text-xs text-gray-500">{{ $entityLabels[$analysis->proposed_entity_kind] ?? $analysis->proposed_entity_kind }} · {{ $intentLabels[$analysis->page_intent] ?? $analysis->page_intent }} · {{ $analysis->confidence ?? 0 }}/100</p></div>
                    </div>
                    @if($analysis->abstained)<p class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">Çekimser: {{ $analysis->abstention_reason }}</p>@endif
                    <div class="mt-5 grid gap-5 lg:grid-cols-3">
                        <div><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Konu ve kullanıcı soruları</h3><p class="mt-2 text-xs text-gray-500">{{ collect($analysis->topics)->merge($analysis->subtopics)->join(' · ') ?: 'Konu çıkarımı yok' }}</p><ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">@forelse($analysis->user_questions ?? [] as $item)<li>• {{ $item }}</li>@empty<li>• Soru çıkarımı yok.</li>@endforelse</ul></div>
                        <div><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Marka sayfasında eksik kapsam</h3><ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">@forelse($analysis->missing_coverage ?? [] as $item)<li>• {{ $item }}</li>@empty<li>• Kanıtlanmış eksik ihtiyaç yok.</li>@endforelse</ul></div>
                        <div><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Farklılaşma önerileri</h3><ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">@forelse($analysis->differentiation_ideas ?? [] as $item)<li>• {{ $item }}</li>@empty<li>• Öneri yok.</li>@endforelse</ul></div>
                        <div><h3 class="text-sm font-semibold text-gray-900 dark:text-white">İçerik yapısı ve lokal güven</h3><ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">@foreach(collect($analysis->content_structure)->merge($analysis->local_trust_signals) as $item)<li>• {{ $item }}</li>@endforeach</ul></div>
                        <div><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Kopyalanmaması gerekenler</h3><ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">@foreach(collect($analysis->unnecessary_content)->merge($analysis->do_not_copy) as $item)<li>• {{ $item }}</li>@endforeach</ul></div>
                        <div><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Kanıtlı açıklama</h3><ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">@foreach($analysis->evidence_explanation ?? [] as $item)<li>• {{ $item }}</li>@endforeach</ul></div>
                    </div>
                    @if($analysis->review_status === 'pending')
                        <div class="mt-5 border-t border-gray-100 pt-4 dark:border-gray-800"><label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">İnceleme notu</span><textarea wire:model="reviewNotes.{{ $analysis->id }}" rows="2" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" placeholder="İsteğe bağlı"></textarea></label><div class="mt-3 flex flex-wrap gap-2">@if(!$analysis->abstained)<button wire:click="review({{ $analysis->id }}, 'approved')" type="button" class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white">Analizi kabul et</button>@endif<button wire:click="review({{ $analysis->id }}, 'rejected')" type="button" class="rounded-lg bg-red-50 px-3 py-2 text-sm font-semibold text-red-700 ring-1 ring-inset ring-red-200">Reddet</button></div><p class="mt-2 text-xs text-gray-500">Kabul yalnız bu analiz kaydını incelenmiş yapar; rakip türünü, rolünü veya URL sahipliğini değiştirmez.</p></div>
                    @elseif($analysis->review_note)<p class="mt-4 border-t border-gray-100 pt-3 text-xs text-gray-500 dark:border-gray-800">İnceleme notu: {{ $analysis->review_note }}</p>@endif
                </article>
            @endforeach
        </div>
    @endif
</div>
