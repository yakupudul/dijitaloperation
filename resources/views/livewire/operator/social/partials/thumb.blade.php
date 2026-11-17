{{-- A light cover picture (SocialThumbs); never the video itself. $size = Tailwind size classes, $fallback = a picture
     to use when no cover could be made (the Instagram thumbnail of a live video). --}}
@php
    $cover = $thumbs->cover($post);
    $src = $cover['url'] ?? ($fallback ?? null);
@endphp
<span class="relative block shrink-0 overflow-hidden rounded-md border border-gray-200 bg-gray-100 dark:border-gray-800 dark:bg-white/5 {{ $size ?? 'h-11 w-9' }}" data-social-thumb>
    @if ($src)
        <img src="{{ $src }}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" class="h-full w-full object-cover">
    @else
        <span class="flex h-full w-full items-center justify-center text-xs text-gray-400" aria-hidden="true">{{ $post->kind === 'story' ? '○' : ($post->kind === 'text' ? '≡' : '▶') }}</span>
    @endif
</span>
