@php
    $from = ($list['page'] - 1) * \App\Services\Social\SocialScreen::PER_PAGE + 1;
    $to = min($list['total'], $list['page'] * \App\Services\Social\SocialScreen::PER_PAGE);
@endphp
<div class="no-scrollbar -mx-4 flex gap-1.5 overflow-x-auto px-4 sm:mx-0 sm:flex-wrap sm:px-0" data-social-status-filter>
    @foreach ($statusFilters as $key => $label)
        <button type="button" wire:click="$set('status', '{{ $key }}')" @class(['seg h-8', 'seg-active' => $status === $key])>{{ $label }} <span @class(['text-xs tabular-nums', 'text-accent' => $status === $key, 'text-gray-400 dark:text-gray-500' => $status !== $key])>{{ $list['counts'][$key] ?? 0 }}</span></button>
    @endforeach
</div>
<section class="card" data-social-list>
    @forelse ($list['rows'] as $post)
        @include('livewire.operator.social.partials.row', ['post' => $post, 'when' => 'date'])
    @empty
        <p class="px-4 py-10 text-center text-sm text-gray-500">{{ $status === 'yayinda' ? 'Henüz yayınlanan gönderi yok.' : 'Bu süzgeçte gönderi yok.' }}</p>
    @endforelse
</section>
@if ($list['total'] > 0)
    <div class="flex flex-wrap items-center justify-between gap-3 text-[13px] text-gray-500" data-social-pager>
        <p class="tabular-nums">{{ $from }}–{{ $to }} / {{ $list['total'] }} gönderi</p>
        @if ($list['pages'] > 1)
            <div class="flex items-center gap-1.5">
                <button type="button" wire:click="turn({{ $list['page'] - 1 }})" class="btn" @disabled($list['page'] <= 1)>‹ Önceki</button>
                <span class="px-1 tabular-nums">{{ $list['page'] }} / {{ $list['pages'] }}</span>
                <button type="button" wire:click="turn({{ $list['page'] + 1 }})" class="btn" @disabled($list['page'] >= $list['pages'])>Sonraki ›</button>
            </div>
        @endif
    </div>
@endif
