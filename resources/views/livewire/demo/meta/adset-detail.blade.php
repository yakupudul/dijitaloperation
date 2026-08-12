<div class="space-y-6">
    @include('livewire.demo.partials.flash')
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <x-ta.button href="{{ route('demo.meta.campaign', ['assetId' => $assetId, 'campaignId' => $campaignId]) }}" size="sm" variant="outline">← Campaign</x-ta.button>
            <h1 class="mt-3 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $adset['name'] ?? 'Ad set' }}</h1>
            <p class="text-sm text-gray-500">{{ $adset['status'] ?? '' }} · Demo Mode</p>
        </div>
        @include('livewire.demo.partials.period-bar')
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        @include('livewire.demo.partials.kpi', ['kpi' => ['label' => 'Spend', 'value' => $adset['spend'] ?? 0, 'format' => 'try', 'family' => 'spend']])
        @include('livewire.demo.partials.kpi', ['kpi' => ['label' => 'Results', 'value' => $adset['results'] ?? 0, 'format' => 'int', 'family' => 'result']])
        @include('livewire.demo.partials.kpi', ['kpi' => ['label' => 'CTR', 'value' => $adset['ctr'] ?? 0, 'format' => 'pct', 'family' => 'delivery']])
    </div>

    <x-ta.card>
        <h2 class="mb-4 font-semibold text-gray-800 dark:text-white/90">Related creatives</h2>
        <ul class="space-y-3">
            @foreach ($creatives as $creative)
                <li class="flex items-center justify-between">
                    <span class="text-sm text-gray-800 dark:text-white/90">{{ $creative['name'] }} · {{ $creative['format'] }}</span>
                    <x-ta.button :href="route('demo.meta.ad', ['assetId' => $assetId, 'adId' => $creative['id']])" size="sm" variant="outline">Open</x-ta.button>
                </li>
            @endforeach
        </ul>
    </x-ta.card>
</div>