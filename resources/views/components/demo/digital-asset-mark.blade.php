@props([
    'type' => 'ga4',
    'size' => 'md',
    'asset' => null,
    'decorative' => true,
])

@php
    use App\Support\DigitalAssetVisualCatalog;

    $visual = is_array($asset)
        ? DigitalAssetVisualCatalog::resolve($asset)
        : DigitalAssetVisualCatalog::forType((string) $type);

    $sizeClass = match ($size) {
        'xs' => 'h-6 w-6',
        'sm' => 'h-7 w-7',
        'lg' => 'h-11 w-11',
        'xl' => 'h-12 w-12',
        default => 'h-8 w-8',
    };

    $imgClass = match ($size) {
        'xs' => 'h-4 w-4',
        'sm' => 'h-4.5 w-4.5',
        'lg' => 'h-7 w-7',
        'xl' => 'h-8 w-8',
        default => 'h-5 w-5',
    };

    $a11y = $visual['a11y'] ?? 'Digital Asset';
@endphp

<span
    {{ $attributes->class([
        'inline-flex shrink-0 items-center justify-center overflow-hidden rounded-xl ring-1 ring-inset ring-gray-200 dark:ring-white/10',
        $sizeClass,
        $visual['container'] ?? 'bg-white dark:bg-white/95',
    ]) }}
    @if ($decorative) aria-hidden="true" @else role="img" aria-label="{{ $a11y }}" @endif
    data-asset-mark="{{ $visual['type'] }}"
    data-mark-source="{{ $visual['source'] ?? 'provider_mark' }}"
>
    <img
        src="{{ $visual['asset_path'] }}"
        alt=""
        width="32"
        height="32"
        class="{{ $imgClass }} object-contain"
        loading="lazy"
        decoding="async"
        onerror="this.classList.add('hidden'); this.nextElementSibling?.classList.remove('hidden');"
    />
    <span class="hidden text-[10px] font-semibold text-slate-600 dark:text-slate-200">{{ $visual['fallback_initials'] }}</span>
</span>
