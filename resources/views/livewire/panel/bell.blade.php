<div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false" wire:poll.20s data-panel-bell>
    <button type="button" @click="open = ! open" :aria-expanded="open" class="relative flex h-9 w-9 items-center justify-center rounded-lg text-gray-600 hover:bg-black/[0.05]" aria-label="Bildirimler{{ $unread ? ', '.$unread.' yeni' : '' }}">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 1 1 12 0c0 6 2.5 7.5 2.5 7.5h-17S6 14 6 8Z"/><path d="M10 19.5a2 2 0 0 0 4 0"/></svg>
        @if ($unread)
            <span class="absolute -right-0.5 -top-0.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-error-600 px-1 text-[11px] font-semibold leading-none text-white">{{ $unread > 99 ? '99+' : $unread }}</span>
        @endif
    </button>
    <div x-show="open" x-cloak @class(['absolute top-full z-[10000] mt-1 w-[320px] max-w-[calc(100vw-2rem)] rounded-xl bg-white p-1.5 shadow-theme-lg ring-1 ring-gray-200', 'left-0' => $side === 'left', 'right-0' => $side !== 'left'])>
        <div class="flex items-center justify-between px-2.5 py-1.5">
            <span class="text-[13px] font-semibold text-gray-900">Bildirimler</span>
            @if ($rows->whereNull('read_at')->isNotEmpty())
                <button type="button" wire:click="readAll" class="text-[13px] text-gray-500 hover:text-gray-900">Hepsini okundu yap</button>
            @endif
        </div>
        <div class="max-h-[60vh] overflow-y-auto">
            @foreach ($due as $reminder)
                <a href="{{ route('panel.person', ['person' => $reminder->person_id, 'sekme' => 'reminders']) }}" wire:navigate class="block rounded-lg px-2.5 py-2 hover:bg-gray-100" wire:key="bell-due-{{ $reminder->id }}">
                    <span class="block truncate text-sm font-medium text-gray-900">Hatırlatma: {{ $reminder->title }}</span>
                    <span class="block truncate text-[13px] text-gray-500">{{ $reminder->person?->name }}, {{ $reminder->due_at->timezone('Europe/Istanbul')->diffForHumans() }}</span>
                </a>
            @endforeach
            @foreach ($rows as $row)
                <button type="button" wire:click="open({{ $row->id }})" class="block w-full rounded-lg px-2.5 py-2 text-left hover:bg-gray-100" wire:key="bell-{{ $row->id }}">
                    <span @class(['block truncate text-sm', 'font-medium text-gray-900' => ! $row->read_at, 'text-gray-600' => $row->read_at])>{{ $row->title }}</span>
                    <span class="block text-[13px] text-gray-500">{{ $row->created_at->timezone('Europe/Istanbul')->diffForHumans() }}</span>
                </button>
            @endforeach
            @if ($rows->isEmpty() && $due->isEmpty())
                <p class="px-2.5 py-3 text-[13px] text-gray-500">Yeni bir şey yok.</p>
            @endif
        </div>
    </div>
</div>
