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
<div class="rounded-xl bg-white p-4 sm:p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-daily-send @if ($running) wire:poll.10s @endif>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0 flex-1">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Bugün · günlük gönderim</p>
            <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white" data-daily-ready>
                @if ($ready > 0)
                    {{ $n($ready) }} iş gönderilmeye hazır
                @else
                    Gönderilecek hazır iş yok
                @endif
            </p>
            <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-400">
                AI kontrolünden geçen: <span class="font-medium text-gray-900 dark:text-white" data-daily-content>{{ $n($summary['content']) }} yazı</span>@if (($summary['content_preparing'] ?? 0) > 0) <span class="text-gray-500" data-daily-preparing>(bu haftanın {{ $n($summary['content_preparing']) }} yazısı hâlâ yazılıyor)</span>@endif ·
                <span class="font-medium text-gray-900 dark:text-white" data-daily-posts>{{ $n($summary['posts']) }} profil gönderisi</span> ·
                <span class="font-medium text-gray-900 dark:text-white" data-daily-repair>{{ $n($summary['repair']) }} onarım</span>
                @if ($summary['held_total'] > 0)
                    · <button type="button" wire:click="$toggle('showHeld')" class="text-amber-700 underline dark:text-amber-300" data-daily-held>{{ $n($summary['held_total']) }} iş kontrolde kaldı</button>
                @endif
            </p>
        </div>
        @if ($isAdmin)
            <button type="button" wire:click="send" wire:loading.attr="disabled" @disabled($running || $ready === 0)
                class="w-full shrink-0 rounded-lg bg-brand-500 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto" data-daily-send-button>
                {{ $running ? 'Gönderiliyor…' : 'Hepsini gönder' }}
            </button>
        @endif
    </div>

    @if ($stale)
        <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300" data-daily-stale>
            @if ($lastAt === null)
                Henüz hiç gönderim yapılmadı; hazır işler bekliyor.
            @else
                Son gönderim {{ $lastDays }} gün önce ({{ $lastAt->format('d.m H:i') }}); hazır işler o günden beri bekliyor.
            @endif
        </p>
    @endif

    @if (($summary['no_connector'] ?? []) !== [])
        <p class="mt-2 text-xs text-amber-700 dark:text-amber-300" data-daily-no-connector>
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
        <p class="mt-3 rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-800 dark:bg-gray-800 dark:text-gray-200">{{ $message }}</p>
    @endif
    @if ($showHeld && $summary['held'] !== [])
        <ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-800" data-daily-held-list>
            @foreach ($summary['held'] as $item)
                <li class="py-2">
                    <span class="text-xs text-gray-500">{{ $item['area'] }}</span>
                    <span class="font-medium text-gray-900 dark:text-white">{{ $item['title'] }}</span>
                    <span class="block text-amber-800 dark:text-amber-300">{{ $item['reason'] }}</span>
                </li>
            @endforeach
        </ul>
        @if ($summary['held_total'] > count($summary['held']))
            <p class="mt-1 text-xs text-gray-500">İlk {{ count($summary['held']) }} gösteriliyor.</p>
        @endif
    @endif
</div>
