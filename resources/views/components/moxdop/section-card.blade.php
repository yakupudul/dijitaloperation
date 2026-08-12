@props([
    'title' => null,
    'description' => null,
])

{{-- General-purpose bordered card with an optional header (Card standard). --}}
<section {{ $attributes->class(['mox-section-card']) }}>
    @if (filled($title) || filled($description))
        <div class="mox-section-card__head">
            <div>
                @if (filled($title))
                    <h4 class="mox-section-card__title">{{ $title }}</h4>
                @endif

                @if (filled($description))
                    <p class="mox-section-card__description">{{ $description }}</p>
                @endif
            </div>
        </div>
    @endif

    {{ $slot }}
</section>
