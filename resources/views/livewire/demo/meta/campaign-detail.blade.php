<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Meta Ads',
        'title' => $campaign['name'],
        'subtitle' => ($campaign['status'] ?? '').' · '.($campaign['objective'] ?? '').' · Connected provider',
        'badges' => ['Connected provider'],
    ])

    @include('livewire.demo.partials.meta-asset-nav', ['assetId' => $assetId, 'active' => 'campaigns'])
    @include('livewire.demo.partials.period-bar')

    @include('livewire.demo.partials.kpi-strip', [
        'kpis' => collect($campaign['kpis'])->map(fn ($kpi) => array_merge([
            'family' => match ($kpi['label'] ?? '') {
                'Spend' => 'spend',
                'Cost / Result' => 'efficiency',
                'Link CTR' => 'delivery',
                default => 'result',
            },
        ], $kpi))->all(),
        'primaryCount' => 4,
    ])

    <x-ta.card>
        @include('livewire.demo.partials.section-question', [
            'question' => 'Which ad sets are delivering?',
            'hint' => 'Open an ad set to inspect related creatives.',
        ])
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
