{{-- Konuşmalar: public comments that wait for an answer. Direct messages stay in the Gelen kutusu with the other
     channels; answers are written and sent there, so one place holds the AI draft and the send button. --}}
<section class="card" data-social-talks>
    <div class="flex flex-wrap items-baseline justify-between gap-2 px-4 py-3.5">
        <h2 class="section-title">Cevap bekleyen yorumlar</h2>
        <p class="text-[13px] text-gray-500">{{ $talks['commentTotal'] }} yorum{{ $talks['commentTotal'] > $talks['comments']->count() ? ', en yeni '.$talks['comments']->count().' tanesi' : '' }}</p>
    </div>
    @forelse ($talks['comments'] as $thread)
        <a href="{{ route('operator.inbox', ['kayit' => 'sosyal-'.$thread->id]) }}" wire:navigate class="card-row items-start transition hover:bg-gray-50 dark:hover:bg-white/[0.03]" data-social-talk="{{ $thread->id }}">
            <span class="inline-flex h-[22px] min-w-[26px] shrink-0 items-center justify-center rounded-md border border-gray-200 px-1 text-[11px] font-semibold text-gray-500 dark:border-gray-700">{{ ['instagram' => 'IG', 'facebook' => 'FB', 'linkedin' => 'in', 'threads' => 'Th'][$thread->account?->platform] ?? '?' }}</span>
            <span class="min-w-0 flex-1">
                <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ $thread->author_name ?: 'Adı görünmüyor' }} <span class="font-normal text-gray-500">· {{ $thread->last_at?->timezone($tz)->translatedFormat('j F H:i') }}</span></span>
                <span class="mt-0.5 line-clamp-2 block text-sm text-gray-700 dark:text-gray-300">{{ $thread->last_text }}</span>
                @if ($thread->post_caption)<span class="mt-0.5 block truncate text-xs text-gray-500">Gönderi: {{ $thread->post_caption }}</span>@endif
            </span>
            <span class="chip shrink-0 {{ $thread->draft_status === 'ready' ? 'tone-ok' : 'tone-info' }}">{{ $thread->draft_status === 'ready' ? 'Cevap hazır' : 'Cevapla' }}</span>
        </a>
    @empty
        <p class="border-t border-gray-200 px-4 py-10 text-center text-sm text-gray-500 dark:border-gray-800">Cevap bekleyen yorum yok.</p>
    @endforelse
</section>
<section class="card flex flex-wrap items-center justify-between gap-3 p-4" data-social-inbox-link>
    <div class="min-w-0">
        <p class="text-sm font-medium text-gray-900 dark:text-white">Özel mesajlar Gelen kutusunda{{ $talks['dms'] > 0 ? ': '.$talks['dms'].' mesaj cevap bekliyor' : '' }}</p>
        <p class="mt-0.5 text-[13px] text-gray-500">Instagram ve Facebook mesajları WhatsApp ve formlarla aynı yerde cevaplanır. LinkedIn ve Threads yorumları henüz okunmuyor.</p>
    </div>
    <a href="{{ route('operator.inbox') }}" wire:navigate class="btn">Gelen kutusunu aç</a>
</section>
