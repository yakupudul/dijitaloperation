<section wire:poll.{{ $busy ? '4s' : '20s' }}.visible class="rounded-xl bg-white px-4 py-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-live-feed>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-white/90">
            <span class="relative flex h-2.5 w-2.5">
                @if ($busy)<span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-violet-400 opacity-60"></span>@endif
                <span @class(['relative inline-flex h-2.5 w-2.5 rounded-full', 'bg-violet-500' => $busy, 'bg-emerald-500' => ! $busy])></span>
            </span>
            Şu an sistemde
            <span class="font-normal text-gray-500">· {{ $busy ? 'çalışıyor' : 'sakin, bekleyen iş yok' }}</span>
        </h2>
        <p class="flex flex-wrap gap-x-4 text-xs tabular-nums text-gray-600 dark:text-gray-400" data-live-feed-counters>
            <span><strong class="text-gray-900 dark:text-white">{{ $doneToday }}</strong> Claude işi bitti bugün</span>
            @if ($failedToday > 0)<span class="text-rose-600"><strong>{{ $failedToday }}</strong> yapılamadı</span>@endif
            <span><strong class="text-gray-900 dark:text-white">{{ $waiting }}</strong> sırada</span>
            <span><strong class="text-gray-900 dark:text-white">{{ $jobsToday }}</strong> arka plan işi</span>
        </p>
    </div>

    @if ($working->isNotEmpty())
        <ul class="mt-3 space-y-1.5" data-live-feed-working>
            @foreach ($working as $row)
                <li wire:key="live-working-{{ $loop->index }}-{{ md5($row['label'].$row['brand']) }}" class="live-in flex flex-wrap items-baseline gap-x-2 rounded-lg bg-violet-50 px-3 py-2 text-sm text-violet-900 dark:bg-violet-500/10 dark:text-violet-200">
                    <span class="font-semibold">Claude</span>
                    <span>{{ $row['label'] }}@if ($row['brand']) · {{ $row['brand'] }}@endif</span>
                    <span class="text-xs text-violet-700 dark:text-violet-300">{{ $row['count'] }} iş @if ($row['since'])· {{ $row['since'] }} önce başladı @endif</span>
                    <span class="live-typing" aria-label="çalışıyor"><i></i><i></i><i></i></span>
                    @if ($row['subject'])<span class="w-full truncate text-xs text-violet-700/80 dark:text-violet-300/80">{{ $row['subject'] }}</span>@endif
                </li>
            @endforeach
        </ul>
    @elseif ($waiting > 0)
        <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ $waiting }} iş Claude'un bir sonraki turunu bekliyor.</p>
    @endif

    <ol class="mt-3 divide-y divide-gray-100 dark:divide-gray-800" data-live-feed-stream>
        @forelse ($stream as $row)
            <li wire:key="live-{{ $row['key'] }}" class="live-in flex gap-3 py-2 text-sm">
                <span @class(['mt-1.5 h-2 w-2 shrink-0 rounded-full', 'bg-emerald-500' => $row['ok'], 'bg-rose-500' => ! $row['ok']])></span>
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-baseline gap-x-2">
                        <span class="font-medium text-gray-900 dark:text-white">{{ $row['who'] }}</span>
                        <span class="text-gray-700 dark:text-gray-300">{{ $row['label'] }}@if ($row['brand']) · {{ $row['brand'] }}@endif</span>
                        <time class="ml-auto shrink-0 text-xs tabular-nums text-gray-500" datetime="{{ $row['at']->toIso8601String() }}">{{ $row['at']->diffForHumans(short: true) }}</time>
                    </p>
                    @if ($row['subject'])<p class="truncate text-xs text-gray-500">{{ $row['subject'] }}</p>@endif
                    @if ($row['preview'])<p @class(['mt-0.5 line-clamp-2 text-xs', 'text-gray-600 dark:text-gray-400 italic' => $row['ok'], 'text-rose-600' => ! $row['ok']])>{{ $row['ok'] ? '“'.$row['preview'].'”' : $row['preview'] }}</p>@endif
                </div>
            </li>
        @empty
            <li class="py-2 text-sm text-gray-500">Henüz biten iş yok.</li>
        @endforelse
    </ol>
</section>
