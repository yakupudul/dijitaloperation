@php
    use App\Livewire\Operator\Portfolio\PortfolioPage;
    use App\Services\Goals\BrandTargets;
    use App\Services\Operator\KomutaReader;

    $n = fn (int $v): string => number_format($v, 0, ',', '.');
    $stateLabel = ['ahead' => 'Önde', 'on_track' => 'Yolunda', 'behind' => 'Geride', 'no_goal' => 'Amaç yok'];
    $stateChip = ['ahead' => 'chip-up', 'on_track' => 'chip-flat', 'behind' => 'chip-down', 'no_goal' => 'chip-flat'];
    $dot = ['ok' => 'bg-success-500', 'look' => 'bg-warning-500', 'bad' => 'bg-error-500', 'none' => 'bg-gray-200 dark:bg-gray-700'];
    $dotTitle = ['ok' => 'sağlıklı', 'look' => 'bakılacak bir alarm var', 'bad' => 'para ya da veri kaybı', 'none' => 'bağlı varlık yok'];
    $short = ['web' => 'Web', 'google_ads' => 'Ads', 'meta' => 'Meta', 'gbp' => 'Profil'];
    $spark = function (array $values): ?string {
        $values = array_values(array_map('floatval', $values));
        if (count($values) < 2 || max($values) <= 0) {
            return null;
        }
        $max = max($values);
        $points = [];
        foreach ($values as $i => $v) {
            $points[] = round($i * 88 / (count($values) - 1), 1).','.round(26 - $v / $max * 22, 1);
        }

        return implode(' ', $points);
    };
    $month = now('Europe/Istanbul')->locale('tr')->isoFormat('MMMM');
@endphp
<div class="pb-24" data-portfolio>
    <h1 class="page-title">Port<span class="highlight">föy</span></h1>
    <p class="page-sub">Her marka tek satırda: amacı, bu ayki talebi, dört kanalının durumu ve sekiz haftalık eğilimi. Dikkat isteyen en üstte.</p>

    <div class="hero-panel mt-7 grid-cols-2 lg:grid-cols-4" data-portfolio-summary>
        <div class="hero-tile hero-tile-accent">
            <span class="hero-label">{{ \Illuminate\Support\Str::ucfirst($month) }} talebi</span>
            <span class="hero-value">{{ $n($mtdTotal) }}<span class="text-base font-medium opacity-70"> / {{ $n($goalTotal) }}</span></span>
            <span class="mt-2 text-xs opacity-75">ay sonu tahmini {{ $n($projectedTotal) }}</span>
        </div>
        <div class="hero-tile"><span class="hero-label">Amacında</span><span class="hero-value">{{ $n(($states['ahead'] ?? 0) + ($states['on_track'] ?? 0)) }}</span><span class="mt-2 text-xs text-white/60">marka</span></div>
        <div class="hero-tile"><span class="hero-label">Geride</span><span class="hero-value">{{ $n($states['behind'] ?? 0) }}</span><span class="mt-2 text-xs text-white/60">marka</span></div>
        <div class="hero-tile"><span class="hero-label">Amacı onaylanmadı</span><span class="hero-value">{{ $n($states['no_goal'] ?? 0) }}</span><span class="mt-2 text-xs text-white/60">marka</span></div>
    </div>

    <div class="mt-8 flex flex-wrap items-center gap-1.5" role="tablist" aria-label="Sıralama">
        @foreach (PortfolioPage::SORTS as $key => $label)
            <button type="button" wire:click="$set('sort', '{{ $key }}')" @class(['seg', 'seg-active' => $sort === $key]) aria-selected="{{ $sort === $key ? 'true' : 'false' }}" role="tab">{{ $label }}</button>
        @endforeach
    </div>
    @if ($message !== '')
        <p class="mt-4 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300" data-portfolio-message>{{ $message }}</p>
    @endif

    <div class="card mt-4" data-portfolio-list>
        @forelse ($rows as $row)
            @php
                $p = $row['progress'];
                $target = $row['target'];
                $pct = $p['goal'] ? min(100, (int) round($p['mtd'] / max(1, $p['goal']) * 100)) : 0;
                $expectedPct = $p['goal'] && $p['expected'] !== null ? min(100, (int) round($p['expected'] / max(1, $p['goal']) * 100)) : null;
                $line = $spark($row['clicks']);
            @endphp
            <div wire:key="brand-{{ $row['id'] }}" @class(['grid grid-cols-1 gap-3 border-t border-gray-200 px-4 py-3.5 first:border-t-0 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1.6fr)_150px_100px_70px] lg:items-center dark:border-gray-800', 'bg-accent-soft/50' => $brand === $row['id']]) data-portfolio-brand="{{ $row['id'] }}">
                <div class="flex min-w-0 items-center gap-3">
                    <x-ta.brand-avatar :name="$row['name']" :logo="$row['logo']" />
                    <div class="min-w-0">
                        <a href="{{ route('operator.brand', ['brand' => $row['id']]) }}" wire:navigate class="block truncate text-sm font-semibold text-gray-900 hover:underline dark:text-white">{{ $row['name'] }}</a>
                        <p class="truncate text-[13px] text-gray-500">{{ $row['reasons'] !== [] ? \Illuminate\Support\Str::ucfirst(implode(' · ', $row['reasons'])) : 'Sorun yok' }}</p>
                    </div>
                </div>

                <div class="min-w-0" data-portfolio-goal>
                    @if ($p['goal'] !== null && $editing !== $row['id'])
                        <div class="flex items-baseline justify-between gap-2 text-[13px]">
                            <span><b class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $n($p['mtd']) }}</b> <span class="text-gray-500">/ {{ $n($p['goal']) }} talep · ay sonu ~{{ $n($p['projected']) }}</span></span>
                            <span class="chip {{ $stateChip[$p['state']] }}">{{ $stateLabel[$p['state']] }}{{ $p['gap_pct'] !== null && $p['state'] !== 'on_track' ? ' '.($p['gap_pct'] > 0 ? '+' : '').$p['gap_pct'].'%' : '' }}</span>
                        </div>
                        <div class="relative mt-1.5 h-1.5 overflow-hidden rounded-full bg-gray-900/[0.08] dark:bg-white/10" title="Şu ana kadar beklenen: {{ $p['expected'] }}">
                            <i @class(['block h-full rounded-full', 'bg-success-500' => $p['state'] !== 'behind', 'bg-error-500' => $p['state'] === 'behind']) style="width: {{ $pct }}%"></i>
                            @if ($expectedPct !== null)<i class="absolute top-0 h-full w-0.5 bg-gray-900/50 dark:bg-white/60" style="left: {{ $expectedPct }}%"></i>@endif
                        </div>
                        <p class="mt-1 truncate text-xs text-gray-500">{{ KomutaReader::splitLine((array) $target?->split) }}@if ($isAdmin) · <button type="button" wire:click="edit({{ $row['id'] }})" class="link-neutral">değiştir</button>@endif</p>
                    @elseif ($target !== null && ($target->proposed_leads !== null || $editing === $row['id']))
                        <div class="flex flex-wrap items-center gap-2 text-[13px]">
                            @if ($editing === $row['id'])
                                <label class="sr-only" for="goal-{{ $row['id'] }}">Aylık talep amacı</label>
                                <span class="text-gray-500">Ayda</span><input id="goal-{{ $row['id'] }}" type="number" min="1" wire:model="goal" class="h-8 w-20 rounded-lg border border-gray-200 bg-white px-2 text-sm tabular-nums dark:border-gray-700 dark:bg-gray-900" /><span class="text-gray-500">talep</span>
                            @else
                                <span class="text-gray-600 dark:text-gray-300">Öneri: <b class="font-semibold text-gray-900 dark:text-white">ayda {{ $target->proposed_leads }} talep</b></span>
                            @endif
                            @if ($isAdmin)
                                <button type="button" wire:click="approve({{ $row['id'] }})" wire:loading.attr="disabled" class="btn-dark h-7" data-portfolio-approve>Onayla</button>
                                @if ($editing !== $row['id'])<button type="button" wire:click="edit({{ $row['id'] }})" class="btn-quiet h-7">Değiştir</button>@endif
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-gray-500" title="{{ $target->reason }}">{{ \Illuminate\Support\Str::limit((string) $target->reason, 110) }}</p>
                    @else
                        <p class="text-[13px] text-gray-500">{{ $target?->reason ? \Illuminate\Support\Str::limit((string) $target->reason, 110) : 'Talep henüz okunmadı; gece okunur.' }}</p>
                    @endif
                </div>

                <div class="flex items-center gap-3" data-portfolio-channels>
                    @foreach ($row['channels'] as $channel => $state)
                        <span class="flex flex-col items-center gap-1 text-[11px] text-gray-500" title="{{ BrandTargets::CHANNELS[$channel] }}: {{ $dotTitle[$state] }}" data-channel="{{ $channel }}" data-health="{{ $state }}">
                            <i class="h-2.5 w-2.5 rounded-full {{ $dot[$state] }}"></i>{{ $short[$channel] }}
                        </span>
                    @endforeach
                </div>

                <div title="Son 8 hafta organik tık">
                    @if ($line !== null)
                        <svg viewBox="0 0 88 28" class="h-7 w-[88px] text-second" aria-hidden="true"><polyline points="{{ $line }}" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"/></svg>
                    @else
                        <span class="text-xs text-gray-400">veri yok</span>
                    @endif
                </div>

                <div class="text-right">
                    @if ($row['waiting'] > 0)
                        <a href="{{ route('operator.repair', ['marka' => $row['id']]) }}" wire:navigate class="menu-count menu-count-accent ml-0 inline-grid" title="Karar bekleyen iş">{{ $n($row['waiting']) }}</a>
                    @endif
                </div>
            </div>
        @empty
            <div class="px-4 py-10 text-center text-sm text-gray-500">Henüz marka yok.</div>
        @endforelse
    </div>
</div>
