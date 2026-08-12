@props([
    'title',
    'description' => null,
])

{{--
    Chart container. "title" must name the operator question the chart
    answers (e.g. "How is spend trending over the period?") — never a
    generic "Chart" label (no decorative charts, docs/product/MOXDOP_DESIGN_SYSTEM.md).
--}}
<section {{ $attributes->class(['mox-chart-card']) }}>
    <div class="mox-chart-card__head">
        <div>
            <h4 class="mox-chart-card__title">{{ $title }}</h4>

            @if (filled($description))
                <p class="mox-chart-card__description">{{ $description }}</p>
            @endif
        </div>

        @isset($toolbar)
            <div class="mox-chart-card__toolbar">
                {{ $toolbar }}
            </div>
        @endisset
    </div>

    {{ $slot }}
</section>
