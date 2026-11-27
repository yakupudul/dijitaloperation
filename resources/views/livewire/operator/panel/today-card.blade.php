<div data-panel-today>
    @if ($count > 0)
        <div class="card mt-4">
            <div class="flex items-center justify-between gap-3 px-4 pb-2 pt-4">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">Müşteri paneli <span class="font-normal text-gray-500">· {{ $count }} iş</span></h2>
                <a href="{{ route('operator.settings.panel-users') }}" wire:navigate class="link-neutral text-[13px]">Tüm paneller →</a>
            </div>
            @foreach ($rows as $i => $row)
                <a href="{{ $row['url'] }}" wire:navigate wire:key="panel-today-{{ $i }}" class="card-row py-2.5! hover:bg-soft dark:hover:bg-white/[0.02]">
                    <x-ta.brand-avatar :name="$row['brand']" size="sm" />
                    <span class="min-w-0 flex-1 truncate text-sm text-gray-900 dark:text-white">{{ $row['brand'] }}<span class="text-gray-500"> · {{ $row['text'] }}</span></span>
                    <span @class(['shrink-0 text-xs', 'text-error-600' => $row['tone'] === 'red', 'text-gray-500' => $row['tone'] !== 'red'])>{{ $row['when'] }}</span>
                </a>
            @endforeach
            @if (! $all && $count > $rows->count())
                <button type="button" wire:click="$set('all', true)" class="block w-full border-t border-line px-4 py-2.5 text-left text-[13px] text-gray-500 hover:text-gray-900 dark:border-gray-800">{{ $count - $rows->count() }} satır daha</button>
            @endif
        </div>
    @endif
</div>
