@php
    $trackingLabels = ['recorded' => 'Değişiklik kaydedildi', 'collecting' => 'Yeniden taranıyor', 'verifying' => 'Doğrulanıyor', 'pending_review' => 'İnsan incelemesi bekliyor', 'verified' => 'Sonuç kabul edildi', 'rejected' => 'Doğrulama reddedildi', 'failed' => 'Başarısız'];
    $resultLabels = ['technically_fixed' => 'Teknik olarak düzeltildi', 'content_change_verified' => 'İçerik değişikliği doğrulandı', 'visibility_increased' => 'Görünürlük arttı', 'visibility_decreased' => 'Görünürlük azaldı', 'no_change_observed' => 'Değişiklik gözlenmedi', 'too_early' => 'Henüz değerlendirmek için erken', 'insufficient_data' => 'Veri yetersiz'];
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-600">Arama talebi · Faz 13</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Değişiklik ve sonuç takibi</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Tamamlanan Task’ın uygulama kaydını eski/yeni HTML sürümleriyle eşler; hedefli URL ve sayfa ailesi taraması, teknik kontrol, AI semantik doğrulama ve saklı GSC/GA4/SERP dönem karşılaştırmasını tek bir insan onayına getirir. Sonuç gözlemseldir; nedensellik iddiası değildir.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('operator.library.search-demand-improvements', ['brand' => $selectedBrandId, 'website' => $selectedWebsiteId]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Faz 12 önerileri</a>
            <a href="{{ route('operator.tasks') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Task’lar</a>
            <a href="{{ route('operator.activity') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Activity</a>
        </div>
    </div>

    @if($message !== '')<div class="rounded-lg border px-4 py-3 text-sm {{ $messageTone === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' }}">{{ $message }}</div>@endif

    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="grid gap-3 md:grid-cols-2">
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Marka</span><select wire:model.live="selectedBrandId" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Marka seçin</option>@foreach($brands as $brand)<option value="{{ $brand->id }}">{{ $brand->name }}</option>@endforeach</select></label>
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Website</span><select wire:model.live="selectedWebsiteId" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Website seçin</option>@foreach($websites as $website)<option value="{{ $website->id }}">{{ $website->name }}</option>@endforeach</select></label>
        </div>
    </section>

    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <h2 class="font-semibold text-gray-900 dark:text-white">Uygulanan değişikliği kaydet</h2>
        <p class="mt-1 text-xs text-gray-500">Yalnız onaylı Faz 12 Recommendation’ından manuel oluşturulup tamamlanmış Task’lar listelenir. Her Task için tek uygulama kaydı açılır.</p>
        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <label class="block lg:col-span-2"><span class="mb-1 block text-xs font-medium text-gray-500">Tamamlanan Task</span><select wire:model="selectedTaskId" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Task seçin</option>@foreach($tasks as $task)<option value="{{ $task->id }}">#{{ $task->id }} · {{ $task->title }}</option>@endforeach</select>@error('selectedTaskId')<span class="mt-1 block text-xs text-red-600">{{ $message }}</span>@enderror</label>
            <label class="block lg:col-span-2"><span class="mb-1 block text-xs font-medium text-gray-500">Uygulanan değişiklik</span><textarea wire:model="changeSummary" rows="3" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" placeholder="Neyin, hangi kapsamda değiştirildiğini yazın."></textarea>@error('changeSummary')<span class="mt-1 block text-xs text-red-600">{{ $message }}</span>@enderror</label>
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Uygulama zamanı</span><input wire:model="appliedAt" type="datetime-local" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"></label>
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Sonuç inceleme zamanı</span><input wire:model="reviewAfter" type="datetime-local" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"></label>
            <label class="block lg:col-span-2"><span class="mb-1 block text-xs font-medium text-gray-500">Ek etkilenen URL’ler (satır başına bir URL)</span><textarea wire:model="affectedUrlsText" rows="3" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" placeholder="Faz 12 hedef URL’si otomatik eklenir."></textarea></label>
        </div>
        <button wire:click="recordChange" wire:loading.attr="disabled" type="button" class="mt-4 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">Değişikliği ve eski fingerprint’i kaydet</button>
    </section>

    <div class="space-y-5" @if($trackings->contains(fn($item) => in_array($item->status, ['collecting', 'verifying']))) wire:poll.5s="refresh" @endif>
        @foreach($trackings as $tracking)
            @php $collectionStatus = $tracking->collectionRun?->status?->value; $latest = $tracking->runs->sortByDesc('id')->first(); @endphp
            <article class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div><p class="text-xs font-semibold text-brand-600">Değişiklik #{{ $tracking->id }} · Task #{{ $tracking->task_id }} · {{ $tracking->cluster?->name }}</p><h2 class="mt-1 font-semibold text-gray-900 dark:text-white">{{ $tracking->change_summary }}</h2><p class="mt-2 text-xs text-gray-500">Uygulandı: {{ $tracking->applied_at?->format('d.m.Y H:i') }} · İnceleme: {{ $tracking->review_after_at?->format('d.m.Y H:i') }}</p></div>
                    <div class="text-right"><span class="rounded bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ $trackingLabels[$tracking->status] ?? $tracking->status }}</span>@if($tracking->result_status)<p class="mt-2 text-sm font-semibold text-brand-700">{{ $resultLabels[$tracking->result_status] ?? $tracking->result_status }}</p>@endif</div>
                </div>
                <div class="mt-4 grid gap-4 lg:grid-cols-3">
                    <div><h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Etkilenen URL’ler</h3><ul class="mt-2 space-y-1 text-xs text-gray-600">@foreach($tracking->affected_urls as $url)<li class="break-all">{{ $url }}</li>@endforeach</ul></div>
                    <div><h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">HTML fingerprint</h3><p class="mt-2 text-xs text-gray-600">Eski: {{ collect($tracking->baseline_html_fingerprints)->filter(fn($row) => filled($row['html_hash'] ?? null))->count() }} / {{ count($tracking->affected_urls) }}</p><p class="mt-1 text-xs text-gray-600">Yeni: {{ collect($tracking->latest_html_fingerprints ?? [])->filter(fn($row) => filled($row['html_hash'] ?? null))->count() }} / {{ count($tracking->affected_urls) }}</p></div>
                    <div><h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Bileşenler</h3><dl class="mt-2 space-y-1 text-xs text-gray-600"><div>Teknik: {{ data_get($tracking->component_results, 'technical', 'bekliyor') }}</div><div>İçerik: {{ data_get($tracking->component_results, 'content', 'bekliyor') }}</div><div>Görünürlük: {{ data_get($tracking->component_results, 'visibility', 'bekliyor') }}</div></dl></div>
                </div>
                <div class="mt-5 flex flex-wrap gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    @if(in_array($tracking->status, ['recorded', 'failed', 'rejected']))<button wire:click="startCollection({{ $tracking->id }})" type="button" class="rounded-lg bg-sky-600 px-3 py-2 text-sm font-semibold text-white">Hedefli URL + sayfa ailesini tara</button>@endif
                    @if(in_array($collectionStatus, ['completed', 'partial']) && in_array($tracking->status, ['collecting', 'failed', 'rejected']))<button wire:click="queueVerification({{ $tracking->id }})" type="button" class="rounded-lg bg-violet-600 px-3 py-2 text-sm font-semibold text-white">Teknik + AI + dönem doğrulamasını çalıştır</button>@endif
                    @if($collectionStatus)<span class="self-center text-xs text-gray-500">Tarama: {{ $collectionStatus }} · #{{ $tracking->targeted_collection_run_id }}</span>@endif
                </div>
                @if($latest && $latest->status === 'failed')<p class="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ $latest->error_summary }}</p>@endif
                @if($latest && $latest->status === 'completed' && $latest->review_status === 'pending')
                    <div class="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-4">
                        <div class="flex flex-wrap justify-between gap-3"><h3 class="font-semibold text-amber-900">Önerilen sonuç: {{ $resultLabels[$latest->proposed_result_status] ?? $latest->proposed_result_status }}</h3><span class="text-xs text-amber-800">AI güveni {{ data_get($latest->semantic_result, 'confidence', 0) }}/100</span></div>
                        <p class="mt-2 text-sm text-amber-900">{{ data_get($latest->semantic_result, 'summary', 'Semantik özet bulunmuyor.') }}</p>
                        <p class="mt-2 text-xs text-amber-800">Teknik: {{ data_get($latest->technical_result, 'explanation', '—') }} · GSC/GA4/SERP: {{ data_get($latest->metric_comparison, 'state', 'veri_yetersiz') }}</p>
                        <label class="mt-3 block"><span class="mb-1 block text-xs font-medium text-amber-800">İnceleme notu</span><textarea wire:model="reviewNotes.{{ $latest->id }}" rows="2" class="w-full rounded-lg border-amber-300 bg-white text-sm" placeholder="İsteğe bağlı"></textarea></label>
                        <div class="mt-3 flex gap-2"><button wire:click="review({{ $latest->id }}, 'approved')" type="button" class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white">Sonucu kabul et</button><button wire:click="review({{ $latest->id }}, 'rejected')" type="button" class="rounded-lg bg-white px-3 py-2 text-sm font-semibold text-red-700 ring-1 ring-inset ring-red-200">Reddet</button></div>
                        <p class="mt-2 text-xs text-amber-800">Kabul, Task Outcome’u günceller ve yalnız çözüldü/sürüyor kararı varsa bağlı Finding için insan onaylı yeniden değerlendirme kaydı oluşturur.</p>
                    </div>
                @endif
            </article>
        @endforeach
    </div>
</div>
