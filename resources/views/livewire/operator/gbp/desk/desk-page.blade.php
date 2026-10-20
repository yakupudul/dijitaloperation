@php
    // İşletme profilleri › Durum ve ölçüm (panel concept 2026-10-20). The question: "Which profile is missing something
    // or losing views?" The six conditions first (each opens the tab that fixes it), then last month in four numbers,
    // then one row per profile with its own gaps.
    $num = fn (?int $n): string => $n === null ? '—' : number_format($n, 0, ',', '.');
    $delta = function (?int $now, ?int $before): ?int {
        return $now !== null && $before !== null && $before > 0 ? (int) round(($now / $before - 1) * 100) : null;
    };
    $deltaClass = fn (?int $d): string => $d === null ? 'text-gray-400' : ($d >= 0 ? 'text-success-600 dark:text-success-400' : 'text-error-600 dark:text-error-400');
    $deltaText = fn (?int $d): string => $d === null ? '' : ($d >= 0 ? '+'.$d.'%' : '−'.abs($d).'%');
    // Google sends a month's data a few days late: the desk shows the last full month, so the head says which month that is.
    $lagging = $month !== now()->format('Y-m');
    $previousLabel = \Illuminate\Support\Carbon::parse($month.'-01')->subMonth()->locale('tr')->translatedFormat('F');

    // The six conditions: those most profiles share first (one button fixes all), then the other gaps, then the done ones.
    $conditions = collect($readiness)->map(fn (array $item, string $key): array => $item + ['key' => $key, 'shared' => $shared[$key] ?? null,
        'done' => $count > 0 && $item['ok'] === $count])
        ->sortBy(fn (array $c): string => ($c['shared'] !== null ? '0' : ($c['done'] ? '2' : '1')).str_pad((string) $c['ok'], 4, '0', STR_PAD_LEFT))->values();
    $gapCount = $conditions->where('done', false)->count();
    $icons = [
        'page' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
        'description' => '<path d="M4 6h16M4 10h16M4 14h10M4 18h7"/>',
        'hours' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'photos' => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-5-5-8 8"/>',
        'reviews' => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
        'posts' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/>',
    ];
@endphp
<div class="dark:text-gray-200" data-gbp-desk>
    @include('livewire.operator.gbp.partials.desk-tabs', ['active' => 'operator.gbp-desk', 'brandFilter' => $brand])

    <div class="space-y-10">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <h1 class="page-title" title="Her İşletme Profili için son tam ayın sonuçları ve yerel aramada güçlü olmanın altı koşulu. Eksik olana tıklayınca düzeltileceği yere gidersin.">İşletme profilleri</h1>
                <p class="page-sub">@if ($lagging)<span data-gbp-data-month>Son tam veri: {{ $monthLabel }}</span>@else{{ $monthLabel }}@endif, {{ $count }} işletme. {{ $count === 0 ? '' : ($gapCount === 0 ? 'Altı koşulun hepsi tamam.' : 'Altı koşulun '.$gapCount.' tanesinde eksik var.') }}</p>
            </div>
            @include('livewire.operator.gbp.partials.brand-filter')
        </header>

        {{-- 1. The six conditions of a strong profile; a gap most profiles share is fixed from one place. --}}
        <section @if ($shared !== []) data-testid="gbp-shared-gaps" @endif>
            <div class="section-head">
                <h2 class="section-title" title="Çoğu şubede olan eksik burada bir kez gösterilir, satırlarda tekrar edilmez; “Hepsini düzenle” ile tek yerden düzeltirsin.">Koşullar <span class="section-count">{{ $count > 0 ? (6 - $gapCount).'/6 tamam' : '' }}</span></h2>
            </div>
            <ul class="metric-strip lg:grid-cols-2">
                @foreach ($conditions as $c)
                    <li class="flex min-w-0 items-center gap-3.5 bg-white px-4 py-3.5 dark:bg-gray-900" wire:key="check-{{ $c['key'] }}">
                        <span @class(['row-icon max-sm:hidden', 'border-transparent bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400' => $c['shared'] !== null]) aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! $icons[$c['key']] ?? $icons['page'] !!}</svg></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $c['label'] }}</p>
                            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">@if ($c['done'])<x-ta.pill tone="ok" class="text-[13px]!">{{ $c['ok'] }}/{{ $count }} tamam</x-ta.pill>@else{{ $c['shared'] !== null ? $c['shared']['missing'].'/'.$count.' işletmede eksik' : $c['ok'].'/'.$count.' tamam' }}@endif<span @class(['hidden' => $c['unknown'] === 0]) title="Google bu veriyi vermiyor ya da henüz toplanmadı; işletmenin satırında nedeni yazar">, {{ $c['unknown'] }} işletmede veri yok</span></p>
                        </div>
                        @if ($c['shared'] !== null)
                            <a href="{{ route($c['shared']['route'], array_filter(['marka' => $brand] + $c['shared']['query'])) }}" wire:navigate data-testid="gbp-shared-{{ $c['key'] }}" class="btn">Hepsini düzenle</a>
                        @else
                            <a href="{{ route($c['route'], array_filter(['marka' => $brand])) }}" wire:navigate class="btn-quiet" aria-label="Aç: {{ $c['label'] }}">Aç</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>

        {{-- 2. Last full month in four numbers. --}}
        <section>
            <div class="section-head">
                <h2 class="section-title" title="Değişim bir önceki aya göre. Google verisi birkaç gün gecikmeli gelir.">{{ $lagging ? $monthLabel.' (son tam ay)' : $monthLabel }}</h2>
                <span class="text-sm text-gray-500">{{ $count }} işletme, {{ $previousLabel }} ayına göre</span>
            </div>
            <div class="metric-strip grid-cols-2 lg:grid-cols-4">
                @foreach ($columns as $key => $label)
                    @php $d = $delta($totals[$key], $previous[$key] ?: null); @endphp
                    <x-ta.metric-card :label="$label" :value="$num($totals[$key])" :delta="$d === null ? null : $deltaText($d)" :tone="$d === null ? 'neutral' : ($d >= 0 ? 'positive' : 'negative')"
                        :hint="$previous[$key] > 0 ? $previousLabel.' '.$num($previous[$key]) : null" />
                @endforeach
            </div>
        </section>

        {{-- 3. One row per profile, grouped by brand. --}}
        <section>
            <div class="section-head">
                <h2 class="section-title">İşletmeler <span class="section-count">{{ $count }}</span></h2>
                <span class="text-[13px] text-gray-500">Satıra tıklayınca son 6 ay, aramalar ve yapılanlar açılır</span>
            </div>
            @php
                $ownGaps = fn (array $row): array => array_filter($row['checks'], fn (array $c, string $k): bool => ! $c['ok'] && ! isset($shared[$k]), ARRAY_FILTER_USE_BOTH);
            @endphp
            <div class="card overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>İşletme</th>
                            @foreach ($columns as $key => $label)<th @class(['text-right!', 'hidden md:table-cell' => $key !== 'views'])>{{ $label }}</th>@endforeach
                            <th class="hidden md:table-cell">{{ $shared !== [] ? 'Bu şubeye özel eksikler' : 'Koşullar' }}</th>
                        </tr>
                    </thead>
                    @forelse ($groups as $brandName => $brandRows)
                        @php
                            $foldable = $brandRows->count() > \App\Livewire\Operator\Gbp\Desk\DeskPage::GROUP_FOLD_AFTER;
                            $withOwn = $brandRows->filter(fn (array $row): bool => collect($ownGaps($row))->contains(fn (array $c): bool => ! $c['unknown']))->count();
                            $expanded = ! $foldable || $withOwn > 0 || $brandRows->contains(fn (array $row): bool => $open === (int) $row['location']->id);
                            $brandModel = $brandRows->first()['location']->brand;
                        @endphp
                        <tbody wire:key="group-{{ md5((string) $brandName) }}" x-data="{ show: @js($expanded) }" data-testid="gbp-brand-group">
                            <tr class="[&>td]:bg-gray-50/70 dark:[&>td]:bg-white/[0.02]">
                                <td colspan="{{ count($columns) + 2 }}" class="py-2.5!">
                                    @if ($foldable)
                                        <button type="button" x-on:click="show = ! show" class="flex min-w-0 items-center gap-2 text-left" x-bind:aria-expanded="show">
                                            <svg class="h-4 w-4 shrink-0 text-gray-400 transition-transform {{ $expanded ? 'rotate-90' : '' }}" x-bind:class="show ? 'rotate-90' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                                            <x-ta.brand-avatar :name="(string) $brandName" :logo="$brandModel?->logo_url" size="sm" />
                                            <span class="min-w-0"><span class="font-medium text-gray-900 dark:text-white">{{ $brandName }}</span> <span class="tabular-nums text-gray-500">{{ $brandRows->count() }}</span>
                                                <span class="ml-1 text-[13px] text-gray-500">{{ $withOwn > 0 ? $withOwn.' şubede özel eksik' : 'şubeye özel eksik yok' }}</span></span>
                                        </button>
                                    @else
                                        <span class="flex min-w-0 items-center gap-2"><x-ta.brand-avatar :name="(string) $brandName" :logo="$brandModel?->logo_url" size="sm" /> <span class="min-w-0"><span class="font-medium text-gray-900 dark:text-white">{{ $brandName }}</span> <span class="tabular-nums text-gray-500">{{ $brandRows->count() }}</span></span></span>
                                    @endif
                                </td>
                            </tr>
                            @foreach ($brandRows as $row)
                                @php $location = $row['location']; $r = $row['report']; $isOpen = $open === (int) $location->id; @endphp
                                <tr wire:key="row-{{ $location->id }}" @if ($foldable) x-show="show" @if (! $expanded) style="display: none" @endif @endif class="cursor-pointer" wire:click="toggle({{ $location->id }})" @if ($isOpen) aria-expanded="true" @endif>
                                    <td class="max-w-0 w-full md:w-[44%]">
                                        <span class="block truncate font-medium text-gray-900 dark:text-white" title="{{ $location->name }}">{{ \App\Services\Gbp\Desk\GbpDesk::shortName((string) $location->name) }}</span>
                                        <span class="mt-0.5 block truncate text-[13px] text-gray-500">{{ $row['snapshot']['area'] ?? 'Profil verisi yok' }}@if (($row['snapshot']['rating'] ?? null) !== null), ★ {{ $row['snapshot']['rating'] }} ({{ $row['snapshot']['reviews'] }})@endif</span>
                                        @if ($r === null)<span class="mt-0.5 block text-[13px] text-gray-500">{{ $row['performance']['state'] === 'unavailable' ? 'Ölçüm verisi gelmiyor: '.$row['performance']['reason'] : ($row['performance']['state'] === 'never' ? 'Ölçüm verisi henüz toplanmadı' : 'Geçen ayın ölçümü yok') }}</span>@endif
                                        @include('livewire.operator.gbp.partials.desk-gaps', ['class' => 'mt-1.5 flex flex-wrap gap-x-3 gap-y-1 md:hidden'])
                                    </td>
                                    @foreach (array_keys($columns) as $key)
                                        <td @class(['text-right tabular-nums', 'hidden md:table-cell' => $key !== 'views'])>
                                            <span class="text-gray-900 dark:text-white">{{ $num($r['current'][$key] ?? null) }}</span>
                                            @if (($r['change'][$key] ?? null) !== null)<span class="block text-xs {{ $deltaClass($r['change'][$key]) }}">{{ $deltaText($r['change'][$key]) }}</span>@endif
                                        </td>
                                    @endforeach
                                    <td class="hidden md:table-cell">
                                        @include('livewire.operator.gbp.partials.desk-gaps', ['class' => 'flex flex-wrap gap-x-3 gap-y-1'])
                                    </td>
                                </tr>
                                @if ($isOpen)
                                    <tr wire:key="detail-{{ $location->id }}" @if ($foldable) x-show="show" @endif class="[&>td]:bg-gray-50/50! dark:[&>td]:bg-white/[0.02]!">
                                        <td colspan="{{ count($columns) + 2 }}" class="py-4!">
                                            @if ($detail === null)
                                                <p class="text-sm text-gray-500">Bu işletmenin performans verisi henüz toplanmadı.</p>
                                            @else
                                                <div class="grid gap-6 lg:grid-cols-3">
                                                    <div class="min-w-0">
                                                        <h3 class="mb-1.5 text-[13px] font-medium text-gray-900 dark:text-white">Son 6 ay</h3>
                                                        <div class="grid grid-cols-[minmax(0,1fr)_repeat(4,auto)] gap-x-4 gap-y-1 text-[13px]">
                                                            <span class="text-gray-500">Ay</span>@foreach ($columns as $label)<span class="text-right text-gray-500">{{ \Illuminate\Support\Str::before($label, ' ') }}</span>@endforeach
                                                            @forelse ($detail['history'] as $h)
                                                                <span class="text-gray-700 dark:text-gray-300">{{ \Illuminate\Support\Carbon::parse($h['month'].'-01')->locale('tr')->translatedFormat('M Y') }}</span>@foreach (array_keys($columns) as $key)<span class="text-right tabular-nums text-gray-700 dark:text-gray-300">{{ $num($h[$key]) }}</span>@endforeach
                                                            @empty
                                                                <span class="col-span-5 text-gray-500">Veri yok.</span>
                                                            @endforelse
                                                        </div>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <h3 class="mb-1.5 text-[13px] font-medium text-gray-900 dark:text-white">Google’da bulunduğu aramalar, {{ $detail['keywords']['month'] ? \Illuminate\Support\Carbon::parse($detail['keywords']['month'].'-01')->locale('tr')->translatedFormat('F Y') : $monthLabel }}</h3>
                                                        @if ($detail['keywords']['month'] !== null && $detail['keywords']['month'] !== $month)<p class="mb-1 text-xs text-gray-500">{{ $monthLabel }} kelimeleri Google’dan henüz gelmedi; son gelen ay gösteriliyor.</p>@endif
                                                        @forelse ($detail['keywords']['rows'] as $k)
                                                            <div class="flex justify-between gap-2 py-0.5 text-[13px]">
                                                                <span class="truncate text-gray-700 dark:text-gray-300" title="{{ $k['keyword'] }}">{{ $k['keyword'] }} @if ($k['new'])<span class="text-xs text-success-700 dark:text-success-400">yeni</span>@endif</span>
                                                                <span class="shrink-0 tabular-nums text-gray-600 dark:text-gray-300">{{ $k['current'] !== null ? $num($k['current']) : '<'.$k['threshold'] }} @if ($k['previous'] !== null && $k['current'] !== null)<span class="{{ $deltaClass($delta($k['current'], $k['previous'])) }}">{{ $deltaText($delta($k['current'], $k['previous'])) }}</span>@endif</span>
                                                            </div>
                                                        @empty
                                                            <p class="text-[13px] text-gray-500">Google bu işletme için arama kelimesi vermedi (az aranan işletmelerde Google kelimeleri gizler) ya da henüz toplanmadı.</p>
                                                        @endforelse
                                                    </div>
                                                    <div class="min-w-0">
                                                        <h3 class="mb-1.5 text-[13px] font-medium text-gray-900 dark:text-white">MoxDOP’un son yaptıkları</h3>
                                                        @forelse ($detail['work'] as $item)
                                                            <p class="py-0.5 text-[13px] text-gray-700 dark:text-gray-300"><span class="mr-1.5 tabular-nums text-gray-500">{{ $item['at'] }}</span>{{ $item['label'] }}, <span @class(['text-success-600' => in_array($item['status'], ['succeeded', 'partial'], true), 'text-error-600' => $item['status'] === 'failed', 'text-gray-500' => ! in_array($item['status'], ['succeeded', 'partial', 'failed'], true)])>{{ $item['status_label'] }}</span></p>
                                                        @empty
                                                            <p class="text-[13px] text-gray-500">Bu profile MoxDOP’tan henüz bir şey gönderilmedi.</p>
                                                        @endforelse
                                                        <a href="{{ route('operator.gbp', ['assetId' => $location->id, 'tab' => 'analysis']) }}" wire:navigate x-on:click.stop class="btn mt-3">Günlük analiz</a>
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
            </div>
        </section>
    </div>
</div>
