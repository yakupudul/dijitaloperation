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

    @elseif ($tab === 'todo')
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="recheck" class="{{ $btn }}">Standartları kontrol et</button>
            <button type="button" wire:click="compareServices" wire:loading.attr="disabled" @disabled(! $operational || ($servicesState['status'] ?? null) === 'running') class="{{ $btn }}">Hizmetleri karşılaştır</button>
            <button type="button" wire:click="proposeDescription" wire:loading.attr="disabled" @disabled(! $operational || ($descriptionState['status'] ?? null) === 'running') class="{{ $btn }}">Açıklama öner</button>
            @unless ($operational)<span class="text-xs text-gray-500">Marka operasyonel değil; AI kapalı.</span>@endunless
        </div>
        @foreach (['Hizmetleri karşılaştır' => $servicesState, 'Açıklama öner' => $descriptionState] as $label => $state)
            @if ($line = $stateLine($state))
                <p class="text-xs {{ $line[0] }}" @if ($line[2]) wire:poll.5s @endif>{{ $label }}: {{ $line[1] }}</p>
            @endif
        @endforeach

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
                'Sektör' => $brand?->sectorCategory?->name ?? '—',
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
