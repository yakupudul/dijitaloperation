{{-- Gönderiler: the same posts as a list (find, filter, page) or as the Instagram profile grid. --}}
<div class="flex flex-wrap items-center justify-between gap-3" data-social-posts-head>
    <span class="inline-flex gap-1">
        <button type="button" wire:click="$set('mode', 'liste')" @class(['btn', 'border-second bg-second-soft text-second' => $mode === 'liste'])>Liste</button>
        <button type="button" wire:click="$set('mode', 'izgara')" @class(['btn', 'border-second bg-second-soft text-second' => $mode === 'izgara'])>Instagram profili</button>
    </span>
    @if ($mode === 'liste')
        <div class="flex min-w-0 flex-1 flex-wrap items-center justify-end gap-2">
            <select wire:model.live="kind" aria-label="Tür" class="h-8 rounded-lg border border-gray-300 py-0 text-[13px] dark:border-gray-700 dark:bg-gray-900">
                @foreach ($kindFilters as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Başlıkta ya da metinde ara" aria-label="Ara" class="h-8 w-full rounded-lg border border-gray-300 py-0 text-[13px] sm:w-64 dark:border-gray-700 dark:bg-gray-900">
        </div>
    @endif
</div>
