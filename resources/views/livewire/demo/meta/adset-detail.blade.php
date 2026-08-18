<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    @include('livewire.demo.partials.workspace-header', [
        'eyebrow' => 'Meta Ads',
        'title' => $adset['name'] ?? 'Ad set',
        'subtitle' => ($adset['status'] ?? '').' · Connected provider',
        'badges' => ['Connected provider'],
    ])

    @include('livewire.demo.partials.meta-asset-nav', ['assetId' => $assetId, 'active' => 'adsets'])
    @include('livewire.demo.partials.period-bar')

    @include('livewire.demo.partials.kpi-strip', [
        'kpis' => [
            ['label' => 'Spend', 'value' => $adset['spend'] ?? 0, 'format' => 'try', 'family' => 'spend'],
            ['label' => 'Results', 'value' => $adset['results'] ?? 0, 'format' => 'int', 'family' => 'result'],
            ['label' => 'CTR', 'value' => $adset['ctr'] ?? 0, 'format' => 'pct', 'family' => 'delivery'],
        ],
        'primaryCount' => 3,
    ])

    <x-ta.card>
        @include('livewire.demo.partials.section-question', [
            'question' => 'Which creatives sit under this ad set?',
            'hint' => 'Related creatives from the demo catalog.',
        ])
        <ul class="space-y-3">
            @foreach ($creatives as $creative)
                <li class="flex items-center justify-between">
                    <span class="text-sm text-gray-800 dark:text-white/90">{{ $creative['name'] }} · {{ $creative['format'] }}</span>
                    <x-ta.button :href="route('operator.meta.ad', ['assetId' => $assetId, 'adId' => $creative['id']])" size="sm" variant="outline">Open</x-ta.button>
                </li>
            @endforeach
        </ul>
    </x-ta.card>
</div>
