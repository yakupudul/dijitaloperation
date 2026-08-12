<div class="space-y-6">
    @include('livewire.demo.partials.flash')
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <x-ta.button href="{{ route('demo.meta.creatives', ['assetId' => $assetId]) }}" size="sm" variant="outline">← Creatives</x-ta.button>
            <h1 class="mt-3 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $creative['name'] }}</h1>
            <p class="text-sm text-gray-500">{{ $creative['format'] }} · {{ $creative['campaign'] }}</p>
        </div>
        @include('livewire.demo.partials.period-bar')
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <x-ta.card class="lg:col-span-1">
            <div class="flex h-48 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500/20 to-gray-200 dark:to-gray-800">
                <span class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ strtoupper($creative['preview']) }} preview</span>
            </div>
            <h3 class="mt-4 font-semibold text-gray-800 dark:text-white/90">{{ $creative['headline'] }}</h3>
            <p class="mt-1 text-sm text-gray-500">{{ $creative['copy'] }}</p>
            <p class="mt-2 text-xs text-gray-400">CTA: {{ $creative['cta'] }} · {{ $creative['destination'] }}</p>
        </x-ta.card>
        <div class="grid gap-4 sm:grid-cols-2 lg:col-span-2">
            @include('livewire.demo.partials.kpi', ['kpi' => ['label' => 'Spend', 'value' => $creative['spend'], 'format' => 'try', 'family' => 'spend', 'tone' => $creative['attention'] ? 'bad' : 'neutral', 'delta' => $creative['attention'] ? 22.0 : 3.0]])
            @include('livewire.demo.partials.kpi', ['kpi' => ['label' => $creative['result_label'], 'value' => $creative['result'], 'format' => 'int', 'family' => 'result']])
            @include('livewire.demo.partials.kpi', ['kpi' => ['label' => 'Cost / Result', 'value' => $creative['cost_result'], 'format' => 'try', 'family' => 'efficiency', 'tone' => $creative['attention'] ? 'bad' : 'good']])
            @include('livewire.demo.partials.kpi', ['kpi' => ['label' => 'Link CTR', 'value' => $creative['ctr'], 'format' => 'pct', 'family' => 'delivery', 'tone' => $creative['attention'] ? 'bad' : 'good']])
        </div>
    </div>
</div>