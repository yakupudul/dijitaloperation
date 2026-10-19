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
@endphp
<div class="surface p-4 sm:p-5" data-daily-send @if ($running) wire:poll.10s @endif>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex min-w-0 flex-1 items-center gap-4">
            <span @class(['flex h-12 w-12 shrink-0 items-center justify-center rounded-xl', 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400' => $ready > 0, 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400' => $ready === 0])>
                @if ($ready > 0)
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4z"/></svg>
                @else
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg>
                @endif
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Bugün · günlük gönderim</p>
                <p class="mt-0.5 text-xl font-semibold tabular-nums tracking-tight text-gray-900 dark:text-white" data-daily-ready>
                    @if ($ready > 0)
                        {{ $n($ready) }} iş gönderilmeye hazır
                    @else
                        Gönderilecek hazır iş yok
                    @endif
                </p>
                <p class="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-sm text-gray-600 dark:text-gray-400">
                    <span>AI kontrolünden geçen: <span class="font-medium tabular-nums text-gray-900 dark:text-white" data-daily-content>{{ $n($summary['content']) }} yazı</span>@if (($summary['content_preparing'] ?? 0) > 0) <span class="text-gray-500" data-daily-preparing>(bu haftanın {{ $n($summary['content_preparing']) }} yazısı hâlâ yazılıyor)</span>@endif</span>
                    <span class="font-medium tabular-nums text-gray-900 dark:text-white" data-daily-posts>{{ $n($summary['posts']) }} profil gönderisi</span>
                    <span class="font-medium tabular-nums text-gray-900 dark:text-white" data-daily-repair>{{ $n($summary['repair']) }} onarım</span>
                    @if ($summary['held_total'] > 0)
                        <button type="button" wire:click="$toggle('showHeld')" class="font-medium text-warning-700 underline decoration-warning-300 underline-offset-2 dark:text-warning-400" data-daily-held>{{ $n($summary['held_total']) }} iş kontrolde kaldı</button>
                    @endif
                </p>
            </div>
        </div>
        @if ($isAdmin)
            <x-ta.button type="button" wire:click="send" wire:loading.attr="disabled" :disabled="$running || $ready === 0" class="w-full sm:w-auto" data-daily-send-button>
                @if ($running)
                    <span class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/40 border-t-white"></span> Gönderiliyor…
                @else
                    Hepsini gönder
                @endif
            </x-ta.button>
        @endif
    </div>

    @if ($running && is_array($progress))
        @php $percent = $progress['total'] > 0 ? (int) min(100, round($progress['done'] * 100 / $progress['total'])) : 0; @endphp
        <div class="mt-3 space-y-1.5" data-daily-progress="{{ $progress['done'] }}/{{ $progress['total'] }}">
            <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10" role="progressbar" aria-label="Gönderim" aria-valuemin="0" aria-valuemax="{{ $progress['total'] }}" aria-valuenow="{{ $progress['done'] }}">
                <div class="h-full rounded-full bg-live-500 transition-all duration-500" style="width: {{ $percent }}%"></div>
            </div>
            <p class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-gray-600 dark:text-gray-400">
                <span class="font-semibold tabular-nums text-gray-900 dark:text-white" data-daily-progress-text>{{ $n($progress['done']) }} / {{ $n($progress['total']) }} gönderildi</span>
                <span class="tabular-nums">{{ $n($progress['content'][0]) }}/{{ $n($progress['content'][1]) }} yazı</span>
                <span class="tabular-nums">{{ $n($progress['posts'][0]) }}/{{ $n($progress['posts'][1]) }} gönderi</span>
                <span class="tabular-nums">{{ $n($progress['repair'][0]) }}/{{ $n($progress['repair'][1]) }} onarım</span>
            </p>
        </div>
    @endif

    @if ($stale)
        <p class="tone-look mt-3 rounded-lg px-3 py-2 text-sm" data-daily-stale>
            @if ($lastAt === null)
                Henüz hiç gönderim yapılmadı; hazır işler bekliyor.
            @else
                Son gönderim {{ $lastDays }} gün önce ({{ $lastAt->format('d.m H:i') }}); hazır işler o günden beri bekliyor.
            @endif
        </p>
    @endif

    @if (($summary['no_connector'] ?? []) !== [])
        <p class="mt-2 text-xs text-warning-700 dark:text-warning-400" data-daily-no-connector>
            Sitede eşleştirilmiş WordPress Connector olmadığı için gönderime girmeyen yazılar:
            {{ collect($summary['no_connector'])->map(fn (array $s): string => $s['site'].' '.$s['count'].' yazı')->implode(' · ') }}.
            <a href="{{ route('operator.websites') }}" wire:navigate class="font-semibold underline">Web siteleri</a>
        </p>
    @endif

    @if (is_array($last))
        <p class="mt-2 text-xs text-gray-500" data-daily-last>
            @if (isset($last['error']))
                Son gönderim yarıda kaldı: {{ $last['error'] }}
            @elseif ($lastAt !== null)
                Son gönderim {{ $lastAt->format('d.m H:i') }}: {{ $n((int) ($last['content']['sent'] ?? 0)) }} yazı, {{ $n((int) ($last['posts'] ?? 0)) }} gönderi, {{ $n((int) ($last['repair']['applied'] ?? 0)) }} onarım.
                @if ($failedReasons->isNotEmpty() || $repairFailed > 0)
                    Gönderilemeyen:
                    {{ $failedReasons->map(fn (int $count, string $reason): string => $count.' yazı: '.rtrim($reason, '.'))->values()->implode(' · ') }}@if ($failedReasons->isNotEmpty() && $repairFailed > 0) · @endif{{ $repairFailed > 0 ? $repairFailed.' onarım (nedeniyle masaya döndü)' : '' }}.
                @endif
            @endif
        </p>
    @endif

    @if ($message !== '')
        <p class="tone-info mt-3 rounded-lg px-3 py-2 text-sm">{{ $message }}</p>
    @endif
    @if ($showHeld && $summary['held'] !== [])
        <ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-800" data-daily-held-list>
            @foreach ($summary['held'] as $item)
                <li class="py-2">
                    <span class="text-xs text-gray-500">{{ $item['area'] }}</span>
                    <span class="font-medium text-gray-900 dark:text-white">{{ $item['title'] }}</span>
                    <span class="block text-warning-700 dark:text-warning-400">{{ $item['reason'] }}</span>
                </li>
            @endforeach
        </ul>
        @if ($summary['held_total'] > count($summary['held']))
            <p class="mt-1 text-xs text-gray-500">İlk {{ count($summary['held']) }} gösteriliyor.</p>
        @endif
    @endif
</div>
