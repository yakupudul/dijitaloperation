<x-filament-widgets::widget>
    @if (count($items) > 0)
        <div class="mox-workspace-summary" role="list" aria-label="Brand operational summary">
            @foreach ($items as $item)
                <div class="mox-workspace-summary__item" role="listitem">
                    <div class="mox-workspace-summary__label">{{ $item['label'] }}</div>
                    <div class="mox-workspace-summary__value">{{ $item['value'] }}</div>
                    <div class="mox-workspace-summary__hint">{{ $item['hint'] }}</div>
                </div>
            @endforeach
        </div>
    @endif
</x-filament-widgets::widget>
