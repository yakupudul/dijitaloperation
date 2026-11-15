{{-- Erişim kaydı: outages and MoxDOP-only drops ($episodes from UptimeLog), newest first. $showSite links each row to its site. --}}
@php
    $tz = fn ($at) => $at->copy()->timezone('Europe/Istanbul');
    $span = fn (int $m): string => $m < 60 ? max(1, $m).' dk' : intdiv($m, 60).' sa'.($m % 60 > 0 ? ' '.($m % 60).' dk' : '');
@endphp
<ul class="card" data-uptime-log>
    @forelse ($episodes as $episode)
        <li class="card-row flex-wrap items-start sm:flex-nowrap" wire:key="ul-{{ $episode['site_id'] }}-{{ $episode['started_at']->timestamp }}">
            <span class="w-28 shrink-0 pt-0.5 text-[13px] tabular-nums text-gray-500">{{ $tz($episode['started_at'])->format('d.m.Y H:i') }}</span>
            <div class="min-w-0 flex-[1_1_16rem]">
                <p class="text-sm font-medium text-gray-900 dark:text-white">
                    @if ($showSite)<a href="{{ route('operator.website', ['assetId' => $episode['site_id'], 'tab' => 'teknik']) }}" wire:navigate class="hover:underline">{{ $episode['site'] }}</a> · @endif
                    @if ($episode['kind'] === 'outage')
                        {{ $episode['ongoing'] ? 'Erişilemiyor, '.$span($episode['minutes']).'dır' : 'Erişilemedi, '.$span($episode['minutes']) }}
                    @else
                        Yalnız MoxDOP'tan ulaşılamadı, {{ $span($episode['minutes']) }}
                    @endif
                </p>
                <p class="mt-0.5 break-words text-[13px] text-gray-500 dark:text-gray-400">{{ $episode['ongoing'] ? 'sürüyor' : 'bitiş '.$tz($episode['ended_at'])->format('H:i') }} · {{ $episode['checks'] }} ölçüm · {{ $episode['error'] }}</p>
            </div>
            <span class="shrink-0 sm:w-28 sm:text-right">
                @if ($episode['notified'])
                    <x-ta.pill tone="critical">bildirim gitti</x-ta.pill>
                @elseif ($episode['kind'] === 'outage' && $episode['ongoing'])
                    <x-ta.pill tone="look">izleniyor</x-ta.pill>
                @else
                    <x-ta.pill tone="info">yalnız kayıt</x-ta.pill>
                @endif
            </span>
        </li>
    @empty
        <li><x-ta.empty-state size="sm" title="{{ $empty }}" /></li>
    @endforelse
</ul>
