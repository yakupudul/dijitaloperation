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

    <div class="mt-7 flex flex-wrap items-center gap-1.5" role="tablist" aria-label="Satış sekmeleri">
        @foreach (SalesPage::TABS as $key => $label)
            @php $count = match ($key) { 'bugun' => $dueCount, 'adaylar' => $openCount, 'istekler' => $requests->count(), default => null }; @endphp
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['seg', 'seg-active' => $tab === $key]) role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" data-sales-tab="{{ $key }}">
                {{ $label }}@if ($count !== null && ($count > 0 || $key !== 'istekler')) <span @class(['tabular-nums', 'text-accent' => $tab === $key && $count > 0, 'text-gray-400' => $tab !== $key || $count === 0])>{{ $n($count) }}</span>@endif
            </button>
        @endforeach
    </div>

    @if ($message !== '')
        <p class="mt-4 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300" data-sales-message>{{ $message }}</p>
    @endif

    @if ($tab === 'bugun')
        <section class="mt-5" data-sales-today>
            <div class="section-head">
                <h2 class="section-title">Bugün yazacakların <span class="section-count">{{ $n($dueCount) }}</span></h2>
                <p class="text-sm text-gray-500">İsme bas, metni gör. WhatsApp'ta aç, gönder, "Gönderdim" de.</p>
            </div>
            {{-- Minimal (yakup 2026-11-06): one line per person; the text opens on click. One poll for the whole list
                 while Claude writes, every 10 s and only while the tab is visible (one poll per card slowed the page). --}}
            <div class="card divide-y divide-gray-200 dark:divide-gray-800" @if ($due->contains(fn ($l) => in_array($l->draft_status, ['queued', 'running'], true) && blank($l->draft))) wire:poll.10s.visible @endif>
                @forelse ($due as $lead)
                    @php
                        $text = (string) $lead->draft;
                        $drafting = in_array($lead->draft_status, ['queued', 'running'], true);
                        $wa = $lead->waNumber();
                        $overdue = $lead->next_step_at !== null && $lead->next_step_at->timezone('Europe/Istanbul')->toDateString() < $today;
                        $preview = trim((string) preg_replace('/\s+/u', ' ', $text));
                    @endphp
                    <div wire:key="due-{{ $lead->id }}" x-data="{ open: false }" class="px-4 py-2.5" data-sales-due="{{ $lead->id }}">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
                            <button type="button" x-on:click="open = !open" class="min-w-0 w-full flex-1 text-left" :aria-expanded="open">
                                <span class="flex items-baseline gap-2">
                                    <span class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $lead->title() }}</span>
                                    <span class="shrink-0 text-xs text-gray-500">{{ $lead->next_step ?: 'Adım' }}@if ($overdue)<span class="text-error-600"> · {{ $lead->next_step_at->timezone('Europe/Istanbul')->format('d.m') }}'den kaldı</span>@endif</span>
                                </span>
                                <span class="mt-0.5 block truncate text-[13px] text-gray-500" x-show="!open">
                                    @if ($preview !== ''){{ $preview }}@elseif ($drafting)Claude yazıyor…@elseif ($lead->draft_status === 'waiting'){{ $lead->draft_note ?: 'Abonelikteki Claude yazacak.' }}@elseif ($lead->draft_status === 'failed'){{ $lead->draft_note ?: 'Yazılamadı.' }}@else Metin yok @endif
                                </span>
                            </button>
                            <div class="flex shrink-0 items-center gap-1.5">
                                @if ($text !== '' && $wa !== null)
                                    <a href="https://wa.me/{{ $wa }}?text={{ rawurlencode($text) }}" target="_blank" rel="noopener" class="btn-primary h-8 px-3 text-[13px]" data-sales-wa>WhatsApp</a>
                                @elseif ($text === '' && ! $drafting)
                                    <button type="button" wire:click="draftFor({{ $lead->id }})" class="btn h-8 px-3 text-[13px]" data-sales-write>Claude yazsın</button>
                                @endif
                                <button type="button" wire:click="markSent({{ $lead->id }})" wire:loading.attr="disabled" class="btn-dark h-8 px-3 text-[13px]" data-sales-sent>Gönderdim</button>
                            </div>
                        </div>
                        <div x-show="open" x-cloak class="mt-2.5">
                            @if ($text !== '')
                                <pre class="max-w-[80ch] whitespace-pre-wrap rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-3 font-sans text-[13px] leading-relaxed text-gray-800 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-200" data-sales-draft>{{ $text }}</pre>
                                @if (filled($lead->draft_note))<p class="mt-1.5 max-w-[80ch] text-xs text-gray-500">Claude'un notu: {{ $lead->draft_note }}</p>@endif
                            @endif
                            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px]">
                                @if ($text !== '')<button type="button" x-on:click="navigator.clipboard?.writeText($el.closest('[data-sales-due]').querySelector('[data-sales-draft]').innerText); $el.textContent = 'Kopyalandı'; setTimeout(() => $el.textContent = 'Kopyala', 1500)" class="link-neutral">Kopyala</button>@endif
                                @if ($text !== '' && $wa === null)<a href="{{ route('operator.sales.lead', ['lead' => $lead->id]) }}" wire:navigate class="link-neutral">Telefon ekle</a>@endif
                                <button type="button" wire:click="snooze({{ $lead->id }})" class="link-neutral">Yarına</button>
                                <button type="button" wire:click="openDialog('close', {{ $lead->id }})" class="link-neutral text-gray-400">Kapat</button>
                                <a href="{{ route('operator.sales.lead', ['lead' => $lead->id]) }}" wire:navigate class="link-neutral ml-auto">Kart →</a>
                            </div>
                        </div>
                    </div>
                @empty
                    <x-ta.empty-state title="Bugün yazılacak kimse yok" message="Yeni bir aday ekle, bir site linkini ya da WhatsApp sohbetini telefondan MoxDOP'a paylaş, ya da Adaylar'dan birine sonraki adım ver." />
                @endforelse
            </div>
            @if ($wonThisMonth > 0)
                <p class="mt-3 text-sm text-gray-500">Bu ay {{ $n($wonThisMonth) }} aday müşteri oldu.</p>
            @endif
        </section>
    @elseif ($tab === 'adaylar')
        {{-- Adaylar (yakup 2026-11-07): every row answers four questions: whose turn is it, when did we last talk and
             about what, what is next and when, how much is it worth. Ready views instead of one stage filter. --}}
        <section class="mt-5" data-sales-leads>
            <div class="flex flex-wrap items-center gap-1.5" role="tablist" aria-label="Görünüm" data-sales-views>
                @foreach (SalesPage::VIEWS as $key => $label)
                    @php $c = (int) ($viewCounts[$key] ?? 0); $alert = in_array($key, ['borclu', 'adimsiz'], true) && $c > 0; @endphp
                    <button type="button" wire:click="$set('view', '{{ $key }}')" @class(['seg h-8 text-[13px]', 'seg-active' => $view === $key && trim($q) === '']) data-sales-view="{{ $key }}">
                        {{ $label }} <span @class(['tabular-nums', 'text-error-600' => $alert && $view !== $key, 'text-gray-400' => ! $alert && $view !== $key])>{{ $n($c) }}</span>
                    </button>
                @endforeach
            </div>

            @php $openStages = array_merge(SalesLead::FLOW, ['later']); @endphp
            <div class="mt-3 flex flex-wrap items-center gap-x-1 gap-y-1 text-[13px] text-gray-500" data-sales-stage-strip>
                @foreach ($openStages as $stage)
                    <button type="button" wire:click="$set('stageFilter', '{{ $stageFilter === $stage ? '' : $stage }}')" @class(['rounded-md px-2 py-0.5 hover:bg-gray-100 dark:hover:bg-white/5', 'bg-gray-900 text-white hover:bg-gray-900 dark:bg-white dark:text-gray-900' => $stageFilter === $stage])>
                        {{ SalesLead::STAGES[$stage] }} <span class="tabular-nums font-semibold">{{ $n((int) ($stageCounts[$stage] ?? 0)) }}</span>
                    </button>
                    @if (! $loop->last)<span class="text-gray-300 dark:text-gray-700">·</span>@endif
                @endforeach
            </div>

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
                    <div class="hidden grid-cols-[minmax(0,2fr)_7rem_minmax(0,1.6fr)_6rem_minmax(0,1.4fr)_minmax(0,1.4fr)_7rem] gap-3 border-b border-gray-200 px-4 py-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400 lg:grid dark:border-gray-800">
                        <span>Müşteri</span><span>Tür</span><span>Anlaşma</span><span>Bu ay</span><span>Risk</span><span>Açık fırsat</span><span></span>
                    </div>
                    <div class="divide-y divide-gray-200 dark:divide-gray-800">
                        @forelse ($customerRows as $row)
                            @php $a = $row['agreement']; $pm = $row['this_month']; @endphp
                            <div wire:key="customer-{{ $row['brand']->id }}" class="grid grid-cols-1 gap-x-3 gap-y-1.5 px-4 py-3 lg:grid-cols-[minmax(0,2fr)_7rem_minmax(0,1.6fr)_6rem_minmax(0,1.4fr)_minmax(0,1.4fr)_7rem] lg:items-center lg:py-2.5" data-sales-customer="{{ $row['brand']->id }}">
                                <div class="min-w-0">
                                    <a href="{{ route('operator.brand', ['brand' => $row['brand']->id]) }}" wire:navigate class="truncate text-sm font-semibold text-gray-900 hover:underline dark:text-white">{{ $row['brand']->name }}</a>
                                    @if ($row['customer'] && $row['customer']->name !== $row['brand']->name)<p class="truncate text-[13px] text-gray-500">{{ $row['customer']->name }}</p>@endif
                                    @if ($row['lead'])<a href="{{ route('operator.sales.lead', ['lead' => $row['lead']->id]) }}" wire:navigate class="text-[12px] text-gray-400 hover:underline">Satış kaydı</a>@endif
                                </div>
                                <div class="text-[13px] text-gray-600 dark:text-gray-300"><span class="lg:hidden">Tür: </span>{{ $row['kind'] ?? '–' }}</div>
                                <div class="text-[13px]" data-sales-customer-agreement>
                                    @if ($a === null)
                                        <a href="{{ route('operator.brand', ['brand' => $row['brand']->id, 'tab' => 'ayarlar']) }}" wire:navigate class="font-semibold text-error-600 hover:underline">Anlaşma girilmedi</a>
                                    @else
                                        <span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $a->feeLabel() }}</span>
                                        <span class="block text-[11px] text-gray-500">{{ $a->kindLabel() }}{{ $a->kind !== 'one_time' ? ' · gün '.$a->due_day : '' }}{{ ! $a->isActive() ? ' · '.mb_strtolower($a->statusLabel()) : '' }}</span>
                                    @endif
                                </div>
                                <div class="text-[13px]" data-sales-customer-month>
                                    @if ($pm !== null)
                                        <button type="button" wire:click="togglePaid({{ $pm->id }})" @class(['seg h-7 px-2.5 text-[12px]', 'seg-active' => $pm->isPaid(), 'border-error-300 text-error-700' => $pm->isOverdue()]) title="{{ $pm->periodLabel() }}">{{ $pm->isPaid() ? '✓ ödendi' : ($pm->isOverdue() ? '! '.$pm->daysLate().' gün' : '○ vade '.$pm->due_on?->format('d.m')) }}</button>
                                    @else
                                        <span class="text-gray-400">–</span>
                                    @endif
                                </div>
                                <div class="flex flex-wrap gap-1 text-[12px]" data-sales-customer-risk>
                                    @forelse ($row['risks'] as $risk)<span class="chip chip-down">{{ $risk['label'] }}</span>@empty<span class="text-gray-400">–</span>@endforelse
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
            <div class="card mt-3 overflow-visible">
                <div class="hidden grid-cols-[minmax(0,2.2fr)_8.5rem_6.5rem_minmax(0,1.8fr)_minmax(0,1.4fr)_8rem_7.5rem_6.5rem] gap-3 border-b border-gray-200 px-4 py-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400 lg:grid dark:border-gray-800">
                    <span>Aday</span><span>Durum</span><span>Top kimde</span><span>Son temas</span><span>Sonraki adım</span><span>Teklif</span><span>Kaynak</span><span></span>
                </div>
                <div class="divide-y divide-gray-200 dark:divide-gray-800">
                    @forelse ($list as $lead)
                        @php
                            $due = $lead->next_step_at?->timezone('Europe/Istanbul');
                            $late = $lead->isDue() && $due !== null && $due->toDateString() < $today;
                            $isToday = $due !== null && $due->toDateString() === $today;
                            $lt = $touches[$lead->id] ?? null;
                            $touchAt = $lt['at'] ?? $lead->lastTouchAt();
                            $ours = $lead->isOpen() && $lead->awaitsUs();
                            $waitDays = $ours ? (int) $lead->last_incoming_at->copy()->startOfDay()->diffInDays(now()->startOfDay()) : ($lead->last_contact_at !== null ? (int) $lead->last_contact_at->copy()->startOfDay()->diffInDays(now()->startOfDay()) : null);
                            $pinned = filled($lead->notes) ? trim(strtok((string) $lead->notes, "\n")) : '';
                            $source = $lead->sourceLabel();
                            $heard = $lead->heard_from !== null ? (SalesLead::HEARD_FROM[$lead->heard_from] ?? null) : null;
                            $wa = $lead->waNumber();
                            $days = $lead->stageDays();
                        @endphp
                        <div wire:key="lead-{{ $lead->id }}" class="grid grid-cols-1 gap-x-3 gap-y-1.5 px-4 py-3 lg:grid-cols-[minmax(0,2.2fr)_8.5rem_6.5rem_minmax(0,1.8fr)_minmax(0,1.4fr)_8rem_7.5rem_6.5rem] lg:items-center lg:py-2.5" data-sales-lead="{{ $lead->id }}">
                            {{-- Aday --}}
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('operator.sales.lead', ['lead' => $lead->id]) }}" wire:navigate class="truncate text-sm font-semibold text-gray-900 hover:underline dark:text-white">{{ $lead->title() }}</a>
                                    {{-- Phone: the stage and whose turn sit next to the name. --}}
                                    <span class="chip {{ $stageChip[$lead->stage] ?? 'chip-flat' }} lg:hidden">{{ $lead->stageLabel() }}</span>
                                    @if ($ours)<span class="chip chip-down lg:hidden">Sende</span>@endif
                                </div>
                                @if ($lead->subtitle() !== '')<p class="truncate text-[13px] text-gray-500">{{ $lead->subtitle() }}</p>@endif
                                @if ($pinned !== '')<p class="truncate text-[12px] italic text-gray-400" title="{{ $lead->notes }}" data-sales-pinned>{{ $pinned }}</p>@endif
                            </div>
                            {{-- Durum --}}
                            <div class="hidden lg:block">
                                <span class="chip {{ $stageChip[$lead->stage] ?? 'chip-flat' }}">{{ $lead->stageLabel() }}</span>
                                @if ($lead->isOpen())<span @class(['mt-0.5 block text-[11px] tabular-nums', 'text-warning-700' => $days >= 14, 'text-gray-400' => $days < 14])>{{ $days === 0 ? 'bugün' : $days.' gündür' }}</span>@endif
                            </div>
                            {{-- Top kimde --}}
                            <div class="hidden text-[13px] lg:block" data-sales-ball>
                                @if (! $lead->isOpen())
                                    <span class="text-gray-400">–</span>
                                @elseif ($ours)
                                    <span class="font-semibold text-error-600">Sende</span><span class="block text-[11px] text-error-600/80">{{ $waitDays === 0 ? 'bugün yazdı' : $waitDays.' gündür' }}</span>
                                @elseif ($lead->last_contact_at !== null)
                                    <span class="text-gray-600 dark:text-gray-300">Onda</span><span class="block text-[11px] text-gray-400">{{ $waitDays === 0 ? 'bugün' : $waitDays.' gündür' }}</span>
                                @else
                                    <span class="text-gray-400">Konuşulmadı</span>
                                @endif
                            </div>
                            {{-- Son temas --}}
                            <div class="min-w-0 text-[13px]" data-sales-touch>
                                @if ($touchAt !== null)
                                    <span class="text-gray-500"><span class="lg:hidden">Son: </span><span class="tabular-nums">{{ $touchAt->timezone('Europe/Istanbul')->format('d.m') }}</span>@if ($lt) · {{ $lt['label'] }}@endif</span>
                                    @if ($lt && $lt['text'] !== '')<span class="block truncate text-gray-700 dark:text-gray-300" title="{{ $lt['text'] }}">{{ $lt['text'] }}</span>@endif
                                @else
                                    <span class="text-gray-400">Henüz temas yok</span>
                                @endif
                            </div>
                            {{-- Sonraki adım --}}
                            <div class="min-w-0 text-[13px]" data-sales-next>
                                @if (! $lead->isOpen())
                                    <span class="text-gray-500">{{ $lead->stage === 'won' ? 'Kazanıldı '.$lead->won_at?->timezone('Europe/Istanbul')->format('d.m.Y') : 'Kapandı · '.(SalesLead::LOST_REASONS[$lead->lost_reason] ?? '') }}</span>
                                @elseif ($due === null)
                                    <button type="button" wire:click="openDialog('touch', {{ $lead->id }})" class="font-semibold text-error-600 hover:underline">Adım yok</button>
                                @else
                                    <span class="block truncate text-gray-800 dark:text-gray-200">{{ $lead->next_step ?: 'Takip et' }}</span>
                                    <span @class(['text-[12px] tabular-nums', 'font-semibold text-error-600' => $late, 'font-semibold text-gray-900 dark:text-white' => $isToday && ! $late, 'text-gray-500' => ! $late && ! $isToday])>{{ $isToday ? 'Bugün' : ($late ? $due->format('d.m').' · gecikti' : $due->locale('tr')->isoFormat('D MMM ddd')) }}</span>
                                @endif
                            </div>
                            {{-- Teklif --}}
                            <div class="text-[13px]" data-sales-offer>
                                @if ($lead->offer_amount !== null)
                                    <button type="button" wire:click="openDialog('offer', {{ $lead->id }})" class="text-left hover:underline">
                                        <span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $n($lead->offer_amount) }} ₺</span><span class="text-gray-500">/ay</span>
                                        @if ($lead->serviceLabel())<span class="block truncate text-[11px] text-gray-500">{{ $lead->serviceLabel() }}</span>@endif
                                    </button>
                                    @if (! $lead->offer_confirmed)
                                        <span class="block text-[11px] text-gray-400">mesajdan · <button type="button" wire:click="confirmOffer({{ $lead->id }})" class="link-neutral" data-sales-offer-confirm>Doğru</button></span>
                                    @endif
                                @else
                                    <button type="button" wire:click="openDialog('offer', {{ $lead->id }})" class="text-gray-400 hover:text-gray-700 hover:underline dark:hover:text-gray-200"><span class="lg:hidden">Teklif </span>–</button>
                                @endif
                            </div>
                            {{-- Kaynak --}}
                            <div class="hidden min-w-0 text-[12px] text-gray-500 lg:block">
                                <span class="block truncate">{{ $source }}</span>
                                @if ($heard && $heard !== $source)<span class="block truncate text-gray-400">{{ $heard }}</span>@endif
                            </div>
                            {{-- İşlemler --}}
                            <div class="flex items-center gap-1 lg:justify-end">
                                @if ($lead->isOpen())
                                    <button type="button" wire:click="openDialog('touch', {{ $lead->id }})" class="btn h-7 px-2.5 text-[12px]" title="Telefon ya da yüz yüze görüşmeyi işle" data-sales-touch-open>Temas</button>
                                @endif
                                <div class="relative" x-data="{ menu: false }" x-on:click.outside="menu = false">
                                    <button type="button" x-on:click="menu = !menu" class="btn-quiet h-7 w-7 px-0" aria-label="Diğer işlemler">⋯</button>
                                    <div x-show="menu" x-cloak class="absolute right-0 z-20 mt-1 w-44 rounded-lg border border-gray-200 bg-white py-1 text-[13px] shadow-lg dark:border-gray-700 dark:bg-gray-900">
                                        <a href="{{ route('operator.sales.lead', ['lead' => $lead->id]) }}" wire:navigate class="block px-3 py-1.5 hover:bg-gray-50 dark:hover:bg-white/5">Kartı aç</a>
                                        @if ($wa !== null)<a href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener" class="block px-3 py-1.5 hover:bg-gray-50 dark:hover:bg-white/5">WhatsApp'ta aç</a>@endif
                                        <button type="button" x-on:click="menu = false" wire:click="openDialog('note', {{ $lead->id }})" class="block w-full px-3 py-1.5 text-left hover:bg-gray-50 dark:hover:bg-white/5">Not</button>
                                        <button type="button" x-on:click="menu = false" wire:click="openDialog('offer', {{ $lead->id }})" class="block w-full px-3 py-1.5 text-left hover:bg-gray-50 dark:hover:bg-white/5">Teklif</button>
                                        @if ($lead->isOpen())
                                            <button type="button" x-on:click="menu = false" wire:click="openDialog('close', {{ $lead->id }})" class="block w-full px-3 py-1.5 text-left text-gray-500 hover:bg-gray-50 dark:hover:bg-white/5">Kapat</button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <x-ta.empty-state :title="trim($q) !== '' ? 'Aramaya uyan aday yok' : ($view === 'borclu' ? 'Cevap bekleyen kimse yok' : ($view === 'adimsiz' ? 'Her açık kartın bir sonraki adımı var' : 'Bu görünümde aday yok'))" message="Başka bir görünüm seç ya da yeni bir aday ekle." />
                    @endforelse
                </div>
            </div>
            @endif
        </section>
    @elseif ($tab === 'istekler')
        <section class="mt-5" data-sales-requests>
            <div class="section-head">
                <h2 class="section-title">Müşterilerden gelen istekler <span class="section-count">{{ $n($requests->count()) }}</span></h2>
                <p class="text-sm text-gray-500">Son sözün müşteride kaldığı sohbetler. "Göreve çevir" yarın 10:00'a hatırlatma açar; telefonda da görünür.</p>
            </div>
            <div class="card">
                @forelse ($requests as $conversation)
                    @php $customer = $requestCustomers[$conversation->customer_id] ?? null; @endphp
                    <div wire:key="req-{{ $conversation->id }}" class="card-row" data-sales-request="{{ $conversation->id }}">
                        <x-ta.brand-avatar :name="$customer?->name ?? $conversation->contact_name ?? (string) $conversation->contact_id" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $customer?->name ?? $conversation->contact_name ?? $conversation->contact_id }}<span class="ml-2 text-xs font-normal text-gray-500">{{ $conversation->last_incoming_at?->timezone('Europe/Istanbul')->format('d.m H:i') }}</span></p>
                            <p class="truncate text-[13px] text-gray-500">{{ $previews[$conversation->id] ?? '—' }}</p>
                        </div>
                        <a href="{{ route('operator.whatsapp', ['conversation' => $conversation->id, 'tab' => 'inbox']) }}" wire:navigate class="btn-quiet">Sohbet</a>
                        <button type="button" wire:click="toTask({{ $conversation->id }})" wire:loading.attr="disabled" class="btn-dark">Göreve çevir</button>
                        <button type="button" wire:click="answered({{ $conversation->id }})" class="btn-quiet">Cevaplandı</button>
                    </div>
                @empty
                    <x-ta.empty-state title="Cevap bekleyen müşteri isteği yok" message="WhatsApp yedeği her sabah geldiğinde müşterilerin yazdıkları burada toplanır." />
                @endforelse
            </div>
        </section>
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
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $n($report['opened']) }} kartın vardığı yer</h3>
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
                @endif
            </div>
        </div>
    @endif
</div>
