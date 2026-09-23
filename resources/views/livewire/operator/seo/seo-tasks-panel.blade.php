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
    $planStates = ['queued' => 'Kuyrukta', 'running' => 'Çalışıyor', 'completed' => 'Tamamlandı', 'failed' => 'Başarısız'];
    $tz = config('app.timezone');
    $listTypes = array_values(array_filter($types, fn (SeoTaskType $type): bool => $type !== SeoTaskType::Question));
    $understanding = $latestPlan ? data_get($latestPlan->input_summary, 'site_understanding') : null;
@endphp
<div class="space-y-5" @if($pendingPlan) wire:poll.5s @endif>

    {{-- 1. Site header (website tab only) --}}
    @if ($site)
        <section class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">SEO planı · {{ $site->domain ?: $site->name }}</h2>
                    @if ($latestPlan)
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            Son plan {{ $latestPlan->completed_at?->timezone($tz)->locale('tr')->diffForHumans() }} ({{ $latestPlan->completed_at?->timezone($tz)->format('d.m.Y H:i') }}) · {{ $latestPlan->summary_text }}
                        </p>
                        <div class="mt-2 flex flex-wrap gap-2 text-xs">
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
                                    'inline-flex items-center gap-1 rounded-full px-2.5 py-1 ring-1 ring-inset',
                                    'bg-success-50 text-success-700 ring-success-200 dark:bg-success-500/10 dark:text-success-400 dark:ring-success-500/20' => $ok,
                                    'bg-gray-50 text-gray-500 ring-gray-200 dark:bg-white/5 dark:text-gray-400 dark:ring-gray-700' => ! $ok,
                                ])>{{ $ok ? '●' : '○' }} {{ $label }}: {{ $detail }}</span>
                            @endforeach
                        </div>
                        @if (data_get($latestPlan->input_summary, 'html.excluded_non_documents', 0) > 0)
                            <p class="mt-2 text-xs text-gray-400">{{ number_format((int) data_get($latestPlan->input_summary, 'html.excluded_non_documents')) }} HTML olmayan adres (feed, sitemap, robots, medya) değerlendirme dışı bırakıldı.</p>
                        @endif
                    @else
                        <p class="mt-1 text-sm text-gray-500">Bu site için henüz plan üretilmedi. "Planı yenile" ile başlat; işlem arka planda çalışır.</p>
                    @endif
                    @if ($pendingPlan)
                        <p class="mt-2 text-sm font-medium text-brand-600">Plan #{{ $pendingPlan->version }} {{ mb_strtolower($planStates[$pendingPlan->status] ?? $pendingPlan->status) }}… Sayfayı kapatabilirsin.</p>
                    @endif
                </div>
                <button type="button" wire:click="refreshPlan" wire:loading.attr="disabled" @disabled($pendingPlan !== null)
                    class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">Planı yenile</button>
            </div>
        </section>
    @endif

    @if ($message !== '')
        <p role="status" @class([
            'rounded-lg p-3 text-sm',
            'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300' => $messageTone === 'success',
            'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300' => $messageTone === 'error',
        ])>{{ $message }}</p>
    @endif

    {{-- 2. KPI strip: four questions an operator asks on Monday --}}
    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs font-medium text-gray-500">Bu haftanın içerik önerileri</p>
            <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $kpis['content'] }}<span class="text-sm font-medium text-gray-400"> / hedef {{ $kpis['content_target'] }}</span></p>
            <p class="mt-1 text-xs text-gray-400">Site başına haftada en az {{ config('moxdop-seo-tasks.create.min_per_site', 4) }} içerik</p>
        </div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs font-medium text-gray-500">Tahmini ek tıklama</p>
            <p class="mt-1 text-2xl font-bold text-success-600 dark:text-success-400">+{{ number_format($kpis['extra_clicks']) }}</p>
            <p class="mt-1 text-xs text-gray-400">90 gün, açık güçlendir + oluştur görevleri yapılırsa</p>
        </div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs font-medium text-gray-500">Kritik / yüksek teknik sorun</p>
            <p @class(['mt-1 text-2xl font-bold', 'text-error-600 dark:text-error-400' => $kpis['critical_fixes'] > 0, 'text-gray-800 dark:text-white/90' => $kpis['critical_fixes'] === 0])>{{ $kpis['critical_fixes'] }}</p>
            <p class="mt-1 text-xs text-gray-400">Önce bunlar: sıralamayı doğrudan etkiler</p>
        </div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-xs font-medium text-gray-500">Kurulum bekleyen</p>
            <p @class(['mt-1 text-2xl font-bold', 'text-warning-600 dark:text-warning-400' => $kpis['pending_mappings'] > 0, 'text-gray-800 dark:text-white/90' => $kpis['pending_mappings'] === 0])>{{ $kpis['pending_mappings'] }}</p>
            <p class="mt-1 text-xs text-gray-400">Sayfası eşleşmemiş öncelikli hizmet</p>
        </div>
    </div>

    {{-- 3. Setup: service ↔ page mapping (one card per site, not one card per service) --}}
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

    {{-- 5. Global: per-site overview + filters --}}
    @if (! $site)
        @if ($siteOverview->isNotEmpty())
            <section class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/[0.03]">
                        <tr><th class="px-4 py-2.5">Site</th><th class="px-3 py-2.5">Açık görev</th><th class="px-3 py-2.5">İçerik önerisi</th><th class="px-3 py-2.5">Kritik/yüksek</th><th class="px-3 py-2.5">Eşleştirme</th><th class="px-3 py-2.5">Tahmini ek tık</th><th class="px-3 py-2.5">Son plan</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-gray-700 dark:divide-gray-800 dark:text-gray-300">
                        @foreach ($siteOverview as $row)
                            <tr wire:key="site-row-{{ $row['id'] }}">
                                <td class="px-4 py-2.5"><a wire:navigate href="{{ route('operator.website', ['assetId' => $row['id'], 'tab' => 'seo']) }}" class="font-medium text-brand-600">{{ $row['domain'] }}</a>@if ($row['gsc'] === false)<span class="ml-1 text-xs text-gray-400">(GSC yok)</span>@endif</td>
                                <td class="px-3 py-2.5">{{ $row['open'] }}</td>
                                <td class="px-3 py-2.5"><span @class(['text-success-600' => $row['content'] >= $row['target'], 'text-warning-600' => $row['content'] < $row['target']])>{{ $row['content'] }} / {{ $row['target'] }}</span></td>
                                <td class="px-3 py-2.5">{{ $row['critical'] ?: '—' }}</td>
                                <td class="px-3 py-2.5">{{ $row['mappings'] ? $row['mappings'].' hizmet' : '—' }}</td>
                                <td class="px-3 py-2.5">{{ $row['clicks'] ? '+'.number_format($row['clicks']) : '—' }}</td>
                                <td class="px-3 py-2.5 text-xs text-gray-500">{{ $row['last_plan_at']?->timezone($tz)->locale('tr')->diffForHumans() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        <div class="flex flex-wrap items-end gap-3">
            <label class="text-sm">
                <span class="block text-xs text-gray-500">Müşteri</span>
                <select wire:model.live="customerFilter" class="mt-1 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900">
                    <option value="">Tümü</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">
                <span class="block text-xs text-gray-500">Site</span>
                <select wire:model.live="siteFilter" class="mt-1 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900">
                    <option value="">Tümü</option>
                    @foreach ($sites as $option)
                        <option value="{{ $option->id }}">{{ $option->domain ?: $option->name }}@if($option->brand) · {{ $option->brand->name }}@endif</option>
                    @endforeach
                </select>
            </label>
            <div class="ml-auto">
                <button type="button" wire:click="refreshAll" wire:confirm="Tüm aktif web siteleri için plan kuyruğa alınsın mı?" wire:loading.attr="disabled" class="rounded-lg bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700">Tüm siteleri yenile</button>
            </div>
        </div>
    @endif

    {{-- 6. Filters --}}
    <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Filtreler">
        <button type="button" wire:click="$set('typeFilter', '')" @class([
            'rounded-lg px-3 py-2 text-sm font-medium transition',
            'bg-brand-500 text-white' => $typeFilter === '',
            'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $typeFilter !== '',
        ])>Tümü ({{ array_sum($counts) - ($counts[SeoTaskType::Question->value] ?? 0) }})</button>
        @foreach ($listTypes as $type)
            <button type="button" wire:click="$set('typeFilter', '{{ $type->value }}')" @class([
                'rounded-lg px-3 py-2 text-sm font-medium transition',
                'bg-brand-500 text-white' => $typeFilter === $type->value,
                'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $typeFilter !== $type->value,
            ])>{{ $type->label() }} ({{ $counts[$type->value] ?? 0 }})</button>
        @endforeach
        <span class="mx-1 hidden h-6 border-l border-gray-200 dark:border-gray-700 sm:block"></span>
        <select wire:model.live="statusFilter" aria-label="Durum" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900">
            @foreach (['open' => 'Açık', 'done' => 'Yapıldı', 'skipped' => 'Atlandı', 'stale' => 'Geçersiz', 'all' => 'Hepsi'] as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    {{-- 7. Task list --}}
    <div class="space-y-3">
        @forelse ($tasks as $task)
            @php
                $brief = is_array($task->content_brief) ? $task->content_brief : null;
                $evidence = is_array($task->evidence) ? $task->evidence : [];
                $isOpen = $task->status === SeoTaskStatus::Open;
                $expanded = $expandedId === $task->id;
            @endphp
            <article wire:key="seo-task-{{ $task->id }}" class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="flex flex-wrap items-start justify-between gap-3 p-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
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
                                <a wire:navigate href="{{ route('operator.website', ['assetId' => $task->digital_asset_id, 'tab' => 'seo']) }}" class="text-xs text-gray-500 hover:text-brand-600">{{ $task->digitalAsset->domain ?: $task->digitalAsset->name }}@if ($task->brand) · {{ $task->brand->name }}@endif</a>
                            @endif
                        </div>
                        <button type="button" wire:click="toggle({{ $task->id }})" aria-expanded="{{ $expanded ? 'true' : 'false' }}" class="mt-2 flex items-start gap-2 text-left text-base font-semibold text-gray-800 hover:text-brand-600 dark:text-white/90">
                            <span class="mt-0.5 text-gray-400">{{ $expanded ? '▾' : '▸' }}</span><span>{{ $task->title }}</span>
                        </button>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $task->reason }}</p>
                        <p class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-500">
                            <span>Etki: <strong class="text-gray-700 dark:text-gray-200">{{ $task->impactLabel() }}</strong>@if ($task->estimated_extra_clicks) (+{{ number_format((float) $task->estimated_extra_clicks, 0) }} tık / 90 gün)@endif</span>
                            <span>Efor: <strong class="text-gray-700 dark:text-gray-200">{{ $task->effortLabel() }}</strong></span>
                            @if ($task->target_url)
                                <a href="{{ $task->target_url }}" target="_blank" rel="noopener" class="text-brand-600">{{ Str::limit(parse_url($task->target_url, PHP_URL_PATH) ?: $task->target_url, 60) }}</a>@if ($task->is_new_page)<span class="text-success-600">yeni sayfa</span>@endif
                            @endif
                            <span>İlk görüldü {{ $task->created_at?->timezone($tz)->format('d.m.Y') }}</span>
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col gap-2">
                        @if ($isOpen)
                            <button type="button" wire:click="markDone({{ $task->id }})" class="rounded-lg bg-success-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-success-600">Yapıldı</button>
                            <button type="button" wire:click="skip({{ $task->id }})" wire:confirm="Bu görev atlansın mı? Sonraki planlarda tekrar gösterilmez." class="rounded-lg bg-white px-3 py-1.5 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700">Atla</button>
                        @elseif ($task->status !== SeoTaskStatus::Stale)
                            <button type="button" wire:click="reopen({{ $task->id }})" class="rounded-lg bg-white px-3 py-1.5 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700">Yeniden aç</button>
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
                                <ul class="mt-1 space-y-0.5 text-xs text-gray-700 dark:text-gray-300">
                                    @foreach ($evidence['pages'] as $row)<li class="truncate">{{ $row['offering'] ?? '' }} → {{ $row['url'] ?? '' }}</li>@endforeach
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
            <x-ta.empty-state title="Görev yok" message="{{ $site ? 'Bu site için açık SEO görevi yok. &quot;Planı yenile&quot; ile yeni plan üret.' : 'Seçili filtrelerde SEO görevi yok.' }}" />
        @endforelse
    </div>

    @if ($tasks->hasPages())
        <div>{{ $tasks->links('livewire.operator.seo.pagination') }}</div>
    @endif
</div>
