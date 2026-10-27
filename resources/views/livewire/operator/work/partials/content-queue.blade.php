{{-- Web site SEO içerikler: the operator's steps across every site, each grouped by site. --}}
@php
    $btn = 'btn';
    $ghost = 'btn-quiet';
    $langs = \App\Services\Work\ContentBoard::LANGUAGE_LABELS;
    $chip = 'text-xs text-gray-500 dark:text-gray-400';
    $readyTotal = array_sum($readyBySite ?? []);
    $readySites = count($readyBySite ?? []);
    $stepLabels = ['yazilacak' => 'Onay bekleyen başlıklar', 'okunacak' => 'Gönderime hazır', 'taslakta' => 'Taslakta', 'yayinda' => 'Yayında'];
@endphp
<section class="space-y-4" data-content-queue>
    <div class="section-head mb-0!">
        <h2 class="section-title" title="Onayla, Claude yazsın; Claude her yazıyı kontrol eder; Gönder, WordPress'e taslak gitsin (geri alınabilir). Okumak isteğe bağlı.">İçerik fikirleri</h2>
        @if ($readyTotal > 0 && auth()->user()?->hasRole(\App\Support\Roles::ADMIN))
            <button type="button" wire:click="sendAllContent" wire:confirm="Claude'un kontrolünden geçen {{ $readyTotal }} yazı, çevirileri ve görselleriyle WordPress'e taslak olarak gönderilsin mi? Tarihi sitede «Akıllı tarihle yayınla» ile verirsin; geri alınabilir."
                    class="btn-primary ml-auto" data-send-all-content>Tümünü taslak olarak gönder ({{ $readyTotal }} yazı · {{ $readySites }} site)</button>
        @endif
    </div>

    @include('livewire.operator.work.partials.content-coverage')

    <div role="tablist" class="no-scrollbar flex gap-1.5 overflow-x-auto pt-4">
        @foreach ($stepLabels as $code => $label)
            <button type="button" role="tab" wire:click="setStep('{{ $code }}')" aria-selected="{{ $step === $code ? 'true' : 'false' }}"
                    @class(['seg', 'seg-active' => $step === $code])
                    data-step="{{ $code }}">
                <span>{{ $label }}</span>
                <span @class(['text-xs font-normal tabular-nums', 'text-accent' => $step === $code, 'text-warning-700 dark:text-warning-400' => $step !== $code && $code === 'okunacak' && $queue['counts'][$code] > 0, 'text-gray-400 dark:text-gray-500' => $step !== $code && ! ($code === 'okunacak' && $queue['counts'][$code] > 0)])>{{ $queue['counts'][$code] }}</span>
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
        {{-- One line per site; its articles open on a click (yakup 2026-10-20: the page was 33 screens long). --}}
        <article wire:key="queue-{{ $step }}-{{ $site->id }}" class="card text-sm" data-queue-site="{{ $site->id }}" x-data="{ open: {{ count($queue['groups'][$step]) === 1 ? 'true' : 'false' }} }">
            <header class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3.5" :class="open && 'border-b border-gray-200 dark:border-gray-800'">
                <button type="button" @click="open = ! open" :aria-expanded="open" class="-m-1 flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 dark:hover:bg-white/5" aria-label="Yazıları göster" data-queue-toggle="{{ $site->id }}">
                    <svg class="h-4 w-4 transition" :class="open && 'rotate-90'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.2 14.8a.75.75 0 0 1 0-1.06L10.94 10 7.2 6.26a.75.75 0 1 1 1.06-1.06l4.27 4.27a.75.75 0 0 1 0 1.06L8.26 14.8a.75.75 0 0 1-1.06 0z" clip-rule="evenodd"/></svg>
                </button>
                <x-ta.brand-avatar :name="$group['brand'] ?? $site->name" :logo="$workLogos[(int) ($brands->firstWhere('name', $group['brand'])?->id)] ?? null" size="sm" />
                <div class="min-w-0">
                    <h3 class="truncate text-sm font-medium text-gray-900 dark:text-white"><button type="button" @click="open = ! open" class="hover:underline">{{ $site->domain ?: $site->name }}</button> <span class="ml-1 font-normal text-gray-500">{{ $group['total'] }} yazı</span></h3>
                    <span class="block truncate text-xs text-gray-500">{{ $group['brand'] ?? '—' }}</span>
                </div>
                <div class="flex w-full items-center gap-2 sm:ml-auto sm:w-auto">
                    @if ($step === 'okunacak' && ($readyBySite[$site->id] ?? 0) > 0 && $readySites > 1 && auth()->user()?->hasRole(\App\Support\Roles::ADMIN))
                        <button type="button" wire:click="sendAllContent({{ $site->id }})" wire:confirm="Bu sitenin kontrolden geçen {{ $readyBySite[$site->id] }} yazısı WordPress'e taslak olarak gönderilsin mi?" class="btn-dark" data-send-site="{{ $site->id }}">Bu siteyi gönder ({{ $readyBySite[$site->id] }})</button>
                    @endif
                    @if ($step === 'yazilacak' && $group['waiting'] > 1)
                        <button type="button" wire:click="writeAll({{ $site->id }})" wire:confirm="{{ min($group['waiting'], \App\Services\Work\ContentBoard::WRITE_ALL_MAX) }} başlık onaylanıp yazdırılsın mı?" class="btn-dark" data-write-all="{{ $site->id }}">Hepsini onayla ve yazdır ({{ min($group['waiting'], \App\Services\Work\ContentBoard::WRITE_ALL_MAX) }})</button>
                    @endif
                    <a href="{{ $group['url'] }}" wire:navigate class="link-neutral text-[13px]">Site</a>
                </div>
            </header>
            <div x-show="open" x-cloak>
            @if ($shared->isNotEmpty())
                <div class="space-y-0.5 border-b border-gray-200 px-4 py-2.5 text-xs text-error-700 dark:border-gray-800 dark:text-error-400" data-queue-shared-error>
                    @foreach ($shared as $line => $count)
                        <p>{{ $count }} yazı için son deneme: {{ $line }}</p>
                    @endforeach
                </div>
            @endif
            <ul>
                @foreach ($group['items'] as $item)
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-2.5 border-t border-gray-200 px-4 py-3.5 first:border-t-0 sm:flex-nowrap dark:border-gray-800" wire:key="queue-item-{{ $item['id'] }}" data-content-item="{{ $item['id'] }}">
                        <div class="min-w-0 flex-1 basis-64">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                @if ($item['rank'] <= 1 && $step === 'yazilacak')<x-ta.pill tone="critical">Acil</x-ta.pill>@endif
                                <span class="{{ $chip }}" @if ($step === 'yazilacak' && $item['score'] > 0) title="Öncelik puanı {{ $item['score'] }}: {{ $item['score_line'] }}" data-score="{{ $item['score'] }}" @endif>{{ $item['kind'] }}</span>
                                @if ($item['translations'] !== [])<span class="{{ $chip }}">{{ strtoupper($item['language']) }}@foreach ($item['translations'] as $t) + {{ strtoupper($t) }}@endforeach</span>@endif
                                @if ($item['blocked'])@if ($item['fixing'])<x-ta.pill tone="live" :title="$item['blocked']" data-check="fixing">Claude kurala uyduruyor</x-ta.pill>@else<x-ta.pill tone="critical">Kurala takıldı</x-ta.pill>@endif @endif
                                @if ($step === 'okunacak' && ! $item['blocked'] && ($item['sendable'] || $item['fixing']))@include('livewire.operator.work.partials.content-check', ['item' => $item])@endif
                                @if ($item['writing'])<x-ta.pill tone="live" data-writing>{{ $step === 'yazilacak' ? 'yazılıyor…' : 'çeviri yazılıyor…' }}</x-ta.pill>@endif
                            </div>
                            <p class="mt-1 font-medium leading-snug text-gray-900 dark:text-white">{{ $item['title'] }}</p>
                            @include('livewire.operator.work.partials.content-target', ['item' => $item])
                            @if ($item['blocked'] && ! $item['fixing'])<p class="mt-0.5 text-xs text-warning-700 dark:text-warning-400" data-blocked-reason>Claude düzeltemedi: {{ \App\Services\Work\ContentBoard::plainReason($item['blocked']) }}.</p>@endif
                            @if ($step === 'yazilacak' && ($item['cluster'] || $item['reason']))
                                <details class="mt-0.5 text-xs text-gray-500" data-why>
                                    <summary class="cursor-pointer list-none text-gray-500 hover:text-gray-900 dark:hover:text-white">Neden?</summary>
                                    <p class="mt-0.5">@if ($item['cluster'])<span class="font-medium text-gray-700 dark:text-gray-300">Konu: {{ $item['cluster'] }}</span>@if ($item['reason']). @endif @endif{{ $item['reason'] }}@if ($item['angle']) Açı: {{ $item['angle'] }}.@endif</p>
                                </details>
                            @endif
                            @if ($item['line'] && ! $item['writing'] && in_array($step, ['yazilacak', 'okunacak'], true) && ! $shared->has($item['line']))<p class="mt-0.5 text-xs text-error-700 dark:text-error-400">Son deneme: {{ $item['line'] }}</p>@endif
                            @if (in_array($step, ['taslakta', 'yayinda'], true))
                                @include('livewire.operator.work.partials.content-post', ['item' => $item, 'step' => $step])
                            @endif
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            @if ($step === 'yazilacak')
                                @if (! $item['writing'])
                                    <button type="button" wire:click="writeContent({{ $item['id'] }})" class="{{ $item['approved'] ? $btn : 'btn-dark' }}" data-write="{{ $item['id'] }}">{{ $item['approved'] ? 'Yeniden yaz' : 'Onayla ve yazdır' }}</button>
                                    @if (! $item['approved'])<button type="button" wire:click="dismiss({{ $item['id'] }})" class="{{ $ghost }}">Reddet</button>@endif
                                @endif
                            @elseif ($step === 'okunacak')
                                @if ($item['translating'] !== [])<span class="text-xs text-gray-500" data-translating="{{ $item['id'] }}">{{ implode(', ', array_map('strtoupper', $item['translating'])) }} çevirisi hazırlanıyor…</span>@endif
                                <button type="button" wire:click="read({{ $item['id'] }})" class="{{ $ghost }}" data-read="{{ $item['id'] }}">Oku</button>
                                @if ($item['sendable'] && ! $item['fixing'] && $item['translating'] === [])
                                    <button type="button" wire:click="sendContent({{ $item['id'] }})" wire:confirm="Yazı çevirileriyle WordPress'e taslak olarak gönderilsin mi? Geri alınabilir." class="{{ ($item['check']['verdict'] ?? null) === 'ok' ? 'btn-dark' : $btn }}" data-send="{{ $item['id'] }}">{{ in_array($item['check']['verdict'] ?? null, ['wrong', 'unsure'], true) ? 'Yine de gönder' : 'Siteye gönder' }}</button>
                                @endif
                            @else
                                @if ($item['link'])<a href="{{ $item['link'] }}" target="_blank" rel="noopener" class="link-neutral text-[13px]" data-post-link="{{ $item['id'] }}">Sitede aç</a>@endif
                                @if ($item['unsent'] !== [])
                                    <button type="button" wire:click="sendContent({{ $item['id'] }})" wire:confirm="Yeni dil WordPress'e taslak olarak gönderilsin mi?" class="{{ $btn }}">{{ implode(', ', array_map('strtoupper', $item['unsent'])) }} gönder</button>
                                @endif
                                <button type="button" wire:click="read({{ $item['id'] }})" class="{{ $ghost }}">Oku</button>
                            @endif
                        </div>
                    </li>
                @endforeach
                @if ($group['total'] > count($group['items']))
                    <li class="border-t border-gray-200 px-4 py-2.5 text-[13px] text-gray-500 dark:border-gray-800">+{{ $group['total'] - count($group['items']) }} daha, <a href="{{ $group['url'] }}" wire:navigate class="link-neutral">sitenin İçerik sekmesinde</a></li>
                @endif
            </ul>
            </div>
        </article>
    @empty
        <div class="card"><x-ta.empty-state size="sm" :icon="in_array($step, ['yazilacak', 'okunacak'], true) ? 'check' : 'none'" :title="['yazilacak' => 'Onay bekleyen başlık yok.', 'okunacak' => 'Gönderime hazır yazı yok.', 'taslakta' => 'Sitede taslakta bekleyen yazı yok.', 'yayinda' => 'Son 180 günde gönderilip yayınlanan yazı yok.'][$step] ?? ''" /></div>
    @endforelse
</section>

@include('livewire.operator.work.partials.content-reader')
