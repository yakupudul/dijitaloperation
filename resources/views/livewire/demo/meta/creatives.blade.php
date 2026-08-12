<div class="space-y-6">
    @include('livewire.demo.partials.flash')
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <x-ta.button href="{{ route('demo.meta.overview', ['assetId' => $assetId]) }}" size="sm" variant="outline">← Overview</x-ta.button>
            <h1 class="mt-3 text-2xl font-bold text-gray-800 dark:text-white/90">Creatives</h1>
        </div>
        @include('livewire.demo.partials.period-bar')
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($creatives as $creative)
            <x-ta.card>
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <h3 class="font-semibold text-gray-800 dark:text-white/90">{{ $creative['name'] }}</h3>
                        <p class="text-sm text-gray-500">{{ $creative['format'] }} · {{ $creative['campaign'] }}</p>
                    </div>
                    @if ($creative['attention'] ?? null)
                        <x-ta.badge color="error" size="sm">Fatigue</x-ta.badge>
                    @endif
                </div>
                <dl class="mt-4 grid grid-cols-2 gap-2 text-sm">
                    <div><dt class="text-gray-400">Spend</dt><dd class="font-medium">₺{{ number_format($creative['spend']) }}</dd></div>
                    <div><dt class="text-gray-400">CTR</dt><dd class="font-medium">{{ $creative['ctr'] }}%</dd></div>
                    <div><dt class="text-gray-400">{{ $creative['result_label'] }}</dt><dd class="font-medium">{{ number_format($creative['result']) }}</dd></div>
                    <div><dt class="text-gray-400">Cost</dt><dd class="font-medium">₺{{ number_format($creative['cost_result']) }}</dd></div>
                </dl>
                <div class="mt-4">
                    <x-ta.button :href="route('demo.meta.ad', ['assetId' => $assetId, 'adId' => $creative['id']])" size="sm">Open creative</x-ta.button>
                </div>
            </x-ta.card>
        @endforeach
    </div>
</div>