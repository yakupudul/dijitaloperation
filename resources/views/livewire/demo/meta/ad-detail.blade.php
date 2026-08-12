<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Meta Ads · '.($ad['name'] ?? $creative['name']),
        'title' => $creative['name'],
        'subtitle' => ($creative['format'] ?? '').' · '.($creative['campaign'] ?? '').' · Connected provider',
        'badges' => ['Connected provider'],
    ])

    @include('livewire.demo.partials.meta-asset-nav', ['assetId' => $assetId, 'active' => 'ads'])
    @include('livewire.demo.partials.period-bar')

    <div class="grid gap-4 lg:grid-cols-3">
        <x-ta.card class="lg:col-span-1">
            @php
                $gradient = match (strtolower((string) ($creative['format'] ?? 'image'))) {
                    'video' => 'from-orange-500/30 via-amber-400/20 to-stone-300/40 dark:to-stone-800/60',
                    'carousel' => 'from-sky-500/25 via-cyan-400/15 to-slate-300/40 dark:to-slate-800/60',
                    default => 'from-emerald-500/25 via-teal-400/15 to-zinc-300/40 dark:to-zinc-800/60',
                };
            @endphp
            <div @class(['flex h-48 items-center justify-center rounded-xl bg-gradient-to-br', $gradient])>
                <span class="rounded-md bg-black/35 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-white">
                    {{ strtoupper($creative['preview'] ?? $creative['format']) }} preview
                </span>
            </div>
            <h3 class="mt-4 font-semibold text-gray-800 dark:text-white/90">{{ $creative['headline'] }}</h3>
            <p class="mt-1 text-sm text-gray-500">{{ $creative['copy'] }}</p>
            <p class="mt-2 text-xs text-gray-400">CTA: {{ $creative['cta'] }} · {{ $creative['destination'] }}</p>
        </x-ta.card>
        <div class="grid gap-4 sm:grid-cols-2 lg:col-span-2">
            @include('livewire.demo.partials.kpi', ['kpi' => ['label' => 'Spend', 'value' => $ad['spend'] ?? $creative['spend'], 'format' => 'try', 'family' => 'spend', 'tone' => ($creative['attention'] ?? null) ? 'bad' : 'neutral', 'delta' => ($creative['attention'] ?? null) ? 22.0 : 3.0]])
            @include('livewire.demo.partials.kpi', ['kpi' => ['label' => $creative['result_label'], 'value' => $ad['results'] ?? $creative['result'], 'format' => 'int', 'family' => 'result']])
            @include('livewire.demo.partials.kpi', ['kpi' => ['label' => 'Cost / Result', 'value' => $ad['cost_result'] ?? $creative['cost_result'], 'format' => 'try', 'family' => 'efficiency', 'tone' => ($creative['attention'] ?? null) ? 'bad' : 'good']])
            @include('livewire.demo.partials.kpi', ['kpi' => ['label' => 'Link CTR', 'value' => $ad['ctr'] ?? $creative['ctr'], 'format' => 'pct', 'family' => 'delivery', 'tone' => ($creative['attention'] ?? null) ? 'bad' : 'good']])
        </div>
    </div>
</div>
