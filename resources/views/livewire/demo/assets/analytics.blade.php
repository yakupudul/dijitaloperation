<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-gray-500">Google Analytics · {{ $asset['name'] ?? 'Atlas Dental GA4' }}</p>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">Overview</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $data['period_label'] }} · Connected provider · Demo Mode</p>
        </div>
        <x-ta.button href="{{ route('demo.website') }}" size="sm" variant="outline">Website workspace</x-ta.button>
    </div>

    @include('livewire.demo.partials.period-bar')

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ($data['kpis'] as $kpi)
            @include('livewire.demo.partials.kpi', ['kpi' => $kpi])
        @endforeach
    </div>

    <x-ta.chart-card title="Sessions by source" :options="$chartOptions" />

    <div class="grid gap-4 md:grid-cols-3">
        @foreach ($data['devices'] as $device => $share)
            <x-ta.metric-card :label="ucfirst($device)" :value="$share.'%'" />
        @endforeach
    </div>
</div>