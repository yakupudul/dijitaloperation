<div wire:poll.15s class="relative">
    @if($activeCount > 0)
        <details class="group" wire:key="data-sync-details">
            <summary class="flex cursor-pointer list-none items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-800 dark:border-blue-500/20 dark:bg-blue-500/10 dark:text-blue-300">
                <span class="h-2 w-2 shrink-0 rounded-full {{ $items->contains('stalled', true) ? 'bg-amber-500' : 'bg-blue-500' }}"></span>
                <span class="font-semibold">{{ $tr ? 'Veri işleri' : 'Data jobs' }} · {{ $activeCount }}</span>
                @if($activeCount === 1 && $items->isNotEmpty())<span class="hidden max-w-52 truncate sm:inline">{{ $items->first()['name'] }} · {{ $items->first()['state'] }}</span>@endif
                <span aria-hidden="true">▾</span>
            </summary>
            <div class="absolute right-0 z-50 mt-2 w-80 max-w-[90vw] rounded-xl border border-gray-200 bg-white p-4 shadow-lg dark:border-gray-700 dark:bg-gray-900">
                <p class="mb-3 text-xs text-gray-500">{{ $tr ? 'Tüm kaynaklardaki işler. Bu sayfanın taranma yüzdesi değildir.' : 'Jobs across all sources, not this page’s crawl percentage.' }}</p>
                <div class="max-h-80 space-y-3 overflow-y-auto">
                    @foreach($items as $item)
                        <div wire:key="sync-job-{{ $item['id'] }}" class="border-b border-gray-100 pb-3 text-xs dark:border-gray-800">
                            <p class="font-semibold text-gray-900 dark:text-white">#{{ $item['id'] }} · {{ $item['name'] }}</p>
                            <p class="mt-1 {{ $item['stalled'] ? 'text-amber-700 dark:text-amber-300' : 'text-blue-700 dark:text-blue-300' }}">{{ $item['state'] }}</p>
                            <p class="mt-1 text-gray-500">{{ $item['automatic'] ? ($tr ? 'Otomatik' : 'Automatic') : ($tr ? 'Elle başlatıldı' : 'Manual') }} · {{ $item['completed'] }}/{{ $item['total'] }} {{ $tr ? 'veri grubu başarılı' : 'datasets successful' }}</p>
                            <p class="mt-1 text-gray-500">{{ $tr ? 'Son ilerleme:' : 'Last progress:' }} {{ $item['last'] ?: '—' }}</p>
                        </div>
                    @endforeach
                </div>
                <a href="{{ route('operator.settings.background-operations') }}" wire:navigate class="mt-3 block text-xs font-semibold text-brand-600">{{ $tr ? 'Tüm işleri ve ayrıntıları aç' : 'Open all jobs and details' }}</a>
            </div>
        </details>
    @endif
</div>
