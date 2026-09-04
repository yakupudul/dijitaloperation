@php
    $statusLabels = ['queued' => 'Kuyrukta', 'running' => 'Çalışıyor', 'completed' => 'Tamamlandı', 'failed' => 'Başarısız'];
    $reviewLabels = ['pending' => 'İnceleme bekliyor', 'approved' => 'Kabul edildi', 'rejected' => 'Reddedildi'];
    $actionLabels = [
        'improve_existing' => 'Mevcut sayfayı geliştir', 'new_service_page' => 'Yeni hizmet sayfası',
        'blog_guide' => 'Blog / rehber', 'faq' => 'SSS', 'merge' => 'Birleştirme',
        'internal_linking' => 'İç bağlantı düzenlemesi', 'no_action' => 'İşlem yapma',
        'insufficient_evidence' => 'Kanıt yetersiz',
    ];
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-600">Arama talebi · Faz 12</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Bulgu ve öneri üretimi</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Teknik kontrolleri deterministik, semantik yorumları onaylı Faz 11 kanıtıyla üretir. Her kayıt önce taslaktır; yalnız insan kabulü kanonik Evidence → Finding → Recommendation zincirini oluşturur. Task geçişi ayrıca manueldir.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('operator.library.search-demand-competitive-intelligence', ['brand' => $selectedBrandId, 'website' => $selectedWebsiteId, 'cluster' => $selectedClusterId]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Faz 11 analizi</a>
            <a href="{{ route('operator.recommendations', ['asset' => $selectedWebsiteId]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Öneriler / Task</a>
            <a href="{{ route('operator.library.search-demand-changes', ['brand' => $selectedBrandId, 'website' => $selectedWebsiteId]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Faz 13 sonuç takibi</a>
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
            <div class="rounded-lg border p-3 {{ $readiness['verified_owner'] ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50' }}"><p class="text-xs font-semibold {{ $readiness['verified_owner'] ? 'text-emerald-800' : 'text-amber-800' }}">Doğrulanmış marka URL’si</p><p class="mt-1 text-xs text-gray-600">{{ $readiness['verified_owner'] ? 'Hazır; teknik kontrol bu saklı sayfa kanıtını kullanır.' : 'Eksik; Faz 8’de URL sahibini doğrulayın.' }}</p></div>
            <div class="rounded-lg border p-3 {{ $readiness['approved_analyses'] > 0 ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50' }}"><p class="text-xs font-semibold {{ $readiness['approved_analyses'] > 0 ? 'text-emerald-800' : 'text-amber-800' }}">Onaylı Faz 11 analizi</p><p class="mt-1 text-xs text-gray-600">{{ $readiness['approved_analyses'] }} kabul edilmiş analiz; pending veya reddedilmiş kayıt kullanılmaz.</p></div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <button wire:click="queuePlanning" wire:loading.attr="disabled" type="button" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">Bulgu ve öneri taslaklarını üret</button>
            <p class="text-xs text-gray-500">Aynı kanıt + agent + Skill + model rotası imzası tamamlandıysa yeniden ücret oluşmaz.</p>
        </div>
    </section>

    @if($runs->isNotEmpty())
        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><h2 class="font-semibold text-gray-900 dark:text-white">Planlama geçmişi</h2></div>
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach($runs as $history)
                    <button wire:click="openRun({{ $history->id }})" type="button" class="grid w-full gap-2 px-5 py-3 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-950 md:grid-cols-[5rem_1fr_10rem]">
                        <span class="font-semibold text-gray-800 dark:text-gray-200">#{{ $history->id }}</span>
                        <span class="text-gray-500">{{ $history->proposal_count }} taslak · Faz 11 run #{{ $history->competitive_intelligence_run_id }}</span>
                        <span class="text-right font-medium">{{ $statusLabels[$history->status] ?? $history->status }}</span>
                    </button>
                @endforeach
            </div>
        </section>
    @endif

    @if($run)
        <section @if(in_array($run->status, ['queued', 'running'])) wire:poll.5s="refreshRun" @endif class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div><p class="text-xs font-semibold text-gray-500">Website Improvement #{{ $run->id }}</p><h2 class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $statusLabels[$run->status] ?? $run->status }}</h2></div>
                <div class="text-right text-xs text-gray-500"><p>{{ $run->proposal_count }} taslak</p><p class="mt-1">{{ $run->provider ?: '—' }} / {{ $run->model ?: '—' }}</p></div>
            </div>
            @if($run->error_summary)<p class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ $run->error_summary }}</p>@endif
            @if($run->abstained)<p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">AI genel olarak çekimser: {{ $run->abstention_reason ?: 'Semantik kanıt yeterli görülmedi; deterministik taslaklar yine gösterilir.' }}</p>@endif
            <p class="mt-4 break-all text-[10px] text-gray-400">Girdi {{ $run->input_fingerprint }} · agent {{ $run->agent_signature }} · skill {{ $run->skill_signature }}</p>
        </section>

        <div class="space-y-5">
            @forelse($run->proposals as $proposal)
                <article class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div><p class="text-xs font-semibold uppercase tracking-wide {{ $proposal->origin === 'deterministic' ? 'text-sky-600' : 'text-violet-600' }}">{{ $proposal->origin === 'deterministic' ? 'Deterministik teknik bulgu' : 'AI semantik bulgu' }}</p><h2 class="mt-1 font-semibold text-gray-900 dark:text-white">{{ $proposal->title }}</h2><p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $proposal->summary }}</p></div>
                        <div class="text-right"><span class="rounded bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ $reviewLabels[$proposal->review_status] ?? $proposal->review_status }}</span><p class="mt-2 text-xs text-gray-500">{{ $actionLabels[$proposal->action_type] ?? $proposal->action_type }} · {{ $proposal->confidence }}/100</p></div>
                    </div>
                    @if($proposal->abstained)<p class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">Çekimser / kanıt yetersiz: {{ $proposal->abstention_reason }}</p>@endif
                    <div class="mt-5 grid gap-5 lg:grid-cols-3">
                        <div><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Önerilen işlem</h3><p class="mt-2 text-sm font-medium text-gray-700 dark:text-gray-200">{{ $proposal->recommendation_title }}</p><p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $proposal->recommendation_action }}</p></div>
                        <div><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Gerekçe ve kanıt</h3><p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $proposal->rationale }}</p><ul class="mt-2 space-y-1 text-xs text-gray-500">@foreach((array) data_get($proposal->evidence_refs, 'evidence_explanation', []) as $item)<li>• {{ $item }}</li>@endforeach</ul><p class="mt-2 text-[11px] text-gray-400">Analiz: {{ collect((array) data_get($proposal->evidence_refs, 'analysis_ids', []))->join(', ') ?: 'teknik sayfa kanıtı' }} · Gözlem: {{ collect((array) data_get($proposal->evidence_refs, 'observation_ids', []))->join(', ') ?: '—' }}</p></div>
                        <div><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Nasıl doğrulanır</h3><ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">@forelse($proposal->verification_steps ?? [] as $item)<li>• {{ $item }}</li>@empty<li>• Doğrulama adımı belirtilmedi.</li>@endforelse</ul></div>
                    </div>
                    @if(is_array($proposal->content_brief) && array_filter($proposal->content_brief) !== [])
                        <div class="mt-5 rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">İçerik brief’i</h3><dl class="mt-3 grid gap-3 md:grid-cols-2">@foreach($proposal->content_brief as $key => $value)@if(filled($value))<div><dt class="text-xs font-semibold text-gray-500">{{ str($key)->replace('_', ' ')->title() }}</dt><dd class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ is_array($value) ? collect($value)->join(' · ') : $value }}</dd></div>@endif @endforeach</dl></div>
                    @endif
                    @if($proposal->review_status === 'pending')
                        <div class="mt-5 border-t border-gray-100 pt-4 dark:border-gray-800"><label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">İnceleme notu</span><textarea wire:model="reviewNotes.{{ $proposal->id }}" rows="2" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" placeholder="İsteğe bağlı"></textarea></label><div class="mt-3 flex flex-wrap gap-2">@if(!$proposal->abstained && $proposal->action_type !== 'insufficient_evidence')<button wire:click="review({{ $proposal->id }}, 'approved')" type="button" class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white">Finding + Recommendation oluştur</button>@endif<button wire:click="review({{ $proposal->id }}, 'rejected')" type="button" class="rounded-lg bg-red-50 px-3 py-2 text-sm font-semibold text-red-700 ring-1 ring-inset ring-red-200">Reddet</button></div><p class="mt-2 text-xs text-gray-500">Kabul kanıtı Finding’e bağlar ve Recommendation oluşturur. Task oluşturmaz; Recommendation ekranındaki mevcut manuel işlem kullanılır.</p></div>
                    @else
                        <div class="mt-5 border-t border-gray-100 pt-4 text-xs text-gray-500 dark:border-gray-800">@if($proposal->review_status === 'approved') Finding #{{ $proposal->finding_id }} · Recommendation #{{ $proposal->recommendation_id }} · @if($proposal->recommendation?->tasks?->isNotEmpty()) Task #{{ $proposal->recommendation->tasks->first()->id }} @else Task bekliyor (manuel) @endif @endif @if($proposal->review_note) · Not: {{ $proposal->review_note }} @endif</div>
                    @endif
                </article>
            @empty
                @if($run->status === 'completed')<div class="rounded-xl border border-gray-200 bg-white p-5 text-sm text-gray-500">Bu kanıt paketinde teknik veya semantik bulgu taslağı oluşmadı.</div>@endif
            @endforelse
        </div>
    @endif
</div>
