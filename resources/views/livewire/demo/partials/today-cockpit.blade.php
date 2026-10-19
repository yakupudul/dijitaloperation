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
            return $current > 0 ? ['yeni', 'neutral'] : null;
        }
        $change = (int) round(100 * ($current - $previous) / $previous);
        $tone = match (true) {
            ! $lossWhenDown || $change === 0 => 'neutral',
            $change > 0 => 'positive',
            default => 'negative',
        };

        return [($change > 0 ? '+' : '').$change.'%', $tone];
    };
    $deltaText = ['neutral' => 'text-gray-500 dark:text-gray-400', 'positive' => 'text-success-700 dark:text-success-400', 'negative' => 'text-error-700 dark:text-error-400'];
    $clicksDelta = $delta($week['clicks']['current'], $week['clicks']['previous']);
    $leadsDelta = $delta($week['conversions']['current'], $week['conversions']['previous']);
    $alarmTone = $alarms['loss'] > 0 ? 'negative' : ($alarms['accounts'] > 0 ? 'warning' : 'positive');
@endphp

{{-- Four counters: approvals, this week (with sparkline), alarms, AI work of the night. Same data-today-* hooks as before. --}}
<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" data-today-counters>
    <x-ta.metric-card :href="route('operator.repair')" label="Onay bekleyen" :value="$num($approvals['total'])"
        :hint="$approvals['total'] > 0 ? 'paket · '.$num($approvals['rows'] ?? 0).' iş · hepsini gör →' : 'Onarım masası boş'" data-today-counter="approvals" />

    <x-ta.metric-card label="Organik tık · bu hafta" :value="$num($week['clicks']['current'])" :delta="$clicksDelta[0] ?? null" :tone="$clicksDelta[1] ?? 'neutral'"
        :spark="$week['series']['clicks'] ?? []" :hint="$week['from'].'–'.$week['to'].' · önceki 7 güne göre'" data-today-counter="week">
        <dl class="mt-1 space-y-0.5 border-t border-gray-100 pt-2 text-xs dark:border-gray-800">
            <div class="flex items-baseline justify-between gap-2">
                <dt class="text-gray-500 dark:text-gray-400" title="Google Ads dönüşümleri">Müşteri (Ads)</dt>
                <dd class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $num($week['conversions']['current']) }}
                    @if ($leadsDelta)<span class="ml-1 font-medium {{ $deltaText[$leadsDelta[1]] }}">{{ $leadsDelta[0] }}</span>@endif</dd>
            </div>
            <div class="flex items-baseline justify-between gap-2">
                <dt class="text-gray-500 dark:text-gray-400" title="Google Ads + Meta">Harcama</dt>
                <dd class="text-right font-semibold tabular-nums text-gray-900 dark:text-white">
                    @forelse ($week['spend'] as $spend)
                        @php($spendDelta = $delta($spend['current'], $spend['previous'], false))
                        <span class="block">{{ $money($spend['current'], $spend['currency']) }}@if ($spendDelta)<span class="ml-1 font-medium {{ $deltaText[$spendDelta[1]] }}">{{ $spendDelta[0] }}</span>@endif</span>
                    @empty
                        <span class="text-gray-400">—</span>
                    @endforelse
                </dd>
            </div>
        </dl>
    </x-ta.metric-card>

    <x-ta.metric-card href="#alarm-verenler" label="Alarm veren hesaplar" :value="$num($alarms['accounts'])" :tone="$alarmTone"
        :delta="$alarms['loss'] > 0 ? $alarms['loss'].' kayıp' : null"
        :hint="$alarms['accounts'] === 0 ? 'Sorun yok' : ($alarms['loss'] > 0 ? $alarms['loss'].' hesap para / trafik kaybediyor' : 'Bakılması gerekenler var')" data-today-counter="alarms" />

    <x-ta.metric-card :href="route('operator.ai-jobs', ['durum' => 'done'])" label="Claude dün gece" :value="$num($overnight['ai_done'])" hint="AI işi bitti · son 24 saat" data-today-counter="ai" />
</div>

<div class="grid gap-4 lg:grid-cols-2">
    <section id="alarm-verenler" class="surface px-4 py-3" data-today-alarms>
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Alarm verenler</h2>
            @if ($alarms['items'] !== [])
                <a href="{{ route('operator.repair', ['bolum' => 'diger']) }}" wire:navigate class="link-neutral text-xs">Hepsini gör →</a>
            @endif
        </div>
        @if ($alarms['items'] === [])
            <x-ta.empty-state size="sm" class="mt-2" title="Sorun yok" message="Harcaması duran hesap, erişilemeyen site ya da biten bütçe yok." />
        @else
            <ul class="mt-1 divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($alarms['items'] as $alarm)
                    <x-ta.alarm-row wire:key="today-alarm-{{ $alarm['id'] }}" :tone="$alarm['tone']" :title="$alarm['title']" :brand="$alarm['brand']" :meta="$alarm['asset'] !== '' ? $alarm['asset'] : null"
                        :message="$alarm['message'] !== '' ? \Illuminate\Support\Str::limit($alarm['message'], 160) : null" :href="$alarm['url']" data-today-alarm="{{ $alarm['tone'] }}" />
                @endforeach
            </ul>
            @if ($alarms['more'] > 0)
                <p class="mt-1.5 text-xs text-gray-500">+{{ $alarms['more'] }} alarm daha</p>
            @endif
        @endif
    </section>

    <section class="surface px-4 py-3" data-today-approvals>
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Onay bekleyenler</h2>
            @if ($approvals['total'] > 0)
                <span class="text-xs text-gray-500">{{ $num($approvals['total']) }} paket · {{ $num($approvals['rows'] ?? 0) }} iş</span>
            @endif
        </div>
        @if ($approvals['packages'] === [])
            <x-ta.empty-state size="sm" class="mt-2" title="Onay bekleyen iş yok." message="Hazırlanan düzeltmeler burada toplanır; bir tıkla gönderilir." />
        @else
            <ul class="mt-1 divide-y divide-gray-100 text-sm dark:divide-gray-800">
                @foreach ($approvals['packages'] as $package)
                    <li class="flex items-center gap-3 py-2" wire:key="today-package-{{ $package['key'] }}" data-today-package="{{ $package['key'] }}">
                        <x-ta.brand-avatar :name="$package['brand']" size="sm" />
                        <a href="{{ $package['url'] }}" wire:navigate class="min-w-0 flex-1 truncate text-gray-800 hover:underline dark:text-white/90">
                            <span class="font-medium">{{ $package['brand'] }}</span>
                            <span class="text-gray-500 dark:text-gray-400">· {{ $package['kind_label'] }}</span>
                        </a>
                        <x-ta.pill :tone="$package['lane'] === 'ready' ? 'ok' : 'look'">{{ $package['lane_label'] }}</x-ta.pill>
                        <span class="w-8 shrink-0 text-right font-semibold tabular-nums text-gray-900 dark:text-white">{{ $num($package['count']) }}</span>
                    </li>
                @endforeach
            </ul>
            <div class="mt-3">
                <x-ta.button :href="route('operator.repair')" size="sm" wire:navigate>Onay masasını aç</x-ta.button>
            </div>
        @endif
    </section>
</div>

<section class="surface px-4 py-3" data-today-overnight>
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
                    <ul class="mt-1 divide-y divide-gray-100 text-sm dark:divide-gray-800">
                        @foreach ($overnight['writes'] as $write)
                            <li class="flex flex-wrap items-baseline gap-x-2 py-1.5" data-today-write>
                                <span class="w-10 shrink-0 text-xs tabular-nums text-gray-400">{{ $write['at'] }}</span>
                                <span class="font-medium text-gray-800 dark:text-white/90">{{ $write['asset'] !== '' ? $write['asset'] : $write['label'] }}</span>
                                <span class="text-gray-600 dark:text-gray-300">{{ $write['label'] }} · {{ $write['sentence'] }}</span>
                                @if ($write['failed'] > 0)<x-ta.pill tone="look">{{ $write['failed'] }} yazılamadı</x-ta.pill>@endif
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
                    <ul class="mt-1 divide-y divide-gray-100 text-sm dark:divide-gray-800">
                        @foreach ($overnight['ai'] as $job)
                            <li class="flex items-baseline justify-between gap-2 py-1.5" data-today-ai>
                                <span class="min-w-0 truncate text-gray-700 dark:text-gray-300">{{ $job['label'] }}</span>
                                <span class="shrink-0 font-semibold tabular-nums text-gray-900 dark:text-white">{{ $num($job['count']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif
</section>
