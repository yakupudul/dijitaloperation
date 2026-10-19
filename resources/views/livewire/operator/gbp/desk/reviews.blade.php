@php
    // Review topics mix every brand's words when several brands are listed ("Tül perde" under a dental clinic): show them for one brand.
    $topicsForOneBrand = $brand !== null || $this->location !== null || $asset !== null || $groups->count() <= 1;
    $pickLink = 'link-neutral whitespace-nowrap';
@endphp
<div class="space-y-5 dark:text-gray-200" data-gbp-reviews>
    @if ($asset === null)
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">İşletme profilleri</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500">Yorum sayısı, yorumların tazeliği ve yanıtlanması yerel sıralamada en güçlü sinyallerden. Bütün profillerin yorumları tek ekranda: yanıt bekleyenleri seç, yapay zekâ taslak yazsın ya da ortak bir yanıt ver, ön izle ve toplu yayımla (her yanıt geri alınabilir). “Yorum isteme” bölümünde her şube için Google yorum bağlantısı, QR kod, müşteriye gönderilecek mesaj ve yazdırılabilir kart var.</p>
        </div>
        @include('livewire.operator.gbp.partials.brand-filter')
    </header>
    @include('livewire.operator.gbp.partials.desk-tabs', ['active' => 'operator.gbp-reviews', 'brandFilter' => $brand])
    @endif
    @include('livewire.operator.gbp.partials.desk-message')

    @if ($approvalUrl || $approvalLinks->isNotEmpty())
        <section class="surface p-4 text-sm" data-testid="gbp-approval-links">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Marka onay bağlantıları</p>
            @if ($approvalUrl)
                <div class="mt-2 flex flex-wrap items-center gap-2 rounded-lg bg-gray-50 p-2 dark:bg-white/[0.03]" x-data="{ copied: false }">
                    <input type="text" readonly value="{{ $approvalUrl }}" class="min-w-0 flex-1 rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" x-on:focus="$el.select()">
                    <button type="button" x-on:click="navigator.clipboard.writeText(@js($approvalUrl)); copied = true" class="rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-gray-800 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-transparent dark:text-gray-200 dark:ring-gray-700" x-text="copied ? 'Kopyalandı' : 'Bağlantıyı kopyala'">Bağlantıyı kopyala</button>
                    <a href="https://wa.me/?text={{ rawurlencode('Google yorumlarınıza vermeyi önerdiğimiz yanıtlar: '.$approvalUrl) }}" target="_blank" rel="noopener" class="link-neutral text-xs font-medium">WhatsApp ile paylaş</a>
                </div>
            @endif
            <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($approvalLinks as $link)
                    @php
                        $decided = collect($link->items)->whereNotNull('decision');
                        $state = match (true) {
                            $link->status === \App\Models\GbpReviewApproval::CLOSED => 'Kapatıldı',
                            ! $link->isUsable() => 'Süresi doldu',
                            $link->answered_at !== null => 'Marka yanıtladı · '.$decided->where('decision', 'ok')->count().' uygun, '.$decided->where('decision', 'edit')->count().' düzeltildi, '.$decided->where('decision', 'skip')->count().' istenmedi',
                            default => 'Markada bekliyor',
                        };
                    @endphp
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 py-1.5 text-xs" wire:key="approval-{{ $link->id }}">
                        <span class="text-gray-400">{{ $link->created_at?->timezone('Europe/Istanbul')->format('d.m H:i') }}</span>
                        <span class="font-medium text-gray-800 dark:text-gray-200">{{ $link->brand?->name }} · {{ count($link->items) }} yanıt</span>
                        <span @class(['font-semibold', 'text-success-600' => $link->answered_at !== null && $link->isUsable(), 'text-warning-600' => $link->answered_at === null && $link->isUsable(), 'text-gray-500' => ! $link->isUsable()])>{{ $state }}</span>
                        @if ($link->note)<span class="w-full text-gray-600 dark:text-gray-300">Markanın notu: {{ $link->note }}</span>@endif
                        @if ($link->isUsable())
                            <span class="ml-auto flex gap-3">
                                <button type="button" x-data x-on:click="navigator.clipboard.writeText(@js(\App\Services\Gbp\Desk\ReviewApprovals::url($link)))" class="link-neutral">Kopyala</button>
                                <a href="{{ \App\Services\Gbp\Desk\ReviewApprovals::url($link) }}" target="_blank" rel="noopener" class="link-neutral">Aç</a>
                                <button type="button" wire:click="closeApproval({{ $link->id }})" wire:confirm="Bağlantı kapatılsın mı? Marka artık açamaz." class="link-neutral">Kapat</button>
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Unanswered reviews are worth a look, not money loss: plain metric cards, no red number. --}}
    <section class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <x-ta.metric-card label="Yanıtsız yorum" :value="number_format($totals['unanswered'], 0, ',', '.')" class="min-w-0">@if ($totals['unanswered'] > 0)<x-ta.pill tone="look">yanıt bekliyor</x-ta.pill>@endif</x-ta.metric-card>
        <x-ta.metric-card :label="\App\Services\Gbp\GbpDailyWorkspace::REPLY_SLA_HOURS.' saatten uzun bekleyen'" :value="number_format($totals['late'], 0, ',', '.')" class="min-w-0" />
        <x-ta.metric-card :label="'Son '.\App\Services\Gbp\Desk\ReviewDesk::WINDOW_DAYS.' günde gelen yorum'" :value="number_format($totals['recent'], 0, ',', '.')" class="col-span-2 min-w-0 sm:col-span-1" />
    </section>

    <div class="inline-flex max-w-full flex-wrap rounded-lg bg-gray-100 p-1 text-sm dark:bg-white/[0.06]">
        <button type="button" wire:click="setSection('yanit')" @class(['rounded-md px-3 py-1 font-medium', 'bg-white shadow-sm text-gray-900 dark:bg-gray-800 dark:text-white' => $section === 'yanit', 'text-gray-500' => $section !== 'yanit'])>Yorumlar ({{ $totals['unanswered'] }} yanıtsız)</button>
        <button type="button" wire:click="setSection('iste')" @class(['rounded-md px-3 py-1 font-medium', 'bg-white shadow-sm text-gray-900 dark:bg-gray-800 dark:text-white' => $section === 'iste', 'text-gray-500' => $section !== 'iste'])>Yorum isteme</button>
    </div>

    @if ($chips !== [])
        <nav class="flex gap-1.5 overflow-x-auto pb-1 text-xs" aria-label="İşletme" data-testid="gbp-review-locations">
            <button type="button" wire:click="setLocation" @class(['shrink-0 rounded-full px-3 py-1 font-medium ring-1 ring-inset', 'bg-gray-900 text-white ring-gray-900 dark:bg-white dark:text-gray-900' => $this->location === null, 'bg-white text-gray-600 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $this->location !== null])>Tüm işletmeler @if ($allUnanswered > 0)<span class="ml-1 rounded-full bg-gray-200 px-1.5 text-[10px] tabular-nums text-gray-700 dark:bg-white/15 dark:text-gray-200">{{ $allUnanswered }}</span>@endif</button>
            @foreach ($chips as $chip)
                <button type="button" wire:key="chip-{{ $chip['id'] }}" wire:click="setLocation({{ $chip['id'] }})" @class(['shrink-0 rounded-full px-3 py-1 font-medium ring-1 ring-inset', 'bg-gray-900 text-white ring-gray-900 dark:bg-white dark:text-gray-900' => $this->location === $chip['id'], 'bg-white text-gray-600 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $this->location !== $chip['id']])>{{ $chip['name'] }} @if ($chip['unanswered'] > 0)<span class="ml-1 rounded-full bg-gray-200 px-1.5 text-[10px] tabular-nums text-gray-700 dark:bg-white/15 dark:text-gray-200">{{ $chip['unanswered'] }}</span>@endif</button>
            @endforeach
        </nav>
    @endif

    @if ($section === 'yanit' && $lowOpen > 0 && ! ($status === 'bekleyen' && $rating === 'low'))
        <section class="tone-look flex flex-wrap items-center gap-3 rounded-xl p-3 text-sm" data-testid="gbp-low-open">
            <span class="min-w-0 flex-1"><span class="font-semibold">{{ $lowOpen }} kötü yorum (1–2 ★) yanıt bekliyor.</span>
                <span class="opacity-80">Kötü yorumu yanıtsız bırakmak, onu okuyan herkese “ilgilenmiyorlar” der. Önce bunları yanıtlayın.</span></span>
            <button type="button" wire:click="showLowOpen" class="w-full rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-gray-800 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:w-auto dark:bg-gray-900 dark:text-gray-200 dark:ring-gray-700">Kötü yorumları göster</button>
        </section>
    @endif

    @if ($section === 'yanit' && $card !== [])
        <details class="surface" data-testid="gbp-review-card">
            <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-gray-900 dark:text-white">Şube karnesi <span class="font-normal text-gray-500">· yanıt oranı, yanıt süresi, son {{ \App\Services\Gbp\Desk\ReviewDesk::WINDOW_DAYS }} gün puanı</span></summary>
            <div class="overflow-x-auto px-4 pb-4">
                <table class="w-full min-w-[40rem] text-sm">
                    <thead class="text-left text-xs text-gray-500"><tr><th class="py-2 pr-3 font-medium">Şube</th><th class="px-3 font-medium">Yorum</th><th class="px-3 font-medium">Yanıt oranı</th><th class="px-3 font-medium">Ort. yanıt süresi</th><th class="px-3 font-medium">Yanıtsız</th><th class="px-3 font-medium">Son {{ \App\Services\Gbp\Desk\ReviewDesk::WINDOW_DAYS }} gün</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($card as $row)
                            @php
                                $hours = $row['reply_hours'] ?? null;
                                $trend = ($row['average'] ?? null) !== null && ($row['previous_average'] ?? null) !== null ? round($row['average'] - $row['previous_average'], 1) : null;
                            @endphp
                            <tr wire:key="card-{{ $row['id'] }}">
                                <td class="py-2 pr-3"><button type="button" wire:click="setLocation({{ $row['id'] }})" class="text-left font-medium text-gray-800 hover:underline dark:text-gray-200">{{ $row['name'] }}</button></td>
                                <td class="px-3 tabular-nums">{{ $row['total'] }}</td>
                                <td @class(['px-3 tabular-nums', 'font-semibold text-warning-700 dark:text-warning-400' => ($row['reply_rate'] ?? 100) < 50, 'text-success-600' => ($row['reply_rate'] ?? 0) >= 90])>{{ ($row['reply_rate'] ?? null) === null ? '—' : $row['reply_rate'].'%' }}</td>
                                <td class="px-3 tabular-nums">{{ $hours === null ? '—' : ($hours < 48 ? $hours.' saat' : intdiv($hours, 24).' gün') }}</td>
                                <td @class(['px-3 tabular-nums', 'font-semibold text-warning-700 dark:text-warning-400' => ($row['unanswered'] ?? 0) > 0])>{{ $row['unanswered'] ?? 0 }}@if (($row['low_open'] ?? 0) > 0) <span class="text-xs font-normal">({{ $row['low_open'] }} kötü)</span>@endif</td>
                                <td class="px-3 tabular-nums">{{ $row['recent'] ?? 0 }} yorum @if (($row['average'] ?? null) !== null)· {{ number_format($row['average'], 1, ',', '.') }} ★@endif
                                    @if ($trend !== null && $trend != 0)<span @class(['text-xs font-semibold', 'text-success-600' => $trend > 0, 'text-error-600' => $trend < 0])>{{ $trend > 0 ? '+' : '' }}{{ number_format($trend, 1, ',', '.') }}</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-gray-500">Yanıt süresi: yorumdan yanıta geçen ortalama süre (Google’ın tarihine göre). Puan değişimi önceki {{ \App\Services\Gbp\Desk\ReviewDesk::WINDOW_DAYS }} güne göre.</p>
            </div>
        </details>
    @endif

    @if ($section === 'yanit' && ! $topicsForOneBrand && ($topics['doctors'] !== [] || $topics['services'] !== []))
        <p class="text-xs text-gray-500" data-testid="gbp-review-topics-hint">Yorumlarda neler konuşulduğu marka seçilince görünür (markaların konuları karışmasın diye).</p>
    @elseif ($section === 'yanit' && ($topics['doctors'] !== [] || $topics['services'] !== []))
        <section class="surface space-y-2 p-3 text-xs" data-testid="gbp-review-topics">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Yorumlarda neler konuşuluyor</p>
            @foreach (['doctors' => 'Hekimler', 'services' => 'Hizmetler'] as $kind => $label)
                @if ($topics[$kind] !== [])
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="w-full shrink-0 text-gray-500 sm:w-20">{{ $label }}</span>
                        @foreach ($topics[$kind] as $topic)
                            @php $word = $kind === 'doctors' ? $topic['name'] : $topic['word']; @endphp
                            <button type="button" wire:click="searchFor(@js($word))" @class(['rounded-full px-2.5 py-1 font-medium ring-1 ring-inset', 'bg-gray-900 text-white ring-gray-900 dark:bg-white dark:text-gray-900 dark:ring-white' => \App\Services\Gbp\Desk\ReviewDesk::lower($search) === \App\Services\Gbp\Desk\ReviewDesk::lower($word), 'bg-white text-gray-700 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700' => \App\Services\Gbp\Desk\ReviewDesk::lower($search) !== \App\Services\Gbp\Desk\ReviewDesk::lower($word)])>
                                {{ $topic['name'] }} · {{ $topic['count'] }} yorum @if ($topic['average'] !== null)· {{ number_format($topic['average'], 1, ',', '.') }} ★@endif
                            </button>
                        @endforeach
                    </div>
                @endif
            @endforeach
        </section>
    @endif

    @if ($section === 'yanit')
        {{-- One compact filter bar that wraps (2026-10-19): search, sort, period, status, rating; the pick links are a quiet second line. --}}
        <section class="surface space-y-2 p-3 text-xs" data-testid="gbp-review-filters">
            <div class="flex flex-wrap items-center gap-2">
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="Yorumda ya da adda ara (ör. implant, Zeliha)" class="w-full rounded-lg border-gray-300 text-xs sm:w-64 dark:border-gray-700 dark:bg-gray-900" data-testid="gbp-review-search">
                <span class="inline-flex max-w-full flex-wrap rounded-lg bg-gray-100 p-0.5 dark:bg-white/[0.06]">
                    @foreach (\App\Services\Gbp\Desk\ReviewDesk::STATUSES as $key => $label)
                        <button type="button" wire:click="setStatus('{{ $key }}')" @class(['rounded-md px-2.5 py-1 font-medium', 'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-white' => $status === $key, 'text-gray-500' => $status !== $key])>{{ $label }}</button>
                    @endforeach
                </span>
                <span class="inline-flex max-w-full flex-wrap rounded-lg bg-gray-100 p-0.5 dark:bg-white/[0.06]">
                    @foreach (['' => 'Tüm puanlar', 'low' => '1–2 ★', 'mid' => '3 ★', 'high' => '4–5 ★'] as $key => $label)
                        <button type="button" wire:click="setRating('{{ $key }}')" @class(['rounded-md px-2.5 py-1 font-medium', 'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-white' => $rating === $key, 'text-gray-500' => $rating !== $key])>{{ $label }}</button>
                    @endforeach
                </span>
                <span class="inline-flex max-w-full flex-wrap rounded-lg bg-gray-100 p-0.5 dark:bg-white/[0.06]">
                    @foreach (\App\Services\Gbp\Desk\ReviewDesk::SORTS as $key => $label)
                        <button type="button" wire:click="setSort('{{ $key }}')" @class(['rounded-md px-2.5 py-1 font-medium', 'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-white' => $sort === $key, 'text-gray-500' => $sort !== $key])>{{ $label }}</button>
                    @endforeach
                </span>
                <button type="button" wire:click="toggleRecent" @class(['rounded-full px-3 py-1 font-medium ring-1 ring-inset', 'bg-gray-900 text-white ring-gray-900 dark:bg-white dark:text-gray-900 dark:ring-white' => $recentOnly, 'bg-white text-gray-600 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => ! $recentOnly])>Son {{ \App\Services\Gbp\Desk\ReviewDesk::WINDOW_DAYS }} gün</button>
                <span class="tabular-nums text-gray-500 sm:ml-auto">{{ count($reviews) }} / {{ $total }} yorum</span>
            </div>
            @if (($status === 'bekleyen' && $total > 0) || ($canWrite && $openCount > 0))
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-gray-100 pt-2 dark:border-gray-800">
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
        </section>

        @if ($canWrite && $pickedCount > 0)
            <section class="sticky top-2 z-20 space-y-2 rounded-xl bg-white p-3 shadow-lg ring-1 ring-inset ring-gray-300 dark:bg-gray-900 dark:ring-gray-700" data-testid="gbp-review-bulk">
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="font-semibold text-gray-900 dark:text-white">{{ $pickedCount }} yorum seçili</span>
                    <span class="text-xs text-gray-500">· {{ $readyCount }} yanıtı hazır (gönderilmedi) · {{ $toDraftCount }} taslaksız</span>
                    <span class="ml-auto flex flex-wrap gap-2">
                        @if ($toDraftCount > 0)<button type="button" wire:click="draftSelected" class="rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600" title="Yalnız taslağı olmayanlara yazar; hazır yanıtlara dokunmaz">AI ile taslak yaz ({{ min($toDraftCount, \App\Services\Gbp\Desk\ReviewDesk::DRAFT_BATCH) }})</button>@endif
                        <button type="button" wire:click="sendToBrand" class="rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600" title="Marka giriş yapmadan açar; her yanıtı onaylar, düzeltir ya da istemez">Markaya onaya gönder</button>
                        @if ($readyCount > 0)<button type="button" wire:click="deleteSelectedDrafts" wire:confirm="Seçilen {{ $readyCount }} yorumun yanıt taslağı silinsin mi?" class="rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600" data-testid="gbp-delete-drafts">Taslakları sil ({{ $readyCount }})</button>@endif
                        <a href="{{ $this->pdfUrl() }}" class="rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600" title="Hazır yanıtları markanın onayına göndermek için">PDF</a>
                        <button type="button" wire:click="openPreview" class="rounded-lg bg-brand-500 px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-600">Ön izle ve yayımla ({{ $readyCount }})</button>
                    </span>
                </div>
                <div class="flex flex-wrap items-start gap-2">
                    <textarea wire:model="bulkText" rows="2" maxlength="4000" class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900" placeholder="Seçilenlere ortak yanıt (isteğe bağlı). {ad} yorumu yazanın adı olur: “Teşekkür ederiz {ad}, sizi yeniden ağırlamaktan mutluluk duyarız.”"></textarea>
                    <button type="button" wire:click="fillSelected" class="rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600">Seçilenlere yaz</button>
                </div>
            </section>
        @endif

        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4" data-testid="gbp-review-grid" @if ($drafting) wire:poll.10s @endif>
            @forelse ($reviews as $review)
                @php
                    $action = $review['action'];
                    $busy = \App\Services\Gbp\Desk\ReviewDesk::busy($review);
                    $isPicked = in_array($review['id'], array_map('intval', $selected), true);
                    $failedDraft = is_string($review['draft_state']) && str_starts_with($review['draft_state'], 'failed');
                @endphp
                <article wire:key="rv-{{ $review['id'] }}" x-data="{ more: false }" @class(['flex min-w-0 flex-col rounded-xl bg-white p-3 ring-1 ring-inset dark:bg-gray-900', 'ring-2 ring-gray-900 dark:ring-white' => $isPicked, 'ring-gray-200 dark:ring-gray-800' => ! $isPicked])>
                    <div class="flex items-start gap-2 text-xs">
                        @if ($canWrite && ! $busy)
                            <input type="checkbox" wire:model.live="selected" value="{{ $review['id'] }}" class="mt-0.5 rounded border-gray-300 text-brand-600" aria-label="Seç">
                        @endif
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span @class(['font-semibold', 'text-warning-600 dark:text-warning-400' => ($review['rating'] ?? 5) <= 2, 'text-gray-500' => ($review['rating'] ?? 5) === 3, 'text-success-600' => ($review['rating'] ?? 0) >= 4]) aria-label="{{ $review['rating'] }} yıldız">{{ $review['rating'] !== null ? str_repeat('★', $review['rating']).str_repeat('☆', 5 - $review['rating']) : '—' }}</span>
                                @if ($review['answered'])
                                    <x-ta.pill tone="ok" class="ml-auto">Yanıtlandı</x-ta.pill>
                                @else
                                    <span @class(['ml-auto', 'font-medium text-warning-600' => $review['late'], 'text-gray-500' => ! $review['late']])>{{ $review['waiting'] }}</span>
                                @endif
                            </div>
                            <p class="truncate text-gray-700 dark:text-gray-300">{{ $review['reviewer'] }} · <span class="text-gray-500">{{ $review['date'] }}</span></p>
                            <p class="truncate text-gray-500" title="{{ $names[$review['asset_id']] ?? '' }}">{{ $names[$review['asset_id']] ?? '—' }}</p>
                            @if (! $review['answered'] && isset($brandAnswers[$review['id']]))
                                @php $answer = $brandAnswers[$review['id']]; @endphp
                                <span @class(['mt-0.5 inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold', 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300' => in_array($answer['state'], ['ok', 'edit'], true), 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300' => $answer['state'] === 'skip', 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-300' => $answer['state'] === 'waiting']) data-testid="gbp-brand-answer">{{ $answer['label'] }}</span>
                            @endif
                        </div>
                    </div>
                    @if ($review['comment'] !== '')
                        <p class="mt-2 whitespace-pre-line break-words text-sm text-gray-800 dark:text-gray-200" :class="more ? '' : 'line-clamp-4'">{{ $review['comment'] }}</p>
                        @if (mb_strlen($review['comment']) > 180)<button type="button" x-on:click="more = ! more" class="link-neutral self-start text-xs" x-text="more ? 'Daha az' : 'Devamı'"></button>@endif
                    @else
                        <p class="mt-2 text-sm italic text-gray-400">Yalnız puan, yorum metni yok.</p>
                    @endif

                    <div class="mt-auto pt-3">
                        @if ($review['answered'])
                            @if ($editing === $review['id'])
                                <textarea wire:model="editText" rows="4" maxlength="4000" class="w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" data-testid="gbp-edit-reply"></textarea>
                                <span class="mt-1 flex flex-wrap gap-3 text-xs font-medium">
                                    <button type="button" wire:click="saveEdit" wire:confirm="Google’daki yanıt bu metinle değişsin mi? (Geri alınabilir.)" class="font-semibold text-gray-900 hover:underline dark:text-white">Güncelle</button>
                                    <button type="button" wire:click="cancelEdit" class="text-gray-500 hover:underline">Vazgeç</button>
                                </span>
                            @else
                                <p class="break-words rounded-lg bg-gray-50 p-2 text-xs text-gray-700 dark:bg-white/[0.03] dark:text-gray-300"><span class="font-semibold">Yanıt:</span> {{ \Illuminate\Support\Str::limit($review['reply'], 280) }}</p>
                                <span class="mt-1 flex flex-wrap gap-3 text-xs font-medium">
                                    @if ($canWrite && ($action === null || ! in_array($action['status'], ['queued', 'running', 'undoing'], true)))
                                        <button type="button" wire:click="startEdit({{ $review['id'] }})" class="link-neutral">Düzenle</button>
                                    @endif
                                    @if ($canWrite && $action !== null && $action['undoable'])
                                        <button type="button" wire:click="undoReply({{ $action['id'] }})" wire:confirm="Yanıt Google’dan geri alınsın mı?" class="link-neutral">Geri al</button>
                                    @endif
                                </span>
                            @endif
                        @elseif ($action !== null && $busy)
                            <p class="text-xs text-gray-500" @if (in_array($action['status'], ['queued', 'running'], true)) wire:poll.5s @endif>Yanıt: {{ $action['label'] }}</p>
                        @elseif ($canWrite)
                            @if ($action !== null && $action['status'] === 'failed')<p class="mb-1 text-xs text-error-600">Gönderilemedi: {{ \Illuminate\Support\Str::limit((string) $action['error'], 160) }}</p>@endif
                            @if ($review['draft'] !== null || array_key_exists('r'.$review['id'], $replies))
                                <textarea wire:model.blur="replies.r{{ $review['id'] }}" rows="3" maxlength="4000" class="w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" placeholder="Yanıt"></textarea>
                                <span class="mt-1 flex flex-wrap gap-3">
                                    <button type="button" wire:click="send({{ $review['id'] }})" wire:confirm="Bu yanıt Google’da yayınlansın mı? (Geri alınabilir.)" class="text-xs font-semibold text-gray-900 hover:underline dark:text-white">Yalnız bunu gönder</button>
                                    <button type="button" wire:click="deleteDraft({{ $review['id'] }})" wire:confirm="Bu yorumun yanıt taslağı silinsin mi?" class="text-xs font-medium text-gray-500 hover:text-error-600 hover:underline" data-testid="gbp-delete-draft">Taslağı sil</button>
                                </span>
                            @elseif ($review['draft_state'] === 'running')
                                <p class="text-xs text-gray-500">Taslak yazılıyor…</p>
                            @else
                                @if ($failedDraft)<p class="text-xs text-error-600">{{ \Illuminate\Support\Str::after((string) $review['draft_state'], 'failed: ') }}</p>@endif
                                <span class="flex flex-wrap gap-3">
                                    <button type="button" wire:click="draftOne({{ $review['id'] }})" class="text-xs font-semibold text-gray-900 hover:underline dark:text-white">AI taslağı</button>
                                    <button type="button" wire:click="$set('replies.r{{ $review['id'] }}', '')" class="text-xs font-medium text-gray-600 hover:underline dark:text-gray-300">Kendim yazayım</button>
                                </span>
                            @endif
                        @endif
                    </div>
                    @php $flag = $flags[$review['id']] ?? null; @endphp
                    @if ($flag || $flagging === $review['id'])
                        <div class="mt-2 rounded-lg bg-gray-50 p-2 text-xs ring-1 ring-inset ring-gray-200 dark:bg-white/[0.03] dark:ring-gray-800" data-testid="gbp-review-flag">
                            @if ($flagging === $review['id'])
                                <p class="font-semibold text-gray-900 dark:text-white">Neden kaldırılmalı? (Google yalnız kurallarına aykırı yorumu kaldırır; kötü puan tek başına neden değildir.)</p>
                                <select wire:model="flagReason" class="mt-1 w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900">
                                    <option value="">Seçin</option>
                                    @foreach (\App\Models\GbpReviewFlag::REASONS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                                </select>
                                <textarea wire:model="flagNote" rows="2" maxlength="1000" class="mt-1 w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" placeholder="Kanıt / not (ör. kayıtlarda bu kişi yok, rakip firmanın çalışanı)"></textarea>
                                <div class="mt-1 flex gap-3 font-medium"><button type="button" wire:click="saveFlag" class="text-gray-900 hover:underline dark:text-white">Talebi hazırla</button><button type="button" wire:click="$set('flagging', null)" class="text-gray-500 hover:underline">Vazgeç</button></div>
                            @else
                                <p><span class="font-semibold text-gray-900 dark:text-white">{{ $flag['status_label'] }}</span>@if ($flag['reported_at']) · {{ $flag['reported_at'] }}@endif · {{ $flag['reason_label'] }}</p>
                                @if (in_array($flag['status'], ['draft', 'kept'], true))
                                    <p class="mt-1 text-gray-600 dark:text-gray-300">Google’a gönderilecek açıklama:</p>
                                    <p class="mt-0.5 rounded bg-white p-1.5 text-gray-800 dark:bg-gray-900 dark:text-gray-200" x-data>{{ \App\Services\Gbp\Desk\ReviewFlags::reportText($flag['reason'], $flag['note']) }}</p>
                                    <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 font-medium">
                                        <button type="button" x-data x-on:click="navigator.clipboard.writeText(@js(\App\Services\Gbp\Desk\ReviewFlags::reportText($flag['reason'], $flag['note'])))" class="link-neutral">Metni kopyala</button>
                                        <a href="{{ \App\Services\Gbp\Desk\ReviewFlags::TOOL_URL }}" target="_blank" rel="noopener" class="link-neutral">Google’ın yorum yönetim aracı ↗</a>
                                        @if (($places[$review['asset_id']] ?? '') !== '')<a href="https://www.google.com/maps/place/?q=place_id:{{ $places[$review['asset_id']] }}" target="_blank" rel="noopener" class="link-neutral">Haritada aç ↗</a>@endif
                                    </div>
                                    @if ($canWrite)<div class="mt-1 flex gap-3 font-medium"><button type="button" wire:click="markReported({{ $review['id'] }})" class="text-gray-900 hover:underline dark:text-white">Google’a bildirdim</button><button type="button" wire:click="startFlag({{ $review['id'] }})" class="text-gray-500 hover:underline">Düzenle</button><button type="button" wire:click="closeFlag({{ $review['id'] }}, 'cancel')" wire:confirm="Kaldırma talebi silinsin mi?" class="text-gray-500 hover:underline">Sil</button></div>@endif
                                @elseif ($flag['status'] === 'reported' && $canWrite)
                                    <p class="mt-1 text-gray-600 dark:text-gray-300">Google genelde birkaç gün içinde karar verir; yorum kalkınca bir sonraki tam yorum toplamasında “Kaldırıldı” olur. Google reddederse aynı araçtan bir kez itiraz edebilirsiniz.</p>
                                    <div class="mt-1 flex gap-3 font-medium"><button type="button" wire:click="closeFlag({{ $review['id'] }}, 'kept')" class="text-gray-600 hover:underline">Google kaldırmadı</button><a href="{{ \App\Services\Gbp\Desk\ReviewFlags::TOOL_URL }}" target="_blank" rel="noopener" class="link-neutral">Durumu Google’da gör ↗</a></div>
                                @endif
                            @endif
                        </div>
                    @elseif ($canWrite && ! $review['answered'])
                        <button type="button" wire:click="startFlag({{ $review['id'] }})" @class(['mt-2 self-start text-[11px] hover:underline', 'text-gray-600 dark:text-gray-300' => ($review['rating'] ?? 5) <= 2, 'text-gray-400' => ($review['rating'] ?? 5) > 2])>Kaldırılmasını iste</button>
                    @endif
                </article>
            @empty
                <x-ta.empty-state size="sm" :title="$status === 'bekleyen' ? 'Yanıt bekleyen yorum yok.' : 'Bu süzgeçte yorum yok.'" class="col-span-full" />
            @endforelse
        </section>
        @if (count($reviews) < $total)
            <div x-data x-intersect.margin.400px="$wire.more()" class="flex justify-center py-3">
                <button type="button" wire:click="more" wire:loading.attr="disabled" class="rounded-lg bg-white px-4 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600"><span wire:loading.remove wire:target="more">Daha fazla yorum ({{ $total - count($reviews) }})</span><span wire:loading wire:target="more">Yükleniyor…</span></button>
            </div>
        @endif

        @if ($previewOpen)
            <div class="fixed inset-0 z-[100000] flex items-start justify-center overflow-y-auto bg-gray-900/50 p-4" wire:key="review-preview" x-data x-on:keydown.escape.window="$wire.closePreview()" data-testid="gbp-review-preview">
                <div class="my-8 w-full max-w-3xl rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-900">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Yayımlamadan önce oku</h2>
                            <p class="text-xs text-gray-500">{{ count($preview) }} yorum · her yanıt ayrı gönderilir ve tek tek geri alınabilir. Boş yanıtlar atlanır.</p>
                        </div>
                        <button type="button" wire:click="closePreview" class="text-sm text-gray-500 hover:underline">Kapat</button>
                    </div>
                    <div class="mt-4 max-h-[60vh] space-y-3 overflow-y-auto pr-1">
                        @foreach ($preview as $row)
                            <div wire:key="pv-{{ $row['id'] }}" class="rounded-xl p-3 ring-1 ring-inset ring-gray-200 dark:ring-gray-700">
                                <p class="text-xs text-gray-500"><span @class(['font-semibold', 'text-warning-600 dark:text-warning-400' => ($row['rating'] ?? 5) <= 2, 'text-success-600' => ($row['rating'] ?? 0) >= 4])>{{ $row['rating'] !== null ? str_repeat('★', $row['rating']) : '—' }}</span> · {{ $row['reviewer'] }} · {{ $names[$row['asset_id']] ?? '—' }}</p>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $row['comment'] !== '' ? \Illuminate\Support\Str::limit($row['comment'], 300) : 'Yalnız puan.' }}</p>
                                <div class="mt-2 rounded-lg bg-gray-50 p-2 text-sm text-gray-900 dark:bg-white/[0.04] dark:text-white">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">İşletmenin yanıtı</p>
                                    <p class="whitespace-pre-line break-words">{{ $row['text'] !== '' ? $row['text'] : '—' }}</p>
                                </div>
                                @foreach ($row['warnings'] as $warning)<p class="mt-1 text-xs text-warning-700 dark:text-warning-300">{{ $warning }}</p>@endforeach
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-4 flex flex-wrap items-center justify-end gap-3">
                        <button type="button" wire:click="closePreview" class="text-sm font-medium text-gray-600 hover:underline">Düzenlemeye dön</button>
                        <button type="button" wire:click="publishSelected" wire:confirm="{{ $readyCount }} yanıt Google’da yayınlansın mı?" @disabled($readyCount === 0) class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white shadow-theme-xs hover:bg-brand-600 disabled:opacity-50">Yayımla ({{ $readyCount }})</button>
                    </div>
                </div>
            </div>
        @endif
    @else
        <section class="surface divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($groups as $brandName => $locations)
                <div class="flex items-center gap-2 bg-gray-50 px-4 py-2 text-sm font-semibold text-gray-800 dark:bg-white/[0.03] dark:text-gray-200"><x-ta.brand-avatar :name="(string) $brandName" size="xs" /> <span class="min-w-0 break-words">{{ $brandName }}</span> <span class="font-normal tabular-nums text-gray-500">· {{ $locations->count() }}</span></div>
                @foreach ($locations as $location)
                    @php
                        $s = $stats[$location->id] ?? null;
                        $kit = $kits[$location->id] ?? ['link' => null, 'qr' => null];
                        $text = $kit['link'] ? \App\Services\Gbp\Desk\ReviewDesk::requestMessage((string) ($location->brand?->name ?: $names[$location->id]), $kit['link']) : null;
                    @endphp
                    <div wire:key="kit-{{ $location->id }}" class="flex flex-wrap gap-4 px-4 py-4" x-data="{ copied: '' }">
                        <div class="h-28 w-28 shrink-0 rounded-lg bg-white p-1 ring-1 ring-inset ring-gray-200 [&_svg]:h-full [&_svg]:w-full">
                            @if ($kit['qr']){!! $kit['qr'] !!}@else<span class="flex h-full items-center justify-center text-center text-[11px] text-gray-400">QR yok</span>@endif
                        </div>
                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="min-w-0 break-words font-medium text-gray-900 dark:text-white" title="{{ $location->name }}">{{ $names[$location->id] }}</span>
                                @if ($s)
                                    <span class="text-xs text-gray-500">90 günde {{ $s['recent'] }} yorum @if ($s['average'] !== null)· ort. {{ number_format($s['average'], 1, ',', '') }} ★@endif @if ($s['reply_rate'] !== null)· yanıt oranı %{{ $s['reply_rate'] }}@endif</span>
                                    @if ($s['recent'] < 3)<x-ta.pill tone="look">Yorum az geliyor</x-ta.pill>@endif
                                @endif
                            </div>
                            @if ($kit['link'])
                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                    <input type="text" readonly value="{{ $kit['link'] }}" class="min-w-0 flex-1 rounded-lg border-gray-300 bg-gray-50 py-1 text-xs dark:border-gray-700 dark:bg-gray-900">
                                    <button type="button" x-on:click="navigator.clipboard.writeText(@js($kit['link'])); copied = 'link'" class="link-neutral font-medium"><span x-text="copied === 'link' ? 'Kopyalandı' : 'Bağlantıyı kopyala'"></span></button>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-2 text-xs text-gray-700 dark:bg-white/[0.03] dark:text-gray-300">
                                    <p class="break-words">{{ $text }}</p>
                                    <div class="mt-1 flex flex-wrap gap-3 font-medium">
                                        <button type="button" x-on:click="navigator.clipboard.writeText(@js($text)); copied = 'text'" class="link-neutral"><span x-text="copied === 'text' ? 'Kopyalandı' : 'Mesajı kopyala'"></span></button>
                                        <a href="https://wa.me/?text={{ rawurlencode((string) $text) }}" target="_blank" rel="noopener" class="link-neutral">WhatsApp’ta aç ↗</a>
                                        <a href="{{ route('operator.gbp-review-card', ['assetId' => $location->id]) }}" target="_blank" class="link-neutral">Yazdırılabilir kart ↗</a>
                                    </div>
                                </div>
                            @else
                                <p class="text-xs text-gray-500">Google yer kimliği (place id) henüz toplanmadı; profil verisi bir sonraki toplamada gelince yorum bağlantısı burada görünür.</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            @empty
                <x-ta.empty-state size="sm" title="Operasyonel markaya bağlı İşletme Profili yok." class="m-4" />
            @endforelse
        </section>
    @endif
</div>
