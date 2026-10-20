{{-- Durum ve ölçüm: one profile's own gaps (each links to the tab that fixes it), or "Özel eksik yok". --}}
<span class="{{ $class }}">
    @forelse ($ownGaps($row) as $check)
        <a href="{{ route($check['route'], array_filter(['marka' => $location->brand_id])) }}" wire:navigate x-on:click.stop title="{{ $check['label'] }}: {{ $check['hint'] }}"
            @class(['inline-flex items-center gap-1.5 text-[13px] hover:underline', 'text-gray-500' => $check['unknown'], 'text-warning-800 dark:text-warning-400' => ! $check['unknown']])><span @class(['h-1.5 w-1.5 shrink-0 rounded-full', 'bg-gray-300 dark:bg-gray-600' => $check['unknown'], 'bg-warning-500' => ! $check['unknown']]) aria-hidden="true"></span>{{ $check['label'] }}@if ($check['unknown'])<span class="sr-only"> (veri yok)</span>@endif</a>
    @empty
        <x-ta.pill tone="ok" title="{{ collect($row['checks'])->filter(fn (array $c): bool => $c['ok'])->pluck('label')->join(', ') }}">{{ $shared !== [] ? 'Özel eksik yok' : 'Tamam' }}</x-ta.pill>
    @endforelse
</span>
