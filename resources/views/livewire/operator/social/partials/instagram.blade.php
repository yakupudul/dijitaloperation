{{-- Instagram görünümü (yakup 2026-11-08: "Instagram görünümünde olmalı"): the profile as it looks on Instagram. Ready posts
     first (waiting for approval or for their time), then what went out. A tile opens the post: approve and publish a
     ready one, open or take down a published one. --}}
@php
    $ig = $profile['account'];
    $tile = function ($post): array {
        $media = $post->mediaItems();

        return ['first' => $media[0] ?? null, 'many' => count($media) > 1];
    };
@endphp
<section class="mx-auto max-w-[935px]" data-social-instagram>
    <header class="flex items-center gap-5 border-b border-gray-200 pb-6 sm:gap-10 sm:px-8 dark:border-gray-800">
        <span class="flex h-20 w-20 shrink-0 items-center justify-center rounded-full bg-[#d62976] p-[3px] sm:h-36 sm:w-36">
            <span class="flex h-full w-full items-center justify-center rounded-full border-4 border-white bg-[#FDCF3C] text-2xl font-semibold text-gray-900 sm:text-4xl dark:border-gray-900">M</span>
        </span>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-3">
                <h2 class="truncate text-xl text-gray-900 dark:text-white">{{ $ig?->username ?? 'moximucom' }}</h2>
                @if ($ig?->profileUrl())<a href="{{ $ig->profileUrl() }}" target="_blank" rel="noopener" class="btn h-8 px-3 text-[13px]">Instagram'da aç ↗</a>@endif
                <button type="button" wire:click="setTab('yeni')" class="btn h-8 px-3 text-[13px]">+ Yeni gönderi</button>
            </div>
            <p class="mt-3 flex gap-6 text-sm text-gray-900 dark:text-white">
                <span><span class="font-semibold tabular-nums">{{ $profile['grid']->count() }}</span> gönderi</span>
                <span><span class="font-semibold tabular-nums">{{ $profile['ready']->count() }}</span> hazır</span>
                @if ($profile['waiting'] > 0)<span class="text-warning-700 dark:text-warning-400"><span class="font-semibold tabular-nums">{{ $profile['waiting'] }}</span> onay bekliyor</span>@endif
            </p>
            @if ($ig === null)<p class="mt-2 text-[13px] text-gray-500">Instagram hesabı bağlı değil; gönderiler hazırlanır ama yayınlanamaz.</p>@endif
        </div>
    </header>

    @if ($profile['ready']->isNotEmpty())
        <h3 class="mt-6 mb-2 text-xs font-semibold tracking-wide text-gray-500">HAZIR GÖNDERİLER</h3>
        <div class="grid grid-cols-3 gap-1" data-social-ready-grid>
            @foreach ($profile['ready'] as $post)
                @php $t = $tile($post); @endphp
                <button type="button" wire:key="ig-ready-{{ $post->id }}" wire:click="openPost({{ $post->id }})" class="group relative aspect-[4/5] overflow-hidden bg-gray-100 dark:bg-white/5" data-social-tile="{{ $post->id }}">
                    @if ($t['first'] && $t['first']['type'] === 'video')<video src="{{ $t['first']['url'] }}" muted preload="metadata" class="h-full w-full object-cover"></video>@elseif ($t['first'])<img src="{{ $t['first']['url'] }}" alt="" loading="lazy" class="h-full w-full object-cover" />@endif
                    <span @class(['absolute top-1.5 left-1.5 rounded px-1.5 py-0.5 text-[11px] font-medium', 'bg-warning-400 text-gray-900' => $post->status === 'draft', 'bg-white/90 text-gray-900' => $post->status === 'approved', 'bg-error-600 text-white' => in_array($post->status, ['failed', 'partial'], true)])>{{ match ($post->status) { 'draft' => 'Onay bekliyor', 'approved' => $post->publish_at->timezone($tz)->format('d.m H:i'), default => 'Yayınlanamadı' } }}</span>
                    @if ($t['many'])<span class="absolute top-1.5 right-1.5 text-white drop-shadow" aria-label="Kaydırmalı">❐</span>@elseif ($post->kind === 'reels')<span class="absolute top-1.5 right-1.5 text-white drop-shadow" aria-label="Reels">▶</span>@endif
                    <span class="absolute inset-0 bg-black/0 transition group-hover:bg-black/20"></span>
                </button>
            @endforeach
        </div>
    @endif

    <h3 class="mt-6 mb-2 flex items-center justify-center gap-1.5 border-t border-gray-900 pt-3 text-xs font-semibold tracking-wide text-gray-900 dark:border-white dark:text-white">▦ GÖNDERİLER</h3>
    <div class="grid grid-cols-3 gap-1" data-social-published-grid>
        @forelse ($profile['grid'] as $cell)
            @php $post = $cell['post']; $m = $cell['ig']; $t = $post !== null ? $tile($post) : null; $img = $t['first']['url'] ?? ($m['image'] ?? null); $video = ($t['first']['type'] ?? null) === 'video'; @endphp
            <button type="button" wire:key="ig-cell-{{ $post?->id ?? 'x' }}-{{ $m['id'] ?? 'own' }}" @if ($post !== null) wire:click="openPost({{ $post->id }})" @else wire:click="openIgPost('{{ $m['id'] }}')" @endif class="group relative aspect-[4/5] overflow-hidden bg-gray-100 dark:bg-white/5" data-social-tile="{{ $post?->id ?? 'ig-'.$m['id'] }}">
                @if ($video && $img)<video src="{{ $img }}" muted preload="metadata" class="h-full w-full object-cover"></video>@elseif ($img)<img src="{{ $img }}" alt="" loading="lazy" referrerpolicy="no-referrer" class="h-full w-full object-cover" />@endif
                @if (($t['many'] ?? false) || ($m['type'] ?? '') === 'CAROUSEL_ALBUM')<span class="absolute top-1.5 right-1.5 text-white drop-shadow" aria-label="Kaydırmalı">❐</span>@elseif (($post?->kind ?? '') === 'reels' || ($m['type'] ?? '') === 'VIDEO')<span class="absolute top-1.5 right-1.5 text-white drop-shadow" aria-label="Reels">▶</span>@endif
                @if ($m !== null)<span class="absolute inset-0 flex items-center justify-center gap-4 bg-black/40 text-sm font-semibold text-white opacity-0 transition group-hover:opacity-100">♥ {{ $m['likes'] }} &nbsp; 💬 {{ $m['comments'] }}</span>@else<span class="absolute inset-0 bg-black/0 transition group-hover:bg-black/20"></span>@endif
            </button>
        @empty
            <p class="col-span-3 py-10 text-center text-sm text-gray-500">{{ $ig === null ? 'Instagram hesabı bağlı değil.' : 'Instagram\'dan gönderi okunamadı ya da henüz gönderi yok.' }} <button type="button" wire:click="refreshFeed" class="link-neutral">Yeniden oku</button></p>
        @endforelse
    </div>

    @if ($openedIg !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-3 sm:p-8" wire:click.self="closePost" x-data x-on:keydown.escape.window="$wire.closePost()" data-social-ig-modal="{{ $openedIg['id'] }}">
            <div class="flex max-h-full w-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white md:flex-row dark:bg-gray-900">
                <div class="flex items-center justify-center bg-black md:w-3/5">
                    @if ($openedIg['image'])<img src="{{ $openedIg['image'] }}" alt="" referrerpolicy="no-referrer" class="max-h-[80vh] max-w-full object-contain" />@endif
                </div>
                <div class="flex min-h-0 flex-1 flex-col md:w-2/5">
                    <div class="flex items-center justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                        <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ $ig?->username ?? 'moximucom' }}</span>
                        <button type="button" wire:click="closePost" class="text-gray-500 hover:text-gray-900 dark:hover:text-white" aria-label="Kapat">✕</button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
                        <p class="text-xs text-gray-500">{{ $openedIg['at'] ? \Carbon\CarbonImmutable::parse($openedIg['at'])->timezone($tz)->translatedFormat('j F Y H:i') : '' }} · ♥ {{ $openedIg['likes'] }} · 💬 {{ $openedIg['comments'] }}</p>
                        <p class="mt-2 text-sm whitespace-pre-line text-gray-900 dark:text-gray-100">{{ $openedIg['caption'] }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 border-t border-gray-200 px-4 py-3 dark:border-gray-800">
                        @if ($openedIg['permalink'])<a href="{{ $openedIg['permalink'] }}" target="_blank" rel="noopener" class="btn h-9 px-3 text-[13px]">Instagram'da aç ↗</a>@endif
                        <span class="text-xs text-gray-500">Bu gönderi telefondan paylaşılmış. Meta, Instagram gönderisinin dışarıdan silinmesine izin vermiyor; silmek için Instagram'da aç.</span>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($opened !== null)
        @php $media = $opened->mediaItems(); $igTarget = $opened->targets->first(fn ($t) => $t->account?->platform === 'instagram'); $fbTarget = $opened->targets->first(fn ($t) => $t->account?->platform === 'facebook'); $isReady = in_array($opened->status, ['draft', 'approved', 'failed', 'partial', 'expired'], true) && ! $opened->targets->contains(fn ($t) => $t->status === 'published'); @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-3 sm:p-8" wire:click.self="closePost" x-data x-on:keydown.escape.window="$wire.closePost()" data-social-post-modal="{{ $opened->id }}">
            <div class="flex max-h-full w-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white md:flex-row dark:bg-gray-900">
                <div class="flex min-h-0 snap-x snap-mandatory overflow-x-auto bg-black md:w-3/5">
                    @foreach ($media as $item)
                        <div class="flex aspect-square w-full shrink-0 snap-center items-center justify-center">
                            @if ($item['type'] === 'video')<video src="{{ $item['url'] }}" controls class="max-h-full max-w-full"></video>@else<img src="{{ $item['url'] }}" alt="" class="max-h-full max-w-full object-contain" />@endif
                        </div>
                    @endforeach
                </div>
                <div class="flex min-h-0 flex-1 flex-col md:w-2/5">
                    <div class="flex items-center justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                        <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ $ig?->username ?? 'moximucom' }}</span>
                        <button type="button" wire:click="closePost" class="text-gray-500 hover:text-gray-900 dark:hover:text-white" aria-label="Kapat">✕</button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
                        <p class="text-xs text-gray-500">{{ $opened->kindLabel() }} · {{ $opened->statusLabel() }} · {{ $opened->publish_at->timezone($tz)->translatedFormat('j F Y H:i') }}</p>
                        <p class="mt-2 text-sm whitespace-pre-line text-gray-900 dark:text-gray-100">{{ $opened->caption }}</p>
                        @foreach ($opened->targets as $target)
                            @if ($target->error)<p class="mt-2 text-xs text-error-600">{{ $target->account?->platformLabel() ?? '' }}: {{ $target->error }}</p>@endif
                        @endforeach
                    </div>
                    @if ($canWrite)
                        <div class="flex flex-wrap gap-2 border-t border-gray-200 px-4 py-3 dark:border-gray-800" data-social-post-actions>
                            @if ($isReady)
                                <button type="button" wire:click="publishNow({{ $opened->id }})" wire:confirm="Bu gönderi şimdi yayınlansın mı?" class="btn-primary h-9 px-3 text-[13px]" data-social-publish-now>Onayla ve şimdi yayınla</button>
                                @if ($opened->status === 'draft')<button type="button" wire:click="approve({{ $opened->id }})" class="btn h-9 px-3 text-[13px]">Onayla ({{ $opened->publish_at->timezone($tz)->format('d.m H:i') }})</button>@endif
                                @if ($opened->status === 'approved')<button type="button" wire:click="unapprove({{ $opened->id }})" class="btn h-9 px-3 text-[13px]">Onayı geri al</button>@endif
                                @if (in_array($opened->status, ['failed', 'partial'], true))<button type="button" wire:click="retry({{ $opened->id }})" class="btn h-9 px-3 text-[13px]">Yeniden dene</button>@endif
                                @if (in_array($opened->status, ['draft', 'expired'], true))<button type="button" wire:click="remove({{ $opened->id }})" wire:confirm="Gönderi silinsin mi?" class="btn-quiet h-9 px-2 text-[13px] text-error-600">Sil</button>@else<button type="button" wire:click="skip({{ $opened->id }})" class="btn-quiet h-9 px-2 text-[13px] text-gray-500">Atla</button>@endif
                            @else
                                @if ($igTarget?->permalink)<a href="{{ $igTarget->permalink }}" target="_blank" rel="noopener" class="btn h-9 px-3 text-[13px]">Instagram'da aç ↗</a>@endif
                                <button type="button" wire:click="takeDown({{ $opened->id }})" wire:confirm="{{ $fbTarget?->external_id ? 'Facebook\'taki gönderi silinir ve gönderi bu listeden kalkar. ' : 'Gönderi bu listeden kalkar. ' }}Instagram'daki gönderiyi Instagram uygulamasından silmen gerekir (Meta buna izin vermiyor). Devam edilsin mi?" class="btn-quiet h-9 px-2 text-[13px] text-error-600" data-social-take-down>Sil</button>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</section>
