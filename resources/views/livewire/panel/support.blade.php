@php
    use App\Livewire\Panel\SupportPage;
    use App\Panel\Models\PanelTicket;
    $tz = 'Europe/Istanbul';
    $input = 'h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-base text-gray-900 outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10 sm:h-9 sm:text-sm';
    $area = 'w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-base text-gray-900 outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10 sm:text-sm';
@endphp
<div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <p class="min-w-0 self-center text-[15px] text-gray-500">Moximu'ya yazın, cevabı ve duyuruları burada görün.</p>
        @if ($canWrite && $tab === 'talepler' && ! $ticket)
            <button type="button" wire:click="$set('adding', true)" class="btn-primary w-full sm:w-auto">Talep aç</button>
        @endif
    </header>

    <nav class="no-scrollbar flex gap-1 overflow-x-auto border-b border-gray-200" aria-label="Destek">
        @foreach (['talepler' => 'Talepler', 'duyurular' => 'Duyurular', 'yardim' => 'Yardım'] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}'); $set('ticketId', null)" @class(['tab', 'tab-active' => $tab === $key]) @if ($tab === $key) aria-current="page" @endif>{{ $label }}</button>
        @endforeach
    </nav>

    @if ($tab === 'talepler')
        @if ($ticket)
            <section class="max-w-3xl space-y-5" data-panel-ticket>
                <button type="button" wire:click="$set('ticketId', null)" class="link-neutral text-[13px]">Tüm talepler</button>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-gray-900">{{ $ticket->subject }}</h2>
                        <p class="mt-0.5 text-[13px] text-gray-500">{{ PanelTicket::CATEGORIES[$ticket->category] ?? '' }}, {{ $ticket->created_at->timezone($tz)->translatedFormat('j F Y, H:i') }}</p>
                    </div>
                    <x-ta.pill :tone="PanelTicket::tone($ticket->status)">{{ PanelTicket::STATUSES[$ticket->status] ?? '' }}</x-ta.pill>
                </div>
                <ol class="space-y-3" wire:poll.4s.visible data-ticket-live>
                    @foreach ($messages as $message)
                        <li @class(['rounded-xl border p-4', 'border-gray-200 bg-white' => ! $message->operator_id, 'border-gray-200 bg-gray-50' => $message->operator_id]) wire:key="tm-{{ $message->id }}">
                            <p class="flex flex-wrap items-baseline gap-x-2 text-[13px] text-gray-500">
                                <span class="font-medium text-gray-900">{{ $message->operator_id ? 'Moximu' : ($message->user?->name ?? 'Kullanıcı') }}</span>
                                <time class="ml-auto tabular-nums">{{ $message->created_at->timezone($tz)->translatedFormat('j F Y, H:i') }}</time>
                            </p>
                            <p class="mt-1.5 whitespace-pre-line text-[15px] leading-6 text-gray-800">{{ $message->body }}</p>
                            @if ($message->attachment_path)
                                <a href="{{ route('panel.support.file', $message->id) }}" class="mt-2 inline-block text-[13px] font-medium text-gray-900 underline">{{ $message->attachment_name }}</a>
                            @endif
                        </li>
                    @endforeach
                </ol>
                @if ($canWrite)
                    <form wire:submit="send" class="space-y-2" data-panel-ticket-reply>
                        <textarea wire:model="reply" rows="3" class="{{ $area }}" placeholder="Eklemek istediğiniz bir şey varsa yazın" aria-label="Mesaj"></textarea>
                        @error('reply')<span class="block text-[13px] text-error-600">{{ $message }}</span>@enderror
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="submit" class="btn-primary">Gönder</button>
                            <label class="btn cursor-pointer"><input type="file" wire:model="upload" class="sr-only">{{ $upload ? 'Dosya eklendi' : 'Ekran görüntüsü ekle' }}</label>
                            <span wire:loading wire:target="upload" class="text-[13px] text-gray-500">Yükleniyor</span>
                            @if ($ticket->status !== PanelTicket::SOLVED)
                                <button type="button" wire:click="solve" class="btn-quiet">Çözüldü olarak kapat</button>
                            @endif
                        </div>
                        @error('upload')<span class="block text-[13px] text-error-600">{{ $message }}</span>@enderror
                    </form>
                @endif
            </section>
        @else
            @if ($adding)
                <form wire:submit="open" class="card max-w-3xl space-y-4 p-5" data-panel-ticket-form>
                    <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_200px]">
                        <label class="block"><span class="mb-1.5 block text-[13px] font-medium text-gray-700">Konu</span>
                            <input type="text" wire:model="form.subject" class="{{ $input }}" autocomplete="off" placeholder="Kısaca ne lazım">
                            @error('form.subject')<span class="mt-1 block text-[13px] text-error-600">{{ $message }}</span>@enderror
                        </label>
                        <label class="block"><span class="mb-1.5 block text-[13px] font-medium text-gray-700">Tür</span>
                            <select wire:model="form.category" class="{{ $input }}">
                                @foreach (PanelTicket::CATEGORIES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                            </select>
                        </label>
                    </div>
                    <label class="block"><span class="mb-1.5 block text-[13px] font-medium text-gray-700">Açıklama</span>
                        <textarea wire:model="form.body" rows="4" class="{{ $area }}" placeholder="Ne oldu, nerede oldu, ne olmasını isterdiniz"></textarea>
                        @error('form.body')<span class="mt-1 block text-[13px] text-error-600">{{ $message }}</span>@enderror
                    </label>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="submit" class="btn-primary">Gönder</button>
                        <label class="btn cursor-pointer"><input type="file" wire:model="upload" class="sr-only">{{ $upload ? 'Dosya eklendi' : 'Ekran görüntüsü ekle' }}</label>
                        <span wire:loading wire:target="upload" class="text-[13px] text-gray-500">Yükleniyor</span>
                        <button type="button" wire:click="$set('adding', false)" class="btn-quiet">Vazgeç</button>
                    </div>
                    @error('upload')<span class="block text-[13px] text-error-600">{{ $message }}</span>@enderror
                </form>
            @endif
            <section class="card" wire:poll.15s.visible data-panel-tickets>
                @forelse ($tickets as $row)
                    <button type="button" wire:click="$set('ticketId', {{ $row->id }})" class="card-row w-full text-left hover:bg-gray-50" wire:key="ticket-{{ $row->id }}">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-gray-900">{{ $row->subject }}</span>
                            <span class="block truncate text-[13px] text-gray-500">{{ PanelTicket::CATEGORIES[$row->category] ?? '' }}, {{ $row->user?->name }}, {{ $row->last_message_at?->timezone($tz)->diffForHumans() }}</span>
                        </span>
                        <x-ta.pill :tone="PanelTicket::tone($row->status)">{{ PanelTicket::STATUSES[$row->status] ?? '' }}</x-ta.pill>
                    </button>
                @empty
                    <x-ta.empty-state size="sm" icon="none" title="Henüz talep yok." :message="$canWrite ? 'Bir sorunuz, isteğiniz ya da bağlanmasını istediğiniz bir kanal olduğunda “Talep aç” ile yazın.' : null" />
                @endforelse
            </section>
        @endif
    @elseif ($tab === 'duyurular')
        <section class="max-w-3xl space-y-4" data-panel-announcements>
            @forelse ($announcements as $item)
                <article class="rounded-xl border border-gray-200 p-5" wire:key="ann-{{ $item->id }}">
                    <p class="text-[13px] text-gray-500">{{ $item->published_at->timezone($tz)->translatedFormat('j F Y') }}</p>
                    <h2 class="mt-1 text-[17px] font-semibold text-gray-900">{{ $item->title }}</h2>
                    <p class="mt-2 max-w-[75ch] whitespace-pre-line text-[15px] leading-6 text-gray-700">{{ $item->body }}</p>
                    @if ($item->link_url)
                        <a href="{{ $item->link_url }}" target="_blank" rel="noopener" class="mt-3 inline-block text-sm font-medium text-gray-900 underline">Devamını oku</a>
                    @endif
                </article>
            @empty
                <x-ta.empty-state size="sm" icon="none" title="Henüz duyuru yok." />
            @endforelse
        </section>
    @else
        <section class="max-w-3xl divide-y divide-gray-200 border-y border-gray-200" data-panel-help>
            @foreach (SupportPage::HELP as [$question, $answer])
                <details class="group py-3.5">
                    <summary class="cursor-pointer list-none text-[15px] font-medium text-gray-900">{{ $question }}</summary>
                    <p class="mt-2 max-w-[75ch] text-[15px] leading-6 text-gray-700">{{ $answer }}</p>
                </details>
            @endforeach
        </section>
    @endif
</div>
