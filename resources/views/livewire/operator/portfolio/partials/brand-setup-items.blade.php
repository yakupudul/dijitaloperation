{{-- Marka eksikleri rows: amber dot = missing, gray dot = filled automatically and waiting for a look. `bare` = rows only (inside a card). --}}
<ul @class(['card' => empty($bare)])>
    @foreach ($items as $item)
        @php $missing = ! $item['done']; @endphp
        <li class="card-row flex-wrap gap-y-1.5 sm:flex-nowrap" data-setup-item="{{ $item['key'] }}" data-setup-state="{{ $missing ? 'missing' : 'review' }}">
            <span @class(['h-1.5 w-1.5 shrink-0 rounded-full', 'bg-warning-500' => $missing, 'bg-gray-300 dark:bg-gray-600' => ! $missing]) aria-hidden="true"></span>
            <div class="min-w-0 flex-1 sm:flex sm:items-baseline sm:gap-4">
                <p class="text-sm font-medium text-gray-900 sm:w-44 sm:shrink-0 dark:text-white">{{ $item['label'] }}</p>
                <p class="mt-0.5 min-w-0 text-[13px] text-gray-500 sm:mt-0 dark:text-gray-400">{{ $item['detail'] }}</p>
            </div>
            <span class="flex shrink-0 items-center gap-2 pl-5 sm:pl-0">
                <button type="button" wire:click="setTab('{{ $item['fix'] }}')" class="btn-quiet h-7! px-2!">{{ $missing ? 'Düzelt' : 'Kontrol et' }}</button>
                @if (! $missing && $isAdmin)
                    <button type="button" wire:click="confirmChecked('{{ $item['key'] }}')" class="btn h-7! px-2.5! text-xs!" data-confirm-checked="{{ $item['key'] }}">Kontrol ettim</button>
                @endif
            </span>
        </li>
    @endforeach
</ul>
