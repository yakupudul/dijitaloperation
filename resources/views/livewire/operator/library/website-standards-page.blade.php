@php
    $tr = app()->getLocale() === 'tr';
    $admin = auth()->user()->hasRole(\App\Support\Roles::ADMIN);
    $severityLabels = ['high' => $tr ? 'Yüksek' : 'High', 'medium' => $tr ? 'Orta' : 'Medium', 'low' => $tr ? 'Düşük' : 'Low'];
    $evidenceLabels = [
        'stored_html' => 'Saklı HTML', 'authenticated_wordpress_snapshot' => 'WordPress Connector',
        'assessment_url_index' => 'Saklı sayfalar arası karşılaştırma', 'website_http_snapshot' => 'HTTP gözlemi',
        'tls_info' => 'TLS sertifika gözlemi', 'document_head' => 'Sayfa başlık bilgileri',
        'cluster_context' => 'Sorgu kümesi bağlamı', 'connector_delivery' => 'Connector bildirim kaydı',
        'page_inventory' => 'Sayfa envanteri', 'robots_txt' => 'robots.txt', 'sector_pack' => 'Sektör paketi kuralları',
        'connector_status' => 'Connector durumu', 'ga4_source_medium' => 'GA4 kaynak / ortam', 'url_audit' => 'Sayfa Karnesi',
        'gbp_location' => 'İşletme Profili bilgileri', 'gbp_services' => 'Profil hizmetleri', 'gbp_attributes' => 'Profil özellikleri',
        'gbp_media' => 'Profil fotoğrafları', 'gbp_posts' => 'Profil gönderileri', 'gbp_reviews' => 'Profil yorumları',
        'brand' => 'Marka', 'brand_offerings' => 'Marka hizmetleri',
    ];
    $field = 'h-8 rounded-lg border border-gray-200 bg-white px-3 text-[13px] text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
    $small = 'w-full rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-[13px] text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
@endphp
<div class="space-y-10">
    <header>
        <h1 class="page-title">{{ $tr ? 'Standartlar' : 'Standards' }}</h1>
        <p class="page-sub">{{ $tr ? 'Tüm markalar için ortak kontrol kütüphanesi. Değişiklikler sonraki değerlendirmelere uygulanır.' : 'Shared checks for every brand. Changes apply to future assessments.' }}</p>
    </header>
    @if($message)<p role="status" class="tone-ok -mt-4 rounded-lg px-3 py-2 text-sm">{{ $message }}</p>@endif
    @if($errors->any())<div role="alert" class="tone-critical -mt-4 rounded-lg px-3 py-2 text-sm">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <nav class="no-scrollbar -mt-4 flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="{{ $tr ? 'Dijital varlık türü' : 'Asset type' }}">
        @foreach(['website' => $tr ? 'Web sitesi' : 'Website', 'google_business_profile' => $tr ? 'İşletme Profili' : 'Business Profile', 'google_ads' => 'Google Ads', 'meta_ads' => 'Meta Ads'] as $key => $label)
            <button type="button" wire:click="$set('assetType', '{{ $key }}')" @class(['tab', 'tab-active' => $assetType === $key])>{{ $label }}</button>
        @endforeach
    </nav>
    @if(! in_array($assetType, ['website', 'google_business_profile'], true))
        <section>
            <div class="section-head"><h2 class="section-title">{{ $assetType === 'google_ads' ? 'Google Ads' : 'Meta Ads' }}</h2></div>
            <div class="card"><x-ta.empty-state icon="none" :title="$tr ? 'Reklam standartları henüz tanımlanmadı' : 'Advertising checks have not been defined yet'" :message="$tr ? 'Bu kategori hazır; şu an web sitesi standartlarını düzenliyoruz.' : 'This category is reserved.'" /></div>
        </section>
    @else
        <div class="metric-strip grid-cols-2 lg:grid-cols-4">
            @foreach([[$tr ? 'Standart' : 'Standards', $stats['total']], [$tr ? 'Etkin' : 'Enabled', $stats['enabled']], [$tr ? 'Doğrudan kontrol' : 'Direct checks', $stats['verified']], ['WordPress', $stats['wordpress']]] as [$label, $count])
                <x-ta.metric-card :label="$label" :value="(string) $count" />
            @endforeach
        </div>
        <div class="grid gap-10 lg:grid-cols-[220px_minmax(0,1fr)]">
            <aside class="self-start">
                <div class="section-head"><h2 class="section-title">{{ $tr ? 'Kategoriler' : 'Categories' }}</h2></div>
                <div class="card p-1.5">
                    <button type="button" wire:click="$set('group', '')" @class(['flex w-full items-center justify-between gap-2 rounded-lg px-2.5 py-1.5 text-left text-[13px]', 'bg-gray-100 font-medium text-gray-900 dark:bg-white/10 dark:text-white' => $group === '', 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5' => $group !== ''])><span>{{ $tr ? 'Tüm kategoriler' : 'All categories' }}</span><span class="tabular-nums text-gray-500">{{ $stats['total'] }}</span></button>
                    @foreach($groups as $key => $label)
                        <button type="button" wire:click="$set('group', '{{ $key }}')" @class(['mt-0.5 flex w-full items-center justify-between gap-2 rounded-lg px-2.5 py-1.5 text-left text-[13px]', 'bg-gray-100 font-medium text-gray-900 dark:bg-white/10 dark:text-white' => $group === $key, 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5' => $group !== $key])><span>{{ $label }}</span><span class="text-xs tabular-nums text-gray-500">{{ $counts[$key] ?? 0 }}</span></button>
                    @endforeach
                </div>
            </aside>
            <section class="min-w-0 space-y-4">
                <div class="flex flex-wrap gap-2">
                    <label class="min-w-48 flex-1"><span class="sr-only">{{ $tr ? 'Standart ara' : 'Search standards' }}</span><input wire:model.live.debounce.300ms="search" placeholder="{{ $tr ? 'Standart, koşul veya çözüm ara' : 'Search checks or actions' }}" class="{{ $field }} w-full" /></label>
                    <select aria-label="{{ $tr ? 'Altyapı' : 'Platform' }}" wire:model.live="platform" class="{{ $field }} w-full sm:w-auto"><option value="">{{ $tr ? 'Genel + WordPress' : 'General + WordPress' }}</option><option value="general">{{ $tr ? 'Genel web sitesi' : 'General website' }}</option><option value="wordpress">WordPress</option></select>
                    <select aria-label="{{ $tr ? 'Durum' : 'Status' }}" wire:model.live="status" class="{{ $field }} w-full sm:w-auto"><option value="">{{ $tr ? 'Tüm durumlar' : 'All statuses' }}</option><option value="enabled">{{ $tr ? 'Etkin' : 'Enabled' }}</option><option value="disabled">{{ $tr ? 'Devre dışı' : 'Disabled' }}</option></select>
                    @if($search !== '' || $platform !== '' || $group !== '' || $status !== '')<button type="button" wire:click="clearFilters" class="btn-quiet">{{ $tr ? 'Temizle' : 'Clear' }}</button>@endif
                </div>
                <p class="sr-only">{{ $tr ? 'WordPress sitelerinde genel ve WordPress kontrolleri birlikte uygulanır. Veri eksikliği uygunluk sayılmaz. Tavsiye kontrolleri inceleme adayı üretir.' : 'WordPress sites inherit general checks. Missing evidence is not a pass. Advisory checks produce review candidates.' }}</p>
                <div>
                    <div class="section-head">
                        <h2 class="section-title" title="{{ $tr ? 'WordPress sitelerinde genel ve WordPress kontrolleri birlikte uygulanır. Veri eksikliği uygunluk sayılmaz.' : 'WordPress sites inherit general checks. Missing evidence is not a pass.' }}">{{ $tr ? 'Kontroller' : 'Checks' }} <span class="section-count">{{ $standards->total() }} {{ $tr ? 'standart' : 'standards' }}</span></h2>
                    </div>
                    <div class="card">
                    @forelse($standards as $standard)
                        <article wire:key="standard-{{ $standard['id'] }}" class="border-t border-gray-200 px-4 py-4 first:border-t-0 dark:border-gray-800">
                            <div class="flex flex-wrap items-start justify-between gap-3 sm:flex-nowrap">
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs text-gray-500">{{ ($standard['asset_type'] ?? 'website') === 'google_business_profile' ? ($tr ? 'İşletme Profili' : 'Business Profile') : (($standard['platform'] ?? 'general') === 'wordpress' ? 'WordPress' : ($tr ? 'Genel web sitesi' : 'General website')) }}, {{ \MoxDop\Website\Standards\WebsiteStandardCatalog::GROUPS[$standard['group']] }}</p>
                                    <h3 class="mt-0.5 text-sm font-medium text-gray-900 dark:text-white">{{ $standard['title'] }}@if(str_starts_with($standard['id'], \MoxDop\Website\Standards\WebsiteStandardCatalog::DECISION_PREFIX))<span class="ml-2 text-xs font-normal text-gray-500" data-version>sürüm {{ $standard['version'] ?? 1 }}, {{ \App\Services\Site\ScopedStandards::SCOPE_LABELS[$standard['scope_type'] ?? 'general'] ?? 'genel' }}</span>@endif</h3>
                                    <p class="mt-1 text-[13px] leading-relaxed text-gray-600 dark:text-gray-400">{{ $standard['criterion'] }}</p>
                                    @if(str_starts_with($standard['id'], \MoxDop\Website\Standards\WebsiteStandardCatalog::DECISION_PREFIX))
                                        <dl class="mt-2 grid gap-1 text-xs text-gray-600 dark:text-gray-400" data-decision-standard>
                                            <div><dt class="inline font-medium text-gray-900 dark:text-white">Koşul:</dt> <dd class="inline">{{ ($standard['condition'] ?? '') !== '' ? $standard['condition'] : '—' }}</dd></div>
                                            <div><dt class="inline font-medium text-gray-900 dark:text-white">İstisnalar:</dt> <dd class="inline">{{ ($standard['exceptions'] ?? '') !== '' ? $standard['exceptions'] : '—' }}</dd></div>
                                        </dl>
                                        @if($admin && $editingId === $standard['id'])
                                            <form wire:submit="saveVersion" class="mt-3 grid max-w-xl gap-2" data-edit-standard>
                                                <input type="text" wire:model="draft.title" aria-label="Başlık" class="{{ $small }}">
                                                <textarea wire:model="draft.rule" rows="2" aria-label="Kural" class="{{ $small }}"></textarea>
                                                <input type="text" wire:model="draft.condition" placeholder="Koşul" aria-label="Koşul" class="{{ $small }}">
                                                <input type="text" wire:model="draft.exceptions" placeholder="İstisnalar" aria-label="İstisnalar" class="{{ $small }}">
                                                <div class="flex gap-2"><button type="submit" class="btn-primary">Yeni sürüm kaydet</button><button type="button" wire:click="cancelEdit" class="btn-quiet">Vazgeç</button></div>
                                            </form>
                                        @elseif($admin)
                                            <button type="button" wire:click="editStandard('{{ $standard['id'] }}')" class="btn mt-2 h-7! px-2.5! text-xs!">Düzenle</button>
                                        @endif
                                        @if(($standard['history'] ?? []) !== [])
                                            <ul class="mt-2 space-y-0.5 text-xs text-gray-500" data-history>
                                                @foreach($standard['history'] as $old)<li>sürüm {{ $old['version'] }}: {{ $old['title'] }}, {{ \Illuminate\Support\Str::limit($old['rule'], 90) }}</li>@endforeach
                                            </ul>
                                        @endif
                                    @endif
                                </div>
                                @if($admin)
                                    <button type="button" role="switch" aria-checked="{{ $standard['enabled'] ? 'true' : 'false' }}" aria-label="{{ $standard['title'] }}" wire:click="setEnabled('{{ $standard['id'] }}', {{ $standard['enabled'] ? 'false' : 'true' }})" wire:loading.attr="disabled" class="btn h-7! px-2.5! text-xs!"><span class="h-1.5 w-1.5 rounded-full {{ $standard['enabled'] ? 'bg-success-500' : 'bg-gray-300' }}" aria-hidden="true"></span>{{ $standard['enabled'] ? ($tr ? 'Etkin' : 'Enabled') : ($tr ? 'Devre dışı' : 'Disabled') }}</button>
                                @else<x-ta.pill :tone="$standard['enabled'] ? 'ok' : 'info'">{{ $standard['enabled'] ? ($tr ? 'Etkin' : 'Enabled') : ($tr ? 'Devre dışı' : 'Disabled') }}</x-ta.pill>@endif
                            </div>
                            <details class="group mt-2 text-sm text-gray-600 dark:text-gray-400">
                                <summary class="-ml-2.5 inline-flex cursor-pointer list-none items-center gap-1 rounded-lg px-2.5 py-1 text-xs font-medium text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:hover:bg-white/5 dark:hover:text-white">{{ $tr ? 'Kanıt, çözüm ve ayarlar' : 'Evidence, action and settings' }}<svg class="h-3 w-3 transition group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary>
                                <dl class="mt-2 grid gap-4 rounded-lg bg-gray-50 p-4 sm:grid-cols-2 dark:bg-white/[0.03]">
                                    <div><dt class="text-xs text-gray-500">{{ $tr ? 'Kaynak' : 'Source' }}</dt><dd class="mt-0.5 text-[13px] text-gray-900 dark:text-white">{{ implode(', ', array_map(fn ($key) => $evidenceLabels[$key] ?? $key, $standard['required_evidence'])) }}</dd></div>
                                    <div><dt class="text-xs text-gray-500">{{ $tr ? 'Kontrol türü' : 'Check type' }}</dt><dd class="mt-0.5 text-[13px] text-gray-900 dark:text-white">{{ ($standard['informational'] ?? false) ? ($tr ? 'Yalnız bilgi; sorun sayılmaz' : 'Informational; never a defect') : ($standard['classification'] === 'verified' ? ($tr ? 'Doğrudan gözlem' : 'Direct observation') : ($tr ? 'İnceleme tavsiyesi; sıralama garantisi değildir' : 'Advisory review; no ranking guarantee')) }}</dd></div>
                                    <div class="sm:col-span-2"><dt class="text-xs text-gray-500">{{ $tr ? 'Yapılacak işlem' : 'Action' }}</dt><dd class="mt-0.5 text-[13px] text-gray-900 dark:text-white">{{ $standard['action'] }}</dd></div>
                                    <div class="sm:col-span-2"><dt class="text-xs text-gray-500">{{ $tr ? 'Doğrulama' : 'Verification' }}</dt><dd class="mt-0.5 text-[13px] text-gray-900 dark:text-white">{{ $standard['verification'] }}</dd></div>
                                    <div class="flex flex-wrap items-center gap-3 text-xs sm:col-span-2">
                                        <span class="text-gray-500">{{ $tr ? 'Önem:' : 'Severity:' }}</span>
                                        @if($admin)
                                            <select aria-label="{{ $tr ? 'Önem derecesi' : 'Severity' }}" wire:change="setSeverity('{{ $standard['id'] }}', $event.target.value)" wire:loading.attr="disabled" class="h-7 rounded-lg border border-gray-200 bg-white px-2 text-xs dark:border-gray-700 dark:bg-gray-900">@foreach($severityLabels as $key => $label)<option value="{{ $key }}" @selected($standard['severity'] === $key)>{{ $label }}</option>@endforeach</select>
                                            <button type="button" wire:click="resetStandard('{{ $standard['id'] }}')" wire:confirm="{{ $tr ? 'Bu standardın tüm markalarda geçerli etkinlik ve önem ayarları varsayılana döndürülsün mü?' : 'Restore this standard’s default settings for all brands?' }}" wire:loading.attr="disabled" class="btn-quiet h-7! px-2! text-xs!">{{ $tr ? 'Varsayılana dön' : 'Restore defaults' }}</button>
                                        @else<span class="text-gray-900 dark:text-white">{{ $severityLabels[$standard['severity']] ?? $standard['severity'] }}</span>@endif
                                        @if($standard['source_url'])<a href="{{ $standard['source_url'] }}" target="_blank" rel="noopener noreferrer" class="link-neutral">{{ $tr ? 'Referans kaynağı' : 'Reference' }}</a>@if(! empty($standard['source_reviewed_at']))<span class="text-gray-500">{{ $tr ? 'kontrol:' : 'checked:' }} {{ $standard['source_reviewed_at'] }}</span>@endif @endif
                                    </div>
                                </dl>
                            </details>
                        </article>
                    @empty<x-ta.empty-state icon="none" size="sm" :title="$tr ? 'Bu filtrelere uygun standart bulunamadı.' : 'No matching standards.'" />@endforelse
                    </div>
                </div>
                {{ $standards->links() }}
            </section>
        </div>
    @endif
</div>
