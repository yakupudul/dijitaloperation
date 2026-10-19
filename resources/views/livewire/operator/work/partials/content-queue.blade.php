{{-- Web site SEO içerikler: the operator's steps across every site, each grouped by site. --}}
@php
    $btn = 'h-8 shrink-0 rounded-lg bg-white px-3 text-xs font-semibold text-gray-900 ring-1 ring-inset ring-gray-400 hover:bg-gray-50 disabled:opacity-50 dark:bg-transparent dark:text-white dark:ring-gray-500 dark:hover:bg-white/5';
    $ghost = 'h-8 shrink-0 rounded-lg px-3 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    $langs = \App\Services\Work\ContentBoard::LANGUAGE_LABELS;
    $chip = 'rounded px-1.5 py-0.5 text-[10px] font-semibold';
    $stepLabels = ['yazilacak' => 'Onay bekleyen başlıklar', 'okunacak' => 'Okunacak yazılar', 'taslakta' => 'Taslakta', 'yayinda' => 'Yayında'];
@endphp
<section class="space-y-4" data-content-queue>
    <div>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">İçerik fikirleri</h2>
        <p class="mt-0.5 text-xs text-gray-500">Onayla, Claude yazsın; Oku, yazıyı ve görselini gör; Gönder, WordPress'e taslak gitsin (geri alınabilir).</p>
    </div>

    @include('livewire.operator.work.partials.content-coverage')

    <div role="tablist" class="grid grid-cols-2 gap-2 sm:max-w-3xl sm:grid-cols-4">
        @foreach ($stepLabels as $code => $label)
            <button type="button" role="tab" wire:click="setStep('{{ $code }}')" aria-selected="{{ $step === $code ? 'true' : 'false' }}"
                    @class(['rounded-xl bg-white px-3 py-2.5 text-left ring-inset dark:bg-gray-900', 'ring-2 ring-gray-900 dark:ring-white' => $step === $code, 'ring-1 ring-gray-200 dark:ring-gray-800' => $step !== $code])
                    data-step="{{ $code }}">
                <span class="block text-xs text-gray-500">{{ $label }}</span>
                <span @class(['block text-xl font-semibold tabular-nums', 'text-warning-700 dark:text-warning-300' => $code === 'okunacak' && $queue['counts'][$code] > 0, 'text-success-700 dark:text-success-400' => $code === 'yayinda' && $queue['counts'][$code] > 0, 'text-gray-900 dark:text-white' => in_array($code, ['yazilacak', 'taslakta'], true)])>{{ $queue['counts'][$code] }}</span>
            </button>
        @endforeach
    </div>

    @forelse ($queue['groups'][$step] as $group)
        @php
            $site = $group['site'];
            /** The same failure on several articles of one site is said once in the site header (yakup 2026-10-19). */
            $shared = in_array($step, ['yazilacak', 'okunacak'], true)
                ? collect($group['items'])->filter(fn (array $i): bool => $i['line'] && ! $i['writing'])->countBy('line')->filter(fn (int $n): bool => $n > 1)->sortDesc()
                : collect();
        @endphp
        <article wire:key="queue-{{ $step }}-{{ $site->id }}" class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-queue-site="{{ $site->id }}">
            <header class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-4 py-2.5 dark:border-gray-800">
                <div class="min-w-0">
                    <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ $group['brand'] ?? '—' }}</span>
                    <h3 class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $site->domain ?: $site->name }} <span class="font-normal text-gray-500">· {{ $group['total'] }}</span></h3>
                </div>
                <div class="flex flex-wrap gap-1" data-site-languages>
                    @foreach ($group['languages'] as $code => $pages)
                        <span class="{{ $chip }} {{ $loop->first ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'bg-live-50 text-live-700 dark:bg-live-500/10 dark:text-live-300' }}" title="{{ ($langs[$code] ?? $code).($pages > 0 ? ' · '.$pages.' sayfa' : '') }}">{{ strtoupper($code) }}</span>
                    @endforeach
                </div>
                <div class="ml-auto flex items-center gap-2 text-xs">
                    @if ($step === 'yazilacak' && $group['waiting'] > 1)
                        <button type="button" wire:click="writeAll({{ $site->id }})" wire:confirm="{{ min($group['waiting'], \App\Services\Work\ContentBoard::WRITE_ALL_MAX) }} başlık onaylanıp yazdırılsın mı?" class="{{ $btn }}" data-write-all="{{ $site->id }}">Hepsini onayla ve yazdır ({{ min($group['waiting'], \App\Services\Work\ContentBoard::WRITE_ALL_MAX) }})</button>
                    @endif
                    <a href="{{ $group['url'] }}" wire:navigate class="link-neutral">Site →</a>
                </div>
            </header>
            @if ($shared->isNotEmpty())
                <div class="space-y-0.5 border-b border-gray-100 bg-error-50/60 px-4 py-2 text-xs text-error-700 dark:border-gray-800 dark:bg-error-500/10 dark:text-error-300" data-queue-shared-error>
                    @foreach ($shared as $line => $count)
                        <p>{{ $count }} yazı için son deneme: {{ $line }}</p>
                    @endforeach
                </div>
            @endif
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($group['items'] as $item)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-4 py-2.5" wire:key="queue-item-{{ $item['id'] }}" data-content-item="{{ $item['id'] }}">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                @if ($step === 'yazilacak' && $item['score'] > 0)<span class="{{ $chip }} tabular-nums {{ $item['score'] >= 70 ? 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300' : ($item['score'] >= 45 ? 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-300' : 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300') }}" title="Öncelik puanı: {{ $item['score_line'] }}" data-score="{{ $item['score'] }}">Puan {{ $item['score'] }}</span>@endif
                                @if ($item['rank'] <= 1 && $step === 'yazilacak')<span class="{{ $chip }} bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-300">Acil</span>@endif
                                <span class="{{ $chip }} {{ $item['kind'] === 'Güncelleme' ? 'bg-live-50 text-live-700 dark:bg-live-500/10 dark:text-live-300' : 'bg-gray-50 text-gray-700 dark:bg-gray-500/10 dark:text-gray-300' }}">{{ $item['kind'] }}</span>
                                <span class="{{ $chip }} bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ strtoupper($item['language']) }}@foreach ($item['translations'] as $t) + {{ strtoupper($t) }}@endforeach</span>
                                @if ($item['blocked'])<span class="{{ $chip }} bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-300">Kurala takıldı</span>@endif
                                @if ($item['writing'])<span class="{{ $chip }} bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300" data-writing>{{ $step === 'yazilacak' ? 'yazılıyor…' : 'çeviri yazılıyor…' }}</span>@endif
                                @if ($item['angle'])<span class="{{ $chip }} bg-live-50 text-live-700 dark:bg-live-500/10 dark:text-live-300" data-angle>{{ $item['angle'] }}</span>@endif
                            </div>
                            <p class="mt-1 font-medium leading-snug text-gray-900 dark:text-white">{{ $item['title'] }}</p>
                            @include('livewire.operator.work.partials.content-target', ['item' => $item])
                            @if ($step === 'yazilacak' && ($item['cluster'] || $item['reason']))
                                <p class="mt-0.5 text-xs text-gray-500" data-why>@if ($item['cluster'])<span class="font-medium text-gray-700 dark:text-gray-300">Küme: {{ $item['cluster'] }}</span>@if ($item['reason']) · @endif @endif{{ $item['reason'] }}</p>
                            @endif
                            @if ($item['line'] && ! $item['writing'] && in_array($step, ['yazilacak', 'okunacak'], true) && ! $shared->has($item['line']))<p class="mt-0.5 text-xs text-error-600">Son deneme: {{ $item['line'] }}</p>@endif
                            @if (in_array($step, ['taslakta', 'yayinda'], true))
                                @include('livewire.operator.work.partials.content-post', ['item' => $item, 'step' => $step])
                            @endif
                        </div>
                        <div class="flex items-center gap-2 text-xs">
                            @if ($step === 'yazilacak')
                                @if (! $item['writing'])
                                    <button type="button" wire:click="writeContent({{ $item['id'] }})" class="{{ $btn }}" data-write="{{ $item['id'] }}">{{ $item['approved'] ? 'Yeniden yaz' : 'Onayla ve yazdır' }}</button>
                                    @if (! $item['approved'])<button type="button" wire:click="dismiss({{ $item['id'] }})" class="{{ $ghost }}">Reddet</button>@endif
                                @endif
                            @elseif ($step === 'okunacak')
                                @if ($item['translating'] !== [])<span class="text-brand-600" data-translating="{{ $item['id'] }}">{{ implode(', ', array_map('strtoupper', $item['translating'])) }} çevirisi hazırlanıyor…</span>@endif
                                @if ($item['blocked'])<button type="button" wire:click="writeContent({{ $item['id'] }})" class="{{ $ghost }}" data-rewrite="{{ $item['id'] }}">Yeniden yaz</button>@endif
                                <button type="button" wire:click="read({{ $item['id'] }})" class="{{ $btn }}" data-read="{{ $item['id'] }}">Oku</button>
                            @else
                                @if ($item['link'])<a href="{{ $item['link'] }}" target="_blank" rel="noopener" class="font-medium text-brand-600 hover:underline" data-post-link="{{ $item['id'] }}">Sitede aç ↗</a>@endif
                                @if ($item['unsent'] !== [])
                                    <button type="button" wire:click="sendContent({{ $item['id'] }})" wire:confirm="Yeni dil WordPress'e taslak olarak gönderilsin mi?" class="{{ $btn }}">{{ implode(', ', array_map('strtoupper', $item['unsent'])) }} gönder</button>
                                @endif
                                <button type="button" wire:click="read({{ $item['id'] }})" class="{{ $ghost }}">Oku</button>
                            @endif
                        </div>
                    </li>
                @endforeach
                @if ($group['total'] > count($group['items']))
                    <li class="px-4 py-2 text-center text-xs text-gray-500">+{{ $group['total'] - count($group['items']) }} daha · <a href="{{ $group['url'] }}" wire:navigate class="text-brand-600 hover:underline">sitenin İçerik sekmesinde</a></li>
                @endif
            </ul>
        </article>
    @empty
        <p class="rounded-xl bg-white p-6 text-center text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">{{ ['yazilacak' => 'Onay bekleyen başlık yok.', 'okunacak' => 'Okunacak yazı yok.', 'taslakta' => 'Sitede taslakta bekleyen yazı yok.', 'yayinda' => 'Son 180 günde gönderilip yayınlanan yazı yok.'][$step] ?? '' }}</p>
    @endforelse
</section>

@include('livewire.operator.work.partials.content-reader')
