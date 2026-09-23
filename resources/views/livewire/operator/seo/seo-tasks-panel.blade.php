@php
    $typeColor = fn (\App\Enums\SeoTaskType $type): string => match ($type) {
        \App\Enums\SeoTaskType::Fix => 'error',
        \App\Enums\SeoTaskType::Strengthen => 'warning',
        \App\Enums\SeoTaskType::Create => 'success',
        \App\Enums\SeoTaskType::AiVisibility => 'info',
        \App\Enums\SeoTaskType::Question => 'light',
    };
    $severityColor = fn (string $severity): string => match ($severity) {
        'critical', 'high' => 'error',
        'medium' => 'warning',
        default => 'light',
    };
    $planStates = ['queued' => 'Kuyrukta', 'running' => 'Çalışıyor', 'completed' => 'Tamamlandı', 'failed' => 'Başarısız'];
@endphp
<div class="space-y-5" @if($pendingPlan) wire:poll.5s @endif>
    @if ($site)
        <section class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">SEO görevleri · {{ $site->domain ?: $site->name }}</h2>
                    <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">
                        Search Console, sayfa envanteri, teknik bulgular ve hizmet listesinden üretilir. Haftada en az {{ config('moxdop-seo-tasks.create.min_per_site', 4) }} içerik önerisi hedeflenir.
                    </p>
                    @if ($latestPlan)
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                            Son plan #{{ $latestPlan->version }} · {{ $latestPlan->completed_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') }} ·
                            {{ $latestPlan->summary_text }}
                            @if (data_get($latestPlan->input_summary, 'gsc.available') === false)
                                · <span class="text-warning-600">Search Console verisi yok ({{ data_get($latestPlan->input_summary, 'gsc.reason') }})</span>
                            @else
                                · GSC {{ number_format((int) data_get($latestPlan->input_summary, 'gsc.query_count', 0)) }} sorgu / {{ number_format((int) data_get($latestPlan->input_summary, 'pages', 0)) }} sayfa
                            @endif
                            @if (data_get($latestPlan->llm_summary, 'applied', 0) > 0)
                                · AI {{ data_get($latestPlan->llm_summary, 'applied') }} görevi zenginleştirdi ({{ data_get($latestPlan->llm_summary, 'provider') }})
                            @elseif (data_get($latestPlan->llm_summary, 'skipped_reason'))
                                · AI katmanı atlandı: {{ data_get($latestPlan->llm_summary, 'skipped_reason') }}
                            @endif
                        </p>
                    @else
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Henüz plan üretilmedi.</p>
                    @endif
                    @if ($pendingPlan)
                        <p class="mt-1 text-xs text-brand-600">Plan #{{ $pendingPlan->version }} {{ $planStates[$pendingPlan->status] ?? $pendingPlan->status }}…</p>
                    @endif
                </div>
                <button type="button" wire:click="refreshPlan" wire:loading.attr="disabled" @disabled($pendingPlan !== null)
                    class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Planı yenile</button>
            </div>
            @if ($latestPlan && data_get($latestPlan->input_summary, 'html.read') !== null)
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Saklı HTML: {{ data_get($latestPlan->input_summary, 'html.read') }} / {{ data_get($latestPlan->input_summary, 'html.candidates') }} sayfa okundu (çift H1, alt metni, şema kontrolleri).</p>
            @endif
        </section>

        @php $understanding = $latestPlan ? data_get($latestPlan->input_summary, 'site_understanding') : null; @endphp
        @if (is_array($understanding) && ! empty($understanding['services']))
            <section class="rounded-xl border border-blue-200 bg-blue-50 p-5 dark:border-blue-500/20 dark:bg-blue-500/10">
                <h3 class="text-sm font-semibold text-blue-900 dark:text-blue-200">Markada hizmet tanımlı değil — siteden çıkarıldı</h3>
                <p class="mt-1 text-xs text-blue-800 dark:text-blue-300">
                    Kaynak: {{ match ($understanding['source_detail'] ?? $understanding['source'] ?? '') { 'ai' => 'AI (sayfalar + Search Console + GA4)', 'rules' => 'en çok gösterim alan sayfalar (AI kullanılamadı)', default => $understanding['source'] ?? '' } }}.
                    Bu hizmetler görevleri üretmek için kullanıldı; markaya kaydedilmedi. Doğru olanları "Markaya ekle" ile kalıcı yap.
                    @if (($brandServiceCount ?? 0) > 0) <strong>Markaya hizmet eklendi; bir sonraki planda onlar kullanılacak.</strong> @endif
                </p>
                @if (! empty($understanding['brand_summary']))
                    <p class="mt-2 text-sm text-gray-800 dark:text-gray-200">{{ $understanding['brand_summary'] }}@if (! empty($understanding['audience'])) · <span class="text-gray-600 dark:text-gray-400">Hedef kitle: {{ $understanding['audience'] }}</span>@endif</p>
                @endif
                @if (! empty($understanding['locations']))
                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">Bölgeler: {{ implode(', ', $understanding['locations']) }}</p>
                @endif
                <ul class="mt-3 space-y-2">
                    @foreach ($understanding['services'] as $serviceIndex => $service)
                        <li wire:key="inferred-service-{{ $serviceIndex }}" class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-white px-3 py-2 text-sm dark:bg-gray-900">
                            <div class="min-w-0">
                                <span class="font-medium text-gray-800 dark:text-white/90">@if (! empty($service['is_core']))★ @endif{{ $service['name'] }}</span>
                                @if (! empty($service['page_url']))<span class="ml-2 text-xs text-gray-500">{{ \Illuminate\Support\Str::limit($service['page_url'], 60) }}</span>@endif
                                <span class="ml-2 text-xs text-gray-400">{{ count($service['queries'] ?? []) }} sorgu</span>
                            </div>
                            <button type="button" wire:click="adoptService({{ $serviceIndex }})" class="rounded-lg bg-white px-3 py-1.5 text-xs font-medium text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:bg-gray-800 dark:ring-brand-500/30">Markaya ekle</button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @else
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
                <button type="button" wire:click="refreshAll" wire:confirm="Tüm aktif web siteleri için plan kuyruğa alınsın mı?" wire:loading.attr="disabled"
                    class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Tümünü yenile</button>
            </div>
        </div>
    @endif

    @if ($message !== '')
        <p role="status" @class([
            'rounded-lg p-3 text-sm',
            'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300' => $messageTone === 'success',
            'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300' => $messageTone === 'error',
        ])>{{ $message }}</p>
    @endif

    <div class="flex flex-wrap gap-2" role="group" aria-label="Tür filtresi">
        <button type="button" wire:click="$set('typeFilter', '')" @class([
            'rounded-lg px-3 py-2 text-sm font-medium transition',
            'bg-brand-500 text-white' => $typeFilter === '',
            'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $typeFilter !== '',
        ])>Tümü ({{ array_sum($counts) }})</button>
        @foreach ($types as $type)
            <button type="button" wire:click="$set('typeFilter', '{{ $type->value }}')" @class([
                'rounded-lg px-3 py-2 text-sm font-medium transition',
                'bg-brand-500 text-white' => $typeFilter === $type->value,
                'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $typeFilter !== $type->value,
            ])>{{ $type->label() }} ({{ $counts[$type->value] ?? 0 }})</button>
        @endforeach
        <span class="mx-2 hidden border-l border-gray-200 dark:border-gray-700 sm:block"></span>
        @foreach (['open' => 'Açık', 'done' => 'Yapıldı', 'skipped' => 'Atlandı', 'stale' => 'Geçersiz', 'all' => 'Hepsi'] as $key => $label)
            <button type="button" wire:click="$set('statusFilter', '{{ $key }}')" @class([
                'rounded-lg px-3 py-2 text-sm font-medium transition',
                'bg-gray-800 text-white dark:bg-white dark:text-gray-900' => $statusFilter === $key,
                'bg-white text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $statusFilter !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    <div class="space-y-3">
        @forelse ($tasks as $task)
            @php
                $brief = is_array($task->content_brief) ? $task->content_brief : null;
                $evidence = is_array($task->evidence) ? $task->evidence : [];
                $isOpen = $task->status === \App\Enums\SeoTaskStatus::Open;
            @endphp
            <article wire:key="seo-task-{{ $task->id }}" class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ta.badge :color="$typeColor($task->type)" size="sm">{{ $task->type->label() }}</x-ta.badge>
                            <x-ta.badge :color="$severityColor($task->severity)" size="sm">{{ strtoupper($task->severity) }}</x-ta.badge>
                            @if (! $isOpen)
                                <x-ta.badge color="dark" size="sm">{{ $task->status->label() }}</x-ta.badge>
                            @endif
                            <span class="text-sm text-gray-500 dark:text-gray-400">
                                {{ $task->brand?->customer?->name }}@if($task->brand) / {{ $task->brand->name }}@endif
                                @if (! $site && $task->digitalAsset) · <a wire:navigate href="{{ route('operator.website', ['assetId' => $task->digital_asset_id, 'tab' => 'seo']) }}" class="text-brand-600">{{ $task->digitalAsset->domain ?: $task->digitalAsset->name }}</a>@endif
                            </span>
                            @if ($task->offering?->primaryName)
                                <x-ta.badge color="light" size="sm">{{ $task->offering->primaryName->raw_label }}</x-ta.badge>
                            @elseif (! empty($evidence['service']))
                                <x-ta.badge color="info" size="sm" title="Markada tanımlı değil; siteden çıkarıldı">{{ $evidence['service'] }} · çıkarım</x-ta.badge>
                            @endif
                        </div>
                        <button type="button" wire:click="toggle({{ $task->id }})" class="mt-2 block text-left text-base font-semibold text-gray-800 hover:text-brand-600 dark:text-white/90">{{ $task->title }}</button>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $task->reason }}</p>
                        <p class="mt-1 text-xs text-gray-500">
                            Öncelik {{ number_format((float) $task->priority_score, 0) }}
                            @if ($task->estimated_extra_clicks !== null) · tahmini +{{ number_format((float) $task->estimated_extra_clicks, 0) }} tıklama / 90 gün @endif
                            @if ($task->target_url) · <a href="{{ $task->target_url }}" target="_blank" rel="noopener" class="text-brand-600">{{ \Illuminate\Support\Str::limit($task->target_url, 70) }}</a>@if($task->is_new_page) <span class="text-success-600">(yeni sayfa)</span>@endif @endif
                            · plan #{{ $task->lastSeenPlan?->version ?? $task->last_seen_plan_id }}
                        </p>

                        @if ($expandedId === $task->id)
                            <div class="mt-4 grid gap-3 lg:grid-cols-2">
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Yapılacaklar</p>
                                    <ol class="mt-1 list-decimal space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                                        @foreach ($task->checklist ?? [] as $step)
                                            <li>{{ $step }}</li>
                                        @endforeach
                                    </ol>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Kanıt</p>
                                    @if (! empty($evidence['queries']))
                                        <table class="mt-1 w-full text-left text-xs">
                                            <thead class="text-gray-400"><tr><th class="py-1 pr-2">Sorgu</th><th class="py-1 pr-2">Gösterim</th><th class="py-1 pr-2">Tık</th><th class="py-1">Poz.</th></tr></thead>
                                            <tbody class="text-gray-700 dark:text-gray-300">
                                                @foreach (array_slice($evidence['queries'], 0, 12) as $row)
                                                    <tr><td class="py-0.5 pr-2">{{ $row['query'] ?? '' }}</td><td class="py-0.5 pr-2">{{ isset($row['impressions']) && $row['impressions'] !== null ? number_format((int) $row['impressions']) : '—' }}</td><td class="py-0.5 pr-2">{{ isset($row['clicks']) && $row['clicks'] !== null ? number_format((int) $row['clicks']) : '—' }}</td><td class="py-0.5">{{ isset($row['position']) && $row['position'] !== null ? number_format((float) $row['position'], 1) : '—' }}</td></tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    @endif
                                    @if (! empty($evidence['urls']))
                                        <ul class="mt-1 space-y-0.5 text-xs text-gray-700 dark:text-gray-300">
                                            @foreach ($evidence['urls'] as $url)<li class="truncate">{{ $url }}</li>@endforeach
                                            @if (! empty($evidence['truncated']))<li class="text-gray-400">… ve daha fazlası ({{ $evidence['count'] ?? '' }})</li>@endif
                                        </ul>
                                    @endif
                                    @if (! empty($evidence['pages']))
                                        <ul class="mt-1 space-y-0.5 text-xs text-gray-700 dark:text-gray-300">
                                            @foreach ($evidence['pages'] as $row)<li class="truncate">{{ $row['offering'] ?? '' }} → {{ $row['url'] ?? '' }}</li>@endforeach
                                        </ul>
                                    @endif
                                    @if (! empty($evidence['competing']))
                                        <p class="mt-1 text-xs text-warning-700">Yarışan sayfalar: {{ implode(', ', $evidence['competing']) }}</p>
                                    @endif
                                    @if (! empty($evidence['blocked']))
                                        <p class="mt-1 text-xs text-gray-700 dark:text-gray-300">Engellenen botlar: {{ implode(', ', $evidence['blocked']) }}</p>
                                    @endif
                                    @if (! empty($evidence['found_types']))
                                        <p class="mt-1 text-xs text-gray-700 dark:text-gray-300">Bulunan şema türleri: {{ implode(', ', $evidence['found_types']) ?: 'yok' }}</p>
                                    @endif
                                    @if (isset($evidence['page']) && is_array($evidence['page']))
                                        <p class="mt-1 text-xs text-gray-700 dark:text-gray-300">Title: {{ $evidence['page']['title'] ?? '—' }} · H1: {{ $evidence['page']['h1'] ?? '—' }} · {{ $evidence['page']['word_count'] ?? '—' }} kelime</p>
                                    @endif
                                    @if (isset($evidence['finding_id']))
                                        <p class="mt-1 text-xs text-gray-500">Bulgu #{{ $evidence['finding_id'] }} · {{ $evidence['rule_id'] ?? '' }}</p>
                                    @endif
                                </div>

                                @if ($brief)
                                    <div class="rounded-lg bg-success-50 p-3 lg:col-span-2 dark:bg-success-500/10">
                                        <p class="text-xs font-medium uppercase tracking-wide text-success-700 dark:text-success-400">İçerik briefi @if(($brief['source'] ?? '') === 'llm') · AI @endif</p>
                                        <dl class="mt-2 grid gap-2 text-sm text-gray-700 dark:text-gray-300 sm:grid-cols-2">
                                            <div><dt class="text-xs text-gray-500">Sayfa başlığı</dt><dd class="font-medium">{{ $brief['page_title'] ?? '—' }}</dd></div>
                                            <div><dt class="text-xs text-gray-500">Tür / karar</dt><dd>{{ $brief['page_type'] ?? '—' }} · {{ ($brief['decision'] ?? '') === 'existing_page_section' ? 'mevcut sayfaya bölüm' : 'yeni sayfa' }} · ~{{ $brief['target_words'] ?? '—' }} kelime</dd></div>
                                            <div><dt class="text-xs text-gray-500">Hedef URL</dt><dd class="break-all">{{ $brief['target_url'] ?? '—' }}</dd></div>
                                            <div><dt class="text-xs text-gray-500">İç linkler</dt><dd class="break-all">{{ implode(', ', $brief['internal_links'] ?? []) ?: '—' }}</dd></div>
                                            <div class="sm:col-span-2"><dt class="text-xs text-gray-500">H2 taslağı</dt><dd><ol class="list-decimal pl-5">@foreach ($brief['h2_outline'] ?? [] as $h2)<li>{{ $h2 }}</li>@endforeach</ol></dd></div>
                                            <div class="sm:col-span-2"><dt class="text-xs text-gray-500">Kapsanacak sorgular</dt><dd>{{ implode(' · ', $brief['queries'] ?? []) }}</dd></div>
                                        </dl>
                                    </div>
                                @endif

                                @if ($task->type === \App\Enums\SeoTaskType::Question && $isOpen)
                                    <div class="rounded-lg bg-blue-50 p-3 lg:col-span-2 dark:bg-blue-500/10">
                                        <p class="text-xs font-medium uppercase tracking-wide text-blue-700 dark:text-blue-300">Cevabın (bir kez sorulur)</p>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @foreach ($evidence['candidates'] ?? [] as $candidate)
                                                <button type="button" wire:click="answerQuestion({{ $task->id }}, @js($candidate['url']))" class="rounded-lg bg-white px-3 py-2 text-left text-xs ring-1 ring-inset ring-gray-200 hover:ring-brand-500 dark:bg-gray-800 dark:ring-gray-700">
                                                    <span class="block font-medium text-gray-800 dark:text-white/90">{{ \Illuminate\Support\Str::limit($candidate['title'] ?? $candidate['url'], 60) }}</span>
                                                    <span class="block text-gray-500">{{ \Illuminate\Support\Str::limit($candidate['url'], 70) }} · puan {{ number_format((float) ($candidate['score'] ?? 0), 2) }}</span>
                                                </button>
                                            @endforeach
                                            <button type="button" wire:click="answerQuestion({{ $task->id }}, 'none')" class="rounded-lg bg-white px-3 py-2 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:ring-error-500 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700">Sayfası yok</button>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                    <div class="flex shrink-0 flex-col gap-2">
                        @if ($isOpen && $task->type !== \App\Enums\SeoTaskType::Question)
                            <button type="button" wire:click="markDone({{ $task->id }})" class="rounded-lg bg-success-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-success-600">Yapıldı</button>
                            <button type="button" wire:click="skip({{ $task->id }})" class="rounded-lg bg-white px-3 py-1.5 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700">Atla</button>
                        @elseif (! $isOpen && $task->status !== \App\Enums\SeoTaskStatus::Stale)
                            <button type="button" wire:click="reopen({{ $task->id }})" class="rounded-lg bg-white px-3 py-1.5 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700">Yeniden aç</button>
                        @endif
                    </div>
                </div>
            </article>
        @empty
            <x-ta.empty-state title="Görev yok" message="{{ $site ? 'Bu site için henüz açık SEO görevi yok. \"Planı yenile\" ile üret.' : 'Seçili filtrelerde SEO görevi yok. Bir web sitesi varlığında \"Planı yenile\" ile üretebilirsin.' }}" />
        @endforelse
    </div>

    @if ($tasks->hasPages())
        <div>{{ $tasks->links() }}</div>
    @endif
</div>
