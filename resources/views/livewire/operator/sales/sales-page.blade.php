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
                <label class="block text-xs text-gray-500">Klinik<input wire:model="form.clinic" type="text" placeholder="Klinik adı" class="mt-1 {{ $input }}" /></label>
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
                                <button type="button" wire:click="setStage({{ $lead->id }}, 'lost')" wire:confirm="{{ $lead->title() }} kaybedildi olarak kapansın mı?" class="link-neutral text-gray-400">Kapat</button>
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
        <section class="mt-5" data-sales-leads>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <select wire:model.live="stageFilter" class="{{ str_replace('w-full', 'w-52', $input) }}" aria-label="Aşama">
                    <option value="">Açık ({{ $n($openCount) }})</option>
                    @foreach (SalesLead::STAGES as $key => $label)
                        @php $c = $key === 'won' ? $wonThisMonth : (int) ($stageCounts[$key] ?? 0); @endphp
                        <option value="{{ $key }}">{{ $label }}{{ $key !== 'lost' ? ' ('.$n($c).')' : '' }}</option>
                    @endforeach
                </select>
                <input wire:model.live.debounce.400ms="q" type="search" placeholder="Ara" class="{{ str_replace('w-full', 'w-full sm:w-64', $input) }}" aria-label="Aday ara" />
            </div>
            <div class="card mt-3 divide-y divide-gray-200 dark:divide-gray-800">
                @forelse ($list as $lead)
                    @php $due = $lead->next_step_at?->timezone('Europe/Istanbul'); $late = $lead->isDue() && $due !== null && $due->toDateString() < $today; @endphp
                    <a href="{{ route('operator.sales.lead', ['lead' => $lead->id]) }}" wire:navigate wire:key="lead-{{ $lead->id }}" class="flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50 dark:hover:bg-white/[0.03]" data-sales-lead="{{ $lead->id }}">
                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900 dark:text-white">{{ $lead->title() }}@if ($lead->subtitle() !== '')<span class="font-normal text-gray-500"> · {{ $lead->subtitle() }}</span>@endif</span>
                        <span class="hidden shrink-0 text-xs text-gray-500 sm:inline">{{ $lead->stageLabel() }}</span>
                        <span @class(['w-24 shrink-0 text-right text-[13px] tabular-nums', 'text-error-600' => $late, 'text-gray-500' => ! $late])>
                            @if ($lead->isOpen())
                                {{ $due === null ? '–' : ($due->toDateString() === $today ? 'Bugün' : $due->format('d.m')) }}
                            @else
                                {{ $lead->stage === 'won' ? 'Kazanıldı' : 'Kapandı' }}
                            @endif
                        </span>
                    </a>
                @empty
                    <x-ta.empty-state title="Bu süzgeçte aday yok" message="Yeni bir aday ekle ya da süzgeci değiştir." />
                @endforelse
            </div>
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
                    <div class="hero-tile"><span class="hero-label">Fiyat konuşulan</span><span class="hero-value">{{ $n($money['priced']) }}</span><span class="mt-2 text-xs text-white/60">karar bekliyor</span></div>
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
</div>
