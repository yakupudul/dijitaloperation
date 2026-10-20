@php
    // İşletme profilleri › Yorumlar (panel concept 2026-10-20). The question: "Which reviews wait for a reply?" Bad
    // reviews first, then three numbers, then the list itself (one card, one review per row) with a quiet filter bar.
    // Review topics mix every brand's words when several brands are listed ("Tül perde" under a dental clinic): show them for one brand.
    $topicsForOneBrand = $brand !== null || $this->location !== null || $asset !== null || $groups->count() <= 1;
    $pickLink = 'link-neutral whitespace-nowrap';
    $n = fn (int $v): string => number_format($v, 0, ',', '.');
    $input = 'rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-800 placeholder:text-gray-400 focus:border-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:focus:ring-white/10';
    // Segmented filter: the active option is a white raised chip on a light gray rail.
    $segment = 'inline-flex h-7 items-center rounded-md px-2.5 text-[13px] font-medium whitespace-nowrap';
    $segmentOn = 'bg-white text-gray-900 shadow-card dark:bg-white/10 dark:text-white';
    $segmentOff = 'text-gray-500 hover:text-gray-900 dark:hover:text-white';
    $chipOn = 'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-gray-900';
    $chipOff = 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-300';
@endphp
<div class="dark:text-gray-200" data-gbp-reviews>
    @if ($asset === null)
        @include('livewire.operator.gbp.partials.desk-tabs', ['active' => 'operator.gbp-reviews', 'brandFilter' => $brand])
    @endif

    <div @class(['space-y-8', 'space-y-10' => $asset === null])>
        @if ($asset === null)
            <header class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                    <h1 class="page-title" title="Yorum sayısı, yorumların tazeliği ve yanıtlanması yerel sıralamada en güçlü sinyallerden. Bütün profillerin yorumları tek ekranda: yanıt bekleyenleri seç, yapay zekâ taslak yazsın ya da ortak bir yanıt ver, ön izle ve toplu yayımla (her yanıt geri alınabilir). “Yorum isteme” bölümünde her şube için Google yorum bağlantısı, QR kod, müşteriye gönderilecek mesaj ve yazdırılabilir kart var.">İşletme profilleri</h1>
                    <p class="page-sub">{{ $totals['unanswered'] === 0 ? 'Yanıt bekleyen yorum yok.' : $n($totals['unanswered']).' yorum yanıt bekliyor'.($totals['late'] > 0 ? ', '.$n($totals['late']).' tanesi '.\App\Services\Gbp\GbpDailyWorkspace::REPLY_SLA_HOURS.' saatten uzun.' : '.') }}</p>
                </div>
                @include('livewire.operator.gbp.partials.brand-filter')
            </header>
        @endif
        @include('livewire.operator.gbp.partials.desk-message')

        {{-- 1. What waits: bad reviews without a reply. --}}
        @if ($section === 'yanit' && $lowOpen > 0 && ! ($status === 'bekleyen' && $rating === 'low'))
            <section class="card" data-testid="gbp-low-open">
                <div class="card-row flex-wrap sm:flex-nowrap">
                    <span class="row-icon border-transparent bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/><path d="M12 8v4M12 15.5v.5"/></svg></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $lowOpen }} kötü yorum (1–2 ★) yanıt bekliyor.</p>
                        <p class="mt-0.5 text-[13px] text-gray-500">Kötü yorumu yanıtsız bırakmak, onu okuyan herkese “ilgilenmiyorlar” der. Önce bunları yanıtla.</p>
                    </div>
                    <button type="button" wire:click="showLowOpen" class="btn-primary w-full sm:w-auto">Kötü yorumları göster</button>
                </div>
            </section>
        @endif

        @if ($approvalUrl || $approvalLinks->isNotEmpty())
            <section data-testid="gbp-approval-links">
                <div class="section-head"><h2 class="section-title">Marka onay bağlantıları <span class="section-count">{{ $approvalLinks->count() }}</span></h2></div>
                <div class="card">
                    @if ($approvalUrl)
                        <div class="card-row flex-wrap" x-data="{ copied: false }">
                            <input type="text" readonly value="{{ $approvalUrl }}" class="h-8 min-w-0 flex-1 {{ $input }} text-[13px]" x-on:focus="$el.select()">
                            <button type="button" x-on:click="navigator.clipboard.writeText(@js($approvalUrl)); copied = true" class="btn" x-text="copied ? 'Kopyalandı' : 'Bağlantıyı kopyala'">Bağlantıyı kopyala</button>
                            <a href="https://wa.me/?text={{ rawurlencode('Google yorumlarınıza vermeyi önerdiğimiz yanıtlar: '.$approvalUrl) }}" target="_blank" rel="noopener" class="btn-quiet">WhatsApp ile paylaş</a>
                        </div>
                    @endif
                    @foreach ($approvalLinks as $link)
                        @php
                            $decided = collect($link->items)->whereNotNull('decision');
                            $state = match (true) {
                                $link->status === \App\Models\GbpReviewApproval::CLOSED => 'Kapatıldı',
                                ! $link->isUsable() => 'Süresi doldu',
                                $link->answered_at !== null => 'Marka yanıtladı: '.$decided->where('decision', 'ok')->count().' uygun, '.$decided->where('decision', 'edit')->count().' düzeltildi, '.$decided->where('decision', 'skip')->count().' istenmedi',
                                default => 'Markada bekliyor',
                            };
                        @endphp
                        <div class="card-row flex-wrap sm:flex-nowrap" wire:key="approval-{{ $link->id }}">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $link->brand?->name }}, {{ count($link->items) }} yanıt</p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-x-3 text-[13px] text-gray-500"><span class="tabular-nums">{{ $link->created_at?->timezone('Europe/Istanbul')->format('d.m H:i') }}</span> <x-ta.pill :tone="! $link->isUsable() ? 'info' : ($link->answered_at !== null ? 'ok' : 'look')">{{ $state }}</x-ta.pill></p>
                                @if ($link->note)<p class="mt-1 text-[13px] text-gray-600 dark:text-gray-300">Markanın notu: {{ $link->note }}</p>@endif
                            </div>
                            @if ($link->isUsable())
                                <span class="flex gap-1">
                                    <button type="button" x-data x-on:click="navigator.clipboard.writeText(@js(\App\Services\Gbp\Desk\ReviewApprovals::url($link)))" class="btn-quiet">Kopyala</button>
                                    <a href="{{ \App\Services\Gbp\Desk\ReviewApprovals::url($link) }}" target="_blank" rel="noopener" class="btn-quiet">Aç</a>
                                    <button type="button" wire:click="closeApproval({{ $link->id }})" wire:confirm="Bağlantı kapatılsın mı? Marka artık açamaz." class="btn-quiet">Kapat</button>
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- 2. Three numbers. Unanswered reviews are worth a look, not money loss: no red number. --}}
        <section class="metric-strip grid-cols-2 sm:grid-cols-3">
            <x-ta.metric-card label="Yanıtsız yorum" :value="$n($totals['unanswered'])">@if ($totals['unanswered'] > 0)<x-ta.pill tone="look">yanıt bekliyor</x-ta.pill>@endif</x-ta.metric-card>
            <x-ta.metric-card :label="\App\Services\Gbp\GbpDailyWorkspace::REPLY_SLA_HOURS.' saatten uzun bekleyen'" :value="$n($totals['late'])" />
            <x-ta.metric-card :label="'Son '.\App\Services\Gbp\Desk\ReviewDesk::WINDOW_DAYS.' günde gelen yorum'" :value="$n($totals['recent'])" class="col-span-2 sm:col-span-1" />
        </section>

        <div class="space-y-4">
            <nav class="no-scrollbar flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Yorum bölümleri">
                <button type="button" wire:click="setSection('yanit')" @class(['tab', 'tab-active' => $section === 'yanit']) @if ($section === 'yanit') aria-current="page" @endif>Yorumlar <span class="tabular-nums text-gray-500">{{ $totals['unanswered'] }} yanıtsız</span></button>
                <button type="button" wire:click="setSection('iste')" @class(['tab', 'tab-active' => $section === 'iste']) @if ($section === 'iste') aria-current="page" @endif>Yorum isteme</button>
            </nav>

            @if ($chips !== [])
                <nav class="no-scrollbar flex gap-1.5 overflow-x-auto" aria-label="İşletme" data-testid="gbp-review-locations">
                    <button type="button" wire:click="setLocation" @class(['inline-flex h-7 shrink-0 items-center gap-1.5 rounded-lg border px-2.5 text-[13px] font-medium', $chipOn => $this->location === null, $chipOff => $this->location !== null])>Tüm işletmeler @if ($allUnanswered > 0)<span class="tabular-nums opacity-60">{{ $allUnanswered }}</span>@endif</button>
                    @foreach ($chips as $chip)
                        <button type="button" wire:key="chip-{{ $chip['id'] }}" wire:click="setLocation({{ $chip['id'] }})" title="{{ $chip['name'] }}" @class(['inline-flex h-7 max-w-64 shrink-0 items-center gap-1.5 rounded-lg border px-2.5 text-[13px] font-medium', $chipOn => $this->location === $chip['id'], $chipOff => $this->location !== $chip['id']])><span class="truncate">{{ $chip['name'] }}</span> @if ($chip['unanswered'] > 0)<span class="tabular-nums opacity-60">{{ $chip['unanswered'] }}</span>@endif</button>
                    @endforeach
                </nav>
            @endif
        </div>

        @if ($section === 'yanit' && $card !== [])
            <details class="card group" data-testid="gbp-review-card">
                <summary class="card-row cursor-pointer list-none hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                    <span class="row-icon" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="5"/><rect x="12" y="8" width="3" height="9"/><rect x="17" y="5" width="3" height="12"/></svg></span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium text-gray-900 dark:text-white">Şube karnesi</span>
                        <span class="mt-0.5 block text-[13px] text-gray-500">Yanıt oranı, yanıt süresi, son {{ \App\Services\Gbp\Desk\ReviewDesk::WINDOW_DAYS }} gün puanı</span>
                    </span>
                    <span class="btn-quiet"><span class="group-open:hidden">Aç</span><span class="hidden group-open:inline">Kapat</span></span>
                </summary>
                <div class="overflow-x-auto border-t border-gray-200 dark:border-gray-800">
                    <table class="data-table">
                        <thead><tr><th>Şube</th><th class="text-right!">Yorum</th><th class="text-right!">Yanıt oranı</th><th class="text-right!" title="Yorumdan yanıta geçen ortalama süre (Google’ın tarihine göre). Puan değişimi önceki {{ \App\Services\Gbp\Desk\ReviewDesk::WINDOW_DAYS }} güne göre.">Ort. yanıt süresi</th><th class="text-right!">Yanıtsız</th><th>Son {{ \App\Services\Gbp\Desk\ReviewDesk::WINDOW_DAYS }} gün</th></tr></thead>
                        <tbody>
                            @foreach ($card as $row)
                                @php
                                    $hours = $row['reply_hours'] ?? null;
                                    $trend = ($row['average'] ?? null) !== null && ($row['previous_average'] ?? null) !== null ? round($row['average'] - $row['previous_average'], 1) : null;
                                @endphp
                                <tr wire:key="card-{{ $row['id'] }}">
                                    <td class="max-w-64"><button type="button" wire:click="setLocation({{ $row['id'] }})" class="block max-w-full truncate text-left font-medium text-gray-900 hover:underline dark:text-white" title="{{ $row['name'] }}">{{ $row['name'] }}</button></td>
                                    <td class="text-right tabular-nums">{{ $row['total'] }}</td>
                                    <td @class(['text-right tabular-nums', 'font-medium text-warning-700 dark:text-warning-400' => ($row['reply_rate'] ?? 100) < 50])>{{ ($row['reply_rate'] ?? null) === null ? '—' : $row['reply_rate'].'%' }}</td>
                                    <td class="text-right tabular-nums">{{ $hours === null ? '—' : ($hours < 48 ? $hours.' saat' : intdiv($hours, 24).' gün') }}</td>
                                    <td @class(['text-right tabular-nums', 'font-medium text-warning-700 dark:text-warning-400' => ($row['unanswered'] ?? 0) > 0])>{{ $row['unanswered'] ?? 0 }}@if (($row['low_open'] ?? 0) > 0) <span class="text-xs font-normal">({{ $row['low_open'] }} kötü)</span>@endif</td>
                                    <td class="whitespace-nowrap tabular-nums">{{ ($row['recent'] ?? 0).' yorum'.(($row['average'] ?? null) !== null ? ', '.number_format($row['average'], 1, ',', '.').' ★' : '') }}
                                        @if ($trend !== null && $trend != 0)<span @class(['text-xs font-medium', 'text-success-600' => $trend > 0, 'text-error-600' => $trend < 0])>{{ $trend > 0 ? '+' : '' }}{{ number_format($trend, 1, ',', '.') }}</span>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @endif

        @if ($section === 'yanit' && ! $topicsForOneBrand && ($topics['doctors'] !== [] || $topics['services'] !== []))
            <p class="text-[13px] text-gray-500" data-testid="gbp-review-topics-hint">Yorumlarda neler konuşulduğu marka seçilince görünür (markaların konuları karışmasın diye).</p>
        @elseif ($section === 'yanit' && ($topics['doctors'] !== [] || $topics['services'] !== []))
            <section data-testid="gbp-review-topics">
                <div class="section-head"><h2 class="section-title">Yorumlarda neler konuşuluyor</h2></div>
                <div class="card">
                    @foreach (['doctors' => 'Hekimler', 'services' => 'Hizmetler'] as $kind => $label)
                        @if ($topics[$kind] !== [])
                            <div class="card-row flex-wrap items-start sm:flex-nowrap">
                                <span class="w-full shrink-0 pt-1 text-[13px] text-gray-500 sm:w-20">{{ $label }}</span>
                                <span class="flex min-w-0 flex-1 flex-wrap gap-1.5">
                                    @foreach ($topics[$kind] as $topic)
                                        @php $word = $kind === 'doctors' ? $topic['name'] : $topic['word']; $on = \App\Services\Gbp\Desk\ReviewDesk::lower($search) === \App\Services\Gbp\Desk\ReviewDesk::lower($word); @endphp
                                        <button type="button" wire:click="searchFor(@js($word))" @class(['inline-flex h-7 items-center rounded-lg border px-2.5 text-[13px] font-medium', $chipOn => $on, $chipOff => ! $on])>
                                            {{ $topic['name'].' · '.$topic['count'].' yorum'.($topic['average'] !== null ? ', '.number_format($topic['average'], 1, ',', '.').' ★' : '') }}
                                        </button>
                                    @endforeach
                                </span>
                            </div>
                        @endif
                    @endforeach
                </div>
            </section>
        @endif

        @if ($section === 'yanit')
            <section class="space-y-3">
                {{-- One compact filter bar that wraps (2026-10-19): search, status, rating, sort, period; the pick links are a quiet second line. --}}
                <div class="space-y-2.5" data-testid="gbp-review-filters">
                    <div class="flex flex-wrap items-center gap-2">
                        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Yorumda ya da adda ara (ör. implant, Zeliha)" class="h-8 w-full {{ $input }} text-[13px] sm:w-64" data-testid="gbp-review-search">
                        <span class="no-scrollbar inline-flex max-w-full gap-0.5 overflow-x-auto rounded-lg bg-gray-100 p-0.5 dark:bg-white/[0.04]">
                            @foreach (\App\Services\Gbp\Desk\ReviewDesk::STATUSES as $key => $label)
                                <button type="button" wire:click="setStatus('{{ $key }}')" @class([$segment, $segmentOn => $status === $key, $segmentOff => $status !== $key])>{{ $label }}</button>
                            @endforeach
                        </span>
                        <span class="no-scrollbar inline-flex max-w-full gap-0.5 overflow-x-auto rounded-lg bg-gray-100 p-0.5 dark:bg-white/[0.04]">
                            @foreach (['' => 'Tüm puanlar', 'low' => '1–2 ★', 'mid' => '3 ★', 'high' => '4–5 ★'] as $key => $label)
                                <button type="button" wire:click="setRating('{{ $key }}')" @class([$segment, $segmentOn => $rating === $key, $segmentOff => $rating !== $key])>{{ $label }}</button>
                            @endforeach
                        </span>
                        <span class="no-scrollbar inline-flex max-w-full gap-0.5 overflow-x-auto rounded-lg bg-gray-100 p-0.5 dark:bg-white/[0.04]">
                            @foreach (\App\Services\Gbp\Desk\ReviewDesk::SORTS as $key => $label)
                                <button type="button" wire:click="setSort('{{ $key }}')" @class([$segment, $segmentOn => $sort === $key, $segmentOff => $sort !== $key])>{{ $label }}</button>
                            @endforeach
                        </span>
                        <button type="button" wire:click="toggleRecent" @class(['inline-flex h-8 items-center rounded-lg border px-2.5 text-[13px] font-medium', $chipOn => $recentOnly, $chipOff => ! $recentOnly])>Son {{ \App\Services\Gbp\Desk\ReviewDesk::WINDOW_DAYS }} gün</button>
                    </div>
                    @if (($status === 'bekleyen' && $total > 0) || ($canWrite && $openCount > 0))
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px]">
                            @if ($canWrite && $openCount > 0)
                                <span class="text-gray-500">Seç:</span>
                                <button type="button" wire:click="pick('all')" class="{{ $pickLink }}">Görünen yanıtsızlar ({{ $openCount }})</button>
                                <button type="button" wire:click="pick('ready')" class="{{ $pickLink }}">Yanıtı hazır olanlar ({{ $openReady }})</button>
                                <button type="button" wire:click="pick('nodraft')" class="{{ $pickLink }}" data-testid="gbp-pick-nodraft">Taslağı olmayanlar ({{ $openCount - $openReady }})</button>
                                <button type="button" wire:click="pick('silent')" class="{{ $pickLink }}">Yorumsuz 4–5 ★</button>
                                <button type="button" wire:click="pick('brand')" class="{{ $pickLink }}">Markanın onayladıkları</button>
                                @if ($selected !== [])<button type="button" wire:click="pick('none')" class="text-gray-500 hover:underline">Temizle</button>@endif
                            @endif
                            @if ($status === 'bekleyen' && $total > 0)<a href="{{ route('operator.gbp-review-replies-pdf', array_filter(['marka' => $brand, 'isletme' => $this->location])) }}" class="{{ $pickLink }} sm:ml-auto" title="Yanıtı hazır (AI ya da elle yazılmış) tüm bekleyen yorumlar, markanın onayı için">Hazır yanıtları PDF indir</a>@endif
                        </div>
                    @endif
                </div>

                @if ($canWrite && $pickedCount > 0)
                    <div class="card sticky top-2 z-20 space-y-2.5 p-3 shadow-theme-lg" data-testid="gbp-review-bulk">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $pickedCount }} yorum seçili</span>
                            <span class="text-[13px] text-gray-500">{{ $readyCount }} yanıtı hazır (gönderilmedi) · {{ $toDraftCount }} taslaksız</span>
                            <span class="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto">
                                @if ($toDraftCount > 0)<button type="button" wire:click="draftSelected" class="btn" title="Yalnız taslağı olmayanlara yazar; hazır yanıtlara dokunmaz">AI ile taslak yaz ({{ min($toDraftCount, \App\Services\Gbp\Desk\ReviewDesk::DRAFT_BATCH) }})</button>@endif
                                <button type="button" wire:click="sendToBrand" class="btn" title="Marka giriş yapmadan açar; her yanıtı onaylar, düzeltir ya da istemez">Markaya onaya gönder</button>
                                @if ($readyCount > 0)<button type="button" wire:click="deleteSelectedDrafts" wire:confirm="Seçilen {{ $readyCount }} yorumun yanıt taslağı silinsin mi?" class="btn" data-testid="gbp-delete-drafts">Taslakları sil ({{ $readyCount }})</button>@endif
                                <a href="{{ $this->pdfUrl() }}" class="btn" title="Hazır yanıtları markanın onayına göndermek için">PDF</a>
                                <button type="button" wire:click="openPreview" class="btn-primary">Ön izle ve yayımla ({{ $readyCount }})</button>
                            </span>
                        </div>
                        <div class="flex flex-wrap items-start gap-2">
                            <textarea wire:model="bulkText" rows="2" maxlength="4000" class="min-w-0 flex-1 {{ $input }}" placeholder="Seçilenlere ortak yanıt (isteğe bağlı). {ad} yorumu yazanın adı olur: “Teşekkür ederiz {ad}, sizi yeniden ağırlamaktan mutluluk duyarız.”"></textarea>
                            <button type="button" wire:click="fillSelected" class="btn">Seçilenlere yaz</button>
                        </div>
                    </div>
                @endif

                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="section-title">{{ \App\Services\Gbp\Desk\ReviewDesk::STATUSES[$status] ?? 'Yorumlar' }} <span class="section-count">{{ count($reviews) }} / {{ $total }} yorum</span></h2>
                </div>

                <div class="card" data-testid="gbp-review-grid" @if ($drafting) wire:poll.10s @endif>
                    @forelse ($reviews as $review)
                        @php
                            $action = $review['action'];
                            $busy = \App\Services\Gbp\Desk\ReviewDesk::busy($review);
                            $isPicked = in_array($review['id'], array_map('intval', $selected), true);
                            $failedDraft = is_string($review['draft_state']) && str_starts_with($review['draft_state'], 'failed');
                            $low = ($review['rating'] ?? 5) <= 2;
                        @endphp
                        <article wire:key="rv-{{ $review['id'] }}" x-data="{ more: false }" @class(['flex min-w-0 gap-3 border-t border-gray-200 px-4 py-4 first:border-t-0 dark:border-gray-800', 'bg-gray-50 dark:bg-white/[0.04]' => $isPicked])>
                            @if ($canWrite && ! $busy)
                                <input type="checkbox" wire:model.live="selected" value="{{ $review['id'] }}" class="mt-1 h-4 w-4 shrink-0 rounded border-gray-300 text-gray-900" aria-label="Seç">
                            @endif
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5">
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $review['reviewer'] }}</span>
                                    <span class="text-[13px] tracking-[0.06em]" aria-label="{{ $review['rating'] }} yıldız">@if ($review['rating'] !== null)<span @class(['text-warning-500 dark:text-warning-400' => $low, 'text-gray-700 dark:text-gray-300' => ! $low])>{{ str_repeat('★', $review['rating']) }}</span><span class="text-gray-300 dark:text-gray-600">{{ str_repeat('★', 5 - $review['rating']) }}</span>@else<span class="text-gray-400">—</span>@endif</span>
                                    <span class="text-[13px] tabular-nums text-gray-500">{{ $review['date'] }}</span>
                                    <span class="ml-auto shrink-0 text-[13px]">
                                        @if ($review['answered'])
                                            <x-ta.pill tone="ok">Yanıtlandı</x-ta.pill>
                                        @else
                                            <span @class(['font-medium text-warning-700 dark:text-warning-400' => $review['late'], 'text-gray-500' => ! $review['late']]) title="Yanıt bekliyor">{{ $review['waiting'] }}</span>
                                        @endif
                                    </span>
                                </div>
                                <p class="truncate text-[13px] text-gray-500" title="{{ $names[$review['asset_id']] ?? '' }}">{{ $names[$review['asset_id']] ?? '—' }}</p>
                                @if (! $review['answered'] && isset($brandAnswers[$review['id']]))
                                    @php $answer = $brandAnswers[$review['id']]; @endphp
                                    <x-ta.pill class="mt-1" :tone="in_array($answer['state'], ['ok', 'edit'], true) ? 'ok' : ($answer['state'] === 'waiting' ? 'look' : 'info')" data-testid="gbp-brand-answer">{{ $answer['label'] }}</x-ta.pill>
                                @endif

                                @if ($review['comment'] !== '')
                                    <p class="mt-2 max-w-3xl whitespace-pre-line break-words text-sm leading-6 text-gray-800 dark:text-gray-200" :class="more ? '' : 'line-clamp-4'">{{ $review['comment'] }}</p>
                                    @if (mb_strlen($review['comment']) > 180)<button type="button" x-on:click="more = ! more" class="link-neutral text-[13px]" x-text="more ? 'Daha az' : 'Devamı'"></button>@endif
                                @else
                                    <p class="mt-2 text-sm text-gray-500">Yalnız puan, yorum metni yok.</p>
                                @endif

                                <div class="mt-3 max-w-3xl">
                                    @if ($review['answered'])
                                        @if ($editing === $review['id'])
                                            <textarea wire:model="editText" rows="4" maxlength="4000" class="w-full {{ $input }}" data-testid="gbp-edit-reply"></textarea>
                                            <span class="mt-2 flex flex-wrap items-center gap-2">
                                                <button type="button" wire:click="saveEdit" wire:confirm="Google’daki yanıt bu metinle değişsin mi? (Geri alınabilir.)" class="btn-primary">Güncelle</button>
                                                <button type="button" wire:click="cancelEdit" class="btn-quiet">Vazgeç</button>
                                            </span>
                                        @else
                                            <p class="break-words border-l-2 border-gray-200 pl-3 text-[13px] text-gray-700 dark:border-gray-700 dark:text-gray-300"><span class="font-medium">Yanıt:</span> {{ \Illuminate\Support\Str::limit($review['reply'], 280) }}</p>
                                            <span class="mt-1.5 flex flex-wrap gap-1">
                                                @if ($canWrite && ($action === null || ! in_array($action['status'], ['queued', 'running', 'undoing'], true)))
                                                    <button type="button" wire:click="startEdit({{ $review['id'] }})" class="btn-quiet h-7! px-2!">Düzenle</button>
                                                @endif
                                                @if ($canWrite && $action !== null && $action['undoable'])
                                                    <button type="button" wire:click="undoReply({{ $action['id'] }})" wire:confirm="Yanıt Google’dan geri alınsın mı?" class="btn-quiet h-7! px-2!">Geri al</button>
                                                @endif
                                            </span>
                                        @endif
                                    @elseif ($action !== null && $busy)
                                        <p class="text-[13px] text-gray-500" @if (in_array($action['status'], ['queued', 'running'], true)) wire:poll.5s @endif>Yanıt: {{ $action['label'] }}</p>
                                    @elseif ($canWrite)
                                        @if ($action !== null && $action['status'] === 'failed')<p class="mb-1.5 text-[13px] text-error-600">Gönderilemedi: {{ \Illuminate\Support\Str::limit((string) $action['error'], 160) }}</p>@endif
                                        @if ($review['draft'] !== null || array_key_exists('r'.$review['id'], $replies))
                                            <textarea wire:model.blur="replies.r{{ $review['id'] }}" rows="3" maxlength="4000" class="w-full {{ $input }}" placeholder="Yanıt"></textarea>
                                            <span class="mt-2 flex flex-wrap items-center gap-2">
                                                <button type="button" wire:click="send({{ $review['id'] }})" wire:confirm="Bu yanıt Google’da yayınlansın mı? (Geri alınabilir.)" class="btn">Yalnız bunu gönder</button>
                                                <button type="button" wire:click="deleteDraft({{ $review['id'] }})" wire:confirm="Bu yorumun yanıt taslağı silinsin mi?" class="btn-quiet" data-testid="gbp-delete-draft">Taslağı sil</button>
                                            </span>
                                        @elseif ($review['draft_state'] === 'running')
                                            <p class="text-[13px] text-gray-500">Taslak yazılıyor…</p>
                                        @else
                                            @if ($failedDraft)<p class="mb-1.5 text-[13px] text-error-600">{{ \Illuminate\Support\Str::after((string) $review['draft_state'], 'failed: ') }}</p>@endif
                                            <span class="flex flex-wrap items-center gap-2">
                                                <button type="button" wire:click="draftOne({{ $review['id'] }})" class="btn">AI taslağı</button>
                                                <button type="button" wire:click="$set('replies.r{{ $review['id'] }}', '')" class="btn-quiet">Kendim yazayım</button>
                                                @if (! ($flags[$review['id']] ?? null) && $flagging !== $review['id'])
                                                    <button type="button" wire:click="startFlag({{ $review['id'] }})" @class(['btn-quiet', 'text-gray-500!' => ! $low])>Kaldırılmasını iste</button>
                                                @endif
                                            </span>
                                        @endif
                                    @endif
                                </div>

                                @php $flag = $flags[$review['id']] ?? null; @endphp
                                @if ($flag || $flagging === $review['id'])
                                    <div class="mt-3 max-w-3xl rounded-lg border border-gray-200 bg-gray-50/70 p-3 text-[13px] dark:border-gray-800 dark:bg-white/[0.02]" data-testid="gbp-review-flag">
                                        @if ($flagging === $review['id'])
                                            <p class="font-medium text-gray-900 dark:text-white">Neden kaldırılmalı? (Google yalnız kurallarına aykırı yorumu kaldırır; kötü puan tek başına neden değildir.)</p>
                                            <select wire:model="flagReason" class="mt-2 h-8 w-full {{ $input }} py-0 text-[13px]">
                                                <option value="">Seçin</option>
                                                @foreach (\App\Models\GbpReviewFlag::REASONS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                                            </select>
                                            <textarea wire:model="flagNote" rows="2" maxlength="1000" class="mt-2 w-full {{ $input }} text-[13px]" placeholder="Kanıt / not (ör. kayıtlarda bu kişi yok, rakip firmanın çalışanı)"></textarea>
                                            <div class="mt-2 flex gap-2"><button type="button" wire:click="saveFlag" class="btn">Talebi hazırla</button><button type="button" wire:click="$set('flagging', null)" class="btn-quiet">Vazgeç</button></div>
                                        @else
                                            <p><span class="font-medium text-gray-900 dark:text-white">{{ $flag['status_label'] }}</span>@if ($flag['reported_at']) {{ $flag['reported_at'] }}@endif, {{ $flag['reason_label'] }}</p>
                                            @if (in_array($flag['status'], ['draft', 'kept'], true))
                                                <p class="mt-1.5 text-gray-600 dark:text-gray-300">Google’a gönderilecek açıklama:</p>
                                                <p class="mt-0.5 text-gray-800 dark:text-gray-200" x-data>{{ \App\Services\Gbp\Desk\ReviewFlags::reportText($flag['reason'], $flag['note']) }}</p>
                                                <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 font-medium">
                                                    <button type="button" x-data x-on:click="navigator.clipboard.writeText(@js(\App\Services\Gbp\Desk\ReviewFlags::reportText($flag['reason'], $flag['note'])))" class="link-neutral">Metni kopyala</button>
                                                    <a href="{{ \App\Services\Gbp\Desk\ReviewFlags::TOOL_URL }}" target="_blank" rel="noopener" class="link-neutral">Google’ın yorum yönetim aracı</a>
                                                    @if (($places[$review['asset_id']] ?? '') !== '')<a href="https://www.google.com/maps/place/?q=place_id:{{ $places[$review['asset_id']] }}" target="_blank" rel="noopener" class="link-neutral">Haritada aç</a>@endif
                                                </div>
                                                @if ($canWrite)<div class="mt-2 flex flex-wrap gap-2"><button type="button" wire:click="markReported({{ $review['id'] }})" class="btn">Google’a bildirdim</button><button type="button" wire:click="startFlag({{ $review['id'] }})" class="btn-quiet">Düzenle</button><button type="button" wire:click="closeFlag({{ $review['id'] }}, 'cancel')" wire:confirm="Kaldırma talebi silinsin mi?" class="btn-quiet">Sil</button></div>@endif
                                            @elseif ($flag['status'] === 'reported' && $canWrite)
                                                <p class="mt-1.5 text-gray-600 dark:text-gray-300">Google genelde birkaç gün içinde karar verir; yorum kalkınca bir sonraki tam yorum toplamasında “Kaldırıldı” olur. Google reddederse aynı araçtan bir kez itiraz edebilirsiniz.</p>
                                                <div class="mt-2 flex flex-wrap gap-2"><button type="button" wire:click="closeFlag({{ $review['id'] }}, 'kept')" class="btn-quiet">Google kaldırmadı</button><a href="{{ \App\Services\Gbp\Desk\ReviewFlags::TOOL_URL }}" target="_blank" rel="noopener" class="btn-quiet">Durumu Google’da gör</a></div>
                                            @endif
                                        @endif
                                    </div>
                                @elseif ($canWrite && ! $review['answered'] && ($review['draft'] !== null || array_key_exists('r'.$review['id'], $replies) || $review['draft_state'] === 'running' || ($action !== null && $busy)))
                                    <button type="button" wire:click="startFlag({{ $review['id'] }})" @class(['btn-quiet mt-1 -ml-2.5', 'text-gray-500!' => ! $low])>Kaldırılmasını iste</button>
                                @endif
                            </div>
                        </article>
                    @empty
                        <x-ta.empty-state size="sm" :title="$status === 'bekleyen' ? 'Yanıt bekleyen yorum yok.' : 'Bu süzgeçte yorum yok.'" />
                    @endforelse
                </div>
                @if (count($reviews) < $total)
                    <div x-data x-intersect.margin.400px="$wire.more()" class="flex justify-center pt-1">
                        <button type="button" wire:click="more" wire:loading.attr="disabled" class="btn"><span wire:loading.remove wire:target="more">Daha fazla yorum ({{ $total - count($reviews) }})</span><span wire:loading wire:target="more">Yükleniyor…</span></button>
                    </div>
                @endif
            </section>

            @if ($previewOpen)
                <div class="fixed inset-0 z-[100000] flex items-start justify-center overflow-y-auto bg-gray-900/50 p-4" wire:key="review-preview" x-data x-on:keydown.escape.window="$wire.closePreview()" data-testid="gbp-review-preview">
                    <div class="my-8 w-full max-w-3xl rounded-2xl bg-white shadow-theme-lg dark:bg-gray-900">
                        <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                            <div>
                                <h2 class="text-[15px] font-semibold text-gray-900 dark:text-white">Yayımlamadan önce oku</h2>
                                <p class="mt-0.5 text-[13px] text-gray-500">{{ count($preview) }} yorum. Her yanıt ayrı gönderilir ve tek tek geri alınabilir. Boş yanıtlar atlanır.</p>
                            </div>
                            <button type="button" wire:click="closePreview" class="btn-quiet">Kapat</button>
                        </div>
                        <div class="max-h-[60vh] overflow-y-auto">
                            @foreach ($preview as $row)
                                <div wire:key="pv-{{ $row['id'] }}" class="border-t border-gray-200 px-5 py-4 first:border-t-0 dark:border-gray-800">
                                    <p class="text-[13px] text-gray-500"><span @class(['font-medium', 'text-warning-600 dark:text-warning-400' => ($row['rating'] ?? 5) <= 2])>{{ $row['rating'] !== null ? str_repeat('★', $row['rating']) : '—' }}</span> {{ $row['reviewer'] }}, {{ $names[$row['asset_id']] ?? '—' }}</p>
                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $row['comment'] !== '' ? \Illuminate\Support\Str::limit($row['comment'], 300) : 'Yalnız puan.' }}</p>
                                    <div class="mt-2 border-l-2 border-gray-300 pl-3 text-sm text-gray-900 dark:border-gray-600 dark:text-white">
                                        <p class="text-[13px] font-medium text-gray-500">İşletmenin yanıtı</p>
                                        <p class="whitespace-pre-line break-words">{{ $row['text'] !== '' ? $row['text'] : '—' }}</p>
                                    </div>
                                    @foreach ($row['warnings'] as $warning)<p class="mt-1.5 text-[13px] text-warning-700 dark:text-warning-300">{{ $warning }}</p>@endforeach
                                </div>
                            @endforeach
                        </div>
                        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-gray-200 px-5 py-4 dark:border-gray-800">
                            <button type="button" wire:click="closePreview" class="btn-quiet">Düzenlemeye dön</button>
                            <button type="button" wire:click="publishSelected" wire:confirm="{{ $readyCount }} yanıt Google’da yayınlansın mı?" @disabled($readyCount === 0) class="btn-primary">Yayımla ({{ $readyCount }})</button>
                        </div>
                    </div>
                </div>
            @endif
        @else
            {{-- Yorum isteme: per profile the Google review link, a QR code, the message to send and a printable card. --}}
            @forelse ($groups as $brandName => $locations)
                <section>
                    <div class="section-head">
                        <h2 class="section-title flex min-w-0 items-center gap-2"><x-ta.brand-avatar :name="(string) $brandName" :logo="$locations->first()?->brand?->logo_url" size="sm" /> <span class="min-w-0 break-words">{{ $brandName }} <span class="section-count">{{ $locations->count() }}</span></span></h2>
                    </div>
                    <ul class="card">
                        @foreach ($locations as $location)
                            @php
                                $s = $stats[$location->id] ?? null;
                                $kit = $kits[$location->id] ?? ['link' => null, 'qr' => null];
                                $text = $kit['link'] ? \App\Services\Gbp\Desk\ReviewDesk::requestMessage((string) ($location->brand?->name ?: $names[$location->id]), $kit['link']) : null;
                            @endphp
                            <li wire:key="kit-{{ $location->id }}" class="card-row flex-wrap items-start gap-4 py-4 sm:flex-nowrap" x-data="{ copied: '' }">
                                <div class="h-24 w-24 shrink-0 rounded-lg border border-gray-200 bg-white p-1 dark:border-gray-700 [&_svg]:h-full [&_svg]:w-full">
                                    @if ($kit['qr']){!! $kit['qr'] !!}@else<span class="flex h-full items-center justify-center text-center text-xs text-gray-500">QR yok</span>@endif
                                </div>
                                <div class="min-w-0 flex-1 space-y-2">
                                    <div>
                                        <p class="break-words text-sm font-medium text-gray-900 dark:text-white" title="{{ $location->name }}">{{ $names[$location->id] }}</p>
                                        @if ($s)
                                            <p class="mt-0.5 flex flex-wrap items-center gap-x-3 text-[13px] text-gray-500"><span>{{ '90 günde '.$s['recent'].' yorum'.($s['average'] !== null ? ', ort. '.number_format($s['average'], 1, ',', '').' ★' : '').($s['reply_rate'] !== null ? ', yanıt oranı %'.$s['reply_rate'] : '') }}</span>
                                                @if ($s['recent'] < 3)<x-ta.pill tone="look">Yorum az geliyor</x-ta.pill>@endif</p>
                                        @endif
                                    </div>
                                    @if ($kit['link'])
                                        <div class="flex flex-wrap items-center gap-2">
                                            <input type="text" readonly value="{{ $kit['link'] }}" class="h-8 min-w-0 flex-1 {{ $input }} text-[13px]">
                                            <button type="button" x-on:click="navigator.clipboard.writeText(@js($kit['link'])); copied = 'link'" class="btn"><span x-text="copied === 'link' ? 'Kopyalandı' : 'Bağlantıyı kopyala'"></span></button>
                                        </div>
                                        <p class="break-words text-[13px] text-gray-700 dark:text-gray-300">{{ $text }}</p>
                                        <div class="flex flex-wrap gap-1">
                                            <button type="button" x-on:click="navigator.clipboard.writeText(@js($text)); copied = 'text'" class="btn-quiet -ml-2.5"><span x-text="copied === 'text' ? 'Kopyalandı' : 'Mesajı kopyala'"></span></button>
                                            <a href="https://wa.me/?text={{ rawurlencode((string) $text) }}" target="_blank" rel="noopener" class="btn-quiet">WhatsApp’ta aç</a>
                                            <a href="{{ route('operator.gbp-review-card', ['assetId' => $location->id]) }}" target="_blank" class="btn-quiet">Yazdırılabilir kart</a>
                                        </div>
                                    @else
                                        <p class="text-[13px] text-gray-500">Google yer kimliği (place id) henüz toplanmadı; profil verisi bir sonraki toplamada gelince yorum bağlantısı burada görünür.</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @empty
                <div class="card"><x-ta.empty-state size="sm" title="Operasyonel markaya bağlı İşletme Profili yok." /></div>
            @endforelse
        @endif
    </div>
</div>
