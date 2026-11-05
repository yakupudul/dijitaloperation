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
                <p class="text-sm text-gray-500">Metni Claude sohbeti ve siteyi okuyarak yazdı: WhatsApp'ta aç, gerekirse düzelt, gönder, "Gönderdim" de. Düzelttiklerin bir sonrakine örnek olur.</p>
            </div>
            <div class="card">
                @forelse ($due as $lead)
                    @php
                        $text = (string) $lead->draft;
                        $drafting = in_array($lead->draft_status, ['queued', 'running'], true);
                        $wa = $lead->waNumber();
                        $overdue = $lead->next_step_at !== null && $lead->next_step_at->timezone('Europe/Istanbul')->toDateString() < $today;
                    @endphp
                    <div wire:key="due-{{ $lead->id }}" class="border-t border-gray-200 px-4 py-4 first:border-t-0 dark:border-gray-800" data-sales-due="{{ $lead->id }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-3">
                                <x-ta.brand-avatar :name="$lead->title()" />
                                <div class="min-w-0">
                                    <a href="{{ route('operator.sales.lead', ['lead' => $lead->id]) }}" wire:navigate class="block truncate text-sm font-semibold text-gray-900 hover:underline dark:text-white">{{ $lead->title() }}</a>
                                    <p class="truncate text-[13px] text-gray-500">
                                        {{ $lead->subtitle() !== '' ? $lead->subtitle().' · ' : '' }}{{ $lead->stageLabel() }} · {{ $lead->sourceLabel() }}
                                        @if ($overdue)<span class="text-error-600"> · {{ $lead->next_step_at->timezone('Europe/Istanbul')->format('d.m') }} tarihinden kaldı</span>@endif
                                    </p>
                                </div>
                            </div>
                            <span class="chip {{ $stageChip[$lead->stage] ?? 'chip-flat' }}">{{ $lead->next_step ?: 'Adım' }}</span>
                        </div>
                        @if ($text !== '')
                            <pre class="mt-3 max-w-[80ch] whitespace-pre-wrap rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-3 font-sans text-[13px] leading-relaxed text-gray-800 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-200" data-sales-draft>{{ $text }}</pre>
                            @if (filled($lead->draft_note))<p class="mt-1.5 max-w-[80ch] text-xs text-gray-500">Claude'un notu: {{ $lead->draft_note }}</p>@endif
                        @elseif ($drafting)
                            <p class="mt-3 text-sm text-gray-500" wire:poll.5s>Claude sohbeti ve siteyi okuyup yazıyor…</p>
                        @else
                            <p class="mt-3 text-sm text-gray-500">{{ $lead->draft_status === 'failed' ? ($lead->draft_note ?: 'Yazılamadı.') : 'Metin henüz yazılmadı.' }}
                                <button type="button" wire:click="draftFor({{ $lead->id }})" class="link-neutral ml-1" data-sales-write>Claude yazsın</button></p>
                        @endif
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            @if ($text === '')
                            @elseif ($wa !== null)
                                <a href="https://wa.me/{{ $wa }}?text={{ rawurlencode($text) }}" target="_blank" rel="noopener" class="btn-primary" data-sales-wa>WhatsApp'ta aç ↗</a>
                            @else
                                <a href="{{ route('operator.sales.lead', ['lead' => $lead->id]) }}" wire:navigate class="btn" title="Telefon yok; karta yaz">Telefon ekle</a>
                            @endif
                            @if ($text !== '')<button type="button" x-data x-on:click="navigator.clipboard?.writeText($el.closest('[data-sales-due]').querySelector('[data-sales-draft]').innerText); $el.textContent = 'Kopyalandı'; setTimeout(() => $el.textContent = 'Kopyala', 1500)" class="btn">Kopyala</button>@endif
                            <button type="button" wire:click="markSent({{ $lead->id }})" wire:loading.attr="disabled" class="btn-dark" data-sales-sent>Gönderdim</button>
                            <button type="button" wire:click="snooze({{ $lead->id }})" wire:loading.attr="disabled" class="btn-quiet">Yarına</button>
                            <button type="button" wire:click="setStage({{ $lead->id }}, 'lost')" wire:confirm="{{ $lead->title() }} kaybedildi olarak kapansın mı?" class="btn-quiet text-gray-400">Kapat</button>
                            <a href="{{ route('operator.sales.lead', ['lead' => $lead->id]) }}" wire:navigate class="link-neutral ml-auto text-[13px]">Kart →</a>
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
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" wire:click="$set('stageFilter', '')" @class(['seg h-8', 'seg-active' => $stageFilter === ''])>Açık <span class="tabular-nums opacity-70">{{ $n($openCount) }}</span></button>
                @foreach (SalesLead::STAGES as $key => $label)
                    @php $c = $key === 'won' ? $wonThisMonth : (int) ($stageCounts[$key] ?? 0); @endphp
                    <button type="button" wire:click="$set('stageFilter', '{{ $key }}')" @class(['seg h-8', 'seg-active' => $stageFilter === $key])>{{ $label }}@if ($key !== 'lost') <span class="tabular-nums opacity-70">{{ $n($c) }}</span>@endif</button>
                @endforeach
                <input wire:model.live.debounce.400ms="q" type="search" placeholder="Ara: isim, klinik, şehir, telefon, site" class="{{ $input }} ml-auto max-w-xs" aria-label="Aday ara" />
            </div>
            <div class="card mt-4">
                @forelse ($list as $lead)
                    @php $due = $lead->next_step_at?->timezone('Europe/Istanbul'); $isDue = $lead->isDue(); @endphp
                    <div wire:key="lead-{{ $lead->id }}" class="card-row" data-sales-lead="{{ $lead->id }}">
                        <x-ta.brand-avatar :name="$lead->title()" />
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('operator.sales.lead', ['lead' => $lead->id]) }}" wire:navigate class="block truncate text-sm font-semibold text-gray-900 hover:underline dark:text-white">{{ $lead->title() }}</a>
                            <p class="truncate text-[13px] text-gray-500">{{ $lead->subtitle() !== '' ? $lead->subtitle().' · ' : '' }}{{ $lead->sourceLabel() }}@if ($lead->last_contact_at) · son temas {{ $lead->last_contact_at->timezone('Europe/Istanbul')->format('d.m') }}@endif</p>
                        </div>
                        <span class="chip {{ $stageChip[$lead->stage] ?? 'chip-flat' }}">{{ $lead->stageLabel() }}</span>
                        <div class="hidden w-48 shrink-0 text-right text-[13px] sm:block">
                            @if ($lead->isOpen())
                                @if ($due !== null)
                                    <span @class(['font-medium', 'text-error-600' => $isDue && $due->toDateString() < $today, 'text-gray-900 dark:text-white' => ! ($isDue && $due->toDateString() < $today)])>{{ $due->toDateString() === $today ? 'Bugün' : $due->format('d.m') }}</span>
                                    <span class="block truncate text-gray-500">{{ $lead->next_step }}</span>
                                @else
                                    <span class="text-warning-800 dark:text-warning-400">Sonraki adım yok</span>
                                @endif
                            @else
                                <span class="text-gray-500">{{ $lead->stage === 'won' ? ($lead->won_at?->timezone('Europe/Istanbul')->format('d.m.Y') ?? '') : (SalesLead::LOST_REASONS[$lead->lost_reason] ?? '') }}</span>
                            @endif
                        </div>
                    </div>
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
                <div class="hero-panel grid-cols-2 lg:grid-cols-5">
                    <div class="hero-tile hero-tile-accent"><span class="hero-label">Aylık gelir</span><span class="hero-value">{{ $n((int) $money['mrr']) }} ₺</span><span class="mt-2 text-xs opacity-75">{{ $n($money['customers']) }} aktif müşteri</span></div>
                    <div class="hero-tile"><span class="hero-label">Fiyat konuşulan</span><span class="hero-value">{{ $n($money['priced']) }}</span><span class="mt-2 text-xs text-white/60">aday</span></div>
                    <div class="hero-tile"><span class="hero-label">Bu ay kazanılan</span><span class="hero-value">{{ $n($money['won_month']) }}</span><span class="mt-2 text-xs text-white/60">aday</span></div>
                    <div class="hero-tile"><span class="hero-label">Bu ay kaybedilen</span><span class="hero-value">{{ $n($money['lost_month']) }}</span><span class="mt-2 text-xs text-white/60">aday</span></div>
                    <div class="hero-tile"><span class="hero-label">Açık aday</span><span class="hero-value">{{ $n($openCount) }}</span><span class="mt-2 text-xs text-white/60">{{ $n($dueCount) }} bugün</span></div>
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
