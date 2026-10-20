@php
    // İşletme Profili sayfası (panel concept 2026-10-20). The question: "How is this profile doing, and what is left to do?"
    // Overview: the five numbers in one strip, then the six profile conditions and what MoxDOP already sent. Each other tab
    // answers one job: Yapılacaklar (suggestions), Yorumlar, Gönderiler (30-day plan), Hizmetler, Analiz, Ayarlar.
    $num = fn ($value) => $value === null ? '—' : number_format((float) $value, 0, ',', '.');
    $input = 'rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-800 placeholder:text-gray-400 focus:border-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:focus:ring-white/10';
    $check = 'h-4 w-4 shrink-0 rounded border-gray-300 accent-gray-900 dark:accent-white';
    $profile = $data['profile'] ?? [];
    $connection = $data['connection'] ?? [];
    $stateLine = function (?array $state): ?array {
        return match ($state['status'] ?? null) {
            'running' => ['text-gray-500', 'Çalışıyor…', true],
            'failed' => ['text-error-600 dark:text-error-400', (string) ($state['message'] ?? 'Başarısız.'), false],
            'ready' => ['text-success-600 dark:text-success-400', (string) ($state['message'] ?? 'Hazır.'), false],
            default => null,
        };
    };
@endphp

<div class="space-y-8">
    @include('livewire.demo.partials.flash')
    <x-operator.asset-context :asset-id="$assetId" :title="\App\Services\Gbp\Desk\GbpDesk::shortName((string) $identity['title'])">
        <x-slot:mark><x-demo.digital-asset-mark type="gbp" size="lg" class="mt-0.5 shrink-0" /></x-slot:mark>
        <x-slot:meta>{{ $identity['location_line'] ?: '—' }} · Son veri: {{ $identity['last_refresh'] ?? '—' }}</x-slot:meta>
        <x-slot:actions>
            @if ($ranged)
                <x-operator.date-range-picker :range="$range" :last-day="$lastDay" note="İşletme Profili verisi birkaç gün gecikmeli." class="w-full sm:w-auto" />
            @endif
            <button type="button" wire:click="refreshData" wire:loading.attr="disabled" @disabled(! $bound) class="btn">Verileri yenile</button>
        </x-slot:actions>
        <x-slot:tabs>
            <nav class="no-scrollbar flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="İşletme Profili">
                @foreach ($tabs as $key => $label)
                    <button type="button" wire:click="setTab('{{ $key }}')" @class(['tab shrink-0', 'tab-active' => $tab === $key]) @if ($tab === $key) aria-current="page" @endif>{{ $label }}@if ($key === 'todo' && $openCount > 0) <span class="text-xs font-normal tabular-nums text-gray-500">{{ $openCount }}</span>@endif</button>
                @endforeach
            </nav>
        </x-slot:tabs>
    </x-operator.asset-context>

    @if (! $bound)
        <section class="card">
            <div class="card-row flex-wrap sm:flex-nowrap">
                <span class="row-icon border-transparent bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg></span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Konum bağlı değil</p>
                    <p class="mt-0.5 text-[13px] text-gray-500">Google’daki işletme konumunu bağlayınca sayılar, yorumlar ve gönderiler buraya gelir.</p>
                </div>
                <a href="{{ route('operator.asset.sources', ['assetId' => $assetId]) }}" wire:navigate class="btn-primary w-full sm:w-auto">Bağla</a>
            </div>
        </section>
    @elseif (($data['migration_mode'] ?? '') !== 'real')
        <p class="tone-look rounded-lg px-3.5 py-2.5 text-sm">Veri yok: profil henüz toplanmadı. “Verileri yenile”.</p>
    @endif
    @if (filled($connection['last_error'] ?? null))
        <p class="tone-look rounded-lg px-3.5 py-2.5 text-[13px]">Son toplama: {{ $connection['last_error_hint'] ?? $connection['last_error'] }}</p>
    @endif

    @if ($tab === 'overview')
        @php
            $n = $numbers;
            $views = $n['views'];
            $pct = $views['change_pct'] ?? null;
        @endphp
        {{-- 1. Five numbers for the chosen period. --}}
        <section>
            <div class="section-head"><h2 class="section-title">{{ $range->label() }}</h2></div>
            <div class="hero-panel grid-cols-2 lg:grid-cols-5" data-testid="gbp-numbers">
                <div class="hero-tile hero-tile-accent col-span-2 lg:col-span-1">
                    <span class="hero-label">Görüntüleme (harita + arama)</span>
                    <p class="hero-value">{{ $num($views['current'] ?? null) }}</p>
                    <p class="mt-2 flex flex-wrap items-center gap-2 text-xs opacity-70">@if ($pct !== null)<span @class(['chip', 'chip-up' => $pct > 0, 'chip-down' => $pct < 0, 'chip-flat' => $pct == 0])>{{ $pct > 0 ? '▲ '.$pct : ($pct < 0 ? '▼ '.abs($pct) : '0') }}%</span>@endif{{ $pct === null ? 'önceki dönem yok' : 'karşılaştırma dönemine göre' }}</p>
                </div>
                <div class="hero-tile">
                    <span class="hero-label">Tıklama</span>
                    <p class="hero-value">{{ $n['actions'] ? $num($n['actions']['calls'] + $n['actions']['directions'] + $n['actions']['website_clicks']) : '—' }}</p>
                    <p class="mt-2 text-xs text-white/60">{{ $n['actions'] ? 'Arama '.$num($n['actions']['calls']).', Yol '.$num($n['actions']['directions']).', Web '.$num($n['actions']['website_clicks']) : 'veri yok' }}</p>
                </div>
                <div class="hero-tile">
                    <span class="hero-label">Puan</span>
                    <p class="hero-value">{{ $n['rating'] !== null ? number_format($n['rating'], 1, ',', '.') : '—' }}</p>
                    <p class="mt-2 text-xs text-white/60">{{ $num($n['review_count']) }} yorum</p>
                </div>
                <div class="hero-tile">
                    <span class="hero-label">Yanıtsız yorum</span>
                    <p class="hero-value">{{ $num($n['unanswered']) }}</p>
                    @if (($n['unanswered'] ?? 0) > 0)<p class="mt-2"><button type="button" wire:click="showUnanswered" class="inline-flex h-7 items-center rounded-lg bg-white px-2.5 text-xs font-semibold text-gray-900 transition hover:bg-gray-200">Yanıtla</button></p>@endif
                </div>
                <div class="hero-tile">
                    <span class="hero-label">Profil standartları</span>
                    <p class="hero-value">{{ $n['standards'] ? $n['standards']['passed'].' / '.$n['standards']['total'] : '—' }}</p>
                    <p class="mt-2 text-xs text-white/60">geçen / toplam</p>
                </div>
            </div>
        </section>

        @if ($openCount > 0)
            <section class="soft-card soft-accent hover:translate-y-0! hover:shadow-none!">
                <div class="flex flex-wrap items-start gap-x-4 gap-y-3">
                    <span class="soft-icon" aria-hidden="true"><svg class="h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/></svg></span>
                    <div class="min-w-0 flex-1 basis-60">
                        <p class="text-[15px] font-semibold tracking-[-0.01em] text-gray-900 dark:text-white"><span class="soft-number mr-1.5 align-[-3px] text-[26px]!">{{ $openCount }}</span> açık öneri</p>
                        <p class="mt-1 text-[13px] text-gray-600 dark:text-gray-300">Profil standartları ve yapay zekâ önerileri; onayla, reddet ya da ertele.</p>
                    </div>
                    <button type="button" wire:click="setTab('todo')" class="btn-primary h-10! w-full px-4! text-sm! sm:w-auto sm:self-center">Yapılacaklar</button>
                </div>
            </section>
        @endif

        {{-- 2. The six profile conditions and what MoxDOP already sent. --}}
        @if ($desk)
            <section class="space-y-8" data-testid="gbp-desk-state">
                <div>
                    <div class="section-head">
                        <h2 class="section-title">Koşullar <span class="section-count">{{ $desk['score'] }}/6 tamam</span></h2>
                        <a href="{{ route('operator.gbp-desk', ['marka' => $desk['brand_id'], 'isletme' => $desk['asset_id']]) }}" wire:navigate class="link-neutral ml-auto text-[13px] font-medium">Durum ve ölçümde aç</a>
                    </div>
                    <div class="metric-strip sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($desk['checks'] as $key => $check)
                            <a href="{{ route($check['route'], ['marka' => $desk['brand_id']]) }}" wire:navigate wire:key="desk-check-{{ $key }}" class="flex min-w-0 items-start gap-3 bg-white px-4 py-3.5 transition hover:bg-gray-50 dark:bg-gray-900 dark:hover:bg-white/[0.03]">
                                <span @class(['mt-[7px] h-1.5 w-1.5 shrink-0 rounded-full', 'bg-success-500' => $check['ok'], 'bg-warning-500' => ! $check['ok']]) aria-hidden="true"></span>
                                <span class="min-w-0"><span class="block text-sm font-medium text-gray-900 dark:text-white">{{ $check['label'] }}</span><span class="sr-only">{{ $check['ok'] ? ' (tamam)' : ' (eksik)' }}</span><span @class(['mt-0.5 block text-[13px]', 'text-gray-500' => $check['ok'], 'text-warning-800 dark:text-warning-400' => ! $check['ok']])>{{ $check['hint'] }}</span></span>
                            </a>
                        @endforeach
                    </div>
                </div>

                <div>
                    <div class="section-head"><h2 class="section-title">MoxDOP’un bu profilde yaptıkları</h2></div>
                    <div class="card">
                        @forelse ($desk['history'] as $item)
                            <div wire:key="desk-work-{{ $item['id'] }}" class="card-row flex-wrap items-baseline gap-x-4 gap-y-0.5 py-3">
                                <span class="shrink-0 text-[13px] tabular-nums text-gray-500 sm:w-28">{{ $item['at'] }}</span>
                                <span class="min-w-0 flex-1 text-sm text-gray-800 dark:text-gray-200">{{ $item['label'] }}@if ($item['error'])<span class="block text-[13px] text-error-600 dark:text-error-400">{{ $item['error'] }}</span>@endif</span>
                                <x-ta.pill :tone="in_array($item['status'], ['succeeded', 'partial'], true) ? 'ok' : ($item['status'] === 'failed' ? 'look' : 'info')">{{ $item['status_label'] }}</x-ta.pill>
                                @if ($item['by'] !== '')<span class="text-[13px] text-gray-500">{{ $item['by'] }}</span>@endif
                            </div>
                        @empty
                            <x-ta.empty-state size="sm" icon="none" title="Henüz bu profile MoxDOP’tan bir şey gönderilmedi." />
                        @endforelse
                    </div>
                </div>
            </section>
        @endif
    @elseif ($tab === 'todo')
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="recheck" class="btn">Standartları kontrol et</button>
            <span class="inline-flex items-center gap-1">
                <button type="button" wire:click="compareServices" wire:loading.attr="disabled" @disabled(! $operational || ($servicesState['status'] ?? null) === 'running') class="btn">Hizmetleri karşılaştır</button>
                <x-operator.ai-prompt-info operation="gbp.services_compare" />
            </span>
            <span class="inline-flex items-center gap-1">
                <button type="button" wire:click="proposeDescription" wire:loading.attr="disabled" @disabled(! $operational || ($descriptionState['status'] ?? null) === 'running') class="btn">Açıklama öner</button>
                <x-operator.ai-prompt-info operation="gbp.description" />
            </span>
            @unless ($operational)<span class="text-[13px] text-gray-500">Marka operasyonel değil; AI kapalı.</span>@endunless
        </div>
        @foreach (['Hizmetleri karşılaştır' => $servicesState, 'Açıklama öner' => $descriptionState] as $label => $state)
            @if ($line = $stateLine($state))
                <p class="text-[13px] {{ $line[0] }}" @if ($line[2]) wire:poll.5s @endif>{{ $label }}: {{ $line[1] }}</p>
            @endif
        @endforeach

        @if ($approved->isNotEmpty())
            <section data-testid="gbp-approved">
                <div class="section-head"><h2 class="section-title">Uygulanacaklar <span class="section-count">{{ $approved->count() }}</span></h2></div>
                <div class="card">
                    @foreach ($approved as $s)
                        <div class="card-row flex-wrap py-3" wire:key="approved-{{ $s->id }}">
                            <span class="min-w-0 flex-1 text-sm text-gray-800 dark:text-gray-200">{{ $s->title }}</span>
                            <button type="button" wire:click="markApplied({{ $s->id }})" class="btn">Uygulandı</button>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section>
            <div class="section-head"><h2 class="section-title">Öneriler <span class="section-count">{{ $suggestions->count() }}</span></h2></div>
            <div class="card" data-testid="gbp-suggestions">
                @forelse ($suggestions as $s)
                    <div class="border-t border-gray-200 px-4 py-3.5 first:border-t-0 dark:border-gray-800" wire:key="sug-{{ $s->id }}">
                        <div class="flex flex-wrap items-start gap-x-4 gap-y-2">
                            <div class="min-w-0 basis-full sm:flex-1 sm:basis-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $s->title }}</p>
                                <p class="mt-0.5 text-[13px] text-gray-600 dark:text-gray-300">{{ $s->reason }}</p>
                                @if ($s->action_type === 'gbp_standard' && filled($s->action['todo'] ?? null))
                                    <p class="mt-0.5 text-[13px] text-gray-500">Yapılacak: {{ $s->action['todo'] }}</p>
                                @endif
                            </div>
                            <div class="flex shrink-0 gap-1">
                                <button type="button" wire:click="approveSuggestion({{ $s->id }})" class="btn-dark h-7! px-2.5!">Onayla</button>
                                <button type="button" wire:click="dismissSuggestion({{ $s->id }})" class="btn-quiet h-7! px-2!">Reddet</button>
                                <button type="button" wire:click="snoozeSuggestion({{ $s->id }})" class="btn-quiet h-7! px-2!">Ertele</button>
                            </div>
                        </div>
                        @if ($s->action_type === 'gbp_description')
                            <div class="mt-3 grid gap-3 md:grid-cols-2" x-data="{ text: @js((string) ($s->action['proposed'] ?? '')) }">
                                <div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/[0.03]">
                                    <p class="text-[13px] font-medium text-gray-500">Mevcut, {{ mb_strlen((string) ($s->action['current'] ?? '')) }} karakter</p>
                                    <p class="mt-1 whitespace-pre-line leading-6 text-gray-700 dark:text-gray-300">{{ filled($s->action['current'] ?? null) ? $s->action['current'] : 'Açıklama yok' }}</p>
                                </div>
                                <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-800">
                                    <p class="text-[13px] font-medium text-gray-900 dark:text-white">Önerilen, {{ mb_strlen((string) ($s->action['proposed'] ?? '')) }} karakter</p>
                                    <p class="mt-1 whitespace-pre-line leading-6 text-gray-800 dark:text-gray-200">{{ $s->action['proposed'] ?? '' }}</p>
                                    <p class="mt-2 flex flex-wrap items-center gap-2 text-[13px] text-gray-500"><button type="button" x-on:click="navigator.clipboard.writeText(text)" class="btn h-7! px-2.5!">Kopyala</button> ve Google’da yapıştırın</p>
                                </div>
                            </div>
                        @endif
                        @if (($s->evidence ?? []) !== [])
                            <details class="mt-2 text-[13px] text-gray-500">
                                <summary class="cursor-pointer hover:text-gray-900 dark:hover:text-white">Kanıt</summary>
                                @foreach ($s->evidence as $row)
                                    <p class="mt-0.5">@foreach ((array) $row as $k => $v){{ str_replace('_', ' ', (string) $k) }}: {{ is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE) }}@if (! $loop->last), @endif @endforeach</p>
                                @endforeach
                            </details>
                        @endif
                    </div>
                @empty
                    <x-ta.empty-state size="sm" title="Açık öneri yok." />
                @endforelse
            </div>
        </section>

    @elseif ($tab === 'reviews')
        @include('livewire.demo.gbp.partials.review-access')
        <livewire:operator.gbp.desk.reviews-page :asset="(int) $assetId" :key="'asset-reviews-'.$assetId" />
    @elseif ($tab === 'posts')
        @php
            $postData = $posts ?? ['items' => [], 'hint' => '', 'late' => false];
            $pState = $stateLine($postState);
            $planned = collect($calendar)->pluck('post')->filter();
            $waiting = $planned->where('status', \App\Models\GbpQueuedPost::DRAFT)->count();
            $ready = $planned->where('status', \App\Models\GbpQueuedPost::APPROVED)->count();
            $emptyDays = collect($calendar)->filter(fn (array $d): bool => $d['post'] === null && ! $d['today'])->count();
            $angles = \App\Services\Gbp\GbpPostQueue::ANGLES;
            $langNames = \App\Services\Work\ContentBoard::LANGUAGE_LABELS;
            $categoryNames = ['hizmet' => 'Hizmet sayfaları', 'blog' => 'Blog yazıları', 'lokasyon' => 'Lokasyon sayfaları'];
            $pageGroups = collect($pages)->groupBy(fn (array $p): string => $p['language'] ?: 'tr')->sortByDesc(fn ($rows) => $rows->count())
                ->map(fn ($rows) => $rows->unique(fn (array $p): string => mb_strtolower($p['title']))->groupBy(fn (array $p): string => $categoryNames[$p['category'] ?? ''] ?? 'Diğer sayfalar'));
            $statusTone = [
                \App\Models\GbpQueuedPost::DRAFT => 'look',
                \App\Models\GbpQueuedPost::APPROVED => 'info',
                \App\Models\GbpQueuedPost::PUBLISHED => 'ok',
                \App\Models\GbpQueuedPost::FAILED => 'look',
            ];
            $postHint = $planned->isNotEmpty() ? 'Otomatik plan açık: her gün 1 gönderi.' : $postData['hint'];
        @endphp
        <div class="grid items-start gap-8 xl:grid-cols-3">
            {{-- 1. The 30-day plan. --}}
            <section class="min-w-0 xl:col-span-2" data-post-calendar>
                <div class="section-head items-center">
                    <h2 class="section-title" title="Her gün 10:00 civarı bir gönderi. Onaylananlar günü gelince kendiliğinden yayınlanır; onaylanmayan gün boş geçer.">Gönderi takvimi</h2>
                    <span class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                        <span class="text-gray-600 dark:text-gray-300">{{ $ready }} onaylı</span>
                        <x-ta.pill :tone="$waiting > 0 ? 'look' : 'info'" :dot="$waiting > 0">{{ $waiting }} onay bekliyor</x-ta.pill>
                        <x-ta.pill :tone="$emptyDays > 7 ? 'look' : 'info'" :dot="$emptyDays > 7">{{ $emptyDays }} boş gün</x-ta.pill>
                    </span>
                    <span class="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto">
                        @if ($waiting > 0 && $canWrite)
                            <button type="button" wire:click="approveAllPlanned" wire:confirm="{{ $waiting }} gönderi onaylansın mı? Günü gelince yayınlanırlar." class="btn-primary max-sm:flex-1" data-approve-all-planned>Bekleyenleri onayla ({{ $waiting }})</button>
                        @endif
                        <a href="{{ route('operator.gbp-posts', ['isletme' => $assetId]) }}" wire:navigate class="btn max-sm:flex-1">Toplu görünüm</a>
                    </span>
                </div>
                @if ($calendar === [])
                    <div class="card"><x-ta.empty-state size="sm" icon="none" title="Bu profil bir markaya bağlı değil; otomatik gönderi planı yok." /></div>
                @else
                    <ol class="card">
                        @foreach ($calendar as $row)
                            @php $item = $row['post']; @endphp
                            @if ($loop->first || $row['week'] !== $calendar[$loop->index - 1]['week'])
                                <li class="border-t border-gray-200 bg-gray-50/70 px-4 py-2 text-xs font-medium text-gray-500 first:border-t-0 dark:border-gray-800 dark:bg-white/[0.02]">{{ $row['week'] }}</li>
                            @endif
                            <li wire:key="day-{{ $row['day'] }}" @class(['flex gap-4 border-t border-gray-200 px-4 py-3.5 dark:border-gray-800', 'bg-gray-50/70 dark:bg-white/[0.03]' => $row['today']]) data-calendar-day="{{ $row['day'] }}">
                                <div class="w-14 shrink-0 text-center">
                                    <span @class(['block text-[15px] font-semibold leading-5 tabular-nums text-gray-900 dark:text-white', 'underline decoration-2 underline-offset-4' => $row['today']])>{{ \Illuminate\Support\Str::before($row['label'], ' ') }}</span>
                                    <span class="mt-1 block whitespace-nowrap text-xs text-gray-500">{{ $row['today'] ? 'Bugün' : \Illuminate\Support\Str::after($row['label'], ' ') }}</span>
                                </div>
                                @if ($item)
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                                            <x-ta.pill :tone="$statusTone[$item->status] ?? 'info'">{{ $item->statusLabel() }}</x-ta.pill>
                                            <span class="text-xs text-gray-500">{{ $angles[$item->angle]['label'] ?? $item->angle }}</span>
                                            @if ($item->page)
                                                <a href="{{ $item->page->url }}" target="_blank" rel="noopener" class="min-w-0 max-w-full truncate text-xs font-medium text-gray-700 hover:underline dark:text-gray-300">{{ \Illuminate\Support\Str::limit((string) ($item->page->title ?: $item->page->path), 60) }}</a>
                                            @endif
                                        </div>
                                        <p class="mt-1.5 line-clamp-2 text-sm leading-6 text-gray-800 dark:text-gray-200" title="{{ $item->summary }}">{{ $item->summary }}</p>
                                        @if (filled($item->note))<p @class(['mt-1 text-[13px]', 'text-error-600 dark:text-error-400' => $item->status === \App\Models\GbpQueuedPost::FAILED, 'text-gray-500' => $item->status !== \App\Models\GbpQueuedPost::FAILED])>{{ $item->note }}</p>@endif
                                        @if (in_array($item->status, [\App\Models\GbpQueuedPost::DRAFT, \App\Models\GbpQueuedPost::APPROVED], true))
                                            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                                @if ($item->status === \App\Models\GbpQueuedPost::DRAFT && $canWrite)
                                                    <button type="button" wire:click="approvePlanned({{ $item->id }})" class="btn-dark h-7! px-2.5!" data-approve-planned="{{ $item->id }}">Onayla</button>
                                                @endif
                                                <a href="{{ route('operator.gbp-posts', ['isletme' => $assetId, 'gun' => $row['day']]) }}" wire:navigate class="btn-quiet h-7! px-2!">Metni düzenle</a>
                                                <button type="button" wire:click="skipPlanned({{ $item->id }})" wire:confirm="Bu günün gönderisi atlansın mı?" class="btn-quiet h-7! px-2!">Atla</button>
                                            </div>
                                        @endif
                                    </div>
                                    @if (filled($item->image_url))
                                        <img src="{{ $item->image_url }}" alt="" loading="lazy" class="hidden h-16 w-16 shrink-0 rounded-lg bg-gray-100 object-cover ring-1 ring-gray-200 sm:block dark:bg-gray-800 dark:ring-gray-700">
                                    @endif
                                @else
                                    <div class="flex min-w-0 flex-1 items-center text-[13px] text-gray-500">
                                        {{ $row['today'] ? 'Bugün planlı gönderi yok.' : 'Boş gün; plan bir sonraki çalışmada doldurur.' }}
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>

            <div class="min-w-0 space-y-8">
                {{-- 2. A one-off post. --}}
                <section data-one-off-post>
                    <div class="section-head"><h2 class="section-title" title="Plan dışında bir duyuru ya da sayfa paylaşımı.">Tek seferlik gönderi</h2></div>
                    <div class="card space-y-3 p-4">
                        <div>
                            <label class="text-[13px] font-medium text-gray-600 dark:text-gray-300" for="share-page">Siteden bir sayfayı paylaş</label>
                            <div class="mt-1 flex items-center gap-2">
                                <select id="share-page" wire:model="sharePageId" class="h-8 min-w-0 flex-1 {{ $input }} py-0 text-[13px]">
                                    <option value="">Sayfa seçin</option>
                                    @foreach ($pageGroups as $language => $groups)
                                        @foreach ($groups as $groupName => $rows)
                                            <optgroup label="{{ $groupName }}{{ $pageGroups->count() > 1 ? ', '.($langNames[$language] ?? strtoupper($language)) : '' }}">
                                                @foreach ($rows as $page)
                                                    <option value="{{ $page['id'] }}">{{ \Illuminate\Support\Str::limit($page['title'], 70) }}</option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    @endforeach
                                </select>
                                <button type="button" wire:click="sharePage" wire:loading.attr="disabled" @disabled(! $operational || ($postState['status'] ?? null) === 'running') class="btn">Metni yaz</button>
                                <x-operator.ai-prompt-info operation="gbp.post_from_page" />
                            </div>
                            @if ($pages === [])<p class="mt-1 text-[13px] text-gray-500">Markanın sitesinde sayfa yok.</p>@endif
                            @if ($pState)<p class="mt-1 text-[13px] {{ $pState[0] }}" @if ($pState[2]) wire:poll.5s @endif>{{ $pState[1] }}</p>@endif
                            @if ($postDraft)
                                <div class="mt-2.5 rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/[0.03]">
                                    <p class="text-[13px] font-medium text-gray-700 dark:text-gray-300">{{ data_get($postDraft->content, 'page_title') }}</p>
                                    <p class="mt-1 whitespace-pre-line leading-6 text-gray-700 dark:text-gray-300">{{ \Illuminate\Support\Str::limit((string) data_get($postDraft->content, 'body'), 400) }}</p>
                                    <button type="button" wire:click="useAiDraft({{ $postDraft->id }})" class="btn mt-2 h-7! px-2.5!">Düzenle ve yayınla</button>
                                </div>
                            @endif
                        </div>

                        @if (! $postFormOpen)
                            <button type="button" wire:click="startPost" class="btn-quiet -ml-2.5">Ya da kendin yaz</button>
                        @else
                            <div class="space-y-3 border-t border-gray-200 pt-3 dark:border-gray-800">
                                <label class="block text-sm"><span class="text-[13px] text-gray-500">Metin (en çok 1500)</span>
                                    <textarea wire:model="post.body" rows="7" maxlength="1500" class="mt-1 w-full {{ $input }}"></textarea>
                                    @error('post.body')<span class="text-[13px] text-error-600 dark:text-error-400">{{ $message }}</span>@enderror
                                </label>
                                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                                    <label class="block text-sm"><span class="text-[13px] text-gray-500">Buton</span>
                                        <select wire:model="post.action_type" class="mt-1 h-8 w-full {{ $input }} py-0 text-[13px]">
                                            @foreach (['LEARN_MORE' => 'Daha fazla bilgi', 'BOOK' => 'Randevu al', 'CALL' => 'Ara', 'ORDER' => 'Sipariş ver', 'SIGN_UP' => 'Kaydol'] as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="block text-sm"><span class="text-[13px] text-gray-500">Bağlantı</span>
                                        <input type="url" wire:model="post.url" placeholder="https://" class="mt-1 h-8 w-full {{ $input }} py-0 text-[13px]">
                                        @error('post.url')<span class="text-[13px] text-error-600 dark:text-error-400">{{ $message }}</span>@enderror
                                    </label>
                                </div>
                                <div class="flex flex-wrap items-center gap-3 text-sm">
                                    <label class="inline-flex items-center gap-1.5"><input type="radio" wire:model.live="post.when" value="now" class="accent-gray-900 dark:accent-white"> Şimdi</label>
                                    <label class="inline-flex items-center gap-1.5"><input type="radio" wire:model.live="post.when" value="later" class="accent-gray-900 dark:accent-white"> Zamanla</label>
                                    @if (($post['when'] ?? 'now') === 'later')
                                        <input type="datetime-local" wire:model="post.publish_at" aria-label="Yayın zamanı" class="h-8 {{ $input }} py-0 text-[13px]">
                                    @endif
                                    @error('post.publish_at')<span class="text-[13px] text-error-600 dark:text-error-400">{{ $message }}</span>@enderror
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($canWrite)
                                        <button type="button" x-on:click="if (confirm('Gönderi onaylansın mı? Yayından sonra geri alınabilir.')) $wire.publishPost()" class="btn-primary max-sm:flex-1">Onayla ve yayınla</button>
                                    @else
                                        <span class="text-[13px] text-gray-500">Yayını Admin onaylar.</span>
                                    @endif
                                    <button type="button" wire:click="cancelPost" class="btn-quiet">Vazgeç</button>
                                </div>
                            </div>
                        @endif
                    </div>
                </section>

                {{-- 3. What is on Google now. --}}
                <section data-published-posts>
                    <div class="section-head">
                        <h2 class="section-title">Google'daki gönderiler</h2>
                        <p @class(['w-full text-[13px]', 'text-warning-700 dark:text-warning-400' => $postData['late'] && $planned->isEmpty(), 'text-gray-500' => ! $postData['late'] || $planned->isNotEmpty()])>{{ $postHint }}</p>
                    </div>
                    <div class="card">
                        @forelse ($postData['items'] as $item)
                            <div class="border-t border-gray-200 px-4 py-3 first:border-t-0 dark:border-gray-800" wire:key="post-{{ $item['kind'] }}-{{ $item['id'] ?? $loop->index }}">
                                <div class="flex items-start gap-2 text-sm">
                                    <span class="min-w-0 flex-1 font-medium text-gray-900 dark:text-white">{{ $item['title'] }}</span>
                                    <x-ta.pill class="mt-0.5 shrink-0" :tone="in_array($item['status'], ['succeeded', 'live'], true) ? 'ok' : (in_array($item['status'], ['failed', 'rejected'], true) ? 'look' : 'info')">{{ $item['status_label'] }}</x-ta.pill>
                                </div>
                                <p class="mt-0.5 text-[13px] text-gray-500">{{ $item['when'] }}</p>
                                @if (filled($item['error']))<p class="mt-1 text-[13px] text-error-600 dark:text-error-400">{{ $item['error'] }}</p>@endif
                                @if (in_array($item['action_status'], ['queued', 'running', 'undoing'], true))<p class="mt-1 text-[13px] text-gray-500" wire:poll.5s>Google’a gönderiliyor…</p>@endif
                                @if ($item['kind'] === 'moxdop' && $canWrite)
                                    @if ($item['scheduled'] ?? false)
                                        <button type="button" x-on:click="if (confirm('Zamanlanmış gönderi iptal edilsin mi?')) $wire.cancelScheduled({{ $item['action_id'] }})" class="btn-quiet mt-1 -ml-2 h-7! px-2!">İptal et</button>
                                    @elseif ($item['undoable'])
                                        <button type="button" x-on:click="if (confirm('Gönderi Google’dan silinsin mi?')) $wire.undoWrite({{ $item['action_id'] }})" class="btn-quiet mt-1 -ml-2 h-7! px-2!">Geri al</button>
                                    @endif
                                @elseif ($item['kind'] === 'google' && filled($item['url']))
                                    <a href="{{ $item['url'] }}" target="_blank" rel="noopener" class="link-neutral mt-1 inline-block text-[13px]">Google’da gör</a>
                                @endif
                            </div>
                        @empty
                            <x-ta.empty-state size="sm" icon="none" title="Henüz yayınlanmış gönderi yok." />
                        @endforelse
                    </div>
                </section>
            </div>
        </div>

    @elseif ($tab === 'services')
        @php
            $plState = $stateLine($planState);
            $additionalNow = $profile['categories']['additional'] ?? [];
            $chip = fn (string $status): array => match ($status) {
                'new' => ['Eklenecek', 'ok'],
                'exists' => ['Profilde var', 'info'],
                default => ['9 ek kategori sınırı dolu', 'look'],
            };
        @endphp
        @if ($peerFound !== null)
            <section class="card" data-testid="gbp-peers">
                <div class="card-row flex-wrap items-start">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">Aynı sektördeki işletmeler ({{ $sectorName ?? 'sektör' }})</p>
                        <p class="mt-0.5 text-[13px] text-gray-500">
                            @if (isset($peerFound['error']))
                                {{ $peerFound['error'] }}
                            @elseif ($peerFound['peers'] === 0)
                                Bu sektörde verisi toplanmış başka İşletme Profili yok.
                            @else
                                MoxDOP’taki {{ $peerFound['peers'] }} profilin ({{ \Illuminate\Support\Str::limit(implode(', ', $peerFound['brands']), 120) }}) kategori ve hizmetleri; bu profilde olanlar çıkarıldı, en çok kullanılan önce. Yalnız adlar alınır: açıklamaları “AI ile hazırla” bu marka için yeniden yazar.
                            @endif
                        </p>
                    </div>
                    <button type="button" wire:click="togglePeers" class="btn-quiet">Kapat</button>
                </div>
                @if (($peerFound['categories'] ?? []) !== [] || ($peerFound['services'] ?? []) !== [])
                    <div class="space-y-3 border-t border-gray-200 px-4 py-3.5 dark:border-gray-800">
                        <div class="-ml-2.5 flex flex-wrap gap-1">
                            <button type="button" wire:click="pickPeers('common')" class="btn-quiet h-7! px-2.5!">En az 2 işletmede olanları seç</button>
                            <button type="button" wire:click="pickPeers('all')" class="btn-quiet h-7! px-2.5!">Hepsini seç</button>
                            <button type="button" wire:click="pickPeers('none')" class="btn-quiet h-7! px-2.5!">Seçimi temizle</button>
                        </div>
                        <div class="grid gap-4 lg:grid-cols-3">
                            <div>
                                <p class="mb-1 text-[13px] font-medium text-gray-500">Kategoriler ({{ count($peerFound['categories']) }})</p>
                                @forelse ($peerFound['categories'] as $row)
                                    <label class="flex items-center gap-2 py-1 text-sm" wire:key="peer-{{ $row['key'] }}"><input type="checkbox" wire:model.live="peerPick" value="{{ $row['key'] }}" class="{{ $check }}"> <span class="min-w-0 flex-1">{{ $row['name'] }}</span> <span class="text-xs tabular-nums text-gray-500">{{ $row['count'] }} işletme</span></label>
                                @empty
                                    <p class="text-[13px] text-gray-500">Profilde olmayan kategori yok.</p>
                                @endforelse
                            </div>
                            <div class="lg:col-span-2">
                                <p class="mb-1 text-[13px] font-medium text-gray-500">Hizmetler ({{ count($peerFound['services']) }})</p>
                                <div class="max-h-96 overflow-y-auto pr-1 sm:columns-2">
                                    @forelse ($peerFound['services'] as $row)
                                        <label class="flex break-inside-avoid items-center gap-2 py-1 text-sm" wire:key="peer-{{ $row['key'] }}"><input type="checkbox" wire:model.live="peerPick" value="{{ $row['key'] }}" class="{{ $check }}"> <span class="min-w-0 flex-1">{{ $row['name'] }}@if ($row['category'] !== '') <span class="text-xs text-gray-500">({{ $row['category'] }})</span>@endif</span> <span class="text-xs tabular-nums text-gray-500">{{ $row['count'] }}</span></label>
                                    @empty
                                        <p class="text-[13px] text-gray-500">Profilde olmayan hizmet yok.</p>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <button type="button" wire:click="addPeers" @disabled($peerPick === []) class="btn-primary max-sm:w-full">Seçilenleri listeye ekle ({{ count($peerPick) }})</button>
                            <span class="text-[13px] text-gray-500">Liste kutularına eklenir; sonra “AI ile hazırla” Google’a uygun hale getirir ve açıklamaları yazar.</span>
                        </div>
                    </div>
                @endif
            </section>
        @endif
        <div class="grid items-start gap-8 xl:grid-cols-5" data-testid="gbp-services">
            @php
                $nowPrimary = $live !== null && ! isset($live['error']) ? $live['primary'] : ($profile['categories']['primary'] ?? '');
                $nowAdditional = $live !== null && ! isset($live['error']) ? $live['additional'] : $additionalNow;
                $nowServices = $live !== null && ! isset($live['error']) ? $live['services'] : ($profile['services'] ?? []);
                $step = 'inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-medium tabular-nums text-gray-600 dark:bg-white/[0.08] dark:text-gray-300';
            @endphp
            {{-- Steps 1-3: what is on the profile, what to add, make it fit Google. --}}
            <section class="card min-w-0 xl:col-span-2">
                <div class="px-4 py-4 text-sm" data-testid="gbp-services-now">
                    <div class="flex items-center justify-between gap-2">
                        <p class="flex items-center gap-2 font-medium text-gray-900 dark:text-white"><span class="{{ $step }}">1</span> Profilde şu an</p>
                        @if ($live !== null)
                            <button type="button" wire:click="refreshLive" wire:loading.attr="disabled" class="btn-quiet -mr-2 h-7! px-2!">Google’dan yenile</button>
                        @endif
                    </div>
                    <p class="mt-1 text-[13px] text-gray-500">
                        @if ($live === null)
                            Son toplanan veri (profil bağlı değil).
                        @elseif (isset($live['error']))
                            <span class="text-warning-700 dark:text-warning-300">Google’dan şu an okunamadı ({{ $live['error'] }}); son toplanan veri gösteriliyor.</span>
                        @else
                            Google’dan canlı okundu, {{ \Carbon\CarbonImmutable::parse($live['read_at'])->locale('tr')->diffForHumans() }}
                        @endif
                    </p>
                    <dl class="mt-2 space-y-1 text-gray-700 dark:text-gray-300">
                        <div><dt class="inline text-[13px] text-gray-500">Birincil:</dt> <dd class="inline">{{ $nowPrimary !== '' ? $nowPrimary : '—' }}</dd></div>
                        <div><dt class="inline text-[13px] text-gray-500">Ek ({{ count($nowAdditional) }}/9):</dt> <dd class="inline">{{ $nowAdditional === [] ? 'yok' : implode(', ', $nowAdditional) }}</dd></div>
                        <div><dt class="inline text-[13px] text-gray-500">Hizmetler ({{ count($nowServices) }}):</dt> <dd class="inline">{{ $nowServices === [] ? 'yok' : \Illuminate\Support\Str::limit(implode(', ', $nowServices), 300) }}</dd></div>
                    </dl>
                    @if ($nowAdditional === [] || $nowServices === [])
                        <p class="mt-2 text-[13px] text-warning-800 dark:text-warning-300">{{ $nowAdditional === [] && $nowServices === [] ? 'Ek kategori ve hizmet yok' : ($nowAdditional === [] ? 'Ek kategori yok' : 'Hizmet yok') }}: aramada bu profil yalnız birincil kategoriyle görünür. Aşağıdan ekleyin.</p>
                    @elseif (count($nowAdditional) >= 9)
                        <p class="mt-2 text-[13px] text-gray-500">9 ek kategori sınırı dolu; yalnız hizmet eklenebilir.</p>
                    @endif
                </div>

                <div class="space-y-3 border-t border-gray-200 px-4 py-4 dark:border-gray-800">
                    <p class="flex items-center gap-2 text-sm font-medium text-gray-900 dark:text-white"><span class="{{ $step }}">2</span> Eklemek istediklerinizi toplayın</p>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="fillFromOfferings" class="btn h-7! px-2.5!">Marka hizmetlerinden doldur</button>
                        <button type="button" wire:click="togglePeers" class="btn h-7! px-2.5!">Aynı sektördeki işletmelerden getir</button>
                    </div>
                    <label class="block text-sm"><span class="text-[13px] text-gray-500">Kategoriler (her satıra bir, en çok 10; Google’daki adıyla, ör. “Ortodontist”)</span>
                        <textarea wire:model="wantCategories" rows="3" placeholder="Ortodontist&#10;Pedodontist" class="mt-1 w-full {{ $input }}"></textarea>
                    </label>
                    <label class="block text-sm"><span class="text-[13px] text-gray-500">Hizmetler (her satıra bir, en çok 80)</span>
                        <textarea wire:model="wantServices" rows="8" placeholder="Diş implantı&#10;Zirkonyum kaplama&#10;&#10;ya da yapıştır:&#10;**Diş Kliniği**&#10;| Hizmet | Açıklama |&#10;|---|---|&#10;| Gülüş Tasarımı | … |" class="mt-1 w-full {{ $input }} font-mono text-xs!"></textarea>
                        <span class="mt-1 block text-[13px] text-gray-500">Liste yapıştırabilirsiniz: kalın başlık (ya da “Başlık:”) kategori olur, altındaki satırlar (Hizmet | Açıklama, Excel’den sekmeli de olur) o kategorinin hizmetleri; açıklamanız aynen kullanılır.</span>
                    </label>
                </div>

                <div class="space-y-2.5 border-t border-gray-200 px-4 py-4 dark:border-gray-800">
                    <p class="flex items-center gap-2 text-sm font-medium text-gray-900 dark:text-white"><span class="{{ $step }}">3</span> Google’a uygun hale getirin</p>
                    <div class="flex flex-wrap items-center gap-1">
                        <button type="button" wire:click="preparePlan" wire:loading.attr="disabled" @disabled(! $operational || ! $bound || ($planState['status'] ?? null) === 'running') class="btn">AI ile hazırla</button>
                        <x-operator.ai-prompt-info operation="gbp.profile_plan" />
                    </div>
                    @if ($plState)
                        <p class="text-[13px] {{ $plState[0] }}" @if ($plState[2]) wire:poll.10s @endif data-testid="gbp-plan-state">
                            {{ $plState[1] }}@if (filled($planState['at'] ?? null)) <span class="text-gray-500">{{ \Carbon\CarbonImmutable::parse($planState['at'])->locale('tr')->diffForHumans() }}</span>@endif
                            @if (! $plState[2]) <button type="button" wire:click="dismissPlanState" class="link-neutral ml-1">Kapat</button>@endif
                        </p>
                        @if (($planState['status'] ?? null) === 'failed')<p class="text-[13px] text-gray-500">Listeyi kontrol edip yeniden “AI ile hazırla”ya basın; Google’ın kabul etmediği satırlar artık atlanıp nedeni sağda yazılır.</p>@endif
                    @endif
                    <p class="text-[13px] text-gray-500">Kategoriler Google’ın kendi listesinden seçilir; hizmetler Google’ın hazır hizmeti ya da kısa açıklamalı özel hizmet olur. Yalnız ekleme yapılır: birincil kategori ve mevcut kategori / hizmetler değişmez, geri alınabilir.</p>
                </div>
            </section>

            {{-- Step 4: pick and send. --}}
            <section class="min-w-0 space-y-8 xl:col-span-3">
                <div>
                    <div class="section-head">
                        <h2 class="section-title flex items-center gap-2"><span class="{{ $step }}">4</span> Kontrol edip gönderin</h2>
                        @if ($plan)<span class="ml-auto text-[13px] text-gray-500">{{ $plan->created_at?->diffForHumans() }}</span>@endif
                    </div>
                    <div class="card">
                        @if (! $plan)
                            <x-ta.empty-state size="sm" icon="none" title="Henüz hazırlık yok." message="Soldaki listeyi doldurup “AI ile hazırla”ya basın; Google’a uygun hali burada seçilebilir olarak görünür, seçtiklerinizi tek tıkla profile eklersiniz." />
                        @else
                            @php
                                $planCategories = (array) data_get($plan->content, 'categories', []);
                                $planServices = (array) data_get($plan->content, 'services', []);
                                $skipped = (array) data_get($plan->content, 'skipped', []);
                            @endphp
                            @if ($planCategories !== [])
                                <p class="border-b border-gray-200 bg-gray-50 px-4 py-2 text-xs font-medium text-gray-500 dark:border-gray-800 dark:bg-white/[0.02]">Kategoriler</p>
                                @foreach ($planCategories as $row)
                                    @php [$label, $class] = $chip($row['status']); @endphp
                                    <label class="flex items-start gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-800" wire:key="plan-cat-{{ $row['id'] }}">
                                        <input type="checkbox" wire:model.live="pickCategories" value="{{ $row['id'] }}" @disabled($row['status'] !== 'new') class="mt-1 {{ $check }}">
                                        <span class="min-w-0 flex-1 text-sm">
                                            <span class="font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</span>
                                            <span class="text-[13px] text-gray-500">({{ $row['line'] }})</span>
                                            @if (filled($row['reason']))<span class="block text-[13px] text-gray-500">{{ $row['reason'] }}</span>@endif
                                        </span>
                                        <x-ta.pill :tone="$class">{{ $label }}</x-ta.pill>
                                    </label>
                                @endforeach
                            @endif
                            @if ($planServices !== [])
                                <p class="border-b border-gray-200 bg-gray-50 px-4 py-2 text-xs font-medium text-gray-500 dark:border-gray-800 dark:bg-white/[0.02]">Hizmetler</p>
                                @foreach ($planServices as $index => $row)
                                    @php [$label, $class] = $chip($row['status']); @endphp
                                    <div class="flex items-start gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-800" wire:key="plan-svc-{{ $plan->id }}-{{ $index }}">
                                        <input type="checkbox" wire:model.live="pickServices" value="{{ $index }}" @disabled($row['status'] !== 'new') aria-label="{{ $row['name'] }}" class="mt-1 {{ $check }}">
                                        <div class="min-w-0 flex-1 text-sm">
                                            <p><span class="font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</span>
                                                <span class="text-[13px] text-gray-500">{{ $row['category'] }}, {{ $row['service_type_id'] ? 'Google’ın hazır hizmeti' : 'Özel hizmet' }}</span></p>
                                            @if ($row['status'] === 'new')
                                                <textarea wire:model="serviceText.{{ $index }}" rows="2" maxlength="300" aria-label="Açıklama" class="mt-1.5 w-full {{ $input }} text-[13px]"></textarea>
                                            @elseif (filled($row['description']))
                                                <p class="text-[13px] text-gray-500">{{ $row['description'] }}</p>
                                            @endif
                                        </div>
                                        <x-ta.pill :tone="$class">{{ $label }}</x-ta.pill>
                                    </div>
                                @endforeach
                            @endif
                            @if ($skipped !== [])
                                <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                                    <p class="text-[13px] font-medium text-gray-500">Atlananlar</p>
                                    @foreach ($skipped as $row)
                                        <p class="mt-1 text-[13px] text-gray-600 dark:text-gray-300"><span class="font-medium">{{ $row['line'] }}</span>: {{ $row['reason'] }}</p>
                                    @endforeach
                                </div>
                            @endif
                            <div class="flex flex-wrap items-center gap-2 bg-gray-50/70 px-4 py-3 dark:bg-white/[0.02]">
                                @php $picked = count($pickCategories) + count($pickServices); @endphp
                                @if ($canWrite)
                                    <button type="button" @disabled($picked === 0)
                                        x-on:click="if (confirm('Seçilen {{ count($pickCategories) }} kategori ve {{ count($pickServices) }} hizmet İşletme Profili’ne eklenecek. Mevcut kategori ve hizmetler değişmez; geri alınabilir. Gönderilsin mi?')) $wire.sendPlan()"
                                        class="btn-primary max-sm:flex-1">Seçilenleri gönder ({{ $picked }})</button>
                                @else
                                    <span class="text-[13px] text-gray-500">Gönderimi Admin onaylar.</span>
                                @endif
                                <button type="button" wire:click="discardPlan" class="btn-quiet">Hazırlığı sil</button>
                            </div>
                        @endif
                    </div>
                </div>

                @if ($profileWrites->isNotEmpty())
                    <div>
                        <div class="section-head"><h3 class="section-title">Gönderilenler</h3></div>
                        <div class="card">
                            @foreach ($profileWrites as $write)
                                @php
                                    $sentCategories = collect(data_get($write->request_payload, 'categories', []))->pluck('name')->all();
                                    $sentServices = collect(data_get($write->request_payload, 'services', []))->pluck('name')->all();
                                @endphp
                                <div class="border-t border-gray-200 px-4 py-3 text-sm first:border-t-0 dark:border-gray-800" wire:key="profile-write-{{ $write->id }}">
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                        <span class="text-[13px] tabular-nums text-gray-500">{{ $write->created_at?->format('d.m.Y H:i') }}</span>
                                        <span class="min-w-0 flex-1 text-gray-800 dark:text-gray-200">{{ \Illuminate\Support\Str::limit(implode(', ', [...$sentCategories, ...$sentServices]), 160) }}</span>
                                        <x-ta.pill :tone="$write->status === 'succeeded' ? 'ok' : (in_array($write->status, ['failed', 'undo_failed', 'partial'], true) ? 'look' : 'info')">{{ $write->statusLabel() }}</x-ta.pill>
                                    </div>
                                    @if (filled($write->error) || filled(data_get($write->result, 'error')))<p class="mt-1 text-[13px] text-error-600 dark:text-error-400">{{ $write->error ?: data_get($write->result, 'error') }}</p>@endif
                                    @if (in_array($write->status, ['queued', 'running', 'undoing'], true))<p class="mt-1 text-[13px] text-gray-500" wire:poll.5s>Google’a gönderiliyor…</p>@endif
                                    @if ($canWrite && $write->isUndoable())
                                        <button type="button" x-on:click="if (confirm('Bu gönderimle eklenen kategori ve hizmetler profilden kaldırılsın mı?')) $wire.undoWrite({{ $write->id }})" class="btn-quiet mt-1 -ml-2 h-7! px-2!">Geri al</button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </section>
        </div>

    @elseif ($tab === 'analysis')
        @if ($analysis === null || ($analysis['daily'] === [] && $analysis['keywords']['rows'] === [] && $analysis['reviews'] === []))
            <div class="card"><x-ta.empty-state size="sm" icon="none" title="Veri yok." /></div>
        @else
            <section data-testid="gbp-daily">
                <div class="section-head"><h2 class="section-title">Günlük performans</h2></div>
                @if ($analysis['daily'] === [])
                    <div class="card"><x-ta.empty-state size="sm" icon="none" title="Veri yok." /></div>
                @else
                    <div class="card overflow-x-auto">
                        <table class="data-table tabular-nums">
                            <thead><tr><th>Tarih</th><th class="text-right!">Arama görüntüleme</th><th class="text-right!">Harita görüntüleme</th><th class="text-right!">Telefon</th><th class="text-right!">Yol tarifi</th><th class="text-right!">Web tıklama</th></tr></thead>
                            <tbody>
                                <tr class="font-medium text-gray-900 dark:text-white"><td>Toplam</td>@foreach (['search_views', 'maps_views', 'calls', 'directions', 'website_clicks'] as $k)<td class="text-right">{{ $num($analysis['totals'][$k]) }}</td>@endforeach</tr>
                                @foreach ($analysis['daily'] as $day)
                                    <tr class="text-gray-700 dark:text-gray-300"><td class="whitespace-nowrap">{{ $day['date'] }}</td>@foreach (['search_views', 'maps_views', 'calls', 'directions', 'website_clicks'] as $k)<td class="text-right">{{ $num($day[$k]) }}</td>@endforeach</tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
            <div class="grid items-start gap-8 xl:grid-cols-2">
                <section class="min-w-0" data-testid="gbp-keywords">
                    <div class="section-head"><h2 class="section-title">Arama ifadeleri (ilk 20)</h2></div>
                    @if ($analysis['keywords']['rows'] === [])
                        <div class="card"><x-ta.empty-state size="sm" icon="none" title="Veri yok." /></div>
                    @else
                        <div class="card overflow-x-auto">
                            <table class="data-table tabular-nums">
                                <thead><tr><th>İfade</th>@foreach ($analysis['keywords']['months'] as $month)<th class="text-right!">{{ $month }}</th>@endforeach</tr></thead>
                                <tbody>
                                    @foreach ($analysis['keywords']['rows'] as $row)
                                        <tr class="text-gray-700 dark:text-gray-300"><td class="text-gray-900 dark:text-white">{{ $row['keyword'] }}</td>@foreach ($row['values'] as $value)<td class="text-right">{{ $value === null ? '<15' : $num($value) }}</td>@endforeach</tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
                <section class="min-w-0" data-testid="gbp-review-trend">
                    <div class="section-head"><h2 class="section-title">Yorum trendi</h2></div>
                    @if ($analysis['reviews'] === [])
                        <div class="card"><x-ta.empty-state size="sm" icon="none" title="Son 12 ayda yorum yok." /></div>
                    @else
                        <div class="card overflow-x-auto">
                            <table class="data-table tabular-nums">
                                <thead><tr><th>Ay</th><th class="text-right!">Yorum</th><th class="text-right!">Ortalama</th></tr></thead>
                                <tbody>
                                    @foreach ($analysis['reviews'] as $row)
                                        <tr class="text-gray-700 dark:text-gray-300"><td class="text-gray-900 dark:text-white">{{ $row['month'] }}</td><td class="text-right">{{ $row['count'] }}</td><td class="text-right">{{ $row['average'] !== null ? number_format($row['average'], 1, ',', '.').' ★' : '—' }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            </div>
        @endif

    @elseif ($tab === 'settings')
        @php
            $rows = [
                'Marka' => $brand?->name ?? 'Bağlı değil',
                'Sektör' => $sectorName ?? '—',
                'İşletme adı' => $identity['title'] ?? '—',
                'Adres' => $identity['location_line'] ?? '—',
                'Telefon' => collect($profile['fields'] ?? [])->firstWhere('key', 'primary_phone')['value'] ?? '—',
                'Web sitesi' => collect($profile['fields'] ?? [])->firstWhere('key', 'website')['value'] ?? '—',
                'Birincil kategori' => $profile['categories']['primary'] ?? '—',
                'Ek kategoriler' => ($profile['categories']['additional'] ?? []) === [] ? '—' : implode(', ', $profile['categories']['additional']),
                'Hizmetler' => ($profile['services'] ?? []) === [] ? '—' : implode(', ', $profile['services']),
                'Açıklama' => filled($profile['description'] ?? null) ? $profile['description'] : '—',
                'Bağlı hesap' => $connection['resource_name'] ?? '—',
                'Son toplama' => trim(($connection['last_run_label'] ?? '—').', '.($connection['last_run_human'] ?? ''), ', '),
            ];
        @endphp
        <section class="space-y-3" data-testid="gbp-settings">
            <div class="card">
                <dl>
                    @foreach ($rows as $label => $value)
                        <div class="grid gap-1 border-t border-gray-200 px-4 py-3 text-sm first:border-t-0 sm:grid-cols-4 sm:gap-4 dark:border-gray-800">
                            <dt class="text-[13px] text-gray-500 sm:text-sm">{{ $label }}</dt>
                            <dd class="min-w-0 whitespace-pre-line break-words text-gray-900 sm:col-span-3 dark:text-gray-100">@if ($label === 'Marka' && $brand)<a href="{{ route('operator.brand', ['brand' => $brand->id]) }}" wire:navigate class="link-neutral font-medium">{{ $value }}</a>@else{{ $value }}@endif</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
            <div class="flex flex-wrap gap-2">
                @if (filled($identity['maps_uri'] ?? null))<a href="{{ $identity['maps_uri'] }}" target="_blank" rel="noopener" class="btn">Haritada aç</a>@endif
                <a href="{{ \App\Services\Gbp\GbpDailyWorkspace::MANAGER_URL }}" target="_blank" rel="noopener" class="btn">Google’da düzenle</a>
                <a href="{{ route('operator.asset.edit', ['assetId' => $assetId]) }}" wire:navigate class="btn-quiet">Varlığı düzenle</a>
            </div>
        </section>
    @endif
</div>
