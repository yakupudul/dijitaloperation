{{-- Marka eksikleri rows: amber dot = missing, gray dot = filled automatically and waiting for a look. --}}
<ul class="mt-2 divide-y divide-gray-200 border-b border-gray-200 text-sm dark:divide-gray-800 dark:border-gray-800">
    @foreach ($items as $item)
        @php $missing = ! $item['done']; @endphp
        <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5" data-setup-item="{{ $item['key'] }}" data-setup-state="{{ $missing ? 'missing' : 'review' }}">
            <span @class(['h-1.5 w-1.5 shrink-0 rounded-full', 'bg-warning-500' => $missing, 'bg-gray-300 dark:bg-gray-600' => ! $missing])></span>
            <span class="w-40 shrink-0 font-medium text-gray-900 dark:text-white">{{ $item['label'] }}</span>
            <span @class(['min-w-0 flex-1 text-xs', 'text-warning-800 dark:text-warning-400' => $missing, 'text-gray-500 dark:text-gray-400' => ! $missing])>{{ $item['detail'] }}</span>
            <button type="button" wire:click="setTab('{{ $item['fix'] }}')" class="link-neutral shrink-0 text-xs">{{ $missing ? 'Düzelt' : 'Kontrol et' }}</button>
            @if (! $missing && $isAdmin)
                <button type="button" wire:click="confirmChecked('{{ $item['key'] }}')" class="shrink-0 rounded-md px-2 py-0.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-gray-800" data-confirm-checked="{{ $item['key'] }}">Kontrol ettim</button>
            @endif
        </li>
    @endforeach
</ul>
