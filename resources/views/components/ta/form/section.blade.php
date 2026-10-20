@props(['title', 'subtitle' => null])

<section {{ $attributes->merge(['class' => 'card p-5']) }}>
    <div class="mb-4">
        <h2 class="section-title">{{ $title }}</h2>
        @if ($subtitle)
            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $subtitle }}</p>
        @endif
    </div>
    <div class="space-y-4">
        {{ $slot }}
    </div>
</section>
