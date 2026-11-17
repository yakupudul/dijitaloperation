{{-- One post as a row: time, cover, name, channels, state. The whole row opens the side panel. $when = 'time' | 'date'. --}}
@php
    [$stateLabel, $stateTone] = $state($post);
    $at = $post->publish_at->timezone($tz);
    $linePlace = $place($post);
    $insight = null;
    foreach ($post->targets as $t) {
        $insight ??= \App\Services\Social\SocialInsights::line($t);
    }
@endphp
<button type="button" wire:key="row-{{ $post->id }}" wire:click="openPost({{ $post->id }})" class="card-row w-full text-left transition hover:bg-gray-50 dark:hover:bg-white/[0.03]" data-social-post="{{ $post->id }}">
    <span class="w-[52px] shrink-0 text-sm tabular-nums {{ ($when ?? 'time') === 'date' ? 'sm:w-[104px]' : '' }}">
        @if (($when ?? 'time') === 'date')
            <span class="block font-medium text-gray-900 dark:text-white">{{ $at->translatedFormat('j M') }}</span><span class="block text-xs text-gray-500">{{ $at->translatedFormat('D') }} {{ $at->format('H:i') }}</span>
        @else
            <span class="font-medium text-gray-900 dark:text-white">{{ $at->format('H:i') }}</span>
        @endif
    </span>
    @include('livewire.operator.social.partials.thumb', ['post' => $post, 'size' => 'h-11 w-9'])
    <span class="min-w-0 flex-1">
        <span class="line-clamp-2 text-sm font-medium text-gray-900 sm:line-clamp-1 dark:text-white">{{ $post->title ?: (mb_strimwidth((string) ($post->caption ?: $post->threads_text ?: $post->linkedin_text), 0, 90, '…') ?: 'Adsız gönderi') }}</span>
        <span class="block truncate text-xs text-gray-500">{{ $kindName($post) }}{{ $post->pillarLabel() ? ' · '.$post->pillarLabel() : '' }}@if ($linePlace) · <span data-line-place>{{ $linePlace }}</span>@endif @if ($post->pinned && $post->isEditable()) · <span data-pinned>Sabit tarih</span>@endif @if ($insight) · {{ $insight }}@endif</span>
    </span>
    <span class="hidden sm:block">@include('livewire.operator.social.partials.channels', ['post' => $post])</span>
    <span class="chip shrink-0 {{ $stateTone }}">{{ $stateLabel }}</span>
</button>
