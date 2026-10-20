@php
    $num = fn (?int $n): string => $n === null ? '—' : number_format($n, 0, ',', '.');
    $delta = function (?int $now, ?int $before): ?int {
        return $now !== null && $before !== null && $before > 0 ? (int) round(($now / $before - 1) * 100) : null;
    };
    $deltaClass = fn (?int $d): string => $d === null ? 'text-gray-400' : ($d >= 0 ? 'text-success-600 dark:text-success-400' : 'text-error-600 dark:text-error-400');
    $deltaText = fn (?int $d): string => $d === null ? '' : ($d >= 0 ? '+'.$d.'%' : '−'.abs($d).'%');
    // Google sends a month's data a few days late: the desk shows the last full month, so the head says which month that is.
    $lagging = $month !== now()->format('Y-m');
@endphp
<div class="space-y-10 dark:text-gray-200" data-gbp-desk>
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <h1 class="text-[28px] font-semibold leading-9 tracking-tight text-gray-900 dark:text-white" title="Her İşletme Profili için son tam ayın sonuçları ve yerel aramada güçlü olmanın altı koşulu. Eksik olana tıklayınca düzeltileceği yere gidersin.">İşletme profilleri</h1>
            @if ($lagging)<p class="mt-1 text-sm text-gray-500"><span data-gbp-data-month>Son tam veri: {{ $monthLabel }}</span></p>@endif
        </div>
        @include('livewire.operator.gbp.partials.brand-filter')
    </header>
    @include('livewire.operator.gbp.partials.desk-tabs', ['active' => 'operator.gbp-desk', 'brandFilter' => $brand])

    <section>
        <h2 class="section-title mb-4" title="Değişim bir önceki aya göre. Google verisi birkaç gün gecikmeli gelir.">{{ $lagging ? $monthLabel.' (son tam ay)' : $monthLabel }} <span class="text-sm font-normal text-gray-500">{{ $count }} işletme</span></h2>
        <div class="grid grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-4">
            @foreach ($columns as $key => $label)
                @php $d = $delta($totals[$key], $previous[$key] ?: null); @endphp
                <x-ta.metric-card :label="$label" :value="$num($totals[$key])" :delta="$d === null ? null : $deltaText($d)" :tone="$d === null ? 'neutral' : ($d >= 0 ? 'positive' : 'negative')" class="min-w-0" />
            @endforeach
        </div>
    </section>

    <section class="grid grid-cols-2 gap-x-8 gap-y-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ($readiness as $key => $item)
            <a href="{{ route($item['route'], array_filter(['marka' => $brand])) }}" wire:navigate class="-mx-2 min-w-0 rounded-lg px-2 py-1.5 hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                <span @class(['block text-lg font-semibold tabular-nums', 'text-gray-900 dark:text-white' => $item['ok'] === $count && $count > 0, 'text-warning-700 dark:text-warning-400' => $item['ok'] !== $count || $count === 0])>{{ $item['ok'] }}/{{ $count }}</span>
                <span class="block text-xs text-gray-500">{{ $item['label'] }} tamam</span>
                @if ($item['unknown'] > 0)<span class="block text-xs text-gray-500" title="Google bu veriyi vermiyor ya da henüz toplanmadı; işletmenin satırında nedeni yazar">{{ $item['unknown'] }} işletmede veri yok</span>@endif
            </a>
        @endforeach
    </section>

    @if ($shared !== [])
        <section data-testid="gbp-shared-gaps">
            <h2 class="section-title" title="Bunlar satırlarda tekrar gösterilmiyor; hepsini tek yerden düzenleyebilirsin.">Şubelerin çoğunda ortak eksik</h2>
            <ul class="mt-2 border-t border-gray-200 dark:border-gray-800">
                @foreach ($shared as $key => $gap)
                    <li class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 py-2.5 dark:border-gray-800">
                        <span class="min-w-0 text-sm text-gray-900 dark:text-gray-100">{{ $gap['label'] }} <span class="ml-1 text-xs text-gray-500">{{ $gap['missing'] }}/{{ $count }} işletmede eksik</span></span>
                        <a href="{{ route($gap['route'], array_filter(['marka' => $brand] + $gap['query'])) }}" wire:navigate data-testid="gbp-shared-{{ $key }}"
                            class="rounded-lg px-2.5 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.04]">Hepsini düzenle</a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <table class="w-full min-w-[760px] text-sm">
            <thead class="text-left text-xs text-gray-500">
                <tr class="border-b border-gray-200 dark:border-gray-800">
                    <th class="py-2 pr-4 font-normal">İşletme</th>
                    @foreach ($columns as $label)<th class="px-2 py-2 text-right font-normal">{{ $label }}</th>@endforeach
                    <th class="py-2 pl-4 font-normal">{{ $shared !== [] ? 'Bu şubeye özel eksikler' : 'Koşullar' }}</th>
                </tr>
            </thead>
                @php
                    $ownGaps = fn (array $row): array => array_filter($row['checks'], fn (array $c, string $k): bool => ! $c['ok'] && ! isset($shared[$k]), ARRAY_FILTER_USE_BOTH);
                @endphp
                @forelse ($groups as $brandName => $brandRows)
                    @php
                        $foldable = $brandRows->count() > \App\Livewire\Operator\Gbp\Desk\DeskPage::GROUP_FOLD_AFTER;
                        $withOwn = $brandRows->filter(fn (array $row): bool => collect($ownGaps($row))->contains(fn (array $c): bool => ! $c['unknown']))->count();
                        $expanded = ! $foldable || $withOwn > 0 || $brandRows->contains(fn (array $row): bool => $open === (int) $row['location']->id);
                    @endphp
                    <tbody wire:key="group-{{ md5((string) $brandName) }}" x-data="{ show: @js($expanded) }" class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800" data-testid="gbp-brand-group">
                    <tr>
                        <td colspan="{{ count($columns) + 2 }}" class="pb-1.5 pt-5 text-sm font-semibold text-gray-900 dark:text-white">
                            @if ($foldable)
                                <button type="button" x-on:click="show = ! show" class="inline-flex flex-wrap items-center gap-1.5 hover:text-gray-900 dark:hover:text-white" x-bind:aria-expanded="show">
                                    <span x-text="show ? '▾' : '▸'" class="text-gray-400">{{ $expanded ? '▾' : '▸' }}</span> <x-ta.brand-avatar :name="(string) $brandName" size="xs" /> {{ $brandName }} <span class="font-normal tabular-nums text-gray-500">{{ $brandRows->count() }}</span>
                                    <span class="ml-1 text-xs font-normal {{ $withOwn > 0 ? 'text-gray-700 dark:text-gray-300' : 'text-gray-500' }}">{{ $withOwn > 0 ? $withOwn.' şubede özel eksik' : 'şubeye özel eksik yok' }}</span>
                                </button>
                            @else
                                <span class="inline-flex items-center gap-1.5"><x-ta.brand-avatar :name="(string) $brandName" size="xs" /> {{ $brandName }} <span class="font-normal tabular-nums text-gray-500">{{ $brandRows->count() }}</span></span>
                            @endif
                        </td>
                    </tr>
                    @foreach ($brandRows as $row)
                        @php $location = $row['location']; $r = $row['report']; @endphp
                        <tr wire:key="row-{{ $location->id }}" @if ($foldable) x-show="show" @if (! $expanded) style="display: none" @endif @endif class="cursor-pointer hover:bg-gray-50 dark:hover:bg-white/[0.03]" wire:click="toggle({{ $location->id }})">
                            <td class="py-2.5 pr-4">
                                <span class="block font-medium text-gray-900 dark:text-white" title="{{ $location->name }}">{{ \App\Services\Gbp\Desk\GbpDesk::shortName((string) $location->name) }}</span>
                                <span class="block text-xs text-gray-500">{{ $row['snapshot']['area'] ?? 'Profil verisi yok' }}@if (($row['snapshot']['rating'] ?? null) !== null) · ★ {{ $row['snapshot']['rating'] }} ({{ $row['snapshot']['reviews'] }})@endif</span>
                                @if ($r === null)<span class="block text-xs text-gray-500">{{ $row['performance']['state'] === 'unavailable' ? 'Ölçüm verisi gelmiyor: '.$row['performance']['reason'] : ($row['performance']['state'] === 'never' ? 'Ölçüm verisi henüz toplanmadı' : 'Geçen ayın ölçümü yok') }}</span>@endif
                            </td>
                            @foreach (array_keys($columns) as $key)
                                <td class="px-2 py-2.5 text-right tabular-nums">
                                    {{ $num($r['current'][$key] ?? null) }}
                                    @if (($r['change'][$key] ?? null) !== null)<span class="block text-xs {{ $deltaClass($r['change'][$key]) }}">{{ $deltaText($r['change'][$key]) }}</span>@endif
                                </td>
                            @endforeach
                            <td class="py-2.5 pl-4">
                                <span class="flex flex-wrap gap-x-3 gap-y-1">
                                    @forelse ($ownGaps($row) as $check)
                                        <a href="{{ route($check['route'], array_filter(['marka' => $location->brand_id])) }}" wire:navigate x-on:click.stop title="{{ $check['label'] }}: {{ $check['hint'] }}"
                                            @class(['text-xs underline decoration-gray-300 underline-offset-2 hover:decoration-gray-500 dark:decoration-gray-600', 'text-gray-500' => $check['unknown'], 'text-gray-800 dark:text-gray-200' => ! $check['unknown']])>{{ $check['label'] }}@if ($check['unknown'])<span class="sr-only"> (veri yok)</span>@endif</a>
                                    @empty
                                        <x-ta.pill tone="ok" title="{{ collect($row['checks'])->filter(fn (array $c): bool => $c['ok'])->pluck('label')->join(', ') }}">{{ $shared !== [] ? 'Özel eksik yok' : 'Tamam' }}</x-ta.pill>
                                    @endforelse
                                </span>
                            </td>
                        </tr>
                        @if ($open === (int) $location->id)
                            <tr wire:key="detail-{{ $location->id }}" @if ($foldable) x-show="show" @endif>
                                <td colspan="{{ count($columns) + 2 }}" class="py-4 sm:pl-4">
                                    @if ($detail === null)
                                        <p class="text-sm text-gray-500">Bu işletmenin performans verisi henüz toplanmadı.</p>
                                    @else
                                        <div class="grid gap-6 lg:grid-cols-3">
                                            <div>
                                                <h3 class="mb-1 text-sm font-medium text-gray-900 dark:text-white">Son 6 ay</h3>
                                                <table class="w-full text-xs">
                                                    <thead class="text-gray-500"><tr><th class="text-left font-normal">Ay</th>@foreach ($columns as $label)<th class="text-right font-normal">{{ \Illuminate\Support\Str::before($label, ' ') }}</th>@endforeach</tr></thead>
                                                    <tbody>
                                                        @forelse ($detail['history'] as $h)
                                                            <tr><td>{{ \Illuminate\Support\Carbon::parse($h['month'].'-01')->locale('tr')->translatedFormat('M Y') }}</td>@foreach (array_keys($columns) as $key)<td class="text-right tabular-nums">{{ $num($h[$key]) }}</td>@endforeach</tr>
                                                        @empty
                                                            <tr><td colspan="5" class="text-gray-500">Veri yok.</td></tr>
                                                        @endforelse
                                                    </tbody>
                                                </table>
                                            </div>
                                            <div>
                                                <h3 class="mb-1 text-sm font-medium text-gray-900 dark:text-white">Google’da bulunduğu aramalar · {{ $detail['keywords']['month'] ? \Illuminate\Support\Carbon::parse($detail['keywords']['month'].'-01')->locale('tr')->translatedFormat('F Y') : $monthLabel }}</h3>
                                                @if ($detail['keywords']['month'] !== null && $detail['keywords']['month'] !== $month)<p class="mb-1 text-xs text-gray-500">{{ $monthLabel }} kelimeleri Google’dan henüz gelmedi; son gelen ay gösteriliyor.</p>@endif
                                                @forelse ($detail['keywords']['rows'] as $k)
                                                    <div class="flex justify-between gap-2 text-xs">
                                                        <span class="truncate" title="{{ $k['keyword'] }}">{{ $k['keyword'] }} @if ($k['new'])<span class="text-xs text-success-700 dark:text-success-400">yeni</span>@endif</span>
                                                        <span class="tabular-nums text-gray-600 dark:text-gray-300">{{ $k['current'] !== null ? $num($k['current']) : '<'.$k['threshold'] }} @if ($k['previous'] !== null && $k['current'] !== null)<span class="{{ $deltaClass($delta($k['current'], $k['previous'])) }}">{{ $deltaText($delta($k['current'], $k['previous'])) }}</span>@endif</span>
                                                    </div>
                                                @empty
                                                    <p class="text-xs text-gray-500">Google bu işletme için arama kelimesi vermedi (az aranan işletmelerde Google kelimeleri gizler) ya da henüz toplanmadı.</p>
                                                @endforelse
                                            </div>
                                            <div>
                                                <h3 class="mb-1 text-sm font-medium text-gray-900 dark:text-white">MoxDOP’un son yaptıkları</h3>
                                                @forelse ($detail['work'] as $item)
                                                    <p class="text-xs"><span class="mr-1.5 tabular-nums text-gray-500">{{ $item['at'] }}</span>{{ $item['label'] }}, <span @class(['text-success-600' => in_array($item['status'], ['succeeded', 'partial'], true), 'text-error-600' => $item['status'] === 'failed', 'text-gray-500' => ! in_array($item['status'], ['succeeded', 'partial', 'failed'], true)])>{{ $item['status_label'] }}</span></p>
                                                @empty
                                                    <p class="text-xs text-gray-500">Bu profile MoxDOP’tan henüz bir şey gönderilmedi.</p>
                                                @endforelse
                                                <a href="{{ route('operator.gbp', ['assetId' => $location->id, 'tab' => 'analysis']) }}" wire:navigate class="link-neutral mt-2 inline-block text-xs">Günlük analiz</a>
                                            </div>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @endforeach
                    </tbody>
                @empty
                    <tbody><tr><td colspan="{{ count($columns) + 2 }}" class="py-5 text-sm text-gray-500">Operasyonel markaya bağlı İşletme Profili yok. <a href="{{ route('operator.integrations') }}" wire:navigate class="link-neutral font-medium">Entegrasyonlardan bağla</a></td></tr></tbody>
                @endforelse
        </table>
    </section>
</div>
