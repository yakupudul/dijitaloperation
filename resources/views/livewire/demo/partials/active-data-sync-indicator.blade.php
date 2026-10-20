<div wire:poll.15s class="relative">
    @if($activeCount > 0)
        <details class="group" wire:key="data-sync-details">
            <summary class="flex h-8 cursor-pointer list-none items-center gap-1.5 rounded-md px-2 text-xs text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white">
                <span class="h-2 w-2 shrink-0 rounded-full {{ $items->contains('stalled', true) ? 'bg-warning-500' : 'animate-pulse bg-success-500' }}"></span>
                <span class="sr-only sm:not-sr-only">{{ $tr ? 'Veri işleri' : 'Data jobs' }} · {{ $activeCount }}</span>
            </summary>
            <div class="absolute right-0 z-50 mt-1.5 w-80 max-w-[90vw] rounded-xl bg-white p-4 shadow-theme-lg ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700">
                <p class="mb-3 text-xs text-gray-500">{{ $tr ? 'Tüm kaynaklardaki işler. Bu sayfanın taranma yüzdesi değildir.' : 'Jobs across all sources, not this page’s crawl percentage.' }}</p>
                <div class="max-h-80 space-y-3 overflow-y-auto">
                    @foreach($items as $item)
                        <div wire:key="sync-job-{{ $item['id'] }}" class="border-b border-gray-100 pb-3 text-xs dark:border-gray-800">
                            <p class="font-semibold text-gray-900 dark:text-white">#{{ $item['id'] }} · {{ $item['name'] }}</p>
                            <p class="mt-1 {{ $item['stalled'] ? 'text-warning-700 dark:text-warning-300' : 'text-gray-700 dark:text-gray-300' }}">{{ $item['state'] }}</p>
                            <p class="mt-1 text-gray-500">{{ $item['automatic'] ? ($tr ? 'Otomatik' : 'Automatic') : ($tr ? 'Elle başlatıldı' : 'Manual') }} · {{ $item['completed'] }}/{{ $item['total'] }} {{ $tr ? 'veri grubu başarılı' : 'datasets successful' }}</p>
                            <p class="mt-1 text-gray-500">{{ $tr ? 'Son ilerleme:' : 'Last progress:' }} {{ $item['last'] ?: '—' }}</p>
                        </div>
                    @endforeach
                </div>
                <a href="{{ route('operator.settings.system-health') }}" wire:navigate class="link-neutral mt-3 block text-xs">{{ $tr ? 'Sistem sağlığını aç' : 'Open system health' }}</a>
            </div>
        </details>
    @endif
</div>
