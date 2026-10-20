@php
    // One queued post as a row inside a card: day, angle and source page on one line, the text, then its actions.
    $write = $post->writeAction;
    $failed = $post->status === 'failed' || ($write !== null && $write->status === 'failed');
    $label = $failed ? 'Yayınlanamadı' : ($post->status === 'published' && $write !== null && in_array($write->status, ['queued', 'running'], true) ? 'Gönderiliyor' : $post->statusLabel());
@endphp
<div class="min-w-0 border-b border-gray-200 px-4 py-3.5 last:border-b-0 dark:border-gray-800" wire:key="post-{{ $post->id }}">
    <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1 text-[13px]">
        <span class="font-medium tabular-nums text-gray-900 dark:text-white">{{ \Illuminate\Support\Carbon::parse($post->publish_on)->locale('tr')->translatedFormat('d M D') }}</span>
        @if ($showLocation ?? false)<span class="min-w-0 max-w-full truncate font-medium text-gray-700 sm:max-w-80 dark:text-gray-300" title="{{ $post->digitalAsset?->name }}">{{ \App\Livewire\Operator\Gbp\PostPlanPage::shortName((string) $post->digitalAsset?->name) }}</span>@endif
        <span class="text-gray-500">{{ $angles[$post->angle]['label'] ?? $post->angle }}</span>
        @if ($post->page)<a href="{{ $post->page->url }}" target="_blank" rel="noopener" class="link-neutral min-w-0 max-w-full truncate" title="{{ $post->page->title }}">{{ \Illuminate\Support\Str::limit((string) $post->page->title, 70) }}<svg class="ml-0.5 inline h-3 w-3 align-[-1px] text-gray-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M7 13 13 7M8 7h5v5"/></svg></a>@endif
        <x-ta.pill class="ml-auto" :tone="$chip($failed ? 'failed' : $post->status)">{{ $label }}</x-ta.pill>
    </div>
    <div class="mt-2 flex gap-3">
        @if ($post->image_url)<img src="{{ $post->image_url }}" alt="" loading="lazy" class="h-16 w-16 shrink-0 rounded-md bg-gray-100 object-cover dark:bg-gray-800">@endif
        @if ($editing === $post->id)
            <div class="min-w-0 flex-1">
                <textarea wire:model="editText" rows="6" maxlength="1500" class="w-full rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-800 focus:border-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:focus:ring-white/10"></textarea>
                @error('editText')<p class="text-[13px] text-error-600 dark:text-error-400">{{ $message }}</p>@enderror
                <div class="mt-2 flex gap-2"><button type="button" wire:click="saveEdit" class="btn-primary">Kaydet</button><button type="button" wire:click="$set('editing', null)" class="btn-quiet">Vazgeç</button></div>
            </div>
        @else
            <p class="min-w-0 flex-1 whitespace-pre-line break-words text-sm leading-6 text-gray-800 dark:text-gray-200">{{ $post->summary }}</p>
        @endif
    </div>
    @php($writeNote = $failed ? null : data_get($write?->result, 'note'))
    @if ($post->note || $writeNote || ($failed && $write?->error))<p class="mt-1 text-[13px] {{ $failed ? 'text-error-600 dark:text-error-400' : 'text-gray-500' }}">{{ $failed && $write?->error ? $write->error : ($post->note ?: $writeNote) }}</p>@endif
    @if ($canWrite && $editing !== $post->id && ($failed || in_array($post->status, ['draft', 'approved'], true)))
        <div class="mt-2.5 flex flex-wrap gap-1.5">
            @if ($post->status === 'draft')<button type="button" wire:click="approve({{ $post->id }})" class="btn h-7! px-2.5!">Onayla</button>@endif
            @if (in_array($post->status, ['draft', 'approved'], true))
                <button type="button" wire:click="startEdit({{ $post->id }})" class="btn-quiet h-7! px-2!">Düzenle</button>
                <button type="button" wire:click="skip({{ $post->id }})" class="btn-quiet h-7! px-2!">Atla</button>
            @endif
            @if ($failed)<button type="button" wire:click="retry({{ $post->id }})" class="btn h-7! px-2.5!">Tekrar dene</button>@endif
        </div>
    @endif
</div>
