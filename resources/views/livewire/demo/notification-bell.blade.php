<div class="relative" x-data="{ open: false }" @click.outside="open = false">
    <button
        type="button"
        class="relative inline-flex h-11 w-11 items-center justify-center rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.03]"
        @click="open = !open"
        aria-label="{{ __('operator.notifications.title') }}"
        :aria-expanded="open.toString()"
    >
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
        </svg>
        @if ($unreadCount > 0)
            <span class="absolute -right-1 -top-1 inline-flex min-w-5 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-semibold leading-5 text-white" data-unread-count>{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
            <span class="sr-only">{{ __('operator.notifications.unread') }}</span>
        @endif
    </button>

    <div
        x-show="open"
        x-cloak
        class="absolute right-0 z-50 mt-2 w-[22rem] max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl bg-white shadow-lg ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700"
    >
        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800">
            <p class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ __('operator.notifications.title') }}</p>
            @if ($unreadCount > 0)
                <button type="button" wire:click="markAllRead" class="text-xs font-medium text-brand-600 hover:underline">{{ __('operator.notifications.mark_all') }}</button>
            @endif
        </div>
        @if ($notice !== '')
            <p class="border-b border-gray-100 bg-emerald-50 px-4 py-2 text-xs text-emerald-800 dark:border-gray-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $notice }}</p>
        @endif
        <ul class="max-h-[28rem] divide-y divide-gray-100 overflow-y-auto dark:divide-gray-800">
            @if (count($items) > 0)
                @foreach ($items as $item)
                    <li class="px-4 py-3 text-sm {{ ! empty($item['is_unread']) ? 'bg-brand-50/40 dark:bg-brand-500/5' : '' }}">
                        @if (! empty($item['url']))
                            <a href="{{ $item['url'] }}" wire:click.prevent="open('{{ $item['id'] }}')" class="font-medium text-gray-800 hover:text-brand-600 dark:text-white/90">{{ $item['title'] ?? __('operator.notifications.item') }}</a>
                        @else
                            <p class="font-medium text-gray-800 dark:text-white/90">{{ $item['title'] ?? __('operator.notifications.item') }}</p>
                        @endif
                        @if (($item['detail'] ?? '') !== '')<p class="mt-1 text-xs text-gray-600 dark:text-gray-400">{{ \Illuminate\Support\Str::limit($item['detail'], 420) }}</p>@endif
                        @if (($item['why'] ?? '') !== '')<p class="mt-1 text-xs text-gray-600 dark:text-gray-400"><span class="font-semibold text-gray-700 dark:text-gray-300">Neden önemli:</span> {{ $item['why'] }}</p>@endif
                        @if (($item['action'] ?? '') !== '')<p class="mt-1 text-xs text-gray-600 dark:text-gray-400"><span class="font-semibold text-gray-700 dark:text-gray-300">Ne yapmalısın:</span> {{ $item['action'] }}</p>@endif
                        @if (! empty($item['button']) || (! empty($item['url']) && ! empty($item['link_label'])))
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                @if (! empty($item['button']['run_now']))
                                    <button type="button" wire:click="runNow({{ (int) $item['button']['run_now'] }})" wire:loading.attr="disabled" class="rounded-md bg-brand-500 px-2.5 py-1 text-xs font-semibold text-white hover:bg-brand-600">{{ $item['button']['label'] }}</button>
                                @elseif (! empty($item['button']['mark_inactive']))
                                    <button type="button" wire:click="markAssetInactive({{ (int) $item['button']['mark_inactive'] }})" wire:confirm="Varlık Kullanılmıyor olarak işaretlensin mi? Bağlantı kopmaz, veri silinmez; bir kez daha çekilir, sonra Aktif yapana kadar otomatik çekim durur." wire:loading.attr="disabled" class="rounded-md bg-brand-500 px-2.5 py-1 text-xs font-semibold text-white hover:bg-brand-600">{{ $item['button']['label'] }}</button>
                                @elseif (! empty($item['button']['url']))
                                    <a href="{{ $item['button']['url'] }}" class="rounded-md bg-brand-500 px-2.5 py-1 text-xs font-semibold text-white hover:bg-brand-600">{{ $item['button']['label'] }}</a>
                                @endif
                                @if (! empty($item['url']) && ! empty($item['link_label']))
                                    <a href="{{ $item['url'] }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">{{ $item['link_label'] }} →</a>
                                @endif
                            </div>
                        @endif
                        <p class="mt-1 text-[11px] text-gray-400">{{ $item['when'] ?? '' }}@if (($item['repeat_label'] ?? '') !== '') · <span class="font-medium text-amber-600">{{ $item['repeat_label'] }}</span>@endif</p>
                        @if (! empty($item['is_unread']))
                            <button type="button" wire:click="markRead('{{ $item['id'] }}')" class="mt-2 text-xs font-medium text-brand-600 hover:underline">{{ __('operator.notifications.mark_read') }}</button>
                        @endif
                    </li>
                @endforeach
            @else
                <li class="px-4 py-6 text-sm text-gray-500">{{ __('operator.notifications.empty') }}</li>
            @endif
        </ul>
        <div class="border-t border-gray-100 px-4 py-2 dark:border-gray-800">
            <a href="{{ route('operator.settings', ['section' => 'notifications']) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">{{ __('operator.notifications.preferences') }}</a>
        </div>
    </div>
</div>
