@php
    $ready = $summary['content'] + $summary['posts'] + $summary['repair'];
    $n = fn (int $v): string => number_format($v, 0, ',', '.');
    $lastAt = is_array($last) && isset($last['at']) ? \Illuminate\Support\Carbon::parse($last['at'])->timezone('Europe/Istanbul') : null;
    /** Days since the last send; a send older than a day is the first thing the operator should see (yakup 2026-10-19). */
    $lastDays = $lastAt !== null ? (int) $lastAt->copy()->startOfDay()->diffInDays(now('Europe/Istanbul')->startOfDay()) : null;
    $stale = $ready > 0 && ($lastDays === null || $lastDays >= 2);
    /** Failures of the last send grouped by reason: "3 yazı: Bu sitede ... yok" instead of the same sentence per title (titles carry no colon). */
    $failedReasons = collect(is_array($last) ? ($last['content']['failed'] ?? []) : [])
        ->map(fn (string $line): string => str_contains($line, ': ') ? \Illuminate\Support\Str::after($line, ': ') : $line)->countBy()->sortDesc();
    $repairFailed = is_array($last) ? count($last['repair']['failed'] ?? []) : 0;
    $note = 'flex items-start gap-2.5 border-t border-gray-200 bg-gray-50/80 px-5 py-2.5 text-[13px] text-gray-600 dark:border-gray-800 dark:bg-white/[0.02] dark:text-gray-300';
@endphp
{{-- Günlük gönderim (panel concept 2026-10-20): one card, the ready count big with its parts under it and the one send button; notes as gray rows under it. --}}
<section class="card" data-daily-send @if ($running) wire:poll.10s @endif>
    <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:gap-6">
        <div class="min-w-0 flex-1">
            <p class="text-[13px] text-gray-500 dark:text-gray-400">{{ $ready > 0 ? 'Gönderilmeye hazır, Claude kontrol etti' : 'Günlük gönderim' }}</p>
            <p class="mt-0.5 text-[30px] leading-9 font-semibold tabular-nums tracking-[-0.02em] text-gray-900 dark:text-white" data-daily-ready="{{ $ready }}">
                @if ($ready > 0)
                    {{ $n($ready) }} iş
                @else
                    <span class="text-xl leading-9">Gönderilecek hazır iş yok</span>
                @endif
            </p>
            <p class="mt-1.5 flex flex-wrap gap-x-[18px] gap-y-1 text-sm text-gray-600 dark:text-gray-300">
                <span data-daily-content><span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $n($summary['content']) }}</span> yazı</span>
                <span data-daily-posts><span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $n($summary['posts']) }}</span> profil gönderisi</span>
                <span data-daily-repair><span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $n($summary['repair']) }}</span> site onarımı</span>
                @if ($summary['held_total'] > 0)
                    <button type="button" wire:click="$toggle('showHeld')" class="text-warning-700 hover:underline dark:text-warning-400" data-daily-held>{{ $n($summary['held_total']) }} iş kontrolde kaldı</button>
                @endif
            </p>
            @if (($summary['content_preparing'] ?? 0) > 0)
                <p class="mt-1 text-[13px] text-gray-500" data-daily-preparing>Bu haftanın {{ $n($summary['content_preparing']) }} yazısı hâlâ yazılıyor.</p>
            @endif
            @if ($running && is_array($progress))
                @php $percent = $progress['total'] > 0 ? (int) min(100, round($progress['done'] * 100 / $progress['total'])) : 0; @endphp
                <div class="mt-3 space-y-1.5" data-daily-progress="{{ $progress['done'] }}/{{ $progress['total'] }}">
                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10" role="progressbar" aria-label="Gönderim" aria-valuemin="0" aria-valuemax="{{ $progress['total'] }}" aria-valuenow="{{ $progress['done'] }}">
                        <div class="h-full rounded-full bg-success-500 transition-all duration-500" style="width: {{ $percent }}%"></div>
                    </div>
                    <p class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-gray-500 dark:text-gray-400">
                        <span class="font-medium tabular-nums text-gray-900 dark:text-white" data-daily-progress-text>{{ $n($progress['done']) }} / {{ $n($progress['total']) }} gönderildi</span>
                        <span class="tabular-nums">{{ $n($progress['content'][0]) }}/{{ $n($progress['content'][1]) }} yazı</span>
                        <span class="tabular-nums">{{ $n($progress['posts'][0]) }}/{{ $n($progress['posts'][1]) }} gönderi</span>
                        <span class="tabular-nums">{{ $n($progress['repair'][0]) }}/{{ $n($progress['repair'][1]) }} onarım</span>
                    </p>
                </div>
            @endif
        </div>
        @if ($isAdmin)
            <button type="button" wire:click="send" wire:loading.attr="disabled" @disabled($running || $ready === 0) class="btn-primary h-10! w-full px-4! text-sm! sm:w-auto" data-daily-send-button>
                @if ($running)
                    <span class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/40 border-t-white"></span> Gönderiliyor…
                @else
                    Hepsini gönder
                @endif
            </button>
        @endif
    </div>

    @if ($stale)
        <p class="{{ $note }}" data-daily-stale>
            <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>
            <span>
                @if ($lastAt === null)
                    Henüz hiç gönderim yapılmadı; hazır işler bekliyor.
                @else
                    Son gönderim {{ $lastDays }} gün önce ({{ $lastAt->format('d.m H:i') }}); hazır işler o günden beri bekliyor.
                @endif
            </span>
        </p>
    @endif

    @if (($summary['no_connector'] ?? []) !== [])
        <p class="{{ $note }}" data-daily-no-connector>
            <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>
            <span class="min-w-0 flex-1">
                <span class="font-medium text-gray-900 dark:text-white">{{ collect($summary['no_connector'])->map(fn (array $s): string => $s['site'].' '.$s['count'].' yazı')->implode(', ') }}</span>
                gönderilemiyor: sitede eşleştirilmiş WordPress Connector yok.
            </span>
            <a href="{{ route('operator.websites') }}" wire:navigate class="link-primary shrink-0">Siteyi bağla</a>
        </p>
    @endif

    @if (is_array($last))
        <p class="{{ $note }} text-gray-500!" data-daily-last>
            <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full {{ isset($last['error']) || $failedReasons->isNotEmpty() || $repairFailed > 0 ? 'bg-warning-500' : 'bg-gray-300 dark:bg-gray-600' }}" aria-hidden="true"></span>
            <span>
                @if (isset($last['error']))
                    Son gönderim yarıda kaldı: {{ $last['error'] }}
                @elseif ($lastAt !== null)
                    Son gönderim {{ $lastAt->format('d.m H:i') }}: {{ $n((int) ($last['content']['sent'] ?? 0)) }} yazı, {{ $n((int) ($last['posts'] ?? 0)) }} gönderi, {{ $n((int) ($last['repair']['applied'] ?? 0)) }} onarım.
                    @if ($failedReasons->isNotEmpty() || $repairFailed > 0)
                        Gönderilemeyen:
                        {{ $failedReasons->map(fn (int $count, string $reason): string => $count.' yazı: '.rtrim($reason, '.'))->values()->implode('; ') }}@if ($failedReasons->isNotEmpty() && $repairFailed > 0); @endif{{ $repairFailed > 0 ? $repairFailed.' onarım (nedeniyle masaya döndü)' : '' }}.
                    @endif
                @endif
            </span>
        </p>
    @endif

    @if ($message !== '')
        <p class="{{ $note }}"><span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-success-500" aria-hidden="true"></span><span>{{ $message }}</span></p>
    @endif
    @if ($showHeld && $summary['held'] !== [])
        <ul class="border-t border-gray-200 text-sm dark:border-gray-800" data-daily-held-list>
            @foreach ($summary['held'] as $item)
                <li class="border-t border-gray-200 px-5 py-2.5 first:border-t-0 dark:border-gray-800">
                    <span class="text-[13px] text-gray-500">{{ $item['area'] }}</span>
                    <span class="font-medium text-gray-900 dark:text-white">{{ $item['title'] }}</span>
                    <span class="block text-[13px] text-warning-700 dark:text-warning-400">{{ $item['reason'] }}</span>
                </li>
            @endforeach
            @if ($summary['held_total'] > count($summary['held']))
                <li class="border-t border-gray-200 px-5 py-2.5 text-xs text-gray-500 dark:border-gray-800">İlk {{ count($summary['held']) }} gösteriliyor.</li>
            @endif
        </ul>
    @endif
</section>
