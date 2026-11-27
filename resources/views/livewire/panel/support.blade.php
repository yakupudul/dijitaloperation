@php
    use App\Livewire\Panel\SupportPage;
    use App\Panel\Models\PanelMeeting;
    use App\Panel\Models\PanelTicket;
    $tz = 'Europe/Istanbul';
    $input = 'h-10 w-full rounded-lg border border-line bg-white px-3 text-base text-gray-900 outline-none focus:border-primary sm:text-sm';
    $area = 'w-full rounded-lg border border-line bg-white px-3 py-2 text-base text-gray-900 outline-none focus:border-primary sm:text-sm';
    $clip = '<svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 11.5 12.3 18.2a4.200 4.200 0 0 1-6-6L13 5.500a2.800 2.800 0 0 1 4 4l-6.700 6.700a1.400 1.400 0 0 1-2-2l6.200-6.200"/></svg>';
    $doc = '<svg class="h-5 w-5 shrink-0 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3.500h7l4 4V20a.5.5 0 0 1-.5.500h-10.500a.5.5 0 0 1-.5-.5V4a.5.5 0 0 1 .5-.5z"/><path d="M14 3.500V8h4"/></svg>';
@endphp
<div class="space-y-6" data-panel-support="{{ $tab }}">
    @if ($tab === 'talepler')
        @if ($ticket)
            <section class="max-w-3xl space-y-5" data-panel-ticket>
                <button type="button" wire:click="$set('ticketId', null)" class="inline-flex items-center gap-1 text-sm font-medium text-gray-500 hover:text-gray-900">
                    <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m14 6-6 6 6 6"/></svg>Tüm talepler
                </button>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-[21px] font-semibold leading-7 tracking-[-0.02em] text-gray-900">{{ $ticket->subject }}</h2>
                        <p class="mt-0.5 text-sm text-gray-500">{{ PanelTicket::CATEGORIES[$ticket->category] ?? '' }}, {{ $ticket->created_at->timezone($tz)->translatedFormat('j F Y, H:i') }}@if ($ticket->origin !== 'panel'), {{ mb_strtolower(PanelTicket::ORIGINS[$ticket->origin] ?? '') }}@endif</p>
                    </div>
                    <x-ta.pill :tone="PanelTicket::tone($ticket->status)">{{ PanelTicket::STATUSES[$ticket->status] ?? '' }}</x-ta.pill>
                </div>
                <ol class="space-y-3" wire:poll.6s.visible data-ticket-live>
                    @foreach ($messages as $message)
                        <li @class(['rounded-xl border border-line p-4', 'bg-white' => ! $message->operator_id, 'bg-soft' => $message->operator_id]) wire:key="tm-{{ $message->id }}">
                            <p class="flex flex-wrap items-baseline gap-x-2 text-sm text-gray-500">
                                <span class="font-semibold text-gray-900">{{ $message->operator_id ? 'Moximu' : ($message->user?->name ?? 'Kullanıcı') }}</span>
                                <time class="ml-auto tabular-nums">{{ $message->created_at->timezone($tz)->translatedFormat('j F Y, H:i') }}</time>
                            </p>
                            <div class="rich-text mt-1.5 text-[15px] text-gray-800">{!! $message->html() !!}</div>
                            @if ($message->attachment_path || $message->documents->isNotEmpty())
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @if ($message->attachment_path)
                                        <a href="{{ route('panel.support.file', $message->id) }}" class="inline-flex max-w-full items-center gap-1.5 rounded-lg border border-line bg-white px-2.5 py-1.5 text-sm font-medium text-gray-900 hover:bg-soft">{!! $doc !!}<span class="truncate">{{ $message->attachment_name }}</span></a>
                                    @endif
                                    @foreach ($message->documents as $file)
                                        <a href="{{ route('panel.document', $file->id) }}" class="inline-flex max-w-full items-center gap-1.5 rounded-lg border border-line bg-white px-2.5 py-1.5 text-sm font-medium text-gray-900 hover:bg-soft">{!! $doc !!}<span class="truncate">{{ $file->name }}</span><span class="shrink-0 font-normal text-gray-500">{{ $file->sizeLabel() }}</span></a>
                                    @endforeach
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ol>
                @if ($canWrite)
                    <form wire:submit="send" class="space-y-2" data-panel-ticket-reply>
                        <x-panel.editor model="reply" placeholder="Yanıtınızı yazın">
                            <label class="ml-auto inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-md px-2 text-sm font-medium text-gray-700 hover:bg-soft"><input type="file" wire:model="uploads" multiple class="sr-only">{!! $clip !!}Dosya ekle</label>
                        </x-panel.editor>
                        @include('panel.partials.uploads')
                        @error('reply')<span class="block text-sm text-error-600">{{ $message }}</span>@enderror
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="uploads,send">Gönder</button>
                            @if ($ticket->status !== PanelTicket::SOLVED)
                                <button type="button" wire:click="solve" class="btn-quiet">Çözüldü olarak kapat</button>
                            @endif
                        </div>
                    </form>
                @endif
            </section>
        @else
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="min-w-0 text-[15px] text-gray-500">Bir isteğiniz ya da sorunuz olduğunda buradan yazın; cevabı ve dosyaları aynı yerde görürsünüz.</p>
                @if ($canWrite && ! $adding)
                    <button type="button" wire:click="$set('adding', true)" class="btn-primary w-full sm:w-auto">Talep aç</button>
                @endif
            </div>
            @if ($adding)
                <form wire:submit="open" class="card max-w-3xl space-y-4 p-5" data-panel-ticket-form>
                    <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_200px]">
                        <label class="block"><span class="mb-1.5 block text-sm font-medium text-gray-900">Konu</span>
                            <input type="text" wire:model="form.subject" class="{{ $input }}" autocomplete="off" placeholder="Kısaca ne lazım">
                            @error('form.subject')<span class="mt-1 block text-sm text-error-600">{{ $message }}</span>@enderror
                        </label>
                        <label class="block"><span class="mb-1.5 block text-sm font-medium text-gray-900">Tür</span>
                            <select wire:model="form.category" class="{{ $input }}">
                                @foreach (PanelTicket::CATEGORIES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                            </select>
                        </label>
                    </div>
                    <div>
                        <span class="mb-1.5 block text-sm font-medium text-gray-900">Açıklama</span>
                        <x-panel.editor model="form.body" placeholder="Ne oldu, nerede oldu, ne olmasını isterdiniz" label="Açıklama">
                            <label class="ml-auto inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-md px-2 text-sm font-medium text-gray-700 hover:bg-soft"><input type="file" wire:model="uploads" multiple class="sr-only">{!! $clip !!}Dosya ekle</label>
                        </x-panel.editor>
                        @error('form.body')<span class="mt-1 block text-sm text-error-600">{{ $message }}</span>@enderror
                    </div>
                    @include('panel.partials.uploads')
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="uploads,open">Gönder</button>
                        <button type="button" wire:click="$set('adding', false)" class="btn-quiet">Vazgeç</button>
                    </div>
                </form>
            @endif
            <section class="card" wire:poll.15s.visible data-panel-tickets>
                @forelse ($tickets as $row)
                    <button type="button" wire:click="$set('ticketId', {{ $row->id }})" class="card-row w-full text-left hover:bg-soft" wire:key="ticket-{{ $row->id }}">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[15px] font-medium text-gray-900">{{ $row->subject }}</span>
                            <span class="block truncate text-sm text-gray-500">{{ PanelTicket::CATEGORIES[$row->category] ?? '' }}, {{ $row->user?->name ?? (PanelTicket::ORIGINS[$row->origin] ?? '') }}, {{ $row->last_message_at?->timezone($tz)->diffForHumans() }}</span>
                        </span>
                        <x-ta.pill :tone="PanelTicket::tone($row->status)">{{ PanelTicket::STATUSES[$row->status] ?? '' }}</x-ta.pill>
                    </button>
                @empty
                    <x-ta.empty-state size="sm" icon="none" title="Henüz talep yok." :message="$canWrite ? 'Bir sorunuz, isteğiniz ya da bağlanmasını istediğiniz bir kanal olduğunda “Talep aç” ile yazın.' : null" />
                @endforelse
            </section>

            <section class="max-w-3xl" data-panel-help>
                <div class="section-head"><h2 class="section-title">Sık sorulanlar</h2></div>
                <div class="divide-y divide-line border-y border-line">
                    @foreach (SupportPage::HELP as [$question, $answer])
                        <details class="group py-3.5">
                            <summary class="cursor-pointer list-none text-[15px] font-medium text-gray-900">{{ $question }}</summary>
                            <p class="mt-2 max-w-[75ch] text-[15px] leading-6 text-gray-700">{{ $answer }}</p>
                        </details>
                    @endforeach
                </div>
            </section>
        @endif
    @elseif ($tab === 'dosyalar')
        <p class="max-w-[75ch] text-[15px] text-gray-500">Logo, fotoğraf, belge: bize ulaştırmak istediğiniz her dosyayı buraya bırakın. Taleplerde gönderdikleriniz de burada toplanır.</p>
        @if ($canWrite)
            <form wire:submit="shelve" class="max-w-3xl space-y-3" data-panel-shelf-form
                x-data="{ over: false }" x-on:dragover.prevent="over = true" x-on:dragleave.prevent="over = false"
                x-on:drop.prevent="over = false; $refs.files.files = $event.dataTransfer.files; $refs.files.dispatchEvent(new Event('change', { bubbles: true }))">
                <label class="flex cursor-pointer flex-col items-center gap-1 rounded-xl border border-dashed border-gray-300 px-4 py-7 text-center hover:bg-soft" :class="over ? 'border-primary bg-soft' : ''">
                    <input type="file" x-ref="files" wire:model="uploads" multiple class="sr-only">
                    <span class="text-[15px] font-medium text-gray-900">Dosyaları buraya sürükleyin ya da seçin</span>
                    <span class="text-sm text-gray-500">Bir seferde 10 dosya, her biri en çok 20 MB</span>
                </label>
                @include('panel.partials.uploads')
                @error('uploads')<span class="block text-sm text-error-600">{{ $message }}</span>@enderror
                @if ($uploads !== [])
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="uploads,shelve">Gönder</button>
                @endif
            </form>
        @endif
        <section class="card" data-panel-documents>
            @forelse ($documents as $file)
                <a href="{{ route('panel.document', $file->id) }}" class="card-row hover:bg-soft" wire:key="doc-{{ $file->id }}">
                    {!! $doc !!}
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[15px] font-medium text-gray-900">{{ $file->name }}</span>
                        <span class="block truncate text-sm text-gray-500">{{ $file->operator_id ? 'Moximu' : ($file->user?->name ?? 'Kullanıcı') }}, {{ $file->created_at->timezone($tz)->translatedFormat('j F Y') }}@if ($file->ticket), {{ $file->ticket->subject }}@endif</span>
                    </span>
                    <span class="shrink-0 text-sm tabular-nums text-gray-500">{{ $file->sizeLabel() }}</span>
                </a>
            @empty
                <x-ta.empty-state size="sm" icon="none" title="Henüz dosya yok." />
            @endforelse
        </section>
    @elseif ($tab === 'toplantilar')
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="min-w-0 text-[15px] text-gray-500">Yüz yüze ya da görüntülü konuşmak istediğinizde gün isteyin; saati ve bağlantıyı buradan bildiririz.</p>
            @if ($canWrite && ! $asking)
                <button type="button" wire:click="$set('asking', true)" class="btn-primary w-full sm:w-auto">Toplantı iste</button>
            @endif
        </div>
        @if ($asking)
            <form wire:submit="askMeeting" class="card max-w-3xl space-y-4 p-5" data-panel-meeting-form>
                <label class="block"><span class="mb-1.5 block text-sm font-medium text-gray-900">Ne konuşalım?</span>
                    <input type="text" wire:model="meeting.topic" class="{{ $input }}" autocomplete="off" placeholder="Örneğin yeni yılın reklam planı">
                    @error('meeting.topic')<span class="mt-1 block text-sm text-error-600">{{ $message }}</span>@enderror
                </label>
                <label class="block"><span class="mb-1.5 block text-sm font-medium text-gray-900">Size uyan gün ve saatler</span>
                    <input type="text" wire:model="meeting.preferred" class="{{ $input }}" autocomplete="off" placeholder="Örneğin salı ya da perşembe, 14.00 sonrası">
                    @error('meeting.preferred')<span class="mt-1 block text-sm text-error-600">{{ $message }}</span>@enderror
                </label>
                <label class="block"><span class="mb-1.5 block text-sm font-medium text-gray-900">Eklemek istediğiniz</span>
                    <textarea wire:model="meeting.note" rows="3" class="{{ $area }}"></textarea>
                </label>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="btn-primary">Gönder</button>
                    <button type="button" wire:click="$set('asking', false)" class="btn-quiet">Vazgeç</button>
                </div>
            </form>
        @endif
        <section class="card" data-panel-meetings>
            @forelse ($meetings as $row)
                <div class="card-row flex-wrap" wire:key="meeting-{{ $row->id }}">
                    <div class="min-w-0 flex-1 basis-64">
                        <p class="text-[15px] font-medium text-gray-900">{{ $row->topic }}</p>
                        <p class="text-sm text-gray-500">
                            @if ($row->starts_at) {{ $row->starts_at->timezone($tz)->translatedFormat('j F Y, l, H:i') }}
                            @else İstediğiniz zaman: {{ $row->preferred }}
                            @endif
                        </p>
                        @if (filled($row->summary))<p class="mt-1 whitespace-pre-line text-sm text-gray-700">{{ $row->summary }}</p>@endif
                    </div>
                    <x-ta.pill :tone="['requested' => 'warn', 'planned' => 'info', 'done' => 'ok'][$row->status] ?? 'info'">{{ PanelMeeting::STATUSES[$row->status] ?? '' }}</x-ta.pill>
                    @if ($row->status === PanelMeeting::PLANNED && filled($row->meet_url))
                        <a href="{{ $row->meet_url }}" target="_blank" rel="noopener" class="btn-primary">Toplantıya katıl</a>
                    @endif
                    @if ($canWrite && in_array($row->status, [PanelMeeting::REQUESTED, PanelMeeting::PLANNED], true))
                        <button type="button" wire:click="cancelMeeting({{ $row->id }})" wire:confirm="Bu toplantı iptal edilsin mi?" class="btn-quiet">İptal et</button>
                    @endif
                </div>
            @empty
                <x-ta.empty-state size="sm" icon="none" title="Planlanmış bir toplantı yok." />
            @endforelse
        </section>
    @else
        <section class="max-w-3xl space-y-4" data-panel-announcements>
            @forelse ($announcements as $item)
                <article class="rounded-xl border border-line p-5" wire:key="ann-{{ $item->id }}">
                    <p class="text-sm text-gray-500">{{ $item->published_at->timezone($tz)->translatedFormat('j F Y') }}</p>
                    <h2 class="mt-1 text-[17px] font-semibold text-gray-900">{{ $item->title }}</h2>
                    <p class="mt-2 max-w-[75ch] whitespace-pre-line text-[15px] leading-6 text-gray-700">{{ $item->body }}</p>
                    @if ($item->link_url)
                        <a href="{{ $item->link_url }}" target="_blank" rel="noopener" class="mt-3 inline-block text-sm font-medium text-primary hover:underline">Devamını oku</a>
                    @endif
                </article>
            @empty
                <x-ta.empty-state size="sm" icon="none" title="Henüz duyuru yok." />
            @endforelse
        </section>
    @endif
</div>
