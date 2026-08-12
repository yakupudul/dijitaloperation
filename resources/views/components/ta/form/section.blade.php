@props(['title', 'subtitle' => null])

<section {{ $attributes->merge(['class' => 'rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700']) }}>
    <div class="mb-4">
        <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $title }}</h2>
        @if ($subtitle)
            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ $subtitle }}</p>
        @endif
    </div>
    <div class="space-y-4">
        {{ $slot }}
    </div>
</section>
