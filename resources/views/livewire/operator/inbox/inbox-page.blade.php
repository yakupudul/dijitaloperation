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
    $waUrl = $detail !== null && $detail['waNumber'] !== null ? 'https://wa.me/'.$detail['waNumber'].(trim($reply) !== '' ? '?text='.rawurlencode(trim($reply)) : '') : null;
@endphp
{{-- Gelen kutusu (Aşama 2): the day's one queue. Left the lines, middle the chat and the text, right the record. --}}
<div class="pb-24" data-inbox-page>
    {{-- New messages and the texts Claude writes show up by themselves; faster while a text is being written. --}}
    @if ($drafting)
        <div wire:key="inbox-poll-fast" wire:poll.5s.visible hidden></div>
    @else
        <div wire:key="inbox-poll-slow" wire:poll.30s.visible hidden></div>
    @endif
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="page-title">Gelen <span class="highlight">kutusu</span></h1>
            <p class="page-sub">Kim ne yazdı, bugün kime ne yapacaksın: WhatsApp, Instagram, Facebook, form ve adımlar tek sırada. Her satır Yapıldı, Sonra ya da İlgisiz ile çıkar; hedef gün sonunda sıfır.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($readyCount > 0)
                <button type="button" class="btn-primary" x-on:click="if (confirm('Hazır {{ $readyCount }} sosyal cevabın hepsi gönderilsin mi?')) $wire.sendReady()" data-inbox-send-ready>Hazır sosyal cevapları gönder ({{ $n($readyCount) }})</button>
            @endif
            <a href="{{ route('operator.whatsapp') }}" wire:navigate class="btn" title="Tüm WhatsApp görüşmeleri, Beyin ve bağlantı ayarları">WhatsApp</a>
            <a href="{{ route('operator.sales') }}" wire:navigate class="btn">Satış</a>
        </div>
    </header>

    <div class="hero-panel mt-6 grid-cols-2 lg:grid-cols-4" data-inbox-strip>
        <div class="hero-tile hero-tile-accent"><span class="hero-label">Cevap borcu</span><span class="hero-value">{{ $n($strip['owed']) }}</span><span class="mt-2 text-xs opacity-75">son söz onlarda</span></div>
        <div class="hero-tile"><span class="hero-label">Gecikmiş adım</span><span class="hero-value">{{ $n($strip['late']) }}</span><span class="mt-2 text-xs text-white/60">dünden kalan</span></div>
        <div class="hero-tile"><span class="hero-label">Bugünün adımı</span><span class="hero-value">{{ $n($strip['today']) }}</span><span class="mt-2 text-xs text-white/60">bugün yazılacak</span></div>
        <div class="hero-tile"><span class="hero-label">En eski borç</span><span class="hero-value">{{ $strip['oldest'] ?? '—' }}</span><span class="mt-2 text-xs text-white/60">hedef 15 dakikanın altı</span></div>
    </div>

    <div class="mt-6 flex flex-wrap items-center gap-1.5" role="tablist" aria-label="Gelen kutusu görünümleri" data-inbox-views>
        @foreach (InboxQueue::VIEWS as $key => $label)
            <button type="button" wire:click="$set('view', '{{ $key }}')" @class(['seg', 'seg-active' => $view === $key]) role="tab" aria-selected="{{ $view === $key ? 'true' : 'false' }}" data-inbox-view="{{ $key }}">
                {{ $label }} <span @class(['tabular-nums', 'text-accent' => $view === $key && ($counts[$key] ?? 0) > 0, 'text-gray-400' => $view !== $key || ($counts[$key] ?? 0) === 0])>{{ $n($counts[$key] ?? 0) }}</span>
            </button>
        @endforeach
    </div>

    @if ($message !== '')
        <p role="status" class="mt-4 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300" data-inbox-message>{{ $message }}</p>
    @endif
    @error('item')<p role="alert" class="mt-4 rounded-lg px-3 py-2 text-sm tone-critical">{{ $message }}</p>@enderror

    <div class="mt-4 grid gap-4 lg:grid-cols-12" data-inbox-grid>
        {{-- Left: the lines of the view --}}
        <section @class([$pane, 'lg:col-span-4', 'hidden lg:block' => $selected !== null]) data-inbox-list>
            <div class="flex items-center justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                <h2 class="section-title">{{ InboxQueue::VIEWS[$view] }} <span class="section-count">{{ $n($rows->count()) }}</span></h2>
                <span class="text-xs text-gray-500">{{ $view === 'adimlar' ? 'en eski adım üstte' : 'en yeni üstte' }}</span>
            </div>
            <div class="max-h-[calc(100vh-22rem)] min-h-[16rem] overflow-y-auto">
                @forelse ($rows as $row)
                    @php $initial = preg_match('/\p{L}/u', $row->title, $letter) ? mb_strtoupper($letter[0]) : '#'; @endphp
                    <button type="button" wire:key="inbox-{{ $row->key }}" wire:click="select('{{ $row->key }}')" @class(['flex w-full items-start gap-3 border-b border-gray-200 px-4 py-3 text-left hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]', 'bg-gray-50 dark:bg-white/[0.05]' => $selected?->key === $row->key]) data-inbox-item="{{ $row->key }}" data-inbox-type="{{ $row->type }}">
                        <span @class(['flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-sm font-semibold', 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $row->owed, 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200' => ! $row->owed])>{{ $initial }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-baseline justify-between gap-2">
                                <span class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $row->title }}</span>
                                <span @class(['shrink-0 text-xs tabular-nums', 'font-semibold text-error-600' => $row->late, 'text-gray-500' => ! $row->late])>{{ in_array($row->type, [InboxItem::STEP, InboxItem::RISK], true) ? ($row->late ? $row->at?->timezone($tz)->format('d.m').' · gecikti' : 'Bugün') : $shortTime($row->at) }}</span>
                            </span>
                            <span class="mt-0.5 block truncate text-[13px] text-gray-500">{{ $row->preview !== '' ? $row->preview : '—' }}</span>
                            <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500">
                                <span>{{ $row->channel }}</span>
                                @if ($row->subtitle !== '')<span>· {{ $row->subtitle }}</span>@endif
                                @if ($row->owed)<span class="chip chip-accent h-5! px-1.5! text-[11px]!">Cevap borçlu</span>@endif
                                @if ($row->type === InboxItem::RISK)<span class="chip chip-down h-5! px-1.5! text-[11px]!">Risk</span>@endif
                                @if ($row->late)<span class="chip chip-down h-5! px-1.5! text-[11px]!">Gecikti</span>@elseif ($row->due)<span class="chip chip-flat h-5! px-1.5! text-[11px]!">Bugün</span>@endif
                            </span>
                        </span>
                    </button>
                @empty
                    <div class="p-6">
                        <x-ta.empty-state size="sm" :title="match ($view) { 'borclu' => 'Kimseye cevap borcun yok', 'adimlar' => 'Bugün yapılacak adım yok', 'musteriler' => 'Müşterilerden bekleyen istek yok', default => 'Kutu boş' }" message="Yeni mesaj gelince ya da bir kaydın adımı bugüne gelince burada görünür." />
                    </div>
                @endforelse
            </div>
        </section>

        @if ($selected === null)
            <section class="{{ $pane }} flex items-center justify-center p-8 text-center lg:col-span-8">
                <div>
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Soldan bir satır seç</p>
                    <p class="mt-1 text-sm text-gray-500">Sohbet ve hazır metin ortada, kaydın kartı sağda açılır. Metni kopyala ya da WhatsApp'ta aç, gönder, "Yapıldı" de.</p>
                </div>
            </section>
        @else
            {{-- Middle: the chat and the text in hand --}}
            <section class="{{ $pane }} flex flex-col lg:col-span-5" data-inbox-detail="{{ $selected->key }}">
                <header class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-200 px-4 py-3.5 dark:border-gray-800">
                    <div class="flex min-w-0 items-start gap-2">
                        <button type="button" wire:click="select('')" class="mt-0.5 rounded-lg p-1 text-gray-500 hover:bg-gray-100 lg:hidden dark:hover:bg-white/[0.06]" aria-label="Listeye dön">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                        </button>
                        <div class="min-w-0">
                            <h2 class="truncate text-[15px] font-semibold text-gray-900 dark:text-white">{{ $selected->title }}</h2>
                            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500">
                                <span>{{ $selected->channel }}</span>
                                @if ($selected->conversation !== null)<span>{{ InboxQueue::phoneLabel($selected->conversation->contact_id) }}</span>@elseif ($lead?->phone)<span>{{ $lead->phone }}</span>@endif
                                @if ($detail['fromBackup'])<span title="Telefondaki yedekten geldi; cevap telefondan gider.">Yedekten</span>@endif
                                @if ($detail['window'] !== null)
                                    @if ($detail['window']['open'])<x-ta.pill tone="ok" title="Son gelen mesajdan sonraki 24 saat içinde cevap verilebilir.">Cevap penceresi açık, ~{{ $detail['window']['hours'] }} sa</x-ta.pill>@else<x-ta.pill tone="look" title="24 saat geçti; WhatsApp kuralı gereği yeni mesaj için onaylı şablon gerekir.">Pencere kapalı · onaylı şablon</x-ta.pill>@endif
                                @endif
                                @if ($selected->thread !== null && ! $selected->thread->canReply())<x-ta.pill tone="look">24 saat geçti; uygulamadan yaz</x-ta.pill>@endif
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5" data-inbox-actions>
                        <button type="button" wire:click="done" wire:loading.attr="disabled" class="btn-dark h-8 px-3 text-[13px]" title="{{ $selected->signal !== null ? 'Yapıldı: '.$selected->signal['done'] : 'Cevap telefondan gitti ya da adım yapıldı; kaydın sonraki adımı kurulur.' }}" data-inbox-done>{{ $selected->signal !== null ? 'Yapıldı · '.$selected->signal['done'] : 'Yapıldı' }}</button>
                        <button type="button" wire:click="$toggle('laterOpen')" @class(['btn h-8 px-3 text-[13px]', 'ring-1 ring-gray-400' => $laterOpen]) data-inbox-later-toggle>Sonra</button>
                        @unless ($selected->fromCustomer)
                            <button type="button" wire:click="irrelevant" wire:loading.attr="disabled" class="btn-quiet h-8 px-2.5 text-[13px] text-gray-500" title="Tedarikçi, iş arayan, reklam satan, yanlış numara. Yeniden yazarsa kutuya döner." data-inbox-irrelevant>İlgisiz</button>
                        @endunless
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
                <div class="max-h-[26rem] min-h-[10rem] space-y-2 overflow-y-auto bg-gray-50 px-4 py-4 dark:bg-white/[0.02]" wire:key="inbox-messages-{{ $selected->key }}-{{ $detail['messages']->count() }}" data-inbox-messages>
                    @php $previousDay = null; @endphp
                    @forelse ($detail['messages'] as $m)
                        @php $day = $m['at']->timezone($tz); $isMessage = ! isset($m['kind']) || in_array($m['kind'], ['message_in', 'message_out'], true); @endphp
                        @if ($previousDay !== $day->toDateString())
                            @php $previousDay = $day->toDateString(); @endphp
                            <div class="flex justify-center py-1"><span class="rounded-full bg-white px-3 py-0.5 text-xs text-gray-500 ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">{{ $day->isToday() ? 'Bugün' : ($day->isYesterday() ? 'Dün' : $day->translatedFormat('j F Y')) }}</span></div>
                        @endif
                        @if ($isMessage)
                            <div class="flex {{ $m['in'] ? 'justify-start' : 'justify-end' }}">
                                <div class="max-w-[85%] rounded-2xl px-3 py-2 text-sm shadow-sm {{ $m['in'] ? 'rounded-bl-sm bg-white text-gray-900 dark:bg-gray-800 dark:text-gray-100' : 'rounded-br-sm bg-success-100 text-gray-900 dark:bg-success-500/20 dark:text-gray-100' }}">
                                    <p class="whitespace-pre-wrap break-words">{{ $m['body'] !== '' ? $m['body'] : '(ek: '.$m['type'].')' }}</p>
                                    <p class="mt-1 text-right text-xs text-gray-500">{{ $day->format('H:i') }}</p>
                                </div>
                            </div>
                        @else
                            <p class="text-center text-xs text-gray-500">{{ \App\Models\SalesLeadEvent::KINDS[$m['kind']] ?? $m['kind'] }} · {{ $m['body'] }}</p>
                        @endif
                    @empty
                        <p class="py-6 text-center text-sm text-gray-500">{{ $selected->type === InboxItem::STEP ? 'Henüz mesaj yok; ilk metin aşağıda.' : 'Mesaj yok.' }}</p>
                    @endforelse
                </div>

                <div class="border-t border-gray-200 p-4 dark:border-gray-800" data-inbox-draft>
                    @if ($draft['wait'])
                        <p class="rounded-lg px-3 py-2 text-sm tone-look">Şimdilik yeni mesaj yazma; karşı tarafın dönüşünü bekle.{{ $draft['note'] !== '' ? ' '.$draft['note'] : '' }}</p>
                    @endif
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                            {{ $selected->signal !== null ? 'Not' : ($selected->thread !== null ? 'Cevap' : 'Gönderilecek metin') }}
                            @if ($draft['busy'])<span class="font-normal text-gray-500">· Claude yazıyor…</span>
                            @elseif ($draft['status'] === 'waiting')<span class="font-normal text-gray-500">· {{ $draft['note'] !== '' ? $draft['note'] : 'Abonelikteki Claude yazacak' }}</span>
                            @elseif ($draft['status'] === 'failed')<span class="font-normal text-error-600">· {{ $draft['note'] !== '' ? $draft['note'] : 'Yazılamadı' }}</span>
                            @endif
                        </p>
                        @if ($draft['source'] !== 'none')
                            <button type="button" wire:click="write" wire:loading.attr="disabled" class="btn-quiet h-7! text-xs!" data-inbox-write>{{ $draft['text'] !== '' || $draft['busy'] ? 'Yeniden yaz' : 'Claude yazsın' }}</button>
                        @endif
                    </div>
                    <textarea wire:model="reply" rows="{{ $selected->signal !== null ? 2 : 5 }}" class="mt-2 w-full {{ $input }} py-2 font-sans leading-relaxed" placeholder="{{ $draft['busy'] ? 'Claude yazıyor…' : ($selected->signal !== null ? 'Ne yaptın, ne dedi (isteğe bağlı)' : ($selected->thread !== null ? 'Cevabını yaz' : 'Telefondan göndereceğin metin')) }}" aria-label="Metin" data-inbox-reply></textarea>
                    @if ($draft['note'] !== '' && ! $draft['wait'] && $draft['source'] !== 'conversation')<p class="mt-1 text-xs text-gray-500">Claude'un notu: {{ $draft['note'] }}</p>@endif
                    @error('reply')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                    @if ($selected->thread?->send_error)<p class="mt-1 text-xs text-error-600">{{ $selected->thread->send_error }}</p>@endif
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        @if ($selected->thread !== null)
                            @if ($selected->thread->canReply())
                                <button type="button" wire:click="send" wire:loading.attr="disabled" class="btn-primary h-8 px-3 text-[13px]" data-inbox-send>Gönder</button>
                            @endif
                        @elseif ($waUrl !== null)
                            <a href="{{ $waUrl }}" target="_blank" rel="noopener" class="btn-primary h-8 px-3 text-[13px]" data-inbox-wa>WhatsApp'ta aç</a>
                        @endif
                        <button type="button" x-data x-on:click="navigator.clipboard?.writeText($el.closest('[data-inbox-draft]').querySelector('[data-inbox-reply]').value); $el.textContent = 'Kopyalandı'; setTimeout(() => $el.textContent = 'Kopyala', 1500)" class="btn h-8 px-3 text-[13px]">Kopyala</button>
                        <span class="text-xs text-gray-500">{{ $selected->signal !== null ? 'Müşteriye yazacaksan WhatsApp\'ta aç; sonra "Yapıldı".' : ($selected->thread !== null ? 'Instagram / Facebook cevabı buradan gider.' : 'WhatsApp mesajı telefondan gider; gönderince "Yapıldı" de.') }}</span>
                    </div>
                </div>
            </section>

            {{-- Right: the record's card --}}
            <aside class="space-y-4 lg:col-span-3" data-inbox-record>
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
