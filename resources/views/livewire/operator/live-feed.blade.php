<section wire:poll.{{ $busy ? '4s' : '20s' }}.visible data-live-feed>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="section-title flex items-center gap-2 text-gray-900 dark:text-white">
            @if ($busy)<span class="live-dot"></span>@else<span class="inline-flex h-1.5 w-1.5 rounded-full bg-success-500"></span>@endif
            Şu an sistemde
            <span class="text-sm font-normal text-gray-500">{{ $busy ? 'çalışıyor' : 'sakin, bekleyen iş yok' }}</span>
        </h2>
        <p class="flex flex-wrap gap-x-4 text-xs tabular-nums text-gray-500 dark:text-gray-400" data-live-feed-counters>
            <span><span class="font-medium text-gray-900 dark:text-white">{{ $doneToday }}</span> Claude işi bitti bugün</span>
            @if ($failedToday > 0)<span class="text-error-700 dark:text-error-400"><span class="font-medium">{{ $failedToday }}</span> yapılamadı</span>@endif
            <span><span class="font-medium text-gray-900 dark:text-white">{{ $waiting }}</span> sırada</span>
            <span><span class="font-medium text-gray-900 dark:text-white">{{ $jobsToday }}</span> arka plan işi</span>
        </p>
    </div>

    @if ($working->isNotEmpty())
        <ul class="mt-3 divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800" data-live-feed-working>
            @foreach ($working as $row)
                <li wire:key="live-working-{{ $loop->index }}-{{ md5($row['label'].$row['brand']) }}" class="live-in flex flex-wrap items-baseline gap-x-2 py-2.5 text-sm text-gray-700 dark:text-gray-300">
                    <span class="font-medium text-gray-900 dark:text-white">Claude</span>
                    <span>{{ $row['label'] }}@if ($row['brand'])<span class="ml-1 text-gray-500">{{ $row['brand'] }}</span>@endif</span>
                    <span class="text-xs text-gray-500">{{ $row['count'] }} iş@if ($row['since']), {{ $row['since'] }} önce başladı @endif</span>
                    <span class="live-typing" aria-label="çalışıyor"><i></i><i></i><i></i></span>
                    @if ($row['subject'])<span class="w-full truncate text-xs text-gray-500">{{ $row['subject'] }}</span>@endif
                </li>
            @endforeach
        </ul>
    @elseif ($waiting > 0)
        <p class="mt-3 text-sm text-gray-500">{{ $waiting }} iş Claude'un bir sonraki turunu bekliyor.</p>
    @endif

    <ol class="mt-3 divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800" data-live-feed-stream>
        @forelse ($stream as $row)
            <li wire:key="live-{{ $row['key'] }}" class="live-in flex gap-3 py-2.5 text-sm">
                <span @class(['mt-2 h-1.5 w-1.5 shrink-0 rounded-full', 'bg-success-500' => $row['ok'], 'bg-error-500' => ! $row['ok']])></span>
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-baseline gap-x-2">
                        <span class="font-medium text-gray-900 dark:text-white">{{ $row['who'] }}</span>
                        <span class="text-gray-700 dark:text-gray-300">{{ $row['label'] }}@if ($row['brand'])<span class="ml-1 text-gray-500">{{ $row['brand'] }}</span>@endif</span>
                        <time class="ml-auto shrink-0 text-xs tabular-nums text-gray-500" datetime="{{ $row['at']->toIso8601String() }}">{{ $row['at']->diffForHumans(short: true) }}</time>
                    </p>
                    @if ($row['subject'])<p class="truncate text-xs text-gray-500">{{ $row['subject'] }}</p>@endif
                    @if ($row['preview'])<p @class(['mt-0.5 line-clamp-2 text-xs', 'text-gray-500 dark:text-gray-400' => $row['ok'], 'text-error-700 dark:text-error-400' => ! $row['ok']])>{{ $row['ok'] ? '“'.$row['preview'].'”' : $row['preview'] }}</p>@endif
                </div>
            </li>
        @empty
            <li class="py-2.5 text-sm text-gray-500">Henüz biten iş yok.</li>
        @endforelse
    </ol>
</section>
