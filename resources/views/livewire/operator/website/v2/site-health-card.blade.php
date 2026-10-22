@php
    use App\Services\Site\SiteHealthScore;
    // Aşama 7: the score on the dark panel (sector median and leader beside it), then one row per layer with its problem
    // types; a type the system can write has one "Hepsini onar", the rest say who does it.
    $n = fn ($v) => number_format((float) $v, 0, ',', '.');
    $tone = fn (int $points): string => $points >= 21 ? 'chip-up' : ($points >= 14 ? 'chip-flat' : 'chip-down');
    $line = null;
    if (count($trend) > 1) {
        $max = 100;
        $step = 88 / (count($trend) - 1);
        $line = collect($trend)->map(fn (int $s, int $i): string => round($i * $step, 1).','.round(26 - $s / $max * 24, 1))->implode(' ');
    }
    $fixLabel = ['manual' => 'Senin yapacağın: adımlar Onarım masasında', 'content' => 'İçerik planında ilk sırada', 'info' => 'Onarım masasında bekliyor'];
@endphp
<section class="space-y-4" data-site-health>
    <div class="hero-panel grid-cols-2 lg:grid-cols-4">
        <div class="hero-tile hero-tile-accent">
            <span class="hero-label">Site skoru</span>
            <span class="hero-value" data-site-score>{{ $score['score'] }}<span class="text-base font-medium opacity-70"> / 100</span></span>
            <span class="mt-2 text-xs opacity-75">
                @if ($previous !== null)
                    geçen hafta {{ $previous }} · {{ $score['score'] - $previous >= 0 ? '+' : '' }}{{ $score['score'] - $previous }}
                @else
                    {{ $n($score['pages']) }} sayfa üzerinden
                @endif
            </span>
        </div>
        <div class="hero-tile"><span class="hero-label">Sektör ortası</span><span class="hero-value">{{ $sector['median'] ?? '—' }}</span><span class="mt-2 text-xs text-white/60">{{ $sector['sites'] > 0 ? $sector['sites'].' site' : 'kıyas için yeterli site yok' }}</span></div>
        <div class="hero-tile"><span class="hero-label">Sektör lideri</span><span class="hero-value">{{ $sector['leader'] ?? '—' }}</span><span class="mt-2 text-xs text-white/60">{{ $sector['rank'] !== null ? 'sen '.$sector['rank'].'. sıradasın' : 'adı gösterilmez' }}</span></div>
        <div class="hero-tile">
            <span class="hero-label">8 hafta</span>
            @if ($line !== null)
                <svg viewBox="0 0 88 28" class="mt-2 h-8 w-full text-accent" preserveAspectRatio="none" aria-label="Son 8 haftanın site skoru"><polyline points="{{ $line }}" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"/></svg>
            @else
                <span class="mt-2 text-xs text-white/60">Skor her gece hesaplanır; eğilim birkaç gece sonra görünür.</span>
            @endif
        </div>
    </div>

    @if ($result !== null)
        <p class="text-sm text-gray-700 dark:text-gray-300" data-site-result><span class="chip {{ $result['clicks'] >= 0 ? 'chip-up' : 'chip-down' }}">Sonuç</span> {{ $result['sentence'] }}</p>
    @endif
    @if ($message !== '')
        <p class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300">{{ $message }}</p>
    @endif
    @if ($brandless)
        <p class="text-sm text-gray-500">Bu site bir markaya atanmadı; markasız sitelere otomatik iş yapılmaz.</p>
    @endif

    <div class="card divide-y divide-gray-200 dark:divide-gray-800" data-site-layers>
        @foreach (SiteHealthScore::LAYERS as $layer => $label)
            @php $items = $problems[$layer] ?? collect(); @endphp
            <div class="px-4 py-3.5" data-layer="{{ $layer }}">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="chip {{ $tone($score['layers'][$layer]) }} tabular-nums">{{ $score['layers'][$layer] }} / {{ SiteHealthScore::LAYER_POINTS }}</span>
                    <span class="font-medium text-gray-900 dark:text-white">{{ $label }}</span>
                    <span class="text-[13px] text-gray-500">{{ SiteHealthScore::LAYER_HINTS[$layer] }}</span>
                </div>
                @if ($items->isEmpty())
                    <p class="mt-2 pl-1 text-[13px] text-gray-500">Açık sorun yok.</p>
                @else
                    <ul class="mt-2.5 space-y-2">
                        @foreach ($items as $problem)
                            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/[0.03]" data-problem="{{ $problem['key'] }}">
                                <span class="min-w-0 flex-1">
                                    <span class="font-medium text-gray-900 dark:text-white">{{ $problem['title'] }}</span>
                                    <span class="tabular-nums text-gray-500"> · {{ $n($problem['count']) }}</span>
                                    @if ($problem['pages'] !== [])
                                        <span class="block truncate text-xs text-gray-500" title="{{ implode(', ', $problem['pages']) }}">{{ implode(' · ', $problem['pages']) }}</span>
                                    @endif
                                </span>
                                @if ($problem['fix'] === 'desk' && $isAdmin)
                                    <button type="button" wire:click="fixAll('{{ $problem['key'] }}')" wire:confirm="{{ $problem['title'] }}: {{ count($problem['ids']) }} iş siteye gönderilsin mi? Her biri geri alınabilir." class="btn-dark" data-fix-all>Hepsini onar ({{ $n(count($problem['ids'])) }})</button>
                                @else
                                    <span class="text-xs text-gray-500">{{ $problem['fix'] === 'desk' ? 'Yönetici onaylar' : $fixLabel[$problem['fix']] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>
</section>
