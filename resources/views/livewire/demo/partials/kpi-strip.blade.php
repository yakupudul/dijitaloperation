@php
    $kpis = $kpis ?? [];
    $primaryCount = (int) ($primaryCount ?? 3);
    $count = max(count($kpis), 1);
    $cols = match (true) {
        $count <= 2 => 'sm:grid-cols-2',
        $count === 3 => 'sm:grid-cols-2 xl:grid-cols-3',
        $count === 4 => 'sm:grid-cols-2 xl:grid-cols-4',
        default => 'sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-5',
    };
@endphp

<div @class(['grid gap-4', $cols])>
    @foreach ($kpis as $index => $kpi)
        @include('livewire.demo.partials.kpi', [
            'kpi' => $kpi,
            'large' => $index < $primaryCount,
        ])
    @endforeach
</div>
