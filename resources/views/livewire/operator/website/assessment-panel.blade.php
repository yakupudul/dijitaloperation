@php
    $states = ['queued' => 'Kuyrukta', 'running' => 'Değerlendiriliyor', 'completed' => 'Tamamlandı', 'partial' => 'Kısmi kapsam', 'failed' => 'Başarısız'];
    $coverageLabels = ['covered' => 'Hedef doğrulandı', 'repair_or_verify' => 'Hedefi düzelt / doğrula', 'candidate_review' => 'Sayfa adaylarını incele', 'human_confirmed_gap' => 'Onaylanmış kapsam açığı', 'excluded' => 'Kapsam dışı', 'unknown' => 'Veri / karar yetersiz'];
    $checkLabels = ['pass' => 'Uygun', 'fail' => 'Eksik', 'review' => 'İnceleme', 'unknown' => 'Veri yetersiz', 'not_applicable' => 'Uygulanmaz'];
    $scope = ['brand' => $website->brand_id, 'website' => $website->id];
@endphp
<div class="space-y-5" @if($run && in_array($run->status, ['queued', 'running'])) wire:poll.5s @endif>
    <section class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div><h2 class="text-lg font-semibold text-gray-900 dark:text-white">Standartlar ve İyileştirmeler</h2><p class="mt-2 max-w-3xl text-sm text-gray-500">Saklı web sitesi verilerini değerlendirir; teknik eksikleri, hizmet kapsamını ve ilgili sayfaları bir araya getirir. Kaynak veriyi güncelledikten sonra yeniden çalıştırın.</p></div>
            <button type="button" wire:click="start" wire:loading.attr="disabled" @disabled($run && in_array($run->status, ['queued', 'running'])) class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Web sitesini değerlendir</button>
        </div>
        <div class="mt-4 flex flex-wrap gap-4 text-sm"><a wire:navigate href="{{ route('operator.library.website-standards') }}" class="text-brand-600">Standartlar kütüphanesi</a><a wire:navigate href="{{ route('operator.library.search-demand-improvements', $scope) }}" class="text-brand-600">İçerik ve rakip önerileri</a><a wire:navigate href="{{ route('operator.integrations.website', ['site' => $website->id]) }}" class="text-brand-600">Entegrasyon verileri</a><a wire:navigate href="{{ route('operator.activity') }}" class="text-brand-600">Etkinlik</a></div>
        <p class="mt-3 text-xs text-gray-500">Bu değerlendirme AI veya ücretli veri çağrısı yapmaz. İçerik incelemesini ilgili sorgu kümesinden ayrıca başlatabilirsiniz.</p>
    </section>
    @if($message)<p role="status" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">{{ $message }}</p>@endif
    @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @if($run)
        <div class="flex flex-wrap items-center gap-3 text-sm text-gray-600 dark:text-gray-400"><strong>{{ $states[$run->status] ?? $run->status }}</strong><span>{{ $run->created_at?->format('d.m.Y H:i') }}</span>
            @foreach($history as $previous)<button type="button" wire:click="openRun({{ $previous->id }})" class="rounded border border-gray-200 px-2 py-1">#{{ $previous->id }}</button>@endforeach
        </div>
        @if($run->status === 'failed')<p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">Değerlendirme tamamlanamadı. Ayrıntıları Etkinlik ekranında inceleyip yeniden başlatın.</p>@endif
    @endif
    @if($report !== [])
        <p class="text-sm text-gray-500">{{ $report['total_pages'] ?? 0 }} kayıtlı sayfanın {{ $report['evaluated_pages'] ?? 0 }} tanesi değerlendirildi. Bulgular gösterilen gözlem tarihlerine aittir.</p>
        @if(($report['total_pages'] ?? 0) > ($report['evaluated_pages'] ?? 0))<p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">Bu çalışmanın sınırı {{ $report['page_limit'] }} sayfa. İncelenmeyen sayfalar için uygunluk sonucu verilmedi.</p>@endif
        @if(($report['unreadable_html_count'] ?? 0) > 0)<p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">{{ $report['unreadable_html_count'] }} sayfanın HTML dosyası doğrulanamadı. İlgili içerik kontrollerinde kanıt eksikliği olabilir.</p>@endif
        <section class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <h3 class="p-5 font-semibold text-gray-900 dark:text-white">Standart değerlendirmesi</h3>
            <table class="w-full text-left text-sm"><thead class="bg-gray-50 text-gray-500 dark:bg-gray-800"><tr><th class="px-5 py-3">Standart</th><th class="px-3 py-3">Uygun</th><th class="px-3 py-3">Eksik</th><th class="px-3 py-3">İnceleme</th><th class="px-3 py-3">Veri yetersiz</th><th class="px-3 py-3">Uygulanmaz</th></tr></thead><tbody class="divide-y divide-gray-100 text-gray-700 dark:divide-gray-800 dark:text-gray-300">
                @foreach($report['standards'] ?? [] as $standard)<tr><td class="px-5 py-3"><button type="button" wire:click="showStandard('{{ $standard['id'] }}')" class="text-left text-brand-600 hover:underline">{{ $standard['title'] }}</button></td>@foreach(['pass', 'fail', 'review', 'unknown', 'not_applicable'] as $state)<td class="px-3 py-3 {{ $state === 'fail' && $standard[$state] > 0 ? 'font-semibold text-red-600' : '' }}">{{ $standard[$state] }}</td>@endforeach</tr>@endforeach
            </tbody></table>
        </section>
        @if($selectedStandard)
            <section class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="font-semibold text-gray-900 dark:text-white">{{ $selectedStandard['title'] }}</h3>
                    <select wire:model.live="resultState" aria-label="Kontrol sonucu" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white"><option value="">Tüm sonuçlar</option>@foreach($checkLabels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                </div>
                <p class="mt-2 text-xs text-gray-500">Sonuç, kullanılan saklı veriye aittir. Veri yetersiz olan kayıtlar uygun kabul edilmez.</p>
                <div class="mt-4 divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($standardResults as $result)
                        <div class="py-3">
                            <div class="flex flex-wrap justify-between gap-2"><p class="break-all text-sm text-gray-800 dark:text-gray-200">{{ $result['url'] }}</p><span class="text-xs font-medium {{ $result['state'] === 'fail' ? 'text-red-600' : 'text-gray-500' }}">{{ $checkLabels[$result['state']] ?? $result['state'] }}</span></div>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $result['reason'] }}</p>
                            @if($result['observed_at'])<p class="mt-1 text-xs text-gray-400">Gözlem: {{ $result['observed_at'] }}</p>@endif
                            @if($result['observed'] !== null)<details class="mt-2 text-xs text-gray-500"><summary class="cursor-pointer">Gözlenen değer</summary><pre class="mt-2 max-h-48 overflow-auto whitespace-pre-wrap break-all rounded bg-gray-50 p-3 dark:bg-gray-800">{{ mb_substr(json_encode($result['observed'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), 0, 4000) }}</pre></details>@endif
                        </div>
                    @empty<p class="py-4 text-sm text-gray-500">Gösterilecek ayrıntı yok. Eski değerlendirmelerde site kontrollerinin ayrıntıları saklanmamış olabilir; güncel değerlendirme başlatın.</p>@endforelse
                </div>
                {{ $standardResults->links() }}
            </section>
        @endif
        <section class="space-y-3">
            <h3 class="font-semibold text-gray-900 dark:text-white">Öncelikli iyileştirme grupları</h3>
            <p class="text-sm text-gray-500">Önce doğrulanmış hedeflerin erişim engelleri, ardından diğer teknik eksikler ve inceleme önerileri gelir. Aynı standarttan etkilenen sayfalar birlikte gösterilir.</p>
            @forelse($proposals ?? [] as $proposal)
                <article wire:key="assessment-proposal-{{ $proposal->id }}" class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex flex-wrap justify-between gap-3"><h4 class="font-semibold text-gray-900 dark:text-white">{{ $proposal->title }}</h4><span class="text-xs text-gray-500">{{ count(data_get($proposal->evidence_refs, 'affected_pages', [])) }} URL · {{ data_get($proposal->evidence_refs, 'assessment_state') === 'fail' ? 'Gözlenen eksik' : 'İnceleme önerisi' }}</span></div>
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">{{ $proposal->recommendation_action }}</p><p class="mt-2 text-xs text-gray-500">Öncelik gerekçesi: {{ data_get($proposal->evidence_refs, 'priority_reason') }}</p>
                    <details class="mt-3 text-sm"><summary class="cursor-pointer text-brand-600">Etkilenen sayfalar ve kanıt</summary><div class="mt-2 max-h-72 space-y-2 overflow-y-auto">
                        @foreach(data_get($proposal->evidence_refs, 'affected_pages', []) as $page)<div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800"><p class="break-all text-gray-700 dark:text-gray-300">{{ $page['url'] }}</p><p class="mt-1 text-xs text-gray-500">{{ $page['observed_at'] ?? 'Site gözlemi' }} · {{ $checkLabels[$page['state']] }}</p>@if(isset($page['page_profile_id']))<a wire:navigate href="{{ route('operator.website', ['assetId' => $website->id, 'tab' => 'content', 'page_profile' => $page['page_profile_id']]) }}" class="mt-1 inline-block text-xs text-brand-600">Sayfa verisini incele</a>@endif</div>@endforeach
                    </div><p class="mt-3 text-xs text-gray-500">{{ implode(' ', $proposal->verification_steps ?? []) }}</p></details>
                    @if($proposal->review_status === 'pending')<div class="mt-4 flex gap-3"><button type="button" wire:click="review({{ $proposal->id }}, 'approved')" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white">İyileştirmeyi kabul et</button><button type="button" wire:click="review({{ $proposal->id }}, 'rejected')" wire:loading.attr="disabled" class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-600">Reddet</button></div>@else<p class="mt-3 text-xs text-gray-500">{{ $proposal->review_status === 'approved' ? 'Kabul edildi' : 'Reddedildi' }}</p>@endif
                </article>
            @empty<p class="text-sm text-gray-500">Değerlendirilen kanıtta iyileştirme grubu üretilmedi. Veri yetersiz kontrolleri ayrıca inceleyin.</p>@endforelse
            @if($proposals){{ $proposals->links() }}@endif
        </section>
        <section class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <div class="p-5"><h3 class="font-semibold text-gray-900 dark:text-white">Hizmetlerin arama kapsamı</h3><p class="mt-1 text-sm text-gray-500">{{ data_get($report, 'coverage.unclustered_query_count', 0) }} etkin sorgu henüz kümelenmemiş.</p></div>
            <table class="w-full text-left text-sm"><thead class="bg-gray-50 text-gray-500 dark:bg-gray-800"><tr><th class="px-5 py-3">Hizmet</th><th class="px-3 py-3">Etkin sorgu</th><th class="px-3 py-3">Küme</th><th class="px-3 py-3">Durum</th></tr></thead><tbody class="divide-y divide-gray-100 text-gray-700 dark:divide-gray-800 dark:text-gray-300">@forelse(data_get($report, 'coverage.services', []) as $service)<tr><td class="px-5 py-3">{{ $service['name'] }}</td><td class="px-3 py-3">{{ $service['query_count'] }}</td><td class="px-3 py-3">{{ $service['cluster_count'] }}</td><td class="px-3 py-3">{{ ['unlinked_service' => 'Hizmet kataloğuyla eşleştirilmemiş', 'no_active_queries' => 'Bu sitede etkin sorgu yok', 'available' => 'Sorgular bağlı'][$service['state']] }}</td></tr>@empty<tr><td colspan="4" class="p-5">Markaya bağlı etkin hizmet tanımlı değil.</td></tr>@endforelse</tbody></table>
            <div class="flex gap-4 p-5 text-sm"><a wire:navigate href="{{ route('operator.library.brand-query-portfolios', $scope) }}" class="text-brand-600">Marka sorguları</a><a wire:navigate href="{{ route('operator.library.search-demand-clusters', $scope) }}" class="text-brand-600">Sorgu kümeleri</a></div>
        </section>
        <section class="space-y-3"><h3 class="font-semibold text-gray-900 dark:text-white">Sorgu kümesi → sayfa değerlendirmesi</h3>
            @if(data_get($report, 'coverage.query_limit_reached') || data_get($report, 'coverage.cluster_limit_reached'))<p class="text-sm text-amber-700">Kapsam sınırı: en fazla 3.000 etkin sorgu ve 100 içerik kümesi değerlendirildi.</p>@endif
            @forelse(data_get($report, 'coverage.clusters', []) as $cluster)
                <article class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex flex-wrap justify-between gap-3"><h4 class="font-semibold text-gray-900 dark:text-white">{{ $cluster['cluster_name'] }}</h4><span class="text-sm {{ $cluster['state'] === 'repair_or_verify' ? 'text-amber-700' : 'text-gray-500' }}">{{ $coverageLabels[$cluster['state']] }}</span></div>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $cluster['explanation'] }}</p><p class="mt-2 break-all text-sm text-gray-700 dark:text-gray-300">{{ $cluster['owner_url'] ?: 'Doğrulanmış hedef yok' }} {{ $cluster['owner_locked'] ? '· İnsan kararı kilitli' : '' }}</p>
                    <details class="mt-3 text-sm"><summary class="cursor-pointer text-brand-600">Sorgular ve mevcut sayfa adayları</summary><p class="mt-2 text-gray-500">{{ implode(' · ', $cluster['queries']) }}</p><div class="mt-2 max-h-64 space-y-2 overflow-y-auto">@foreach($cluster['candidates'] as $candidate)<p class="break-all text-gray-600 dark:text-gray-400">{{ $candidate['url'] }} · {{ $candidate['technical_state'] === 'eligible' ? 'Teknik olarak hazır' : 'Teknik engel veya eksik gözlem' }}</p>@endforeach</div></details>
                    <div class="mt-4 flex flex-wrap gap-4 text-sm"><a wire:navigate href="{{ route('operator.library.search-demand-ownership', $scope + ['cluster' => $cluster['cluster_id']]) }}" class="text-brand-600">Sayfa eşleşmesini incele</a><a wire:navigate href="{{ route('operator.library.search-demand-improvements', $scope + ['cluster' => $cluster['cluster_id']]) }}" class="text-brand-600">Standartlara göre içerik analizi</a><a wire:navigate href="{{ route('operator.library.search-demand-competitive-intelligence', $scope + ['cluster' => $cluster['cluster_id']]) }}" class="text-brand-600">Rakip karşılaştırması</a></div>
                </article>
            @empty<p class="text-sm text-gray-500">Bu web sitesinde etkin sorgularla bağlı içerik kümesi bulunamadı. Teknik değerlendirme bundan bağımsızdır.</p>@endforelse
        </section>
    @else
        <p class="text-sm text-gray-500">Web sitesini değerlendirerek standartları, hizmet kapsamını ve iyileştirme gerekçelerini burada görebilirsiniz.</p>
    @endif
</div>
