@php
    $num = fn (float|int $value): string => number_format((float) $value, 0, ',', '.');
    $money = fn (float $value, string $currency): string => match ($currency) {
        'TRY' => '₺'.number_format($value, 0, ',', '.'),
        'EUR' => '€'.number_format($value, 0, ',', '.'),
        'USD' => '$'.number_format($value, 0, ',', '.'),
        'GBP' => '£'.number_format($value, 0, ',', '.'),
        default => number_format($value, 0, ',', '.').' '.$currency,
    };
    // Change against the previous 7 days: [text, tone]. Falling clicks / conversions are a loss (red); spend is information.
    $delta = function (float|int $current, float|int $previous, bool $lossWhenDown = true): ?array {
        if ($previous <= 0) {
            return $current > 0 ? ['yeni', 'text-gray-500 dark:text-gray-400'] : null;
        }
        $change = (int) round(100 * ($current - $previous) / $previous);
        $tone = match (true) {
            ! $lossWhenDown || $change === 0 => 'text-gray-500 dark:text-gray-400',
            $change > 0 => 'text-success-700 dark:text-success-400',
            default => 'text-error-700 dark:text-error-400',
        };

        return [($change > 0 ? '+' : '').$change.'%', $tone];
    };
    $card = 'flex flex-col gap-1 rounded-xl bg-white px-4 py-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $label = 'text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400';
@endphp

<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" data-today-counters>
    <a href="{{ route('operator.repair') }}" wire:navigate class="{{ $card }} hover:ring-gray-300 dark:hover:ring-gray-700" data-today-counter="approvals">
        <span class="{{ $label }}">Onay bekleyen</span>
        <span class="text-2xl font-bold text-gray-900 dark:text-white">{{ $num($approvals['total']) }}</span>
        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $approvals['total'] > 0 ? 'paket · '.$num($approvals['rows'] ?? 0).' iş · hepsini gör →' : 'Onarım masası boş' }}</span>
    </a>

    <div class="{{ $card }}" data-today-counter="week">
        <span class="{{ $label }}">Bu hafta <span class="normal-case tracking-normal">· {{ $week['from'] }}–{{ $week['to'] }}</span></span>
        <dl class="mt-0.5 space-y-0.5 text-sm">
            @php($clicksDelta = $delta($week['clicks']['current'], $week['clicks']['previous']))
            <div class="flex items-baseline justify-between gap-2">
                <dt class="text-gray-500 dark:text-gray-400">Organik tık</dt>
                <dd class="font-semibold text-gray-900 dark:text-white">{{ $num($week['clicks']['current']) }}
                    @if ($clicksDelta)<span class="ml-1 text-xs font-medium {{ $clicksDelta[1] }}">{{ $clicksDelta[0] }}</span>@endif</dd>
            </div>
            @php($leadsDelta = $delta($week['conversions']['current'], $week['conversions']['previous']))
            <div class="flex items-baseline justify-between gap-2">
                <dt class="text-gray-500 dark:text-gray-400" title="Google Ads dönüşümleri">Müşteri (Ads)</dt>
                <dd class="font-semibold text-gray-900 dark:text-white">{{ $num($week['conversions']['current']) }}
                    @if ($leadsDelta)<span class="ml-1 text-xs font-medium {{ $leadsDelta[1] }}">{{ $leadsDelta[0] }}</span>@endif</dd>
            </div>
            <div class="flex items-baseline justify-between gap-2">
                <dt class="text-gray-500 dark:text-gray-400" title="Google Ads + Meta">Harcama</dt>
                <dd class="text-right font-semibold text-gray-900 dark:text-white">
                    @forelse ($week['spend'] as $spend)
                        @php($spendDelta = $delta($spend['current'], $spend['previous'], false))
                        <span class="block">{{ $money($spend['current'], $spend['currency']) }}@if ($spendDelta)<span class="ml-1 text-xs font-medium {{ $spendDelta[1] }}">{{ $spendDelta[0] }}</span>@endif</span>
                    @empty
                        <span class="text-gray-400">—</span>
                    @endforelse
                </dd>
            </div>
        </dl>
        <span class="text-[11px] text-gray-400">önceki 7 güne göre</span>
    </div>

    <a href="#alarm-verenler" class="{{ $card }} hover:ring-gray-300 dark:hover:ring-gray-700" data-today-counter="alarms">
        <span class="{{ $label }}">Alarm veren hesaplar</span>
        <span @class(['text-2xl font-bold', 'text-error-700 dark:text-error-400' => $alarms['loss'] > 0, 'text-warning-700 dark:text-warning-400' => $alarms['loss'] === 0 && $alarms['accounts'] > 0, 'text-success-700 dark:text-success-400' => $alarms['accounts'] === 0])>{{ $num($alarms['accounts']) }}</span>
        <span class="text-xs text-gray-500 dark:text-gray-400">
            @if ($alarms['accounts'] === 0) Sorun yok
            @elseif ($alarms['loss'] > 0) {{ $alarms['loss'] }} hesap para / trafik kaybediyor
            @else Bakılması gerekenler var
            @endif
        </span>
    </a>

    <a href="{{ route('operator.ai-jobs', ['durum' => 'done']) }}" wire:navigate class="{{ $card }} hover:ring-gray-300 dark:hover:ring-gray-700" data-today-counter="ai">
        <span class="{{ $label }}">Claude dün gece</span>
        <span class="text-2xl font-bold text-gray-900 dark:text-white">{{ $num($overnight['ai_done']) }}</span>
        <span class="text-xs text-gray-500 dark:text-gray-400">AI işi bitti · son 24 saat</span>
    </a>
</div>

<div class="grid gap-4 lg:grid-cols-2">
    <section id="alarm-verenler" class="rounded-xl bg-white px-4 py-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-today-alarms>
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Alarm verenler</h2>
            @if ($alarms['items'] !== [])
                <a href="{{ route('operator.repair', ['bolum' => 'diger']) }}" wire:navigate class="link-neutral text-xs">Hepsini gör →</a>
            @endif
        </div>
        @if ($alarms['items'] === [])
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Harcaması duran hesap, erişilemeyen site ya da biten bütçe yok.</p>
        @else
            <ul class="mt-2 space-y-1.5">
                @foreach ($alarms['items'] as $alarm)
                    <li wire:key="today-alarm-{{ $alarm['id'] }}" data-today-alarm="{{ $alarm['tone'] }}" @class(['rounded-lg px-3 py-2 text-sm', 'tone-critical' => $alarm['tone'] === 'critical', 'tone-look' => $alarm['tone'] !== 'critical'])>
                        <div class="flex flex-wrap items-baseline gap-x-2">
                            <span class="font-semibold">{{ $alarm['title'] }}</span>
                            <span class="text-xs opacity-80">{{ $alarm['brand'] }}@if ($alarm['asset'] !== '') · {{ $alarm['asset'] }}@endif</span>
                            @if ($alarm['url'])<a href="{{ $alarm['url'] }}" wire:navigate class="ml-auto text-xs font-medium underline">Aç</a>@endif
                        </div>
                        @if ($alarm['message'] !== '')<p class="mt-0.5 text-xs opacity-80">{{ \Illuminate\Support\Str::limit($alarm['message'], 160) }}</p>@endif
                    </li>
                @endforeach
            </ul>
            @if ($alarms['more'] > 0)
                <p class="mt-1.5 text-xs text-gray-500">+{{ $alarms['more'] }} alarm daha</p>
            @endif
        @endif
    </section>

    <section class="rounded-xl bg-white px-4 py-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-today-approvals>
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Onay bekleyenler</h2>
            @if ($approvals['total'] > 0)
                <span class="text-xs text-gray-500">{{ $num($approvals['total']) }} paket · {{ $num($approvals['rows'] ?? 0) }} iş</span>
            @endif
        </div>
        @if ($approvals['packages'] === [])
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Onay bekleyen iş yok.</p>
        @else
            <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-800">
                @foreach ($approvals['packages'] as $package)
                    <li class="flex items-baseline gap-2 py-1.5" wire:key="today-package-{{ $package['key'] }}" data-today-package="{{ $package['key'] }}">
                        <a href="{{ $package['url'] }}" wire:navigate class="min-w-0 flex-1 truncate text-gray-800 hover:underline dark:text-white/90">
                            <span class="font-medium">{{ $package['brand'] }}</span>
                            <span class="text-gray-500 dark:text-gray-400">· {{ $package['kind_label'] }}</span>
                        </a>
                        <x-ta.badge size="sm" :color="$package['lane'] === 'ready' ? 'ok' : 'look'">{{ $package['lane_label'] }}</x-ta.badge>
                        <span class="w-10 shrink-0 text-right font-semibold text-gray-900 dark:text-white">{{ $num($package['count']) }}</span>
                    </li>
                @endforeach
            </ul>
            <div class="mt-2">
                <x-ta.button :href="route('operator.repair')" size="sm" wire:navigate>Onay masasını aç</x-ta.button>
            </div>
        @endif
    </section>
</div>

<section class="rounded-xl bg-white px-4 py-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-today-overnight>
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Dün gece yapılanlar <span class="text-xs font-normal text-gray-500">· son 24 saat</span></h2>
        <a href="{{ route('operator.repair') }}" wire:navigate class="link-neutral text-xs">Onarım masasında yapılanlar →</a>
    </div>
    @if ($overnight['writes'] === [] && $overnight['ai'] === [])
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Son 24 saatte onaylı yazma ya da biten AI işi yok.</p>
    @else
        <div class="mt-2 grid gap-4 md:grid-cols-2">
            <div>
                <h3 class="text-xs font-medium text-gray-500 dark:text-gray-400">Onaylanıp yazılanlar</h3>
                @if ($overnight['writes'] === [])
                    <p class="mt-1 text-sm text-gray-500">Yok.</p>
                @else
                    <ul class="mt-1 space-y-1 text-sm">
                        @foreach ($overnight['writes'] as $write)
                            <li class="flex flex-wrap items-baseline gap-x-2" data-today-write>
                                <span class="text-xs text-gray-400">{{ $write['at'] }}</span>
                                <span class="font-medium text-gray-800 dark:text-white/90">{{ $write['asset'] !== '' ? $write['asset'] : $write['label'] }}</span>
                                <span class="text-gray-600 dark:text-gray-300">{{ $write['label'] }} · {{ $write['sentence'] }}</span>
                                @if ($write['failed'] > 0)<x-ta.badge size="sm" color="look">{{ $write['failed'] }} yazılamadı</x-ta.badge>@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div>
                <h3 class="text-xs font-medium text-gray-500 dark:text-gray-400">Claude'un bitirdiği AI işleri</h3>
                @if ($overnight['ai'] === [])
                    <p class="mt-1 text-sm text-gray-500">Yok.</p>
                @else
                    <ul class="mt-1 space-y-1 text-sm">
                        @foreach ($overnight['ai'] as $job)
                            <li class="flex items-baseline justify-between gap-2" data-today-ai>
                                <span class="min-w-0 truncate text-gray-700 dark:text-gray-300">{{ $job['label'] }}</span>
                                <span class="shrink-0 font-semibold text-gray-900 dark:text-white">{{ $num($job['count']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif
</section>
