@php
    $tabs = [
        'overview' => 'Overview',
        'health' => 'Health',
        'visibility' => 'Visibility',
        'content' => 'Content',
        'performance' => 'Performance',
        'infrastructure' => 'Infrastructure',
        'operations' => 'Operations',
        'setup' => 'Setup',
    ];
@endphp

<div class="space-y-5">
    <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 dark:border-gray-800 xl:flex-row xl:items-start xl:justify-between">
        <div class="flex min-w-0 items-start gap-3">
            <x-demo.digital-asset-mark type="website" size="lg" class="mt-0.5" />
            <div class="min-w-0">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ $customer?->name }} · {{ $brand?->name }}</p>
                <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $asset->name }}</h1>
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
                    <span>{{ $asset->domain ?: 'Domain belirtilmemiş' }}</span>
                    @if ($asset->primary_url)
                        <a href="{{ $asset->primary_url }}" target="_blank" rel="noopener" class="font-medium text-brand-600 hover:underline">Siteyi aç ↗</a>
                    @endif
                    <span>{{ $data['connection_health'] ?: 'Needs attention · veri kaynağı bağlayın' }}</span>
                    <span>Son veri: {{ $data['last_updated_human'] ?: 'henüz yok' }}</span>
                </div>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="refreshData" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600">Verileri yenile</button>
            <button type="button" wire:click="runDiagnosis" class="rounded-lg bg-white px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700">Teknik kontrol</button>
            <a href="{{ route('operator.website.sources', ['assetId' => $asset->id]) }}" wire:navigate class="rounded-lg bg-white px-3 py-2 text-sm font-semibold text-brand-700 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:bg-gray-900 dark:text-brand-300 dark:ring-brand-500/30">Veri Kaynakları</a>
            <a href="{{ route('operator.website.discovery', ['assetId' => $asset->id]) }}" wire:navigate class="rounded-lg bg-violet-600 px-3 py-2 text-sm font-semibold text-white hover:bg-violet-700">Kamu Keşif</a>
        </div>
    </div>

    <nav class="flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Website workspace">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')" @class([
                'whitespace-nowrap border-b-2 px-3 py-3 text-sm font-medium',
                'border-brand-500 text-brand-600 dark:text-brand-400' => $tab === $key,
                'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200' => $tab !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </nav>

    @if ($showPeriodBar)
        @include('livewire.demo.partials.period-bar')
        <p class="text-xs text-gray-400">Selected UI period: {{ $this->appliedPeriodLabel() }}. Evidence cards report the period stored with the latest successful provider collection.</p>
    @endif

    @if ($message !== '')
        <div @class([
            'rounded-xl px-4 py-3 text-sm ring-1 ring-inset',
            'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20' => $messageTone === 'success',
            'bg-blue-50 text-blue-800 ring-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20' => $messageTone !== 'success',
        ])>{{ $message }}</div>
    @endif

    @if ($tab === 'overview')
        @if (! $data['has_performance_data'])
            <section class="rounded-xl bg-amber-50 p-5 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/20">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="font-semibold text-amber-900 dark:text-amber-200">Needs attention · Website için henüz gerçek GA4 / Search Console Evidence yok</h2>
                        <p class="mt-1 text-sm text-amber-800/80 dark:text-amber-300/80">Bu bir demo placeholder değildir. Kaynağı bağlayın ve collection çalıştırın; KPI ve tablolar Evidence geldikçe oluşur.</p>
                    </div>
                    <a href="{{ route('operator.website.sources', ['assetId' => $asset->id]) }}" wire:navigate class="shrink-0 rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">Kaynakları bağla</a>
                </div>
            </section>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @forelse ($data['kpis'] as $kpi)
                <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="flex items-start justify-between gap-3"><p class="text-sm font-medium text-gray-500">{{ $kpi['label'] }}</p><span class="rounded bg-gray-100 px-2 py-1 text-[10px] font-semibold uppercase text-gray-500 dark:bg-gray-700">{{ $kpi['source'] }}</span></div>
                    <p class="mt-3 text-2xl font-bold text-gray-900 dark:text-white">{{ $kpi['value'] }}</p>
                    @if ($kpi['delta_label'])<p class="mt-2 text-xs text-gray-400">{{ $kpi['delta_label'] }}</p>@endif
                </section>
            @empty
                @foreach (['Organic Search', 'GA4', 'Findings', 'Recommendations'] as $label)
                    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><p class="text-sm font-medium text-gray-500">{{ $label }}</p><p class="mt-3 text-2xl font-bold text-gray-300 dark:text-gray-600">—</p><p class="mt-2 text-xs text-gray-400">Gerçek veri bekleniyor</p></section>
                @endforeach
            @endforelse
        </div>

        <div class="grid gap-4 xl:grid-cols-3">
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><h2 class="text-base font-semibold text-gray-900 dark:text-white">Needs attention</h2><p class="mt-3 text-3xl font-bold text-gray-900 dark:text-white">{{ $data['findings']['counts']['high'] }}</p><p class="mt-1 text-sm text-gray-500">High / critical open findings</p></section>
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><h2 class="text-base font-semibold text-gray-900 dark:text-white">Opportunities</h2><p class="mt-3 text-3xl font-bold text-gray-900 dark:text-white">{{ count($data['seo_opportunities'] ?? []) }}</p><p class="mt-1 text-sm text-gray-500">Observed GSC / SEO opportunities</p></section>
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><h2 class="text-base font-semibold text-gray-900 dark:text-white">Site inventory</h2><p class="mt-3 text-sm text-gray-600 dark:text-gray-300">Inventory is populated only from collected Website / Site Connector evidence.</p><a href="{{ route('operator.integrations.site-connectors') }}" wire:navigate class="mt-3 inline-flex text-sm font-medium text-brand-600 hover:underline">Site Connector →</a></section>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-gray-700"><div><h2 class="font-semibold text-gray-900 dark:text-white">Açık bulgular</h2><p class="mt-1 text-xs text-gray-400">Gerçek Finding kayıtları</p></div><a href="{{ route('operator.findings', ['asset' => $asset->id]) }}" wire:navigate class="text-xs font-medium text-brand-600">Tümü →</a></div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">@forelse ($data['findings']['open']->take(5) as $finding)<div class="px-5 py-4"><div class="flex justify-between gap-3"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $finding->title }}</p><span class="text-xs font-semibold uppercase text-rose-600">{{ $finding->severity }}</span></div><p class="mt-1 text-sm text-gray-500">{{ $finding->summary }}</p></div>@empty<div class="px-5 py-8 text-sm text-gray-500">Henüz açık Finding yok.</div>@endforelse</div>
            </section>
            <section class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-gray-700"><div><h2 class="font-semibold text-gray-900 dark:text-white">Öneriler</h2><p class="mt-1 text-xs text-gray-400">Gerçek Recommendation kayıtları</p></div><a href="{{ route('operator.recommendations', ['asset' => $asset->id]) }}" wire:navigate class="text-xs font-medium text-brand-600">Tümü →</a></div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">@forelse ($data['recommendations']->take(5) as $recommendation)<div class="px-5 py-4"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $recommendation->title }}</p><p class="mt-1 text-sm text-gray-500">{{ $recommendation->action }}</p></div>@empty<div class="px-5 py-8 text-sm text-gray-500">Henüz Recommendation yok.</div>@endforelse</div>
            </section>
        </div>
    @elseif ($tab === 'health')
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="flex items-center justify-between gap-3"><div><h2 class="text-lg font-semibold text-gray-900 dark:text-white">Website health</h2><p class="mt-1 text-sm text-gray-500">Gerçek diagnosis run ve Finding kayıtları</p></div><button type="button" wire:click="runDiagnosis" class="text-sm font-medium text-brand-600">Tekrar çalıştır</button></div>
            <p class="mt-4 text-sm font-medium text-gray-700 dark:text-gray-300">{{ $data['diagnosis']['checks_evaluated'] ?? 0 }} checks evaluated</p>
            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]"><p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $data['diagnosis']['checks_evaluated'] ?? 0 }}</p><p class="text-xs text-gray-400">checks evaluated</p></div>
                <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]"><p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $data['findings']['counts']['open'] }}</p><p class="text-xs text-gray-400">open findings</p></div>
                <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]"><p class="text-2xl font-bold text-rose-600">{{ $data['findings']['counts']['high'] }}</p><p class="text-xs text-gray-400">high severity</p></div>
            </div>
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">{{ $data['diagnosis']['summary'] ?? 'No diagnosis run yet.' }}</p>
        </section>
    @elseif ($tab === 'visibility')
        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><div class="flex items-center justify-between"><h2 class="font-semibold text-gray-900 dark:text-white">Organic Search</h2><button type="button" wire:click="refreshSeoIntelligence" class="text-sm font-medium text-brand-600">SEO intelligence yenile</button></div><p class="mt-2 text-sm text-gray-500">Search Console Evidence + DataForSEO intelligence. Paid refresh yalnız explicit action ile çalışır.</p><div class="mt-4 space-y-2">@forelse (array_slice($data['seo_opportunities'] ?? [], 0, 8) as $row)<div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/[0.03]">{{ is_array($row) ? ($row['query'] ?? $row['title'] ?? json_encode($row)) : $row }}</div>@empty<p class="text-sm text-gray-500">Henüz gerçek SEO opportunity yok.</p>@endforelse</div></section>
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><h2 class="font-semibold text-gray-900 dark:text-white">Kamu Keşif / competitors</h2><p class="mt-2 text-sm text-gray-500">Public crawl ve bounded competitor discovery ayrı gerçek workspace'te yönetilir.</p><a href="{{ route('operator.website.discovery', ['assetId' => $asset->id]) }}" wire:navigate class="mt-4 inline-flex text-sm font-medium text-violet-600">Kamu Keşif'i aç →</a></section>
        </div>
    @elseif ($tab === 'content')
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><h2 class="text-lg font-semibold text-gray-900 dark:text-white">Site inventory & Content</h2><p class="mt-2 text-sm text-gray-500">Bu alan uydurma sayfa envanteri göstermiyor. WordPress/Site Connector veya Website observation collection ile gerçek içerik envanteri geldikçe burada işlenecek.</p><div class="mt-4 flex gap-2"><a href="{{ route('operator.integrations.site-connectors') }}" wire:navigate class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white">Site Connector</a><a href="{{ route('operator.website.sources', ['assetId' => $asset->id]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300">Veri Kaynakları</a></div></section>
    @elseif ($tab === 'performance')
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><h2 class="text-lg font-semibold text-gray-900 dark:text-white">Performance</h2><p class="mt-1 text-sm text-gray-500">GA4 + Search Console Evidence</p><div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">@forelse ($data['kpis'] as $kpi)<div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ $kpi['label'] }}</p><p class="mt-2 text-xl font-bold text-gray-900 dark:text-white">{{ $kpi['value'] }}</p><p class="mt-1 text-xs text-gray-400">{{ strtoupper($kpi['source']) }}</p></div>@empty<p class="text-sm text-gray-500 sm:col-span-2">Henüz provider Evidence yok.</p>@endforelse</div></section>
    @elseif ($tab === 'infrastructure')
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Infrastructure</h2>
            <p class="mt-2 text-sm text-gray-500">Domain, DNS, hosting, CDN, SSL ve CMS Website Digital Asset'in altyapısıdır; not standalone assets.</p>
            <dl class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]"><dt class="text-xs text-gray-400">Domain</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $asset->domain ?: '—' }}</dd></div>
                <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]"><dt class="text-xs text-gray-400">Primary URL</dt><dd class="mt-1 break-all font-medium text-gray-900 dark:text-white">{{ $asset->primary_url ?: '—' }}</dd></div>
                <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]"><dt class="text-xs text-gray-400">CMS</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $asset->cms ?: 'Not collected' }}</dd></div>
                <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]"><dt class="text-xs text-gray-400">Hosting</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $asset->hosting_context ?: 'Not collected' }}</dd></div>
                <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]"><dt class="text-xs text-gray-400">SSL / TLS</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">Not collected</dd></div>
                <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]"><dt class="text-xs text-gray-400">DNS / CDN</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">Not collected</dd></div>
            </dl>
        </section>
    @elseif ($tab === 'operations')
        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><h2 class="font-semibold text-gray-900 dark:text-white">Findings</h2><div class="mt-4 space-y-3">@forelse ($data['findings']['open']->take(10) as $finding)<div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $finding->title }}</p><p class="mt-1 text-xs text-gray-500">{{ $finding->severity }} · {{ $finding->status }}</p></div>@empty<p class="text-sm text-gray-500">Açık Finding yok.</p>@endforelse</div></section>
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><h2 class="font-semibold text-gray-900 dark:text-white">Recommendations</h2><div class="mt-4 space-y-3">@forelse ($data['recommendations']->take(10) as $recommendation)<div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $recommendation->title }}</p><p class="mt-1 text-xs text-gray-500">{{ $recommendation->priority }} · {{ $recommendation->status }}</p></div>@empty<p class="text-sm text-gray-500">Recommendation yok.</p>@endforelse</div></section>
        </div>
    @elseif ($tab === 'setup')
        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><h2 class="font-semibold text-gray-900 dark:text-white">Connections</h2><div class="mt-4 space-y-3">@foreach ($data['connections'] as $source)<div class="flex items-center justify-between rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><div><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $source['label'] }}</p><p class="mt-1 text-xs text-gray-500">{{ $source['display_name'] ?: ($source['subtitle'] ?? '—') }}</p></div><span class="text-xs font-semibold {{ $source['connected'] ? 'text-emerald-600' : 'text-gray-400' }}">{{ $source['connected'] ? 'Connected' : 'Not connected' }}</span></div>@endforeach</div><a href="{{ route('operator.website.sources', ['assetId' => $asset->id]) }}" wire:navigate class="mt-4 inline-flex text-sm font-medium text-brand-600">Tüm veri kaynaklarını yönet →</a></section>
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><h2 class="font-semibold text-gray-900 dark:text-white">Website configuration</h2><dl class="mt-4 space-y-3 text-sm"><div><dt class="text-gray-400">Search market</dt><dd class="font-medium text-gray-800 dark:text-white">{{ $asset->seo_market_location_name ?: 'Not configured' }} · {{ $asset->seo_market_language_name ?: 'Not configured' }}</dd></div><div><dt class="text-gray-400">Languages</dt><dd class="font-medium text-gray-800 dark:text-white">{{ implode(', ', $asset->languages ?? []) ?: '—' }}</dd></div><div><dt class="text-gray-400">Target countries</dt><dd class="font-medium text-gray-800 dark:text-white">{{ implode(', ', $asset->target_countries ?? []) ?: '—' }}</dd></div></dl></section>
        </div>
    @endif
</div>
