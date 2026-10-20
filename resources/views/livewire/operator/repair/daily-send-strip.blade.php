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
    $note = 'flex items-start gap-2.5 text-[13px] text-white/70';
@endphp
{{-- Günlük gönderim (living panel language 2026-10-20): a dark card, the ready count big with its parts and the one
     accent send button on the left; how a send works and the notes on the right. --}}
<section class="relative grid overflow-hidden rounded-[20px] bg-hero text-white lg:grid-cols-[1.2fr_1fr]" data-daily-send @if ($running) wire:poll.10s @endif>
    <div class="relative p-5 sm:p-6" style="background-image: radial-gradient(70% 90% at 0% 0%, color-mix(in srgb, var(--color-accent) 22%, transparent), transparent 60%)">
        <p class="flex items-center gap-2 text-[13px] text-white/60">@if ($ready > 0)<span class="live-dot" aria-hidden="true"></span>@endif{{ $ready > 0 ? 'Gönderilmeye hazır, Claude kontrol etti' : 'Günlük gönderim' }}</p>
        <p class="mt-3 font-semibold tabular-nums tracking-[-0.04em]" data-daily-ready="{{ $ready }}">
            @if ($ready > 0)
                <span class="text-[56px] leading-none sm:text-[60px]">{{ $n($ready) }}</span><span class="ml-1 text-[22px] font-medium tracking-normal text-white/60">iş</span>
            @else
                <span class="text-2xl leading-9 tracking-[-0.02em]">Gönderilecek hazır iş yok</span>
            @endif
        </p>
        <p class="mt-4 flex flex-wrap gap-2 text-[13px]">
            <span class="inline-flex h-[30px] items-center gap-1.5 rounded-[9px] border border-white/10 bg-white/[0.06] px-3" data-daily-content><b class="font-semibold tabular-nums">{{ $n($summary['content']) }}</b> yazı</span>
            <span class="inline-flex h-[30px] items-center gap-1.5 rounded-[9px] border border-white/10 bg-white/[0.06] px-3" data-daily-posts><b class="font-semibold tabular-nums">{{ $n($summary['posts']) }}</b> profil gönderisi</span>
            <span class="inline-flex h-[30px] items-center gap-1.5 rounded-[9px] border border-white/10 bg-white/[0.06] px-3" data-daily-repair><b class="font-semibold tabular-nums">{{ $n($summary['repair']) }}</b> site onarımı</span>
            @if ($summary['held_total'] > 0)
                <button type="button" wire:click="$toggle('showHeld')" class="inline-flex h-[30px] items-center rounded-[9px] px-2 text-[#f5c26b] hover:underline" data-daily-held>{{ $n($summary['held_total']) }} iş kontrolde kaldı</button>
            @endif
        </p>
        @if (($summary['content_preparing'] ?? 0) > 0)
            <p class="mt-2 text-[13px] text-white/60" data-daily-preparing>Bu haftanın {{ $n($summary['content_preparing']) }} yazısı hâlâ yazılıyor.</p>
        @endif
        @if ($running && is_array($progress))
            @php $percent = $progress['total'] > 0 ? (int) min(100, round($progress['done'] * 100 / $progress['total'])) : 0; @endphp
            <div class="mt-4 space-y-1.5" data-daily-progress="{{ $progress['done'] }}/{{ $progress['total'] }}">
                <div class="h-1.5 w-full overflow-hidden rounded-full bg-white/10" role="progressbar" aria-label="Gönderim" aria-valuemin="0" aria-valuemax="{{ $progress['total'] }}" aria-valuenow="{{ $progress['done'] }}">
                    <div class="h-full rounded-full bg-accent transition-all duration-500" style="width: {{ $percent }}%"></div>
                </div>
                <p class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-white/60">
                    <span class="font-medium tabular-nums text-white" data-daily-progress-text>{{ $n($progress['done']) }} / {{ $n($progress['total']) }} gönderildi</span>
                    <span class="tabular-nums">{{ $n($progress['content'][0]) }}/{{ $n($progress['content'][1]) }} yazı</span>
                    <span class="tabular-nums">{{ $n($progress['posts'][0]) }}/{{ $n($progress['posts'][1]) }} gönderi</span>
                    <span class="tabular-nums">{{ $n($progress['repair'][0]) }}/{{ $n($progress['repair'][1]) }} onarım</span>
                </p>
            </div>
        @endif
        @if ($isAdmin)
            <button type="button" wire:click="send" wire:loading.attr="disabled" @disabled($running || $ready === 0) class="btn-primary mt-5 h-[42px]! w-full rounded-xl! px-5! text-sm! disabled:bg-white/15! disabled:text-white/50! sm:w-auto" data-daily-send-button>
                @if ($running)
                    <span class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-current/40 border-t-current"></span> Gönderiliyor…
                @else
                    Hepsini gönder
                @endif
            </button>
        @endif
    </div>

    <div class="flex flex-col gap-3 border-t border-white/10 bg-white/[0.03] p-5 sm:p-6 lg:border-t-0 lg:border-l">
        <ol class="space-y-2.5 text-[13px]">
            <li class="flex items-center gap-2.5 text-white"><span class="flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-[7px] bg-accent text-[11px] text-accent-ink" aria-hidden="true">✓</span>Claude yazdı ve kontrol etti</li>
            <li class="flex items-center gap-2.5 text-white"><span class="flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-[7px] bg-accent text-[11px] text-accent-ink" aria-hidden="true">✓</span>Marka epikriziyle karşılaştırıldı</li>
            <li class="flex items-center gap-2.5 text-white/60"><span class="flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-[7px] border border-white/15 text-[11px] text-white" aria-hidden="true">3</span>Sen gönderince siteye yazılır, geri alınabilir</li>
            <li class="flex items-center gap-2.5 text-white/60"><span class="flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-[7px] border border-white/15 text-[11px] text-white" aria-hidden="true">4</span>30 gün sonra sonucu ölçülür</li>
        </ol>
        <div class="mt-auto space-y-2 pt-2">
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
                <span class="font-medium text-white">{{ collect($summary['no_connector'])->map(fn (array $s): string => $s['site'].' '.$s['count'].' yazı')->implode(', ') }}</span>
                gönderilemiyor: sitede eşleştirilmiş WordPress Connector yok.
            </span>
            <a href="{{ route('operator.websites') }}" wire:navigate class="shrink-0 font-medium text-white underline underline-offset-2">Siteyi bağla</a>
        </p>
    @endif

    @if (is_array($last))
        <p class="{{ $note }} text-white/50!" data-daily-last>
            <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full {{ isset($last['error']) || $failedReasons->isNotEmpty() || $repairFailed > 0 ? 'bg-warning-500' : 'bg-white/30' }}" aria-hidden="true"></span>
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
        </div>
    </div>

    @if ($showHeld && $summary['held'] !== [])
        <ul class="border-t border-white/10 text-sm lg:col-span-2" data-daily-held-list>
            @foreach ($summary['held'] as $item)
                <li class="border-t border-white/10 px-5 py-2.5 first:border-t-0 sm:px-6">
                    <span class="text-[13px] text-white/50">{{ $item['area'] }}</span>
                    <span class="font-medium text-white">{{ $item['title'] }}</span>
                    <span class="block text-[13px] text-[#f5c26b]">{{ $item['reason'] }}</span>
                </li>
            @endforeach
            @if ($summary['held_total'] > count($summary['held']))
                <li class="border-t border-white/10 px-5 py-2.5 text-xs text-white/50 sm:px-6">İlk {{ count($summary['held']) }} gösteriliyor.</li>
            @endif
        </ul>
    @endif
</section>
