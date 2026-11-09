@php
    use App\Services\Inbox\InboxQueue;
    use App\Services\Inbox\InboxItem;

    $n = fn (int $v): string => number_format($v, 0, ',', '.');
    $tz = 'Europe/Istanbul';
    $shortTime = function ($at) use ($tz): string {
        if (! $at) {
            return '';
        }
        $local = $at->timezone($tz);

        return $local->isToday() ? $local->format('H:i') : ($local->isYesterday() ? 'Dün' : $local->format('d.m'));
    };
    $input = 'rounded-lg border border-gray-200 bg-white px-3 text-sm placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white';
    $stageChip = ['new' => 'chip-flat', 'contacted' => 'chip-flat', 'talking' => 'chip-accent', 'meeting' => 'chip-accent', 'priced' => 'chip-accent', 'thinking' => 'chip-accent', 'later' => 'chip-flat', 'won' => 'chip-up', 'lost' => 'chip-down'];
    $pane = 'card min-w-0';
    $lead = $selected?->lead;
    $draft = $detail['draft'] ?? null;
    // The left rail (yakup 2026-11-08: Pazarlama's format): Tümü and the five channels, each with its colour and a short name.
    $channelLook = [
        '' => ['label' => 'Tümü', 'short' => 'Tümü', 'mark' => '∑', 'color' => '#111827'],
        'whatsapp' => ['short' => 'WhatsApp', 'mark' => 'W', 'color' => '#25a35a'],
        'instagram' => ['short' => 'Instagram', 'mark' => 'I', 'color' => '#c13584'],
        'meta_form' => ['short' => 'Meta form', 'mark' => 'M', 'color' => '#1877f2'],
        'form' => ['short' => 'Site formu', 'mark' => 'F', 'color' => '#0f8b8d'],
        'cagri' => ['short' => 'Çağrı', 'mark' => 'Ç', 'color' => '#d4a106'],
    ];
    $channelName = fn (string $key): string => $key === '' ? 'Tümü' : InboxQueue::CHANNELS[$key]['label'];
    $waUrl = $detail !== null && $detail['waNumber'] !== null ? 'https://wa.me/'.$detail['waNumber'].(trim($reply) !== '' ? '?text='.rawurlencode(trim($reply)) : '') : null;
@endphp
{{-- Gelen kutusu (yakup 2026-11-08: "tek ekran, WhatsApp gibi"): left the chats, middle the chat with the composer at
     the bottom, right the record. "Sohbetler" lists every chat like WhatsApp Web; the other tabs are the day's queue. --}}
<div data-inbox-page>
    @if ($drafting)
        <div wire:key="inbox-poll-fast" wire:poll.5s.visible hidden></div>
    @else
        <div wire:key="inbox-poll-slow" wire:poll.15s.visible hidden></div>
    @endif
    <header class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex min-w-0 items-baseline gap-3">
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Gelen kutusu</h1>
            <p class="truncate text-sm text-gray-500" data-inbox-strip>
                <span @class(['font-semibold text-gray-900 dark:text-white' => $strip['owed'] > 0])>{{ $n($strip['owed']) }} cevap bekliyor</span>
                @if ($strip['oldest'] !== null)<span> · en eskisi {{ $strip['oldest'] }}</span>@endif
                @if ($strip['late'] + $strip['today'] > 0)<span> · {{ $n($strip['late'] + $strip['today']) }} adım</span>@endif
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($readyCount > 0)
                <button type="button" class="btn-primary h-8 px-3 text-[13px]" x-on:click="if (confirm('Hazır {{ $readyCount }} sosyal cevabın hepsi gönderilsin mi?')) $wire.sendReady()" data-inbox-send-ready>Hazır sosyal cevapları gönder ({{ $n($readyCount) }})</button>
            @endif
            <a href="{{ route('operator.whatsapp') }}" wire:navigate class="btn h-8 px-3 text-[13px]" title="Bağlantı, sabit kararlar, yedekler ve Beyin">WhatsApp ayarları</a>
        </div>
    </header>

    @if ($message !== '')
        <p role="status" class="mt-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300" data-inbox-message>{{ $message }}</p>
    @endif
    @error('item')<p role="alert" class="mt-3 rounded-lg px-3 py-2 text-sm tone-critical">{{ $message }}</p>@enderror

    <div class="mt-3 flex h-[calc(100dvh-10.5rem)] min-h-[32rem] overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900" data-inbox-grid>
        {{-- Far left: the channels (wide screens; a narrow icon rail until 2xl, a select on phones) --}}
        <nav class="hidden w-[4.75rem] shrink-0 flex-col overflow-y-auto border-r border-gray-200 bg-gray-50 lg:flex 2xl:w-60 dark:border-gray-800 dark:bg-white/[0.02]" aria-label="Kanallar" data-inbox-channels>
            @foreach ($channelLook as $key => $look)
                @php $cc = $channelCounts[$key] ?? ['open' => 0, 'month' => 0]; @endphp
                <button type="button" wire:key="inbox-ch-{{ $key ?: 'tumu' }}" wire:click="pickChannel('{{ $key }}')" @class(['relative flex w-full flex-col items-center gap-1 px-1 py-2.5 text-center hover:bg-gray-100 2xl:flex-row 2xl:gap-3 2xl:px-3 2xl:text-left dark:hover:bg-white/[0.04]', 'bg-white shadow-[inset_3px_0_0_0_#111827] dark:bg-white/[0.08] dark:shadow-[inset_3px_0_0_0_#fff]' => $channel === $key, 'border-b border-gray-200 dark:border-gray-800' => $key === '']) aria-current="{{ $channel === $key ? 'true' : 'false' }}" title="{{ $channelName($key) }}" data-inbox-channel="{{ $key ?: 'tumu' }}">
                    <span class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold text-white" style="background: {{ $look['color'] }}">
                        {{ $look['mark'] }}
                        @if ($cc['open'] > 0)<span class="absolute -top-1 -right-1 min-w-5 rounded-full bg-success-500 px-1 text-[11px] leading-5 font-semibold text-white tabular-nums 2xl:hidden">{{ $cc['open'] }}</span>@endif
                    </span>
                    <span class="block max-w-full truncate text-[11px] text-gray-600 2xl:hidden dark:text-gray-300">{{ $look['short'] }}</span>
                    <span class="hidden min-w-0 flex-1 2xl:block">
                        <span class="flex items-baseline justify-between gap-2">
                            <span class="truncate text-[15px] text-gray-900 dark:text-white">{{ $channelName($key) }}</span>
                            @if ($cc['open'] > 0)<span class="shrink-0 rounded-full bg-success-500 px-2 text-xs leading-5 font-semibold text-white tabular-nums">{{ $n($cc['open']) }}</span>@endif
                        </span>
                        <span class="block truncate text-[13px] text-gray-500">{{ $cc['open'] > 0 ? $n($cc['open']).' bekliyor' : 'bekleyen yok' }}{{ $key !== 'whatsapp' && $key !== 'instagram' || $cc['month'] > 0 ? ' · bu ay '.$n($cc['month']).' kayıt' : '' }}</span>
                    </span>
                </button>
            @endforeach
            <a href="{{ route('operator.marketing') }}" wire:navigate class="mt-auto hidden px-3 py-3 text-xs text-gray-500 hover:text-gray-900 2xl:block dark:hover:text-white" data-inbox-to-marketing>Kanallar neyi getiriyor? Pazarlama</a>
        </nav>
        {{-- Left: the chats --}}
        <section @class(['flex w-full flex-col border-r border-gray-200 md:w-[22rem] md:shrink-0 dark:border-gray-800', 'hidden md:flex' => $selected !== null]) data-inbox-list>
            <div class="space-y-2 border-b border-gray-200 p-3 dark:border-gray-800">
                <select wire:change="pickChannel($event.target.value)" class="h-9 w-full lg:hidden {{ $input }}" aria-label="Kanal" data-inbox-channel-select>
                    @foreach ($channelLook as $key => $look)
                        <option value="{{ $key }}" @selected($channel === $key)>{{ $channelName($key) }}{{ ($channelCounts[$key]['open'] ?? 0) > 0 ? ' ('.$channelCounts[$key]['open'].')' : '' }}</option>
                    @endforeach
                </select>
                @if ($view === 'sohbetler')
                    <div class="flex gap-2">
                        <input type="search" wire:model.live.debounce.300ms="search" placeholder="İsim ya da numara ara" class="h-9 min-w-0 flex-1 {{ $input }}" aria-label="Sohbet ara" data-inbox-search />
                        @if ($channel === 'cagri')
                            <button type="button" wire:click="$toggle('callOpen')" @class(['btn h-9 shrink-0 px-3 text-[13px]', 'ring-1 ring-gray-400' => $callOpen]) data-inbox-call-toggle>+ Çağrı ekle</button>
                        @endif
                    </div>
                @endif
                @if ($channel === 'cagri' && $callOpen)
                    <form wire:submit="addCall" class="space-y-2 rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-white/[0.03]" data-inbox-call-form>
                        @php $pickedCall = $call['lead'] !== '' ? \App\Models\SalesLead::query()->find((int) $call['lead']) : null; @endphp
                        @if ($pickedCall !== null)
                            <div class="flex items-center justify-between gap-2 text-[13px]">
                                <span class="truncate text-gray-900 dark:text-white">{{ $pickedCall->title() }}{{ $pickedCall->phone ? ' · '.$pickedCall->phone : '' }}</span>
                                <button type="button" wire:click="$set('call.lead', '')" class="link-neutral text-xs">Değiştir</button>
                            </div>
                        @else
                            <input type="search" wire:model.live.debounce.300ms="callSearch" placeholder="Kayıtlı kişi ara (ad, telefon)" class="h-9 w-full {{ $input }}" aria-label="Kişi ara" data-inbox-call-search />
                            @if ($callMatches->isNotEmpty())
                                <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white text-[13px] dark:divide-gray-800 dark:border-gray-800 dark:bg-transparent">
                                    @foreach ($callMatches as $match)
                                        <li><button type="button" wire:click="pickCallLead({{ $match->id }})" class="w-full px-3 py-1.5 text-left hover:bg-gray-50 dark:hover:bg-white/[0.04]" data-inbox-call-match="{{ $match->id }}">{{ $match->title() }}{{ $match->phone ? ' · '.$match->phone : '' }}</button></li>
                                    @endforeach
                                </ul>
                            @endif
                            <p class="text-xs text-gray-500">Kayıtlı değilse:</p>
                            <div class="flex gap-2">
                                <input type="text" wire:model="call.name" placeholder="Ad" class="h-9 min-w-0 flex-1 {{ $input }}" aria-label="Ad" />
                                <input type="tel" wire:model="call.phone" placeholder="Telefon" class="h-9 w-32 {{ $input }}" aria-label="Telefon" />
                            </div>
                            @error('call.name')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
                        @endif
                        <textarea wire:model="call.note" rows="2" placeholder="Ne konuşuldu?" class="w-full py-2 {{ $input }}" aria-label="Not" data-inbox-call-note></textarea>
                        @error('call.note')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
                        <button type="submit" class="btn-dark h-8 px-3 text-[13px]" data-inbox-call-save>Kaydet</button>
                    </form>
                @endif
                <div class="-mx-1 flex gap-1 overflow-x-auto px-1 pb-0.5" role="tablist" aria-label="Gelen kutusu görünümleri" data-inbox-views>
                    @foreach (InboxQueue::VIEWS as $key => $label)
                        <button type="button" wire:click="$set('view', '{{ $key }}')" @class(['shrink-0 rounded-full px-3 py-1 text-[13px] whitespace-nowrap', 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $view === $key, 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-200' => $view !== $key]) role="tab" aria-selected="{{ $view === $key ? 'true' : 'false' }}" data-inbox-view="{{ $key }}">
                            {{ $label }} <span class="tabular-nums opacity-70">{{ $n($counts[$key] ?? 0) }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto">
                @forelse ($rows as $row)
                    @php
                        $initial = preg_match('/\p{L}/u', $row->title, $letter) ? mb_strtoupper($letter[0]) : '#';
                        $hue = crc32($row->title) % 360;
                    @endphp
                    <button type="button" wire:key="inbox-{{ $row->key }}" wire:click="select('{{ $row->key }}')" @class(['flex w-full items-center gap-3 px-3 text-left hover:bg-gray-50 dark:hover:bg-white/[0.04]', 'bg-gray-100 dark:bg-white/[0.08]' => $selected?->key === $row->key]) data-inbox-item="{{ $row->key }}" data-inbox-type="{{ $row->type }}">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-[15px] font-semibold text-white" style="background: hsl({{ $hue }} 35% 52%)">{{ $initial }}</span>
                        <span class="min-w-0 flex-1 border-b border-gray-100 py-3 dark:border-gray-800">
                            <span class="flex items-baseline justify-between gap-2">
                                <span class="truncate text-[15px] text-gray-900 dark:text-white">{{ $row->title }}</span>
                                <span @class(['shrink-0 text-xs tabular-nums', 'font-semibold text-success-600' => $row->owed, 'font-semibold text-error-600' => ! $row->owed && $row->late, 'text-gray-500' => ! $row->owed && ! $row->late])>{{ in_array($row->type, [InboxItem::STEP, InboxItem::RISK], true) && ($row->due || $row->late) ? ($row->late ? $row->at?->timezone($tz)->format('d.m').' · gecikti' : 'Bugün') : $shortTime($row->at) }}</span>
                            </span>
                            <span class="mt-0.5 flex items-center justify-between gap-2">
                                <span class="truncate text-[13px] text-gray-500">{{ $row->preview !== '' ? $row->preview : ($row->subtitle !== '' ? $row->subtitle : $row->channel) }}</span>
                                <span class="flex shrink-0 items-center gap-1">
                                    @if ($row->fromCustomer)<span class="rounded bg-gray-100 px-1.5 text-[11px] text-gray-600 dark:bg-white/10 dark:text-gray-300">Müşteri</span>@endif
                                    @if ($row->type === InboxItem::RISK)<span class="chip chip-down h-5! px-1.5! text-[11px]!">Risk</span>@endif
                                    @if ($row->type !== InboxItem::WHATSAPP)<span class="text-[11px] text-gray-400">{{ $row->channel }}</span>@endif
                                    @if ($row->owed)<span class="h-2.5 w-2.5 rounded-full bg-success-500" title="Cevap bekliyor"></span>@endif
                                </span>
                            </span>
                        </span>
                    </button>
                @empty
                    <div class="p-6">
                        <x-ta.empty-state size="sm" :title="$channel !== '' && $search === '' ? $channelName($channel).': burada bir şey yok' : match ($view) { 'sohbetler' => ($search !== '' ? 'Aramaya uyan sohbet yok' : 'Henüz sohbet yok'), 'borclu' => 'Kimseye cevap borcun yok', 'adimlar' => 'Bugün yapılacak adım yok', 'musteriler' => 'Müşterilerden bekleyen istek yok', default => 'Kutu boş' }" message="Yeni mesaj gelince burada görünür." />
                    </div>
                @endforelse
            </div>
        </section>

        @if ($selected === null)
            <section class="hidden flex-1 items-center justify-center bg-gray-50 p-8 text-center md:flex dark:bg-white/[0.02]">
                <div>
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Soldan bir sohbet seç</p>
                    <p class="mt-1 text-sm text-gray-500">Mesajlar ortada, Claude'un hazırladığı cevap en altta. Kontrol et, gerekirse düzelt, Gönder'e bas.</p>
                </div>
            </section>
        @else
            @php
                $hue = crc32($selected->title) % 360;
                $initial = preg_match('/\p{L}/u', $selected->title, $letter) ? mb_strtoupper($letter[0]) : '#';
            @endphp
            {{-- Middle: the chat, the composer at the bottom --}}
            <section class="flex min-w-0 flex-1 flex-col" data-inbox-detail="{{ $selected->key }}">
                <header class="flex items-center justify-between gap-3 border-b border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="flex min-w-0 items-center gap-2.5">
                        <button type="button" wire:click="select('')" class="rounded-lg p-1 text-gray-500 hover:bg-gray-200 md:hidden dark:hover:bg-white/[0.06]" aria-label="Listeye dön">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                        </button>
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold text-white" style="background: hsl({{ $hue }} 35% 52%)">{{ $initial }}</span>
                        <div class="min-w-0">
                            <h2 class="truncate text-[15px] font-semibold text-gray-900 dark:text-white">{{ $selected->title }}</h2>
                            <p class="flex flex-wrap items-center gap-x-2 text-xs text-gray-500">
                                @if ($selected->conversation !== null)<span>{{ InboxQueue::phoneLabel($selected->conversation->contact_id) }}</span>@elseif ($lead?->phone)<span>{{ $lead->phone }}</span>@else<span>{{ $selected->channel }}</span>@endif
                                @if ($selected->subtitle !== '')<span>· {{ $selected->subtitle }}</span>@endif
                                @if ($detail['fromBackup'])<span title="Telefondaki yedekten geldi; cevap telefondan gider.">· Yedekten</span>@endif
                                @if ($detail['window'] !== null)
                                    @if ($detail['window']['open'])<span class="text-success-600" title="Son gelen mesajdan sonraki 24 saat içinde buradan cevap gider.">· cevap penceresi ~{{ $detail['window']['hours'] }} sa</span>@else<span title="24 saat geçti; WhatsApp kuralı gereği telefondan yazılır.">· pencere kapalı</span>@endif
                                @endif
                            </p>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-1" data-inbox-actions>
                        <button type="button" wire:click="done" wire:loading.attr="disabled" class="btn h-8 px-2.5 text-[13px]" title="{{ $selected->signal !== null ? 'Yapıldı: '.$selected->signal['done'] : 'Cevap verildi ya da adım yapıldı; listeden düşer, kaydın sonraki adımı kurulur.' }}" data-inbox-done>{{ $selected->signal !== null ? 'Yapıldı · '.$selected->signal['done'] : 'Yapıldı' }}</button>
                        <button type="button" wire:click="$toggle('laterOpen')" @class(['btn h-8 px-2.5 text-[13px]', 'ring-1 ring-gray-400' => $laterOpen]) data-inbox-later-toggle>Sonra</button>
                        @unless ($selected->fromCustomer)
                            <button type="button" wire:click="irrelevant" wire:loading.attr="disabled" class="btn-quiet h-8 px-2 text-[13px] text-gray-500" title="Tedarikçi, iş arayan, reklam satan, yanlış numara. Yeniden yazarsa geri gelir." data-inbox-irrelevant>İlgisiz</button>
                        @endunless
                        <button type="button" wire:click="$toggle('who')" @class(['btn h-8 px-2.5 text-[13px] xl:hidden', 'ring-1 ring-gray-400' => $who]) title="Bu kişi hakkında bilinen her şey" data-inbox-who-toggle>Kim?</button>
                    </div>
                </header>
                @if ($laterOpen)
                    <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 bg-gray-50 px-4 py-2.5 text-[13px] dark:border-gray-800 dark:bg-white/[0.02]" data-inbox-later>
                        <span class="text-gray-500">Ne zaman dönsün?</span>
                        <button type="button" wire:click="$set('laterDate', '{{ $tomorrow }}')" @class(['seg h-7! px-2.5! text-xs!', 'seg-active' => $laterDate === $tomorrow || $laterDate === ''])>Yarın</button>
                        <button type="button" wire:click="$set('laterDate', '{{ \Carbon\CarbonImmutable::parse($today, $tz)->addDays(3)->toDateString() }}')" @class(['seg h-7! px-2.5! text-xs!', 'seg-active' => $laterDate === \Carbon\CarbonImmutable::parse($today, $tz)->addDays(3)->toDateString()])>3 gün</button>
                        <button type="button" wire:click="$set('laterDate', '{{ \Carbon\CarbonImmutable::parse($today, $tz)->addWeek()->toDateString() }}')" @class(['seg h-7! px-2.5! text-xs!', 'seg-active' => $laterDate === \Carbon\CarbonImmutable::parse($today, $tz)->addWeek()->toDateString()])>Haftaya</button>
                        <input wire:model.live="laterDate" type="date" min="{{ $tomorrow }}" class="h-7 {{ $input }} px-2 text-xs" aria-label="Tarih seç" />
                        <button type="button" wire:click="later" wire:loading.attr="disabled" class="btn-dark h-7! px-2.5! text-xs!" data-inbox-later-save>Bırak</button>
                        @error('laterDate')<span class="text-xs text-error-600">{{ $message }}</span>@enderror
                    </div>
                @endif
                @if ($selected->signal !== null)
                    <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-800" data-inbox-signal="{{ $selected->signal['key'] }}">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $selected->signal['label'] }}</p>
                        <p class="mt-1 text-[13px] text-gray-600 dark:text-gray-300">{{ $selected->signal['detail'] }}</p>
                        <p class="mt-1 text-xs text-gray-500">Not yazıp "Yapıldı" dersen not kayda işlenir.</p>
                    </div>
                @endif

                {{-- The chat: oldest at the top, the newest at the bottom, opened scrolled down (WhatsApp order). --}}
                <div class="min-h-0 flex-1 space-y-1.5 overflow-y-auto bg-[#efeae2] px-3 py-4 sm:px-8 dark:bg-[#0b141a]" wire:key="inbox-messages-{{ $selected->key }}-{{ $detail['messages']->count() }}-{{ optional($detail['messages']->last())['at'] ?? '' }}" x-data x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight })" data-inbox-messages data-wa-oldest-first>
                    @php $previousDay = null; @endphp
                    @forelse ($detail['messages'] as $m)
                        @php $day = $m['at']->timezone($tz); $isMessage = ! isset($m['kind']) || in_array($m['kind'], ['message_in', 'message_out'], true); @endphp
                        @if ($previousDay !== $day->toDateString())
                            @php $previousDay = $day->toDateString(); @endphp
                            <div class="flex justify-center py-2"><span class="rounded-lg bg-white/90 px-3 py-1 text-xs text-gray-600 shadow-sm dark:bg-[#182229] dark:text-gray-300">{{ $day->isToday() ? 'Bugün' : ($day->isYesterday() ? 'Dün' : $day->translatedFormat('j F Y')) }}</span></div>
                        @endif
                        @if ($isMessage)
                            <div class="flex {{ $m['in'] ? 'justify-start' : 'justify-end' }}">
                                <div class="max-w-[80%] rounded-lg px-2.5 pt-1.5 pb-1 text-[14px] leading-snug shadow-sm {{ $m['in'] ? 'rounded-tl-none bg-white text-gray-900 dark:bg-[#202c33] dark:text-gray-100' : 'rounded-tr-none bg-[#d9fdd3] text-gray-900 dark:bg-[#005c4b] dark:text-gray-100' }}">
                                    @if (! empty($m['who']))<p class="text-xs font-semibold text-gray-500">{{ $m['who'] }}</p>@endif
                                    <p class="whitespace-pre-wrap break-words">{{ $m['body'] !== '' ? $m['body'] : '(ek: '.$m['type'].')' }}<span class="float-right ml-2 mt-1.5 text-[11px] text-gray-500 dark:text-gray-400">{{ $day->format('H:i') }}</span></p>
                                </div>
                            </div>
                        @else
                            <p class="py-1 text-center text-xs text-gray-600 dark:text-gray-400">{{ \App\Models\SalesLeadEvent::KINDS[$m['kind']] ?? $m['kind'] }} · {{ $m['body'] }}</p>
                        @endif
                    @empty
                        <p class="py-6 text-center text-sm text-gray-600">{{ $selected->type === InboxItem::STEP ? 'Henüz mesaj yok; ilk metin aşağıda.' : 'Mesaj yok.' }}</p>
                    @endforelse
                </div>

                {{-- Composer: Claude's draft in the box; the operator checks it and sends. --}}
                <div class="border-t border-gray-200 bg-gray-50 px-3 py-2.5 dark:border-gray-800 dark:bg-white/[0.03]" data-inbox-draft>
                    @if ($draft['wait'])
                        <p class="mb-2 rounded-lg px-3 py-2 text-sm tone-look">Şimdilik yeni mesaj yazma; karşı tarafın dönüşünü bekle.{{ $draft['note'] !== '' ? ' '.$draft['note'] : '' }}</p>
                    @endif
                    <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
                        <span>
                            {{ $selected->signal !== null ? 'Not' : ($draft['source'] === 'none' ? 'Mesaj' : 'Claude\'un taslağı') }}
                            @if ($draft['busy'])<span>· Claude yazıyor…</span>
                            @elseif ($draft['status'] === 'waiting')<span>· {{ $draft['note'] !== '' ? $draft['note'] : 'Abonelikteki Claude yazacak' }}</span>
                            @elseif ($draft['status'] === 'failed')<span class="text-error-600">· {{ $draft['note'] !== '' ? $draft['note'] : 'Yazılamadı' }}</span>
                            @elseif ($draft['note'] !== '' && ! $draft['wait'])<span title="{{ $draft['note'] }}">· {{ \Illuminate\Support\Str::limit($draft['note'], 90) }}</span>
                            @endif
                        </span>
                        @if ($draft['source'] !== 'none')
                            <button type="button" wire:click="write" wire:loading.attr="disabled" class="link-neutral" data-inbox-write>{{ $draft['text'] !== '' || $draft['busy'] ? 'Yeniden yaz' : 'Claude yazsın' }}</button>
                        @endif
                    </div>
                    <div class="flex items-end gap-2">
                        <textarea wire:model="reply" rows="{{ $selected->signal !== null ? 2 : 3 }}" x-data x-init="$el.style.height = Math.min($el.scrollHeight, 240) + 'px'" x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 240) + 'px'" class="max-h-60 min-h-[2.75rem] w-full resize-none rounded-lg border-0 bg-white px-3 py-2.5 font-sans text-[14px] leading-relaxed text-gray-900 shadow-sm ring-1 ring-gray-200 placeholder:text-gray-400 focus:ring-gray-400 focus:outline-none dark:bg-[#2a3942] dark:text-white dark:ring-transparent" placeholder="{{ $draft['busy'] ? 'Claude yazıyor…' : ($selected->signal !== null ? 'Ne yaptın, ne dedi (isteğe bağlı)' : 'Mesaj yaz') }}" aria-label="Metin" data-inbox-reply></textarea>
                        @if ($detail['canSend'])
                            <button type="button" wire:click="send" wire:loading.attr="disabled" class="btn-primary h-11 shrink-0 px-4" data-inbox-send>Gönder</button>
                        @elseif ($waUrl !== null)
                            <a href="{{ $waUrl }}" target="_blank" rel="noopener" class="btn-primary h-11 shrink-0 px-4" title="24 saat penceresi kapalı ya da yedekten gelen sohbet: telefonda açılır" data-inbox-wa>WhatsApp'ta aç</a>
                        @endif
                    </div>
                    @if (($detail['phoneIssue'] ?? null) !== null)<p class="mt-1 text-xs text-error-600" data-inbox-phone-issue>Numara hatalı, WhatsApp açılmaz: {{ $detail['phoneIssue'] }}</p>@endif
                    @error('reply')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                    @if ($selected->thread?->send_error)<p class="mt-1 text-xs text-error-600">{{ $selected->thread->send_error }}</p>@endif
                    <div class="mt-1.5 flex flex-wrap items-center gap-3 text-xs text-gray-500">
                        <button type="button" x-data x-on:click="navigator.clipboard?.writeText($el.closest('[data-inbox-draft]').querySelector('[data-inbox-reply]').value); $el.textContent = 'Kopyalandı'; setTimeout(() => $el.textContent = 'Kopyala', 1500)" class="link-neutral">Kopyala</button>
                        <span>{{ $detail['canSend'] ? 'Gönder\'e basınca mesaj numaradan gider ve telefonunda da görünür.' : ($selected->thread !== null ? '24 saat geçti; uygulamadan yaz.' : 'Telefondan gönderip "Yapıldı" de.') }}</span>
                    </div>
                </div>
            </section>

            {{-- Right: the record's card --}}
            @if ($who)
                <div wire:click="$set('who', false)" class="fixed inset-0 z-30 bg-black/30 xl:hidden" aria-hidden="true"></div>
            @endif
            <aside @class(['w-80 shrink-0 space-y-3 overflow-y-auto border-l border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-900', 'fixed inset-y-0 right-0 z-40 block w-[22rem] max-w-full shadow-xl xl:static xl:z-auto xl:w-80 xl:shadow-none' => $who, 'hidden xl:block' => ! $who]) data-inbox-record>
                @if ($person !== null)
                    <section class="card p-4" data-inbox-who>
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Kim? · {{ $person['title'] }}</h3>
                            <button type="button" wire:click="$set('who', false)" class="text-gray-400 hover:text-gray-700 xl:hidden" aria-label="Kapat">✕</button>
                        </div>
                        @if ($person['summary'])<p class="mt-2 text-[13px] text-gray-700 dark:text-gray-300">{{ $person['summary'] }}</p>@endif
                        @if ($person['channels'] !== [])
                            <ul class="mt-2 space-y-0.5 text-[13px] text-gray-600 dark:text-gray-300" data-inbox-who-channels>
                                @foreach ($person['channels'] as $line)<li>{{ $line }}</li>@endforeach
                            </ul>
                        @endif
                        @if ($person['facts'] !== [])
                            <dl class="mt-3 space-y-1.5 text-[13px]">
                                @foreach ($person['facts'] as $fact)
                                    <div><dt class="text-xs text-gray-500">{{ $fact['label'] }}</dt><dd class="text-gray-900 dark:text-white">{{ $fact['text'] }}</dd></div>
                                @endforeach
                            </dl>
                        @endif
                        @if ($person['recent'] !== [])
                            <p class="mt-3 text-xs font-medium text-gray-700 dark:text-gray-300">Son olanlar</p>
                            <ul class="mt-1 space-y-1 text-[13px] text-gray-600 dark:text-gray-300">
                                @foreach ($person['recent'] as $row)<li><span class="text-gray-400 tabular-nums">{{ $row['at'] }}</span> {{ $row['text'] }}</li>@endforeach
                            </ul>
                        @endif
                        @if (! $person['known'])<p class="mt-2 text-[13px] text-gray-500">Bu kişi hakkında henüz kayıtlı bilgi yok.</p>@endif
                    </section>
                @endif
                <section class="card p-4">
                    <div class="flex items-start justify-between gap-2">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Kayıt</h3>
                        @if ($lead !== null)
                            <a href="{{ route('operator.sales.lead', ['lead' => $lead->id]) }}" wire:navigate class="link-primary text-xs" data-inbox-card-link>Kart →</a>
                        @endif
                    </div>
                    @if ($lead === null)
                        <p class="mt-2 text-sm text-gray-500">{{ $selected->customer !== null ? 'Müşterinin satış kaydı henüz yok.' : 'Bu görüşmenin kaydı yok.' }}</p>
                        <button type="button" wire:click="openRecord" wire:loading.attr="disabled" class="btn mt-3 h-8 px-3 text-[13px]" data-inbox-open-record>Kayıt aç</button>
                    @else
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @if ($lead->isCustomer())<span class="chip chip-up">Müşteri</span>@elseif ($lead->lifecycle === 'former')<span class="chip chip-flat">Eski müşteri</span>@elseif ($lead->isIrrelevant())<span class="chip chip-flat">İlgisiz</span>@else<span class="chip chip-flat">Aday</span>@endif
                            <span class="chip {{ $stageChip[$lead->stage] ?? 'chip-flat' }}">{{ $lead->stageLabel() }}</span>
                            @if ($lead->kindLabel() !== null)<span class="chip chip-flat">{{ $lead->kindLabel() }}</span>@elseif ($lead->suggestedKind() !== null)<span class="chip chip-flat">{{ \App\Support\Sales\LeadKinds::label($lead->suggestedKind()) }}?</span>@else<a href="{{ route('operator.sales.lead', ['lead' => $lead->id]) }}" wire:navigate class="chip chip-down">Tür seçilmedi</a>@endif
                        </div>
                        <dl class="mt-3 space-y-1.5 text-[13px]">
                            @if ($lead->company && $lead->company !== $selected->title)<div class="flex justify-between gap-3"><dt class="text-gray-500">İşletme</dt><dd class="text-right text-gray-900 dark:text-white">{{ $lead->company }}</dd></div>@endif
                            @if ($lead->city)<div class="flex justify-between gap-3"><dt class="text-gray-500">Şehir</dt><dd class="text-right text-gray-900 dark:text-white">{{ $lead->city }}</dd></div>@endif
                            @if ($lead->sectorCategory)<div class="flex justify-between gap-3"><dt class="text-gray-500">Sektör</dt><dd class="text-right text-gray-900 dark:text-white">{{ $lead->sectorCategory->name }}</dd></div>@endif
                            @if ($lead->website_host)<div class="flex justify-between gap-3"><dt class="text-gray-500">Site</dt><dd class="text-right"><a href="https://{{ $lead->website_host }}" target="_blank" rel="noopener" class="link-neutral">{{ $lead->website_host }}</a></dd></div>@endif
                            <div class="flex justify-between gap-3"><dt class="text-gray-500">Nereden</dt><dd class="text-right text-gray-900 dark:text-white">{{ $lead->sourceLabel() }}{{ $lead->heard_from ? ' · '.(\App\Models\SalesLead::HEARD_FROM[$lead->heard_from] ?? $lead->heard_from) : '' }}</dd></div>
                            @if ($lead->next_step)<div class="flex justify-between gap-3"><dt class="text-gray-500">Sonraki adım</dt><dd @class(['text-right', 'font-semibold text-error-600' => $selected->late, 'text-gray-900 dark:text-white' => ! $selected->late])>{{ $lead->next_step }}{{ $lead->next_step_at ? ' · '.$lead->next_step_at->timezone($tz)->format('d.m') : '' }}</dd></div>@endif
                            @if ($lead->offer_amount)<div class="flex justify-between gap-3"><dt class="text-gray-500">Teklif</dt><dd class="text-right text-gray-900 dark:text-white">{{ $n((int) $lead->offer_amount) }} ₺{{ $lead->serviceLabel() ? ' · '.$lead->serviceLabel() : '' }}</dd></div>@endif
                            @if ($detail['opportunity'] !== null)<div class="flex justify-between gap-3"><dt class="text-gray-500">Açık fırsat</dt><dd class="text-right text-gray-900 dark:text-white">{{ $detail['opportunity']->title() }} · {{ $detail['opportunity']->amountLabel() }}</dd></div>@endif
                            @if ($lead->lastTouchAt())<div class="flex justify-between gap-3"><dt class="text-gray-500">Son temas</dt><dd class="text-right text-gray-900 dark:text-white">{{ $lead->lastTouchAt()->timezone($tz)->format('d.m.Y') }}</dd></div>@endif
                        </dl>
                        @if ($detail['findings'] !== [])
                            <p class="mt-3 text-xs font-medium text-gray-700 dark:text-gray-300">Bulgular</p>
                            <ul class="mt-1 space-y-1 text-[13px] text-gray-700 dark:text-gray-300">
                                @foreach ($detail['findings'] as $finding)<li class="flex gap-2"><span class="text-gray-400">◎</span><span>{{ \Illuminate\Support\Str::limit($finding, 110) }}</span></li>@endforeach
                            </ul>
                        @endif
                    @endif
                </section>

                @if ($selected->fromCustomer)
                    <section class="card p-4" data-inbox-customer>
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Müşteri</h3>
                            @if ($detail['brand'] !== null)<a href="{{ route('operator.brand', ['brand' => $detail['brand']->id]) }}" wire:navigate class="link-primary text-xs">Marka →</a>@elseif ($selected->customer !== null)<a href="{{ route('operator.customer', ['customerId' => $selected->customer->id]) }}" wire:navigate class="link-primary text-xs">Müşteri →</a>@endif
                        </div>
                        @if ($detail['terms'] !== null)
                            @php $terms = $detail['terms']; $agreement = $terms['agreement']; @endphp
                            <dl class="mt-2 space-y-1.5 text-[13px]">
                                <div class="flex justify-between gap-3"><dt class="text-gray-500">Anlaşma</dt><dd class="text-right text-gray-900 dark:text-white">{{ $agreement !== null ? $agreement->feeLabel().' · '.(\App\Models\Agreement::KINDS[$agreement->kind] ?? $agreement->kind) : 'Yok' }}</dd></div>
                                @if ($terms['next_due'] !== null)<div class="flex justify-between gap-3"><dt class="text-gray-500">Sıradaki ödeme</dt><dd class="text-right text-gray-900 dark:text-white">{{ \Carbon\CarbonImmutable::parse($terms['next_due'])->format('d.m.Y') }}</dd></div>@endif
                                @if ($terms['overdue'] > 0)<div class="flex justify-between gap-3"><dt class="text-gray-500">Geciken</dt><dd class="text-right font-semibold text-error-600">{{ $n((int) $terms['overdue']) }} ₺ · {{ $terms['late_days'] }} gün</dd></div>@endif
                            </dl>
                            @if ($terms['risks'] !== [])
                                <div class="mt-2 flex flex-wrap gap-1.5">@foreach ($terms['risks'] as $risk)<span class="chip chip-down" data-inbox-risk="{{ $risk['key'] }}">{{ $risk['label'] }}</span>@endforeach</div>
                            @endif
                        @else
                            <p class="mt-2 text-sm text-gray-500">Marka kaydı bulunamadı; anlaşma bilgisi yok.</p>
                        @endif
                    </section>
                @endif

                @if ($selected->thread !== null && $selected->thread->post_url)
                    <section class="card p-4">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Gönderi</h3>
                        @if ($selected->thread->post_caption)<p class="mt-1 text-[13px] text-gray-600 dark:text-gray-300">{{ \Illuminate\Support\Str::limit($selected->thread->post_caption, 140) }}</p>@endif
                        <a href="{{ $selected->thread->post_url }}" target="_blank" rel="noopener" class="link-neutral mt-2 inline-block text-xs">Gönderiyi aç ↗</a>
                    </section>
                @endif
            </aside>
        @endif
    </div>
</div>
