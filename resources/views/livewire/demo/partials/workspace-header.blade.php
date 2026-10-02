@php
    $eyebrow = $eyebrow ?? null;
    $title = $title ?? '';
    $subtitle = $subtitle ?? null;
    $badges = $badges ?? null;
@endphp

<div class="flex flex-wrap items-start justify-between gap-4">
    <div class="min-w-0 flex-1">
        @if ($eyebrow)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $eyebrow }}</p>
        @endif
        <div class="mt-0.5 flex flex-wrap items-center gap-2">
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">{{ $title }}</h1>
            @isset($badges)
                @if (is_array($badges))
                    @foreach ($badges as $badgeLabel)
                        @include('livewire.demo.partials.provenance-badge', ['label' => $badgeLabel])
                    @endforeach
                @else
                    {!! $badges !!}
                @endif
            @endisset
        </div>
        @if ($subtitle)
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">
            {!! $actions !!}
        </div>
    @endisset
</div>
