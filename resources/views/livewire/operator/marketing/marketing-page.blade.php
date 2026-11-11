@php
    use App\Models\SalesLead;
    use App\Services\Marketing\MarketingFunnel;

    $n = fn (int|float $v): string => number_format((int) round($v), 0, ',', '.');
    $tl = fn (int|float $v): string => number_format((int) round($v), 0, ',', '.').' ₺';
    $pct = fn (int|float $part, int|float $whole): ?int => $whole > 0 ? (int) round($part * 100 / $whole) : null;
    $change = function (int|float $now, int|float $prev): ?string {
        if ($prev <= 0) {
            return null;
        }
        $p = (int) round(($now - $prev) * 100 / $prev);

        return ($p > 0 ? '+' : '').$p.'%';
    };
    $day = fn (?string $d): string => $d !== null ? \Carbon\CarbonImmutable::parse($d)->locale('tr')->isoFormat('D MMM') : '—';
    $monthLabel = $monthDate->locale('tr')->isoFormat('MMMM YYYY');
    $periodLabel = $funnel['span'] > 1 ? $funnel['from']->locale('tr')->isoFormat('MMM').'–'.$monthDate->locale('tr')->isoFormat('MMM YYYY') : $monthLabel;
    $t = $funnel['totals'];
    $meta = $own['meta'];
    $google = $own['google_ads'];
    $site = $own['web'];
    $gbp = $own['gbp'];
    $social = $own['social'];
    $spend = (float) $meta['spend'] + (float) $google['cost'];
    $paidApps = $rows['meta']['applications'] + $rows['google_ads']['applications'];

    // What each channel put in this month, in one line under its name.
    $input = [
        'meta' => $meta['connected'] ? ($meta['spend'] > 0 ? $tl($meta['spend']).' · '.$n($meta['messages'] + $meta['leads']).' mesaj/form' : 'bu ay harcama yok') : 'hesap bağlı değil',
        'web' => $site['connected'] ? $n($site['clicks']).' Google tıklaması' : 'Search Console bağlı değil',
        'google_ads' => $google['connected'] ? ($google['cost'] > 0 ? $tl($google['cost']).' · '.$n($google['clicks']).' tık' : 'bu ay harcama yok') : 'hesap bağlı değil',
        'gbp' => $gbp['connected'] ? $n($gbp['calls']).' arama · '.$n($gbp['directions']).' yol tarifi' : 'profil verisi yok',
        'social' => $social['accounts'] === 0 ? 'hesaplar bağlı değil' : $n($social['published']).' gönderi yayında',
        'referral' => 'tavsiye ve All for Clinics',
        'unknown' => $rows['unknown']['applications'] > 0 ? 'kaynağı işaretlenmeli' : 'hepsi işaretli',
        'outbound' => 'bizim yazdıklarımız',
    ];
    $hue = ['meta' => 220, 'web' => 150, 'google_ads' => 40, 'gbp' => 10, 'social' => 300, 'referral' => 180, 'unknown' => 0, 'outbound' => 260];
    $color = fn (string $key): string => 'hsl('.$hue[$key].' '.($key === 'unknown' ? '0%' : '35%').' 52%)';
    $bar = fn (int $done, int $target): string => $target <= 0 ? '' : ($done >= $target ? 'bg-success-500' : ($done * 2 >= $target ? 'bg-accent' : 'bg-error-500'));
    $toneClass = ['critical' => 'soft-red', 'warning' => 'soft-amber', 'info' => ''];
    $stageChip = ['new' => 'chip-flat', 'contacted' => 'chip-flat', 'talking' => 'chip-accent', 'meeting' => 'chip-accent', 'priced' => 'chip-accent', 'thinking' => 'chip-accent', 'later' => 'chip-flat', 'won' => 'chip-up', 'lost' => 'chip-down'];
    $selectCls = 'h-8 rounded-lg border border-gray-200 bg-white px-2 text-[13px] text-gray-700 dark:border-gray-700 dark:bg-transparent dark:text-gray-200';
@endphp
{{-- Pazarlama (2026-11-08): one screen like the Gelen kutusu. Left the channels with what they cost and brought, right
     the chosen channel's whole story or the overview (what to do, funnel, six months). --}}
<div class="pb-24" data-marketing>
    <header class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex min-w-0 flex-wrap items-baseline gap-x-3 gap-y-1">
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Pazarlama</h1>
            <p class="text-sm text-gray-500" data-marketing-strip>
                <span class="font-semibold text-gray-900 dark:text-white">{{ $n($t['counted']) }}{{ $t['target'] > 0 ? ' / '.$n($t['target']) : '' }} başvuru</span>
                @if ($pace['expected'] !== null && $t['target'] > 0)<span> · bugüne kadar beklenen {{ $n($pace['expected']) }}</span>@endif
                <span> · {{ $n($t['won']) }} müşteri</span>
                @if ($t['revenue'] > 0)<span> · {{ $tl($t['revenue']) }}/ay</span>@endif
                @if ($spend > 0)<span> · {{ $tl($spend) }} reklam</span>@endif
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <select wire:model.live="filter" class="{{ $selectCls }}" aria-label="Tür" title="Hedef sağlık başvurusu içindir; diğer türler ayrı satırda kalır." data-marketing-filter-select>
                @foreach ($filters as $f)
                    <option value="{{ $f['key'] }}">{{ $f['label'] }}</option>
                @endforeach
            </select>
            <select wire:model.live="span" class="{{ $selectCls }}" aria-label="Dönem" data-marketing-span-select>
                @foreach (MarketingFunnel::SPANS as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
            <div class="flex items-center gap-0.5" data-marketing-month>
                <button type="button" wire:click="shift(-1)" class="btn-quiet h-8 px-2" aria-label="Önceki ay">‹</button>
                <span class="min-w-[8.5rem] text-center text-sm font-semibold text-gray-900 dark:text-white">{{ $periodLabel }}</span>
                <button type="button" wire:click="shift(1)" class="btn-quiet h-8 px-2" aria-label="Sonraki ay" @disabled($isThisMonth)>›</button>
            </div>
            <a href="{{ route('operator.social') }}" wire:navigate class="btn h-8 px-3 text-[13px]" data-marketing-social-link>Gönderi planı</a>
            <a href="{{ route('operator.social', ['sekme' => 'hesaplar']) }}" wire:navigate @class(['h-8 px-3 text-[13px]', 'btn-primary' => $social['accounts'] === 0, 'btn' => $social['accounts'] > 0]) data-marketing-social-accounts>{{ $social['accounts'] === 0 ? 'Sosyal hesapları bağla' : 'Sosyal hesaplar' }}</a>
        </div>
    </header>

    @if ($message !== '')
        <p role="status" class="mt-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300" data-marketing-message>{{ $message }}</p>
    @endif

    <div class="mt-3 flex min-h-[36rem] flex-col overflow-hidden md:flex-row rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900" data-marketing-grid>
        {{-- Left: the channels --}}
        <nav @class(['w-full shrink-0 border-gray-200 md:w-[21rem] md:border-r dark:border-gray-800', 'hidden md:block' => $channel !== '']) aria-label="Kanallar" data-marketing-channels>
            <button type="button" wire:click="show('')" @class(['flex w-full items-center gap-3 border-b border-gray-100 px-3 py-3 text-left hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.04]', 'bg-gray-100 dark:bg-white/[0.08]' => $channel === '']) data-marketing-channel="genel">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gray-900 text-sm font-semibold text-white dark:bg-white dark:text-gray-900">∑</span>
                <span class="min-w-0 flex-1">
                    <span class="block text-[15px] text-gray-900 dark:text-white">Genel bakış</span>
                    <span class="block truncate text-[13px] text-gray-500">{{ count($actions) > 0 ? count($actions).' iş bekliyor' : 'her şey yolunda' }}</span>
                </span>
                @if (count($actions) > 0)<span class="rounded-full bg-accent px-2 py-0.5 text-xs font-semibold text-gray-900">{{ count($actions) }}</span>@endif
            </button>
            @foreach (MarketingFunnel::CHANNELS as $key => $label)
                @php $row = $rows[$key]; @endphp
                @if ($key === 'unknown')<div class="border-t border-gray-200 dark:border-gray-800"></div>@endif
                <button type="button" wire:key="ch-{{ $key }}" wire:click="show('{{ $key }}')" @class(['flex w-full items-center gap-3 px-3 text-left hover:bg-gray-50 dark:hover:bg-white/[0.04]', 'bg-gray-100 dark:bg-white/[0.08]' => $channel === $key]) data-marketing-channel="{{ $key }}">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold text-white" style="background: {{ $color($key) }}">{{ mb_substr($label, 0, 1) }}</span>
                    <span class="min-w-0 flex-1 border-b border-gray-100 py-3 dark:border-gray-800">
                        <span class="flex items-baseline justify-between gap-2">
                            <span class="truncate text-[15px] text-gray-900 dark:text-white">{{ $label }}</span>
                            <span @class(['shrink-0 text-sm tabular-nums', 'font-semibold text-error-600' => $key === 'unknown' && $row['applications'] > 0, 'text-gray-900 dark:text-white' => ! ($key === 'unknown' && $row['applications'] > 0)])>{{ $n($row['applications']) }}{{ $row['target'] > 0 ? ' / '.$n($row['target']) : '' }}</span>
                        </span>
                        <span class="mt-0.5 flex items-center justify-between gap-2">
                            <span class="truncate text-[13px] text-gray-500">{{ $input[$key] }}</span>
                            @if ($row['target'] > 0)
                                <span class="h-1 w-14 shrink-0 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800"><span class="block h-full rounded-full {{ $bar($row['applications'], $row['target']) }}" style="width: {{ min(100, $pct($row['applications'], $row['target'])) }}%"></span></span>
                            @elseif ($row['won'] > 0)
                                <span class="shrink-0 text-xs text-success-700 dark:text-success-400">{{ $n($row['won']) }} müşteri</span>
                            @endif
                        </span>
                    </span>
                </button>
            @endforeach
            @if ($isAdmin)
                <div class="p-3">
                    @if ($editingTargets)
                        <form wire:submit="saveTargets" class="space-y-2 rounded-xl border border-gray-200 p-3 dark:border-gray-800" data-marketing-targets-form>
                            <p class="text-xs text-gray-500">Aylık başvuru hedefi (sağlık). Kaynağı bilinmeyen başvuru toplama sayılır; Biz ulaştık sayılmaz.</p>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach (MarketingFunnel::CHANNELS as $key => $label)
                                    @if (! in_array($key, ['unknown', 'outbound'], true))
                                        <label class="block text-xs text-gray-500">{{ $label }}<input wire:model="targets.{{ $key }}" type="number" min="0" max="999" class="mt-1 h-8 w-full rounded-lg border border-gray-200 bg-white px-2 text-sm dark:border-gray-700 dark:bg-transparent dark:text-white" /></label>
                                    @endif
                                @endforeach
                            </div>
                            <div class="flex items-center gap-2"><button type="submit" class="btn-dark h-8 px-3 text-[13px]">Kaydet</button><button type="button" wire:click="$set('editingTargets', false)" class="btn-quiet h-8 px-3 text-[13px]">Vazgeç</button></div>
                        </form>
                    @else
                        <button type="button" wire:click="editTargets" class="link-neutral text-xs" data-marketing-edit-targets>Hedefi düzenle (ayda {{ $n(array_sum($targets)) }})</button>
                    @endif
                </div>
            @endif
        </nav>

        {{-- Right: the overview or the chosen channel --}}
        <section class="min-w-0 flex-1 border-t border-gray-200 p-4 sm:p-6 md:border-t-0 dark:border-gray-800" data-marketing-detail>
            @if ($channel === '')
                {{-- Genel bakış --}}
                <div class="hero-panel grid-cols-2 lg:grid-cols-4" data-marketing-hero>
                    <div class="hero-tile hero-tile-accent"><span class="hero-label">Başvuru</span><span class="hero-value">{{ $n($t['counted']) }}{{ $t['target'] > 0 ? ' / '.$n($t['target']) : '' }}</span><span class="mt-2 text-xs opacity-75">{{ $t['target'] > 0 ? 'hedefe göre %'.$pct($t['counted'], $t['target']) : 'hedef girilmedi' }}{{ $pace['expected'] !== null && $t['target'] > 0 ? ' · ayın '.$pace['day'].'. günü' : '' }}</span></div>
                    <div class="hero-tile"><span class="hero-label">Teklif → müşteri</span><span class="hero-value">{{ $n($t['offered']) }} → {{ $n($t['won']) }}</span><span class="mt-2 text-xs text-white/60">{{ $t['offered'] > 0 ? '%'.$pct($t['won'], $t['offered']).' kapanış' : 'teklif yok' }}</span></div>
                    <div class="hero-tile"><span class="hero-label">Kazanılan</span><span class="hero-value">{{ $tl($t['revenue']) }}</span><span class="mt-2 text-xs text-white/60">aylık, bu dönemde açılan kayıtlardan</span></div>
                    <div class="hero-tile"><span class="hero-label">Reklam → başvuru</span><span class="hero-value">{{ $spend > 0 ? $tl($spend) : '0 ₺' }}</span><span class="mt-2 text-xs text-white/60">{{ $spend > 0 ? ($paidApps > 0 ? 'başvuru başına '.$tl($spend / $paidApps) : 'kayda düşen başvuru yok') : 'bu ay reklam harcaması yok' }}</span></div>
                </div>

                <div class="mt-6" data-marketing-actions>
                    <div class="section-head"><h2 class="section-title">Yapılacaklar</h2><span class="section-count">{{ count($actions) }}</span></div>
                    @if ($actions === [])
                        <p class="mt-2 text-sm text-gray-500">Bu ay rakamların istediği bir iş yok.</p>
                    @else
                        <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
                            @foreach ($actions as $a)
                                <div class="soft-card {{ $toneClass[$a['tone']] }}" data-marketing-action="{{ $a['key'] }}">
                                    <p class="text-[15px] font-semibold text-gray-900 dark:text-white">{{ $a['title'] }}</p>
                                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ $a['body'] }}</p>
                                    @if ($a['channel'] !== null && $a['cta'] !== '')
                                        <button type="button" wire:click="show('{{ $a['channel'] }}')" class="btn self-start h-8 px-3 text-[13px]">{{ $a['cta'] }} →</button>
                                    @elseif ($a['route'] !== null)
                                        <a href="{{ route($a['route'][0], $a['route'][1]) }}" wire:navigate class="btn self-start h-8 px-3 text-[13px]">{{ $a['cta'] }} →</a>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <div data-marketing-funnel>
                        <div class="section-head"><h2 class="section-title">Huni</h2><span class="text-xs text-gray-500">{{ $funnel['filter_label'] }} · {{ $periodLabel }}</span></div>
                        @php $steps = [['Başvuru', $t['applications']], ['Konuşma', $t['talked']], ['Teklif', $t['offered']], ['Müşteri', $t['won']]]; @endphp
                        <div class="mt-3 space-y-2">
                            @foreach ($steps as $i => [$label, $value])
                                <div class="flex items-center gap-3">
                                    <span class="w-20 shrink-0 text-sm text-gray-600 dark:text-gray-300">{{ $label }}</span>
                                    <span class="h-7 flex-1 overflow-hidden rounded-lg bg-gray-100 dark:bg-white/[0.05]"><span class="flex h-full items-center rounded-lg bg-second/80 px-2 text-xs font-semibold text-white" style="width: {{ max(6, $pct($value, max(1, $t['applications'])) ?? 0) }}%">{{ $n($value) }}</span></span>
                                    <span class="w-12 shrink-0 text-right text-xs tabular-nums text-gray-500">{{ $i > 0 && $steps[$i - 1][1] > 0 ? '%'.$pct($value, $steps[$i - 1][1]) : '' }}</span>
                                </div>
                            @endforeach
                        </div>
                        @if ($funnel['excluded']['applications'] > 0 && $funnel['excluded']['label'] !== '')
                            <p class="mt-3 text-xs text-gray-500" data-marketing-excluded>{{ $funnel['excluded']['label'] }}: {{ $n($funnel['excluded']['applications']) }} başvuru, hedefe sayılmaz{{ $funnel['excluded']['won'] > 0 ? '; '.$n($funnel['excluded']['won']).' müşteri oldu'.($funnel['excluded']['revenue'] > 0 ? ' ('.$tl($funnel['excluded']['revenue']).'/ay)' : '') : '' }}.</p>
                        @endif
                    </div>
                    <div data-marketing-trend>
                        <div class="section-head"><h2 class="section-title">Son 6 ay</h2><span class="text-xs text-gray-500">renkli: başvuru · gri zemin: hedef · m.: müşteri</span></div>
                        @php $peak = max(1, ...array_map(fn ($m) => max($m['counted'], $m['target']), $trend)); @endphp
                        <div class="mt-3 flex h-40 items-end gap-2">
                            @foreach ($trend as $m)
                                <div class="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1" title="{{ $m['label'] }}: {{ $m['counted'] }} başvuru{{ $m['target'] > 0 ? ' / '.$m['target'] : '' }}, {{ $m['won'] }} müşteri{{ $m['revenue'] > 0 ? ', '.$tl($m['revenue']).'/ay' : '' }}">
                                    <span class="text-xs tabular-nums text-gray-700 dark:text-gray-200">{{ $m['counted'] }}</span>
                                    <span class="relative flex w-full max-w-10 flex-col-reverse overflow-hidden rounded-md bg-gray-100 dark:bg-white/[0.05]" style="height: {{ max(4, (int) round(max($m['counted'], $m['target']) * 100 / $peak)) }}%">
                                        @foreach (MarketingFunnel::CHANNELS as $key => $label)
                                            @if (($m['by'][$key] ?? 0) > 0 && $key !== 'outbound')
                                                <span class="block w-full" style="height: {{ $m['by'][$key] * 100 / max($m['counted'], $m['target']) }}%; background: {{ $color($key) }}"></span>
                                            @endif
                                        @endforeach
                                    </span>
                                    <span class="text-xs text-gray-500">{{ $m['label'] }}</span>
                                    <span @class(['text-[11px] tabular-nums', 'font-semibold text-success-700 dark:text-success-400' => $m['won'] > 0, 'text-gray-400' => $m['won'] === 0])>{{ $m['won'] }} m.</span>
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-gray-500">
                            @foreach (MarketingFunnel::CHANNELS as $key => $label)
                                @if ($key !== 'outbound')<span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full" style="background: {{ $color($key) }}"></span>{{ $label }}</span>@endif
                            @endforeach
                        </p>
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <div data-marketing-summary>
                        <h2 class="section-title">{{ $isThisMonth ? 'Bu ay' : $monthDate->locale('tr')->isoFormat('MMMM') }} ne çalıştı</h2>
                        <ul class="mt-2 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                            @foreach ($summary as $line)<li>{{ $line }}</li>@endforeach
                        </ul>
                        <p class="mt-3 text-xs text-gray-500">Her ayın 1'inde geçen ayın özeti telefona düşer. Kazanma ve kaybetme nedenleri: <a href="{{ route('operator.sales', ['sekme' => 'sonuclar']) }}" wire:navigate class="link-neutral">Satış › Sonuçlar</a>.</p>
                    </div>
                    <div data-marketing-won>
                        <h2 class="section-title">Müşteri olanlar</h2>
                        @forelse ($funnel['won_names'] as $w)
                            <p class="mt-2 text-sm"><a href="{{ route('operator.sales.lead', $w['id']) }}" wire:navigate class="link-neutral">{{ $w['title'] }}</a><span class="text-gray-500"> · {{ $w['channel'] }}{{ $w['fee'] > 0 ? ' · '.$tl($w['fee']).'/ay' : '' }}</span></p>
                        @empty
                            <p class="mt-2 text-sm text-gray-500">Bu dönemde açılan kayıtlardan henüz müşteri çıkmadı.</p>
                        @endforelse
                    </div>
                </div>
            @else
                {{-- One channel --}}
                @php $row = $rows[$channel]; @endphp
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="show('')" class="btn-quiet h-8 px-2 md:hidden" aria-label="Kanallara dön">‹</button>
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white" data-marketing-channel-title>{{ MarketingFunnel::CHANNELS[$channel] }}</h2>
                        @if (! $row['counts'])<span class="chip chip-flat">hedefe sayılmaz</span>@endif
                    </div>
                    @php $last = ['meta' => $meta['last_day'], 'google_ads' => $google['last_day'], 'web' => $site['last_day'], 'gbp' => $gbp['last_day']][$channel] ?? null; @endphp
                    @if ($last !== null)<span class="text-xs text-gray-500">veri {{ $day($last) }} gününe kadar</span>@endif
                </div>

                {{-- What came out: the same four steps for every channel --}}
                <div class="metric-strip mt-4 grid-cols-2 lg:grid-cols-4" data-marketing-outcome>
                    <x-ta.metric-card label="Başvuru" :value="$n($row['applications']).($row['target'] > 0 ? ' / '.$n($row['target']) : '')" :hint="$row['target'] > 0 ? 'hedefe göre %'.$pct($row['applications'], $row['target']) : 'bu kanalın hedefi yok'" />
                    <x-ta.metric-card label="Konuşma" :value="$n($row['talked'])" :hint="$row['applications'] > 0 ? '%'.$pct($row['talked'], $row['applications']).' konuştu' : '—'" />
                    <x-ta.metric-card label="Teklif" :value="$n($row['offered'])" />
                    <x-ta.metric-card label="Müşteri" :value="$n($row['won'])" :hint="$row['revenue'] > 0 ? $tl($row['revenue']).' / ay' : 'henüz ₺ yok'" :tone="$row['won'] > 0 ? 'positive' : 'neutral'" />
                </div>

                {{-- What went in: the channel's own numbers --}}
                @if ($channel === 'meta')
                    <div class="metric-strip mt-3 grid-cols-2 lg:grid-cols-4" data-marketing-meta>
                        @if ($meta['connected'])
                            <x-ta.metric-card label="Harcama" :value="$tl($meta['spend'])" :delta="$change($meta['spend'], $meta['spend_prev'])" hint="geçen ayın aynı günlerine göre" />
                            <x-ta.metric-card label="Meta'nın saydığı" :value="$n($meta['messages'] + $meta['leads'])" :hint="$n($meta['messages']).' mesaj · '.$n($meta['leads']).' form'" />
                            <x-ta.metric-card label="Mesaj başına" :value="$meta['messages'] + $meta['leads'] > 0 ? $tl($meta['spend'] / ($meta['messages'] + $meta['leads'])) : '—'" />
                            <x-ta.metric-card label="Başvuru başına" :value="$row['applications'] > 0 ? $tl($meta['spend'] / $row['applications']) : '—'" :hint="$row['won'] > 0 ? 'müşteri başına '.$tl($meta['spend'] / $row['won']) : 'kayda düşen başvuruya göre'" :tone="$meta['spend'] > 0 && $row['applications'] === 0 ? 'critical' : 'neutral'" />
                        @endif
                    </div>
                    @if (! $meta['connected'])
                        <p class="mt-3 text-sm text-gray-500">Moximu markasına bağlı Meta reklam hesabında veri yok. <a href="{{ route('operator.integrations.meta') }}" wire:navigate class="link-neutral">Meta bağlantısı</a></p>
                    @elseif ($meta['campaigns'] !== [])
                        <div class="card mt-4 overflow-x-auto" data-marketing-campaigns>
                            <table class="data-table"><thead><tr><th>Kampanya</th><th class="text-right">Harcama</th><th class="text-right">Mesaj</th><th class="text-right">Form</th><th class="text-right">Mesaj başına</th><th class="text-right">Son gün</th></tr></thead><tbody>
                                @foreach ($meta['campaigns'] as $c)
                                    <tr><td class="max-w-[22rem] truncate">{{ $c['name'] }}</td><td class="text-right tabular-nums">{{ $tl($c['spend']) }}</td><td class="text-right tabular-nums">{{ $n($c['messages']) }}</td><td class="text-right tabular-nums">{{ $n($c['leads']) }}</td><td class="text-right tabular-nums">{{ $c['messages'] + $c['leads'] > 0 ? $tl($c['spend'] / ($c['messages'] + $c['leads'])) : '—' }}</td><td class="text-right text-gray-500">{{ $day($c['last_day']) }}</td></tr>
                                @endforeach
                            </tbody></table>
                        </div>
                    @endif
                    @if ($adLeads !== [])
                        {{-- Hangi reklam gerçek aday getiriyor (Satış Düzeni 4.3): the ad is judged by who it brought, not by its message count. --}}
                        <div class="mt-6" data-marketing-ad-leads>
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Hangi reklam aday getiriyor</h3>
                            <p class="text-[13px] text-gray-500">Son {{ \App\Services\Marketing\AdLeads::DAYS }} günde reklamdan WhatsApp'a yazanlar, kayıtlarının bugünkü durumuyla.</p>
                            <div class="card mt-3 overflow-x-auto">
                                <table class="data-table"><thead><tr><th>Reklam</th><th class="text-right">Yazan</th><th class="text-right">Aday</th><th class="text-right">Kapıda elenen</th><th class="text-right">Teklif</th><th class="text-right">Müşteri</th><th class="text-right">Son yazan</th></tr></thead><tbody>
                                    @foreach ($adLeads as $ad)
                                        <tr data-marketing-ad-row>
                                            <td class="max-w-[22rem]"><span class="block truncate font-medium text-gray-900 dark:text-white" title="{{ $ad['ad'] }}">{{ $ad['ad'] }}</span>@if ($ad['text'] !== '')<span class="block truncate text-xs text-gray-500" title="{{ $ad['text'] }}">{{ $ad['text'] }}</span>@endif</td>
                                            <td class="text-right tabular-nums">{{ $n($ad['wrote']) }}</td>
                                            <td class="text-right tabular-nums font-semibold text-gray-900 dark:text-white">{{ $n($ad['prospects']) }}</td>
                                            <td class="text-right tabular-nums text-gray-500">{{ $n($ad['screened']) }}</td>
                                            <td class="text-right tabular-nums">{{ $n($ad['offers']) }}</td>
                                            <td class="text-right tabular-nums">{{ $n($ad['won']) }}</td>
                                            <td class="text-right tabular-nums text-gray-500">{{ $ad['last'] !== '' ? \Carbon\CarbonImmutable::parse($ad['last'])->format('d.m') : '' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody></table>
                            </div>
                        </div>
                    @endif
                    <p class="mt-3 text-xs text-gray-500">Reklamdan WhatsApp'a yazan kişi, ilk mesajında reklamın bilgisini getirir ve kaydı kendiliğinden "Instagram / Facebook reklamı" olur. Meta'nın saydığı mesaj ile kayda düşen başvuru arasındaki fark, işaretlenmemiş ya da kayda dönmemiş konuşmadır. Reklam hesabına buradan yazılmaz.</p>
                @elseif ($channel === 'google_ads')
                    @if ($google['connected'])
                        <div class="metric-strip mt-3 grid-cols-2 lg:grid-cols-4" data-marketing-google>
                            <x-ta.metric-card label="Harcama" :value="$tl($google['cost'])" :delta="$change($google['cost'], $google['cost_prev'])" />
                            <x-ta.metric-card label="Tıklama" :value="$n($google['clicks'])" />
                            <x-ta.metric-card label="Google'ın dönüşümü" :value="$n($google['conversions'])" />
                            <x-ta.metric-card label="Başvuru başına" :value="$row['applications'] > 0 && $google['cost'] > 0 ? $tl($google['cost'] / $row['applications']) : '—'" />
                        </div>
                    @else
                        <p class="mt-3 text-sm text-gray-500" data-marketing-google>Moximu markasına bağlı Google Ads hesabında veri yok.</p>
                    @endif
                @elseif ($channel === 'web')
                    @if ($site['connected'])
                        <div class="metric-strip mt-3 grid-cols-2 lg:grid-cols-4" data-marketing-site>
                            <x-ta.metric-card label="Google tıklaması" :value="$n($site['clicks'])" :delta="$change($site['clicks'], $site['clicks_prev'])" :tone="$site['clicks'] < $site['clicks_prev'] ? 'negative' : 'positive'" hint="geçen ayın aynı günlerine göre" />
                            <x-ta.metric-card label="Gösterim" :value="$n($site['impressions'])" :delta="$change($site['impressions'], $site['impressions_prev'])" />
                            <x-ta.metric-card label="Tıklama → başvuru" :value="$site['clicks'] > 0 ? '%'.number_format($row['applications'] * 100 / $site['clicks'], 1, ',', '.') : '—'" />
                            <x-ta.metric-card label="Site" value="Web sitemiz" :href="$website !== null ? route('operator.website', ['assetId' => $website]) : null" hint="içerik ve teknik durum" :muted="true" />
                        </div>
                        <div class="card mt-4 p-4" data-marketing-queries>
                            <h3 class="section-title">En çok tıklanan aramalar</h3>
                            @forelse ($site['queries'] as $q)
                                <p class="mt-1.5 flex items-center justify-between gap-3 text-sm"><span class="truncate text-gray-900 dark:text-white">{{ $q['query'] }}</span><span class="shrink-0 tabular-nums text-gray-500">{{ $n($q['clicks']) }} tık · {{ $n($q['impressions']) }} gösterim</span></p>
                            @empty
                                <p class="mt-2 text-sm text-gray-500">Bu ay sorgu verisi yok.</p>
                            @endforelse
                        </div>
                    @else
                        <p class="mt-3 text-sm text-gray-500" data-marketing-site>Moximu sitesinin Search Console bağlantısı yok.</p>
                    @endif
                @elseif ($channel === 'gbp')
                    @if ($gbp['connected'])
                        <div class="metric-strip mt-3 grid-cols-2 lg:grid-cols-4" data-marketing-gbp>
                            <x-ta.metric-card label="Görüntülenme" :value="$n($gbp['views'])" :delta="$change($gbp['views'], $gbp['views_prev'])" />
                            <x-ta.metric-card label="Arama" :value="$n($gbp['calls'])" hint="profilden telefon" />
                            <x-ta.metric-card label="Yol tarifi" :value="$n($gbp['directions'])" />
                            <x-ta.metric-card label="Siteye geçiş" :value="$n($gbp['website'])" />
                        </div>
                        @if ($gbp['keywords'] !== [])
                            <div class="card mt-4 p-4" data-marketing-gbp-keywords>
                                <h3 class="section-title">Profili bulduran aramalar <span class="text-xs font-normal text-gray-500">({{ $gbp['keywords_month'] }})</span></h3>
                                @foreach ($gbp['keywords'] as $k)
                                    <p class="mt-1.5 flex items-center justify-between gap-3 text-sm"><span class="truncate text-gray-900 dark:text-white">{{ $k['keyword'] }}</span><span class="shrink-0 tabular-nums text-gray-500">{{ $n($k['impressions']) }} gösterim</span></p>
                                @endforeach
                            </div>
                        @endif
                    @else
                        <p class="mt-3 text-sm text-gray-500" data-marketing-gbp>Moximu'nun İşletme Profili verisi yok.</p>
                    @endif
                @elseif ($channel === 'social')
                    <div class="metric-strip mt-3 grid-cols-2 lg:grid-cols-4" data-marketing-social>
                        <x-ta.metric-card label="Bağlı hesap" :value="$n($social['accounts'])" :tone="$social['accounts'] === 0 ? 'critical' : 'neutral'" :href="route('operator.social', ['sekme' => 'hesaplar'])" :hint="$social['accounts'] === 0 ? 'hiçbir gönderi yayınlanamaz' : 'Instagram · Facebook · LinkedIn'" />
                        <x-ta.metric-card label="Yayınlanan" :value="$n($social['published'])" :hint="'geçen ay '.$n($social['published_prev'])" />
                        <x-ta.metric-card label="Onay bekleyen" :value="$n($social['drafts'])" :hint="$n($social['drafts_week']).' tanesi bu hafta'" :href="route('operator.social')" />
                        <x-ta.metric-card label="Ölçü" value="başvuru" hint="takipçi değil; görüşmede 'Instagram'da gördüm'" :muted="true" />
                    </div>
                    @if ($social['next'] !== [])
                        <div class="card mt-4 p-4" data-marketing-social-next>
                            <div class="section-head"><h3 class="section-title">Sıradaki gönderiler</h3><a href="{{ route('operator.social') }}" wire:navigate class="link-neutral text-xs">Gönderi planı →</a></div>
                            @foreach ($social['next'] as $p)
                                <p class="mt-1.5 flex items-center justify-between gap-3 text-sm"><span class="truncate text-gray-900 dark:text-white">{{ $p['title'] }}</span><span class="shrink-0 text-gray-500">{{ $p['at']->locale('tr')->isoFormat('D MMM ddd HH:mm') }} · {{ $p['status'] === 'draft' ? 'onay bekliyor' : 'onaylı' }}</span></p>
                            @endforeach
                        </div>
                    @endif
                @elseif ($channel === 'unknown')
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl px-4 py-3 text-sm tone-look" data-marketing-unknown-help>
                        <span>Kaynağı bilinmeyen kayıt huniyi eksik sayar. Her kayıtta nereden duyduğunu seçin; ilk WhatsApp mesajından okunabilenler için öneri var.</span>
                        @if ($suggested > 0)
                            <button type="button" wire:click="applySuggestions" class="btn-dark h-8 px-3 text-[13px]" data-marketing-apply-suggestions>Önerileri uygula ({{ $n($suggested) }})</button>
                        @endif
                    </div>
                @elseif ($channel === 'referral')
                    <p class="mt-3 text-sm text-gray-500">Tavsiyeyle ve All for Clinics üzerinden gelenler. Harcaması yok; en ucuz kanal, her müşteri yeni bir tavsiyedir.</p>
                @elseif ($channel === 'outbound')
                    <p class="mt-3 text-sm text-gray-500">Bizim ilk yazdığımız, sitesini paylaştığımız kayıtlar. Pazarlamanın getirdiği başvuru değil; hedefe sayılmaz, satıştaki sonucu burada görünür.</p>
                @endif

                {{-- The records of the channel --}}
                <div class="mt-6" data-marketing-records>
                    <div class="section-head"><h3 class="section-title">Kayıtlar</h3><span class="section-count">{{ count($records) }}</span></div>
                    @if ($records === [])
                        <p class="mt-2 text-sm text-gray-500">Bu dönemde bu kanaldan açılan {{ mb_strtolower($funnel['filter_label']) }} kaydı yok.</p>
                    @else
                        <div class="card mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($records as $r)
                                <div wire:key="rec-{{ $r['id'] }}" class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-2.5" data-marketing-record="{{ $r['id'] }}">
                                    <span class="min-w-0 flex-1">
                                        <a href="{{ route('operator.sales.lead', $r['id']) }}" wire:navigate class="block truncate text-sm font-medium text-gray-900 hover:underline dark:text-white">{{ $r['title'] }}</a>
                                        <span class="block truncate text-xs text-gray-500">{{ $r['created_at']->locale('tr')->isoFormat('D MMM') }}{{ $r['kind'] !== null ? ' · '.$r['kind'] : '' }}</span>
                                    </span>
                                    <span class="chip {{ $stageChip[$r['stage']] ?? 'chip-flat' }}">{{ $r['stage_label'] }}</span>
                                    @if ($channel === 'unknown')
                                        @if ($r['suggestion'] !== null)
                                            <button type="button" wire:click="setSource({{ $r['id'] }}, '{{ $r['suggestion']['key'] }}')" class="btn h-8 px-2.5 text-[13px]" title="{{ $r['suggestion']['why'] }}" data-marketing-suggestion="{{ $r['suggestion']['key'] }}">{{ $r['suggestion']['label'] }} ✓</button>
                                        @endif
                                        <select x-on:change="if ($event.target.value) $wire.setSource({{ $r['id'] }}, $event.target.value)" class="{{ $selectCls }}" aria-label="Nereden duydu" data-marketing-source-select>
                                            <option value="">Nereden duydu…</option>
                                            @foreach (SalesLead::HEARD_FROM as $key => $label)
                                                <option value="{{ $key }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </section>
    </div>
</div>
