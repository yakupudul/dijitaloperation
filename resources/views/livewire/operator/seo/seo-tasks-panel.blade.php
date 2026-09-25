@php
    use App\Enums\SeoTaskStatus;
    use App\Enums\SeoTaskType;
    use Illuminate\Support\Str;

    $typeColor = fn (SeoTaskType $type): string => match ($type) {
        SeoTaskType::Fix => 'error',
        SeoTaskType::Strengthen => 'warning',
        SeoTaskType::Create => 'success',
        SeoTaskType::AiVisibility => 'info',
        SeoTaskType::Question => 'light',
    };
    $typeAccent = fn (SeoTaskType $type): string => match ($type) {
        SeoTaskType::Fix => 'border-l-error-500',
        SeoTaskType::Strengthen => 'border-l-warning-500',
        SeoTaskType::Create => 'border-l-success-500',
        SeoTaskType::AiVisibility => 'border-l-blue-500',
        SeoTaskType::Question => 'border-l-gray-300',
    };
    $typeHelp = [
        '' => 'Etkiye göre sıralı: en üstteki, bu hafta en çok karşılık verecek iş.',
        SeoTaskType::Fix->value => 'Teknik sorunlar: sayfanın dizine girmesini veya sıralanmasını engelleyenler. Önce bunlar.',
        SeoTaskType::Strengthen->value => 'Zaten gösterim alan sayfalar: az çabayla daha çok tıklama.',
        SeoTaskType::Create->value => 'Yazılacak yeni içerikler. Her kartta yazara verilecek brief hazır.',
        SeoTaskType::AiVisibility->value => 'ChatGPT, Gemini, Perplexity gibi AI aramalarında görünmek için.',
    ];
    $planStates = ['queued' => 'Kuyrukta', 'running' => 'Kuruluyor', 'completed' => 'Hazır', 'failed' => 'Başarısız'];
    $tz = config('app.timezone');
    $listTypes = array_values(array_filter($types, fn (SeoTaskType $type): bool => $type !== SeoTaskType::Question));
    $understanding = $latestPlan ? data_get($latestPlan->input_summary, 'site_understanding') : null;
    $plansPending = $plansPending ?? 0;
    $polling = $pendingPlan !== null || $plansPending > 0 || ($draftsPending ?? 0) > 0;
    $days = [1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 0 => 'Pazar', 7 => 'Pazar'];
    $scheduleText = ($days[(int) config('moxdop-seo-tasks.schedule.weekly_day', 1)] ?? 'Pazartesi').' '.config('moxdop-seo-tasks.schedule.weekly_time', '06:30');
    $card = 'rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]';
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white shadow-theme-xs hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50';
    $btnSecondary = 'inline-flex items-center justify-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.06]';
    $select = 'h-10 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $spinner = '<svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>';
    $refreshIcon = '<svg class="size-4" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M16.5 10a6.5 6.5 0 1 1-1.9-4.6M16.5 3.5v3.3h-3.3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
@endphp
<div class="space-y-6" @if($polling) wire:poll.5s @endif>

    {{-- 1. Plan bar: what the plan is, when it was built, how to rebuild it --}}
    @if ($site)
        <section class="{{ $card }} p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">SEO planı</p>
                    <h2 class="mt-0.5 text-lg font-semibold text-gray-900 dark:text-white">{{ $site->domain ?: $site->name }}</h2>
                    @if ($latestPlan)
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            Son plan {{ $latestPlan->completed_at?->timezone($tz)->locale('tr')->diffForHumans() }} ({{ $latestPlan->completed_at?->timezone($tz)->format('d.m.Y H:i') }}) · {{ $latestPlan->summary_text }}
                        </p>
                        <div class="mt-3 flex flex-wrap gap-2 text-xs">
                            @php
                                $gscOk = data_get($latestPlan->input_summary, 'gsc.available') === true;
                                $sources = [
                                    ['Search Console', $gscOk, $gscOk ? number_format((int) data_get($latestPlan->input_summary, 'gsc.query_count', 0)).' sorgu' : 'veri yok'],
                                    ['Sayfa envanteri', (int) data_get($latestPlan->input_summary, 'pages', 0) > 0, number_format((int) data_get($latestPlan->input_summary, 'pages', 0)).' sayfa'],
                                    ['Saklı HTML', (int) data_get($latestPlan->input_summary, 'html.read', 0) > 0, (int) data_get($latestPlan->input_summary, 'html.read', 0).' sayfa okundu'],
                                    ['GA4', (bool) data_get($latestPlan->input_summary, 'ga4_available'), data_get($latestPlan->input_summary, 'ga4_available') ? 'bağlı' : 'veri yok'],
                                    ['AI', data_get($latestPlan->llm_summary, 'applied', 0) > 0, data_get($latestPlan->llm_summary, 'applied', 0) > 0 ? data_get($latestPlan->llm_summary, 'applied').' görev zenginleşti' : 'kullanılmadı ('.(data_get($latestPlan->llm_summary, 'skipped_reason') ?? '—').')'],
                                ];
                            @endphp
                            @foreach ($sources as [$label, $ok, $detail])
                                <span @class([
                                    'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 ring-1 ring-inset',
                                    'bg-success-50 text-success-700 ring-success-200 dark:bg-success-500/10 dark:text-success-400 dark:ring-success-500/20' => $ok,
                                    'bg-gray-50 text-gray-500 ring-gray-200 dark:bg-white/5 dark:text-gray-400 dark:ring-gray-700' => ! $ok,
                                ])><span @class(['size-1.5 rounded-full', 'bg-success-500' => $ok, 'bg-gray-300 dark:bg-gray-600' => ! $ok])></span>{{ $label }}: {{ $detail }}</span>
                            @endforeach
                        </div>
                        @if (data_get($latestPlan->input_summary, 'html.excluded_non_documents', 0) > 0)
                            <p class="mt-2 text-xs text-gray-400">{{ number_format((int) data_get($latestPlan->input_summary, 'html.excluded_non_documents')) }} HTML olmayan adres (feed, sitemap, robots, medya) değerlendirme dışı bırakıldı.</p>
                        @endif
                    @else
                        <p class="mt-1 text-sm text-gray-500">Bu site için henüz plan kurulmadı. "Planı yenile"ye bas; plan arka planda 1–3 dakikada kurulur.</p>
                    @endif
                    @if ($pendingPlan)
                        <p class="mt-3 inline-flex items-center gap-2 rounded-lg bg-brand-50 px-3 py-2 text-sm font-medium text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">{!! $spinner !!} Plan #{{ $pendingPlan->version }} {{ mb_strtolower($planStates[$pendingPlan->status] ?? $pendingPlan->status) }}… Sayfa kendini günceller; kapatabilirsin.</p>
                    @endif
                </div>
                <div class="flex flex-col items-end gap-1">
                    <button type="button" wire:click="refreshPlan" wire:loading.attr="disabled" @disabled($pendingPlan !== null) class="{{ $btnPrimary }}">{!! $refreshIcon !!} Planı yenile</button>
                    <button type="button" wire:click="refreshPlanWithAi" wire:confirm="Plan yeniden kurulacak ve içerik briefleri AI ile yazılacak (1–2 AI çağrısı, bütçeden düşer). Devam edilsin mi?" wire:loading.attr="disabled" @disabled($pendingPlan !== null) class="{{ $btnSecondary }}" title="Yalnız bu buton AI kullanır">✨ Briefleri AI ile hazırla</button>
                    <span class="text-xs text-gray-400">Otomatik: her {{ $scheduleText }}</span>
                </div>
            </div>
        </section>
    @else
        <section class="{{ $card }} p-5" x-data="{ help: false }">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">Haftalık plan</h2>
                    @if ($plansPending > 0)
                        <p class="mt-1 inline-flex items-center gap-2 text-sm font-medium text-brand-600 dark:text-brand-400">{!! $spinner !!} {{ $plansPending }} site için plan kuruluyor. Sayfa kendini günceller; kapatabilirsin.</p>
                    @else
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Planlar her {{ $scheduleText }} otomatik kurulur.
                            @if ($lastPlanAt) Son kurulum {{ $lastPlanAt->timezone($tz)->locale('tr')->diffForHumans() }}. @endif
                            Veri değiştiyse (yeni hizmet, eşleştirme, Search Console) hemen yenileyebilirsin.
                        </p>
                    @endif
                    <button type="button" x-on:click="help = ! help" class="mt-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400"><span x-text="help ? 'Gizle' : 'Planı yenile ne yapar?'">Planı yenile ne yapar?</span></button>
                </div>
                <button type="button" wire:click="refreshAll" wire:confirm="Tüm aktif web siteleri için plan yeniden kurulsun mu? İşlem arka planda çalışır." wire:loading.attr="disabled" @disabled($plansPending > 0) class="{{ $btnPrimary }}">{!! $refreshIcon !!} Tüm planları yenile</button>
            </div>
            <ol x-show="help" x-cloak class="mt-4 grid gap-3 border-t border-gray-100 pt-4 text-sm text-gray-600 sm:grid-cols-3 dark:border-gray-800 dark:text-gray-300">
                <li class="flex gap-3"><span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-600 dark:bg-brand-500/10">1</span><span>Search Console, sayfa taraması, GA4 ve markanın hizmetleri yeniden okunur. Dışarıya hiçbir şey yazılmaz.</span></li>
                <li class="flex gap-3"><span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-600 dark:bg-brand-500/10">2</span><span>Kurallar görevleri etkiye göre sıralar; önemli sayfalar için Google dizin kontrolü istenir (sonucu bir sonraki planda görünür).</span></li>
                <li class="flex gap-3"><span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-600 dark:bg-brand-500/10">3</span><span>Yapıldı / Atla dediğin görevler korunur; çözülen sorunlar listeden kendiliğinden düşer.</span></li>
            </ol>
        </section>
    @endif

    @if ($message !== '')
        <div role="status" @class([
            'flex items-start gap-2 rounded-xl border p-3 text-sm',
            'border-success-200 bg-success-50 text-success-800 dark:border-success-500/20 dark:bg-success-500/10 dark:text-success-300' => $messageTone === 'success',
            'border-error-200 bg-error-50 text-error-700 dark:border-error-500/20 dark:bg-error-500/10 dark:text-error-300' => $messageTone === 'error',
        ])>
            <span class="flex-1">{{ $message }}</span>
            <button type="button" wire:click="$set('message', '')" class="text-xs opacity-60 hover:opacity-100" aria-label="Kapat">✕</button>
        </div>
    @endif

    {{-- 2. KPI strip: four questions an operator asks on Monday --}}
    @php
        $contentPct = $kpis['content_target'] > 0 ? min(100, (int) round($kpis['content'] / $kpis['content_target'] * 100)) : 0;
    @endphp
    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <button type="button" wire:click="$set('typeFilter', '{{ SeoTaskType::Create->value }}')" class="{{ $card }} p-4 text-left transition hover:border-success-300">
            <p class="text-xs font-medium text-gray-500">Bu haftanın içerik önerileri</p>
            <p class="mt-2 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $kpis['content'] }}<span class="text-sm font-medium text-gray-400"> / hedef {{ $kpis['content_target'] }}</span></p>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800"><div class="h-full rounded-full bg-success-500" style="width: {{ $contentPct }}%"></div></div>
            <p class="mt-2 text-xs text-gray-400">Site başına haftada en az {{ config('moxdop-seo-tasks.create.min_per_site', 4) }} içerik</p>
        </button>
        <div class="{{ $card }} p-4">
            <p class="text-xs font-medium text-gray-500">Tahmini ek tıklama</p>
            <p class="mt-2 text-2xl font-bold text-success-600 dark:text-success-400">+{{ number_format($kpis['extra_clicks']) }}</p>
            <p class="mt-2 text-xs text-gray-400">90 günde, açık Güçlendir + Oluştur görevleri yapılırsa</p>
        </div>
        <button type="button" wire:click="$set('typeFilter', '{{ SeoTaskType::Fix->value }}')" class="{{ $card }} p-4 text-left transition hover:border-error-300">
            <p class="text-xs font-medium text-gray-500">Kritik / yüksek teknik sorun</p>
            <p @class(['mt-2 text-2xl font-bold', 'text-error-600 dark:text-error-400' => $kpis['critical_fixes'] > 0, 'text-gray-800 dark:text-white/90' => $kpis['critical_fixes'] === 0])>{{ $kpis['critical_fixes'] }}</p>
            <p class="mt-2 text-xs text-gray-400">{{ $kpis['critical_fixes'] > 0 ? 'Önce bunlar: sıralamayı doğrudan etkiler' : 'Acil teknik sorun yok' }}</p>
        </button>
        <div class="{{ $card }} p-4">
            <p class="text-xs font-medium text-gray-500">Kurulum bekleyen</p>
            <p @class(['mt-2 text-2xl font-bold', 'text-warning-600 dark:text-warning-400' => $kpis['pending_mappings'] > 0, 'text-gray-800 dark:text-white/90' => $kpis['pending_mappings'] === 0])>{{ $kpis['pending_mappings'] }}</p>
            <p class="mt-2 text-xs text-gray-400">{{ $kpis['pending_mappings'] > 0 ? 'Sayfası eşleşmemiş öncelikli hizmet' : 'Hizmet ↔ sayfa eşleşmeleri tamam' }}</p>
        </div>
    </div>

    {{-- 3. Setup: answers the plan needs (one card per site, not one card per service) --}}
    @if ($setupTasks->isNotEmpty())
        <div class="space-y-3">
            <div class="flex items-center gap-2">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Önce bunları yanıtla</h3>
                <x-ta.badge color="warning" size="sm">{{ $setupTasks->count() }}</x-ta.badge>
                <span class="text-xs text-gray-400">Cevaplar bir sonraki planı daha isabetli yapar.</span>
            </div>
            @foreach ($setupTasks as $setup)
                @if ($setup->rule_id === 'out-of-area-demand')
                    <section wire:key="setup-{{ $setup->id }}" class="rounded-xl border border-warning-200 bg-warning-50 p-5 dark:border-warning-500/20 dark:bg-warning-500/10">
                        <h3 class="text-sm font-semibold text-warning-900 dark:text-warning-200">@if (! $site && $setup->digitalAsset){{ $setup->digitalAsset->domain }} · @endif{{ $setup->title }}</h3>
                        <p class="mt-1 max-w-3xl text-xs text-warning-800 dark:text-warning-300">{{ $setup->reason }}</p>
                        <ul class="mt-2 space-y-1 text-xs text-warning-900 dark:text-warning-200">
                            @foreach ($setup->evidence['locations'] ?? [] as $place)
                                <li><strong>{{ $place['name'] }}</strong> · {{ number_format($place['impressions']) }} gösterim · ör. {{ implode(', ', array_slice(array_column($place['queries'], 'query'), 0, 3)) }}</li>
                            @endforeach
                        </ul>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @if ($setup->digitalAsset?->brand_id)
                                <a wire:navigate href="{{ route('operator.brand.edit', ['brandId' => $setup->digitalAsset->brand_id]) }}" class="rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-warning-800 ring-1 ring-inset ring-warning-300 hover:bg-warning-100 dark:bg-gray-900 dark:text-warning-300">Hizmet verdiği yerlere ekle →</a>
                            @endif
                            <button type="button" wire:click="skip({{ $setup->id }})" class="rounded-lg bg-white px-3 py-1.5 text-xs ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">Hizmet vermiyorum</button>
                        </div>
                    </section>
                    @continue
                @endif
                @php
                    $services = $setup->evidence['services'] ?? null;
                    $legacy = $services === null;
                @endphp
                <section wire:key="setup-{{ $setup->id }}" class="rounded-xl border border-warning-200 bg-warning-50 p-5 dark:border-warning-500/20 dark:bg-warning-500/10">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-warning-900 dark:text-warning-200">
                                @if (! $site && $setup->digitalAsset){{ $setup->digitalAsset->domain }} · @endif{{ $setup->title }}
                            </h3>
                            <p class="mt-1 max-w-3xl text-xs text-warning-800 dark:text-warning-300">{{ $setup->reason }}</p>
                        </div>
                        @if (! $site)
                            <a wire:navigate href="{{ route('operator.website', ['assetId' => $setup->digital_asset_id, 'tab' => 'seo']) }}" class="rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-warning-800 ring-1 ring-inset ring-warning-300 hover:bg-warning-100 dark:bg-gray-900 dark:text-warning-300">Eşleştir →</a>
                        @endif
                    </div>
                    @if ($site && ! $legacy)
                        <div class="mt-3 space-y-2">
                            @foreach ($services as $service)
                                <div wire:key="map-{{ $setup->id }}-{{ $service['offering_id'] }}" class="flex flex-wrap items-center gap-2 rounded-lg bg-white px-3 py-2 dark:bg-gray-900">
                                    <span class="w-48 shrink-0 text-sm font-medium text-gray-800 dark:text-white/90">★ {{ $service['name'] }}</span>
                                    <select wire:model="mapping.{{ $service['offering_id'] }}" class="min-w-0 flex-1 rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900">
                                        <option value="">Sayfa seç…</option>
                                        @foreach ($service['candidates'] ?? [] as $candidate)
                                            <option value="{{ $candidate['url'] }}">{{ Str::limit($candidate['title'] ?: $candidate['url'], 60) }} — {{ Str::limit(parse_url($candidate['url'], PHP_URL_PATH) ?: '/', 50) }} ({{ (int) round(($candidate['score'] ?? 0) * 100) }}%)</option>
                                        @endforeach
                                        <option value="none">Bu hizmetin sayfası yok</option>
                                    </select>
                                    <button type="button" wire:click="answerMapping({{ $setup->id }}, {{ $service['offering_id'] }})" class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600">Kaydet</button>
                                </div>
                            @endforeach
                        </div>
                    @elseif ($site && $legacy)
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($setup->evidence['candidates'] ?? [] as $candidate)
                                <button type="button" wire:click="answerQuestion({{ $setup->id }}, @js($candidate['url']))" class="rounded-lg bg-white px-3 py-2 text-left text-xs ring-1 ring-inset ring-gray-200 hover:ring-brand-500 dark:bg-gray-800 dark:ring-gray-700">{{ Str::limit($candidate['title'] ?? $candidate['url'], 60) }}</button>
                            @endforeach
                            <button type="button" wire:click="answerQuestion({{ $setup->id }}, 'none')" class="rounded-lg bg-white px-3 py-2 text-xs ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">Sayfası yok</button>
                        </div>
                    @endif
                </section>
            @endforeach

        </div>
    @endif

    {{-- 4. Site understanding (brand without matching services) --}}
    @if ($site && is_array($understanding) && ! empty($understanding['services']))
        <section class="rounded-xl border border-blue-200 bg-blue-50 p-5 dark:border-blue-500/20 dark:bg-blue-500/10">
            <h3 class="text-sm font-semibold text-blue-900 dark:text-blue-200">
                {{ ($understanding['reason'] ?? '') === 'brand_services_not_on_site' ? 'Markanın hizmetleri bu siteyle örtüşmüyor — site kendi verisinden anlaşıldı' : 'Markada hizmet tanımlı değil — siteden çıkarıldı' }}
            </h3>
            <p class="mt-1 text-xs text-blue-800 dark:text-blue-300">
                Kaynak: {{ match ($understanding['source_detail'] ?? $understanding['source'] ?? '') { 'ai' => 'AI (sayfalar + Search Console + GA4)', 'rules' => 'en çok gösterim alan sayfalar (AI kullanılamadı)', default => $understanding['source'] ?? '' } }}.
                Bu hizmetler görevleri üretmek için kullanıldı; markaya kaydedilmedi. Doğru olanları "Markaya ekle" ile kalıcı yap.
                @if (($understanding['reason'] ?? '') === 'brand_services_not_on_site') Site başka bir markaya aitse varlığı doğru markaya taşı. @endif
            </p>
            @if (! empty($understanding['brand_summary']))
                <p class="mt-2 text-sm text-gray-800 dark:text-gray-200">{{ $understanding['brand_summary'] }}@if (! empty($understanding['audience'])) · <span class="text-gray-600 dark:text-gray-400">Hedef kitle: {{ $understanding['audience'] }}</span>@endif</p>
            @endif
            <ul class="mt-3 space-y-2">
                @foreach ($understanding['services'] as $serviceIndex => $service)
                    <li wire:key="inferred-service-{{ $serviceIndex }}" class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-white px-3 py-2 text-sm dark:bg-gray-900">
                        <div class="min-w-0">
                            <span class="font-medium text-gray-800 dark:text-white/90">@if (! empty($service['is_core']))★ @endif{{ $service['name'] }}</span>
                            @if (! empty($service['page_url']))<span class="ml-2 text-xs text-gray-500">{{ Str::limit($service['page_url'], 60) }}</span>@endif
                            <span class="ml-2 text-xs text-gray-400">{{ count($service['queries'] ?? []) }} sorgu</span>
                        </div>
                        <button type="button" wire:click="adoptService({{ $serviceIndex }})" class="rounded-lg bg-white px-3 py-1.5 text-xs font-medium text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:bg-gray-800 dark:ring-brand-500/30">Markaya ekle</button>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
    {{-- 5. Global: site table with live plan state --}}
    @if (! $site && $siteOverview->isNotEmpty())
        <section class="{{ $card }} overflow-hidden">
            <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-3 dark:border-gray-800">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Siteler</h3>
                <span class="text-xs text-gray-400">Satıra tıkla: yalnızca o sitenin görevleri</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead class="bg-gray-50 text-xs font-medium text-gray-500 dark:bg-white/[0.02] dark:text-gray-400">
                        <tr>
                            <th class="px-5 py-2.5">Site</th>
                            <th class="px-3 py-2.5">Plan</th>
                            <th class="px-3 py-2.5">İçerik önerisi</th>
                            <th class="px-3 py-2.5 text-center">Kritik/yüksek</th>
                            <th class="px-3 py-2.5 text-center">Açık görev</th>
                            <th class="px-3 py-2.5 text-right">Tahmini ek tık</th>
                            <th class="px-5 py-2.5"><span class="sr-only">İşlem</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-gray-700 dark:divide-gray-800 dark:text-gray-300">
                        @foreach ($siteOverview as $row)
                            @php
                                $selected = $siteFilter === (string) $row['id'];
                                $busy = in_array($row['plan_status'], ['queued', 'running'], true);
                                $pct = $row['target'] > 0 ? min(100, (int) round($row['content'] / $row['target'] * 100)) : 0;
                            @endphp
                            <tr wire:key="site-row-{{ $row['id'] }}" @class(['transition hover:bg-gray-50 dark:hover:bg-white/[0.02]', 'bg-brand-25 dark:bg-brand-500/5' => $selected])>
                                <td class="px-5 py-3">
                                    <button type="button" wire:click="$set('siteFilter', '{{ $selected ? '' : $row['id'] }}')" class="text-left">
                                        <span class="block font-medium text-gray-800 hover:text-brand-600 dark:text-white/90">{{ $row['domain'] }}</span>
                                        <span class="block text-xs text-gray-400">{{ $row['brand'] }}@if ($row['gsc'] === false) · Search Console verisi yok @endif @if ($row['mappings']) · <span class="text-warning-600">{{ $row['mappings'] }} hizmet eşleşmeyi bekliyor</span>@endif</span>
                                    </button>
                                </td>
                                <td class="px-3 py-3">
                                    @if ($busy)
                                        <span class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-600 dark:text-brand-400">{!! $spinner !!} {{ $planStates[$row['plan_status']] }}</span>
                                    @elseif ($row['plan_status'] === 'failed')
                                        <x-ta.badge color="error" size="sm" title="{{ $row['plan_error'] }}">Başarısız</x-ta.badge>
                                        @if ($row['last_plan_at'])<span class="mt-0.5 block text-xs text-gray-400">önceki: {{ $row['last_plan_at']->timezone($tz)->locale('tr')->diffForHumans() }}</span>@endif
                                    @elseif ($row['last_plan_at'])
                                        <span class="text-xs text-gray-600 dark:text-gray-300" title="{{ $row['last_plan_at']->timezone($tz)->format('d.m.Y H:i') }}">{{ $row['last_plan_at']->timezone($tz)->locale('tr')->diffForHumans() }}</span>
                                    @else
                                        <span class="text-xs text-gray-400">Plan yok</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 w-16 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800"><div @class(['h-full rounded-full', 'bg-success-500' => $pct >= 100, 'bg-warning-500' => $pct < 100]) style="width: {{ $pct }}%"></div></div>
                                        <span class="text-xs">{{ $row['content'] }} / {{ $row['target'] }}</span>
                                    </div>
                                </td>
                                <td class="px-3 py-3 text-center">@if ($row['critical'])<x-ta.badge color="error" size="sm">{{ $row['critical'] }}</x-ta.badge>@else<span class="text-gray-300 dark:text-gray-600">—</span>@endif</td>
                                <td class="px-3 py-3 text-center">{{ $row['open'] ?: '—' }}</td>
                                <td class="px-3 py-3 text-right font-medium text-success-600 dark:text-success-400">{{ $row['clicks'] ? '+'.number_format($row['clicks']) : '—' }}</td>
                                <td class="px-5 py-3">
                                    <div class="flex justify-end gap-2">
                                        <button type="button" wire:click="refreshSite({{ $row['id'] }})" wire:loading.attr="disabled" @disabled($busy) class="{{ $btnSecondary }}" title="Bu sitenin planını yeniden kur">{!! $refreshIcon !!} Yenile</button>
                                        <a wire:navigate href="{{ route('operator.website', ['assetId' => $row['id'], 'tab' => 'seo']) }}" class="{{ $btnSecondary }}">Aç →</a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- 6. Task list: type tabs + filters --}}
    <section class="space-y-3">
        <div class="{{ $card }} px-4 pt-3">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div class="-mb-px flex flex-wrap gap-1" role="tablist" aria-label="Görev türü">
                    @php $tabs = array_merge([['', 'Tümü', array_sum($counts) - ($counts[SeoTaskType::Question->value] ?? 0)]], array_map(fn (SeoTaskType $type): array => [$type->value, $type->label(), $counts[$type->value] ?? 0], $listTypes)); @endphp
                    @foreach ($tabs as [$value, $label, $count])
                        <button type="button" role="tab" aria-selected="{{ $typeFilter === $value ? 'true' : 'false' }}" wire:click="$set('typeFilter', '{{ $value }}')" @class([
                            'inline-flex items-center gap-2 border-b-2 px-3 pb-3 pt-1 text-sm font-medium transition',
                            'border-brand-500 text-brand-600 dark:text-brand-400' => $typeFilter === $value,
                            'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $typeFilter !== $value,
                        ])>{{ $label }} <span @class(['rounded-full px-2 py-0.5 text-xs', 'bg-brand-50 text-brand-600 dark:bg-brand-500/15' => $typeFilter === $value, 'bg-gray-100 text-gray-500 dark:bg-white/5' => $typeFilter !== $value])>{{ $count }}</span></button>
                    @endforeach
                </div>
                <div class="flex flex-wrap items-center gap-2 pb-3" role="group" aria-label="Filtreler">
                    @if (! $site)
                        <select wire:model.live="customerFilter" aria-label="Müşteri" class="{{ $select }}">
                            <option value="">Tüm müşteriler</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                            @endforeach
                        </select>
                        <select wire:model.live="siteFilter" aria-label="Site" class="{{ $select }}">
                            <option value="">Tüm siteler</option>
                            @foreach ($sites as $option)
                                <option value="{{ $option->id }}">{{ $option->domain ?: $option->name }}@if($option->brand) · {{ $option->brand->name }}@endif</option>
                            @endforeach
                        </select>
                    @endif
                    <select wire:model.live="statusFilter" aria-label="Durum" class="{{ $select }}">
                        @foreach (['open' => 'Açık görevler', 'done' => 'Yapıldı', 'skipped' => 'Atlandı', 'stale' => 'Geçersiz', 'all' => 'Hepsi'] as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @if (! $site && ($customerFilter !== '' || $siteFilter !== '' || $typeFilter !== '' || $statusFilter !== 'open'))
                        <button type="button" wire:click="clearFilters" class="text-xs font-medium text-gray-500 hover:text-brand-600">Filtreleri temizle</button>
                    @endif
                </div>
            </div>
        </div>
        <p class="px-1 text-xs text-gray-500 dark:text-gray-400">{{ $typeHelp[$typeFilter] ?? '' }}</p>

        {{-- 7. Task list --}}
        <div class="space-y-3">
            @if ($bulkIds !== [])
                <div class="flex flex-wrap items-center gap-3 rounded-lg bg-brand-50 px-4 py-2 text-sm dark:bg-brand-500/10">
                    <span class="font-medium text-brand-700 dark:text-brand-300">{{ count($bulkIds) }} görev seçili</span>
                    <button type="button" wire:click="bulkDone" class="rounded-lg bg-success-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-success-600">✓ Hepsi yapıldı</button>
                    <button type="button" wire:click="bulkSnooze(30)" class="{{ $btnSecondary }}">30 gün ertele</button>
                    <button type="button" wire:click="bulkSkip" wire:confirm="Seçili görevler atlansın mı?" class="{{ $btnSecondary }}">Atla</button>
                    <button type="button" wire:click="$set('bulkIds', [])" class="text-xs text-gray-500 hover:underline">Seçimi kaldır</button>
                </div>
            @endif
            @forelse ($tasks as $task)
                @php
                    $brief = is_array($task->content_brief) ? $task->content_brief : null;
                    $evidence = is_array($task->evidence) ? $task->evidence : [];
                    $isOpen = $task->status === SeoTaskStatus::Open;
                    $expanded = $expandedId === $task->id;
                    $rank = ($tasks->firstItem() ?? 1) + $loop->index;
                @endphp
                <article wire:key="seo-task-{{ $task->id }}" @class([$card, 'border-l-4', $typeAccent($task->type), 'opacity-70' => ! $isOpen, 'shadow-theme-sm' => $expanded])>
                    <div class="flex flex-wrap items-start gap-4 p-4 sm:flex-nowrap">
                        @if ($isOpen)
                            <input type="checkbox" value="{{ $task->id }}" wire:model.live="bulkIds" aria-label="Seç" class="mt-2 size-4 shrink-0 rounded border-gray-300">
                        @endif
                        <span class="hidden size-8 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-sm font-semibold text-gray-500 sm:flex dark:bg-white/5 dark:text-gray-400">{{ $rank }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <x-ta.badge :color="$typeColor($task->type)" size="sm">{{ $task->type->label() }}</x-ta.badge>
                                <x-ta.badge :color="$task->severityColor()" size="sm">{{ $task->severityLabel() }}</x-ta.badge>
                                @if (! $isOpen)
                                    <x-ta.badge color="dark" size="sm">{{ $task->status->label() }}</x-ta.badge>
                                @endif
                                @if ($task->offering?->primaryName)
                                    <x-ta.badge color="light" size="sm">{{ $task->offering->primaryName->raw_label }}</x-ta.badge>
                                @elseif (! empty($evidence['service']))
                                    <x-ta.badge color="info" size="sm" title="Markada tanımlı değil; siteden çıkarıldı">{{ $evidence['service'] }} · çıkarım</x-ta.badge>
                                @endif
                                @if (! $site && $task->digitalAsset)
                                    <a wire:navigate href="{{ route('operator.website', ['assetId' => $task->digital_asset_id, 'tab' => 'seo']) }}" class="ml-1 text-xs text-gray-500 hover:text-brand-600">{{ $task->digitalAsset->domain ?: $task->digitalAsset->name }}@if ($task->brand) · {{ $task->brand->name }}@endif</a>
                                @endif
                            </div>
                            <button type="button" wire:click="toggle({{ $task->id }})" aria-expanded="{{ $expanded ? 'true' : 'false' }}" class="group mt-2 flex w-full items-start gap-2 text-left">
                                <span class="text-base font-semibold text-gray-800 group-hover:text-brand-600 dark:text-white/90">{{ $task->title }}</span>
                            </button>
                            <p @class(['mt-1 text-sm text-gray-600 dark:text-gray-300', 'line-clamp-2' => ! $expanded])>{{ $task->reason }}</p>
                            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500">
                                <span>Etki <strong class="text-gray-700 dark:text-gray-200">{{ $task->impactLabel() }}</strong>@if ($task->estimated_extra_clicks) <span class="text-success-600 dark:text-success-400">(+{{ number_format((float) $task->estimated_extra_clicks, 0) }} tık / 90 gün)</span>@endif</span>
                                <span>Efor <strong class="text-gray-700 dark:text-gray-200">{{ $task->effortLabel() }}</strong></span>
                                @if ($task->target_url)
                                    <a href="{{ $task->target_url }}" target="_blank" rel="noopener" class="max-w-xs truncate text-brand-600 hover:underline">{{ Str::limit(parse_url($task->target_url, PHP_URL_PATH) ?: $task->target_url, 60) }} ↗</a>@if ($task->is_new_page)<x-ta.badge color="success" size="sm">yeni sayfa</x-ta.badge>@endif
                                @endif
                                <span>İlk görüldü {{ $task->created_at?->timezone($tz)->format('d.m.Y') }}</span>
                                @php
                                $verifyLabel = match ($task->verification) {
                                    'verified' => ['Doğrulandı ✓', 'text-emerald-700 dark:text-emerald-300'],
                                    'still_detected' => ['Veride hâlâ görünüyor — veriler yenilenince tekrar bakılır', 'text-amber-700 dark:text-amber-300'],
                                    'recurred' => ['Sorun geri geldi ('.$task->reopened_count.'. kez)', 'text-rose-700 dark:text-rose-300'],
                                    default => null,
                                };
                            @endphp
                            @if ($verifyLabel)<span class="{{ $verifyLabel[1] }}">{{ $verifyLabel[0] }}</span>@endif
                            @if ($task->snoozed_until && $task->snoozed_until->isFuture())<span>{{ $task->snoozed_until->timezone($tz)->format('d.m.Y') }} tarihine ertelendi</span>@endif
                                <button type="button" wire:click="toggle({{ $task->id }})" class="font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $expanded ? 'Detayı gizle' : ($brief ? 'Brief ve adımlar' : 'Adımlar ve kanıt') }}</button>
                            </div>
                        </div>
                        <div class="flex shrink-0 gap-2 sm:flex-col">
                            @if ($isOpen)
                                <button type="button" wire:click="markDone({{ $task->id }})" class="inline-flex items-center justify-center gap-1 rounded-lg bg-success-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-success-600">✓ Yapıldı</button>
                                <button type="button" wire:click="skip({{ $task->id }})" wire:confirm="Bu görev atlansın mı? Sonraki planlarda tekrar gösterilmez." class="{{ $btnSecondary }}">Atla</button>
                                <button type="button" wire:click="snooze({{ $task->id }}, 30)" class="{{ $btnSecondary }}">30 gün ertele</button>
                            @elseif ($task->status !== SeoTaskStatus::Stale)
                                <button type="button" wire:click="reopen({{ $task->id }})" class="{{ $btnSecondary }}">Yeniden aç</button>
                            @endif
                        </div>
                    </div>

                    @if ($expanded)
                        <div class="grid gap-3 border-t border-gray-100 p-4 lg:grid-cols-2 dark:border-gray-800">
                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Yapılacaklar</p>
                                <ol class="mt-1 list-decimal space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                                    @foreach ($task->checklist ?? [] as $step)
                                        <li>{{ $step }}</li>
                                    @endforeach
                                </ol>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Neden bu görev · kanıt</p>
                                @if (! empty($evidence['queries']))
                                    <table class="mt-1 w-full text-left text-xs">
                                        <thead class="text-gray-400"><tr><th class="py-1 pr-2">Sorgu</th><th class="py-1 pr-2">Gösterim</th><th class="py-1 pr-2">Tık</th><th class="py-1">Sıra</th></tr></thead>
                                        <tbody class="text-gray-700 dark:text-gray-300">
                                            @foreach (array_slice($evidence['queries'], 0, 12) as $row)
                                                <tr><td class="py-0.5 pr-2">{{ $row['query'] ?? '' }}</td><td class="py-0.5 pr-2">{{ isset($row['impressions']) && $row['impressions'] !== null ? number_format((int) $row['impressions']) : '—' }}</td><td class="py-0.5 pr-2">{{ isset($row['clicks']) && $row['clicks'] !== null ? number_format((int) $row['clicks']) : '—' }}</td><td class="py-0.5">{{ isset($row['position']) && $row['position'] !== null ? number_format((float) $row['position'], 1) : '—' }}</td></tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                    @if (($evidence['source'] ?? '') === 'fallback' || ($evidence['source'] ?? '') === 'library')
                                        <p class="mt-1 text-xs text-gray-400">"—" olan sorgular için Search Console verisi yok; konu boşluğundan önerildi.</p>
                                    @endif
                                @endif
                                @if (! empty($evidence['urls']))
                                    <p class="mt-1 text-xs text-gray-500">{{ $evidence['count'] ?? count($evidence['urls']) }} sayfa @if (! empty($evidence['with_traffic'])), {{ $evidence['with_traffic'] }} tanesi arama trafiği alıyor (önce onlar)@endif:</p>
                                    <ul class="mt-1 space-y-0.5 text-xs text-gray-700 dark:text-gray-300">
                                        @foreach ($evidence['urls'] as $url)<li class="truncate">{{ $url }}</li>@endforeach
                                        @if (! empty($evidence['truncated']))<li class="text-gray-400">… ve {{ ($evidence['count'] ?? 0) - count($evidence['urls']) }} sayfa daha</li>@endif
                                    </ul>
                                @endif
                                @if (! empty($evidence['pages']))
                                    <ul class="mt-1 space-y-1 text-xs text-gray-700 dark:text-gray-300">
                                        @foreach ($evidence['pages'] as $row)
                                            <li>
                                                <span class="font-medium">@if (! empty($row['offering'])){{ $row['offering'] }} → @endif{{ \Illuminate\Support\Str::limit(parse_url($row['url'] ?? '', PHP_URL_PATH) ?: ($row['url'] ?? ''), 70) }}</span>
                                                @if (! empty($row['decision']))<span class="ml-1 rounded bg-warning-50 px-1.5 text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">{{ $row['decision'] }}</span> <span class="text-gray-500">{{ $row['why'] ?? '' }}</span>@endif
                                                @if (! empty($row['coverage_state']))<span class="text-gray-500"> · {{ $row['why'] ?? '' }} · Google: {{ $row['coverage_state'] }}</span>@endif
                                                @if (! empty($row['google_canonical']))<span class="text-gray-500"> · senin canonical: {{ $row['your_canonical'] }} · Google'ın seçtiği: {{ $row['google_canonical'] }}</span>@endif
                                                @if (isset($row['lcp_ms']))<span class="text-gray-500"> · LCP {{ number_format($row['lcp_ms'] / 1000, 1, ',', '') }} sn ({{ $row['strategy'] ?? 'mobil' }})</span>@endif
                                                @if (array_key_exists('first_paragraph_words', $row))<span class="text-gray-500"> · ilk paragraf: {{ $row['first_paragraph_words'] ?: 'yok' }}{{ $row['first_paragraph_words'] ? ' kelime' : '' }}</span>@endif
                                            </li>
                                        @endforeach
                                        @if (($evidence['total'] ?? 0) > count($evidence['pages']))<li class="text-gray-400">… ve {{ $evidence['total'] - count($evidence['pages']) }} sayfa daha</li>@endif
                                    </ul>
                                @endif
                                @if (isset($evidence['clicks_previous']))
                                    <p class="mt-1 text-xs text-gray-700 dark:text-gray-300">Tıklama (28 gün): {{ $evidence['clicks_previous'] }} → {{ $evidence['clicks_current'] }} · Gösterim: {{ $evidence['impressions_previous'] }} → {{ $evidence['impressions_current'] }}</p>
                                @endif
                                @if (! empty($evidence['related_not_linking']) || isset($evidence['inlinks']))
                                    <p class="mt-1 text-xs text-gray-700 dark:text-gray-300">Bu sayfaya link veren: {{ $evidence['inlinks'] ?? 0 }} sayfa.@if (! empty($evidence['related_not_linking'])) Link vermesi gerekenler:@endif</p>
                                    <ul class="mt-0.5 space-y-0.5 text-xs text-gray-600 dark:text-gray-400">
                                        @foreach ($evidence['related_not_linking'] ?? [] as $row)<li class="truncate">{{ $row['title'] }} — {{ parse_url($row['url'], PHP_URL_PATH) }}</li>@endforeach
                                    </ul>
                                @endif
                                @if (! empty($evidence['sitemaps']))
                                    <ul class="mt-1 space-y-0.5 text-xs text-gray-700 dark:text-gray-300">
                                        @foreach ($evidence['sitemaps'] as $row)<li class="truncate">{{ $row['path'] }} · {{ $row['errors'] }} hata, {{ $row['warnings'] }} uyarı</li>@endforeach
                                    </ul>
                                @endif
                                @if (! empty($evidence['issues']))
                                    <ul class="mt-1 space-y-0.5 text-xs text-gray-700 dark:text-gray-300">
                                        @foreach ($evidence['issues'] as $row)<li><span class="font-medium">{{ $row['field'] }}:</span> profilde "{{ $row['profile'] }}", sitede "{{ $row['site'] }}"</li>@endforeach
                                    </ul>
                                @endif
                                @if (! empty($evidence['competing']))
                                    <p class="mt-1 text-xs text-warning-700">Aynı sorgularda yarışan sayfalar: {{ implode(', ', $evidence['competing']) }}</p>
                                @endif
                                @if (! empty($evidence['blocked']))
                                    <p class="mt-1 text-xs text-gray-700 dark:text-gray-300">Engellenen botlar: {{ implode(', ', $evidence['blocked']) }}</p>
                                @endif
                                @if (array_key_exists('found_types', $evidence))
                                    <p class="mt-1 text-xs text-gray-700 dark:text-gray-300">Bulunan şema türleri: {{ implode(', ', $evidence['found_types']) ?: 'yok' }}</p>
                                @endif
                                @if (isset($evidence['page']) && is_array($evidence['page']))
                                    <p class="mt-1 text-xs text-gray-700 dark:text-gray-300">Şu an → Title: {{ $evidence['page']['title'] ?? '—' }} · H1: {{ $evidence['page']['h1'] ?? '—' }} · {{ $evidence['page']['word_count'] ?? '—' }} kelime</p>
                                @endif
                                @if (isset($evidence['finding_id']))
                                    <p class="mt-1 text-xs text-gray-500">Teknik tanı bulgusu #{{ $evidence['finding_id'] }}</p>
                                @endif
                            </div>

                            @if ($brief)
                                <div class="rounded-lg bg-success-50 p-3 lg:col-span-2 dark:bg-success-500/10" x-data="{ copied: false }">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-xs font-medium uppercase tracking-wide text-success-700 dark:text-success-400">İçerik briefi @if (($brief['source'] ?? '') === 'llm') · AI ile hazırlandı @endif</p>
                                        <button type="button" x-on:click="navigator.clipboard.writeText(@js($task->briefText())); copied = true; setTimeout(() => copied = false, 2000)" class="rounded-md bg-white px-2 py-1 text-xs font-medium text-success-700 ring-1 ring-inset ring-success-200 dark:bg-gray-900 dark:text-success-400"><span x-show="! copied">Briefi kopyala</span><span x-show="copied">Kopyalandı</span></button>
                                    </div>
                                    @if (($brief['source'] ?? '') === 'llm')
                                        @php $briefCompliance = $this->complianceFor($task); @endphp
                                        @if ($briefCompliance)
                                            <div class="mt-2 rounded-md bg-rose-50 p-2 text-xs text-rose-800 dark:bg-rose-500/10 dark:text-rose-300">
                                                <p class="font-semibold">Uyum: {{ count($briefCompliance) }} sorun — yazara vermeden önce düzelt</p>
                                                @foreach ($briefCompliance as $hit)<p>• {{ $hit['label'] }}: “{{ $hit['matched'] }}” — {{ $hit['message'] }}</p>@endforeach
                                            </div>
                                        @elseif ($briefCompliance === [])
                                            <p class="mt-2 text-xs text-success-700 dark:text-success-400">Uyum: sektör kurallarına takılan ifade yok.</p>
                                        @endif
                                    @endif
                                    @php $taskDrafts = $drafts[$task->id] ?? collect(); $lastDraft = $taskDrafts->first(); @endphp
                                @if ($canWriteWordPress || $lastDraft)
                                    <div class="mt-2 flex flex-wrap items-center gap-2 rounded-md bg-white px-3 py-2 text-xs ring-1 ring-inset ring-success-200 dark:bg-gray-900 dark:ring-success-500/20">
                                        @if ($lastDraft)
                                            <span class="text-gray-700 dark:text-gray-300">
                                                @if (in_array($lastDraft->status, ['queued', 'running', 'undoing'], true)){!! $spinner !!}@endif
                                                WordPress taslağı: <strong>{{ $lastDraft->statusLabel() }}</strong> · {{ $lastDraft->created_at?->timezone($tz)->format('d.m.Y H:i') }}
                                                @if ($lastDraft->error)<span class="block text-error-600">{{ $lastDraft->error }}</span>@endif
                                            </span>
                                            @if (in_array($lastDraft->status, ['succeeded', 'partial', 'undo_failed'], true) && ! empty($lastDraft->result['edit_url']))
                                                <a href="{{ $lastDraft->result['edit_url'] }}" target="_blank" rel="noopener" class="font-medium text-brand-600 hover:underline">Taslağı aç ↗</a>
                                            @endif
                                            @if ($canWriteWordPress && $lastDraft->isUndoable())
                                                <button type="button" wire:click="undoDraft({{ $lastDraft->id }})" wire:confirm="Taslak WordPress'te çöpe taşınacak (yalnız hâlâ taslaksa). Emin misin?" class="{{ $btnSecondary }}">Geri al</button>
                                            @endif
                                        @endif
                                        @if ($canWriteWordPress && $isOpen && (! $lastDraft || in_array($lastDraft->status, ['failed', 'undone'], true)))
                                            <button type="button" wire:click="sendDraft({{ $task->id }})" wire:confirm="Brief WordPress'e TASLAK olarak gönderilecek (yayınlanmaz). Onaylıyor musun?" class="rounded-md bg-success-600 px-2.5 py-1 font-semibold text-white hover:bg-success-700">WordPress'e taslak gönder</button>
                                            <span class="text-gray-400">Başlık ve H2 iskeleti taslak olarak oluşur; yayınlanmaz.</span>
                                        @endif
                                    </div>
                                @endif
                                <dl class="mt-2 grid gap-2 text-sm text-gray-700 dark:text-gray-300 sm:grid-cols-2">
                                        <div><dt class="text-xs text-gray-500">Önerilen title / H1</dt><dd class="font-medium">{{ $brief['page_title'] ?? '—' }}</dd></div>
                                        <div><dt class="text-xs text-gray-500">Karar</dt><dd>{{ ($brief['decision'] ?? '') === 'existing_page_section' ? 'Mevcut sayfaya bölüm ekle' : 'Yeni sayfa aç' }} · {{ match ($brief['page_type'] ?? '') { 'service' => 'hizmet', 'guide' => 'rehber', 'faq' => 'SSS', 'location' => 'bölge', default => '—' } }} · ~{{ $brief['target_words'] ?? '—' }} kelime</dd></div>
                                        <div><dt class="text-xs text-gray-500">Hedef URL</dt><dd class="break-all">{{ $brief['target_url'] ?? '—' }}</dd></div>
                                        <div><dt class="text-xs text-gray-500">İç linkler</dt><dd class="break-all">{{ implode(', ', $brief['internal_links'] ?? []) ?: '—' }}</dd></div>
                                        <div class="sm:col-span-2"><dt class="text-xs text-gray-500">H2 taslağı</dt><dd><ol class="list-decimal pl-5">@foreach ($brief['h2_outline'] ?? [] as $h2)<li>{{ $h2 }}</li>@endforeach</ol></dd></div>
                                        <div class="sm:col-span-2"><dt class="text-xs text-gray-500">Kapsanacak sorgular</dt><dd>{{ implode(' · ', $brief['queries'] ?? []) }}</dd></div>
                                    </dl>
                                </div>
                            @endif
                        </div>
                    @endif
                </article>
            @empty
                <div class="{{ $card }} px-6 py-12 text-center">
                    <p class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $statusFilter === 'open' ? 'Açık görev yok' : 'Görev yok' }}</p>
                    <p class="mx-auto mt-1 max-w-md text-sm text-gray-500">
                        @if ($site)
                            Bu site için açık SEO görevi yok. Yeni veriyle kontrol etmek için "Planı yenile"ye bas.
                        @elseif ($typeFilter !== '' || $siteFilter !== '' || $customerFilter !== '' || $statusFilter !== 'open')
                            Seçili filtrelerde SEO görevi yok. Filtreleri temizleyip tekrar dene.
                        @else
                            Henüz plan kurulmamış olabilir. Yukarıdaki "Tüm planları yenile" ile başlat.
                        @endif
                    </p>
                </div>
            @endforelse
        </div>

        @if ($tasks->hasPages())
            <div>{{ $tasks->links('livewire.operator.seo.pagination') }}</div>
        @endif
    </section>
</div>
