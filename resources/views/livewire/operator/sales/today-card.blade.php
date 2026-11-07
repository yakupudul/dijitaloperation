{{-- Komuta's Moximu line (2026-11-07): the Gelen kutusu in one sentence and its first lines; the work is done there. --}}
<div class="card" data-sales-today>
    <div class="flex items-center justify-between gap-3 px-4 pt-4 pb-2">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Moximu <span class="font-normal text-gray-500">· {{ $line }}</span></h2>
        <a href="{{ route('operator.inbox') }}" wire:navigate class="link-neutral text-[13px]">Gelen kutusu →</a>
    </div>
    @forelse ($items as $item)
        <a href="{{ route('operator.inbox', ['kayit' => $item->key, 'gorunum' => $item->owed ? 'borclu' : 'adimlar']) }}" wire:navigate wire:key="today-{{ $item->key }}" class="card-row py-2.5! hover:bg-gray-50 dark:hover:bg-white/[0.02]">
            <x-ta.brand-avatar :name="$item->title" size="sm" />
            <span class="min-w-0 flex-1 truncate text-sm text-gray-900 dark:text-white">{{ $item->title }}<span class="text-gray-500"> · {{ $item->preview !== '' ? $item->preview : $item->channel }}</span></span>
            <span @class(['shrink-0 text-xs', 'text-error-600' => $item->late, 'text-gray-500' => ! $item->late])>{{ $item->owed ? 'cevap borçlu' : ($item->late ? 'gecikti' : 'bugün') }}</span>
        </a>
    @empty
        <p class="px-4 pb-4 text-sm text-gray-500">Kimseye cevap borcun yok, bugün adım yok. Yeni mesaj gelince burada görünür.</p>
    @endforelse
    @if ($count > 5)
        <a href="{{ route('operator.inbox') }}" wire:navigate class="block border-t border-gray-200 px-4 py-2.5 text-[13px] text-gray-500 hover:text-gray-900 dark:border-gray-800">{{ $count - 5 }} satır daha →</a>
    @endif
</div>
