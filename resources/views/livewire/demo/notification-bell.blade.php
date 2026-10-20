<div class="relative" x-data="{ open: false }" @click.outside="open = false">
    <button
        type="button"
        class="relative inline-flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-800 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white"
        @click="open = !open"
        aria-label="{{ __('operator.notifications.title') }}"
        :aria-expanded="open.toString()"
    >
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
        </svg>
        @if ($unreadCount > 0)
            <span class="absolute -right-0.5 -top-0.5 inline-flex min-w-4.5 items-center justify-center rounded-full bg-error-500 px-1 text-xs font-semibold leading-[18px] text-white ring-2 ring-white dark:ring-gray-900" data-unread-count>{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
            <span class="sr-only">{{ __('operator.notifications.unread') }}</span>
        @endif
    </button>

    <div
        x-show="open"
        x-cloak
        class="surface absolute right-0 z-50 mt-1.5 w-[22rem] max-w-[calc(100vw-2rem)] overflow-hidden shadow-theme-lg"
    >
        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-800">
            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('operator.notifications.title') }}</p>
            @if ($unreadCount > 0)
                <button type="button" wire:click="markAllRead" class="link-neutral text-xs">{{ __('operator.notifications.mark_all') }}</button>
            @endif
        </div>
        @if ($notice !== '')
            <p class="border-b border-gray-200 px-4 py-2 text-xs text-gray-600 dark:border-gray-800 dark:text-gray-300">{{ $notice }}</p>
        @endif
        <ul class="max-h-[28rem] divide-y divide-gray-200 overflow-y-auto dark:divide-gray-800">
            @if (count($items) > 0)
                @foreach ($items as $item)
                    <li class="relative px-4 py-3 text-sm {{ ! empty($item['is_unread']) ? 'bg-gray-50 dark:bg-white/[0.03]' : '' }}">
                        @if (! empty($item['is_unread']))<span class="absolute left-1.5 top-[1.15rem] h-1.5 w-1.5 rounded-full bg-gray-900 dark:bg-white" aria-hidden="true"></span>@endif
                        @if (! empty($item['url']))
                            <a href="{{ $item['url'] }}" wire:click.prevent="open('{{ $item['id'] }}')" class="font-medium text-gray-900 hover:underline dark:text-white">{{ $item['title'] ?? __('operator.notifications.item') }}</a>
                        @else
                            <p class="font-medium text-gray-900 dark:text-white">{{ $item['title'] ?? __('operator.notifications.item') }}</p>
                        @endif
                        @if (($item['detail'] ?? '') !== '')<p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Str::limit($item['detail'], 420) }}</p>@endif
                        @if (($item['why'] ?? '') !== '')<p class="mt-1 text-xs text-gray-500 dark:text-gray-400"><span class="font-medium text-gray-700 dark:text-gray-300">Neden önemli:</span> {{ $item['why'] }}</p>@endif
                        @if (($item['action'] ?? '') !== '')<p class="mt-1 text-xs text-gray-500 dark:text-gray-400"><span class="font-medium text-gray-700 dark:text-gray-300">Ne yapmalısın:</span> {{ $item['action'] }}</p>@endif
                        @if (! empty($item['button']) || (! empty($item['url']) && ! empty($item['link_label'])))
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                @if (! empty($item['button']['run_now']))
                                    <button type="button" wire:click="runNow({{ (int) $item['button']['run_now'] }})" wire:loading.attr="disabled" class="rounded-md px-2.5 py-1 text-xs font-medium text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-white dark:ring-gray-700 dark:hover:bg-white/5">{{ $item['button']['label'] }}</button>
                                @elseif (! empty($item['button']['url']))
                                    <a href="{{ $item['button']['url'] }}" class="rounded-md px-2.5 py-1 text-xs font-medium text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-white dark:ring-gray-700 dark:hover:bg-white/5">{{ $item['button']['label'] }}</a>
                                @endif
                                @if (! empty($item['url']) && ! empty($item['link_label']))
                                    <a href="{{ $item['url'] }}" wire:navigate class="link-neutral text-xs">{{ $item['link_label'] }}</a>
                                @endif
                            </div>
                        @endif
                        <p class="mt-1 text-xs text-gray-500">{{ $item['when'] ?? '' }}@if (($item['repeat_label'] ?? '') !== '') <span class="ml-1 text-warning-700 dark:text-warning-400">{{ $item['repeat_label'] }}</span>@endif</p>
                        @if (! empty($item['is_unread']))
                            <button type="button" wire:click="markRead('{{ $item['id'] }}')" class="link-neutral mt-2 text-xs">{{ __('operator.notifications.mark_read') }}</button>
                        @endif
                    </li>
                @endforeach
            @else
                <li class="px-4 py-6 text-sm text-gray-500">{{ __('operator.notifications.empty') }}</li>
            @endif
        </ul>
        <div class="border-t border-gray-200 px-4 py-2 dark:border-gray-800">
            <a href="{{ route('operator.settings', ['section' => 'notifications']) }}" wire:navigate class="link-neutral text-xs">{{ __('operator.notifications.preferences') }}</a>
        </div>
    </div>
</div>
