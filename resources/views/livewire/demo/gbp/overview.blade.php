@php
    $num = fn ($value) => $value === null ? '—' : number_format((float) $value, 0, ',', '.');
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
    $panel = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
    $btn = 'rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-inset ring-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-white/[0.04]';
    $primary = 'rounded-lg bg-brand-500 px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $profile = $data['profile'] ?? [];
    $connection = $data['connection'] ?? [];
    $stateLine = function (?array $state): ?array {
        return match ($state['status'] ?? null) {
            'running' => ['text-gray-500', 'Çalışıyor…', true],
            'failed' => ['text-rose-600', (string) ($state['message'] ?? 'Başarısız.'), false],
            'ready' => ['text-emerald-600', (string) ($state['message'] ?? 'Hazır.'), false],
            default => null,
        };
    };
@endphp

<div class="space-y-5">
    @include('livewire.demo.partials.flash')
    <x-operator.asset-context :asset-id="$assetId" />

    <div class="flex flex-col gap-3 border-b border-gray-200 pb-4 dark:border-gray-800 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex min-w-0 items-center gap-3">
            <x-demo.digital-asset-mark type="gbp" size="lg" />
            <div class="min-w-0">
                <h1 class="text-xl font-bold text-gray-900 dark:text-white">{{ $identity['title'] }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $identity['location_line'] ?: '—' }} · Son veri: {{ $identity['last_refresh'] ?? '—' }}</p>
            </div>
        </div>
        <button type="button" wire:click="refreshData" wire:loading.attr="disabled" @disabled(! $bound) class="{{ $primary }}">Verileri yenile</button>
    </div>

    <nav class="flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="İşletme Profili">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')" @class([
                'whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium',
                'border-brand-500 text-brand-600 dark:text-brand-400' => $tab === $key,
                'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200' => $tab !== $key,
            ])>{{ $label }}@if ($key === 'todo' && $openCount > 0) <span class="ml-1 rounded-full bg-brand-50 px-1.5 text-xs text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">{{ $openCount }}</span>@endif</button>
        @endforeach
    </nav>

    @if (! $bound)
        <section class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm dark:border-amber-900/50 dark:bg-amber-950/20">
            <p class="font-semibold text-amber-900 dark:text-amber-200">Konum bağlı değil</p>
            <a href="{{ route('operator.asset.sources', ['assetId' => $assetId]) }}" wire:navigate class="mt-2 inline-flex rounded-lg bg-amber-600 px-3 py-1.5 font-semibold text-white">Bağla</a>
        </section>
    @elseif (($data['migration_mode'] ?? '') !== 'real')
        <p class="rounded-lg bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:bg-amber-950/20 dark:text-amber-200">Veri yok: profil henüz toplanmadı. “Verileri yenile”.</p>
    @endif
    @if (filled($connection['last_error'] ?? null))
        <p class="rounded-lg bg-rose-50 px-4 py-2 text-xs text-rose-700 dark:bg-rose-950/20 dark:text-rose-300">Son toplama: {{ $connection['last_error_hint'] ?? $connection['last_error'] }}</p>
    @endif

    @if ($tab === 'overview')
        @php
            $n = $numbers;
            $views = $n['views'];
            $pct = $views['change_pct'] ?? null;
        @endphp
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5" data-testid="gbp-numbers">
            <section class="{{ $card }}">
                <p class="text-xs text-gray-500">Görüntüleme (harita + arama) · 28 gün</p>
                <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $num($views['current'] ?? null) }}</p>
                <p @class(['text-xs', 'text-emerald-600' => ($pct ?? 0) >= 0, 'text-rose-600' => ($pct ?? 0) < 0, 'text-gray-400' => $pct === null])>{{ $pct === null ? 'önceki dönem yok' : (($pct > 0 ? '+' : '').$pct.'% önceki 28 güne göre') }}</p>
            </section>
            <section class="{{ $card }}">
                <p class="text-xs text-gray-500">Tıklama · 28 gün</p>
                @if ($n['actions'])
                    <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $num($n['actions']['calls'] + $n['actions']['directions'] + $n['actions']['website_clicks']) }}</p>
                    <p class="text-xs text-gray-500">Arama {{ $num($n['actions']['calls']) }} · Yol {{ $num($n['actions']['directions']) }} · Web {{ $num($n['actions']['website_clicks']) }}</p>
                @else
                    <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">—</p><p class="text-xs text-gray-400">veri yok</p>
                @endif
            </section>
            <section class="{{ $card }}">
                <p class="text-xs text-gray-500">Puan</p>
                <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $n['rating'] !== null ? number_format($n['rating'], 1, ',', '.') : '—' }} <span class="text-amber-500">★</span></p>
                <p class="text-xs text-gray-500">{{ $num($n['review_count']) }} yorum</p>
            </section>
            <section class="{{ $card }}">
                <p class="text-xs text-gray-500">Yanıtsız yorum</p>
                <p @class(['mt-1 text-2xl font-bold', 'text-rose-600' => ($n['unanswered'] ?? 0) > 0, 'text-gray-900 dark:text-white' => ($n['unanswered'] ?? 0) === 0])>{{ $num($n['unanswered']) }}</p>
                @if (($n['unanswered'] ?? 0) > 0)<button type="button" wire:click="showUnanswered" class="text-xs font-medium text-brand-600 hover:underline">Yanıtla</button>@endif
            </section>
            <section class="{{ $card }}">
                <p class="text-xs text-gray-500">Profil standartları</p>
                <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $n['standards'] ? $n['standards']['passed'].' / '.$n['standards']['total'] : '—' }}</p>
                <p class="text-xs text-gray-500">geçen / toplam</p>
            </section>
        </div>
        @if ($openCount > 0)
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $openCount }} açık öneri · <button type="button" wire:click="setTab('todo')" class="font-semibold text-brand-600 hover:underline">Yapılacaklar</button></p>
        @endif

        @if ($desk)
            <section class="{{ $card }}" data-testid="gbp-desk-state">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">İşletme profilleri · {{ $desk['score'] }}/6 tamam</h3>
                    <a href="{{ route('operator.gbp-desk', ['marka' => $desk['brand_id'], 'isletme' => $desk['asset_id']]) }}" class="text-xs font-medium text-brand-600 hover:underline">Durum ve ölçümde aç →</a>
                </div>
                <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($desk['checks'] as $key => $check)
                        <a href="{{ route($check['route'], ['marka' => $desk['brand_id']]) }}" wire:key="desk-check-{{ $key }}" @class(['flex items-start gap-2 rounded-lg px-3 py-2 text-xs ring-1 ring-inset hover:bg-gray-50 dark:hover:bg-white/[0.03]', 'ring-emerald-200 dark:ring-emerald-500/30' => $check['ok'], 'ring-amber-200 dark:ring-amber-500/30' => ! $check['ok']])>
                            <span @class(['font-bold', 'text-emerald-600' => $check['ok'], 'text-amber-600' => ! $check['ok']])>{{ $check['ok'] ? '✓' : '!' }}</span>
                            <span><span class="font-semibold text-gray-900 dark:text-white">{{ $check['label'] }}</span><span class="block text-gray-500">{{ $check['hint'] }}</span></span>
                        </a>
                    @endforeach
                </div>
                <h4 class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-500">MoxDOP’un bu profilde yaptıkları</h4>
                @forelse ($desk['history'] as $item)
                    <div wire:key="desk-work-{{ $item['id'] }}" class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 border-b border-gray-100 py-1.5 text-xs last:border-0 dark:border-gray-800">
                        <span class="w-28 shrink-0 tabular-nums text-gray-500">{{ $item['at'] }}</span>
                        <span class="min-w-0 flex-1 text-gray-800 dark:text-gray-200">{{ $item['label'] }}@if ($item['error'])<span class="block text-rose-600">{{ $item['error'] }}</span>@endif</span>
                        <span @class(['font-medium', 'text-emerald-600' => in_array($item['status'], ['succeeded', 'partial'], true), 'text-rose-600' => $item['status'] === 'failed', 'text-gray-500' => ! in_array($item['status'], ['succeeded', 'partial', 'failed'], true)])>{{ $item['status_label'] }}</span>
                        @if ($item['by'] !== '')<span class="text-gray-400">{{ $item['by'] }}</span>@endif
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
            <section class="{{ $panel }} divide-y divide-gray-100 dark:divide-gray-700" data-testid="gbp-approved">
                <h2 class="px-4 py-3 font-semibold text-gray-900 dark:text-white">Uygulanacaklar · {{ $approved->count() }}</h2>
                @foreach ($approved as $s)
                    <div class="flex flex-wrap items-center gap-2 px-4 py-2" wire:key="approved-{{ $s->id }}">
                        <span class="min-w-0 flex-1 text-sm text-gray-800 dark:text-gray-200">{{ $s->title }}</span>
                        <button type="button" wire:click="markApplied({{ $s->id }})" class="rounded bg-success-500 px-2 py-1 text-xs font-semibold text-white hover:bg-success-600">Uygulandı</button>
                    </div>
                @endforeach
            </section>
        @endif

        <section class="{{ $panel }} divide-y divide-gray-100 dark:divide-gray-700" data-testid="gbp-suggestions">
            @forelse ($suggestions as $s)
                <div class="px-4 py-3" wire:key="sug-{{ $s->id }}">
                    <div class="flex flex-wrap items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $s->title }}</p>
                            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $s->reason }}</p>
                            @if ($s->action_type === 'gbp_standard' && filled($s->action['todo'] ?? null))
                                <p class="mt-0.5 text-xs text-gray-500">Yapılacak: {{ $s->action['todo'] }}</p>
                            @endif
                        </div>
                        <div class="flex shrink-0 gap-1.5">
                            <button type="button" wire:click="approveSuggestion({{ $s->id }})" class="rounded bg-success-500 px-2 py-1 text-xs font-semibold text-white hover:bg-success-600">Onayla</button>
                            <button type="button" wire:click="dismissSuggestion({{ $s->id }})" class="rounded px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Reddet</button>
                            <button type="button" wire:click="snoozeSuggestion({{ $s->id }})" class="rounded px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Ertele</button>
                        </div>
                    </div>
                    @if ($s->action_type === 'gbp_description')
                        <div class="mt-2 grid gap-2 md:grid-cols-2" x-data="{ text: @js((string) ($s->action['proposed'] ?? '')) }">
                            <div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/[0.03]">
                                <p class="text-xs font-semibold text-gray-500">Mevcut · {{ mb_strlen((string) ($s->action['current'] ?? '')) }} karakter</p>
                                <p class="mt-1 whitespace-pre-line text-gray-700 dark:text-gray-300">{{ filled($s->action['current'] ?? null) ? $s->action['current'] : 'Açıklama yok' }}</p>
                            </div>
                            <div class="rounded-lg bg-brand-50 p-3 text-sm dark:bg-brand-500/10">
                                <p class="text-xs font-semibold text-brand-700 dark:text-brand-300">Önerilen · {{ mb_strlen((string) ($s->action['proposed'] ?? '')) }} karakter</p>
                                <p class="mt-1 whitespace-pre-line text-gray-800 dark:text-gray-200">{{ $s->action['proposed'] ?? '' }}</p>
                                <button type="button" x-on:click="navigator.clipboard.writeText(text)" class="mt-1 text-xs font-semibold text-brand-600 hover:underline">Kopyala</button>
                                <span class="text-xs text-gray-500">→ Google’da yapıştırın</span>
                            </div>
                        </div>
                    @endif
                    @if (($s->evidence ?? []) !== [])
                        <details class="mt-1 text-xs text-gray-500">
                            <summary class="cursor-pointer">Kanıt</summary>
                            @foreach ($s->evidence as $row)
                                <p class="mt-0.5">@foreach ((array) $row as $k => $v){{ str_replace('_', ' ', (string) $k) }}: {{ is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE) }}@if (! $loop->last) · @endif @endforeach</p>
                            @endforeach
                        </details>
                    @endif
                </div>
            @empty
                <p class="px-4 py-5 text-sm text-gray-500">Açık öneri yok.</p>
            @endforelse
        </section>

    @elseif ($tab === 'reviews')
        @include('livewire.demo.gbp.partials.review-access')
        <section class="{{ $panel }}">
            <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                <h2 class="font-semibold text-gray-900 dark:text-white">Yorumlar</h2>
                <label class="inline-flex items-center gap-1.5 text-sm text-gray-600 dark:text-gray-300"><input type="checkbox" wire:model.live="unanswered" class="rounded border-gray-300"> Yanıtsız</label>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($reviewList as $review)
                    @php
                        $draft = $replyDrafts[$review['id']] ?? ['text' => null, 'state' => null];
                        $write = $review['action'];
                        $sending = $write !== null && in_array($write['status'], ['queued', 'running', 'undoing'], true);
                    @endphp
                    <div class="px-4 py-3" wire:key="review-{{ $review['id'] }}">
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="text-amber-500">{{ $review['rating'] !== null ? str_repeat('★', $review['rating']).str_repeat('☆', 5 - $review['rating']) : '' }}</span>
                            <span class="text-xs text-gray-400">{{ $review['date'] }}</span>
                            <span class="text-xs text-gray-500">{{ $review['reviewer'] }}</span>
                            @if ($review['late'])<span class="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">48 saati geçti</span>@endif
                            <span @class([
                                'ml-auto rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $review['replied'],
                                'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => ! $review['replied'],
                            ])>{{ $review['replied'] ? 'Yanıtlandı' : 'Yanıtsız' }}</span>
                        </div>
                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $review['comment'] !== '' ? \Illuminate\Support\Str::limit($review['comment'], 600) : 'Yorum metni yok' }}</p>
                        @if ($review['replied'] && filled($review['reply']))
                            <div class="mt-2 rounded-lg bg-gray-50 p-2.5 text-sm text-gray-700 dark:bg-white/[0.03] dark:text-gray-300">
                                <p class="text-xs font-semibold text-gray-500">İşletme yanıtı</p>
                                <p class="mt-0.5 whitespace-pre-line">{{ $review['reply'] }}</p>
                                @if ($canWrite && $write !== null && $write['undoable'])
                                    <button type="button" x-on:click="if (confirm('Yanıt Google’dan geri alınsın mı?')) $wire.undoWrite({{ $write['id'] }})" class="mt-1 text-xs font-medium text-rose-600 hover:underline">Geri al</button>
                                @endif
                            </div>
                        @endif
                        @if ($write !== null && $sending)
                            <p class="mt-1 text-xs text-gray-500" wire:poll.5s>Google: {{ $write['label'] }}…</p>
                        @elseif ($write !== null && in_array($write['status'], ['failed', 'undo_failed'], true))
                            <p class="mt-1 text-xs text-rose-600">Google’a gönderilemedi: {{ $write['error'] }}</p>
                        @endif
                        @if (! $review['replied'] && ! $sending)
                            @if ($draft['text'])
                                <div class="mt-2" x-data="{ text: @js($draft['text']) }">
                                    <textarea x-model="text" rows="4" aria-label="Yanıt" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></textarea>
                                    <div class="mt-1 flex flex-wrap items-center gap-3">
                                        @if ($canWrite)
                                            <button type="button" x-on:click="if (text.trim() !== '' && confirm('Yanıt Google’da yayınlansın mı? Sonradan geri alınabilir.')) $wire.publishReply({{ $review['id'] }}, text)" class="rounded bg-success-500 px-2 py-1 text-xs font-semibold text-white hover:bg-success-600">Gönder</button>
                                        @else
                                            <span class="text-xs text-gray-500">Göndermeyi Admin onaylar.</span>
                                        @endif
                                        <button type="button" wire:click="draftReply({{ $review['id'] }})" class="text-xs font-medium text-gray-600 hover:underline dark:text-gray-300">Yeni taslak</button>
                                        <x-operator.ai-prompt-info operation="gbp.review_reply" />
                                    </div>
                                </div>
                            @elseif ($draft['state'] === 'running')
                                <p class="mt-1 text-xs text-gray-500" wire:poll.5s>Yanıt taslağı hazırlanıyor…</p>
                            @else
                                @if (str_starts_with((string) $draft['state'], 'failed'))
                                    <p class="mt-1 text-xs text-rose-600">{{ \Illuminate\Support\Str::after((string) $draft['state'], 'failed: ') }}</p>
                                @endif
                                <div class="mt-2 flex flex-wrap items-start gap-3" x-data="{ open: false, text: '' }">
                                    <button type="button" wire:click="draftReply({{ $review['id'] }})" @disabled(! $operational) class="text-xs font-semibold text-brand-600 hover:underline disabled:opacity-50">Yanıt taslağı</button>
                                    <x-operator.ai-prompt-info operation="gbp.review_reply" />
                                    @if ($canWrite)
                                        <button type="button" x-on:click="open = ! open" class="text-xs font-medium text-gray-600 hover:underline dark:text-gray-300">Kendim yazayım</button>
                                        <span x-show="open" x-cloak class="block w-full">
                                            <textarea x-model="text" rows="3" aria-label="Yanıt" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></textarea>
                                            <button type="button" x-on:click="if (text.trim() !== '' && confirm('Yanıt Google’da yayınlansın mı? Sonradan geri alınabilir.')) $wire.publishReply({{ $review['id'] }}, text)" class="mt-1 rounded bg-success-500 px-2 py-1 text-xs font-semibold text-white hover:bg-success-600">Gönder</button>
                                        </span>
                                    @endif
                                </div>
                            @endif
                        @endif
                    </div>
                @empty
                    <p class="px-4 py-5 text-sm text-gray-500">Yorum yok.</p>
                @endforelse
            </div>
        </section>

    @elseif ($tab === 'posts')
        @php
            $postData = $posts ?? ['items' => [], 'hint' => '', 'late' => false];
            $pState = $stateLine($postState);
        @endphp
        @if ($queue)
            <div class="flex flex-wrap items-center gap-2 rounded-lg bg-sky-50 px-4 py-2 text-sm text-sky-900 dark:bg-sky-500/10 dark:text-sky-200" data-testid="gbp-post-queue">
                <span>Otomatik plan (30 gün): {{ $queue['planned'] }} gün dolu · {{ $queue['drafts'] }} onay bekliyor · {{ $queue['approved'] }} onaylı{{ $queue['low'] ? ' · içerik az' : '' }}.</span>
                <a href="{{ route('operator.gbp-posts', ['isletme' => $assetId]) }}" wire:navigate class="font-semibold underline underline-offset-2">İşletme gönderileri</a>
            </div>
        @endif
        <div class="grid gap-4 xl:grid-cols-5">
            <section class="{{ $card }} space-y-3 xl:col-span-2">
                <div>
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">Siteden paylaş</p>
                    <div class="mt-2 flex gap-2">
                        <select wire:model="sharePageId" aria-label="Sayfa" class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                            <option value="">Sayfa seçin</option>
                            @foreach ($pages as $page)
                                <option value="{{ $page['id'] }}">{{ $page['category'] ? '['.$page['category'].'] ' : '' }}{{ \Illuminate\Support\Str::limit($page['title'], 80) }}</option>
                            @endforeach
                        </select>
                        <button type="button" wire:click="sharePage" wire:loading.attr="disabled" @disabled(! $operational || ($postState['status'] ?? null) === 'running') class="{{ $btn }}">Yaz</button>
                        <x-operator.ai-prompt-info operation="gbp.post_from_page" />
                    </div>
                    @if ($pages === [])<p class="mt-1 text-xs text-gray-500">Markanın sitesinde sayfa yok.</p>@endif
                    @if ($pState)<p class="mt-1 text-xs {{ $pState[0] }}" @if ($pState[2]) wire:poll.5s @endif>{{ $pState[1] }}</p>@endif
                    @if ($postDraft)
                        <div class="mt-2 rounded-lg bg-brand-50 p-3 text-sm dark:bg-brand-500/10">
                            <p class="text-xs font-semibold text-brand-700 dark:text-brand-300">{{ data_get($postDraft->content, 'page_title') }}</p>
                            <p class="mt-1 whitespace-pre-line text-gray-700 dark:text-gray-300">{{ \Illuminate\Support\Str::limit((string) data_get($postDraft->content, 'body'), 400) }}</p>
                            <button type="button" wire:click="useAiDraft({{ $postDraft->id }})" class="mt-1 text-xs font-semibold text-brand-600 hover:underline">Düzenle ve yayınla</button>
                        </div>
                    @endif
                </div>

                @if (! $postFormOpen)
                    <button type="button" wire:click="startPost" class="{{ $btn }}">Yeni gönderi</button>
                @else
                    <div class="space-y-2 border-t border-gray-100 pt-3 dark:border-gray-700">
                        <label class="block text-sm"><span class="text-xs text-gray-500">Metin (en çok 1500)</span>
                            <textarea wire:model="post.body" rows="7" maxlength="1500" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></textarea>
                            @error('post.body')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror
                        </label>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label class="block text-sm"><span class="text-xs text-gray-500">Buton</span>
                                <select wire:model="post.action_type" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                                    @foreach (['LEARN_MORE' => 'Daha fazla bilgi', 'BOOK' => 'Randevu al', 'CALL' => 'Ara', 'ORDER' => 'Sipariş ver', 'SIGN_UP' => 'Kaydol'] as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block text-sm"><span class="text-xs text-gray-500">Bağlantı</span>
                                <input type="url" wire:model="post.url" placeholder="https://" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                                @error('post.url')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror
                            </label>
                        </div>
                        <div class="flex flex-wrap items-center gap-3 text-sm">
                            <label class="inline-flex items-center gap-1"><input type="radio" wire:model.live="post.when" value="now"> Şimdi</label>
                            <label class="inline-flex items-center gap-1"><input type="radio" wire:model.live="post.when" value="later"> Zamanla</label>
                            @if (($post['when'] ?? 'now') === 'later')
                                <input type="datetime-local" wire:model="post.publish_at" aria-label="Yayın zamanı" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                            @endif
                            @error('post.publish_at')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($canWrite)
                                <button type="button" x-on:click="if (confirm('Gönderi onaylansın mı? Yayından sonra geri alınabilir.')) $wire.publishPost()" class="rounded-lg bg-success-500 px-3 py-1.5 text-sm font-semibold text-white hover:bg-success-600">Onayla ve yayınla</button>
                            @else
                                <span class="text-xs text-gray-500">Yayını Admin onaylar.</span>
                            @endif
                            <button type="button" wire:click="cancelPost" class="px-2 text-sm text-gray-500 hover:underline">Vazgeç</button>
                        </div>
                    </div>
                @endif
            </section>

            <section class="{{ $panel }} xl:col-span-3">
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                    <h2 class="font-semibold text-gray-900 dark:text-white">Gönderiler</h2>
                    <span @class(['text-xs', 'text-amber-600' => $postData['late'], 'text-gray-500' => ! $postData['late']])>{{ $postData['hint'] }}</span>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($postData['items'] as $item)
                        <div class="px-4 py-3" wire:key="post-{{ $item['kind'] }}-{{ $item['id'] ?? $loop->index }}">
                            <div class="flex flex-wrap items-center gap-2 text-sm">
                                <span class="font-medium text-gray-900 dark:text-white">{{ $item['title'] }}</span>
                                <span class="text-xs text-gray-400">{{ $item['when'] }}</span>
                                <span @class([
                                    'ml-auto rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                    'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => in_array($item['status'], ['succeeded', 'live'], true),
                                    'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300' => $item['status'] === 'scheduled',
                                    'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => in_array($item['status'], ['failed', 'rejected'], true),
                                    'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300' => ! in_array($item['status'], ['succeeded', 'live', 'scheduled', 'failed', 'rejected'], true),
                                ])>{{ $item['status_label'] }}</span>
                            </div>
                            @if (filled($item['url']) && $item['kind'] === 'moxdop')<p class="text-xs text-gray-500">{{ $item['url'] }}</p>@endif
                            @if (filled($item['error']))<p class="mt-1 text-xs text-rose-600">{{ $item['error'] }}</p>@endif
                            @if (in_array($item['action_status'], ['queued', 'running', 'undoing'], true))<p class="mt-1 text-xs text-gray-500" wire:poll.5s>Google’a gönderiliyor…</p>@endif
                            @if ($item['kind'] === 'moxdop' && $canWrite)
                                @if ($item['scheduled'] ?? false)
                                    <button type="button" x-on:click="if (confirm('Zamanlanmış gönderi iptal edilsin mi?')) $wire.cancelScheduled({{ $item['action_id'] }})" class="mt-1 text-xs font-medium text-rose-600 hover:underline">İptal et</button>
                                @elseif ($item['undoable'])
                                    <button type="button" x-on:click="if (confirm('Gönderi Google’dan silinsin mi?')) $wire.undoWrite({{ $item['action_id'] }})" class="mt-1 text-xs font-medium text-rose-600 hover:underline">Geri al</button>
                                @endif
                            @elseif ($item['kind'] === 'google' && filled($item['url']))
                                <a href="{{ $item['url'] }}" target="_blank" rel="noopener" class="mt-1 inline-block text-xs text-brand-600 hover:underline">Google’da gör ↗</a>
                            @endif
                        </div>
                    @empty
                        <p class="px-4 py-5 text-sm text-gray-500">Gönderi yok.</p>
                    @endforelse
                </div>
            </section>
        </div>

    @elseif ($tab === 'services')
        @php
            $plState = $stateLine($planState);
            $additionalNow = $profile['categories']['additional'] ?? [];
            $chip = fn (string $status): array => match ($status) {
                'new' => ['Eklenecek', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'],
                'exists' => ['Profilde var', 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300'],
                default => ['9 ek kategori sınırı dolu', 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'],
            };
        @endphp
        @if ($peerFound !== null)
            <section class="{{ $card }} mb-4 space-y-3" data-testid="gbp-peers">
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
                        <button type="button" wire:click="pickPeers('common')" class="text-brand-600 hover:underline">En az 2 işletmede olanları seç</button>
                        <button type="button" wire:click="pickPeers('all')" class="text-brand-600 hover:underline">Hepsini seç</button>
                        <button type="button" wire:click="pickPeers('none')" class="text-gray-500 hover:underline">Seçimi temizle</button>
                    </div>
                    <div class="grid gap-4 lg:grid-cols-3">
                        <div>
                            <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Kategoriler ({{ count($peerFound['categories']) }})</p>
                            @forelse ($peerFound['categories'] as $row)
                                <label class="flex items-center gap-2 py-0.5 text-sm" wire:key="peer-{{ $row['key'] }}"><input type="checkbox" wire:model.live="peerPick" value="{{ $row['key'] }}" class="rounded border-gray-300"> <span class="min-w-0 flex-1">{{ $row['name'] }}</span> <span class="text-xs tabular-nums text-gray-400">{{ $row['count'] }} işletme</span></label>
                            @empty
                                <p class="text-xs text-gray-500">Profilde olmayan kategori yok.</p>
                            @endforelse
                        </div>
                        <div class="lg:col-span-2">
                            <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Hizmetler ({{ count($peerFound['services']) }})</p>
                            <div class="max-h-96 overflow-y-auto pr-1 sm:columns-2">
                                @forelse ($peerFound['services'] as $row)
                                    <label class="flex break-inside-avoid items-center gap-2 py-0.5 text-sm" wire:key="peer-{{ $row['key'] }}"><input type="checkbox" wire:model.live="peerPick" value="{{ $row['key'] }}" class="rounded border-gray-300"> <span class="min-w-0 flex-1">{{ $row['name'] }}@if ($row['category'] !== '') <span class="text-xs text-gray-400">· {{ $row['category'] }}</span>@endif</span> <span class="text-xs tabular-nums text-gray-400">{{ $row['count'] }}</span></label>
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
        <div class="grid gap-4 xl:grid-cols-5" data-testid="gbp-services">
            <section class="{{ $card }} space-y-3 xl:col-span-2">
                <div class="text-sm">
                    <p class="font-semibold text-gray-900 dark:text-white">Profilde şu an</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-300"><span class="text-xs text-gray-500">Birincil:</span> {{ $profile['categories']['primary'] ?? '—' }}</p>
                    <p class="text-gray-600 dark:text-gray-300"><span class="text-xs text-gray-500">Ek ({{ count($additionalNow) }}/9):</span> {{ $additionalNow === [] ? '—' : implode(', ', $additionalNow) }}</p>
                    <p class="text-gray-600 dark:text-gray-300"><span class="text-xs text-gray-500">Hizmetler ({{ count($profile['services'] ?? []) }}):</span> {{ ($profile['services'] ?? []) === [] ? '—' : \Illuminate\Support\Str::limit(implode(', ', $profile['services']), 300) }}</p>
                </div>
                <label class="block text-sm"><span class="text-xs text-gray-500">Eklenecek kategoriler (her satıra bir, en çok 10)</span>
                    <textarea wire:model="wantCategories" rows="3" placeholder="Ortodontist&#10;Ağız ve diş sağlığı kliniği" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></textarea>
                </label>
                <label class="block text-sm"><span class="flex items-center justify-between text-xs text-gray-500">Eklenecek hizmetler (her satıra bir, en çok 80)
                        <span class="flex gap-3"><button type="button" wire:click="fillFromOfferings" class="font-medium text-brand-600 hover:underline">Marka hizmetlerinden doldur</button>
                        <button type="button" wire:click="togglePeers" class="font-medium text-brand-600 hover:underline">Aynı sektördeki işletmelerden getir</button></span></span>
                    <textarea wire:model="wantServices" rows="10" placeholder="Diş implantı&#10;Zirkonyum kaplama&#10;&#10;ya da yapıştır:&#10;**Diş Kliniği**&#10;| Hizmet | Açıklama |&#10;|---|---|&#10;| Gülüş Tasarımı | … |" class="mt-1 w-full rounded-lg border-gray-300 font-mono text-xs dark:border-gray-700 dark:bg-gray-900"></textarea>
                    <span class="mt-1 block text-xs text-gray-500">Liste yapıştırabilirsiniz: kalın başlık (ya da “Başlık:”) kategori olur, altındaki tablo satırları (Hizmet | Açıklama, Excel’den sekmeli de olur) o kategorinin hizmetleri olur; açıklamanız aynen kullanılır.</span>
                </label>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" wire:click="preparePlan" wire:loading.attr="disabled" @disabled(! $operational || ! $bound || ($planState['status'] ?? null) === 'running') class="{{ $primary }}">AI ile hazırla</button>
                    <x-operator.ai-prompt-info operation="gbp.profile_plan" />
                </div>
                @if ($plState)<p class="text-xs {{ $plState[0] }}" @if ($plState[2]) wire:poll.10s @endif>{{ $plState[1] }}</p>@endif
                <p class="text-xs text-gray-500">Kategoriler Google’ın kendi listesinden seçilir; hizmetler Google’ın hazır hizmeti ya da kısa açıklamalı özel hizmet olarak hazırlanır. Gönderince yalnız ekleme yapılır: birincil kategori ve mevcut kategori / hizmetler değişmez, geri alınabilir.</p>
            </section>

            <section class="{{ $panel }} xl:col-span-3">
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                    <h2 class="font-semibold text-gray-900 dark:text-white">Hazırlık</h2>
                    @if ($plan)<span class="text-xs text-gray-500">{{ $plan->created_at?->diffForHumans() }}</span>@endif
                </div>
                @if (! $plan)
                    <p class="px-4 py-5 text-sm text-gray-500">Listeyi yazıp “AI ile hazırla”ya basın; Google’a uygun hali burada görünür.</p>
                @else
                    @php
                        $planCategories = (array) data_get($plan->content, 'categories', []);
                        $planServices = (array) data_get($plan->content, 'services', []);
                        $skipped = (array) data_get($plan->content, 'skipped', []);
                    @endphp
                    <div class="divide-y divide-gray-100 dark:divide-gray-700">
                        @if ($planCategories !== [])
                            <p class="px-4 pt-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Kategoriler</p>
                            @foreach ($planCategories as $row)
                                @php [$label, $class] = $chip($row['status']); @endphp
                                <label class="flex items-start gap-3 px-4 py-2" wire:key="plan-cat-{{ $row['id'] }}">
                                    <input type="checkbox" wire:model.live="pickCategories" value="{{ $row['id'] }}" @disabled($row['status'] !== 'new') class="mt-1 rounded border-gray-300">
                                    <span class="min-w-0 flex-1 text-sm">
                                        <span class="font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</span>
                                        <span class="text-xs text-gray-400">← {{ $row['line'] }}</span>
                                        @if (filled($row['reason']))<span class="block text-xs text-gray-500">{{ $row['reason'] }}</span>@endif
                                    </span>
                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $class }}">{{ $label }}</span>
                                </label>
                            @endforeach
                        @endif
                        @if ($planServices !== [])
                            <p class="px-4 pt-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Hizmetler</p>
                            @foreach ($planServices as $index => $row)
                                @php [$label, $class] = $chip($row['status']); @endphp
                                <div class="flex items-start gap-3 px-4 py-2" wire:key="plan-svc-{{ $plan->id }}-{{ $index }}">
                                    <input type="checkbox" wire:model.live="pickServices" value="{{ $index }}" @disabled($row['status'] !== 'new') aria-label="{{ $row['name'] }}" class="mt-1 rounded border-gray-300">
                                    <div class="min-w-0 flex-1 text-sm">
                                        <p><span class="font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</span>
                                            <span class="text-xs text-gray-500">· {{ $row['category'] }} · {{ $row['service_type_id'] ? 'Google’ın hazır hizmeti' : 'Özel hizmet' }}</span></p>
                                        @if ($row['status'] === 'new')
                                            <textarea wire:model="serviceText.{{ $index }}" rows="2" maxlength="300" aria-label="Açıklama" class="mt-1 w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900"></textarea>
                                        @elseif (filled($row['description']))
                                            <p class="text-xs text-gray-500">{{ $row['description'] }}</p>
                                        @endif
                                    </div>
                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $class }}">{{ $label }}</span>
                                </div>
                            @endforeach
                        @endif
                        @if ($skipped !== [])
                            <div class="px-4 py-3">
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Atlananlar</p>
                                @foreach ($skipped as $row)
                                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-300"><span class="font-medium">{{ $row['line'] }}</span>: {{ $row['reason'] }}</p>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 px-4 py-3 dark:border-gray-700">
                        @php $picked = count($pickCategories) + count($pickServices); @endphp
                        @if ($canWrite)
                            <button type="button" @disabled($picked === 0)
                                x-on:click="if (confirm('Seçilen {{ count($pickCategories) }} kategori ve {{ count($pickServices) }} hizmet İşletme Profili’ne eklenecek. Mevcut kategori ve hizmetler değişmez; geri alınabilir. Gönderilsin mi?')) $wire.sendPlan()"
                                class="rounded-lg bg-success-500 px-3 py-1.5 text-sm font-semibold text-white hover:bg-success-600 disabled:opacity-50">Seçilenleri gönder ({{ $picked }})</button>
                        @else
                            <span class="text-xs text-gray-500">Gönderimi Admin onaylar.</span>
                        @endif
                        <button type="button" wire:click="discardPlan" class="px-2 text-sm text-gray-500 hover:underline">Hazırlığı sil</button>
                    </div>
                @endif

                @if ($profileWrites->isNotEmpty())
                    <div class="border-t border-gray-100 dark:border-gray-700">
                        <p class="px-4 pt-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Gönderilenler</p>
                        @foreach ($profileWrites as $write)
                            @php
                                $sentCategories = collect(data_get($write->request_payload, 'categories', []))->pluck('name')->all();
                                $sentServices = collect(data_get($write->request_payload, 'services', []))->pluck('name')->all();
                            @endphp
                            <div class="px-4 py-2 text-sm" wire:key="profile-write-{{ $write->id }}">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-xs text-gray-400">{{ $write->created_at?->format('d.m.Y H:i') }}</span>
                                    <span class="min-w-0 flex-1 text-gray-800 dark:text-gray-200">{{ \Illuminate\Support\Str::limit(implode(', ', [...$sentCategories, ...$sentServices]), 160) }}</span>
                                    <span @class([
                                        'rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                        'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $write->status === 'succeeded',
                                        'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => in_array($write->status, ['failed', 'undo_failed', 'partial'], true),
                                        'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300' => ! in_array($write->status, ['succeeded', 'failed', 'undo_failed', 'partial'], true),
                                    ])>{{ $write->statusLabel() }}</span>
                                </div>
                                @if (filled($write->error) || filled(data_get($write->result, 'error')))<p class="mt-1 text-xs text-rose-600">{{ $write->error ?: data_get($write->result, 'error') }}</p>@endif
                                @if (in_array($write->status, ['queued', 'running', 'undoing'], true))<p class="mt-1 text-xs text-gray-500" wire:poll.5s>Google’a gönderiliyor…</p>@endif
                                @if ($canWrite && $write->isUndoable())
                                    <button type="button" x-on:click="if (confirm('Bu gönderimle eklenen kategori ve hizmetler profilden kaldırılsın mı?')) $wire.undoWrite({{ $write->id }})" class="mt-1 text-xs font-medium text-rose-600 hover:underline">Geri al</button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>

    @elseif ($tab === 'analysis')
        <div class="flex gap-1">
            @foreach ($dayOptions as $option)
                <button type="button" wire:click="setDays({{ $option }})" @class(['rounded-lg px-3 py-1 text-sm font-medium', 'bg-brand-500 text-white' => $days === $option, 'text-gray-600 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700' => $days !== $option])>{{ $option }} gün</button>
            @endforeach
        </div>
        @if ($analysis === null || ($analysis['daily'] === [] && $analysis['keywords']['rows'] === [] && $analysis['reviews'] === []))
            <p class="{{ $card }} text-sm text-gray-500">Veri yok.</p>
        @else
            <section class="{{ $panel }} overflow-x-auto" data-testid="gbp-daily">
                <h2 class="border-b border-gray-100 px-4 py-3 font-semibold text-gray-900 dark:border-gray-700 dark:text-white">Günlük performans</h2>
                @if ($analysis['daily'] === [])
                    <p class="px-4 py-4 text-sm text-gray-500">Veri yok.</p>
                @else
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-xs text-gray-500"><th class="px-4 py-2 font-medium">Tarih</th><th class="px-3 py-2 text-right font-medium">Arama görüntüleme</th><th class="px-3 py-2 text-right font-medium">Harita görüntüleme</th><th class="px-3 py-2 text-right font-medium">Telefon</th><th class="px-3 py-2 text-right font-medium">Yol tarifi</th><th class="px-3 py-2 text-right font-medium">Web tıklama</th></tr></thead>
                        <tbody class="divide-y divide-gray-100 tabular-nums dark:divide-gray-700">
                            <tr class="font-semibold text-gray-900 dark:text-white"><td class="px-4 py-1.5">Toplam</td>@foreach (['search_views', 'maps_views', 'calls', 'directions', 'website_clicks'] as $k)<td class="px-3 py-1.5 text-right">{{ $num($analysis['totals'][$k]) }}</td>@endforeach</tr>
                            @foreach ($analysis['daily'] as $day)
                                <tr class="text-gray-700 dark:text-gray-300"><td class="px-4 py-1.5">{{ $day['date'] }}</td>@foreach (['search_views', 'maps_views', 'calls', 'directions', 'website_clicks'] as $k)<td class="px-3 py-1.5 text-right">{{ $num($day[$k]) }}</td>@endforeach</tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
            <div class="grid gap-4 xl:grid-cols-2">
                <section class="{{ $panel }} overflow-x-auto" data-testid="gbp-keywords">
                    <h2 class="border-b border-gray-100 px-4 py-3 font-semibold text-gray-900 dark:border-gray-700 dark:text-white">Arama ifadeleri (ilk 20)</h2>
                    @if ($analysis['keywords']['rows'] === [])
                        <p class="px-4 py-4 text-sm text-gray-500">Veri yok.</p>
                    @else
                        <table class="w-full text-sm">
                            <thead><tr class="text-left text-xs text-gray-500"><th class="px-4 py-2 font-medium">İfade</th>@foreach ($analysis['keywords']['months'] as $month)<th class="px-3 py-2 text-right font-medium">{{ $month }}</th>@endforeach</tr></thead>
                            <tbody class="divide-y divide-gray-100 tabular-nums dark:divide-gray-700">
                                @foreach ($analysis['keywords']['rows'] as $row)
                                    <tr class="text-gray-700 dark:text-gray-300"><td class="px-4 py-1.5">{{ $row['keyword'] }}</td>@foreach ($row['values'] as $value)<td class="px-3 py-1.5 text-right">{{ $value === null ? '<15' : $num($value) }}</td>@endforeach</tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </section>
                <section class="{{ $panel }}" data-testid="gbp-review-trend">
                    <h2 class="border-b border-gray-100 px-4 py-3 font-semibold text-gray-900 dark:border-gray-700 dark:text-white">Yorum trendi</h2>
                    @if ($analysis['reviews'] === [])
                        <p class="px-4 py-4 text-sm text-gray-500">Son 12 ayda yorum yok.</p>
                    @else
                        <table class="w-full text-sm">
                            <thead><tr class="text-left text-xs text-gray-500"><th class="px-4 py-2 font-medium">Ay</th><th class="px-3 py-2 text-right font-medium">Yorum</th><th class="px-3 py-2 text-right font-medium">Ortalama</th></tr></thead>
                            <tbody class="divide-y divide-gray-100 tabular-nums dark:divide-gray-700">
                                @foreach ($analysis['reviews'] as $row)
                                    <tr class="text-gray-700 dark:text-gray-300"><td class="px-4 py-1.5">{{ $row['month'] }}</td><td class="px-3 py-1.5 text-right">{{ $row['count'] }}</td><td class="px-3 py-1.5 text-right">{{ $row['average'] !== null ? number_format($row['average'], 1, ',', '.').' ★' : '—' }}</td></tr>
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
                'Son toplama' => trim(($connection['last_run_label'] ?? '—').' · '.($connection['last_run_human'] ?? '')),
            ];
        @endphp
        <section class="{{ $panel }}" data-testid="gbp-settings">
            <dl class="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                @foreach ($rows as $label => $value)
                    <div class="grid gap-1 px-4 py-2 sm:grid-cols-4">
                        <dt class="text-gray-500">{{ $label }}</dt>
                        <dd class="whitespace-pre-line text-gray-900 dark:text-gray-100 sm:col-span-3">
                            @if ($label === 'Marka' && $brand)
                                <a href="{{ route('operator.brand', ['brand' => $brand->id]) }}" wire:navigate class="font-medium text-brand-600 hover:underline">{{ $value }}</a>
                            @else
                                {{ $value }}
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
            <div class="flex flex-wrap gap-3 border-t border-gray-100 px-4 py-3 text-sm dark:border-gray-700">
                @if (filled($identity['maps_uri'] ?? null))<a href="{{ $identity['maps_uri'] }}" target="_blank" rel="noopener" class="font-medium text-brand-600 hover:underline">Haritada aç ↗</a>@endif
                <a href="{{ \App\Services\Gbp\GbpDailyWorkspace::MANAGER_URL }}" target="_blank" rel="noopener" class="font-medium text-brand-600 hover:underline">Google’da düzenle ↗</a>
                <a href="{{ route('operator.asset.edit', ['assetId' => $assetId]) }}" wire:navigate class="font-medium text-gray-600 hover:underline dark:text-gray-300">Varlığı düzenle</a>
            </div>
        </section>
    @endif
</div>
