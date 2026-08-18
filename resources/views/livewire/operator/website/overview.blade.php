<div class="space-y-6">
    <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 dark:border-gray-800 xl:flex-row xl:items-start xl:justify-between">
        <div class="min-w-0">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ $customer?->name }} · {{ $brand?->name }}</p>
            <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $asset->name }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
                <span>{{ $asset->domain ?: 'Domain belirtilmemiş' }}</span>
                @if ($asset->primary_url)
                    <a href="{{ $asset->primary_url }}" target="_blank" rel="noopener" class="font-medium text-brand-600 hover:underline">Siteyi aç ↗</a>
                @endif
                <span>{{ $data['connection_health'] ?: 'Kaynak bağlantısı yok' }}</span>
                <span>Son veri: {{ $data['last_updated_human'] ?: 'henüz yok' }}</span>
            </div>
            <p class="mt-2 text-xs text-gray-400">{{ $data['period_label'] }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="refreshData" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600">Verileri yenile</button>
            <button type="button" wire:click="runDiagnosis" class="rounded-lg bg-white px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700">Teknik kontrol</button>
            <a href="{{ route('operator.website.sources', ['assetId' => $asset->id]) }}" wire:navigate class="rounded-lg bg-white px-3 py-2 text-sm font-semibold text-brand-700 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:bg-gray-900 dark:text-brand-300 dark:ring-brand-500/30">Veri Kaynakları</a>
            <a href="{{ route('operator.website.discovery', ['assetId' => $asset->id]) }}" wire:navigate class="rounded-lg bg-violet-600 px-3 py-2 text-sm font-semibold text-white hover:bg-violet-700">Kamu Keşif</a>
        </div>
    </div>

    @if ($message !== '')
        <div @class([
            'rounded-xl px-4 py-3 text-sm ring-1 ring-inset',
            'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20' => $messageTone === 'success',
            'bg-blue-50 text-blue-800 ring-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20' => $messageTone !== 'success',
        ])>{{ $message }}</div>
    @endif

    @if (! $data['has_performance_data'])
        <section class="rounded-xl bg-amber-50 p-5 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/20">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="font-semibold text-amber-900 dark:text-amber-200">Website için henüz gerçek GA4 / Search Console Evidence yok</h2>
                    <p class="mt-1 text-sm text-amber-800/80 dark:text-amber-300/80">Bu bir demo placeholder değildir. Kaynağı bağlayın ve collection çalıştırın; KPI ve tablolar Evidence geldikçe burada oluşur.</p>
                </div>
                <a href="{{ route('operator.website.sources', ['assetId' => $asset->id]) }}" wire:navigate class="shrink-0 rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">Kaynakları bağla</a>
            </div>
        </section>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @forelse ($data['kpis'] as $kpi)
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $kpi['label'] }}</p>
                    <span class="rounded bg-gray-100 px-2 py-1 text-[10px] font-semibold uppercase text-gray-500 dark:bg-gray-700">{{ $kpi['source'] }}</span>
                </div>
                <p class="mt-3 text-2xl font-bold text-gray-900 dark:text-white">{{ $kpi['value'] }}</p>
                @if ($kpi['delta_label'])
                    <p @class([
                        'mt-2 text-xs font-medium',
                        'text-emerald-600' => $kpi['direction'] === 'up',
                        'text-rose-600' => $kpi['direction'] === 'down',
                        'text-gray-400' => $kpi['direction'] === 'flat',
                    ])>{{ $kpi['delta_label'] }}</p>
                @endif
            </section>
        @empty
            @foreach (['Organic Search', 'GA4', 'Findings', 'Tasks'] as $label)
                <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <p class="text-sm font-medium text-gray-500">{{ $label }}</p>
                    <p class="mt-3 text-2xl font-bold text-gray-300 dark:text-gray-600">—</p>
                    <p class="mt-2 text-xs text-gray-400">Gerçek veri bekleniyor</p>
                </section>
            @endforeach
        @endforelse
    </div>

    <div class="grid gap-4 xl:grid-cols-3">
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 xl:col-span-2">
            <div class="flex items-center justify-between gap-3">
                <div><h2 class="text-base font-semibold text-gray-900 dark:text-white">Kaynak bağlantıları</h2><p class="mt-1 text-xs text-gray-400">Canonical bindings / Site Connector</p></div>
                <a href="{{ route('operator.website.sources', ['assetId' => $asset->id]) }}" wire:navigate class="text-sm font-medium text-brand-600 hover:underline">Yönet →</a>
            </div>
            <div class="mt-4 grid gap-3 md:grid-cols-3">
                @foreach ($data['connections'] as $source)
                    <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $source['label'] }}</p>
                            <span class="text-xs font-medium {{ $source['connected'] ? 'text-emerald-600' : 'text-gray-400' }}">{{ $source['connected'] ? 'Bağlı' : 'Bağlı değil' }}</span>
                        </div>
                        <p class="mt-2 text-xs text-gray-500">{{ $source['display_name'] ?: ($source['subtitle'] ?? '—') }}</p>
                        <p class="mt-2 text-xs text-gray-400">{{ $source['last_sync_human'] ?: 'Henüz veri çekilmedi' }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="flex items-center justify-between gap-3"><h2 class="text-base font-semibold text-gray-900 dark:text-white">Teknik teşhis</h2><button type="button" wire:click="runDiagnosis" class="text-xs font-medium text-brand-600 hover:underline">Çalıştır</button></div>
            <p class="mt-3 text-sm text-gray-700 dark:text-gray-300">{{ $data['diagnosis']['summary'] }}</p>
            <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-lg font-bold text-gray-900 dark:text-white">{{ $data['findings']['counts']['open'] }}</p><p class="text-[11px] text-gray-400">Open</p></div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-lg font-bold text-rose-600">{{ $data['findings']['counts']['high'] }}</p><p class="text-[11px] text-gray-400">High</p></div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-lg font-bold text-amber-600">{{ $data['findings']['counts']['medium'] }}</p><p class="text-[11px] text-gray-400">Medium</p></div>
            </div>
        </section>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
        <section class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                <div><h2 class="text-base font-semibold text-gray-900 dark:text-white">Açık bulgular</h2><p class="mt-1 text-xs text-gray-400">Gerçek Finding kayıtları</p></div>
                <a href="{{ route('operator.findings', ['asset' => $asset->id]) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">Tümü →</a>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($data['findings']['open']->take(6) as $finding)
                    <div class="px-5 py-4">
                        <div class="flex items-start justify-between gap-3"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $finding->title }}</p><span class="text-xs font-semibold uppercase {{ in_array($finding->severity, ['critical','high'], true) ? 'text-rose-600' : 'text-amber-600' }}">{{ $finding->severity }}</span></div>
                        <p class="mt-1 text-sm text-gray-500">{{ $finding->summary }}</p>
                    </div>
                @empty
                    <div class="px-5 py-8 text-sm text-gray-500">Henüz açık Finding yok. Teknik kontrol veya collection çalıştırıldığında gerçek sonuçlar burada görünür.</div>
                @endforelse
            </div>
        </section>

        <section class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                <div><h2 class="text-base font-semibold text-gray-900 dark:text-white">Öneriler</h2><p class="mt-1 text-xs text-gray-400">Finding / Opportunity kaynaklı gerçek Recommendation kayıtları</p></div>
                <a href="{{ route('operator.recommendations', ['asset' => $asset->id]) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">Tümü →</a>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($data['recommendations']->take(6) as $recommendation)
                    <div class="px-5 py-4">
                        <div class="flex items-start justify-between gap-3"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $recommendation->title }}</p><span class="text-xs font-semibold uppercase text-gray-400">{{ $recommendation->priority }}</span></div>
                        <p class="mt-1 text-sm text-gray-500">{{ $recommendation->action }}</p>
                    </div>
                @empty
                    <div class="px-5 py-8 text-sm text-gray-500">Henüz Recommendation yok. Finding üretildikten sonra diagnosis/AI akışı önerileri burada gösterir.</div>
                @endforelse
            </div>
        </section>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="flex items-center justify-between gap-3"><div><h2 class="text-base font-semibold text-gray-900 dark:text-white">Kamu Keşif</h2><p class="mt-1 text-xs text-gray-400">Website → Evidence → reviewable Brand Context candidates</p></div><a href="{{ route('operator.website.discovery', ['assetId' => $asset->id]) }}" wire:navigate class="text-sm font-medium text-violet-600 hover:underline">Aç →</a></div>
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">Public Discovery artık ayrı gerçek workspace'te çalışır. Candidate kabulü canonical Brand Context'e yazar.</p>
        </section>

        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="flex items-center justify-between gap-3"><div><h2 class="text-base font-semibold text-gray-900 dark:text-white">AI Guidance</h2><p class="mt-1 text-xs text-gray-400">Evidence / Finding tabanlı</p></div><button type="button" wire:click="generateAiGuidance" class="text-sm font-medium text-brand-600 hover:underline">Yenile →</button></div>
            @if ($data['ai_guidance']['available'])
                <p class="mt-4 text-sm text-gray-700 dark:text-gray-300">{{ $data['ai_guidance']['executive_summary'] ?: 'AI guidance generated.' }}</p>
                <p class="mt-2 text-xs text-gray-400">{{ $data['ai_guidance']['generated_human'] }} · {{ $data['ai_guidance']['finding_count'] }} finding · {{ $data['ai_guidance']['evidence_count'] }} evidence</p>
            @else
                <p class="mt-4 text-sm text-gray-500">Henüz gerçek AI guidance yok. Önce Evidence/Finding üretin; sonra bu motoru çalıştırın.</p>
            @endif
        </section>
    </div>

    <section class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-700"><h2 class="text-base font-semibold text-gray-900 dark:text-white">Son motor çalışmaları</h2></div>
        <div class="divide-y divide-gray-100 dark:divide-gray-700">
            @forelse (array_slice($data['activity'], 0, 8) as $run)
                <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                    <div><p class="font-medium text-gray-800 dark:text-gray-200">{{ $run['title'] ?? $run['module'] ?? 'Run' }}</p><p class="mt-1 text-xs text-gray-400">{{ $run['started_human'] ?? $run['started_at'] ?? '—' }}</p></div>
                    <span class="text-xs font-semibold uppercase text-gray-500">{{ $run['status'] ?? '—' }}</span>
                </div>
            @empty
                <div class="px-5 py-8 text-sm text-gray-500">Bu Website için henüz collection / diagnosis / discovery run yok.</div>
            @endforelse
        </div>
    </section>
</div>
