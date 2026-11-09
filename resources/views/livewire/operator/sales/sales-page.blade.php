@php
    use App\Livewire\Operator\Sales\SalesPage;
    use App\Models\SalesLead;

    $n = fn (int $v): string => number_format($v, 0, ',', '.');
    $input = 'h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white';
    $stageChip = ['new' => 'chip-flat', 'contacted' => 'chip-flat', 'talking' => 'chip-accent', 'meeting' => 'chip-accent', 'priced' => 'chip-accent', 'thinking' => 'chip-accent', 'later' => 'chip-flat', 'won' => 'chip-up', 'lost' => 'chip-down'];
    $today = now('Europe/Istanbul')->toDateString();
@endphp
{{-- Satış (2026-11-04): one place for every prospect. MoxDOP never sends a message; the phone does. --}}
<div class="pb-24" data-sales>
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="page-title">Sa<span class="highlight">tış</span></h1>
            <p class="page-sub">Kiminle ne konuştun, ne oldu, ne bitti: her aday tek kartta, her kartın bir sonraki adımı var. Metinler hazır, gönderen sensin.</p>
        </div>
        <div class="flex items-center gap-2">
            @if ($isAdmin)
                <button type="button" wire:click="scan" wire:loading.attr="disabled" class="btn-quiet" title="Kartı olmayan her WhatsApp görüşmesine kart açar">Konuşmaları tara</button>
            @endif
            <button type="button" wire:click="$toggle('pasting')" class="btn" data-sales-paste-toggle>Sohbet yapıştır</button>
            <button type="button" wire:click="$toggle('adding')" class="btn-primary" data-sales-add>+ Aday ekle</button>
        </div>
    </header>

    @if ($adding)
        <form wire:submit="quickAdd" class="card mt-6 p-4" data-sales-add-form>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <label class="block text-xs text-gray-500">Kişi<input wire:model="form.name" type="text" placeholder="Dr. Ayşe Yılmaz" class="mt-1 {{ $input }}" /></label>
                <label class="block text-xs text-gray-500">İşletme<input wire:model="form.company" type="text" placeholder="Klinik, fabrika, mağaza… adı" class="mt-1 {{ $input }}" /></label>
                <label class="block text-xs text-gray-500">Şehir<input wire:model="form.city" type="text" placeholder="Manisa" class="mt-1 {{ $input }}" /></label>
                <label class="block text-xs text-gray-500">Telefon<input wire:model="form.phone" type="tel" placeholder="0532 000 00 00" class="mt-1 {{ $input }}" /></label>
                <label class="block text-xs text-gray-500">Site<input wire:model="form.website" type="text" placeholder="klinik.com" class="mt-1 {{ $input }}" /></label>
                <label class="block text-xs text-gray-500">Nereden geldi
                    <select wire:model="form.source" class="mt-1 {{ $input }}">
                        @foreach (SalesLead::SOURCES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </select>
                </label>
                <label class="block text-xs text-gray-500 sm:col-span-2 lg:col-span-3">Not<textarea wire:model="form.note" rows="2" placeholder="Nasıl tanıştınız, ne istedi, ne söyledin…" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white"></textarea></label>
            </div>
            @error('form')<p class="mt-2 text-sm text-error-600">{{ $message }}</p>@enderror
            <div class="mt-3 flex items-center gap-2">
                <button type="submit" class="btn-dark" wire:loading.attr="disabled">Kartı aç</button>
                <button type="button" wire:click="$set('adding', false)" class="btn-quiet">Vazgeç</button>
                <span class="text-xs text-gray-500">Site yazarsan bulgular kendiliğinden çıkar; telefon ya da site eşleşirse var olan kart açılır.</span>
            </div>
        </form>
    @endif

    @if ($pasting)
        <form wire:submit="pasteChat" class="card mt-6 space-y-2 p-4" data-sales-paste>
            <p class="text-sm text-gray-700 dark:text-gray-300">WhatsApp'ta sohbetin mesajlarını seç, kopyala, buraya yapıştır. Kart bulunur ya da açılır, Claude son mesaja cevabı hemen yazar.</p>
            <textarea wire:model="paste" rows="7" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm leading-relaxed placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white" placeholder="[22:35, 30.10.2026] Yaşar Özdemir: Fiyata KDV dahil mi?" aria-label="Sohbet"></textarea>
            <input wire:model="pasteNote" type="text" class="{{ $input }}" placeholder="Claude'a not (isteğe bağlı): telefonda konuştuk, fiyatı uygun buldu…" aria-label="Claude'a not" />
            @error('paste')<p class="text-sm text-error-600">{{ $message }}</p>@enderror
            <div class="flex items-center gap-2">
                <button type="submit" class="btn-dark" wire:loading.attr="disabled" wire:target="pasteChat">
                    <span wire:loading.remove wire:target="pasteChat">Karta işle ve cevabı yaz</span>
                    <span wire:loading wire:target="pasteChat">Claude yazıyor… (10-30 sn)</span>
                </button>
                <button type="button" wire:click="$set('pasting', false)" class="btn-quiet">Vazgeç</button>
            </div>
        </form>
    @endif

    {{-- İç menü (yakup 2026-11-08): the first six are filters on the same table; then Rapor and Ayarlar. --}}
    <div class="mt-7 flex flex-wrap items-center gap-1.5" role="tablist" aria-label="Satış menüsü" data-sales-menu>
        @foreach (SalesLead::STATUSES as $key => $label)
            @php $c = (int) ($viewCounts['durum_'.$key] ?? 0); $on = $tab === 'adaylar' && $status === $key && trim($q) === ''; @endphp
            <button type="button" wire:click="showStatus('{{ $key }}')" @class(['seg', 'seg-active' => $on]) role="tab" aria-selected="{{ $on ? 'true' : 'false' }}" data-sales-status="{{ $key }}">
                {{ ['devam' => 'Devam eden', 'guclu' => 'En güçlü adaylar', 'kararsiz' => 'Kararsızlar', 'olumsuz' => 'Olumsuz', 'aday_degil' => 'Aday olmayanlar', 'musteri' => 'Müşteriye dönüşenler'][$key] }}
                @if ($tab === 'adaylar')<span @class(['tabular-nums', 'text-accent' => $on && $c > 0, 'text-gray-400' => ! $on || $c === 0])>{{ $n($c) }}</span>@endif
            </button>
        @endforeach
        <span class="mx-1 hidden h-5 w-px bg-gray-200 sm:block dark:bg-gray-700"></span>
        <button type="button" wire:click="$set('tab', 'sonuclar')" @class(['seg', 'seg-active' => $tab === 'sonuclar']) data-sales-tab="sonuclar">Rapor</button>
        <button type="button" wire:click="$set('tab', 'ayarlar')" @class(['seg', 'seg-active' => $tab === 'ayarlar']) data-sales-tab="ayarlar">Ayarlar</button>
        {{-- Bugün and Müşteri istekleri live in the Gelen kutusu (Aşama 2): one queue for every answer owed and today's steps. --}}
        <a href="{{ route('operator.inbox') }}" wire:navigate class="seg sm:ml-auto" data-sales-inbox-link>Gelen kutusu <span @class(['tabular-nums', 'text-accent' => $inboxOpen > 0, 'text-gray-400' => $inboxOpen === 0])>{{ $n($inboxOpen) }}</span></a>
    </div>

    @if ($message !== '')
        <p class="mt-4 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300" data-sales-message>{{ $message }}</p>
    @endif

    @if ($tab === 'adaylar')
        {{-- Adaylar (yakup 2026-11-07): every row answers four questions: whose turn is it, when did we last talk and
             about what, what is next and when, how much is it worth. Ready views instead of one stage filter. --}}
        <section class="mt-5" data-sales-leads>
            @if (in_array($status, ['devam', 'guclu', 'kararsiz'], true))
                <div class="flex flex-wrap items-center gap-1.5" role="tablist" aria-label="Hızlı süzgeç" data-sales-views>
                    @foreach (SalesPage::VIEWS as $key => $label)
                        @continue(in_array($key, ['musteriler', 'kaybedilen'], true))
                        @php $c = (int) ($viewCounts[$key] ?? 0); $alert = in_array($key, ['borclu', 'adimsiz'], true) && $c > 0; @endphp
                        <button type="button" wire:click="$set('view', '{{ $key }}')" @class(['seg h-8 text-[13px]', 'seg-active' => $view === $key && trim($q) === '']) data-sales-view="{{ $key }}">
                            {{ $key === 'acik' ? 'Hepsi' : $label }} <span @class(['tabular-nums', 'text-error-600' => $alert && $view !== $key, 'text-gray-400' => ! $alert && $view !== $key])>{{ $n($c) }}</span>
                        </button>
                    @endforeach
                </div>
            @elseif ($status === 'musteri')
                <div class="flex flex-wrap items-center gap-1.5" data-sales-views>
                    <button type="button" wire:click="$set('view', 'acik')" @class(['seg h-8 text-[13px]', 'seg-active' => $view !== 'musteriler']) data-sales-view="acik">Dönüşen kayıtlar</button>
                    <button type="button" wire:click="$set('view', 'musteriler')" @class(['seg h-8 text-[13px]', 'seg-active' => $view === 'musteriler']) data-sales-view="musteriler">Ödeme, rapor ve risk tablosu</button>
                </div>
            @endif

            <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center">
                <input wire:model.live.debounce.400ms="q" type="search" placeholder="Ara: isim, işletme, şehir, telefon, not…" class="{{ str_replace('w-full', 'w-full sm:w-80', $input) }}" aria-label="Aday ara" />
                <select wire:model.live="sort" class="{{ str_replace('w-full', 'w-full sm:w-56', $input) }}" aria-label="Sırala">
                    @foreach (SalesPage::SORTS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
                @if ($view === 'teklif' && $offerTotal > 0 && trim($q) === '')
                    <p class="text-sm text-gray-600 sm:ml-auto dark:text-gray-300" data-sales-offer-total>{{ $n((int) ($viewCounts['teklif'] ?? 0)) }} aday · <span class="font-semibold text-gray-900 dark:text-white">{{ $n($offerTotal) }} ₺/ay</span> karar bekliyor</p>
                @endif
            </div>

            @if (($viewCounts['isimsiz'] ?? 0) > 0)
                <p class="mt-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-[13px] text-gray-600 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300" data-sales-unnamed>
                    {{ $n((int) $viewCounts['isimsiz']) }} açık adayın adı yok: WhatsApp yedeğinde isim olmadığı için numara görünüyor.
                    <a href="{{ route('operator.whatsapp', ['tab' => 'setup']) }}" wire:navigate class="link-neutral font-medium">Telefon rehberini (.vcf) bir kez yükle</a>, eşleşen numaralara isimler kendiliğinden yazılır.
                </p>
            @endif
            @if ($view === 'musteriler' && trim($q) === '')
                {{-- Müşteriler (2026-11-07): the commercial lens on the served brands — the agreement, this month's tick,
                     what is late, the open opportunity. Operations stay on Portföy › Markalar; nothing is edited twice. --}}
                @if ($customerStrip !== null)
                    <div class="hero-panel mt-3 grid-cols-2 lg:grid-cols-4" data-sales-customer-strip>
                        <div class="hero-tile hero-tile-accent"><span class="hero-label">Aylık gelir</span><span class="hero-value">{{ $n($customerStrip['mrr']) }} ₺</span><span class="mt-2 text-xs opacity-75">{{ $n(count($customerRows)) }} marka</span></div>
                        <div class="hero-tile"><span class="hero-label">Teklifte</span><span class="hero-value">{{ $n($customerStrip['offers']) }} ₺</span><span class="mt-2 text-xs text-white/60">aylık, karar bekliyor</span></div>
                        <div class="hero-tile"><span class="hero-label">Bu ay kazanılan</span><span class="hero-value">{{ $n($customerStrip['won_month']) }} ₺</span><span class="mt-2 text-xs text-white/60">kazanılan fırsatlar</span></div>
                        <div class="hero-tile" data-sales-overdue-tile><span class="hero-label">Gecikmiş tahsilat</span><span class="hero-value">{{ $customerStrip['overdue'] > 0 ? $n($customerStrip['overdue_sum']).' ₺' : '—' }}</span><span class="mt-2 text-xs text-white/60">{{ $customerStrip['overdue'] > 0 ? $customerStrip['overdue'].' ay gecikmiş' : 'geciken ödeme yok' }}</span></div>
                    </div>
                @endif
                <div class="card mt-3 overflow-visible" data-sales-customers>
                    @php $cols = 'lg:grid-cols-[minmax(0,2fr)_minmax(0,1.5fr)_6.5rem_6.5rem_5.5rem_minmax(0,1.6fr)_minmax(0,1.3fr)_6rem]'; @endphp
                    <div class="hidden grid-cols-[minmax(0,2fr)_minmax(0,1.5fr)_6.5rem_6.5rem_5.5rem_minmax(0,1.6fr)_minmax(0,1.3fr)_6rem] gap-3 border-b border-gray-200 px-4 py-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400 lg:grid dark:border-gray-800">
                        <span>Müşteri</span><span>Anlaşma</span><span>Ödeme</span><span>Rapor</span><span>Temas</span><span>Risk</span><span>Açık fırsat</span><span></span>
                    </div>
                    <div class="divide-y divide-gray-200 dark:divide-gray-800">
                        @forelse ($customerRows as $row)
                            @php $a = $row['agreement']; $pm = $row['this_month']; $setup = $row['setup']; $demand = $row['demand']; @endphp
                            <div wire:key="customer-{{ $row['brand']->id }}" class="grid grid-cols-1 gap-x-3 gap-y-1.5 px-4 py-3 {{ $cols }} lg:items-center lg:py-2.5" data-sales-customer="{{ $row['brand']->id }}">
                                <div class="min-w-0">
                                    <a href="{{ route('operator.brand', ['brand' => $row['brand']->id]) }}" wire:navigate class="truncate text-sm font-semibold text-gray-900 hover:underline dark:text-white">{{ $row['brand']->name }}</a>
                                    <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[12px] text-gray-500">
                                        @if ($row['kind'])<span>{{ $row['kind'] }}</span>@endif
                                        @if ($row['customer'] && $row['customer']->name !== $row['brand']->name)<span class="truncate">{{ $row['customer']->name }}</span>@endif
                                        @if ($setup['total'] > 0 && $setup['done'] < $setup['total'])
                                            <span class="chip chip-flat h-5! px-1.5! text-[11px]!" title="{{ collect($setup['items'])->map(fn ($i) => ($i['done'] ? '✓ ' : '○ ').$i['label'])->implode(' · ') }}" data-sales-customer-setup>Kurulum {{ $setup['done'] }}/{{ $setup['total'] }}</span>
                                        @endif
                                        @if ($row['lead'])<a href="{{ route('operator.sales.lead', ['lead' => $row['lead']->id]) }}" wire:navigate class="text-gray-400 hover:underline">Satış kaydı</a>@endif
                                    </p>
                                </div>
                                <div class="text-[13px]" data-sales-customer-agreement>
                                    @if ($a === null)
                                        <a href="{{ route('operator.brand', ['brand' => $row['brand']->id, 'tab' => 'ayarlar']) }}" wire:navigate class="font-semibold text-error-600 hover:underline">Anlaşma girilmedi</a>
                                    @else
                                        <span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $a->feeLabel() }}</span>
                                        <span class="block text-[11px] text-gray-500">{{ $a->kindLabel() }}{{ $row['month_no'] ? ' · '.$row['month_no'].'. ay' : '' }}{{ $a->kind !== 'one_time' ? ' · gün '.$a->due_day : '' }}{{ ! $a->isActive() ? ' · '.mb_strtolower($a->statusLabel()) : '' }}</span>
                                    @endif
                                </div>
                                <div class="text-[13px]" data-sales-customer-month>
                                    @if ($pm !== null)
                                        <button type="button" wire:click="togglePaid({{ $pm->id }})" @class(['seg h-7 px-2.5 text-[12px]', 'seg-active' => $pm->isPaid(), 'border-error-300 text-error-700' => $pm->isOverdue()]) title="{{ $pm->periodLabel() }}">{{ $pm->isPaid() ? '✓ ödendi' : ($pm->isOverdue() ? '! '.$pm->daysLate().' gün' : '○ vade '.$pm->due_on?->format('d.m')) }}</button>
                                    @else
                                        <span class="text-gray-400">–</span>
                                    @endif
                                </div>
                                <div class="text-[13px]" data-sales-customer-report>
                                    @if ($a !== null && $a->kind !== 'one_time')
                                        <button type="button" wire:click="toggleReport({{ $row['brand']->id }}, '{{ $row['report_month'] }}')" @class(['seg h-7 px-2.5 text-[12px]', 'seg-active' => $row['report'] !== null]) title="{{ \Carbon\CarbonImmutable::parse($row['report_month'].'-01')->locale('tr')->isoFormat('MMMM YYYY') }} raporu{{ $row['report'] ? ' · '.$row['report']->sent_at->timezone('Europe/Istanbul')->format('d.m') : '' }}">{{ $row['report'] !== null ? '✓ '.\Carbon\CarbonImmutable::parse($row['report_month'].'-01')->locale('tr')->isoFormat('MMM') : '○ '.\Carbon\CarbonImmutable::parse($row['report_month'].'-01')->locale('tr')->isoFormat('MMM') }}</button>
                                    @else
                                        <span class="text-gray-400">–</span>
                                    @endif
                                </div>
                                <div class="text-[13px]" data-sales-customer-contact>
                                    @if ($row['contact_days'] !== null)
                                        <span @class(['tabular-nums', 'font-semibold text-error-600' => $row['contact_days'] >= \App\Services\Sales\CustomerSignals::CONTACT_DAYS, 'text-gray-700 dark:text-gray-300' => $row['contact_days'] < \App\Services\Sales\CustomerSignals::CONTACT_DAYS])" title="Son temas {{ $row['last_contact']->timezone('Europe/Istanbul')->format('d.m.Y') }}">{{ $row['contact_days'] === 0 ? 'bugün' : $row['contact_days'].' gün' }}</span>
                                    @else
                                        <span class="text-gray-400" title="Kayıtlı temas yok">–</span>
                                    @endif
                                </div>
                                <div class="flex flex-wrap gap-1 text-[12px]" data-sales-customer-risk>
                                    @foreach ($row['signals'] as $signal)<span class="chip chip-down" title="{{ $signal['detail'] }}">{{ $signal['label'] }}</span>@endforeach
                                    @foreach ($row['risks'] as $risk)@continue($risk['key'] === 'odeme_gecikti' && collect($row['signals'])->contains('key', 'odeme_gecikti'))<span class="chip chip-down">{{ $risk['label'] }}</span>@endforeach
                                    @if ($demand !== null && ! $demand['falling'])<span class="text-gray-400" title="Son üç ayın talebi: {{ collect($demand['months'])->map(fn ($m) => \Carbon\CarbonImmutable::parse($m['month'].'-01')->locale('tr')->isoFormat('MMM').' '.$n((int) $m['total']))->implode(' → ') }}">talep {{ $demand['months'][2]['total'] >= $demand['months'][1]['total'] ? '↑' : '↓' }}</span>@endif
                                    @if ($row['signals'] === [] && $row['risks'] === [] && $demand === null)<span class="text-gray-400">–</span>@endif
                                </div>
                                <div class="min-w-0 text-[13px]">
                                    @if ($row['opportunity'])
                                        <a href="{{ route('operator.sales.lead', ['lead' => $row['lead']->id]) }}" wire:navigate class="block truncate font-medium text-gray-900 hover:underline dark:text-white">{{ $row['opportunity']->title() }}</a>
                                        <span class="text-[11px] text-gray-500">{{ $row['opportunity']->amountLabel() }}</span>
                                    @else
                                        <span class="text-gray-400">–</span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-1 lg:justify-end">
                                    <button type="button" wire:click="opportunityFor({{ $row['brand']->id }})" class="btn h-7 px-2.5 text-[12px]" data-sales-customer-opportunity>Yeni fırsat</button>
                                </div>
                            </div>
                        @empty
                            <x-ta.empty-state title="Hizmet verilen marka yok" message="Bir aday kazanıldığında müşteri ve markası burada görünür; mevcut müşteriler Markalar'dan eklenir." />
                        @endforelse
                    </div>
                </div>
            @else
            @php $grid = 'lg:grid-cols-[minmax(0,2fr)_9.5rem_minmax(0,1.3fr)_8rem_minmax(0,1.7fr)_7rem_minmax(0,1.2fr)_5.5rem]'; @endphp
            <div class="card mt-3 overflow-visible" data-sales-table>
                <div class="hidden gap-3 border-b border-gray-200 px-4 py-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400 lg:grid {{ $grid }} dark:border-gray-800">
                    <span>Müşteri adı</span><span>WhatsApp</span><span>E-posta</span><span>Durum</span><span>Yapılacak</span><span>Kaynak</span><span>Kimden</span><span></span>
                </div>
                <div class="divide-y divide-gray-200 dark:divide-gray-800">
                    @forelse ($list as $lead)
                        @php
                            $due = $lead->next_step_at?->timezone('Europe/Istanbul');
                            $late = $lead->isDue() && $due !== null && $due->toDateString() < $today;
                            $isToday = $due !== null && $due->toDateString() === $today;
                            $lt = $touches[$lead->id] ?? null;
                            $ours = $lead->isOpen() && $lead->awaitsUs();
                            $waitDays = $ours ? (int) $lead->last_incoming_at->copy()->startOfDay()->diffInDays(now()->startOfDay()) : null;
                            $pinned = filled($lead->notes) ? trim(strtok((string) $lead->notes, "\n")) : '';
                            $source = $lead->sourceLabel();
                            $heard = $lead->heard_from !== null ? (SalesLead::HEARD_FROM[$lead->heard_from] ?? null) : null;
                            $wa = $lead->waNumber();
                            $st = $lead->status();
                            $stChip = ['devam' => 'chip-flat', 'guclu' => 'chip-accent', 'kararsiz' => 'chip-flat', 'olumsuz' => 'chip-down', 'aday_degil' => 'chip-flat', 'musteri' => 'chip-up'][$st];
                            $via = $lead->cameViaLabel();
                        @endphp
                        <div wire:key="lead-{{ $lead->id }}" class="grid grid-cols-1 gap-x-3 gap-y-1.5 px-4 py-3 {{ $grid }} lg:items-center lg:py-2.5" data-sales-lead="{{ $lead->id }}">
                            {{-- Müşteri adı: the name (WhatsApp profile, or found by Claude in the messages); empty stays empty. --}}
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('operator.sales.lead', ['lead' => $lead->id]) }}" wire:navigate @class(['truncate text-sm font-semibold hover:underline', 'text-gray-900 dark:text-white' => filled($lead->name) || filled($lead->company), 'text-gray-400' => ! filled($lead->name) && ! filled($lead->company)])>{{ filled($lead->name) ? $lead->name : (filled($lead->company) ? $lead->company : 'İsim yok') }}</a>
                                    @if ($ours)<span class="chip chip-down shrink-0" title="Son mesaj onlardan" data-sales-ball>Sende{{ $waitDays ? ' · '.$waitDays.' g' : '' }}</span>@endif
                                    <span class="chip {{ $stChip }} lg:hidden">{{ $lead->statusLabel() }}</span>
                                </div>
                                @php $sub = collect([filled($lead->name) ? $lead->company : null, $lead->city, $lead->kindLabel()])->filter()->unique()->implode(' · '); @endphp
                                @if ($sub !== '')<p class="truncate text-[13px] text-gray-500">{{ $sub }}</p>@endif
                                @if ($pinned !== '')<p class="truncate text-[12px] italic text-gray-400" title="{{ $lead->notes }}" data-sales-pinned>{{ $pinned }}</p>@endif
                                @if ($lt && $lt['text'] !== '')<p class="truncate text-[12px] text-gray-400" title="{{ $lt['text'] }}" data-sales-touch>{{ $lt['at']->timezone('Europe/Istanbul')->format('d.m') }} · {{ $lt['label'] }}: {{ $lt['text'] }}</p>@endif
                            </div>
                            {{-- WhatsApp --}}
                            <div class="min-w-0 text-[13px] tabular-nums">
                                @if ($wa !== null)
                                    <a href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener" class="text-gray-700 hover:underline dark:text-gray-300" data-sales-wa>{{ $lead->phone }}</a>
                                @elseif (filled($lead->phone))
                                    <span class="text-error-600" title="{{ $lead->phoneIssue() }}" data-sales-phone-issue>{{ $lead->phone }} · hatalı</span>
                                @else
                                    <span class="text-gray-400">–</span>
                                @endif
                            </div>
                            {{-- E-posta --}}
                            <div class="min-w-0 truncate text-[13px]">
                                @if (filled($lead->email))<a href="mailto:{{ $lead->email }}" class="text-gray-700 hover:underline dark:text-gray-300">{{ $lead->email }}</a>@else<span class="text-gray-400">–</span>@endif
                            </div>
                            {{-- Durum (+ Claude's suggestion to mark a strong prospect) --}}
                            <div class="hidden lg:block">
                                <span class="chip {{ $stChip }}" data-sales-status-chip>{{ $lead->statusLabel() }}</span>
                                @if ($st === 'devam' || $st === 'kararsiz')
                                    @if (filled($lead->strong_hint))
                                        <button type="button" wire:click="setStatus({{ $lead->id }}, 'guclu')" class="mt-0.5 block text-left text-[11px] font-medium text-accent hover:underline" title="{{ $lead->strong_hint }}" data-sales-strong-hint>Claude: güçlü aday?</button>
                                    @else
                                        <span class="mt-0.5 block text-[11px] text-gray-400">{{ $lead->stageLabel() }}</span>
                                    @endif
                                @elseif ($st === 'olumsuz')
                                    <span class="mt-0.5 block text-[11px] text-gray-400">{{ SalesLead::LOST_REASONS[$lead->lost_reason] ?? '' }}</span>
                                @endif
                            </div>
                            {{-- Yapılacak --}}
                            <div class="min-w-0 text-[13px]" data-sales-next>
                                @if (! $lead->isOpen())
                                    <span class="text-gray-500">{{ $lead->stage === 'won' ? 'Müşteri oldu '.$lead->won_at?->timezone('Europe/Istanbul')->format('d.m.Y') : 'Kapandı '.$lead->lost_at?->timezone('Europe/Istanbul')->format('d.m.Y') }}</span>
                                @elseif ($due === null)
                                    <button type="button" wire:click="openDialog('todo', {{ $lead->id }})" class="font-semibold text-error-600 hover:underline">Adım yok</button>
                                @else
                                    <button type="button" wire:click="openDialog('todo', {{ $lead->id }})" class="block w-full text-left hover:underline">
                                        <span class="block truncate text-gray-800 dark:text-gray-200">{{ $lead->next_step ?: 'Takip et' }}</span>
                                        <span @class(['text-[12px] tabular-nums', 'font-semibold text-error-600' => $late, 'font-semibold text-gray-900 dark:text-white' => $isToday && ! $late, 'text-gray-500' => ! $late && ! $isToday])>{{ $isToday ? 'Bugün' : ($late ? $due->format('d.m.Y').' · gecikti' : $due->format('d.m.Y')) }}</span>
                                    </button>
                                @endif
                            </div>
                            {{-- Kaynak --}}
                            <div class="min-w-0 text-[12px] text-gray-500">
                                <span class="block truncate">{{ $source }}</span>
                                @if ($heard && $heard !== $source && $lead->heard_from !== 'referral')<span class="block truncate text-gray-400">{{ $heard }}</span>@endif
                            </div>
                            {{-- Kimden --}}
                            <div class="min-w-0 text-[12px]" data-sales-from>
                                <button type="button" wire:click="openDialog('from', {{ $lead->id }})" @class(['block max-w-full truncate text-left hover:underline', 'text-gray-700 dark:text-gray-300' => $via !== '', 'text-gray-400' => $via === ''])>{{ $via !== '' ? $via : 'Seç' }}</button>
                            </div>
                            {{-- ⋯ --}}
                            <div class="flex items-center gap-1 lg:justify-end">
                                <div class="relative" x-data="{ menu: false }" x-on:click.outside="menu = false">
                                    <button type="button" x-on:click="menu = !menu" class="btn h-7 px-2.5 text-[12px]" aria-label="İşlemler" data-sales-menu-open>⋯</button>
                                    <div x-show="menu" x-cloak class="absolute right-0 z-20 mt-1 w-56 rounded-lg border border-gray-200 bg-white py-1 text-[13px] shadow-lg dark:border-gray-700 dark:bg-gray-900">
                                        <p class="px-3 pb-1 pt-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Durum değiştir</p>
                                        @foreach (SalesLead::STATUSES as $key => $label)
                                            @continue($key === $st)
                                            @continue($lead->isCustomer() && in_array($key, ['olumsuz', 'aday_degil'], true))
                                            <button type="button" x-on:click="menu = false" wire:click="setStatus({{ $lead->id }}, '{{ $key }}')" @if ($key === 'musteri') wire:confirm="{{ $lead->title() }} müşteri olarak kaydedilsin mi? Müşteri ve marka kaydı açılır." @endif class="block w-full px-3 py-1.5 text-left hover:bg-gray-50 dark:hover:bg-white/5" data-sales-set-status="{{ $key }}">{{ $label }}</button>
                                        @endforeach
                                        <div class="my-1 border-t border-gray-100 dark:border-gray-800"></div>
                                        <button type="button" x-on:click="menu = false" wire:click="openDialog('todo', {{ $lead->id }})" class="block w-full px-3 py-1.5 text-left hover:bg-gray-50 dark:hover:bg-white/5">Yapılacak yaz</button>
                                        <button type="button" x-on:click="menu = false" wire:click="openDialog('note', {{ $lead->id }})" class="block w-full px-3 py-1.5 text-left hover:bg-gray-50 dark:hover:bg-white/5">Not</button>
                                        <button type="button" x-on:click="menu = false" wire:click="openDialog('plan', {{ $lead->id }})" class="block w-full px-3 py-1.5 text-left hover:bg-gray-50 dark:hover:bg-white/5" data-sales-plan-open>Hatırlatma / toplantı</button>
                                        @if ($lead->isOpen())
                                            <button type="button" x-on:click="menu = false" wire:click="openDialog('touch', {{ $lead->id }})" class="block w-full px-3 py-1.5 text-left hover:bg-gray-50 dark:hover:bg-white/5" data-sales-touch-open>Görüşme işle</button>
                                        @endif
                                        <button type="button" x-on:click="menu = false" wire:click="openDialog('offer', {{ $lead->id }})" class="block w-full px-3 py-1.5 text-left hover:bg-gray-50 dark:hover:bg-white/5" data-sales-offer>Teklif{{ $lead->offer_amount ? ' · '.$n($lead->offer_amount).' ₺' : '' }}</button>
                                        <button type="button" x-on:click="menu = false" wire:click="openDialog('from', {{ $lead->id }})" class="block w-full px-3 py-1.5 text-left hover:bg-gray-50 dark:hover:bg-white/5">Kimden</button>
                                        <div class="my-1 border-t border-gray-100 dark:border-gray-800"></div>
                                        <a href="{{ route('operator.sales.lead', ['lead' => $lead->id]) }}" wire:navigate class="block px-3 py-1.5 hover:bg-gray-50 dark:hover:bg-white/5">Kartı aç</a>
                                        @if ($wa !== null)<a href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener" class="block px-3 py-1.5 hover:bg-gray-50 dark:hover:bg-white/5">WhatsApp'ta aç</a>@endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <x-ta.empty-state :title="trim($q) !== '' ? 'Aramaya uyan kayıt yok' : ($view === 'borclu' ? 'Cevap bekleyen kimse yok' : ($view === 'adimsiz' ? 'Her açık kartın bir sonraki adımı var' : 'Bu listede kayıt yok'))" message="Başka bir durum seç ya da yeni bir aday ekle." />
                    @endforelse
                </div>
            </div>
            @endif
        </section>
    @elseif ($tab === 'ayarlar')
        @include('livewire.operator.sales.partials.settings')
    @else
        <section class="mt-5" data-sales-results>
            @if ($money !== null)
                <div class="hero-panel grid-cols-3">
                    <div class="hero-tile hero-tile-accent"><span class="hero-label">Aylık gelir</span><span class="hero-value">{{ $n((int) $money['mrr']) }} ₺</span><span class="mt-2 text-xs opacity-75">{{ $n($money['customers']) }} aktif müşteri</span></div>
                    <div class="hero-tile"><span class="hero-label">Açık aday</span><span class="hero-value">{{ $n($openCount) }}</span><span class="mt-2 text-xs text-white/60">{{ $n($dueCount) }} bugün</span></div>
                    <div class="hero-tile" data-sales-offer-tile><span class="hero-label">Teklifte bekleyen</span><span class="hero-value">{{ $money['offer_total'] > 0 ? $n($money['offer_total']).' ₺' : $n($money['priced']) }}</span><span class="mt-2 text-xs text-white/60">{{ $money['offer_total'] > 0 ? $n($money['priced']).' aday · aylık, karar bekliyor' : 'aday karar bekliyor' }}</span></div>
                </div>
            @endif

            @if ($report !== null)
                {{-- Ay raporu (4. hafta): what came this month, how far it got, what was won and lost. --}}
                @php
                    $base = max(1, $report['opened']);
                    $hours = $report['reply_hours'];
                    $speed = $hours === null ? '–' : ($hours < 1 ? round($hours * 60).' dk' : ($hours < 48 ? round($hours, 1).' sa' : round($hours / 24, 1).' gün'));
                @endphp
                <div class="section-head mt-8 flex flex-wrap items-end justify-between gap-3" data-sales-month-report>
                    <div>
                        <h2 class="section-title">Ay raporu</h2>
                        <p class="text-sm text-gray-500">O ay açılan kartlar nereye kadar gitti; kazanılan ve kaybedilenler o ay kapananlar.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="$set('month', '{{ $reportMonth->subMonth()->format('Y-m') }}')" class="btn-quiet px-2.5" aria-label="Önceki ay">‹</button>
                        <span class="min-w-[8rem] text-center text-sm font-semibold capitalize text-gray-900 dark:text-white" data-sales-report-month>{{ $reportMonth->locale('tr')->translatedFormat('F Y') }}</span>
                        @if (! $isThisMonth)
                            <button type="button" wire:click="$set('month', '{{ $reportMonth->addMonth()->format('Y-m') }}')" class="btn-quiet px-2.5" aria-label="Sonraki ay">›</button>
                        @endif
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    <div class="card p-3"><p class="text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $n($report['opened']) }}</p><p class="text-xs text-gray-500">yeni kart</p></div>
                    <div class="card p-3"><p class="text-2xl font-semibold tabular-nums text-success-600">{{ $n($report['won']) }}</p><p class="text-xs text-gray-500">kazanıldı{{ $report['revenue'] > 0 ? ' · +'.$n((int) $report['revenue']).' ₺/ay' : '' }}</p></div>
                    <div class="card p-3"><p class="text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $n($report['lost']) }}</p><p class="text-xs text-gray-500">kaybedildi</p></div>
                    <div class="card p-3"><p class="text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $n($report['meetings']) }}</p><p class="text-xs text-gray-500">görüşme{{ $report['meetings'] > 0 ? ' · '.$report['meetings_done'].' notlu' : '' }}</p></div>
                    <div class="card p-3"><p class="text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ $speed }}</p><p class="text-xs text-gray-500">ilk mesaja kadar (ortanca)</p></div>
                </div>
                <div class="mt-3 grid gap-3 lg:grid-cols-[3fr_2fr]">
                    <div class="card p-4" data-sales-funnel>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $n($report['opened']) }} kartın vardığı yer <a href="{{ route('operator.marketing', ['ay' => $reportMonth->format('Y-m')]) }}" wire:navigate class="ml-2 text-xs font-normal link-neutral" data-sales-marketing-link>kaynak kaynak: Pazarlama →</a></h3>
                        <div class="mt-3 space-y-2">
                            @foreach (\App\Services\Sales\SalesMonthReport::FUNNEL as $step => $label)
                                @php $c = $report['funnel'][$step]; $pct = $report['opened'] > 0 ? (int) round($c * 100 / $base) : 0; @endphp
                                <div class="grid grid-cols-[7rem_1fr_4.5rem] items-center gap-3 text-sm">
                                    <span class="text-gray-600 dark:text-gray-300">{{ $label }}</span>
                                    <span class="h-2.5 rounded-full bg-gray-100 dark:bg-white/10"><span class="block h-2.5 rounded-full {{ $step === 'won' ? 'bg-success-500' : 'bg-accent' }}" style="width: {{ max($c > 0 ? 2 : 0, $pct) }}%"></span></span>
                                    <span class="text-right tabular-nums text-gray-900 dark:text-white">{{ $c }} <span class="text-xs text-gray-400">%{{ $pct }}</span></span>
                                </div>
                            @endforeach
                        </div>
                        @if ($report['won_names'] !== [])
                            <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">Kazanılan:
                                @foreach ($report['won_names'] as $w)<a href="{{ route('operator.sales.lead', $w['id']) }}" wire:navigate class="link-neutral">{{ $w['title'] }}</a>{{ $w['fee'] ? ' ('.$n((int) $w['fee']).' ₺)' : '' }}{{ $loop->last ? '' : ', ' }}@endforeach
                            </p>
                        @endif
                    </div>
                    <div class="card p-4 text-sm">
                        <h3 class="font-semibold text-gray-900 dark:text-white">Kanal</h3>
                        <p class="mt-1 text-gray-600 dark:text-gray-300">
                            @forelse ($report['by_source'] as $key => $c){{ SalesLead::SOURCES[$key] ?? $key }} <span class="tabular-nums text-gray-900 dark:text-white">{{ $c }}</span>{{ $loop->last ? '' : ' · ' }}@empty<span class="text-gray-400">Bu ay kart açılmadı.</span>@endforelse
                        </p>
                        @if ($report['by_heard'] !== [])
                            <h3 class="mt-3 font-semibold text-gray-900 dark:text-white">Bizi nereden duydu</h3>
                            <p class="mt-1 text-gray-600 dark:text-gray-300">@foreach ($report['by_heard'] as $key => $c){{ SalesLead::HEARD_FROM[$key] ?? $key }} <span class="tabular-nums text-gray-900 dark:text-white">{{ $c }}</span>{{ $loop->last ? '' : ' · ' }}@endforeach</p>
                        @endif
                        @if ($report['lost_reasons'] !== [])
                            <h3 class="mt-3 font-semibold text-gray-900 dark:text-white">Neden kaybedildi</h3>
                            <p class="mt-1 text-gray-600 dark:text-gray-300">@foreach ($report['lost_reasons'] as $key => $c){{ SalesLead::LOST_REASONS[$key] ?? $key }} <span class="tabular-nums text-gray-900 dark:text-white">{{ $c }}</span>{{ $loop->last ? '' : ' · ' }}@endforeach</p>
                        @endif
                        @if ($report['days_to_win'] !== null)
                            <p class="mt-3 text-gray-500">Kart açılışından kazanmaya ortanca {{ round($report['days_to_win']) }} gün.</p>
                        @endif
                    </div>
                </div>
            @endif
            <div class="section-head mt-8">
                <h2 class="section-title">Nereden geliyor, ne getiriyor</h2>
                <p class="text-sm text-gray-500">Her ay açılan kart ve kazanılan müşteri, kaynağa göre.</p>
            </div>
            <div class="card overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>Ay</th>@foreach (SalesLead::SOURCES as $label)<th class="text-right">{{ $label }}</th>@endforeach<th class="text-right">Toplam</th></tr></thead>
                    <tbody>
                        @forelse ($results as $month => $bySource)
                            @php $opened = array_sum(array_column($bySource, 'opened')); $won = array_sum(array_column($bySource, 'won')); @endphp
                            <tr>
                                <td class="font-medium text-gray-900 dark:text-white">{{ \Carbon\CarbonImmutable::parse($month.'-01')->locale('tr')->isoFormat('MMM YYYY') }}</td>
                                @foreach (SalesLead::SOURCES as $key => $label)
                                    <td class="text-right tabular-nums text-gray-600 dark:text-gray-300">@if (isset($bySource[$key])){{ $bySource[$key]['opened'] }}@if ($bySource[$key]['won'] > 0) <span class="text-success-700">+{{ $bySource[$key]['won'] }}</span>@endif @else<span class="text-gray-300">·</span>@endif</td>
                                @endforeach
                                <td class="text-right font-semibold tabular-nums text-gray-900 dark:text-white">{{ $opened }}@if ($won > 0) <span class="text-success-700">+{{ $won }}</span>@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ count(SalesLead::SOURCES) + 2 }}" class="text-gray-500">Henüz kart yok. İlk kartlar açıldıkça aylık tablo burada dolar.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-gray-500">Sayı = o ay açılan kart; yeşil +N = o kartlardan müşteri olan.</p>

            @if ($patterns !== null)
                {{-- Kazanılan ve kaybedilenler (4. hafta): counts only; no rule is drawn before 30 wins. --}}
                <div class="section-head mt-8" data-sales-patterns>
                    <h2 class="section-title">Kazanılanlar ve kaybedilenler</h2>
                    <p class="text-sm text-gray-500">
                        Şimdiye kadar {{ $n($patterns['wins']) }} kazanılan, {{ $n($patterns['losses']) }} kaybedilen kart.
                        @if (! $patterns['ready'])
                            {{ \App\Services\Sales\SalesMonthReport::RULES_AFTER_WINS }} kazanılan kart birikmeden buradan kural çıkarılmaz; şimdilik yalnızca sayılar. Oran, en az {{ \App\Services\Sales\SalesMonthReport::MIN_GROUP }} kartlık gruplarda gösterilir.
                        @else
                            Oran, en az {{ \App\Services\Sales\SalesMonthReport::MIN_GROUP }} kartlık gruplarda gösterilir.
                        @endif
                    </p>
                </div>
                @if ($patterns['wins'] + $patterns['losses'] === 0)
                    <div class="card p-4 text-sm text-gray-500">Henüz kazanılan ya da kaybedilen kart yok. Kartlar kapandıkça burada hangi kanalın, hangi branşın kazandırdığı görünür.</div>
                @else
                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach ($patterns['groups'] as $title => $rows)
                            <div class="card overflow-x-auto">
                                <table class="data-table">
                                    <thead><tr><th>{{ $title }}</th><th class="text-right">Kazanıldı</th><th class="text-right">Kaybedildi</th><th class="text-right">Oran</th></tr></thead>
                                    <tbody>
                                        @foreach ($rows as $row)
                                            <tr><td class="text-gray-800 dark:text-gray-200">{{ $row['label'] }}</td><td class="text-right tabular-nums text-success-700">{{ $row['won'] }}</td><td class="text-right tabular-nums text-gray-600 dark:text-gray-300">{{ $row['lost'] }}</td><td class="text-right tabular-nums text-gray-500">{{ $row['rate'] !== null ? '%'.$row['rate'] : '·' }}</td></tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endforeach
                    </div>
                    @if ($patterns['lost_at'] !== [] || $patterns['lost_reasons'] !== [])
                        <div class="card mt-3 p-4 text-sm text-gray-600 dark:text-gray-300">
                            @if ($patterns['lost_at'] !== [])<p><span class="font-semibold text-gray-900 dark:text-white">Nerede kaybedildi:</span> @foreach ($patterns['lost_at'] as $label => $c){{ $label }} <span class="tabular-nums text-gray-900 dark:text-white">{{ $c }}</span>{{ $loop->last ? '' : ' · ' }}@endforeach</p>@endif
                            @if ($patterns['lost_reasons'] !== [])<p class="mt-1"><span class="font-semibold text-gray-900 dark:text-white">Neden:</span> @foreach ($patterns['lost_reasons'] as $label => $c){{ $label }} <span class="tabular-nums text-gray-900 dark:text-white">{{ $c }}</span>{{ $loop->last ? '' : ' · ' }}@endforeach</p>@endif
                        </div>
                    @endif
                @endif
            @endif

            @include('livewire.operator.sales.partials.karne')

            @include('livewire.operator.sales.partials.rewards')

            @if ($isAdmin)
                <div class="card mt-8 p-4" data-sales-form-link>
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">Site formu</h2>
                    <p class="mt-1 text-sm text-gray-500">
                        @if ($formSiteId !== null)
                            Bağlı: <span class="font-medium text-gray-800 dark:text-gray-200">{{ $formSites[$formSiteId] ?? 'site #'.$formSiteId }}</span>.
                            {{ $lastForm ? 'Son form '.\Carbon\CarbonImmutable::parse($lastForm)->timezone('Europe/Istanbul')->format('d.m.Y H:i').'.' : 'Henüz form gelmedi.' }}
                            Gündüz gelen form hemen telefona bildirilir; 22:00-08:00 arası gelenler 08:30'da tek bildirimle.
                        @else
                            Sitedeki analiz formu dolunca kişi buraya kart olarak düşsün, telefona hemen bildirim gelsin. Siteyi seç, bağla; anahtar siteye kendiliğinden yazılır.
                        @endif
                    </p>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <select wire:model="formSite" class="{{ $input }} max-w-sm" aria-label="Formu gelecek site">
                            <option value="">Site seç</option>
                            @foreach ($formSites as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                        </select>
                        <button type="button" wire:click="connectForm" wire:loading.attr="disabled" class="btn">{{ $formSiteId !== null ? 'Yeniden bağla' : 'Bağla' }}</button>
                    </div>
                    @error('formSite')<p class="mt-2 text-sm text-error-600">{{ $message }}</p>@enderror
                </div>
            @endif
        </section>
    @endif

    {{-- Row dialogs: Temas kaydet, Not, Teklif, Kapat. One at a time, for one card. --}}
    @if ($dialog !== '' && $dialogCard !== null)
        @php $area = 'w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white'; @endphp
        <div class="fixed inset-0 z-[99999] flex items-end justify-center p-3 sm:items-center sm:p-5" x-data x-on:keydown.escape.window="$wire.closeDialog()" data-sales-dialog="{{ $dialog }}">
            <div class="fixed inset-0 bg-gray-900/50 backdrop-blur-[2px]" wire:click="closeDialog"></div>
            <div class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-900">
                <button type="button" wire:click="closeDialog" class="absolute right-3 top-3 flex h-8 w-8 items-center justify-center rounded-full text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800" aria-label="Kapat">✕</button>
                <p class="text-xs text-gray-500">{{ $dialogCard->title() }}@if ($dialogCard->subtitle() !== '') · {{ $dialogCard->subtitle() }}@endif</p>

                @if ($dialog === 'touch')
                    <form wire:submit="saveTouch" class="mt-1 space-y-3">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Temas kaydet</h3>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach (\App\Services\Sales\SalesLeads::TOUCH_CHANNELS as $key => $label)
                                <button type="button" wire:click="$set('touch.channel', '{{ $key }}')" @class(['seg h-8 text-[13px]', 'seg-active' => $touch['channel'] === $key])>{{ $label }}</button>
                            @endforeach
                        </div>
                        <label class="block text-xs text-gray-500">Ne konuştunuz, ne oldu?
                            <textarea wire:model="touch.body" rows="3" class="mt-1 {{ $area }}" placeholder="Fiyatı sordu, ortağıyla konuşup pazartesi dönecek." data-sales-touch-body></textarea>
                        </label>
                        @error('touch.body')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
                        @if ($touch['close'] === '')
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_10rem]">
                                <label class="block text-xs text-gray-500">Sonraki adım<input wire:model="touch.next" type="text" class="mt-1 {{ $input }}" placeholder="Teklifi hatırlat" /></label>
                                <label class="block text-xs text-gray-500">Ne zaman<input wire:model="touch.date" type="date" class="mt-1 {{ $input }}" /></label>
                            </div>
                            @error('touch.date')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
                            <button type="button" wire:click="$set('touch.close', 'other')" class="link-neutral text-[13px] text-gray-500">Adım yok, kartı kapat</button>
                        @else
                            <label class="block text-xs text-gray-500">Neden kapanıyor
                                <select wire:model="touch.close" class="mt-1 {{ $input }}">
                                    @foreach (SalesLead::LOST_REASONS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                                </select>
                            </label>
                            @error('touch.close')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
                            <button type="button" wire:click="$set('touch.close', '')" class="link-neutral text-[13px] text-gray-500">Kapatma, sonraki adımı yaz</button>
                        @endif
                        <div class="flex items-center gap-2 pt-1">
                            <button type="submit" class="btn-dark" wire:loading.attr="disabled" data-sales-touch-save>Kaydet</button>
                            <button type="button" wire:click="closeDialog" class="btn-quiet">Vazgeç</button>
                        </div>
                        <p class="text-xs text-gray-500">WhatsApp mesajları yedekten kendiliğinden gelir; burası telefon ve yüz yüze görüşmeler için.</p>
                    </form>
                @elseif ($dialog === 'note')
                    <form wire:submit="saveNote" class="mt-1 space-y-3">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Not</h3>
                        <textarea wire:model="noteText" rows="5" class="{{ $area }}" placeholder="Ajanslardan yanmış, bir firmayla daha karşılaştırıyor." data-sales-note-text></textarea>
                        <p class="text-xs text-gray-500">İlk satır listede ismin altında görünür. Claude mesaj yazarken bu notu da okur.</p>
                        <div class="flex items-center gap-2">
                            <button type="submit" class="btn-dark">Kaydet</button>
                            <button type="button" wire:click="closeDialog" class="btn-quiet">Vazgeç</button>
                        </div>
                    </form>
                @elseif ($dialog === 'offer')
                    <form wire:submit="saveOffer" class="mt-1 space-y-3">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Teklif</h3>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <label class="block text-xs text-gray-500">Aylık tutar (₺, KDV hariç)<input wire:model="offerAmount" type="text" inputmode="numeric" class="mt-1 {{ $input }}" placeholder="14000" data-sales-offer-amount /></label>
                            <label class="block text-xs text-gray-500">Hizmet
                                <select wire:model="offerService" class="mt-1 {{ $input }}">
                                    <option value="">Seç</option>
                                    @foreach (SalesLead::SERVICES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                                </select>
                            </label>
                        </div>
                        <p class="text-xs text-gray-500">Gönderdiğin mesajda fiyat geçince tutar buraya kendiliğinden öneri olarak gelir. Silmek için tutarı boş bırak.</p>
                        <div class="flex items-center gap-2">
                            <button type="submit" class="btn-dark">Kaydet</button>
                            <button type="button" wire:click="closeDialog" class="btn-quiet">Vazgeç</button>
                        </div>
                    </form>
                @elseif ($dialog === 'close')
                    <form wire:submit="saveClose" class="mt-1 space-y-3">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Kartı kapat</h3>
                        <div class="flex flex-wrap gap-1.5" data-sales-close-reasons>
                            @foreach (SalesLead::LOST_REASONS as $key => $label)
                                <button type="button" wire:click="$set('closeReason', '{{ $key }}')" @class(['seg h-8 text-[13px]', 'seg-active' => $closeReason === $key])>{{ $label }}</button>
                            @endforeach
                        </div>
                        @error('closeReason')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
                        <input wire:model="closeNote" type="text" class="{{ $input }}" placeholder="Not (isteğe bağlı): başka ajansla devam etti…" />
                        <p class="text-xs text-gray-500">Sonradan yazarsa kart kendiliğinden yeniden açılır.</p>
                        <div class="flex items-center gap-2">
                            <button type="submit" class="btn-dark" data-sales-close-save>Kapat</button>
                            <button type="button" wire:click="closeDialog" class="btn-quiet">Vazgeç</button>
                        </div>
                    </form>
                @elseif ($dialog === 'todo')
                    <form wire:submit="saveTodo" class="mt-1 space-y-3" data-sales-todo-form>
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Yapılacak</h3>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_10rem]">
                            <label class="block text-xs text-gray-500">Ne yapılacak<input wire:model="todo.todo" type="text" class="mt-1 {{ $input }}" placeholder="03.11'de görüştük, karar bekleniyor" /></label>
                            <label class="block text-xs text-gray-500">Ne zaman<input wire:model="todo.date" type="date" class="mt-1 {{ $input }}" /></label>
                        </div>
                        @error('todo.todo')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
                        @error('todo.date')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
                        <p class="text-xs text-gray-500">O gün Gelen kutusu ve Takvim'de görünür. Toplantıysa ⋯ › Hatırlatma / toplantı ile saatini de gir.</p>
                        <div class="flex items-center gap-2">
                            <button type="submit" class="btn-dark">Kaydet</button>
                            <button type="button" wire:click="closeDialog" class="btn-quiet">Vazgeç</button>
                        </div>
                    </form>
                @elseif ($dialog === 'plan')
                    <form wire:submit="savePlan" class="mt-1 space-y-3" data-sales-plan-form>
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Hatırlatma / toplantı</h3>
                        <div class="flex gap-1.5">
                            <button type="button" wire:click="$set('plan.kind', 'meeting')" @class(['seg h-8 text-[13px]', 'seg-active' => $plan['kind'] === 'meeting'])>Toplantı</button>
                            <button type="button" wire:click="$set('plan.kind', 'task')" @class(['seg h-8 text-[13px]', 'seg-active' => $plan['kind'] === 'task'])>Hatırlatma</button>
                        </div>
                        <label class="block text-xs text-gray-500">Başlık<input wire:model="plan.title" type="text" class="mt-1 {{ $input }}" placeholder="{{ $plan['kind'] === 'meeting' ? 'Online görüşme' : 'Teklifi sor' }}" /></label>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="block text-xs text-gray-500">Gün<input wire:model="plan.date" type="date" class="mt-1 {{ $input }}" /></label>
                            <label class="block text-xs text-gray-500">Saat<input wire:model="plan.time" type="time" class="mt-1 {{ $input }}" /></label>
                        </div>
                        @error('plan.date')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
                        <p class="text-xs text-gray-500">Takvim'e eklenir, o sabah telefona bildirim gelir; kaydın Yapılacak satırı da bu olur.</p>
                        <div class="flex items-center gap-2">
                            <button type="submit" class="btn-dark">Ekle</button>
                            <button type="button" wire:click="closeDialog" class="btn-quiet">Vazgeç</button>
                        </div>
                    </form>
                @elseif ($dialog === 'from')
                    <form wire:submit="saveFrom" class="mt-1 space-y-3" data-sales-from-form>
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Kimden geldi</h3>
                        <div class="flex gap-1.5">
                            @foreach (SalesLead::CAME_VIA as $key => $label)
                                <button type="button" wire:click="$set('from.via', '{{ $key }}')" @class(['seg h-8 text-[13px]', 'seg-active' => $from['via'] === $key]) data-sales-from-via="{{ $key }}">{{ $label }}</button>
                            @endforeach
                        </div>
                        @if ($from['via'] === 'referral')
                            <label class="block text-xs text-gray-500">Referans
                                <select wire:model="from.who" class="mt-1 {{ $input }}" data-sales-from-who>
                                    <option value="">Seç</option>
                                    @if ($referrerCustomers->isNotEmpty())
                                        <optgroup label="Mevcut müşteriler (müşteriyse 1 ay hediye)">
                                            @foreach ($referrerCustomers as $c)@continue($c->id === $dialogCard->customer_id)<option value="c-{{ $c->id }}">{{ $c->name }}</option>@endforeach
                                        </optgroup>
                                    @endif
                                    @if ($referrers->isNotEmpty())
                                        <optgroup label="Referanslar listesi (ilk ödemenin %10'u)">
                                            @foreach ($referrers as $r)<option value="r-{{ $r->id }}">{{ $r->name }}</option>@endforeach
                                        </optgroup>
                                    @endif
                                </select>
                            </label>
                            <label class="block text-xs text-gray-500">Listede yoksa yeni referans<input wire:model="newReferrer" type="text" class="mt-1 {{ $input }}" placeholder="Ad soyad" /></label>
                            @error('from.who')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
                            <p class="text-xs text-gray-500">Aday müşteriye dönüşür ve ikinci aylık ödemesini yaparsa ödül hak edilir: referans müşterimizse 1 ay hizmeti hediye, değilse yeni müşterinin ilk ödemesinin %10'u. Hepsi Satış › Rapor › Referans ödüllerinde.</p>
                        @endif
                        @error('from.via')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
                        <div class="flex items-center gap-2">
                            <button type="submit" class="btn-dark" data-sales-from-save>Kaydet</button>
                            <button type="button" wire:click="closeDialog" class="btn-quiet">Vazgeç</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    @endif
</div>
