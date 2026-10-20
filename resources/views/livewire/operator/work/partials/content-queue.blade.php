{{-- Web site SEO içerikler: the operator's steps across every site, each grouped by site. --}}
@php
    $btn = 'h-8 shrink-0 rounded-lg bg-white px-3 text-xs font-medium text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:bg-transparent dark:text-white dark:ring-gray-500 dark:hover:bg-white/5';
    $ghost = 'h-8 shrink-0 rounded-lg px-3 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    $langs = \App\Services\Work\ContentBoard::LANGUAGE_LABELS;
    $chip = 'text-xs text-gray-500 dark:text-gray-400';
    $stepLabels = ['yazilacak' => 'Onay bekleyen başlıklar', 'okunacak' => 'Okunacak yazılar', 'taslakta' => 'Taslakta', 'yayinda' => 'Yayında'];
@endphp
<section class="space-y-6" data-content-queue>
    <div>
        <h2 class="section-title text-gray-900 dark:text-white" title="Onayla, Claude yazsın; Oku, yazıyı ve görselini gör; Gönder, WordPress'e taslak gitsin (geri alınabilir).">İçerik fikirleri</h2>
    </div>

    @include('livewire.operator.work.partials.content-coverage')

    <div role="tablist" class="flex flex-wrap gap-x-6 border-b border-gray-200 dark:border-gray-800">
        @foreach ($stepLabels as $code => $label)
            <button type="button" role="tab" wire:click="setStep('{{ $code }}')" aria-selected="{{ $step === $code ? 'true' : 'false' }}"
                    @class(['-mb-px flex h-10 items-baseline gap-1.5 whitespace-nowrap border-b-2 pt-2.5 text-left text-sm', 'border-gray-900 font-medium text-gray-900 dark:border-white dark:text-white' => $step === $code, 'border-transparent text-gray-500 hover:text-gray-900 dark:text-gray-400' => $step !== $code])
                    data-step="{{ $code }}">
                <span>{{ $label }}</span>
                <span @class(['text-xs font-normal tabular-nums', 'text-warning-700 dark:text-warning-400' => $code === 'okunacak' && $queue['counts'][$code] > 0, 'text-gray-500' => ! ($code === 'okunacak' && $queue['counts'][$code] > 0)])>{{ $queue['counts'][$code] }}</span>
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
        <article wire:key="queue-{{ $step }}-{{ $site->id }}" class="text-sm" data-queue-site="{{ $site->id }}">
            <header class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-gray-200 py-2.5 dark:border-gray-800">
                <div class="min-w-0">
                    <span class="text-xs text-gray-500">{{ $group['brand'] ?? '—' }}</span>
                    <h3 class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $site->domain ?: $site->name }} <span class="ml-1 font-normal text-gray-500">{{ $group['total'] }}</span></h3>
                </div>
                <div class="flex flex-wrap gap-x-2" data-site-languages>
                    @foreach ($group['languages'] as $code => $pages)
                        <span class="text-xs {{ $loop->first ? 'font-medium text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400' }}" title="{{ ($langs[$code] ?? $code).($pages > 0 ? ', '.$pages.' sayfa' : '') }}">{{ strtoupper($code) }}</span>
                    @endforeach
                </div>
                <div class="ml-auto flex items-center gap-2 text-xs">
                    @if ($step === 'yazilacak' && $group['waiting'] > 1)
                        <button type="button" wire:click="writeAll({{ $site->id }})" wire:confirm="{{ min($group['waiting'], \App\Services\Work\ContentBoard::WRITE_ALL_MAX) }} başlık onaylanıp yazdırılsın mı?" class="{{ $btn }}" data-write-all="{{ $site->id }}">Hepsini onayla ve yazdır ({{ min($group['waiting'], \App\Services\Work\ContentBoard::WRITE_ALL_MAX) }})</button>
                    @endif
                    <a href="{{ $group['url'] }}" wire:navigate class="link-neutral">Site</a>
                </div>
            </header>
            @if ($shared->isNotEmpty())
                <div class="space-y-0.5 border-b border-gray-200 py-2 text-xs text-error-700 dark:border-gray-800 dark:text-error-400" data-queue-shared-error>
                    @foreach ($shared as $line => $count)
                        <p>{{ $count }} yazı için son deneme: {{ $line }}</p>
                    @endforeach
                </div>
            @endif
            <ul class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                @foreach ($group['items'] as $item)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1.5 py-2.5" wire:key="queue-item-{{ $item['id'] }}" data-content-item="{{ $item['id'] }}">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                @if ($step === 'yazilacak' && $item['score'] > 0)<span class="text-xs tabular-nums {{ $item['score'] >= 70 ? 'text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400' }}" title="Öncelik puanı: {{ $item['score_line'] }}" data-score="{{ $item['score'] }}">Puan {{ $item['score'] }}</span>@endif
                                @if ($item['rank'] <= 1 && $step === 'yazilacak')<x-ta.pill tone="critical">Acil</x-ta.pill>@endif
                                <span class="{{ $chip }}">{{ $item['kind'] }}</span>
                                <span class="{{ $chip }}">{{ strtoupper($item['language']) }}@foreach ($item['translations'] as $t) + {{ strtoupper($t) }}@endforeach</span>
                                @if ($item['blocked'])<x-ta.pill tone="critical">Kurala takıldı</x-ta.pill>@endif
                                @if ($item['writing'])<x-ta.pill tone="live" data-writing>{{ $step === 'yazilacak' ? 'yazılıyor…' : 'çeviri yazılıyor…' }}</x-ta.pill>@endif
                                @if ($item['angle'])<span class="{{ $chip }}" data-angle>{{ $item['angle'] }}</span>@endif
                            </div>
                            <p class="mt-1 font-medium leading-snug text-gray-900 dark:text-white">{{ $item['title'] }}</p>
                            @include('livewire.operator.work.partials.content-target', ['item' => $item])
                            @if ($step === 'yazilacak' && ($item['cluster'] || $item['reason']))
                                <p class="mt-0.5 text-xs text-gray-500" data-why>@if ($item['cluster'])<span class="font-medium text-gray-700 dark:text-gray-300">Küme: {{ $item['cluster'] }}</span>@if ($item['reason']). @endif @endif{{ $item['reason'] }}</p>
                            @endif
                            @if ($item['line'] && ! $item['writing'] && in_array($step, ['yazilacak', 'okunacak'], true) && ! $shared->has($item['line']))<p class="mt-0.5 text-xs text-error-700 dark:text-error-400">Son deneme: {{ $item['line'] }}</p>@endif
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
                                @if ($item['translating'] !== [])<span class="text-gray-500" data-translating="{{ $item['id'] }}">{{ implode(', ', array_map('strtoupper', $item['translating'])) }} çevirisi hazırlanıyor…</span>@endif
                                @if ($item['blocked'])<button type="button" wire:click="writeContent({{ $item['id'] }})" class="{{ $ghost }}" data-rewrite="{{ $item['id'] }}">Yeniden yaz</button>@endif
                                <button type="button" wire:click="read({{ $item['id'] }})" class="{{ $btn }}" data-read="{{ $item['id'] }}">Oku</button>
                            @else
                                @if ($item['link'])<a href="{{ $item['link'] }}" target="_blank" rel="noopener" class="link-neutral" data-post-link="{{ $item['id'] }}">Sitede aç</a>@endif
                                @if ($item['unsent'] !== [])
                                    <button type="button" wire:click="sendContent({{ $item['id'] }})" wire:confirm="Yeni dil WordPress'e taslak olarak gönderilsin mi?" class="{{ $btn }}">{{ implode(', ', array_map('strtoupper', $item['unsent'])) }} gönder</button>
                                @endif
                                <button type="button" wire:click="read({{ $item['id'] }})" class="{{ $ghost }}">Oku</button>
                            @endif
                        </div>
                    </li>
                @endforeach
                @if ($group['total'] > count($group['items']))
                    <li class="py-2 text-xs text-gray-500">+{{ $group['total'] - count($group['items']) }} daha, <a href="{{ $group['url'] }}" wire:navigate class="link-neutral">sitenin İçerik sekmesinde</a></li>
                @endif
            </ul>
        </article>
    @empty
        <p class="py-6 text-sm text-gray-500">{{ ['yazilacak' => 'Onay bekleyen başlık yok.', 'okunacak' => 'Okunacak yazı yok.', 'taslakta' => 'Sitede taslakta bekleyen yazı yok.', 'yayinda' => 'Son 180 günde gönderilip yayınlanan yazı yok.'][$step] ?? '' }}</p>
    @endforelse
</section>

@include('livewire.operator.work.partials.content-reader')
