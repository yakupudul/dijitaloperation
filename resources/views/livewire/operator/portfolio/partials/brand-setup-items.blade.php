{{-- Marka eksikleri rows: amber dot = missing, gray dot = filled automatically and waiting for a look. --}}
<ul class="mt-3 divide-y divide-gray-100 text-xs dark:divide-gray-800">
    @foreach ($items as $item)
        @php $missing = ! $item['done']; @endphp
        <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2" data-setup-item="{{ $item['key'] }}" data-setup-state="{{ $missing ? 'missing' : 'review' }}">
            <span @class(['h-2 w-2 shrink-0 rounded-full', 'bg-amber-400' => $missing, 'bg-gray-300 dark:bg-gray-600' => ! $missing])></span>
            <span class="w-40 shrink-0 font-semibold text-gray-900 dark:text-white">{{ $item['label'] }}</span>
            <span @class(['min-w-0 flex-1', 'text-amber-700 dark:text-amber-300' => $missing, 'text-gray-600 dark:text-gray-400' => ! $missing])>{{ $item['detail'] }}</span>
            <button type="button" wire:click="setTab('{{ $item['fix'] }}')" class="shrink-0 font-semibold text-gray-700 hover:underline dark:text-gray-200">{{ $missing ? 'Düzelt' : 'Kontrol et' }} →</button>
            @if (! $missing && $isAdmin)
                <button type="button" wire:click="confirmChecked('{{ $item['key'] }}')" class="shrink-0 rounded-md px-2 py-0.5 font-semibold text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-gray-800" data-confirm-checked="{{ $item['key'] }}">Kontrol ettim</button>
            @endif
        </li>
    @endforeach
</ul>
