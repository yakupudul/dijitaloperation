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
    $kindsOf = fn (string $lane): string => collect($approvals['packages'])->where('lane', $lane)->pluck('kind_label')->unique()->take(3)->implode(', ');
    if (($lanes['review'] ?? 0) > 0) {
        $actions[] = ['key' => 'review', 'icon' => 'clock', 'tone' => 'look', 'title' => $num($lanes['review']).' iş bir göz atmanı bekliyor',
            'line' => ($kindsOf('review') !== '' ? $kindsOf('review').'. ' : '').'Her biri açıklamalı, onaylayınca gider.', 'label' => 'Bak', 'href' => route('operator.repair', ['serit' => 'review']), 'primary' => false, 'hook' => 'approvals'];
    }
    if (($lanes['ready'] ?? 0) > 0) {
        $actions[] = ['key' => 'ready', 'icon' => 'check', 'tone' => null, 'title' => $num($lanes['ready']).' düşük riskli iş onay bekliyor',
            'line' => ($kindsOf('ready') !== '' ? $kindsOf('ready').'. ' : '').'Bir paketi tek tıkla onaylarsın.', 'label' => 'Onayla', 'href' => route('operator.repair', ['serit' => 'ready']), 'primary' => false, 'hook' => ($lanes['review'] ?? 0) > 0 ? null : 'approvals'];
    }
    if (($lanes['manual'] ?? 0) > 0) {
        $actions[] = ['key' => 'manual', 'icon' => 'hand', 'tone' => null, 'title' => $num($lanes['manual']).' iş senin elini bekliyor',
            'line' => 'Sitede ya da hesapta elle yapılacaklar.', 'label' => 'Gör', 'href' => route('operator.repair', ['serit' => 'manual']), 'primary' => false, 'hook' => null];
    }
    if (($systemAlerts['critical'] ?? 0) > 0) {
        $actions[] = ['key' => 'system', 'icon' => 'alert', 'tone' => 'look', 'title' => 'Hata merkezi: '.$systemAlerts['critical'].' iş seni bekliyor',
            'line' => (string) ($systemAlerts['top'] ?? ''), 'label' => 'Aç', 'href' => route('operator.settings.system-health'), 'primary' => false, 'hook' => null];
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

        return $first;
    })->values();
@endphp

<div class="space-y-10">
    @include('livewire.demo.partials.flash')

    <header>
        <h1 class="page-title">{{ $greeting }}{{ $firstName !== '' ? ' '.$firstName : '' }}</h1>
        <p class="page-sub">{{ __('operator.dashboard_exec.today') }}, {{ $now->isoFormat('D MMMM dddd') }}. {{ $actions === [] ? 'Bugün senden beklenen bir şey yok.' : 'Senden '.count($actions).' şey bekleniyor.' }}</p>
    </header>

    {{-- 1. What waits on the operator: one row each, one button each. --}}
    <section class="-mt-4" data-today-actions>
        <div class="card">
            @forelse ($actions as $action)
                <div class="card-row flex-wrap sm:flex-nowrap" data-today-action="{{ $action['key'] }}" @if ($action['hook']) data-today-counter="{{ $action['hook'] }}" @endif @if ($action['key'] === 'system') data-system-alerts @endif>
                    <span class="row-icon {{ $iconTone[$action['tone']] ?? '' }}" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! $icons[$action['icon']] !!}</svg></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $action['title'] }}</p>
                        @if ($action['line'] !== '' && $action['line'] !== '.')<p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $action['line'] }}</p>@endif
                    </div>
                    <a href="{{ $action['href'] }}" @if (! str_starts_with($action['href'], '#')) wire:navigate @endif class="{{ $action['primary'] ? 'btn-primary' : 'btn' }} w-full sm:w-auto">{{ $action['label'] }}</a>
                </div>
            @empty
                <x-ta.empty-state title="Sorun yok" message="Harcaması duran hesap, erişilemeyen site ya da biten bütçe yok. Onarım masası boş." data-today-counter="approvals" />
            @endforelse
        </div>
    </section>

    <livewire:operator.portfolio.epicrisis-questions lazy />

    {{-- 2. This week in four numbers. --}}
    <section data-today-counters>
        <div class="section-head">
            <h2 class="section-title">Bu hafta</h2>
            <span class="text-sm text-gray-500">{{ $week['from'] }}–{{ $week['to'] }}, önceki 7 güne göre</span>
        </div>
        <div class="metric-strip grid-cols-2 lg:grid-cols-4">
            <x-ta.metric-card label="Organik tık" :value="$num($week['clicks']['current'])" :delta="$clicksDelta[0] ?? null" :tone="$clicksDelta[1] ?? 'neutral'"
                :hint="'Geçen hafta '.$num($week['clicks']['previous'])" :spark="$week['series']['clicks'] ?? []" data-today-counter="week" />
            <x-ta.metric-card label="Reklamdan müşteri" :value="$num($week['conversions']['current'])" :delta="$leadsDelta[0] ?? null" :tone="$leadsDelta[1] ?? 'neutral'"
                :hint="'Geçen hafta '.$num($week['conversions']['previous'])" :spark="$week['series']['conversions'] ?? []" title="Google Ads dönüşümleri" data-today-counter="leads" />
            <x-ta.metric-card label="Reklam harcaması" :value="$spend !== null ? $money($spend['current'], $spend['currency']) : '—'" :delta="$spendDelta[0] ?? null"
                :hint="$spend !== null ? 'Geçen hafta '.$money($spend['previous'], $spend['currency']).($otherSpend !== '' ? ', ayrıca '.$otherSpend : '') : 'Harcama yok'" title="Google Ads ve Meta" data-today-counter="spend" />
            <x-ta.metric-card :href="route('operator.ai-jobs', ['durum' => 'done'])" label="Claude'un bitirdiği iş" :value="$num($overnight['ai_done'])" hint="Son 24 saat" data-today-counter="ai" />
        </div>
    </section>

    {{-- 3. One line per brand. --}}
    @if ($brandRows->isNotEmpty())
        <section data-today-brands>
            <div class="section-head">
                <h2 class="section-title">Markalar <span class="section-count">{{ $brandRows->count() }}</span></h2>
                <a href="{{ route('operator.brands') }}" wire:navigate class="ml-auto text-[13px] text-gray-500 hover:text-gray-900 dark:hover:text-white">Tümü</a>
            </div>
            <div class="card overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Marka</th>
                            <th class="hidden md:table-cell">Organik tık, 8 hafta</th>
                            <th class="hidden text-right! md:table-cell" title="Google Ads dönüşümü, son 7 gün">Reklamdan müşteri</th>
                            <th class="hidden text-right! md:table-cell" title="Onarım masasında bekleyen iş">Bekleyen</th>
                            <th class="hidden md:table-cell">Durum</th>
                        </tr>
                    </thead>
                    <tbody data-portfolio-today>
                        @foreach ($brandRows as $row)
                            @php
                                $status = $row['status'];
                                $pill = $status === null ? ['ok', 'Yolunda'] : [$status['tone'], \Illuminate\Support\Str::limit($status['title'], 34)];
                            @endphp
                            <tr wire:key="today-{{ $row['id'] }}" data-today-brand="{{ $row['id'] }}">
                                <td class="max-w-0 w-[38%]">
                                    <a href="{{ route('operator.brand', ['brand' => $row['id']]) }}" wire:navigate class="flex min-w-0 items-center gap-2.5">
                                        <x-ta.brand-avatar :name="$row['name']" :logo="$row['logo']" />
                                        <span class="min-w-0">
                                            <span class="block truncate font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</span>
                                            <x-ta.pill :tone="$pill[0]" class="mt-0.5 md:hidden!">{{ $pill[1] }}</x-ta.pill>
                                        </span>
                                    </a>
                                </td>
                                <td class="hidden md:table-cell">
                                    <span class="flex items-center gap-2.5">
                                        <x-ta.sparkline :values="$row['clicks']" :tone="($row['last_delta'][1] ?? 'neutral') === 'negative' ? 'down' : (($row['last_delta'][1] ?? '') === 'positive' ? 'up' : 'neutral')" :width="72" :height="22" :fill="false" />
                                        <span class="tabular-nums text-gray-900 dark:text-white">{{ $num($row['last']) }}</span>
                                        @if ($row['last_delta'])<span class="text-[13px] font-medium tabular-nums {{ $deltaText[$row['last_delta'][1]] }}">{{ $row['last_delta'][0] }}</span>@endif
                                    </span>
                                </td>
                                <td class="hidden text-right tabular-nums md:table-cell">{{ $row['leads'] > 0 || $row['leads_previous'] > 0 ? $num($row['leads']) : '—' }}</td>
                                <td class="hidden text-right tabular-nums md:table-cell">
                                    @if ($row['waiting'] > 0)
                                        <a href="{{ route('operator.repair', ['marka' => $row['id']]) }}" wire:navigate class="hover:underline">{{ $num($row['waiting']) }}</a>
                                    @else
                                        <span class="text-gray-400">0</span>
                                    @endif
                                </td>
                                <td class="hidden md:table-cell"><x-ta.pill :tone="$pill[0]" :title="$status['title'] ?? null">{{ $pill[1] }}</x-ta.pill></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- 4. The accounts behind the alarms. --}}
    @if ($alarmGroups->isNotEmpty())
        <section id="alarm-verenler" class="scroll-mt-20" data-today-alarms>
            <div class="section-head">
                <h2 class="section-title">Alarm verenler <span class="section-count">{{ $alarms['accounts'] }}</span></h2>
                <a href="{{ route('operator.repair', ['bolum' => 'diger']) }}" wire:navigate class="ml-auto text-[13px] text-gray-500 hover:text-gray-900 dark:hover:text-white">Tümü</a>
            </div>
            <ul class="card">
                @foreach ($alarmGroups as $alarm)
                    <x-ta.alarm-row wire:key="today-alarm-{{ $alarm['id'] }}" :tone="$alarm['tone']" :title="$alarm['title']" :brand="$alarm['brand']" :logo="$alarm['logo'] ?? null"
                        :meta="$alarm['asset'] !== '' && $alarm['asset'] !== $alarm['brand'] ? $alarm['asset'] : null"
                        :message="$alarm['message'] !== '' ? \Illuminate\Support\Str::limit($alarm['message'], 140) : null" :href="$alarm['url']" data-today-alarm="{{ $alarm['tone'] }}" />
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
            @if ($overnight['writes'] === [] && $overnight['ai'] === [])
                <div class="card-row">
                    <span class="row-icon" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! $icons['moon'] !!}</svg></span>
                    <p class="text-sm text-gray-500">Son 24 saatte onaylı yazma ya da biten AI işi yok.</p>
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
                            <span class="block text-sm font-medium text-gray-900 dark:text-white">Claude {{ $num($overnight['ai_done']) }} iş bitirdi</span>
                            <span class="mt-0.5 block text-[13px] text-gray-500">{{ $written->count() > 0 ? $written->count().' onaylı yazma yapıldı' : 'Onaylı yazma yok' }}{{ $failed > 0 ? ', '.$failed.' iş yazılamadı' : '' }}.</span>
                        </span>
                        <span class="btn-quiet"><span class="group-open:hidden">Ayrıntı</span><span class="hidden group-open:inline">Kapat</span></span>
                    </summary>
                    <div class="grid gap-px border-t border-gray-200 bg-gray-200 md:grid-cols-2 dark:border-gray-800 dark:bg-gray-800">
                        <div class="bg-white px-4 py-3 dark:bg-gray-900">
                            <h3 class="text-[13px] text-gray-500">Onaylanıp yazılanlar</h3>
                            <ul class="mt-1 text-sm">
                                @forelse ($overnight['writes'] as $write)
                                    <li class="flex flex-wrap items-baseline gap-x-2 border-b border-gray-100 py-2 last:border-b-0 dark:border-gray-800" data-today-write>
                                        <span class="w-10 shrink-0 text-xs tabular-nums text-gray-400">{{ $write['at'] }}</span>
                                        <span class="font-medium text-gray-900 dark:text-white">{{ $write['asset'] !== '' ? $write['asset'] : $write['label'] }}</span>
                                        <span class="text-gray-600 dark:text-gray-300">{{ $write['label'] }}, {{ $write['sentence'] }}</span>
                                        @if ($write['failed'] > 0)<x-ta.pill tone="look">{{ $write['failed'] }} yazılamadı</x-ta.pill>@endif
                                    </li>
                                @empty
                                    <li class="py-2 text-gray-500">Yok.</li>
                                @endforelse
                            </ul>
                        </div>
                        <div class="bg-white px-4 py-3 dark:bg-gray-900">
                            <h3 class="text-[13px] text-gray-500">Claude'un bitirdiği AI işleri</h3>
                            <ul class="mt-1 text-sm">
                                @forelse ($overnight['ai'] as $job)
                                    <li class="flex items-baseline justify-between gap-2 border-b border-gray-100 py-2 last:border-b-0 dark:border-gray-800" data-today-ai>
                                        <span class="min-w-0 truncate text-gray-700 dark:text-gray-300">{{ $job['label'] }}</span>
                                        <span class="shrink-0 font-medium tabular-nums text-gray-900 dark:text-white">{{ $num($job['count']) }}</span>
                                    </li>
                                @empty
                                    <li class="py-2 text-gray-500">Yok.</li>
                                @endforelse
                            </ul>
                        </div>
                    </div>
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
                <ol>
                    @foreach ((array) $chiefPlan->plan as $line)
                        <li class="card-row items-start">
                            <span class="mt-1 w-4 shrink-0 text-right text-xs tabular-nums text-gray-400">{{ $loop->iteration }}</span>
                            <x-ta.brand-avatar :name="$line['brand']" />
                            <div class="min-w-0 flex-1 text-sm">
                                @if ($line['brand_id'] > 0)
                                    <a href="{{ route('operator.brand', ['brand' => $line['brand_id'], 'tab' => 'dosya']) }}" wire:navigate class="font-medium text-gray-900 hover:underline dark:text-white">{{ $line['brand'] }}</a>
                                @else
                                    <a href="{{ route('operator.settings.system-health') }}" wire:navigate class="font-medium text-gray-900 hover:underline dark:text-white">{{ $line['brand'] }}</a>
                                @endif
                                <span class="text-gray-700 dark:text-gray-300">{{ $line['task'] }}</span>
                                @if ($line['why'] !== '')<span class="mt-0.5 block text-[13px] text-gray-500">{{ $line['why'] }}</span>@endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </section>

    {{-- 7. Did the applied suggestions work? --}}
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

    <livewire:operator.live-feed />
</div>
