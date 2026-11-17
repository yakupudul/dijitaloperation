{{-- Takvim: one week as seven columns (a phone shows a day strip and one day), or a month of counts. --}}
@php
    $short = ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'];
    $weekEnd = $weekStart->copy()->addDays(6);
    $title = $calendar === 'ay'
        ? $monthOf->translatedFormat('F Y')
        : ($weekStart->month === $weekEnd->month ? $weekStart->format('j').' – '.$weekEnd->translatedFormat('j F Y') : $weekStart->translatedFormat('j F').' – '.$weekEnd->translatedFormat('j F Y'));
    $weekDrafts = $calendar === 'hafta' ? collect($days)->flatten(1)->where('status', 'draft')->count() : 0;
    $cell = function ($post) use ($state, $kindMark, $tz): array {
        $done = in_array($post->status, ['published', 'partial'], true);

        return [
            'time' => $post->publish_at->timezone($tz)->format('H:i'),
            'note' => match (true) { $post->status === 'draft' => 'onay bekliyor', in_array($post->status, ['failed', 'partial'], true) => 'yayınlanamadı', $post->status === 'expired' => 'onaylanmadı', $post->pinned && ! $done => 'sabit', default => '' },
            'class' => match (true) {
                in_array($post->status, ['failed', 'partial'], true) => 'border-error-300 bg-error-50/60 dark:border-error-500/40 dark:bg-error-500/10',
                $post->status === 'draft' || $post->status === 'expired' => 'border-warning-300 bg-warning-50/60 dark:border-warning-500/40 dark:bg-warning-500/10',
                $done => 'border-gray-200 bg-gray-50 text-gray-500 dark:border-gray-800 dark:bg-white/[0.02]',
                default => 'border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.04]',
            },
            'mark' => $kindMark($post),
        ];
    };
@endphp
<div class="flex flex-wrap items-center justify-between gap-3" data-social-calendar-head>
    <div class="flex items-center gap-2">
        <button type="button" wire:click="shift(-1)" class="btn w-8 px-0" aria-label="Önceki">‹</button>
        <h2 class="section-title min-w-[150px] text-center">{{ $title }}</h2>
        <button type="button" wire:click="shift(1)" class="btn w-8 px-0" aria-label="Sonraki">›</button>
        <button type="button" wire:click="shift(0)" class="btn-quiet">Bu hafta</button>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        @if ($calendar === 'hafta')
            <label class="btn cursor-pointer gap-2 font-normal"><input type="checkbox" wire:model.live="stories" class="rounded border-gray-300 dark:border-gray-700"> Hikâyeleri göster</label>
            @if ($canWrite && $weekDrafts > 0)
                <button type="button" class="btn-primary" x-on:click="if (confirm('Bu haftanın onay bekleyen {{ $weekDrafts }} gönderisi onaylansın mı? Paylaşım düzenindeki günlere sırayla yerleşir.')) $wire.approveWeek('{{ $weekStart->format('Y-m-d') }}')">Haftayı onayla ({{ $weekDrafts }})</button>
            @endif
        @endif
        <span class="inline-flex gap-1">
            <button type="button" wire:click="$set('calendar', 'hafta')" @class(['btn', 'border-second bg-second-soft text-second' => $calendar === 'hafta'])>Hafta</button>
            <button type="button" wire:click="$set('calendar', 'ay')" @class(['btn', 'border-second bg-second-soft text-second' => $calendar === 'ay'])>Ay</button>
        </span>
    </div>
</div>

@if ($calendar === 'ay')
    <div class="card" data-social-month>
        <div class="grid grid-cols-7 border-b border-gray-200 bg-gray-50 text-center text-xs font-medium text-gray-500 dark:border-gray-800 dark:bg-white/[0.02]">
            @foreach ($short as $name)<div class="px-1 py-2">{{ $name }}</div>@endforeach
        </div>
        <div class="grid grid-cols-7">
            @foreach ($month as $key => $c)
                @php $d = \Illuminate\Support\Carbon::parse($key, $tz); $inMonth = $d->month === $monthOf->month; $sum = $c['post'] + $c['video'] + $c['story']; @endphp
                <button type="button" wire:click="pickDay('{{ $key }}')" @class(['min-h-[74px] border-b border-r border-gray-200 p-1.5 text-left align-top transition hover:bg-gray-50 sm:min-h-[96px] sm:p-2.5 dark:border-gray-800 dark:hover:bg-white/[0.03]', 'opacity-40' => ! $inMonth]) data-month-day="{{ $key }}">
                    <span @class(['inline-flex h-6 min-w-6 items-center justify-center rounded-full px-1 text-[13px] font-semibold tabular-nums', 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $key === $todayKey])>{{ $d->day }}</span>
                    @if ($sum > 0)
                        <span class="mt-1 block text-xs text-gray-600 dark:text-gray-300">{{ $sum }}<span class="hidden sm:inline"> paylaşım</span></span>
                        <span class="mt-0.5 hidden text-[11px] text-gray-500 sm:block">{{ collect(['□ '.$c['post'] => $c['post'], '▶ '.$c['video'] => $c['video'], '○ '.$c['story'] => $c['story']])->filter()->keys()->implode('  ') }}</span>
                        @if ($c['failed'] > 0)<span class="mt-0.5 block text-[11px] font-medium text-error-600">{{ $c['failed'] }} sorunlu</span>@elseif ($c['draft'] > 0)<span class="mt-0.5 block text-[11px] font-medium text-warning-700 dark:text-warning-400">{{ $c['draft'] }} onay<span class="hidden sm:inline"> bekliyor</span></span>@endif
                    @endif
                </button>
            @endforeach
        </div>
    </div>
    <p class="text-[13px] text-gray-500">Bir güne basınca o günün haftası açılır.</p>
@else
    {{-- Phone: the days as a strip, one day's posts under it. --}}
    <div class="md:hidden" data-social-week-phone>
        <div class="grid grid-cols-7 gap-1">
            @foreach ($days as $key => $posts)
                @php $d = \Illuminate\Support\Carbon::parse($key, $tz); @endphp
                <button type="button" wire:click="$set('day', '{{ $key }}')" @class(['flex flex-col items-center rounded-xl border py-1.5 text-xs', 'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-gray-900' => $key === $pickedDay, 'border-gray-200 bg-white text-gray-600 dark:border-gray-800 dark:bg-white/[0.04] dark:text-gray-300' => $key !== $pickedDay])>
                    <span>{{ $short[$loop->index] }}</span><span class="text-[15px] font-semibold tabular-nums">{{ $d->day }}</span><span @class(['tabular-nums', 'opacity-70' => $key === $pickedDay, 'text-gray-400' => $key !== $pickedDay])>{{ $posts->count() }}</span>
                </button>
            @endforeach
        </div>
        <div class="card mt-3">
            @forelse ($days[$pickedDay] as $post)
                @include('livewire.operator.social.partials.row', ['post' => $post, 'when' => 'time'])
            @empty
                <p class="px-4 py-8 text-center text-sm text-gray-500">Bu gün için paylaşım yok.</p>
            @endforelse
        </div>
    </div>

    <div class="card hidden md:block" data-social-week>
        <div class="grid grid-cols-7 divide-x divide-gray-200 dark:divide-gray-800">
            @foreach ($days as $key => $posts)
                @php $d = \Illuminate\Support\Carbon::parse($key, $tz); @endphp
                <div class="min-w-0" data-week-day="{{ $key }}">
                    <div class="border-b border-gray-200 bg-gray-50 px-2.5 py-2 dark:border-gray-800 dark:bg-white/[0.02]">
                        <p class="text-xs text-gray-500">{{ $short[$loop->index] }}</p>
                        <p @class(['text-[15px] font-semibold tabular-nums', 'text-second' => $key === $todayKey, 'text-gray-900 dark:text-white' => $key !== $todayKey])>{{ $d->day }}</p>
                        <p class="text-xs text-gray-500">{{ $posts->count() }} paylaşım</p>
                    </div>
                    <div class="min-h-[160px] space-y-1.5 p-1.5">
                        @foreach ($posts as $post)
                            @php $c = $cell($post); @endphp
                            <button type="button" wire:key="cal-{{ $post->id }}" wire:click="openPost({{ $post->id }})" class="block w-full rounded-lg border px-2 py-1.5 text-left transition hover:border-gray-400 {{ $c['class'] }}" data-social-post="{{ $post->id }}" title="{{ $post->title }}">
                                <span class="block text-xs tabular-nums text-gray-500"><span class="{{ $post->kind === 'story' ? 'text-warning-600' : 'text-second' }}" aria-hidden="true">{{ $c['mark'] }}</span> {{ $c['time'] }}@if ($c['note'] !== '') · {{ $c['note'] }}@endif</span>
                                <span class="mt-0.5 line-clamp-2 text-[13px] leading-snug">{{ $post->title ?: mb_strimwidth((string) ($post->caption ?: $post->threads_text), 0, 60, '…') }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    <p class="flex flex-wrap gap-x-4 gap-y-1 text-[13px] text-gray-500"><span>□ Gönderi</span><span>▶ Video</span><span>○ Hikâye</span><span>Gri kutu: yayınlandı</span><span>Sarı kutu: onayınızı bekliyor</span><span>“sabit”: sıradan bağımsız, kendi saatinde çıkar</span></p>
@endif
