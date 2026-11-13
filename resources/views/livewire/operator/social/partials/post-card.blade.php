@php
    $media = $post->mediaItems();
    $first = $media[0] ?? null;
    $tone = match ($post->status) {
        'draft', 'failed', 'partial', 'expired' => 'tone-look',
        'published' => 'tone-ok',
        default => 'tone-info',
    };
@endphp
<article class="card flex flex-col overflow-hidden" data-social-post="{{ $post->id }}">
    <div class="relative bg-gray-100 dark:bg-gray-900">
        @if ($first && $first['type'] === 'video')
            <video src="{{ $first['url'] }}" class="aspect-[4/5] w-full object-cover" controls preload="metadata" muted playsinline></video>
        @elseif ($first)
            <img src="{{ $first['url'] }}" alt="" loading="lazy" class="{{ $post->kind === 'story' ? 'aspect-[9/16]' : 'aspect-[4/5]' }} w-full object-cover">
        @endif
        @if (count($media) > 1)<span class="absolute right-2 top-2 rounded-full bg-black/70 px-2 py-0.5 text-xs text-white">1/{{ count($media) }}</span>@endif
    </div>
    <div class="flex flex-1 flex-col gap-3 p-4">
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <span class="rounded-full px-2 py-0.5 {{ $tone }}">{{ $post->statusLabel() }}</span>
            <span class="text-gray-500">{{ $post->publish_at->timezone($tz)->translatedFormat('j F D, H:i') }}</span>
            <span class="text-gray-500">· {{ $post->kindLabel() }}{{ $post->pillarLabel() ? ' · '.$post->pillarLabel() : '' }}</span>
            @php
                $inReels = $post->kind === 'reels' && ($reelLine ?? null) !== null;
                $place = array_search($post->id, $post->kind === 'story' ? ($storyLine ?? []) : ($inReels ? $reelLine : ($line ?? [])), true);
            @endphp
            @if ($place !== false)<span class="rounded-full bg-gray-100 px-2 py-0.5 dark:bg-gray-800" data-line-place>{{ $post->kind === 'story' ? 'Story sırasında' : ($inReels ? 'Video sırasında' : 'Sırada') }} {{ $place + 1 }}.</span>@endif
            @if ($post->pinned && $post->isEditable())<span class="rounded-full bg-gray-100 px-2 py-0.5 dark:bg-gray-800" data-pinned>Sabit tarih</span>@endif
        </div>
        @if ($post->title)<p class="text-sm font-semibold">{{ $post->title }}</p>@endif

        @if ($editing === $post->id)
            <label class="block text-xs text-gray-500">Instagram ve Facebook
                <textarea wire:model="editCaption" rows="6" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></textarea></label>
            <label class="block text-xs text-gray-500">LinkedIn
                <textarea wire:model="editLinkedin" rows="5" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></textarea></label>
            <label class="block text-xs text-gray-500">Zaman
                <input type="datetime-local" wire:model="editTime" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></label>
            <div class="flex gap-2"><button type="button" wire:click="saveEdit" class="btn-primary">Kaydet</button><button type="button" wire:click="cancelEdit" class="btn">Vazgeç</button></div>
        @else
            @if ($post->caption)<p class="line-clamp-6 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300" title="{{ $post->caption }}">{{ $post->caption }}</p>@endif
            @if ($post->linkedin_text)
                <details class="text-sm"><summary class="cursor-pointer text-xs text-gray-500">LinkedIn metni</summary><p class="mt-1 whitespace-pre-line text-gray-700 dark:text-gray-300">{{ $post->linkedin_text }}</p></details>
            @endif
        @endif

        <ul class="space-y-1 text-xs">
            @foreach ($post->targets as $target)
                <li class="flex flex-wrap items-center gap-1.5">
                    <span class="font-medium">{{ $target->account?->platformLabel() }}</span>
                    <span class="text-gray-500">· {{ $target->statusLabel() }}</span>
                    @if ($target->permalink)<a href="{{ $target->permalink }}" target="_blank" rel="noopener" class="underline">Aç ↗</a>@endif
                    @if ($target->error)<span class="text-error-600">· {{ $target->error }}</span>@endif
                </li>
            @endforeach
        </ul>

        @if ($canWrite && $editing !== $post->id)
            <div class="mt-auto flex flex-wrap gap-2 pt-1">
                @if ($post->status === 'draft' || $post->status === 'expired')<button type="button" wire:click="approve({{ $post->id }})" class="btn-primary">Onayla</button>@endif
                @if ($post->status === 'approved')<button type="button" wire:click="unapprove({{ $post->id }})" class="btn">Onayı geri al</button>@endif
                @if (in_array($post->status, ['failed', 'partial'], true))<button type="button" wire:click="retry({{ $post->id }})" class="btn-primary">Tekrar dene</button>@endif
                @if ($place !== false)
                    <button type="button" wire:click="move({{ $post->id }}, -1)" class="btn" @disabled($place === 0) aria-label="Sırada öne al">↑ Öne al</button>
                    <button type="button" wire:click="move({{ $post->id }}, 1)" class="btn" @disabled($place === count($line) - 1) aria-label="Sırada arkaya al">↓ Arkaya al</button>
                @endif
                @if ($post->isEditable())
                    @if ($post->pinned)<button type="button" wire:click="pin({{ $post->id }}, false)" class="btn">Sıraya al</button>
                    @else<button type="button" wire:click="pin({{ $post->id }}, true)" class="btn">Tarihi sabitle</button>@endif
                    <button type="button" wire:click="edit({{ $post->id }})" class="btn">Düzenle</button>
                    <button type="button" wire:click="skip({{ $post->id }})" class="btn-quiet">Atla</button>
                @endif
                @if (in_array($post->status, ['draft', 'skipped', 'expired'], true))<button type="button" x-on:click="if (confirm('Gönderi silinsin mi?')) $wire.remove({{ $post->id }})" class="btn-quiet">Sil</button>@endif
            </div>
        @endif
    </div>
</article>
