@php
    use App\Livewire\Panel\InboxPage;
    use App\Panel\Models\PanelConversation;
    use App\Panel\Models\PanelImport;
    use App\Panel\Models\PanelRequest;
    use App\Panel\Support\PanelStages;
    use App\Panel\Support\PhoneKey;
    $tz = 'Europe/Istanbul';
    $input = 'h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-base text-gray-900 outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10 sm:h-9 sm:text-sm';
    $area = 'w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-base text-gray-900 outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10 sm:text-sm';
    $label = InboxPage::CHANNELS[$channel][0] ?? '';
    $when = fn ($time) => $time === null ? '' : ($time->timezone($tz)->isToday() ? $time->timezone($tz)->format('H:i') : ($time->timezone($tz)->isCurrentYear() ? $time->timezone($tz)->translatedFormat('j M') : $time->timezone($tz)->format('d.m.Y')));
    $empty = [
        'whatsapp' => ['WhatsApp henüz bağlı değil.', 'Geçmiş yazışmalarınızı telefonunuzdaki yedekten buraya alabilir, yeni mesajların kendiliğinden düşmesi için bağlantı isteyebilirsiniz.'],
        'instagram' => ['Instagram henüz bağlı değil.', 'Bağlandığında Instagram mesajlarınız burada görünür ve yazan kişi tek tıkla adaya dönüşür.'],
        'facebook' => ['Facebook henüz bağlı değil.', 'Bağlandığında sayfanıza gelen mesajlar burada görünür.'],
        'web_form' => ['Sitenizdeki formdan henüz talep düşmedi.', 'Form bağlı değilse biz bağlarız; bağlandıktan sonra her talep burada ve Adaylar ekranında görünür.'],
        'meta_form' => ['Meta formu henüz bağlı değil.', 'Bağlandığında Facebook ve Instagram reklam formlarını dolduranlar kendiliğinden aday olur.'],
        'call' => ['Henüz çağrı kaydı yok.', 'Adaylar ekranında kaynağı “Çağrı” seçerek ekleyebilir ya da çağrı listenizi Excel ile yükleyebilirsiniz.'],
    ];
@endphp
<div class="space-y-5" data-panel-inbox>
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="page-title">Gelen kutusu</h1>
            <p class="page-sub">Size yazan ve arayan herkes tek ekranda. Buradan mesaj gönderilmez; cevabı kendi telefonunuzdan yazarsınız.</p>
        </div>
        @if ($manager && $channel === 'whatsapp')
            <button type="button" wire:click="$toggle('backupOpen')" class="btn w-full sm:w-auto" data-panel-backup-open>WhatsApp yedeği yükle</button>
        @endif
    </header>

    <nav class="no-scrollbar flex gap-1 overflow-x-auto border-b border-gray-200" aria-label="Kanallar">
        @foreach ($tabs as $key => $name)
            <button type="button" wire:click="$set('channel', '{{ $key }}')" @class(['tab', 'tab-active' => $channel === $key]) @if ($channel === $key) aria-current="page" @endif>{{ $name }}</button>
        @endforeach
    </nav>

    @if ($tabs === [])
        <x-ta.empty-state size="sm" icon="none" title="Size açık bir kanal yok." message="Hangi kanalları göreceğinizi markanızın yöneticisi Kullanıcılar ekranından belirler." />
    @endif

    @if ($link)
        <p @class(['flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border px-4 py-2.5 text-sm', 'border-gray-200 text-gray-700' => $link->isConnected(), 'border-error-200 bg-error-50 text-error-700' => ! $link->isConnected()]) data-panel-channel-state>
            @if ($link->isConnected())
                <span class="inline-flex items-center gap-1.5 font-medium text-gray-900"><span class="h-2 w-2 rounded-full bg-success-500" aria-hidden="true"></span>{{ $label }} bağlı{{ $link->label ? ', '.$link->label : '' }}</span>
                <span>{{ $link->last_event_at ? 'Son mesaj '.$link->last_event_at->timezone($tz)->diffForHumans().' geldi.' : 'İlk mesaj gelince burada görünür.' }} Yeni yazan kişi kendiliğinden aday olur.</span>
            @else
                <span class="font-medium">{{ $label }} bağlantısı kesildi.</span>
                <span>Yeni mesajlar şimdilik buraya düşmüyor; eski yazışmalarınız yerinde. Moximu ilgileniyor.</span>
            @endif
        </p>
    @endif

    @if ($backupOpen && $manager && $channel === 'whatsapp')
        <section class="card max-w-3xl space-y-4 p-5" data-panel-backup
            x-data="{
                progress: 0, uploading: false, message: '', ready: @js($imports->contains('status', 'uploaded')),
                async send(url, body) {
                    for (let attempt = 1; ; attempt++) {
                        let response;
                        try {
                            response = await fetch(url, { method: 'POST', credentials: 'same-origin', body,
                                headers: { 'Content-Type': 'application/octet-stream', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
                        } catch (_) {
                            if (attempt < 4) { await new Promise(done => setTimeout(done, attempt * 2000)); continue; }
                            throw new Error('Sunucuya ulaşılamadı. İnternet bağlantınızı kontrol edip dosyayı yeniden seçin.');
                        }
                        const data = await response.json().catch(() => ({}));
                        if (response.status === 409 && data.expected_offset !== undefined) return data;
                        if (!response.ok) {
                            if ([401, 419].includes(response.status)) throw new Error('Oturumunuz kapanmış. Sayfayı yenileyip yeniden giriş yapın.');
                            if (response.status >= 500 && attempt < 4) { await new Promise(done => setTimeout(done, attempt * 2000)); continue; }
                            throw new Error(data.message || ('Yükleme durdu (HTTP ' + response.status + ').'));
                        }
                        return data;
                    }
                },
                async upload(input) {
                    const file = input.files[0];
                    if (!file || this.uploading) return;
                    this.uploading = true; this.message = ''; this.progress = 0; this.ready = false;
                    try {
                        const started = await $wire.beginBackup(file.name, file.size);
                        const url = @js(route('panel.inbox.backup.chunk', ['import' => 0])).replace(/\/0\/parca$/, '/' + started.id + '/parca');
                        let offset = 0;
                        while (offset < file.size) {
                            const answer = await this.send(url + '?offset=' + offset, file.slice(offset, offset + started.chunk));
                            offset = answer.expected_offset ?? answer.received;
                            this.progress = Math.floor(offset * 100 / file.size);
                        }
                        this.ready = true;
                        this.message = 'Dosya yüklendi. Şimdi 64 haneli anahtarı yazıp “Sohbetleri aktar”a basın.';
                    } catch (error) { this.message = error.message || 'Dosya yüklenemedi. Dosya adı msgstore.db.crypt15 olmalı.'; }
                    finally { this.uploading = false; input.value = ''; }
                },
                async extract(input) { if (await $wire.extractBackup(input.value)) { input.value = ''; this.ready = false; this.message = ''; } },
            }">
            <div>
                <h2 class="text-[15px] font-semibold text-gray-900">Geçmiş WhatsApp yazışmalarını buraya alın</h2>
                <ol class="mt-2 max-w-[70ch] list-decimal space-y-1 pl-5 text-sm leading-6 text-gray-700">
                    <li>Telefonda WhatsApp Business › Ayarlar › Sohbetler › Sohbet yedeği › Uçtan uca şifreli yedek bölümünde “64 haneli anahtar” ile yedek alın ve anahtarı not edin.</li>
                    <li>Telefonun dosyalarında Android › media › com.whatsapp.w4b › WhatsApp Business › Databases klasöründeki msgstore.db.crypt15 dosyasını seçin.</li>
                    <li>Anahtarı yazın. Yalnız birebir yazışmalar alınır; gruplar, fotoğraf ve videolar alınmaz. Eski sohbetler aday listenize eklenmez.</li>
                </ol>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <label class="btn cursor-pointer" :class="uploading && 'pointer-events-none opacity-60'">
                    <input type="file" class="sr-only" accept=".crypt15" @change="upload($event.target)">
                    <span x-text="uploading ? 'Yükleniyor %' + progress : 'Yedek dosyasını seç'">Yedek dosyasını seç</span>
                </label>
                <input type="text" x-ref="key" class="{{ $input }} min-w-0 flex-1 font-mono sm:min-w-80" placeholder="64 haneli anahtar" autocomplete="off" spellcheck="false" aria-label="64 haneli anahtar">
                <button type="button" class="btn-primary" :disabled="!ready" :class="!ready && 'opacity-50'" @click="extract($refs.key)">Sohbetleri aktar</button>
            </div>
            <div x-show="uploading" x-cloak class="h-1.5 overflow-hidden rounded-full bg-gray-100"><div class="h-full bg-gray-900 transition-all" :style="'width:' + progress + '%'"></div></div>
            <p x-show="message" x-cloak x-text="message" class="text-sm text-gray-700" role="status"></p>
            @if ($imports->isNotEmpty())
                <ul class="divide-y divide-gray-200 border-t border-gray-200" @if ($imports->whereIn('status', ['queued', 'running'])->isNotEmpty()) wire:poll.10s @endif>
                    @foreach ($imports as $import)
                        <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5 text-sm" wire:key="imp-{{ $import->id }}">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-gray-900">{{ $import->created_at->timezone($tz)->translatedFormat('j F Y, H:i') }}, {{ number_format($import->size / 1048576, 1, ',', '.') }} MB</span>
                                <span class="block text-[13px] text-gray-500">
                                    @if ($import->status === 'completed')
                                        {{ (int) ($import->stats['chats'] ?? 0) }} sohbet, {{ number_format((int) ($import->stats['messages'] ?? 0), 0, ',', '.') }} yeni mesaj, {{ (int) ($import->stats['linked'] ?? 0) }} tanesi kayıtlı adayınızla eşleşti. {{ (int) ($import->stats['groups'] ?? 0) }} grup alınmadı.
                                    @elseif ($import->error)
                                        {{ $import->error }}
                                    @elseif ($import->status === 'uploaded')
                                        Dosya hazır, anahtarı bekliyor.
                                    @elseif (in_array($import->status, ['queued', 'running'], true))
                                        Sohbetler aktarılıyor, sayfa kendiliğinden yenilenir.
                                    @endif
                                </span>
                            </span>
                            <x-ta.pill :tone="PanelImport::tone($import->status)">{{ PanelImport::STATUSES[$import->status] ?? '' }}</x-ta.pill>
                            @if ($import->status === 'completed')
                                <button type="button" wire:click="undoBackup({{ $import->id }})" wire:confirm="Bu yedeğin eklediği mesajlar silinecek. Adaylarınız ve notlarınız yerinde kalır. Geri alınsın mı?" class="btn-quiet">Geri al</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    @if ($channel !== '' && $total === 0)
        <section class="max-w-2xl" data-panel-inbox-empty>
            <x-ta.empty-state size="sm" icon="none" :title="$link?->isConnected() ? 'Henüz mesaj gelmedi.' : $empty[$channel][0]" :message="$link?->isConnected() ? 'Bağlantı kuruldu. İlk mesaj geldiğinde burada görünür ve zil haber verir.' : $empty[$channel][1]">
                <div class="flex flex-wrap gap-2">
                    @if ($channel === 'whatsapp' && $manager && ! $backupOpen)
                        <button type="button" wire:click="$set('backupOpen', true)" class="btn-primary">Yedek yükle</button>
                    @endif
                    @if ($channel === 'call')
                        <a href="{{ route('panel.leads') }}" wire:navigate class="btn">Adaylar'a git</a>
                    @elseif ($can['support'] && ! $link?->isConnected())
                        <button type="button" wire:click="requestConnection" class="btn" data-panel-connect>Bağlantı iste</button>
                    @endif
                </div>
            </x-ta.empty-state>
        </section>
    @elseif ($channel !== '')
        <div class="grid gap-5 lg:grid-cols-[300px_minmax(0,1fr)] xl:grid-cols-[300px_minmax(0,1fr)_288px]">
            {{-- The list --}}
            <section @class(['min-w-0 space-y-3', 'hidden lg:block' => $record]) data-panel-inbox-list>
                <div class="flex gap-2">
                    <input type="search" wire:model.live.debounce.300ms="search" class="{{ $input }}" placeholder="İsim ara" aria-label="İsim ara">
                </div>
                <div class="flex gap-1.5">
                    <button type="button" wire:click="$set('show', 'all')" @class(['seg', 'seg-active' => $show === 'all'])>Tümü</button>
                    <button type="button" wire:click="$set('show', 'new')" @class(['seg', 'seg-active' => $show === 'new'])>Bekleyen {{ $waiting ?: '' }}</button>
                </div>
                <div class="card max-h-[70dvh] overflow-y-auto">
                    @forelse ($rows as $row)
                        @php
                            $isChat = $row instanceof PanelConversation;
                            $title = $isChat ? $row->title($showPhone) : ($row->person?->name ?? 'İsimsiz');
                            $preview = $isChat ? (($row->last_outgoing ? 'Siz: ' : '').$row->last_preview) : ($row->message ?: ($row->interest ?: 'Mesaj yazılmamış'));
                            $time = $isChat ? $row->last_message_at : $row->received_at;
                        @endphp
                        <button type="button" wire:click="$set('open', {{ $row->id }})" wire:key="row-{{ $row->id }}"
                            @class(['flex w-full items-start gap-2.5 border-b border-gray-100 px-3.5 py-3 text-left last:border-b-0 hover:bg-gray-50', 'bg-gray-50' => $open === $row->id])>
                            <span @class(['mt-1.5 h-2 w-2 shrink-0 rounded-full', 'bg-brand-500' => $row->handled_at === null, 'bg-transparent' => $row->handled_at !== null]) aria-hidden="true"></span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-baseline gap-2">
                                    <span @class(['min-w-0 flex-1 truncate text-sm text-gray-900', 'font-semibold' => $row->handled_at === null, 'font-medium' => $row->handled_at !== null])>{{ $title }}</span>
                                    <time class="shrink-0 text-xs tabular-nums text-gray-500">{{ $when($time) }}</time>
                                </span>
                                <span class="mt-0.5 block truncate text-[13px] text-gray-500">{{ $preview }}</span>
                                @if ($isChat)
                                    <span class="mt-1 block text-xs text-gray-500">{{ $row->person_id ? 'Aday, '.PanelStages::label($row->person?->stage) : 'Eski sohbet' }}</span>
                                @endif
                            </span>
                        </button>
                    @empty
                        <x-ta.empty-state size="sm" icon="none" :title="$show === 'new' ? 'Bekleyen yok.' : 'Aramanıza uyan kayıt yok.'" />
                    @endforelse
                </div>
            </section>

            {{-- What was written --}}
            <section @class(['min-w-0', 'hidden lg:block' => ! $record]) data-panel-inbox-thread>
                @if (! $record)
                    <p class="rounded-xl border border-dashed border-gray-200 px-5 py-10 text-center text-sm text-gray-500">Soldan bir kayıt seçin.</p>
                @else
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" wire:click="$set('open', null)" class="btn h-9! lg:hidden">Geri</button>
                            <h2 class="min-w-0 flex-1 truncate text-[17px] font-semibold text-gray-900">{{ $record instanceof PanelConversation ? $record->title($showPhone) : ($person?->name ?? 'İsimsiz') }}</h2>
                            @if ($can['handle'])
                                @if ($record->handled_at === null)
                                    <button type="button" wire:click="handle(true)" class="btn h-9!">İlgilenildi</button>
                                @else
                                    <button type="button" wire:click="handle(false)" class="btn-quiet">Bekleyenlere al</button>
                                @endif
                            @endif
                        </div>
                        @if ($record instanceof PanelRequest)
                            <article class="rounded-xl border border-gray-200 p-5">
                                <p class="text-[13px] text-gray-500">{{ $label }}, {{ $record->received_at->timezone($tz)->translatedFormat('j F Y, H:i') }}</p>
                                @if ($record->interest)<p class="mt-2 text-sm font-medium text-gray-900">{{ $record->interest }}</p>@endif
                                <p class="mt-2 whitespace-pre-line text-[15px] leading-6 text-gray-800">{{ $record->message ?: 'Mesaj yazılmamış.' }}</p>
                                @php($page = $record->meta['page'] ?? ($record->meta['url'] ?? null))
                                @if (is_string($page) && $page !== '')
                                    <p class="mt-3 truncate text-[13px] text-gray-500">Geldiği sayfa: {{ $page }}</p>
                                @endif
                            </article>
                            @if ($history->isNotEmpty())
                                <div>
                                    <h3 class="text-[13px] font-medium text-gray-500">Aynı kişinin önceki talepleri</h3>
                                    <ul class="mt-2 divide-y divide-gray-200 border-y border-gray-200">
                                        @foreach ($history as $old)
                                            <li class="py-2.5 text-sm" wire:key="old-{{ $old->id }}">
                                                <span class="text-[13px] text-gray-500">{{ \App\Panel\Support\PanelSources::label($old->source) }}, {{ $old->received_at->timezone($tz)->translatedFormat('j F Y, H:i') }}</span>
                                                <span class="block truncate text-gray-800">{{ $old->message ?: ($old->interest ?: 'Mesaj yazılmamış') }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        @else
                            <div class="max-h-[68dvh] space-y-1.5 overflow-y-auto rounded-xl border border-gray-200 bg-gray-50 p-3 sm:p-4" data-panel-messages-list
                                x-data x-init="$el.scrollTop = $el.scrollHeight">
                                @if ($older)
                                    <p class="pb-2 text-center"><button type="button" wire:click="$set('limit', {{ $limit + 300 }})" class="link-neutral text-[13px]">Daha eski mesajları göster</button></p>
                                @endif
                                @php($day = null)
                                @foreach ($messages as $message)
                                    @php($sent = $message->sent_at->timezone($tz))
                                    @if ($day !== $sent->format('Y-m-d'))
                                        @php($day = $sent->format('Y-m-d'))
                                        <p class="py-2 text-center text-xs text-gray-500">{{ $sent->translatedFormat('j F Y') }}</p>
                                    @endif
                                    <div @class(['flex', 'justify-end' => $message->outgoing]) wire:key="m-{{ $message->id }}">
                                        <p @class(['max-w-[82%] whitespace-pre-line break-words rounded-2xl px-3 py-2 text-sm leading-5', 'bg-white text-gray-900 ring-1 ring-gray-200' => ! $message->outgoing, 'bg-brand-100 text-gray-900' => $message->outgoing])>{{ $message->body }}<time class="ml-2 inline-block align-bottom text-[11px] tabular-nums text-gray-500">{{ $sent->format('H:i') }}</time></p>
                                    </div>
                                @endforeach
                            </div>
                            <p class="text-[13px] text-gray-500">{{ number_format($record->message_count, 0, ',', '.') }} mesaj. Cevabı kendi telefonunuzdan yazarsınız.</p>
                        @endif
                    </div>
                @endif
            </section>

            {{-- The person's card --}}
            @if ($record)
                <aside class="min-w-0 space-y-5 lg:col-span-2 xl:col-span-1" data-panel-inbox-card>
                    @if (! $person)
                        <div class="rounded-xl border border-gray-200 p-5">
                            <h3 class="text-sm font-semibold text-gray-900">Bu kişi aday listenizde değil</h3>
                            <p class="mt-1 text-sm text-gray-600">{{ $record->contact_phone ? ($showPhone ? $record->contact_phone : PhoneKey::mask($record->contact_phone)) : 'Numarası gizli' }}</p>
                            @if ($can['create'])
                                <button type="button" wire:click="convert" class="btn-primary mt-3 w-full" data-panel-convert>Adaya çevir</button>
                            @endif
                        </div>
                    @else
                        <div class="rounded-xl border border-gray-200 p-5">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <h3 class="truncate text-[15px] font-semibold text-gray-900">{{ $person->name }}</h3>
                                    <p class="text-[13px] text-gray-500">{{ $showPhone ? $person->phone : PhoneKey::mask($person->phone) }}</p>
                                </div>
                                <x-ta.pill :tone="PanelStages::tone($person->stage)">{{ PanelStages::label($person->stage) }}</x-ta.pill>
                            </div>
                            <dl class="mt-3 space-y-1 text-[13px]">
                                @foreach (array_filter(['İlgilendiği' => $person->interest, 'İlgilenen' => $person->assignee?->name, 'Randevu' => $person->appointment_at?->timezone($tz)->translatedFormat('j F, H:i')]) as $name => $value)
                                    <div class="flex gap-2"><dt class="w-20 shrink-0 text-gray-500">{{ $name }}</dt><dd class="min-w-0 truncate text-gray-900">{{ $value }}</dd></div>
                                @endforeach
                            </dl>
                            <a href="{{ route('panel.person', $person->id) }}" wire:navigate class="mt-3 inline-block text-[13px] font-medium text-gray-900 underline">Kaydın tamamını aç</a>
                        </div>
                        @if ($can['stage'])
                            <div>
                                <h3 class="mb-2 text-[13px] font-medium text-gray-500">Aşama</h3>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach (PanelStages::labels() as $key => $name)
                                        @continue($key === PanelStages::LOST)
                                        <button type="button" wire:click="setStage('{{ $key }}')" @class(['seg', 'seg-active' => $person->stage === $key])>{{ $name }}</button>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @if ($can['note'])
                            <form wire:submit="addNote" class="space-y-2">
                                <h3 class="text-[13px] font-medium text-gray-500">Not</h3>
                                <textarea wire:model="note" rows="2" class="{{ $area }}" placeholder="Ne konuşuldu, sırada ne var" aria-label="Not"></textarea>
                                @error('note')<span class="block text-[13px] text-error-600">{{ $message }}</span>@enderror
                                <button type="submit" class="btn">Notu kaydet</button>
                            </form>
                            @if ($lastNotes->isNotEmpty())
                                <ul class="space-y-2">
                                    @foreach ($lastNotes as $event)
                                        <li class="text-[13px] leading-5 text-gray-700" wire:key="n-{{ $event->id }}"><span class="text-gray-500">{{ $event->occurred_at->timezone($tz)->translatedFormat('j M') }}, {{ $event->user?->name }}:</span> {{ \Illuminate\Support\Str::limit((string) $event->body, 160) }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        @endif
                        @if ($can['reminder'])
                            <div>
                                <h3 class="mb-2 text-[13px] font-medium text-gray-500">Hatırlat</h3>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ([1 => 'Yarın', 3 => '3 gün sonra', 7 => '1 hafta sonra'] as $days => $name)
                                        <button type="button" wire:click="remind({{ $days }})" class="seg">{{ $name }}</button>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @if ($can['appointment'])
                            <form wire:submit="setAppointment" class="space-y-2">
                                <h3 class="text-[13px] font-medium text-gray-500">Randevu</h3>
                                <div class="flex gap-2">
                                    <input type="datetime-local" wire:model="appointment" class="{{ $input }} min-w-0" aria-label="Randevu zamanı">
                                    <button type="submit" class="btn shrink-0">Kaydet</button>
                                </div>
                                @error('appointment')<span class="block text-[13px] text-error-600">{{ $message }}</span>@enderror
                            </form>
                        @endif
                        @if ($can['assign'] && $team->isNotEmpty())
                            <label class="block"><span class="mb-2 block text-[13px] font-medium text-gray-500">İlgilenen kişi</span>
                                <select class="{{ $input }}" wire:change="assign($event.target.value)">
                                    <option value="">Kimse</option>
                                    @foreach ($team as $id => $name)<option value="{{ $id }}" @selected((int) $person->assigned_user_id === (int) $id)>{{ $name }}</option>@endforeach
                                </select>
                            </label>
                        @endif
                    @endif
                </aside>
            @endif
        </div>
    @endif
</div>
