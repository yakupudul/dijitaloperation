{{-- Instagram profili (yakup 2026-11-08: "Instagram görünümünde olmalı"): ready posts first, then what is live, as the
     profile shows them. Tiles are light covers; 24 at a time. A tile opens the side panel. --}}
@php $ig = $profile['account']; @endphp
<section class="mx-auto max-w-[760px]" data-social-instagram>
    <header class="flex items-center gap-5 border-b border-gray-200 pb-6 sm:gap-10 sm:px-8 dark:border-gray-800">
        <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-accent text-2xl font-semibold text-accent-ink sm:h-24 sm:w-24 sm:text-3xl">M</span>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-3">
                <h2 class="truncate text-xl text-gray-900 dark:text-white">{{ $ig?->username ?? 'moximucom' }}</h2>
                @if ($ig?->profileUrl())<a href="{{ $ig->profileUrl() }}" target="_blank" rel="noopener" class="btn">Instagram'da aç ↗</a>@endif
                <button type="button" wire:click="refreshFeed" class="btn-quiet">Yeniden oku</button>
            </div>
            <p class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm text-gray-900 dark:text-white">
                <span><span class="font-semibold tabular-nums">{{ $profile['gridTotal'] }}</span> gönderi</span>
                <span><span class="font-semibold tabular-nums">{{ $profile['readyTotal'] }}</span> hazır</span>
                @if ($profile['waiting'] > 0)<span class="text-warning-700 dark:text-warning-400"><span class="font-semibold tabular-nums">{{ $profile['waiting'] }}</span> onay bekliyor</span>@endif
            </p>
            @if ($ig === null)<p class="mt-2 text-[13px] text-gray-500">Instagram hesabı bağlı değil; gönderiler hazırlanır ama yayınlanamaz.</p>@endif
        </div>
    </header>

    @if ($profile['ready']->isNotEmpty())
        <h3 class="mt-6 mb-2 text-[13px] font-semibold text-gray-500">Hazır gönderiler · {{ $profile['ready']->count() }} / {{ $profile['readyTotal'] }}</h3>
        <div class="grid grid-cols-4 gap-1 sm:grid-cols-6" data-social-ready-grid>
            @foreach ($profile['ready'] as $post)
                @php $c = $thumbs->cover($post); @endphp
                <button type="button" wire:key="ig-ready-{{ $post->id }}" wire:click="openPost({{ $post->id }})" class="group relative aspect-[4/5] overflow-hidden bg-gray-100 dark:bg-white/5" data-social-tile="{{ $post->id }}">
                    @if ($c['url'])<img src="{{ $c['url'] }}" alt="" loading="lazy" decoding="async" class="h-full w-full object-cover" />@else<span class="flex h-full w-full items-center justify-center px-2 text-center text-xs text-gray-500">{{ $post->title ?: 'Video' }}</span>@endif
                    <span @class(['absolute top-1.5 left-1.5 rounded px-1.5 py-0.5 text-[11px] font-medium', 'bg-warning-400 text-gray-900' => $post->status === 'draft', 'bg-white/90 text-gray-900' => $post->status === 'approved', 'bg-error-600 text-white' => in_array($post->status, ['failed', 'partial'], true)])>{{ match ($post->status) { 'draft' => 'Onay bekliyor', 'approved' => $post->publish_at->timezone($tz)->format('d.m H:i'), default => 'Yayınlanamadı' } }}</span>
                    @if ($c['many'])<span class="absolute top-1.5 right-1.5 text-white drop-shadow" aria-label="Kaydırmalı">❐</span>@elseif ($post->kind === 'reels')<span class="absolute top-1.5 right-1.5 text-white drop-shadow" aria-label="Video">▶</span>@elseif ($post->kind === 'story')<span class="absolute top-1.5 right-1.5 text-white drop-shadow" aria-label="Hikâye">○</span>@endif
                    <span class="absolute inset-0 bg-black/0 transition group-hover:bg-black/20"></span>
                </button>
            @endforeach
        </div>
        @if ($profile['readyTotal'] > $profile['ready']->count())
            <p class="mt-3 text-center"><button type="button" wire:click="more('ready')" class="btn">Daha fazla hazır gönderi ({{ $profile['readyTotal'] - $profile['ready']->count() }})</button></p>
        @endif
    @endif

    <h3 class="mt-8 mb-2 border-t border-gray-900 pt-3 text-center text-[13px] font-semibold text-gray-900 dark:border-white dark:text-white">Yayındaki gönderiler</h3>
    <div class="grid grid-cols-3 gap-1" data-social-published-grid>
        @forelse ($profile['grid'] as $cell)
            @php
                $post = $cell['post'];
                $m = $cell['ig'];
                $c = $post !== null ? $thumbs->cover($post) : null;
                // The Instagram picture first (it is the cover people see); our own cover when the post is not in the feed.
                $img = ($m['image'] ?? '') !== '' ? $m['image'] : ($c['url'] ?? null);
            @endphp
            <button type="button" wire:key="ig-cell-{{ $post?->id ?? 'x' }}-{{ $m['id'] ?? 'own' }}" @if ($post !== null) wire:click="openPost({{ $post->id }})" @else wire:click="openIgPost('{{ $m['id'] }}')" @endif class="group relative aspect-[4/5] overflow-hidden bg-gray-100 dark:bg-white/5" data-social-tile="{{ $post?->id ?? 'ig-'.$m['id'] }}">
                @if ($img)<img src="{{ $img }}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" class="h-full w-full object-cover" />@else<span class="flex h-full w-full items-center justify-center px-2 text-center text-xs text-gray-500">{{ $post?->title ?: 'Video' }}</span>@endif
                @if (($c['many'] ?? false) || ($m['type'] ?? '') === 'CAROUSEL_ALBUM')<span class="absolute top-1.5 right-1.5 text-white drop-shadow" aria-label="Kaydırmalı">❐</span>@elseif (($post?->kind ?? '') === 'reels' || ($m['type'] ?? '') === 'VIDEO')<span class="absolute top-1.5 right-1.5 text-white drop-shadow" aria-label="Video">▶</span>@endif
                @if ($m !== null)<span class="absolute inset-0 flex items-center justify-center gap-4 bg-black/40 text-sm font-semibold text-white opacity-0 transition group-hover:opacity-100">♥ {{ $m['likes'] }} &nbsp; 💬 {{ $m['comments'] }}</span>@else<span class="absolute inset-0 bg-black/0 transition group-hover:bg-black/20"></span>@endif
            </button>
        @empty
            <p class="col-span-3 py-10 text-center text-sm text-gray-500">{{ $ig === null ? 'Instagram hesabı bağlı değil.' : 'Instagram\'dan gönderi okunamadı ya da henüz gönderi yok.' }}</p>
        @endforelse
    </div>
    @if ($profile['gridTotal'] > $profile['grid']->count())
        <p class="mt-4 text-center"><button type="button" wire:click="more('grid')" class="btn">Daha fazla göster ({{ $profile['gridTotal'] - $profile['grid']->count() }})</button></p>
    @endif

    @if ($openedIg !== null)
        <div class="fixed inset-0 z-99999 flex items-center justify-center bg-black/70 p-3 sm:p-8" wire:click.self="closePost" x-data x-on:keydown.escape.window="$wire.closePost()" data-social-ig-modal="{{ $openedIg['id'] }}">
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
</section>
