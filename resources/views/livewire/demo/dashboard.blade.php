@php
    $num = fn (float|int $value): string => number_format((float) $value, 0, ',', '.');
    $money = fn (float $value, string $currency): string => match ($currency) {
        'TRY' => '₺'.number_format($value, 0, ',', '.'),
        'EUR' => '€'.number_format($value, 0, ',', '.'),
        'USD' => '$'.number_format($value, 0, ',', '.'),
        'GBP' => '£'.number_format($value, 0, ',', '.'),
        default => number_format($value, 0, ',', '.').' '.$currency,
    };
    // Change against the previous period: [text, tone]. Falling clicks / conversions are a loss (red); spend is information.
    $delta = function (float|int $current, float|int $previous, bool $lossWhenDown = true): ?array {
        if ($previous <= 0) {
            return $current > 0 ? ['yeni', 'neutral'] : null;
        }
        $change = (int) round(100 * ($current - $previous) / $previous);
        $tone = match (true) {
            ! $lossWhenDown || $change === 0 => 'neutral',
            $change > 0 => 'positive',
            default => 'negative',
        };

        return [($change > 0 ? '+' : ($change < 0 ? '−' : '')).abs($change).'%', $tone];
    };
    $deltaText = ['neutral' => 'text-gray-500', 'positive' => 'text-success-600 dark:text-success-400', 'negative' => 'text-error-600 dark:text-error-400'];

    $now = now('Europe/Istanbul')->locale(app()->getLocale());
    $greeting = $now->hour < 12 ? 'Günaydın' : ($now->hour < 18 ? 'İyi günler' : 'İyi akşamlar');
    $firstName = \Illuminate\Support\Str::before(trim((string) (auth()->user()?->name ?? '')).' ', ' ');

    // "Senden beklenenler": only the things that wait on the operator, each with one button.
    $lanes = $approvals['lanes'] ?? [];
    $lossItems = collect($alarms['items'])->where('tone', 'critical');
    $lossBrands = $lossItems->pluck('brand')->filter()->unique()->values();
    $lastSend = $daily['last_at'] !== null ? ($daily['last_at']->isToday() ? 'bugün '.$daily['last_at']->format('H:i') : $daily['last_at']->diffForHumans()) : null;
    $actions = [];
    if ($daily['ready'] > 0) {
        $parts = collect([$daily['content'] > 0 ? $daily['content'].' yazı' : null, $daily['posts'] > 0 ? $daily['posts'].' profil gönderisi' : null, $daily['repair'] > 0 ? $daily['repair'].' site onarımı' : null])->filter()->implode(', ');
        $actions[] = ['key' => 'send', 'icon' => 'send', 'tone' => null, 'title' => $num($daily['ready']).' iş gönderilmeye hazır',
            'line' => $parts.'.'.($lastSend !== null ? ' Son gönderim '.$lastSend.'.' : ''), 'label' => 'Gönder', 'href' => route('operator.repair'), 'primary' => true, 'hook' => null];
    }
    if ($alarms['loss'] > 0) {
        $actions[] = ['key' => 'loss', 'icon' => 'alert', 'tone' => 'critical', 'title' => $alarms['loss'].' hesap para ya da trafik kaybediyor',
            'line' => $lossBrands->take(3)->implode(', ').($lossBrands->count() > 3 ? ' ve '.($lossBrands->count() - 3).' marka daha' : '').'.', 'label' => 'Hesaplara bak', 'href' => '#alarm-verenler', 'primary' => false, 'hook' => 'alarms'];
    } elseif ($alarms['accounts'] > 0) {
        $actions[] = ['key' => 'look', 'icon' => 'alert', 'tone' => 'look', 'title' => $alarms['accounts'].' hesap bir göz atmanı bekliyor',
            'line' => collect($alarms['items'])->pluck('brand')->filter()->unique()->take(3)->implode(', ').'.', 'label' => 'Hesaplara bak', 'href' => '#alarm-verenler', 'primary' => false, 'hook' => 'alarms'];
    }
    // One count everywhere (yakup 2026-10-20): "karar bekliyor" is the menu badge of Onarım masası, its two lanes by name.
    $decide = (int) ($lanes['ready'] ?? 0) + (int) ($lanes['review'] ?? 0);
    if ($decide > 0) {
        $actions[] = ['key' => 'decide', 'icon' => 'check', 'tone' => null, 'title' => $num($decide).' iş karar bekliyor',
            'line' => collect([($lanes['ready'] ?? 0) > 0 ? \App\Services\Repair\RepairDesk::LANES['ready'].': '.$num($lanes['ready']) : null, ($lanes['review'] ?? 0) > 0 ? \App\Services\Repair\RepairDesk::LANES['review'].': '.$num($lanes['review']) : null])->filter()->implode(' · '),
            'label' => 'Onarım masası', 'href' => route('operator.repair'), 'primary' => false, 'hook' => 'approvals'];
    }
    if (($lanes['manual'] ?? 0) > 0) {
        $actions[] = ['key' => 'manual', 'icon' => 'hand', 'tone' => null, 'title' => $num($lanes['manual']).' iş senin elinle yapılacak',
            'line' => '', 'label' => 'Gör', 'href' => route('operator.repair', ['serit' => 'manual']), 'primary' => false, 'hook' => null];
    }
    if (($systemAlerts['critical'] ?? 0) > 0) {
        $actions[] = ['key' => 'system', 'icon' => 'alert', 'tone' => 'look', 'title' => 'Hata merkezi: '.$systemAlerts['critical'].' iş seni bekliyor',
            'line' => trim((string) (preg_split('/(?<=[.!?])\s+/u', (string) ($systemAlerts['top'] ?? ''), 2)[0] ?? '')), 'label' => 'Aç', 'href' => route('operator.settings.system-health'), 'primary' => false, 'hook' => null];
    }
    $icons = [
        'send' => '<path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/>',
        'alert' => '<path d="M12 8v5M12 16.5v.5"/><path d="M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'hand' => '<path d="M8 13V5.5a1.5 1.5 0 0 1 3 0V12M11 11.5v-2a1.5 1.5 0 0 1 3 0V12M14 10.5a1.5 1.5 0 0 1 3 0V12M17 11.5a1.5 1.5 0 0 1 3 0V16a6 6 0 0 1-6 6h-2c-2.2 0-3.6-1-4.8-2.6L4.4 15.5a1.5 1.5 0 0 1 2.4-1.8L8 15"/>',
        'moon' => '<path d="M20 12.5A8 8 0 1 1 11.5 4a6 6 0 0 0 8.5 8.5z"/>',
    ];
    $iconTone = ['critical' => 'border-transparent bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-400', 'look' => 'border-transparent bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400'];

    $clicksDelta = $delta($week['clicks']['current'], $week['clicks']['previous']);
    $leadsDelta = $delta($week['conversions']['current'], $week['conversions']['previous']);
    $spend = $week['spend'][0] ?? null;
    $spendDelta = $spend !== null ? $delta($spend['current'], $spend['previous'], false) : null;
    $otherSpend = collect($week['spend'])->slice(1)->map(fn (array $s): string => $money($s['current'], $s['currency']))->implode(', ');

    // Brand table: brands with a loss first, then a look, then the most organic clicks last week.
    $brandRows = collect($brands)->map(function (array $brand) use ($alarms, $approvals, $delta): array {
        $clicks = $brand['clicks'];
        $last = (int) end($clicks);
        $previous = (int) ($clicks[count($clicks) - 2] ?? 0);

        return $brand + ['last' => $last, 'last_delta' => $delta($last, $previous), 'leads_delta' => $delta($brand['leads'], $brand['leads_previous']),
            'status' => $alarms['by_brand'][$brand['id']] ?? null, 'waiting' => (int) ($approvals['by_brand'][$brand['id']] ?? 0)];
    })->sortBy([fn (array $a, array $b): int => (['critical' => 0, 'look' => 1][$a['status']['tone'] ?? ''] ?? 2) <=> (['critical' => 0, 'look' => 1][$b['status']['tone'] ?? ''] ?? 2), fn (array $a, array $b): int => $b['last'] <=> $a['last']])->values();

    // One alarm row per brand and problem (yakup 2026-10-20): Avrupadent's three blocked Meta accounts are one line.
    $alarmGroups = collect($alarms['items'])->groupBy(fn (array $a): string => $a['brand'].'|'.$a['title'])->map(function ($group): array {
        $first = $group->first();
        if ($group->count() > 1) {
            $first['title'] = $group->count().' hesap: '.$first['title'];
            $first['asset'] = $group->pluck('asset')->filter()->implode(', ');
            $first['tone'] = $group->contains('tone', 'critical') ? 'critical' : $first['tone'];
        }

        // First sentence only; the whole text stays in the row's tooltip.
        $first['full'] = trim((string) $first['message'].(($first['others'] ?? []) !== [] ? ' Ayrıca: '.implode(', ', $first['others']).'.' : ''));
        $first['message'] = trim((string) (preg_split('/(?<=[.!?])\s+/u', $first['full'], 2)[0] ?? ''));

        return $first;
    })->values();
    $resultsEmpty = $results['items'] === [] && array_sum(array_map('intval', (array) ($results['counts'] ?? []))) === 0;
@endphp

@php
    // Living panel language (yakup 2026-10-20): the waiting things as tinted cards, the week in a dark number panel,
    // brands as cards. The send card is the widest; a loss card is red, the error centre amber.
    $actionTone = ['send' => 'soft-accent', 'loss' => 'soft-red', 'look' => 'soft-red', 'system' => 'soft-amber'];
    $actionIconTone = ['loss' => 'text-error-600 dark:text-error-400', 'look' => 'text-error-600 dark:text-error-400', 'system' => 'text-warning-600 dark:text-warning-400'];
    $lossLogos = $lossItems->isNotEmpty() ? $lossItems : collect($alarms['items']);
    $lossLogos = $lossLogos->unique('brand')->values();
    // Cards fill full rows on wide screens: the send card takes two thirds next to the first other card, the rest go
    // three to a row and the last row stretches.
    $actionSpans = [];
    $others = array_values(array_filter($actions, fn (array $a): bool => $a['key'] !== 'send'));
    $sendFirst = ($actions[0]['key'] ?? '') === 'send';
    if ($sendFirst) {
        $actionSpans[] = $others === [] ? 'lg:col-span-6' : 'lg:col-span-4';
    }
    $rest = $sendFirst ? array_slice($others, 1) : $others;
    if ($sendFirst && $others !== []) {
        $actionSpans[] = 'lg:col-span-2';
    }
    $restCount = count($rest);
    foreach ($rest as $i => $unused) {
        $inLastRow = $i >= $restCount - ($restCount % 3 ?: 3);
        $actionSpans[] = $inLastRow ? match ($restCount % 3) { 1 => 'lg:col-span-6', 2 => 'lg:col-span-3', default => 'lg:col-span-2' } : 'lg:col-span-2';
    }

    // Week clicks as an area in the dark panel (8 points, last one marked).
    $series = array_values(array_map('floatval', $week['series']['clicks'] ?? []));
    $area = null;
    if (count($series) >= 2) {
        $max = max($series);
        $min = min($series);
        $range = ($max - $min) ?: 1.0;
        $points = [];
        foreach ($series as $i => $value) {
            $points[] = [round($i * 400 / (count($series) - 1), 1), round(14 + (1 - ($value - $min) / $range) * 92, 1)];
        }
        $line = 'M'.implode(' L', array_map(fn (array $p): string => $p[0].','.$p[1], $points));
        $area = ['line' => $line, 'fill' => $line.' L400,120 L0,120 Z', 'last' => end($points)];
    }
    $chip = fn (?array $d): string => match ($d[1] ?? 'neutral') { 'positive' => 'chip-up', 'negative' => 'chip-down', default => 'chip-flat' };
    $arrow = fn (?array $d): string => str_starts_with((string) ($d[0] ?? ''), '+') ? '▲ '.ltrim($d[0], '+') : (str_starts_with((string) ($d[0] ?? ''), '−') ? '▼ '.ltrim($d[0], '−') : (string) ($d[0] ?? ''));
@endphp

<div class="space-y-11">
    @include('livewire.demo.partials.flash')

    <header>
        <h1 class="page-title text-balance">
            {{ $greeting }}{{ $firstName !== '' ? ' '.$firstName : '' }}@if ($actions !== []), bugün <span class="highlight">{{ count($actions) }} şey</span> seni bekliyor @endif
        </h1>
        <p class="page-sub mt-1.5! text-[15px]!">{{ __('operator.dashboard_exec.today') }}, {{ $now->isoFormat('D MMMM dddd') }}. {{ $actions === [] ? 'Bugün senden beklenen bir şey yok.' : 'Senden '.count($actions).' şey bekleniyor, en çok getirecek olan en üstte.' }}</p>
    </header>

    @php
        // A part that could not be read says so instead of showing 0 (panel audit N5); the error is already recorded.
        $unread = array_keys(array_filter(['Onarım masası' => $approvals['failed'] ?? false, 'Alarmlar' => $alarms['failed'] ?? false,
            'Hata merkezi' => $systemAlerts['failed'] ?? false, 'Günlük gönderim' => $daily['failed'] ?? false, 'Haftalık sayılar' => $week['failed'] ?? false]));
    @endphp
    @if ($unread !== [])
        <div class="card border-warning-200 bg-warning-50 px-4 py-3 text-[13px] text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-200" data-today-unread>
            <span class="font-medium">Okunamadı: {{ implode(', ', $unread) }}.</span>
            Bu kutulardaki sayılar şu an bilinmiyor, sıfır değil. Hata kaydedildi; <a href="{{ route('operator.settings.system-health') }}" wire:navigate class="underline">Hata merkezi</a>'nde görünür.
        </div>
    @endif

    {{-- 1. What waits on the operator: one tinted card each, one button each. --}}
    <section data-today-actions>
        @if ($actions === [] && $unread !== [])
            <div class="card">
                <x-ta.empty-state title="Okunamadı" message="Bekleyen işler şu an okunamadı. Sayfayı biraz sonra yenile." />
            </div>
        @elseif ($actions === [])
            <div class="card">
                <x-ta.empty-state title="Sorun yok" message="Harcaması duran hesap, erişilemeyen site ya da biten bütçe yok. Onarım masası boş." data-today-counter="approvals" />
            </div>
        @else
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                @foreach ($actions as $action)
                    @php
                        $isSend = $action['key'] === 'send';
                        $title = preg_replace('/^Hata merkezi: /u', '', $action['title']);
                        if ($isSend) {
                            [$big, $rest] = [$num($daily['ready']).' iş', 'Gönderilmeye hazır, Claude kontrol etti'];
                        } elseif (preg_match('/^([\d.]+) (.*)$/u', $title, $m) === 1) {
                            $unit = str_contains($m[2], 'hesap') ? 'hesap' : 'iş';
                            $big = $m[1].' '.$unit;
                            $rest = $action['key'] === 'system' ? 'Hata merkezi' : \Illuminate\Support\Str::ucfirst(trim(preg_replace('/\b'.$unit.' /u', '', $m[2], 1)));
                        } else {
                            [$big, $rest] = [null, $action['title']];
                        }
                        $span = $actionSpans[$loop->index];
                    @endphp
                    <div @class(['soft-card', $actionTone[$action['key']] ?? '', 'sm:col-span-2' => $isSend, $span])
                        data-today-action="{{ $action['key'] }}" @if ($action['hook']) data-today-counter="{{ $action['hook'] }}" @endif @if ($action['key'] === 'system') data-system-alerts @endif>
                        <div class="flex items-center gap-2.5">
                            <span class="soft-icon {{ $actionIconTone[$action['key']] ?? '' }}" aria-hidden="true"><svg class="h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">{!! $icons[$action['icon']] !!}</svg></span>
                            @if ($isSend)<span class="text-[13px] text-gray-600 dark:text-gray-300">{{ $rest }}</span>@endif
                        </div>
                        @if ($big !== null)
                            <p @class(['soft-number', 'text-[42px]!' => $isSend])>{{ $big }}</p>
                        @endif
                        <p class="sr-only">{{ $action['title'] }}</p>
                        @if (! $isSend)<p class="text-[15px] font-semibold tracking-[-0.01em] text-gray-900 dark:text-white" aria-hidden="true">{{ $rest }}</p>@endif
                        @if ($isSend)
                            <div class="flex flex-wrap items-center gap-1.5 rounded-2xl border border-gray-200 bg-white p-3 shadow-card dark:border-gray-800 dark:bg-white/[0.04]">
                                @if ($daily['content'] > 0)<span class="inline-flex h-7 items-center rounded-lg border border-[#f3e4a1] bg-[#fff8d9] px-2.5 text-xs font-medium text-[#7a5b00] dark:border-transparent dark:bg-[#fff8d9]/10 dark:text-[#f3d77a]">{{ $num($daily['content']) }} yazı</span>@endif
                                @if ($daily['posts'] > 0)<span class="inline-flex h-7 items-center rounded-lg border border-[#f6cfe3] bg-[#fdeef6] px-2.5 text-xs font-medium text-[#a8306e] dark:border-transparent dark:bg-[#fdeef6]/10 dark:text-[#f19ac5]">{{ $num($daily['posts']) }} profil gönderisi</span>@endif
                                @if ($daily['repair'] > 0)<span class="inline-flex h-7 items-center rounded-lg border border-second/25 bg-second-soft px-2.5 text-xs font-medium text-second">{{ $num($daily['repair']) }} site onarımı</span>@endif
                                <span class="text-gray-400" aria-hidden="true">→</span>
                                <span class="inline-flex h-7 items-center rounded-lg border border-success-100 bg-success-50 px-2.5 text-xs font-medium text-success-700 dark:border-transparent dark:bg-success-500/15 dark:text-success-400">✓ Siteye yazılır, geri alınabilir</span>
                            </div>
                            @if ($lastSend !== null)<p class="text-[13px] text-gray-600 dark:text-gray-300">Son gönderim {{ $lastSend }}.</p>@endif
                        @elseif (in_array($action['key'], ['loss', 'look'], true) && $lossLogos->isNotEmpty())
                            <div class="flex items-center">
                                @foreach ($lossLogos->take(5) as $item)
                                    <span class="-ml-2 first:ml-0 inline-flex h-[30px] w-[30px] items-center justify-center overflow-hidden rounded-full border-2 border-white bg-white shadow-card dark:border-gray-900" title="{{ $item['brand'] }}">
                                        <x-ta.brand-avatar :name="$item['brand']" :logo="$item['logo'] ?? null" size="sm" class="border-0!" />
                                    </span>
                                @endforeach
                                @if ($lossLogos->count() > 5)<span class="ml-2 text-xs text-gray-600 dark:text-gray-300">+{{ $lossLogos->count() - 5 }}</span>@endif
                            </div>
                        @elseif ($action['line'] !== '' && $action['line'] !== '.')
                            <p class="text-[13px] text-gray-600 dark:text-gray-300">{{ $action['line'] }}</p>
                        @endif
                        <div class="mt-auto flex items-center gap-2 pt-1">
                            <a href="{{ $action['href'] }}" @if (! str_starts_with($action['href'], '#')) wire:navigate @endif @class(['btn-primary h-10! px-4! text-sm!' => $action['primary'], 'btn-dark' => ! $action['primary'] && $action['key'] === 'decide', 'btn' => ! $action['primary'] && $action['key'] !== 'decide'])>{{ $action['label'] }}</a>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <livewire:operator.portfolio.epicrisis-questions lazy />

    {{-- 2. This week in a dark number panel. --}}
    <section data-today-counters>
        <div class="section-head">
            <h2 class="section-title">Bu hafta</h2>
            <span class="text-sm text-gray-500">{{ $week['from'] }}–{{ $week['to'] }}, önceki 7 güne göre</span>
        </div>
        <div class="hero-panel grid-cols-2 lg:grid-cols-[1.5fr_1fr_1fr]">
            <div class="hero-tile col-span-2 lg:col-span-1" data-today-counter="week">
                <span class="hero-label"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 17l6-6 4 4 8-8"/></svg>Organik tık</span>
                <p class="hero-value text-[40px]! sm:text-[44px]!">{{ $num($week['clicks']['current']) }}</p>
                <p class="mt-2 flex items-center gap-2 text-xs text-white/60">@if ($clicksDelta)<span class="chip {{ $chip($clicksDelta) }}">{{ $arrow($clicksDelta) }}</span>@endif Geçen hafta {{ $num($week['clicks']['previous']) }}</p>
                @if ($area !== null)
                    <svg class="mt-auto block h-[110px] w-full pt-4" viewBox="0 0 400 120" preserveAspectRatio="none" aria-hidden="true">
                        <defs><linearGradient id="today-week-area" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="var(--color-second)" stop-opacity=".7"/><stop offset="1" stop-color="var(--color-second)" stop-opacity="0"/></linearGradient></defs>
                        <path d="{{ $area['fill'] }}" fill="url(#today-week-area)" />
                        <path d="{{ $area['line'] }}" fill="none" stroke="color-mix(in srgb, var(--color-second) 45%, #ffffff)" stroke-width="2.5" vector-effect="non-scaling-stroke" />
                        <circle cx="{{ $area['last'][0] }}" cy="{{ $area['last'][1] }}" r="4.5" fill="#fff" />
                    </svg>
                @endif
            </div>
            <div class="hero-tile" title="Google Ads dönüşümleri" data-today-counter="leads">
                <span class="hero-label">Reklamdan müşteri</span>
                <p class="hero-value">{{ $num($week['conversions']['current']) }}</p>
                <p class="mt-2 flex flex-wrap items-center gap-2 text-xs text-white/60">@if ($leadsDelta)<span class="chip {{ $chip($leadsDelta) }}">{{ $arrow($leadsDelta) }}</span>@endif Geçen hafta {{ $num($week['conversions']['previous']) }}</p>
            </div>
            <div class="hero-tile" title="Google Ads ve Meta" data-today-counter="spend">
                <span class="hero-label">Reklam harcaması</span>
                <p class="hero-value">{{ $spend !== null ? $money($spend['current'], $spend['currency']) : '—' }}</p>
                <p class="mt-2 flex flex-wrap items-center gap-2 text-xs text-white/60">@if ($spendDelta)<span class="chip chip-flat bg-white/10! text-white/80!">{{ $spendDelta[0] }}</span>@endif {{ $spend !== null ? 'Geçen hafta '.$money($spend['previous'], $spend['currency']).($otherSpend !== '' ? ', ayrıca '.$otherSpend : '') : 'Harcama yok' }}</p>
            </div>
        </div>
    </section>

    {{-- 3. One card per brand: clicks, change, 8 weeks, status. --}}
    @if ($brandRows->isNotEmpty())
        <section data-today-brands>
            <div class="section-head">
                <h2 class="section-title">Markalar <span class="section-count">{{ $brandRows->count() }}</span></h2>
                <span class="text-sm text-gray-500">8 haftalık organik tık, sorunlu olan önde</span>
                <a href="{{ route('operator.brands') }}" wire:navigate class="ml-auto text-[13px] text-gray-500 hover:text-gray-900 dark:hover:text-white">Tümü</a>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 wide:grid-cols-4" data-portfolio-today>
                @foreach ($brandRows as $row)
                    @php
                        $status = $row['status'];
                        $previousWeek = (int) ($row['clicks'][count($row['clicks']) - 2] ?? 0);
                        $falling = $previousWeek >= 20 && ($row['last'] - $previousWeek) / $previousWeek <= -0.3;
                        $pill = $status !== null ? [$status['tone'], \Illuminate\Support\Str::limit($status['title'], 40)] : ($falling ? ['look', 'Tık düşüyor'] : ['ok', 'Yolunda']);
                        $down = ($row['last_delta'][1] ?? 'neutral') === 'negative';
                    @endphp
                    <div wire:key="today-{{ $row['id'] }}" data-today-brand="{{ $row['id'] }}" class="card lift flex flex-col gap-3 p-4">
                        <a href="{{ route('operator.brand', ['brand' => $row['id']]) }}" wire:navigate class="flex min-w-0 items-center gap-2.5">
                            <x-ta.brand-avatar :name="$row['name']" :logo="$row['logo']" size="lg" />
                            <span class="min-w-0">
                                <span class="block truncate font-semibold text-gray-900 dark:text-white">{{ $row['name'] }}</span>
                                <span class="block text-xs text-gray-500">{{ ($row['waiting'] > 0 ? $num($row['waiting']).' iş bekliyor' : 'Bekleyen iş yok').($row['leads'] > 0 || $row['leads_previous'] > 0 ? ', '.$num($row['leads']).' reklam müşterisi' : '') }}</span>
                            </span>
                        </a>
                        <div class="flex items-end gap-2.5">
                            <div>
                                <p class="text-2xl leading-none font-semibold tracking-[-0.02em] tabular-nums text-gray-900 dark:text-white">{{ $num($row['last']) }}</p>
                                <p class="mt-1 text-xs text-gray-500">tık, geçen hafta</p>
                            </div>
                            @if ($row['last_delta'])<span class="chip {{ $chip($row['last_delta']) }} mb-0.5">{{ $arrow($row['last_delta']) }}</span>@endif
                            <x-ta.sparkline class="ml-auto" :values="$row['clicks']" :tone="$down ? 'down' : 'second'" :width="110" :height="36" />
                        </div>
                        <div class="flex min-w-0 items-center gap-2 border-t border-dashed border-gray-200 pt-2.5 dark:border-gray-800">
                            <x-ta.pill :tone="$pill[0]" :title="$status['title'] ?? null" class="min-w-0 truncate">{{ $pill[1] }}</x-ta.pill>
                            @if ($row['waiting'] > 0)
                                <a href="{{ route('operator.repair', ['marka' => $row['id']]) }}" wire:navigate class="ml-auto shrink-0 text-[13px] font-medium text-gray-700 hover:underline dark:text-gray-300">Onarım masası →</a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- 4. The accounts behind the alarms. --}}
    @if ($alarmGroups->isNotEmpty())
        <section id="alarm-verenler" class="scroll-mt-20" data-today-alarms>
            <div class="section-head">
                <h2 class="section-title">Alarm verenler <span class="section-count">{{ $alarmGroups->count() }}</span></h2>
                <a href="{{ route('operator.repair', ['bolum' => 'diger']) }}" wire:navigate class="ml-auto text-[13px] text-gray-500 hover:text-gray-900 dark:hover:text-white">Tümü</a>
            </div>
            <ul class="card">
                @foreach ($alarmGroups as $alarm)
                    <x-ta.alarm-row wire:key="today-alarm-{{ $alarm['id'] }}" :tone="$alarm['tone']" :title="$alarm['title']" :brand="$alarm['brand']" :logo="$alarm['logo'] ?? null"
                        :meta="$alarm['asset'] !== '' && $alarm['asset'] !== $alarm['brand'] ? $alarm['asset'] : null"
                        :message="$alarm['message'] !== '' ? \Illuminate\Support\Str::limit($alarm['message'], 140) : null" :href="$alarm['url']" :hint="$alarm['full']" data-today-alarm="{{ $alarm['tone'] }}" />
                @endforeach
            </ul>
            @if ($alarms['more'] > 0)
                <p class="mt-2 text-[13px] text-gray-500">{{ $alarms['more'] }} alarm daha</p>
            @endif
        </section>
    @endif

    {{-- 5. Last night. --}}
    <section data-today-overnight>
        <div class="section-head"><h2 class="section-title">Dün gece</h2></div>
        <div class="card">
            @if ($overnight['writes'] === [])
                <div class="card-row">
                    <span class="row-icon" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! $icons['moon'] !!}</svg></span>
                    <p class="text-sm text-gray-500">Son 24 saatte siteye ya da hesaplara yazılan bir şey yok.</p>
                </div>
            @else
                @php
                    $written = collect($overnight['writes']);
                    $failed = (int) $written->sum('failed');
                @endphp
                <details class="group">
                    <summary class="card-row cursor-pointer list-none hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                        <span class="row-icon" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! $icons['moon'] !!}</svg></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ $written->count() }} onaylı yazma yapıldı{{ $failed > 0 ? ', '.$failed.' iş yazılamadı' : '' }}</span>
                        </span>
                        <span class="btn-quiet"><span class="group-open:hidden">Ayrıntı</span><span class="hidden group-open:inline">Kapat</span></span>
                    </summary>
                    <ul class="border-t border-gray-200 px-4 py-1 text-sm dark:border-gray-800">
                        @foreach ($overnight['writes'] as $write)
                            <li class="flex flex-wrap items-baseline gap-x-2 border-b border-gray-100 py-2 last:border-b-0 dark:border-gray-800" data-today-write>
                                <span class="w-10 shrink-0 text-xs tabular-nums text-gray-400">{{ $write['at'] }}</span>
                                <span class="font-medium text-gray-900 dark:text-white">{{ $write['asset'] !== '' ? $write['asset'] : $write['label'] }}</span>
                                <span class="text-gray-600 dark:text-gray-300">{{ $write['label'] }}, {{ $write['sentence'] }}</span>
                                @if ($write['failed'] > 0)<x-ta.pill tone="look">{{ $write['failed'] }} yazılamadı</x-ta.pill>@endif
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </div>
    </section>

    {{-- 6. Şef's plan for the week. --}}
    <section data-chief-plan @if ($chiefPlan === null || $chiefPlan->status === 'failed') data-chief-plan-empty @endif>
        <div class="section-head">
            <h2 class="section-title">Bu haftanın planı</h2>
            @if ($chiefPlan !== null && $chiefPlan->status !== 'failed')
                <span class="text-sm text-gray-500" title="Şef her pazartesi bakım ajanlarının notlarından hazırlar">{{ $chiefPlan->week_start->format('d.m.Y') }} haftası</span>
            @endif
            <button type="button" wire:click="refreshPlan" wire:loading.attr="disabled" class="ml-auto text-[13px] text-gray-500 hover:text-gray-900 dark:hover:text-white">{{ $chiefPlan === null || $chiefPlan->status === 'failed' ? 'Şimdi hazırla' : 'Planı yenile' }}</button>
        </div>
        <div class="card">
            @if ($chiefPlan === null)
                <p class="px-4 py-3.5 text-sm text-gray-500">Henüz plan yok; Şef her pazartesi sabahı hazırlar.</p>
            @elseif ($chiefPlan->status === 'failed')
                <p class="px-4 py-3.5 text-sm text-warning-700 dark:text-warning-400">Plan hazırlanamadı: {{ \Illuminate\Support\Str::limit((string) $chiefPlan->error, 120) }}</p>
            @else
                @if ($chiefPlan->headline)
                    <p class="border-b border-gray-200 bg-gray-50/60 px-4 py-3 text-sm text-gray-700 dark:border-gray-800 dark:bg-white/[0.02] dark:text-gray-300">{{ $chiefPlan->headline }}</p>
                @endif
                @php($planLines = array_values((array) $chiefPlan->plan))
                {{-- Three lines first; the rest one click away. --}}
                <ol x-data="{ more: false }">
                    @foreach ($planLines as $line)
                        <li class="card-row items-start" @if ($loop->index >= 3) x-show="more" x-cloak data-plan-extra @endif>
                            <span class="mt-1 w-4 shrink-0 text-right text-xs tabular-nums text-gray-400">{{ $loop->iteration }}</span>
                            <x-ta.brand-avatar :name="$line['brand']" />
                            <div class="min-w-0 flex-1 text-sm">
                                @if ($line['brand_id'] > 0)
                                    <a href="{{ route('operator.brand', ['brand' => $line['brand_id'], 'tab' => 'dosya']) }}" wire:navigate class="font-medium text-gray-900 hover:underline dark:text-white">{{ $line['brand'] }}</a>
                                    <span class="text-gray-700 dark:text-gray-300">{{ $line['task'] }}</span>
                                @else
                                    {{-- A system line names its place in the task already ("Hata merkezinde …"); the task is the link. --}}
                                    <a href="{{ route('operator.settings.system-health') }}" wire:navigate class="text-gray-700 hover:underline dark:text-gray-300" title="{{ $line['brand'] }}">{{ $line['task'] }}</a>
                                @endif
                                @if ($line['why'] !== '')<span class="mt-0.5 block text-[13px] text-gray-500">{{ $line['why'] }}</span>@endif
                            </div>
                        </li>
                    @endforeach
                    @if (count($planLines) > 3)
                        <li class="card-row" data-plan-more>
                            <button type="button" x-on:click="more = ! more" class="text-[13px] text-gray-500 hover:text-gray-900 dark:hover:text-white" x-text="more ? 'Kapat' : '{{ count($planLines) - 3 }} madde daha'">{{ count($planLines) - 3 }} madde daha</button>
                        </li>
                    @endif
                </ol>
            @endif
        </div>
    </section>

    {{-- 7. Did the applied suggestions work? Hidden until the first measurement. --}}
    @unless ($resultsEmpty)
    <section data-today-results>
        <div class="section-head">
            <h2 class="section-title">Sonuçlar</h2>
            <span class="text-sm text-gray-500">son 90 gün, uygulanan öneriler 30. ve 90. günde ölçülür</span>
        </div>
        <div class="metric-strip grid-cols-2 lg:grid-cols-4">
            <x-ta.metric-card label="İşe yaradı" :value="$results['counts']['worked'] ?? 0" tone="positive" />
            <x-ta.metric-card label="Değişmedi" :value="$results['counts']['neutral'] ?? 0" />
            <x-ta.metric-card label="Kötüleşti" :value="$results['counts']['not_worked'] ?? 0" />
            <x-ta.metric-card label="Ölçülemedi" :value="$results['counts']['unclear'] ?? 0" />
        </div>
        @if ($results['items'] === [])
            <p class="mt-3 text-[13px] text-gray-500">Henüz ölçülen öneri yok; uygulanan öneriler 30. ve 90. günde, önceki aynı uzunluktaki dönemle karşılaştırılır.</p>
        @else
            <ul class="card mt-3">
                @foreach ($results['items'] as $item)
                    <li class="card-row flex-wrap text-sm">
                        <x-ta.brand-avatar :name="$item['brand']" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-gray-900 dark:text-white"><span class="font-medium">{{ $item['brand'] }}</span> {{ $item['title'] }}</span>
                            <span class="mt-0.5 block text-[13px] text-gray-500" title="{{ $item['reason'] }}">{{ $item['channel'] }}, {{ $item['point'] }}. {{ $item['line'] ?? $item['reason'] }}</span>
                        </span>
                        @include('livewire.demo.partials.outcome-badge', ['verdict' => $item['verdict']])
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
    @endunless

</div>
