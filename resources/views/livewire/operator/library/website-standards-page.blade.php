@php
    $tr = app()->getLocale() === 'tr';
    $admin = auth()->user()->hasRole(\App\Support\Roles::ADMIN);
    $severityLabels = ['high' => $tr ? 'Yüksek' : 'High', 'medium' => $tr ? 'Orta' : 'Medium', 'low' => $tr ? 'Düşük' : 'Low'];
    $evidenceLabels = [
        'stored_html' => 'Saklı HTML', 'authenticated_wordpress_snapshot' => 'WordPress Connector',
        'assessment_url_index' => 'Saklı sayfalar arası karşılaştırma', 'website_http_snapshot' => 'HTTP gözlemi',
        'tls_info' => 'TLS sertifika gözlemi', 'document_head' => 'Sayfa başlık bilgileri',
        'cluster_context' => 'Sorgu kümesi bağlamı', 'connector_delivery' => 'Connector bildirim kaydı',
    ];
@endphp
<div class="space-y-5">
    <header>
        <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ $tr ? 'Standartlar' : 'Standards' }}</h1>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $tr ? 'Tüm markalar için ortak kontrol kütüphanesi. Değişiklikler sonraki değerlendirmelere uygulanır.' : 'Shared checks for every brand. Changes apply to future assessments.' }}</p>
    </header>
    @if($message)<p role="status" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>@endif
    @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <nav class="flex gap-6 border-b border-gray-200 dark:border-gray-800" aria-label="{{ $tr ? 'Dijital varlık türü' : 'Asset type' }}">
        @foreach(['website' => $tr ? 'Web sitesi' : 'Website', 'google_ads' => 'Google Ads', 'meta_ads' => 'Meta Ads'] as $key => $label)
            <button type="button" wire:click="$set('assetType', '{{ $key }}')" @class(['border-b-2 px-1 pb-3 text-sm font-medium', 'border-brand-500 text-brand-600' => $assetType === $key, 'border-transparent text-gray-500' => $assetType !== $key])>{{ $label }}</button>
        @endforeach
    </nav>
    @if($assetType !== 'website')
        <section class="rounded-xl border border-gray-200 bg-white p-8 dark:border-gray-800 dark:bg-gray-900">
            <h2 class="font-semibold text-gray-900 dark:text-white">{{ $assetType === 'google_ads' ? 'Google Ads' : 'Meta Ads' }}</h2>
            <p class="mt-2 text-sm text-gray-500">{{ $tr ? 'Bu kategori hazır. Reklam standartları henüz tanımlanmadı; şu an web sitesi standartlarını düzenliyoruz.' : 'This category is reserved. Advertising checks have not been defined yet.' }}</p>
        </section>
    @else
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            @foreach([[$tr ? 'Standart' : 'Standards', $stats['total']], [$tr ? 'Etkin' : 'Enabled', $stats['enabled']], [$tr ? 'Doğrudan kontrol' : 'Direct checks', $stats['verified']], ['WordPress', $stats['wordpress']]] as [$label, $count])
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900"><p class="text-xs text-gray-500">{{ $label }}</p><p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $count }}</p></div>
            @endforeach
        </div>
        <div class="grid gap-5 lg:grid-cols-[220px_minmax(0,1fr)]">
            <aside class="self-start rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
                <button type="button" wire:click="$set('group', '')" @class(['flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm', 'bg-brand-50 text-brand-600 dark:bg-brand-500/10' => $group === '', 'text-gray-600 dark:text-gray-300' => $group !== ''])><span>{{ $tr ? 'Tüm kategoriler' : 'All categories' }}</span><span>{{ $stats['total'] }}</span></button>
                @foreach($groups as $key => $label)
                    <button type="button" wire:click="$set('group', '{{ $key }}')" @class(['mt-1 flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm', 'bg-brand-50 text-brand-600 dark:bg-brand-500/10' => $group === $key, 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-800' => $group !== $key])><span>{{ $label }}</span><span class="text-xs">{{ $counts[$key] ?? 0 }}</span></button>
                @endforeach
            </aside>
            <section class="min-w-0 space-y-4">
                <div class="flex flex-wrap gap-2">
                    <label class="min-w-48 flex-1"><span class="sr-only">{{ $tr ? 'Standart ara' : 'Search standards' }}</span><input wire:model.live.debounce.300ms="search" placeholder="{{ $tr ? 'Standart, koşul veya çözüm ara…' : 'Search checks or actions…' }}" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white" /></label>
                    <select aria-label="{{ $tr ? 'Altyapı' : 'Platform' }}" wire:model.live="platform" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white"><option value="">{{ $tr ? 'Genel + WordPress' : 'General + WordPress' }}</option><option value="general">{{ $tr ? 'Genel web sitesi' : 'General website' }}</option><option value="wordpress">WordPress</option></select>
                    <select aria-label="{{ $tr ? 'Durum' : 'Status' }}" wire:model.live="status" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white"><option value="">{{ $tr ? 'Tüm durumlar' : 'All statuses' }}</option><option value="enabled">{{ $tr ? 'Etkin' : 'Enabled' }}</option><option value="disabled">{{ $tr ? 'Devre dışı' : 'Disabled' }}</option></select>
                    @if($search !== '' || $platform !== '' || $group !== '' || $status !== '')<button type="button" wire:click="clearFilters" class="px-2 text-sm text-brand-600">{{ $tr ? 'Temizle' : 'Clear' }}</button>@endif
                </div>
                <p class="text-xs leading-5 text-gray-500">{{ $tr ? 'WordPress sitelerinde genel ve WordPress kontrolleri birlikte uygulanır. Veri eksikliği uygunluk sayılmaz. Tavsiye kontrolleri inceleme adayı üretir.' : 'WordPress sites inherit general checks. Missing evidence is not a pass. Advisory checks produce review candidates.' }}</p>
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                    <div class="border-b border-gray-100 px-5 py-3 text-xs text-gray-500 dark:border-gray-800">{{ $standards->total() }} {{ $tr ? 'standart' : 'standards' }}</div>
                    @forelse($standards as $standard)
                        <article wire:key="standard-{{ $standard['id'] }}" class="border-b border-gray-100 px-5 py-4 last:border-0 dark:border-gray-800">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs text-gray-500">{{ ($standard['platform'] ?? 'general') === 'wordpress' ? 'WordPress' : ($tr ? 'Genel web sitesi' : 'General website') }} · {{ \MoxDop\Website\Standards\WebsiteStandardCatalog::GROUPS[$standard['group']] }}</p>
                                    <h2 class="mt-1 font-semibold text-gray-900 dark:text-white">{{ $standard['title'] }}</h2>
                                    <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-400">{{ $standard['criterion'] }}</p>
                                </div>
                                @if($admin)
                                    <button type="button" role="switch" aria-checked="{{ $standard['enabled'] ? 'true' : 'false' }}" aria-label="{{ $standard['title'] }}" wire:click="setEnabled('{{ $standard['id'] }}', {{ $standard['enabled'] ? 'false' : 'true' }})" wire:loading.attr="disabled" class="rounded-full border px-3 py-1.5 text-xs font-medium {{ $standard['enabled'] ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300' : 'border-gray-300 text-gray-500 dark:border-gray-700' }}">{{ $standard['enabled'] ? ($tr ? 'Etkin' : 'Enabled') : ($tr ? 'Devre dışı' : 'Disabled') }}</button>
                                @else<span class="text-xs text-gray-500">{{ $standard['enabled'] ? ($tr ? 'Etkin' : 'Enabled') : ($tr ? 'Devre dışı' : 'Disabled') }}</span>@endif
                            </div>
                            <details class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                                <summary class="cursor-pointer text-xs font-medium text-brand-600">{{ $tr ? 'Kanıt, çözüm ve ayarlar' : 'Evidence, action and settings' }}</summary>
                                <dl class="mt-3 grid gap-4 rounded-lg bg-gray-50 p-4 sm:grid-cols-2 dark:bg-gray-800">
                                    <div><dt class="text-xs font-semibold">{{ $tr ? 'Kaynak' : 'Source' }}</dt><dd class="mt-1 text-xs">{{ implode(', ', array_map(fn ($key) => $evidenceLabels[$key] ?? $key, $standard['required_evidence'])) }}</dd></div>
                                    <div><dt class="text-xs font-semibold">{{ $tr ? 'Kontrol türü' : 'Check type' }}</dt><dd class="mt-1 text-xs">{{ $standard['classification'] === 'verified' ? ($tr ? 'Doğrudan gözlem' : 'Direct observation') : ($tr ? 'İnceleme tavsiyesi; sıralama garantisi değildir' : 'Advisory review; no ranking guarantee') }}</dd></div>
                                    <div class="sm:col-span-2"><dt class="text-xs font-semibold">{{ $tr ? 'Yapılacak işlem' : 'Action' }}</dt><dd class="mt-1">{{ $standard['action'] }}</dd></div>
                                    <div class="sm:col-span-2"><dt class="text-xs font-semibold">{{ $tr ? 'Doğrulama' : 'Verification' }}</dt><dd class="mt-1 text-xs">{{ $standard['verification'] }}</dd></div>
                                </dl>
                                <div class="mt-3 flex flex-wrap items-center gap-3 text-xs">
                                    <span>{{ $tr ? 'Önem:' : 'Severity:' }}</span>
                                    @if($admin)
                                        <select aria-label="{{ $tr ? 'Önem derecesi' : 'Severity' }}" wire:change="setSeverity('{{ $standard['id'] }}', $event.target.value)" wire:loading.attr="disabled" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900">@foreach($severityLabels as $key => $label)<option value="{{ $key }}" @selected($standard['severity'] === $key)>{{ $label }}</option>@endforeach</select>
                                        <button type="button" wire:click="resetStandard('{{ $standard['id'] }}')" wire:confirm="{{ $tr ? 'Bu standardın tüm markalarda geçerli etkinlik ve önem ayarları varsayılana döndürülsün mü?' : 'Restore this standard’s default settings for all brands?' }}" wire:loading.attr="disabled" class="text-gray-500 underline">{{ $tr ? 'Varsayılana dön' : 'Restore defaults' }}</button>
                                    @else<span>{{ $severityLabels[$standard['severity']] ?? $standard['severity'] }}</span>@endif
                                    @if($standard['source_url'])<a href="{{ $standard['source_url'] }}" target="_blank" rel="noopener noreferrer" class="text-brand-600 underline">{{ $tr ? 'Referans kaynağı' : 'Reference' }}</a>@endif
                                </div>
                            </details>
                        </article>
                    @empty<p class="p-8 text-center text-sm text-gray-500">{{ $tr ? 'Bu filtrelere uygun standart bulunamadı.' : 'No matching standards.' }}</p>@endforelse
                </div>
                {{ $standards->links() }}
            </section>
        </div>
    @endif
</div>
