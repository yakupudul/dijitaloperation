@php
    $write = $post->writeAction;
    $failed = $post->status === 'failed' || ($write !== null && $write->status === 'failed');
    $label = $failed ? 'Yayınlanamadı' : ($post->status === 'published' && $write !== null && in_array($write->status, ['queued', 'running'], true) ? 'Gönderiliyor' : $post->statusLabel());
@endphp
<div class="rounded-lg bg-white p-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-700" wire:key="post-{{ $post->id }}">
    <div class="flex flex-wrap items-center gap-2 text-xs">
        <span class="font-semibold text-gray-900 dark:text-white">{{ \Illuminate\Support\Carbon::parse($post->publish_on)->locale('tr')->translatedFormat('d M D') }}</span>
        @if ($showLocation ?? false)<span class="font-medium text-gray-700 dark:text-gray-300">{{ \App\Livewire\Operator\Gbp\PostPlanPage::shortName((string) $post->digitalAsset?->name) }}</span>@endif
        <span class="text-gray-500">{{ $angles[$post->angle]['label'] ?? $post->angle }}</span>
        @if ($post->page)<a href="{{ $post->page->url }}" target="_blank" rel="noopener" class="truncate text-brand-600 hover:underline">{{ \Illuminate\Support\Str::limit((string) $post->page->title, 70) }} ↗</a>@endif
        <span class="ml-auto rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $chip($failed ? 'failed' : $post->status) }}">{{ $label }}</span>
    </div>
    <div class="mt-2 flex gap-3">
        @if ($post->image_url)<img src="{{ $post->image_url }}" alt="" loading="lazy" class="h-16 w-16 shrink-0 rounded object-cover">@endif
        @if ($editing === $post->id)
            <div class="min-w-0 flex-1">
                <textarea wire:model="editText" rows="6" maxlength="1500" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900"></textarea>
                @error('editText')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                <div class="mt-1 flex gap-2"><button type="button" wire:click="saveEdit" class="rounded bg-brand-500 px-2 py-1 text-xs font-semibold text-white">Kaydet</button><button type="button" wire:click="$set('editing', null)" class="text-xs text-gray-500 hover:underline">Vazgeç</button></div>
            </div>
        @else
            <p class="min-w-0 flex-1 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $post->summary }}</p>
        @endif
    </div>
    @if ($post->note || ($failed && $write?->error))<p class="mt-1 text-xs {{ $failed ? 'text-rose-600' : 'text-gray-500' }}">{{ $failed && $write?->error ? $write->error : $post->note }}</p>@endif
    @if ($canWrite && $editing !== $post->id)
        <div class="mt-2 flex flex-wrap gap-3 text-xs font-medium">
            @if ($post->status === 'draft')<button type="button" wire:click="approve({{ $post->id }})" class="text-success-600 hover:underline">Onayla</button>@endif
            @if (in_array($post->status, ['draft', 'approved'], true))
                <button type="button" wire:click="startEdit({{ $post->id }})" class="text-brand-600 hover:underline">Düzenle</button>
                <button type="button" wire:click="skip({{ $post->id }})" class="text-gray-600 hover:underline dark:text-gray-300">Atla</button>
            @endif
            @if ($failed)<button type="button" wire:click="retry({{ $post->id }})" class="text-rose-600 hover:underline">Tekrar dene</button>@endif
        </div>
    @endif
</div>
