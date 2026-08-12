<div class="space-y-6">
    @include('livewire.demo.partials.flash')
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <x-ta.button href="{{ route('demo.meta.campaigns', ['assetId' => $assetId]) }}" size="sm" variant="outline">← Campaigns</x-ta.button>
            <h1 class="mt-3 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $campaign['name'] }}</h1>
            <p class="text-sm text-gray-500">{{ $campaign['status'] }} · {{ $campaign['objective'] }}</p>
        </div>
        @include('livewire.demo.partials.period-bar')
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($campaign['kpis'] as $kpi)
            @include('livewire.demo.partials.kpi', ['kpi' => $kpi])
        @endforeach
    </div>

    <x-ta.card>
        <h2 class="mb-4 font-semibold text-gray-800 dark:text-white/90">Ad sets</h2>
        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            @foreach ($campaign['adsets'] as $adset)
                <li class="flex items-center justify-between py-3">
                    <div>
                        <p class="font-medium text-gray-800 dark:text-white/90">{{ $adset['name'] }}</p>
                        <p class="text-sm text-gray-500">₺{{ number_format($adset['spend']) }} · {{ $adset['results'] }} results · CTR {{ $adset['ctr'] }}%</p>
                    </div>
                    <x-ta.button :href="route('demo.meta.adset', ['assetId' => $assetId, 'adSetId' => $adset['id']])" size="sm" variant="outline">Open</x-ta.button>
                </li>
            @endforeach
        </ul>
    </x-ta.card>
</div>