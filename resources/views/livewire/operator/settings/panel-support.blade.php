@php
    use App\Panel\Models\PanelTicket;
    $tz = 'Europe/Istanbul';
    $input = 'h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 outline-none focus:border-gray-400 dark:border-gray-700 dark:bg-transparent dark:text-white';
    $area = 'w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-gray-400 dark:border-gray-700 dark:bg-transparent dark:text-white';
@endphp
<div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <a href="{{ route('operator.settings.panel-users') }}" wire:navigate class="link-neutral text-[13px]">Müşteri paneli</a>
            <h1 class="page-title">Panel desteği</h1>
            <p class="page-sub">Markaların panelden açtığı talepler tek kutuda; duyurular buradan gider. {{ $waiting ? $waiting.' talep cevap bekliyor.' : 'Cevap bekleyen talep yok.' }}</p>
        </div>
    </header>

    <nav class="no-scrollbar flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Panel desteği">
        @foreach (['talepler' => 'Talepler', 'duyurular' => 'Duyurular'] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}'); $set('ticketId', null)" @class(['tab', 'tab-active' => $tab === $key])>{{ $label }}</button>
        @endforeach
    </nav>

    @if ($tab === 'talepler')
        @if ($ticket)
            <section class="max-w-3xl space-y-5" data-support-ticket>
                <button type="button" wire:click="$set('ticketId', null)" class="link-neutral text-[13px]">Tüm talepler</button>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="section-title">{{ $ticket->subject }}</h2>
                        <p class="mt-0.5 text-[13px] text-gray-500">{{ $ticket->brand?->name }}, {{ $ticket->user?->name }}, {{ PanelTicket::CATEGORIES[$ticket->category] ?? '' }}, {{ $ticket->created_at->timezone($tz)->translatedFormat('j F Y, H:i') }}</p>
                    </div>
                    <x-ta.pill :tone="PanelTicket::tone($ticket->status)">{{ PanelTicket::STATUSES[$ticket->status] ?? '' }}</x-ta.pill>
                </div>
                <ol class="space-y-3">
                    @foreach ($messages as $message)
                        <li @class(['rounded-xl border border-gray-200 p-4 dark:border-gray-800', 'bg-gray-50 dark:bg-white/[0.03]' => $message->operator_id]) wire:key="tm-{{ $message->id }}">
                            <p class="flex flex-wrap items-baseline gap-x-2 text-[13px] text-gray-500">
                                <span class="font-medium text-gray-900 dark:text-white">{{ $message->operator_id ? ($message->operator?->name ?? 'Moximu') : ($message->user?->name ?? 'Kullanıcı') }}</span>
                                <time class="ml-auto tabular-nums">{{ $message->created_at->timezone($tz)->translatedFormat('j F Y, H:i') }}</time>
                            </p>
                            <p class="mt-1.5 whitespace-pre-line text-sm leading-6 text-gray-800 dark:text-gray-200">{{ $message->body }}</p>
                            @if ($message->attachment_path)
                                <a href="{{ route('operator.settings.panel-support.file', $message->id) }}" class="mt-2 inline-block text-[13px] font-medium text-gray-900 underline dark:text-white">{{ $message->attachment_name }}</a>
                            @endif
                        </li>
                    @endforeach
                </ol>
                <form wire:submit="send" class="space-y-2" data-support-reply>
                    <textarea wire:model="reply" rows="4" class="{{ $area }}" placeholder="Cevabınız" aria-label="Cevap"></textarea>
                    @error('reply')<span class="block text-[13px] text-error-600">{{ $message }}</span>@enderror
                    <div class="flex flex-wrap gap-2">
                        <button type="submit" class="btn-primary">Cevapla</button>
                        @if ($ticket->status !== PanelTicket::SOLVED)
                            <button type="button" wire:click="setStatus('solved')" class="btn">Çözüldü yap</button>
                        @else
                            <button type="button" wire:click="setStatus('open')" class="btn">Yeniden aç</button>
                        @endif
                    </div>
                </form>
            </section>
        @else
            <div class="flex gap-1.5">
                @foreach (['waiting' => 'Cevap bekleyen', 'done' => 'Cevaplanan ve çözülen', 'all' => 'Hepsi'] as $key => $label)
                    <button type="button" wire:click="$set('status', '{{ $key }}')" @class(['seg', 'seg-active' => $status === $key])>{{ $label }}</button>
                @endforeach
            </div>
            <section class="card" data-support-tickets>
                @forelse ($tickets as $row)
                    <button type="button" wire:click="$set('ticketId', {{ $row->id }})" class="card-row w-full text-left hover:bg-gray-50 dark:hover:bg-white/[0.03]" wire:key="ticket-{{ $row->id }}">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-gray-900 dark:text-white">{{ $row->subject }}</span>
                            <span class="block truncate text-[13px] text-gray-500">{{ $row->brand?->name }}, {{ $row->user?->name }}, {{ PanelTicket::CATEGORIES[$row->category] ?? '' }}, {{ $row->last_message_at?->timezone($tz)->diffForHumans() }}</span>
                        </span>
                        <x-ta.pill :tone="PanelTicket::tone($row->status)">{{ PanelTicket::STATUSES[$row->status] ?? '' }}</x-ta.pill>
                    </button>
                @empty
                    <x-ta.empty-state size="sm" icon="none" :title="$status === 'waiting' ? 'Cevap bekleyen talep yok.' : 'Talep yok.'" />
                @endforelse
            </section>
        @endif
    @else
        <div class="grid gap-8 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <form wire:submit="announce" class="space-y-3" data-support-announce>
                <h2 class="section-title">Yeni duyuru</h2>
                <label class="block"><span class="mb-1 block text-[13px] font-medium text-gray-700 dark:text-gray-300">Başlık</span>
                    <input type="text" wire:model="form.title" class="{{ $input }}" autocomplete="off">
                    @error('form.title')<span class="mt-1 block text-[13px] text-error-600">{{ $message }}</span>@enderror
                </label>
                <label class="block"><span class="mb-1 block text-[13px] font-medium text-gray-700 dark:text-gray-300">Metin</span>
                    <textarea wire:model="form.body" rows="5" class="{{ $area }}"></textarea>
                    @error('form.body')<span class="mt-1 block text-[13px] text-error-600">{{ $message }}</span>@enderror
                </label>
                <label class="block"><span class="mb-1 block text-[13px] font-medium text-gray-700 dark:text-gray-300">Bağlantı (blog yazısı vb., boş kalabilir)</span>
                    <input type="url" wire:model="form.link_url" class="{{ $input }}" placeholder="https://" autocomplete="off">
                    @error('form.link_url')<span class="mt-1 block text-[13px] text-error-600">{{ $message }}</span>@enderror
                </label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block"><span class="mb-1 block text-[13px] font-medium text-gray-700 dark:text-gray-300">Kime</span>
                        <select wire:model.live="form.target" class="{{ $input }}">
                            <option value="all">Tüm markalar</option>
                            <option value="sector">Bir sektör</option>
                            <option value="brand">Tek marka</option>
                        </select>
                    </label>
                    @if ($form['target'] === 'brand')
                        <label class="block"><span class="mb-1 block text-[13px] font-medium text-gray-700 dark:text-gray-300">Marka</span>
                            <select wire:model="form.brand_id" class="{{ $input }}"><option value="">Seçin</option>@foreach ($brands as $brand)<option value="{{ $brand->id }}">{{ $brand->name }}</option>@endforeach</select>
                            @error('form.brand_id')<span class="mt-1 block text-[13px] text-error-600">{{ $message }}</span>@enderror
                        </label>
                    @elseif ($form['target'] === 'sector')
                        <label class="block"><span class="mb-1 block text-[13px] font-medium text-gray-700 dark:text-gray-300">Sektör</span>
                            <select wire:model="form.sector_id" class="{{ $input }}"><option value="">Seçin</option>@foreach ($sectors as $sector)<option value="{{ $sector->id }}">{{ $sector->name }}</option>@endforeach</select>
                            @error('form.sector_id')<span class="mt-1 block text-[13px] text-error-600">{{ $message }}</span>@enderror
                        </label>
                    @endif
                </div>
                <button type="submit" class="btn-primary" wire:confirm="Duyuru yayınlansın ve kullanıcıların zili çalsın mı?">Yayınla</button>
            </form>
            <section class="card self-start" data-support-announcements>
                @forelse ($announcements as $item)
                    <div class="card-row items-start!" wire:key="ann-{{ $item->id }}">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $item->title }}</p>
                            <p class="truncate text-[13px] text-gray-500">{{ $item->brand?->name ?? ($item->sector_id ? 'Sektör' : 'Tüm markalar') }}, {{ $item->published_at ? $item->published_at->timezone($tz)->translatedFormat('j F Y') : 'yayından kaldırıldı' }}</p>
                        </div>
                        @if ($item->published_at)
                            <button type="button" wire:click="withdraw({{ $item->id }})" wire:confirm="Duyuru markaların panelinden kaldırılsın mı?" class="btn-quiet shrink-0">Kaldır</button>
                        @endif
                    </div>
                @empty
                    <x-ta.empty-state size="sm" icon="none" title="Henüz duyuru gönderilmedi." />
                @endforelse
            </section>
        </div>
    @endif
</div>
