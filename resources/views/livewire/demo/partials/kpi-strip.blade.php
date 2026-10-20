@php
    $kpis = $kpis ?? [];
    $primaryCount = (int) ($primaryCount ?? 3);
    $count = max(count($kpis), 1);
    $cols = match (true) {
        $count <= 2 => 'grid-cols-2',
        $count === 3 => 'grid-cols-2 sm:grid-cols-3',
        $count === 4 => 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4',
        default => 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-5',
    };
@endphp

<div @class(['grid gap-x-8 gap-y-6', $cols])>
    @foreach ($kpis as $index => $kpi)
        @include('livewire.demo.partials.kpi', [
            'kpi' => $kpi,
            'large' => $index < $primaryCount,
        ])
    @endforeach
</div>
