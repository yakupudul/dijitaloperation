@php
    use App\Models\FinanceDocument;
    use App\Panel\Models\PanelMeeting;
    use App\Panel\Models\PanelTicket;
    use App\Panel\Services\PanelAccount;
    $input = 'h-9 w-full rounded-lg border border-line bg-white px-3 text-sm text-gray-900 outline-none focus:border-primary dark:border-gray-700 dark:bg-transparent dark:text-white';
    $label = 'mb-1 block text-[13px] font-medium text-gray-700 dark:text-gray-300';
    $doc = '<svg class="h-4 w-4 shrink-0 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/></svg>';
    $panelUrl = 'https://'.config('panel.domain');
@endphp
<div class="space-y-10" data-brand-panel-tab="{{ $view }}">
@if ($view === 'panel')
    <section>
        <div class="section-head">
            <h2 class="section-title">Müşteri paneli</h2>
            <a href="{{ route('operator.settings.panel-users', ['marka' => $brand->id]) }}" wire:navigate class="btn ml-auto">Kullanıcılar ve bölümler</a>
        </div>
        <div class="card">
            <div class="card-row">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ ! $setting->is_enabled ? 'Panel bu marka için kapalı' : ($setting->leaving_at ? 'Ayrılış sürüyor, panel yalnız okunur' : 'Panel açık') }}</p>
                    <p class="mt-0.5 text-[13px] text-gray-500">{{ $users }} kullanıcı{{ $lastLogin ? ', son giriş '.$lastLogin->timezone($tz)->translatedFormat('j F, H:i') : ($users > 0 ? ', henüz giren olmadı' : '') }}</p>
                </div>
                <a href="{{ $panelUrl }}" target="_blank" rel="noopener" class="link-neutral text-sm">{{ config('panel.domain') }}</a>
            </div>
            <div class="card-row items-start!" x-data="{ copied: false }">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Davet metni</p>
                    <p class="mt-1 whitespace-pre-line text-sm leading-6 text-gray-700 dark:text-gray-300" x-ref="invite">{{ $invite }}</p>
                </div>
                <button type="button" class="btn shrink-0" x-on:click="navigator.clipboard.writeText($refs.invite.innerText); copied = true; setTimeout(() => copied = false, 2000)" x-text="copied ? 'Kopyalandı' : 'Kopyala'">Kopyala</button>
            </div>
            <div class="card-row">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Google Drive klasörü</p>
                    <p class="mt-0.5 text-[13px] text-gray-500">
                        @if (! $driveOn) Google Drive bağlı değil; dosyalar şimdilik sunucuda duruyor.
                        @elseif ($folderUrl) Müşterinin gönderdiği her dosya bu klasöre kopyalanır, sunucudaki kopya silinir.
                        @else İlk dosya geldiğinde kendiliğinden açılır; istersen şimdi aç.
                        @endif
                    </p>
                </div>
                @if ($folderUrl)
                    <a href="{{ $folderUrl }}" target="_blank" rel="noopener" class="btn shrink-0">Klasörü aç</a>
                @elseif ($driveOn)
                    <button type="button" wire:click="makeFolder" class="btn shrink-0" wire:loading.attr="disabled">Klasörü oluştur</button>
                @else
                    <a href="{{ route('operator.integrations') }}" wire:navigate class="btn shrink-0">Bağlantılar</a>
                @endif
            </div>
        </div>
    </section>

    <section data-brand-meetings>
        <div class="section-head"><h2 class="section-title">Toplantı istekleri <span class="section-count">{{ $meetings->count() }}</span></h2></div>
        <div class="card">
            @forelse ($meetings as $meeting)
                <div class="card-row flex-wrap items-start!" wire:key="meeting-{{ $meeting->id }}">
                    <div class="min-w-0 flex-1 basis-64">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $meeting->topic }}</p>
                        <p class="mt-0.5 text-[13px] text-gray-500">
                            @if ($meeting->status === PanelMeeting::PLANNED){{ $meeting->starts_at?->timezone($tz)->translatedFormat('j F Y, l, H:i') }}@else İstediği zaman: {{ $meeting->preferred }}@endif
                        </p>
                        @if ($meeting->note)<p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $meeting->note }}</p>@endif
                    </div>
                    @if ($meeting->status === PanelMeeting::REQUESTED)
                        <form wire:submit="planMeeting({{ $meeting->id }})" class="flex flex-wrap items-end gap-2">
                            <label class="block"><span class="{{ $label }}">Gün</span><input type="date" wire:model="plan.{{ $meeting->id }}.day" class="{{ $input }} w-40!"></label>
                            <label class="block"><span class="{{ $label }}">Saat</span><input type="time" wire:model="plan.{{ $meeting->id }}.hour" class="{{ $input }} w-28!"></label>
                            <label class="block"><span class="{{ $label }}">Görüşme bağlantısı</span><input type="url" wire:model="plan.{{ $meeting->id }}.url" placeholder="https://meet.google.com/" class="{{ $input }} w-60!"></label>
                            <button type="submit" class="btn-primary">Planla</button>
                            @error('plan.'.$meeting->id.'.*')<span class="basis-full text-[13px] text-error-600">{{ $message }}</span>@enderror
                        </form>
                    @else
                        <form wire:submit="closeMeeting({{ $meeting->id }})" class="flex flex-wrap items-end gap-2">
                            <label class="block"><span class="{{ $label }}">Toplantı özeti (müşteri de görür)</span><input type="text" wire:model="summary.{{ $meeting->id }}" class="{{ $input }} w-80!" maxlength="2000"></label>
                            <button type="submit" class="btn">Yapıldı</button>
                        </form>
                    @endif
                </div>
            @empty
                <p class="px-4 py-3.5 text-sm text-gray-500">Bekleyen toplantı isteği yok.</p>
            @endforelse
        </div>
    </section>

    <section data-brand-leave-start>
        <div class="section-head"><h2 class="section-title">Ayrılış</h2></div>
        <div class="card">
            <div class="card-row flex-wrap">
                <div class="min-w-0 flex-1 basis-72">
                    @if ($setting->leaving_at)
                        <p class="text-sm font-medium text-gray-900 dark:text-white">Ayrılış {{ $setting->leaving_at->timezone($tz)->translatedFormat('j F Y') }} günü başladı</p>
                        <p class="mt-0.5 text-sm text-gray-700 dark:text-gray-300">Panel yalnız okunur. {{ $debt > 0 ? 'Açık bakiye kapanana kadar dışa aktarma ve dosya indirme kapalı.' : 'Bakiye temiz; müşteri kayıtlarını ve dosyalarını alabilir.' }}</p>
                    @else
                        <p class="text-sm text-gray-900 dark:text-white">Müşteri ayrılmak istediğini sana söyler; panelde iptal seçeneği yoktur. Başlatınca panel yalnız okunur olur, açık bakiye varken müşteri hiçbir şeyi dışa aktaramaz.</p>
                    @endif
                </div>
                @if ($setting->leaving_at)
                    <button type="button" wire:click="stopLeave" class="btn shrink-0">Geri al</button>
                @else
                    <button type="button" wire:click="startLeave" wire:confirm="{{ $brand->name }} için ayrılış başlasın mı? Panel yalnız okunur olur." class="btn shrink-0" data-leave-start>Ayrılışı başlat</button>
                @endif
            </div>
            @if ($setting->leaving_at)
                @php($steps = [
                    [$debt === 0, 'Bakiye', $debt > 0 ? PanelAccount::money($debt).' açık'.($late > 0 ? ', '.$late.' ödeme gecikmiş' : '') : 'Açık bakiye yok'],
                    [$folderUrl !== null || ! $driveOn, 'Dosyaların teslimi', $folderUrl ? 'Drive klasörünü müşteriyle paylaş' : 'Paylaşılacak Drive klasörü yok'],
                    [$leave['release'] === [], 'Erişimlerin devri', $leave['release'] === [] ? 'Bırakılacak erişim yok' : count($leave['release']).' erişim, liste Ayarlar sekmesinde'],
                    [$leave['left'], 'Kapanış', $leave['left'] ? 'Müşteri ayrıldı olarak işaretlendi' : 'Ayarlar sekmesinden "Müşteri ayrıldı" ile kapat'],
                ])
                @foreach ($steps as [$done, $name, $text])
                    <div class="card-row">
                        <span @class(['h-2 w-2 shrink-0 rounded-full', 'bg-success-500' => $done, 'bg-warning-500' => ! $done]) aria-hidden="true"></span>
                        <p class="w-40 shrink-0 text-sm font-medium text-gray-900 dark:text-white">{{ $name }}</p>
                        <p class="min-w-0 flex-1 text-sm text-gray-700 dark:text-gray-300">{{ $text }}</p>
                        @if ($name === 'Bakiye' && $debt > 0)<button type="button" wire:click="$parent.setTab('finans')" class="link-neutral text-sm">Finans</button>@endif
                        @if (in_array($name, ['Erişimlerin devri', 'Kapanış'], true) && ! $done)<button type="button" wire:click="$parent.setTab('ayarlar')" class="link-neutral text-sm">Ayarlar</button>@endif
                    </div>
                @endforeach
            @endif
        </div>
    </section>

@elseif ($view === 'talepler')
    @if ($ticket)
        <section class="max-w-3xl space-y-5" data-brand-ticket>
            <button type="button" wire:click="$set('ticketId', null)" class="link-neutral text-sm">Tüm talepler</button>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="section-title">{{ $ticket->subject }}</h2>
                    <p class="mt-0.5 text-[13px] text-gray-500">{{ PanelTicket::ORIGINS[$ticket->origin ?? 'panel'] ?? 'Panelden' }}, {{ $ticket->user?->name ?? 'Moximu açtı' }}, {{ PanelTicket::CATEGORIES[$ticket->category] ?? '' }}, {{ $ticket->created_at->timezone($tz)->translatedFormat('j F Y, H:i') }}</p>
                </div>
                <x-ta.pill :tone="PanelTicket::tone($ticket->status)">{{ PanelTicket::STATUSES[$ticket->status] ?? '' }}</x-ta.pill>
            </div>
            <ol class="space-y-3" wire:poll.6s.visible>
                @foreach ($messages as $message)
                    <li @class(['rounded-xl border border-line p-4 dark:border-gray-800', 'bg-soft dark:bg-white/[0.03]' => $message->operator_id]) wire:key="bm-{{ $message->id }}">
                        <p class="flex flex-wrap items-baseline gap-x-2 text-[13px] text-gray-500">
                            <span class="font-medium text-gray-900 dark:text-white">{{ $message->operator_id ? ($message->operator?->name ?? 'Moximu') : ($message->user?->name ?? 'Kullanıcı') }}</span>
                            <time class="ml-auto tabular-nums">{{ $message->created_at->timezone($tz)->translatedFormat('j F Y, H:i') }}</time>
                        </p>
                        <div class="rich-text mt-1.5 text-sm leading-6 text-gray-800 dark:text-gray-200">{!! $message->html() !!}</div>
                        @if ($message->attachment_path || $message->documents->isNotEmpty())
                            <div class="mt-2 flex flex-wrap gap-2">
                                @if ($message->attachment_path)
                                    <a href="{{ route('operator.settings.panel-support.file', $message->id) }}" class="inline-flex max-w-full items-center gap-1.5 rounded-lg border border-line bg-white px-2.5 py-1.5 text-sm text-gray-900 hover:bg-soft dark:bg-transparent dark:text-white">{!! $doc !!}<span class="truncate">{{ $message->attachment_name }}</span></a>
                                @endif
                                @foreach ($message->documents as $file)
                                    <a href="{{ route('operator.settings.panel-support.document', $file->id) }}" class="inline-flex max-w-full items-center gap-1.5 rounded-lg border border-line bg-white px-2.5 py-1.5 text-sm text-gray-900 hover:bg-soft dark:bg-transparent dark:text-white">{!! $doc !!}<span class="truncate">{{ $file->name }}</span><span class="shrink-0 text-gray-500">{{ $file->sizeLabel() }}</span></a>
                                @endforeach
                            </div>
                        @endif
                    </li>
                @endforeach
            </ol>
            <form wire:submit="send" class="space-y-2" data-brand-reply>
                <x-panel.editor model="reply" placeholder="Cevabını yaz" label="Cevap">
                    <label class="ml-auto inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-md px-2 text-sm font-medium text-gray-700 hover:bg-soft"><input type="file" wire:model="uploads" multiple class="sr-only">Dosya ekle</label>
                </x-panel.editor>
                @include('panel.partials.uploads')
                @error('reply')<span class="block text-[13px] text-error-600">{{ $message }}</span>@enderror
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="uploads,send">Cevapla</button>
                    @if ($ticket->status !== PanelTicket::SOLVED)
                        <button type="button" wire:click="setStatus('solved')" class="btn">Çözüldü yap</button>
                    @else
                        <button type="button" wire:click="setStatus('open')" class="btn">Yeniden aç</button>
                    @endif
                </div>
            </form>
        </section>
    @else
        <section data-brand-tickets>
            <div class="section-head flex-wrap gap-y-2">
                <h2 class="section-title">Talepler <span class="section-count">{{ $waiting ? $waiting.' cevap bekliyor' : '' }}</span></h2>
                <div class="ml-auto flex flex-wrap gap-1.5">
                    @foreach (['waiting' => 'Cevap bekleyen', 'done' => 'Cevaplanan', 'all' => 'Hepsi'] as $key => $text)
                        <button type="button" wire:click="$set('status', '{{ $key }}')" @class(['seg', 'seg-active' => $status === $key])>{{ $text }}</button>
                    @endforeach
                    <button type="button" wire:click="$toggle('adding')" class="btn-primary" data-ticket-add>Talep aç</button>
                </div>
            </div>
            @if ($adding)
                <form wire:submit="openTicket" class="card mb-4 max-w-3xl space-y-3 p-4" data-ticket-form>
                    <p class="text-sm text-gray-700 dark:text-gray-300">Müşteri telefonda ya da WhatsApp'ta bir şey istediğinde buraya yaz; iş kayda geçer, müşteri de panelinde görür.</p>
                    <div class="grid gap-3 sm:grid-cols-[1fr_180px_180px]">
                        <label class="block"><span class="{{ $label }}">Konu</span><input type="text" wire:model="form.subject" class="{{ $input }}" autocomplete="off">@error('form.subject')<span class="mt-1 block text-[13px] text-error-600">{{ $message }}</span>@enderror</label>
                        <label class="block"><span class="{{ $label }}">Nereden geldi</span><select wire:model="form.origin" class="{{ $input }}"><option value="phone">Telefonda istendi</option><option value="whatsapp">WhatsApp'tan</option></select></label>
                        <label class="block"><span class="{{ $label }}">Tür</span><select wire:model="form.category" class="{{ $input }}">@foreach (PanelTicket::CATEGORIES as $key => $text)<option value="{{ $key }}">{{ $text }}</option>@endforeach</select></label>
                    </div>
                    <x-panel.editor model="form.body" placeholder="Ne istendi?" label="Ne istendi">
                        <label class="ml-auto inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-md px-2 text-sm font-medium text-gray-700 hover:bg-soft"><input type="file" wire:model="uploads" multiple class="sr-only">Dosya ekle</label>
                    </x-panel.editor>
                    @error('form.body')<span class="block text-[13px] text-error-600">{{ $message }}</span>@enderror
                    @include('panel.partials.uploads')
                    <div class="flex gap-2"><button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="uploads,openTicket">Kaydet</button><button type="button" wire:click="$set('adding', false)" class="btn">Vazgeç</button></div>
                </form>
            @endif
            <div class="card" wire:poll.20s.visible>
                @forelse ($tickets as $row)
                    <button type="button" wire:click="$set('ticketId', {{ $row->id }})" class="card-row w-full text-left hover:bg-soft dark:hover:bg-white/[0.03]" wire:key="bt-{{ $row->id }}">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-gray-900 dark:text-white">{{ $row->subject }}</span>
                            <span class="block truncate text-[13px] text-gray-500">{{ PanelTicket::ORIGINS[$row->origin ?? 'panel'] ?? 'Panelden' }}, {{ $row->user?->name ?? 'Moximu açtı' }}, {{ $row->last_message_at?->timezone($tz)->diffForHumans() }}</span>
                        </span>
                        <x-ta.pill :tone="PanelTicket::tone($row->status)">{{ PanelTicket::STATUSES[$row->status] ?? '' }}</x-ta.pill>
                    </button>
                @empty
                    <p class="px-4 py-3.5 text-sm text-gray-500">{{ $status === 'waiting' ? 'Cevap bekleyen talep yok.' : 'Talep yok.' }}</p>
                @endforelse
            </div>
        </section>
    @endif

@elseif ($view === 'rapor')
    <section data-brand-reports>
        <div class="section-head">
            <h2 class="section-title">Aylık rapor</h2>
            <p class="ml-3 text-sm text-gray-500">Onayladığın rapor, "Panelde yayınla" dediğinde müşterinin Pazarlama › Aylık rapor ekranına düşer.</p>
        </div>
        <div class="space-y-4">
            @forelse ($reports as $report)
                @php($hidden = array_map('intval', (array) $report->panel_hidden_lines))
                @php($text = "Merhaba, ".$report->monthLabel()." raporunuz hazır. Panelinizde Pazarlama › Aylık rapor bölümünden okuyabilirsiniz: ".$panelUrl."/pazarlama")
                <div class="card" wire:key="rep-{{ $report->id }}">
                    <div class="card-row flex-wrap">
                        <div class="min-w-0 flex-1 basis-56">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $report->monthLabel() }}</p>
                            <p class="mt-0.5 text-[13px] text-gray-500">
                                @if (! $report->isOnPanel()) Panelde değil
                                @elseif ($report->panel_read_at) Yayında, müşteri {{ $report->panel_read_at->timezone($tz)->translatedFormat('j F, H:i') }} okudu
                                @else Yayında, {{ $report->panel_published_at->timezone($tz)->translatedFormat('j F') }} gününden beri okunmadı
                                @endif
                            </p>
                        </div>
                        @if ($report->isOnPanel())
                            <span x-data="{ copied: false }"><button type="button" class="btn" x-on:click="navigator.clipboard.writeText(@js($text)); copied = true; setTimeout(() => copied = false, 2000)" x-text="copied ? 'Kopyalandı' : 'WhatsApp metnini kopyala'">WhatsApp metnini kopyala</button></span>
                            <button type="button" wire:click="unpublish({{ $report->id }})" class="btn">Yayından kaldır</button>
                        @else
                            <button type="button" wire:click="publish({{ $report->id }})" class="btn-primary" data-report-publish>Panelde yayınla</button>
                        @endif
                    </div>
                    @foreach ((array) $report->report_lines as $i => $line)
                        <label class="card-row cursor-pointer items-start!" wire:key="rep-{{ $report->id }}-{{ $i }}">
                            <input type="checkbox" class="mt-1" @checked(! in_array((int) $i, $hidden, true)) wire:click="toggleLine({{ $report->id }}, {{ (int) $i }})">
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm text-gray-900 dark:text-white">{{ $line['what'] ?? '' }}</span>
                                @if (filled($line['result'] ?? ''))<span class="block text-[13px] text-gray-500">{{ $line['result'] }}</span>@endif
                            </span>
                            <span class="shrink-0 text-[13px] text-gray-500">{{ in_array((int) $i, $hidden, true) ? 'Müşteri görmez' : 'Müşteri görür' }}</span>
                        </label>
                    @endforeach
                </div>
            @empty
                <div class="card"><p class="px-4 py-3.5 text-sm text-gray-500">Bu markada onaylanmış aylık rapor yok. Rapor, ay başındaki aylık oturumda onaylanınca burada çıkar.</p></div>
            @endforelse
        </div>
    </section>

@elseif ($view === 'finans')
    <section data-brand-finance>
        <div class="section-head">
            <h2 class="section-title">Ödeme durumu</h2>
            <a href="{{ route('operator.finance') }}" wire:navigate class="btn ml-auto">Finans</a>
        </div>
        <div class="card">
            <div class="card-row">
                <span @class(['h-2 w-2 shrink-0 rounded-full', 'bg-success-500' => $debt === 0, 'bg-error-500' => $debt > 0]) aria-hidden="true"></span>
                <p class="min-w-0 flex-1 text-sm text-gray-900 dark:text-white">{{ $debt > 0 ? 'Açık bakiye '.PanelAccount::money($debt).($late > 0 ? ', '.$late.' ödeme gecikmiş' : '') : 'Vadesi gelmiş açık ödeme yok' }}</p>
            </div>
            @foreach ($open as $payment)
                <div class="card-row" wire:key="pay-{{ $payment->id }}">
                    <p class="min-w-0 flex-1 text-sm text-gray-700 dark:text-gray-300">{{ $payment->periodLabel() }}, vade {{ $payment->due_on?->translatedFormat('j F Y') }}</p>
                    <p class="shrink-0 text-sm font-medium tabular-nums text-gray-900 dark:text-white">{{ PanelAccount::money($payment->open()) }}</p>
                </div>
            @endforeach
        </div>
    </section>

    @if ($renewals->isNotEmpty())
        <section data-brand-renewals>
            <div class="section-head"><h2 class="section-title">Yenilemeler <span class="section-count">{{ $renewals->count() }}</span></h2></div>
            <div class="card">
                @foreach ($renewals as $row)
                    @php($remind = 'Merhaba, '.($row['agreement']->title ?: 'yıllık hizmetiniz').' '.($row['due']?->translatedFormat('j F Y') ?? 'yakında').' tarihinde yenilenecek. Tutar '.PanelAccount::money($row['agreement']->fee).'. Ödemeyi yaptığınızda dekontu panelinizde Hesap › Ödemeler bölümünden yükleyebilirsiniz.')
                    <div class="card-row" wire:key="rn-{{ $row['agreement']->id }}" x-data="{ copied: false }">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $row['agreement']->title ?: 'Yıllık iş' }}</p>
                            <p class="text-[13px] text-gray-500">{{ $row['due']?->translatedFormat('j F Y') ?? 'Günü belli değil' }}@if ($row['days'] !== null), {{ $row['days'] < 0 ? abs($row['days']).' gün geçti' : $row['days'].' gün sonra' }}@endif, {{ PanelAccount::money($row['agreement']->fee) }}</p>
                        </div>
                        <button type="button" class="btn shrink-0" x-on:click="navigator.clipboard.writeText(@js($remind)); copied = true; setTimeout(() => copied = false, 2000)" x-text="copied ? 'Kopyalandı' : 'Hatırlatma metnini kopyala'">Hatırlatma metnini kopyala</button>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <section data-brand-receipts>
        <div class="section-head"><h2 class="section-title">Dekontlar <span class="section-count">{{ $receipts->count() }}</span></h2></div>
        <div class="card">
            @forelse ($receipts as $receipt)
                <div class="card-row" wire:key="rc-{{ $receipt->id }}">
                    {!! $doc !!}
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm text-gray-900 dark:text-white">{{ FinanceDocument::SOURCES[$receipt->source] ?? 'Dekont' }}, {{ $receipt->created_at->timezone($tz)->translatedFormat('j F Y, H:i') }}</p>
                        <p class="text-[13px] text-gray-500">{{ $receipt->status === 'new' ? 'Bankada görünce Finans\'ta "ödendi" işaretle' : 'Yerine kondu' }}</p>
                    </div>
                    @if ($receipt->status === 'new')<a href="{{ route('operator.finance') }}" wire:navigate class="btn shrink-0">Finans'ta aç</a>@endif
                </div>
            @empty
                <p class="px-4 py-3.5 text-sm text-gray-500">Bu markadan dekont gelmedi.</p>
            @endforelse
        </div>
    </section>

    <section data-brand-invoices>
        <div class="section-head">
            <h2 class="section-title">Faturalar <span class="section-count">{{ $docs->count() }}</span></h2>
            <label class="btn-primary ml-auto cursor-pointer"><input type="file" wire:model="invoices" multiple accept=".pdf,.xml,.jpg,.jpeg,.png,.webp,.zip" class="sr-only">Fatura yükle</label>
        </div>
        @if (! $takesInvoices)<p class="mb-3 text-sm text-gray-600">Bu müşterinin anlaşması faturasız; panelinde Faturalar yerine ödeme geçmişini görür.</p>@endif
        <div wire:loading wire:target="invoices" class="mb-3 text-sm text-gray-600">Dosya yükleniyor…</div>
        @if ($invoices !== [])
            <div class="card mb-4 flex flex-wrap items-center gap-3 p-4">
                <p class="min-w-0 flex-1 text-sm text-gray-900 dark:text-white">{{ count($invoices) }} dosya seçildi. Kaydedilince müşterinin panelinde Hesap › Faturalar'da görünür.</p>
                <button type="button" wire:click="uploadInvoices" class="btn-primary" wire:loading.attr="disabled">Kaydet ve panelde aç</button>
                <button type="button" wire:click="$set('invoices', [])" class="btn">Vazgeç</button>
            </div>
        @endif
        @error('invoices.*')<span class="mb-3 block text-[13px] text-error-600">{{ $message }}</span>@enderror
        <div class="card">
            @forelse ($docs as $row)
                <div class="card-row" wire:key="inv-{{ $row->id }}">
                    {!! $doc !!}
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm text-gray-900 dark:text-white">{{ $row->doc_date?->translatedFormat('j F Y') ?? 'Tarihsiz' }}{{ $row->doc_no ? ', '.$row->doc_no : '' }}</p>
                        <p class="text-[13px] text-gray-500">{{ $row->amountLabel() }}</p>
                    </div>
                    <label class="flex shrink-0 cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" @checked($row->panel_visible) wire:click="toggleInvoice({{ $row->id }})" @disabled(blank($row->path))>
                        {{ $row->panel_visible ? 'Panelde görünüyor' : 'Panelde göster' }}
                    </label>
                </div>
            @empty
                <p class="px-4 py-3.5 text-sm text-gray-500">Bu markaya kesilmiş fatura kaydı yok.</p>
            @endforelse
        </div>
    </section>

@else
    <section data-brand-documents>
        <div class="section-head">
            <h2 class="section-title">Dosyalar <span class="section-count">{{ $documents->count() }}</span></h2>
            <div class="ml-auto flex gap-2">
                @if ($folderUrl)<a href="{{ $folderUrl }}" target="_blank" rel="noopener" class="btn">Drive klasörü</a>@endif
                <label class="btn-primary cursor-pointer"><input type="file" wire:model="uploads" multiple class="sr-only">Dosya gönder</label>
            </div>
        </div>
        @include('panel.partials.uploads')
        @error('uploads')<span class="block text-[13px] text-error-600">{{ $message }}</span>@enderror
        @if ($uploads !== [])
            <div class="my-3 flex flex-wrap items-center gap-3">
                <button type="button" wire:click="shelve" class="btn-primary" wire:loading.attr="disabled" wire:target="uploads,shelve">Markanın rafına koy</button>
                <span class="text-sm text-gray-600">Müşteri bu dosyaları panelinde Destek › Dosyalar'da görür.</span>
            </div>
        @endif
        <div class="card mt-3">
            @forelse ($documents as $file)
                <div class="card-row" wire:key="pd-{{ $file->id }}">
                    {!! $doc !!}
                    <a href="{{ route('operator.settings.panel-support.document', $file->id) }}" class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-gray-900 dark:text-white">{{ $file->name }}</span>
                        <span class="block truncate text-[13px] text-gray-500">{{ $file->operator_id ? ($file->operator?->name ?? 'Moximu') : ($file->user?->name ?? 'Kullanıcı') }}, {{ $file->created_at->timezone($tz)->translatedFormat('j F Y') }}{{ $file->ticket ? ', '.$file->ticket->subject : '' }}</span>
                    </a>
                    <span class="shrink-0 text-[13px] text-gray-500">{{ $file->sizeLabel() }}</span>
                    @if ($file->inDrive())
                        <span class="shrink-0 text-[13px] text-gray-500">Drive'da</span>
                    @elseif ($driveOn)
                        <button type="button" wire:click="retryDrive({{ $file->id }})" class="link-neutral shrink-0 text-[13px]" title="{{ $file->drive_error }}">{{ $file->drive_error ? 'Drive\'a gitmedi, yeniden dene' : 'Drive\'a gönder' }}</button>
                    @else
                        <span class="shrink-0 text-[13px] text-gray-500">Sunucuda</span>
                    @endif
                </div>
            @empty
                <p class="px-4 py-3.5 text-sm text-gray-500">Bu markayla paylaşılmış dosya yok.</p>
            @endforelse
        </div>
    </section>
@endif
</div>
