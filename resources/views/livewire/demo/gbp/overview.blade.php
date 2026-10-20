@php
    $num = fn ($value) => $value === null ? '—' : number_format((float) $value, 0, ',', '.');
    $card = 'min-w-0';
    $panel = 'min-w-0';
    $h2 = 'section-title';
    $btn = 'rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-inset ring-gray-200 text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-white/[0.04]';
    $primary = 'rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50';
    $profile = $data['profile'] ?? [];
    $connection = $data['connection'] ?? [];
    $stateLine = function (?array $state): ?array {
        return match ($state['status'] ?? null) {
            'running' => ['text-gray-500', 'Çalışıyor…', true],
            'failed' => ['text-error-600', (string) ($state['message'] ?? 'Başarısız.'), false],
            'ready' => ['text-success-600', (string) ($state['message'] ?? 'Hazır.'), false],
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
            <button type="button" wire:click="refreshData" wire:loading.attr="disabled" @disabled(! $bound) class="{{ $btn }}">Verileri yenile</button>
        </x-slot:actions>
        <x-slot:tabs>
            <nav class="flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="İşletme Profili">
                @foreach ($tabs as $key => $label)
                    <button type="button" wire:click="setTab('{{ $key }}')" @class([
                        'whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium',
                        'border-gray-900 text-gray-900 dark:border-white dark:text-white' => $tab === $key,
                        'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-500 dark:hover:text-gray-200' => $tab !== $key,
                    ])>{{ $label }}@if ($key === 'todo' && $openCount > 0) <span class="ml-1 text-xs font-normal tabular-nums text-gray-500">{{ $openCount }}</span>@endif</button>
                @endforeach
            </nav>
        </x-slot:tabs>
    </x-operator.asset-context>

    @if (! $bound)
        <section class="flex flex-wrap items-center gap-3 text-sm">
            <x-ta.pill tone="look" class="text-sm">Konum bağlı değil</x-ta.pill>
            <a href="{{ route('operator.asset.sources', ['assetId' => $assetId]) }}" wire:navigate class="{{ $primary }} inline-flex">Bağla</a>
        </section>
    @elseif (($data['migration_mode'] ?? '') !== 'real')
        <p class="tone-look rounded-lg px-3 py-2 text-sm">Veri yok: profil henüz toplanmadı. “Verileri yenile”.</p>
    @endif
    @if (filled($connection['last_error'] ?? null))
        <p class="tone-look rounded-lg px-3 py-2 text-xs">Son toplama: {{ $connection['last_error_hint'] ?? $connection['last_error'] }}</p>
    @endif

    @if ($tab === 'overview')
        @php
            $n = $numbers;
            $views = $n['views'];
            $pct = $views['change_pct'] ?? null;
        @endphp
        <div class="grid grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-3 xl:grid-cols-5" data-testid="gbp-numbers">
            <section class="{{ $card }} min-w-0">
                <p class="text-xs text-gray-500">Görüntüleme (harita + arama) · {{ $range->label() }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums tracking-tight text-gray-900 dark:text-white">{{ $num($views['current'] ?? null) }}</p>
                <p @class(['text-xs', 'text-success-700 dark:text-success-400' => $pct !== null && $pct >= 0, 'text-error-700 dark:text-error-400' => $pct !== null && $pct < 0, 'text-gray-500' => $pct === null])>{{ $pct === null ? 'önceki dönem yok' : (($pct > 0 ? '+' : '').$pct.'% karşılaştırma dönemine göre') }}</p>
            </section>
            <section class="{{ $card }} min-w-0">
                <p class="text-xs text-gray-500">Tıklama · {{ $range->label() }}</p>
                @if ($n['actions'])
                    <p class="mt-1 text-2xl font-semibold tabular-nums tracking-tight text-gray-900 dark:text-white">{{ $num($n['actions']['calls'] + $n['actions']['directions'] + $n['actions']['website_clicks']) }}</p>
                    <p class="text-xs text-gray-500">Arama {{ $num($n['actions']['calls']) }}, Yol {{ $num($n['actions']['directions']) }}, Web {{ $num($n['actions']['website_clicks']) }}</p>
                @else
                    <p class="mt-1 text-2xl font-semibold tabular-nums tracking-tight text-gray-900 dark:text-white">—</p><p class="text-xs text-gray-500">veri yok</p>
                @endif
            </section>
            <section class="{{ $card }} min-w-0">
                <p class="text-xs text-gray-500">Puan</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums tracking-tight text-gray-900 dark:text-white">{{ $n['rating'] !== null ? number_format($n['rating'], 1, ',', '.') : '—' }} <span class="text-lg text-gray-500">★</span></p>
                <p class="text-xs text-gray-500">{{ $num($n['review_count']) }} yorum</p>
            </section>
            <section class="{{ $card }} min-w-0">
                <p class="text-xs text-gray-500">Yanıtsız yorum</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums tracking-tight text-gray-900 dark:text-white">{{ $num($n['unanswered']) }}</p>
                @if (($n['unanswered'] ?? 0) > 0)<button type="button" wire:click="showUnanswered" class="text-xs font-medium link-neutral">Yanıtla</button>@endif
            </section>
            <section class="{{ $card }} min-w-0">
                <p class="text-xs text-gray-500">Profil standartları</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums tracking-tight text-gray-900 dark:text-white">{{ $n['standards'] ? $n['standards']['passed'].' / '.$n['standards']['total'] : '—' }}</p>
                <p class="text-xs text-gray-500">geçen / toplam</p>
            </section>
        </div>
        @if ($openCount > 0)
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $openCount }} açık öneri <button type="button" wire:click="setTab('todo')" class="ml-2 font-medium link-neutral">Yapılacaklar</button></p>
        @endif

        @if ($desk)
            <section class="{{ $card }}" data-testid="gbp-desk-state">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="{{ $h2 }}">İşletme profilleri · {{ $desk['score'] }}/6 tamam</h2>
                    <a href="{{ route('operator.gbp-desk', ['marka' => $desk['brand_id'], 'isletme' => $desk['asset_id']]) }}" class="text-xs font-medium link-neutral">Durum ve ölçümde aç</a>
                </div>
                <div class="mt-2 grid border-t border-gray-200 sm:grid-cols-2 sm:gap-x-8 xl:grid-cols-3 dark:border-gray-800">
                    @foreach ($desk['checks'] as $key => $check)
                        <a href="{{ route($check['route'], ['marka' => $desk['brand_id']]) }}" wire:key="desk-check-{{ $key }}" class="-mx-2 flex items-start gap-2 rounded-md border-b border-gray-200 px-2 py-2.5 text-xs hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                            <span @class(['mt-1 h-1.5 w-1.5 shrink-0 rounded-full', 'bg-success-500' => $check['ok'], 'bg-warning-500' => ! $check['ok']]) aria-hidden="true"></span>
                            <span><span class="font-medium text-gray-900 dark:text-white">{{ $check['label'] }}</span><span class="sr-only">{{ $check['ok'] ? ' (tamam)' : ' (eksik)' }}</span><span class="block text-gray-500">{{ $check['hint'] }}</span></span>
                        </a>
                    @endforeach
                </div>
                <h3 class="mt-6 text-sm font-medium text-gray-900 dark:text-white">MoxDOP’un bu profilde yaptıkları</h3>
                @forelse ($desk['history'] as $item)
                    <div wire:key="desk-work-{{ $item['id'] }}" class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 border-b border-gray-200 py-1.5 text-xs last:border-0 dark:border-gray-800">
                        <span class="shrink-0 tabular-nums text-gray-500 sm:w-28">{{ $item['at'] }}</span>
                        <span class="min-w-0 flex-1 text-gray-800 dark:text-gray-200">{{ $item['label'] }}@if ($item['error'])<span class="block text-error-600">{{ $item['error'] }}</span>@endif</span>
                        <span @class(['font-medium', 'text-success-600' => in_array($item['status'], ['succeeded', 'partial'], true), 'text-error-600' => $item['status'] === 'failed', 'text-gray-500' => ! in_array($item['status'], ['succeeded', 'partial', 'failed'], true)])>{{ $item['status_label'] }}</span>
                        @if ($item['by'] !== '')<span class="text-gray-500">{{ $item['by'] }}</span>@endif
                    </div>
                @empty
                    <p class="mt-1 text-xs text-gray-500">Henüz bu profile MoxDOP’tan bir şey gönderilmedi.</p>
                @endforelse
            </section>
        @endif
    @elseif ($tab === 'todo')
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="recheck" class="{{ $btn }}">Standartları kontrol et</button>
            <button type="button" wire:click="compareServices" wire:loading.attr="disabled" @disabled(! $operational || ($servicesState['status'] ?? null) === 'running') class="{{ $btn }}">Hizmetleri karşılaştır</button>
            <x-operator.ai-prompt-info operation="gbp.services_compare" />
            <button type="button" wire:click="proposeDescription" wire:loading.attr="disabled" @disabled(! $operational || ($descriptionState['status'] ?? null) === 'running') class="{{ $btn }}">Açıklama öner</button>
            <x-operator.ai-prompt-info operation="gbp.description" />
            @unless ($operational)<span class="text-xs text-gray-500">Marka operasyonel değil; AI kapalı.</span>@endunless
        </div>
        @foreach (['Hizmetleri karşılaştır' => $servicesState, 'Açıklama öner' => $descriptionState] as $label => $state)
            @if ($line = $stateLine($state))
                <p class="text-xs {{ $line[0] }}" @if ($line[2]) wire:poll.5s @endif>{{ $label }}: {{ $line[1] }}</p>
            @endif
        @endforeach

        @if ($approved->isNotEmpty())
            <section class="{{ $panel }} divide-y divide-gray-200 dark:divide-gray-800" data-testid="gbp-approved">
                <h2 class="{{ $h2 }} pb-2">Uygulanacaklar · {{ $approved->count() }}</h2>
                @foreach ($approved as $s)
                    <div class="flex flex-wrap items-center gap-2 py-2.5" wire:key="approved-{{ $s->id }}">
                        <span class="min-w-0 flex-1 text-sm text-gray-800 dark:text-gray-200">{{ $s->title }}</span>
                        <button type="button" wire:click="markApplied({{ $s->id }})" class="rounded-lg px-2 py-1 text-xs font-medium text-gray-800 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700">Uygulandı</button>
                    </div>
                @endforeach
            </section>
        @endif

        <section class="{{ $panel }} divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800" data-testid="gbp-suggestions">
            @forelse ($suggestions as $s)
                <div class="py-3" wire:key="sug-{{ $s->id }}">
                    <div class="flex flex-wrap items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $s->title }}</p>
                            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $s->reason }}</p>
                            @if ($s->action_type === 'gbp_standard' && filled($s->action['todo'] ?? null))
                                <p class="mt-0.5 text-xs text-gray-500">Yapılacak: {{ $s->action['todo'] }}</p>
                            @endif
                        </div>
                        <div class="flex shrink-0 gap-1.5">
                            <button type="button" wire:click="approveSuggestion({{ $s->id }})" class="rounded-lg px-2 py-1 text-xs font-medium text-gray-800 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700">Onayla</button>
                            <button type="button" wire:click="dismissSuggestion({{ $s->id }})" class="rounded-lg px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">Reddet</button>
                            <button type="button" wire:click="snoozeSuggestion({{ $s->id }})" class="rounded-lg px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">Ertele</button>
                        </div>
                    </div>
                    @if ($s->action_type === 'gbp_description')
                        <div class="mt-2 grid gap-2 md:grid-cols-2" x-data="{ text: @js((string) ($s->action['proposed'] ?? '')) }">
                            <div class="text-sm">
                                <p class="text-xs font-medium text-gray-500">Mevcut, {{ mb_strlen((string) ($s->action['current'] ?? '')) }} karakter</p>
                                <p class="mt-1 whitespace-pre-line text-gray-700 dark:text-gray-300">{{ filled($s->action['current'] ?? null) ? $s->action['current'] : 'Açıklama yok' }}</p>
                            </div>
                            <div class="border-l border-gray-300 pl-3 text-sm dark:border-gray-600">
                                <p class="text-xs font-medium text-gray-700 dark:text-gray-300">Önerilen, {{ mb_strlen((string) ($s->action['proposed'] ?? '')) }} karakter</p>
                                <p class="mt-1 whitespace-pre-line text-gray-800 dark:text-gray-200">{{ $s->action['proposed'] ?? '' }}</p>
                                <button type="button" x-on:click="navigator.clipboard.writeText(text)" class="mt-1 text-xs font-semibold link-neutral">Kopyala</button>
                                <span class="text-xs text-gray-500">ve Google’da yapıştırın</span>
                            </div>
                        </div>
                    @endif
                    @if (($s->evidence ?? []) !== [])
                        <details class="mt-1 text-xs text-gray-500">
                            <summary class="cursor-pointer">Kanıt</summary>
                            @foreach ($s->evidence as $row)
                                <p class="mt-0.5">@foreach ((array) $row as $k => $v){{ str_replace('_', ' ', (string) $k) }}: {{ is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE) }}@if (! $loop->last), @endif @endforeach</p>
                            @endforeach
                        </details>
                    @endif
                </div>
            @empty
                <p class="py-5 text-sm text-gray-500">Açık öneri yok.</p>
            @endforelse
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
                \App\Models\GbpQueuedPost::FAILED => 'critical',
            ];
            $postHint = $planned->isNotEmpty() ? 'Otomatik plan açık: her gün 1 gönderi.' : $postData['hint'];
        @endphp
        <div class="grid gap-10 xl:grid-cols-3">
            <section class="{{ $panel }} xl:col-span-2" data-post-calendar>
                <header class="flex flex-wrap items-start gap-3 border-b border-gray-200 pb-3 dark:border-gray-800">
                    <div class="min-w-0 flex-1">
                        <h2 class="{{ $h2 }}" title="Her gün 10:00 civarı bir gönderi. Onaylananlar günü gelince kendiliğinden yayınlanır; onaylanmayan gün boş geçer.">Gönderi takvimi</h2>
                        <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                            <span class="text-gray-600 dark:text-gray-300">{{ $ready }} onaylı</span>
                            <x-ta.pill :tone="$waiting > 0 ? 'look' : 'info'" :dot="$waiting > 0">{{ $waiting }} onay bekliyor</x-ta.pill>
                            <x-ta.pill :tone="$emptyDays > 7 ? 'look' : 'info'" :dot="$emptyDays > 7">{{ $emptyDays }} boş gün</x-ta.pill>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($waiting > 0 && $canWrite)
                            <button type="button" wire:click="approveAllPlanned" wire:confirm="{{ $waiting }} gönderi onaylansın mı? Günü gelince yayınlanırlar." class="{{ $primary }}" data-approve-all-planned>Bekleyenleri onayla ({{ $waiting }})</button>
                        @endif
                        <a href="{{ route('operator.gbp-posts', ['isletme' => $assetId]) }}" wire:navigate class="{{ $btn }}">Toplu görünüm</a>
                    </div>
                </header>
                @if ($calendar === [])
                    <p class="py-8 text-sm text-gray-500">Bu profil bir markaya bağlı değil; otomatik gönderi planı yok.</p>
                @else
                    <ol class="divide-y divide-gray-200 dark:divide-gray-800/70">
                        @foreach ($calendar as $row)
                            @php $item = $row['post']; @endphp
                            @if ($loop->first || $row['week'] !== $calendar[$loop->index - 1]['week'])
                                <li class="pb-1.5 pt-5 text-xs font-medium text-gray-500">{{ $row['week'] }}</li>
                            @endif
                            <li wire:key="day-{{ $row['day'] }}" @class(['flex gap-4 py-3', '-mx-2 rounded-md bg-gray-50 px-2 dark:bg-white/[0.03]' => $row['today']]) data-calendar-day="{{ $row['day'] }}">
                                <div class="w-14 shrink-0 text-center">
                                    <span @class(['block text-xl font-semibold leading-none tabular-nums', 'text-gray-900 underline decoration-2 underline-offset-4 dark:text-white' => $row['today'], 'text-gray-900 dark:text-white' => ! $row['today']])>{{ \Illuminate\Support\Str::before($row['label'], ' ') }}</span>
                                    <span class="mt-1 block text-xs text-gray-500">{{ $row['today'] ? 'Bugün' : \Illuminate\Support\Str::after($row['label'], ' ') }}</span>
                                </div>
                                @if ($item)
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <x-ta.pill :tone="$statusTone[$item->status] ?? 'info'">{{ $item->statusLabel() }}</x-ta.pill>
                                            <span class="text-xs text-gray-500">{{ $angles[$item->angle]['label'] ?? $item->angle }}</span>
                                            @if ($item->page)
                                                <a href="{{ $item->page->url }}" target="_blank" rel="noopener" class="truncate text-xs font-medium text-gray-700 hover:underline dark:text-gray-300">{{ \Illuminate\Support\Str::limit((string) ($item->page->title ?: $item->page->path), 60) }}</a>
                                            @endif
                                        </div>
                                        <p class="mt-1.5 line-clamp-2 text-sm leading-relaxed text-gray-700 dark:text-gray-300" title="{{ $item->summary }}">{{ $item->summary }}</p>
                                        @if (filled($item->note))<p @class(['mt-1 text-xs', 'text-error-600' => $item->status === \App\Models\GbpQueuedPost::FAILED, 'text-gray-500' => $item->status !== \App\Models\GbpQueuedPost::FAILED])>{{ $item->note }}</p>@endif
                                        @if (in_array($item->status, [\App\Models\GbpQueuedPost::DRAFT, \App\Models\GbpQueuedPost::APPROVED], true))
                                            <div class="mt-2 flex flex-wrap items-center gap-3 text-xs font-medium">
                                                @if ($item->status === \App\Models\GbpQueuedPost::DRAFT && $canWrite)
                                                    <button type="button" wire:click="approvePlanned({{ $item->id }})" class="font-semibold text-gray-900 hover:underline dark:text-white" data-approve-planned="{{ $item->id }}">Onayla</button>
                                                @endif
                                                <a href="{{ route('operator.gbp-posts', ['isletme' => $assetId, 'gun' => $row['day']]) }}" wire:navigate class="text-gray-600 hover:underline dark:text-gray-300">Metni düzenle</a>
                                                <button type="button" wire:click="skipPlanned({{ $item->id }})" wire:confirm="Bu günün gönderisi atlansın mı?" class="text-gray-500 hover:text-error-600 hover:underline">Atla</button>
                                            </div>
                                        @endif
                                    </div>
                                    @if (filled($item->image_url))
                                        <img src="{{ $item->image_url }}" alt="" loading="lazy" class="hidden h-16 w-16 shrink-0 rounded-lg object-cover ring-1 ring-gray-200 sm:block dark:ring-gray-700">
                                    @endif
                                @else
                                    <div class="flex min-w-0 flex-1 items-center text-xs text-gray-500">
                                        {{ $row['today'] ? 'Bugün planlı gönderi yok.' : 'Boş gün; plan bir sonraki çalışmada doldurur.' }}
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>

            <div class="space-y-10">
                <section class="{{ $card }} space-y-3" data-one-off-post>
                    <div>
                        <h2 class="{{ $h2 }}" title="Plan dışında bir duyuru ya da sayfa paylaşımı.">Tek seferlik gönderi</h2>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-600 dark:text-gray-300" for="share-page">Siteden bir sayfayı paylaş</label>
                        <div class="mt-1 flex gap-2">
                            <select id="share-page" wire:model="sharePageId" class="min-w-0 flex-1 rounded-lg border-gray-200 text-sm dark:border-gray-800 dark:bg-gray-900">
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
                            <button type="button" wire:click="sharePage" wire:loading.attr="disabled" @disabled(! $operational || ($postState['status'] ?? null) === 'running') class="{{ $btn }}">Metni yaz</button>
                            <x-operator.ai-prompt-info operation="gbp.post_from_page" />
                        </div>
                        @if ($pages === [])<p class="mt-1 text-xs text-gray-500">Markanın sitesinde sayfa yok.</p>@endif
                        @if ($pState)<p class="mt-1 text-xs {{ $pState[0] }}" @if ($pState[2]) wire:poll.5s @endif>{{ $pState[1] }}</p>@endif
                        @if ($postDraft)
                            <div class="mt-2 border-l border-gray-300 pl-3 text-sm dark:border-gray-600">
                                <p class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ data_get($postDraft->content, 'page_title') }}</p>
                                <p class="mt-1 whitespace-pre-line text-gray-700 dark:text-gray-300">{{ \Illuminate\Support\Str::limit((string) data_get($postDraft->content, 'body'), 400) }}</p>
                                <button type="button" wire:click="useAiDraft({{ $postDraft->id }})" class="mt-1 text-xs font-semibold link-neutral">Düzenle ve yayınla</button>
                            </div>
                        @endif
                    </div>

                    @if (! $postFormOpen)
                        <button type="button" wire:click="startPost" class="text-sm font-medium link-neutral">Ya da kendin yaz</button>
                    @else
                        <div class="space-y-2 border-t border-gray-200 pt-3 dark:border-gray-800">
                            <label class="block text-sm"><span class="text-xs text-gray-500">Metin (en çok 1500)</span>
                                <textarea wire:model="post.body" rows="7" maxlength="1500" class="mt-1 w-full rounded-lg border-gray-200 text-sm dark:border-gray-800 dark:bg-gray-900"></textarea>
                                @error('post.body')<span class="text-xs text-error-600">{{ $message }}</span>@enderror
                            </label>
                            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                                <label class="block text-sm"><span class="text-xs text-gray-500">Buton</span>
                                    <select wire:model="post.action_type" class="mt-1 w-full rounded-lg border-gray-200 text-sm dark:border-gray-800 dark:bg-gray-900">
                                        @foreach (['LEARN_MORE' => 'Daha fazla bilgi', 'BOOK' => 'Randevu al', 'CALL' => 'Ara', 'ORDER' => 'Sipariş ver', 'SIGN_UP' => 'Kaydol'] as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="block text-sm"><span class="text-xs text-gray-500">Bağlantı</span>
                                    <input type="url" wire:model="post.url" placeholder="https://" class="mt-1 w-full rounded-lg border-gray-200 text-sm dark:border-gray-800 dark:bg-gray-900">
                                    @error('post.url')<span class="text-xs text-error-600">{{ $message }}</span>@enderror
                                </label>
                            </div>
                            <div class="flex flex-wrap items-center gap-3 text-sm">
                                <label class="inline-flex items-center gap-1"><input type="radio" wire:model.live="post.when" value="now"> Şimdi</label>
                                <label class="inline-flex items-center gap-1"><input type="radio" wire:model.live="post.when" value="later"> Zamanla</label>
                                @if (($post['when'] ?? 'now') === 'later')
                                    <input type="datetime-local" wire:model="post.publish_at" aria-label="Yayın zamanı" class="rounded-lg border-gray-200 text-sm dark:border-gray-800 dark:bg-gray-900">
                                @endif
                                @error('post.publish_at')<span class="text-xs text-error-600">{{ $message }}</span>@enderror
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if ($canWrite)
                                    <button type="button" x-on:click="if (confirm('Gönderi onaylansın mı? Yayından sonra geri alınabilir.')) $wire.publishPost()" class="{{ $primary }}">Onayla ve yayınla</button>
                                @else
                                    <span class="text-xs text-gray-500">Yayını Admin onaylar.</span>
                                @endif
                                <button type="button" wire:click="cancelPost" class="px-2 text-sm text-gray-500 hover:underline">Vazgeç</button>
                            </div>
                        </div>
                    @endif
                </section>

                <section class="{{ $panel }}" data-published-posts>
                    <div class="border-b border-gray-200 pb-2 dark:border-gray-800">
                        <h2 class="{{ $h2 }}">Google'daki gönderiler</h2>
                        <p @class(['mt-0.5 text-xs', 'text-warning-700 dark:text-warning-400' => $postData['late'] && $planned->isEmpty(), 'text-gray-500' => ! $postData['late'] || $planned->isNotEmpty()])>{{ $postHint }}</p>
                    </div>
                    <div class="divide-y divide-gray-200 dark:divide-gray-800">
                        @forelse ($postData['items'] as $item)
                            <div class="py-2.5" wire:key="post-{{ $item['kind'] }}-{{ $item['id'] ?? $loop->index }}">
                                <div class="flex items-start gap-2 text-sm">
                                    <span class="min-w-0 flex-1 font-medium text-gray-900 dark:text-white">{{ $item['title'] }}</span>
                                    <x-ta.pill class="shrink-0" :tone="in_array($item['status'], ['succeeded', 'live'], true) ? 'ok' : (in_array($item['status'], ['failed', 'rejected'], true) ? 'critical' : 'info')">{{ $item['status_label'] }}</x-ta.pill>
                                </div>
                                <p class="text-xs text-gray-500">{{ $item['when'] }}</p>
                                @if (filled($item['error']))<p class="mt-1 text-xs text-error-600">{{ $item['error'] }}</p>@endif
                                @if (in_array($item['action_status'], ['queued', 'running', 'undoing'], true))<p class="mt-1 text-xs text-gray-500" wire:poll.5s>Google’a gönderiliyor…</p>@endif
                                @if ($item['kind'] === 'moxdop' && $canWrite)
                                    @if ($item['scheduled'] ?? false)
                                        <button type="button" x-on:click="if (confirm('Zamanlanmış gönderi iptal edilsin mi?')) $wire.cancelScheduled({{ $item['action_id'] }})" class="mt-1 text-xs font-medium text-error-600 hover:underline">İptal et</button>
                                    @elseif ($item['undoable'])
                                        <button type="button" x-on:click="if (confirm('Gönderi Google’dan silinsin mi?')) $wire.undoWrite({{ $item['action_id'] }})" class="mt-1 text-xs font-medium text-error-600 hover:underline">Geri al</button>
                                    @endif
                                @elseif ($item['kind'] === 'google' && filled($item['url']))
                                    <a href="{{ $item['url'] }}" target="_blank" rel="noopener" class="mt-1 inline-block text-xs link-neutral">Google’da gör</a>
                                @endif
                            </div>
                        @empty
                            <p class="py-5 text-sm text-gray-500">Henüz yayınlanmış gönderi yok.</p>
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
            <section class="surface space-y-3 p-4" data-testid="gbp-peers">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="font-semibold text-gray-900 dark:text-white">Aynı sektördeki işletmeler ({{ $sectorName ?? 'sektör' }})</p>
                        <p class="mt-0.5 text-xs text-gray-500">
                            @if (isset($peerFound['error']))
                                {{ $peerFound['error'] }}
                            @elseif ($peerFound['peers'] === 0)
                                Bu sektörde verisi toplanmış başka İşletme Profili yok.
                            @else
                                MoxDOP’taki {{ $peerFound['peers'] }} profilin ({{ \Illuminate\Support\Str::limit(implode(', ', $peerFound['brands']), 120) }}) kategori ve hizmetleri; bu profilde olanlar çıkarıldı, en çok kullanılan önce. Yalnız adlar alınır: açıklamaları “AI ile hazırla” bu marka için yeniden yazar.
                            @endif
                        </p>
                    </div>
                    <button type="button" wire:click="togglePeers" class="text-xs text-gray-500 hover:underline">Kapat</button>
                </div>
                @if (($peerFound['categories'] ?? []) !== [] || ($peerFound['services'] ?? []) !== [])
                    <div class="flex flex-wrap gap-3 text-xs font-medium">
                        <button type="button" wire:click="pickPeers('common')" class="link-neutral">En az 2 işletmede olanları seç</button>
                        <button type="button" wire:click="pickPeers('all')" class="link-neutral">Hepsini seç</button>
                        <button type="button" wire:click="pickPeers('none')" class="text-gray-500 hover:underline">Seçimi temizle</button>
                    </div>
                    <div class="grid gap-4 lg:grid-cols-3">
                        <div>
                            <p class="mb-1 text-xs font-medium text-gray-500">Kategoriler ({{ count($peerFound['categories']) }})</p>
                            @forelse ($peerFound['categories'] as $row)
                                <label class="flex items-center gap-2 py-0.5 text-sm" wire:key="peer-{{ $row['key'] }}"><input type="checkbox" wire:model.live="peerPick" value="{{ $row['key'] }}" class="rounded border-gray-200"> <span class="min-w-0 flex-1">{{ $row['name'] }}</span> <span class="text-xs tabular-nums text-gray-500">{{ $row['count'] }} işletme</span></label>
                            @empty
                                <p class="text-xs text-gray-500">Profilde olmayan kategori yok.</p>
                            @endforelse
                        </div>
                        <div class="lg:col-span-2">
                            <p class="mb-1 text-xs font-medium text-gray-500">Hizmetler ({{ count($peerFound['services']) }})</p>
                            <div class="max-h-96 overflow-y-auto pr-1 sm:columns-2">
                                @forelse ($peerFound['services'] as $row)
                                    <label class="flex break-inside-avoid items-center gap-2 py-0.5 text-sm" wire:key="peer-{{ $row['key'] }}"><input type="checkbox" wire:model.live="peerPick" value="{{ $row['key'] }}" class="rounded border-gray-200"> <span class="min-w-0 flex-1">{{ $row['name'] }}@if ($row['category'] !== '') <span class="text-xs text-gray-500">({{ $row['category'] }})</span>@endif</span> <span class="text-xs tabular-nums text-gray-500">{{ $row['count'] }}</span></label>
                                @empty
                                    <p class="text-xs text-gray-500">Profilde olmayan hizmet yok.</p>
                                @endforelse
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="button" wire:click="addPeers" @disabled($peerPick === []) class="{{ $primary }}">Seçilenleri listeye ekle ({{ count($peerPick) }})</button>
                        <span class="text-xs text-gray-500">Liste kutularına eklenir; sonra “AI ile hazırla” Google’a uygun hale getirir ve açıklamaları yazar.</span>
                    </div>
                @endif
            </section>
        @endif
        <div class="grid gap-10 xl:grid-cols-5" data-testid="gbp-services">
            <section class="{{ $card }} space-y-4 xl:col-span-2">
                @php
                    $nowPrimary = $live !== null && ! isset($live['error']) ? $live['primary'] : ($profile['categories']['primary'] ?? '');
                    $nowAdditional = $live !== null && ! isset($live['error']) ? $live['additional'] : $additionalNow;
                    $nowServices = $live !== null && ! isset($live['error']) ? $live['services'] : ($profile['services'] ?? []);
                    $step = 'tabular-nums text-gray-500';
                @endphp
                <div class="text-sm" data-testid="gbp-services-now">
                    <div class="flex items-center justify-between gap-2">
                        <p class="flex items-center gap-2 font-semibold text-gray-900 dark:text-white"><span class="{{ $step }}">1.</span> Profilde şu an</p>
                        @if ($live !== null)
                            <button type="button" wire:click="refreshLive" wire:loading.attr="disabled" class="text-xs font-medium link-neutral">Google’dan yenile</button>
                        @endif
                    </div>
                    <p class="mt-0.5 text-xs text-gray-500">
                        @if ($live === null)
                            Son toplanan veri (profil bağlı değil).
                        @elseif (isset($live['error']))
                            <span class="text-warning-700 dark:text-warning-300">Google’dan şu an okunamadı ({{ $live['error'] }}); son toplanan veri gösteriliyor.</span>
                        @else
                            Google’dan canlı okundu, {{ \Carbon\CarbonImmutable::parse($live['read_at'])->locale('tr')->diffForHumans() }}
                        @endif
                    </p>
                    <p class="mt-1 text-gray-600 dark:text-gray-300"><span class="text-xs text-gray-500">Birincil:</span> {{ $nowPrimary !== '' ? $nowPrimary : '—' }}</p>
                    <p class="text-gray-600 dark:text-gray-300"><span class="text-xs text-gray-500">Ek ({{ count($nowAdditional) }}/9):</span> {{ $nowAdditional === [] ? 'yok' : implode(', ', $nowAdditional) }}</p>
                    <p class="text-gray-600 dark:text-gray-300"><span class="text-xs text-gray-500">Hizmetler ({{ count($nowServices) }}):</span> {{ $nowServices === [] ? 'yok' : \Illuminate\Support\Str::limit(implode(', ', $nowServices), 300) }}</p>
                    @if ($nowAdditional === [] || $nowServices === [])
                        <p class="mt-1 text-xs text-warning-700 dark:text-warning-300">{{ $nowAdditional === [] && $nowServices === [] ? 'Ek kategori ve hizmet yok' : ($nowAdditional === [] ? 'Ek kategori yok' : 'Hizmet yok') }}: aramada bu profil yalnız birincil kategoriyle görünür. Aşağıdan ekleyin.</p>
                    @elseif (count($nowAdditional) >= 9)
                        <p class="mt-1 text-xs text-gray-500">9 ek kategori sınırı dolu; yalnız hizmet eklenebilir.</p>
                    @endif
                </div>

                <div class="space-y-2 border-t border-gray-200 pt-3 dark:border-gray-800">
                    <p class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white"><span class="{{ $step }}">2.</span> Eklemek istediklerinizi toplayın</p>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <button type="button" wire:click="fillFromOfferings" class="rounded-lg px-2.5 py-1 font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-white/[0.04]">Marka hizmetlerinden doldur</button>
                        <button type="button" wire:click="togglePeers" class="rounded-lg px-2.5 py-1 font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-white/[0.04]">Aynı sektördeki işletmelerden getir</button>
                    </div>
                    <label class="block text-sm"><span class="text-xs text-gray-500">Kategoriler (her satıra bir, en çok 10; Google’daki adıyla, ör. “Ortodontist”)</span>
                        <textarea wire:model="wantCategories" rows="3" placeholder="Ortodontist&#10;Pedodontist" class="mt-1 w-full rounded-lg border-gray-200 text-sm dark:border-gray-800 dark:bg-gray-900"></textarea>
                    </label>
                    <label class="block text-sm"><span class="text-xs text-gray-500">Hizmetler (her satıra bir, en çok 80)</span>
                        <textarea wire:model="wantServices" rows="8" placeholder="Diş implantı&#10;Zirkonyum kaplama&#10;&#10;ya da yapıştır:&#10;**Diş Kliniği**&#10;| Hizmet | Açıklama |&#10;|---|---|&#10;| Gülüş Tasarımı | … |" class="mt-1 w-full rounded-lg border-gray-200 font-mono text-xs dark:border-gray-800 dark:bg-gray-900"></textarea>
                        <span class="mt-1 block text-xs text-gray-500">Liste yapıştırabilirsiniz: kalın başlık (ya da “Başlık:”) kategori olur, altındaki satırlar (Hizmet | Açıklama, Excel’den sekmeli de olur) o kategorinin hizmetleri; açıklamanız aynen kullanılır.</span>
                    </label>
                </div>

                <div class="space-y-2 border-t border-gray-200 pt-3 dark:border-gray-800">
                    <p class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white"><span class="{{ $step }}">3.</span> Google’a uygun hale getirin</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" wire:click="preparePlan" wire:loading.attr="disabled" @disabled(! $operational || ! $bound || ($planState['status'] ?? null) === 'running') class="{{ $btn }}">AI ile hazırla</button>
                        <x-operator.ai-prompt-info operation="gbp.profile_plan" />
                    </div>
                    @if ($plState)
                        <p class="text-xs {{ $plState[0] }}" @if ($plState[2]) wire:poll.10s @endif data-testid="gbp-plan-state">
                            {{ $plState[1] }}@if (filled($planState['at'] ?? null)) <span class="text-gray-500">{{ \Carbon\CarbonImmutable::parse($planState['at'])->locale('tr')->diffForHumans() }}</span>@endif
                            @if (! $plState[2]) <button type="button" wire:click="dismissPlanState" class="ml-1 text-gray-500 hover:underline">Kapat</button>@endif
                        </p>
                        @if (($planState['status'] ?? null) === 'failed')<p class="text-xs text-gray-500">Listeyi kontrol edip yeniden “AI ile hazırla”ya basın; Google’ın kabul etmediği satırlar artık atlanıp nedeni sağda yazılır.</p>@endif
                    @endif
                    <p class="text-xs text-gray-500">Kategoriler Google’ın kendi listesinden seçilir; hizmetler Google’ın hazır hizmeti ya da kısa açıklamalı özel hizmet olur. Yalnız ekleme yapılır: birincil kategori ve mevcut kategori / hizmetler değişmez, geri alınabilir.</p>
                </div>
            </section>

            <section class="{{ $panel }} xl:col-span-3">
                <div class="flex items-center justify-between gap-2 border-b border-gray-200 pb-2 dark:border-gray-800">
                    <h2 class="{{ $h2 }} flex items-center gap-2"><span class="tabular-nums font-normal text-gray-500">4.</span> Kontrol edip gönderin</h2>
                    @if ($plan)<span class="text-xs text-gray-500">{{ $plan->created_at?->diffForHumans() }}</span>@endif
                </div>
                @if (! $plan)
                    <p class="py-5 text-sm text-gray-500">Soldaki listeyi doldurup “AI ile hazırla”ya basın; Google’a uygun hali burada seçilebilir olarak görünür, seçtiklerinizi tek tıkla profile eklersiniz.</p>
                @else
                    @php
                        $planCategories = (array) data_get($plan->content, 'categories', []);
                        $planServices = (array) data_get($plan->content, 'services', []);
                        $skipped = (array) data_get($plan->content, 'skipped', []);
                    @endphp
                    <div class="divide-y divide-gray-200 dark:divide-gray-800">
                        @if ($planCategories !== [])
                            <p class="pt-4 text-xs font-medium text-gray-500">Kategoriler</p>
                            @foreach ($planCategories as $row)
                                @php [$label, $class] = $chip($row['status']); @endphp
                                <label class="flex items-start gap-3 py-2.5" wire:key="plan-cat-{{ $row['id'] }}">
                                    <input type="checkbox" wire:model.live="pickCategories" value="{{ $row['id'] }}" @disabled($row['status'] !== 'new') class="mt-1 rounded border-gray-200">
                                    <span class="min-w-0 flex-1 text-sm">
                                        <span class="font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</span>
                                        <span class="text-xs text-gray-500">({{ $row['line'] }})</span>
                                        @if (filled($row['reason']))<span class="block text-xs text-gray-500">{{ $row['reason'] }}</span>@endif
                                    </span>
                                    <x-ta.pill :tone="$class">{{ $label }}</x-ta.pill>
                                </label>
                            @endforeach
                        @endif
                        @if ($planServices !== [])
                            <p class="pt-4 text-xs font-medium text-gray-500">Hizmetler</p>
                            @foreach ($planServices as $index => $row)
                                @php [$label, $class] = $chip($row['status']); @endphp
                                <div class="flex items-start gap-3 py-2.5" wire:key="plan-svc-{{ $plan->id }}-{{ $index }}">
                                    <input type="checkbox" wire:model.live="pickServices" value="{{ $index }}" @disabled($row['status'] !== 'new') aria-label="{{ $row['name'] }}" class="mt-1 rounded border-gray-200">
                                    <div class="min-w-0 flex-1 text-sm">
                                        <p><span class="font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</span>
                                            <span class="text-xs text-gray-500">{{ $row['category'] }}, {{ $row['service_type_id'] ? 'Google’ın hazır hizmeti' : 'Özel hizmet' }}</span></p>
                                        @if ($row['status'] === 'new')
                                            <textarea wire:model="serviceText.{{ $index }}" rows="2" maxlength="300" aria-label="Açıklama" class="mt-1 w-full rounded-lg border-gray-200 text-xs dark:border-gray-800 dark:bg-gray-900"></textarea>
                                        @elseif (filled($row['description']))
                                            <p class="text-xs text-gray-500">{{ $row['description'] }}</p>
                                        @endif
                                    </div>
                                    <x-ta.pill :tone="$class">{{ $label }}</x-ta.pill>
                                </div>
                            @endforeach
                        @endif
                        @if ($skipped !== [])
                            <div class="py-3">
                                <p class="text-xs font-medium text-gray-500">Atlananlar</p>
                                @foreach ($skipped as $row)
                                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-300"><span class="font-medium">{{ $row['line'] }}</span>: {{ $row['reason'] }}</p>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-2 border-t border-gray-200 py-3 dark:border-gray-800">
                        @php $picked = count($pickCategories) + count($pickServices); @endphp
                        @if ($canWrite)
                            <button type="button" @disabled($picked === 0)
                                x-on:click="if (confirm('Seçilen {{ count($pickCategories) }} kategori ve {{ count($pickServices) }} hizmet İşletme Profili’ne eklenecek. Mevcut kategori ve hizmetler değişmez; geri alınabilir. Gönderilsin mi?')) $wire.sendPlan()"
                                class="{{ $primary }}">Seçilenleri gönder ({{ $picked }})</button>
                        @else
                            <span class="text-xs text-gray-500">Gönderimi Admin onaylar.</span>
                        @endif
                        <button type="button" wire:click="discardPlan" class="px-2 text-sm text-gray-500 hover:underline">Hazırlığı sil</button>
                    </div>
                @endif

                @if ($profileWrites->isNotEmpty())
                    <div class="mt-6">
                        <h3 class="text-sm font-medium text-gray-900 dark:text-white">Gönderilenler</h3>
                        @foreach ($profileWrites as $write)
                            @php
                                $sentCategories = collect(data_get($write->request_payload, 'categories', []))->pluck('name')->all();
                                $sentServices = collect(data_get($write->request_payload, 'services', []))->pluck('name')->all();
                            @endphp
                            <div class="border-b border-gray-200 py-2.5 text-sm dark:border-gray-800" wire:key="profile-write-{{ $write->id }}">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-xs text-gray-500">{{ $write->created_at?->format('d.m.Y H:i') }}</span>
                                    <span class="min-w-0 flex-1 text-gray-800 dark:text-gray-200">{{ \Illuminate\Support\Str::limit(implode(', ', [...$sentCategories, ...$sentServices]), 160) }}</span>
                                    <x-ta.pill :tone="$write->status === 'succeeded' ? 'ok' : (in_array($write->status, ['failed', 'undo_failed', 'partial'], true) ? 'critical' : 'info')">{{ $write->statusLabel() }}</x-ta.pill>
                                </div>
                                @if (filled($write->error) || filled(data_get($write->result, 'error')))<p class="mt-1 text-xs text-error-600">{{ $write->error ?: data_get($write->result, 'error') }}</p>@endif
                                @if (in_array($write->status, ['queued', 'running', 'undoing'], true))<p class="mt-1 text-xs text-gray-500" wire:poll.5s>Google’a gönderiliyor…</p>@endif
                                @if ($canWrite && $write->isUndoable())
                                    <button type="button" x-on:click="if (confirm('Bu gönderimle eklenen kategori ve hizmetler profilden kaldırılsın mı?')) $wire.undoWrite({{ $write->id }})" class="mt-1 text-xs font-medium text-error-600 hover:underline">Geri al</button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>

    @elseif ($tab === 'analysis')
        @if ($analysis === null || ($analysis['daily'] === [] && $analysis['keywords']['rows'] === [] && $analysis['reviews'] === []))
            <p class="text-sm text-gray-500">Veri yok.</p>
        @else
            <section class="{{ $panel }} overflow-x-auto" data-testid="gbp-daily">
                <h2 class="{{ $h2 }} pb-2">Günlük performans</h2>
                @if ($analysis['daily'] === [])
                    <p class="py-4 text-sm text-gray-500">Veri yok.</p>
                @else
                    <table class="w-full text-sm">
                        <thead><tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-gray-800"><th class="py-2 pr-3 font-normal">Tarih</th><th class="px-3 py-2 text-right font-normal">Arama görüntüleme</th><th class="px-3 py-2 text-right font-normal">Harita görüntüleme</th><th class="px-3 py-2 text-right font-normal">Telefon</th><th class="px-3 py-2 text-right font-normal">Yol tarifi</th><th class="px-3 py-2 text-right font-normal">Web tıklama</th></tr></thead>
                        <tbody class="divide-y divide-gray-200 tabular-nums dark:divide-gray-800">
                            <tr class="font-semibold text-gray-900 dark:text-white"><td class="py-2 pr-3">Toplam</td>@foreach (['search_views', 'maps_views', 'calls', 'directions', 'website_clicks'] as $k)<td class="px-3 py-2 text-right">{{ $num($analysis['totals'][$k]) }}</td>@endforeach</tr>
                            @foreach ($analysis['daily'] as $day)
                                <tr class="text-gray-700 dark:text-gray-300"><td class="py-2 pr-3">{{ $day['date'] }}</td>@foreach (['search_views', 'maps_views', 'calls', 'directions', 'website_clicks'] as $k)<td class="px-3 py-2 text-right">{{ $num($day[$k]) }}</td>@endforeach</tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
            <div class="grid gap-10 xl:grid-cols-2">
                <section class="{{ $panel }} overflow-x-auto" data-testid="gbp-keywords">
                    <h2 class="{{ $h2 }} pb-2">Arama ifadeleri (ilk 20)</h2>
                    @if ($analysis['keywords']['rows'] === [])
                        <p class="py-4 text-sm text-gray-500">Veri yok.</p>
                    @else
                        <table class="w-full text-sm">
                            <thead><tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-gray-800"><th class="py-2 pr-3 font-normal">İfade</th>@foreach ($analysis['keywords']['months'] as $month)<th class="px-3 py-2 text-right font-normal">{{ $month }}</th>@endforeach</tr></thead>
                            <tbody class="divide-y divide-gray-200 tabular-nums dark:divide-gray-800">
                                @foreach ($analysis['keywords']['rows'] as $row)
                                    <tr class="text-gray-700 dark:text-gray-300"><td class="py-2 pr-3">{{ $row['keyword'] }}</td>@foreach ($row['values'] as $value)<td class="px-3 py-2 text-right">{{ $value === null ? '<15' : $num($value) }}</td>@endforeach</tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </section>
                <section class="{{ $panel }}" data-testid="gbp-review-trend">
                    <h2 class="{{ $h2 }} pb-2">Yorum trendi</h2>
                    @if ($analysis['reviews'] === [])
                        <p class="py-4 text-sm text-gray-500">Son 12 ayda yorum yok.</p>
                    @else
                        <table class="w-full text-sm">
                            <thead><tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-gray-800"><th class="py-2 pr-3 font-normal">Ay</th><th class="px-3 py-2 text-right font-normal">Yorum</th><th class="px-3 py-2 text-right font-normal">Ortalama</th></tr></thead>
                            <tbody class="divide-y divide-gray-200 tabular-nums dark:divide-gray-800">
                                @foreach ($analysis['reviews'] as $row)
                                    <tr class="text-gray-700 dark:text-gray-300"><td class="py-2 pr-3">{{ $row['month'] }}</td><td class="px-3 py-2 text-right">{{ $row['count'] }}</td><td class="px-3 py-2 text-right">{{ $row['average'] !== null ? number_format($row['average'], 1, ',', '.').' ★' : '—' }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
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
        <section class="{{ $panel }}" data-testid="gbp-settings">
            <dl class="divide-y divide-gray-200 border-y border-gray-200 text-sm dark:divide-gray-800 dark:border-gray-800">
                @foreach ($rows as $label => $value)
                    <div class="grid gap-1 py-2.5 sm:grid-cols-4">
                        <dt class="text-gray-500">{{ $label }}</dt>
                        <dd class="whitespace-pre-line text-gray-900 dark:text-gray-100 sm:col-span-3">
                            @if ($label === 'Marka' && $brand)
                                <a href="{{ route('operator.brand', ['brand' => $brand->id]) }}" wire:navigate class="font-medium link-neutral">{{ $value }}</a>
                            @else
                                {{ $value }}
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
            <div class="flex flex-wrap gap-4 py-3 text-sm">
                @if (filled($identity['maps_uri'] ?? null))<a href="{{ $identity['maps_uri'] }}" target="_blank" rel="noopener" class="font-medium link-neutral">Haritada aç</a>@endif
                <a href="{{ \App\Services\Gbp\GbpDailyWorkspace::MANAGER_URL }}" target="_blank" rel="noopener" class="font-medium link-neutral">Google’da düzenle</a>
                <a href="{{ route('operator.asset.edit', ['assetId' => $assetId]) }}" wire:navigate class="font-medium text-gray-600 hover:underline dark:text-gray-300">Varlığı düzenle</a>
            </div>
        </section>
    @endif
</div>
