@php
    $title = $title ?? 'Nothing here yet';
    $message = $message ?? 'This panel has no data to show right now.';
    $ctaLabel = $cta_label ?? null;
    $ctaHref = $cta_href ?? null;
@endphp

<x-ta.empty-state :title="$title" :message="$message">
    @if ($ctaLabel && $ctaHref)
        <x-ta.button :href="$ctaHref" size="sm" variant="outline">{{ $ctaLabel }}</x-ta.button>
    @endif
</x-ta.empty-state>
