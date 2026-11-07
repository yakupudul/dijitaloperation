@php
    use App\Livewire\Operator\Marketing\MarketingPage;
    use App\Services\Marketing\MarketingFunnel;

    $n = fn (int|float $v): string => number_format((int) round($v), 0, ',', '.');
    $money = fn (float $v): string => number_format($v, $v === floor($v) ? 0 : 2, ',', '.').' ₺';
    $delta = function (?int $now, ?int $prev): string {
        if ($now === null || $prev === null) {
            return '';
        }
        if ($prev === 0) {
            return $now > 0 ? 'geçen ay 0' : 'geçen ay da 0';
        }
        $pct = (int) round(($now - $prev) * 100 / $prev);

        return 'geçen ay '.number_format($prev, 0, ',', '.').($pct !== 0 ? ' ('.($pct > 0 ? '+' : '').$pct.'%)' : '');
    };
    $monthLabel = $monthDate->locale('tr')->isoFormat('MMMM YYYY');
    $t = $funnel['totals'];
@endphp
{{-- Pazarlama (Aşama 4, 2026-11-07): one funnel by source against the monthly target; our own channels as tabs. --}}
<div class="pb-24" data-marketing>
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="page-title">Pazar<span class="highlight">lama</span></h1>
            <p class="page-sub">Hangi kaynak kaç başvuru, kaç teklif, kaç müşteri ve kaç ₺ getirdi. Sayılar satış kayıtlarından okunur; hedef ayda {{ $n($t['target'] / max(1, $funnel['span'])) }} {{ mb_strtolower($funnel['filter_label']) }} başvurusu.</p>
        </div>
        <div class="flex items-center gap-1.5" data-marketing-month>
            <button type="button" wire:click="shift(-1)" class="btn-quiet" aria-label="Önceki ay">‹</button>
            <span class="min-w-[9rem] text-center text-sm font-semibold text-gray-900 dark:text-white">{{ $funnel['span'] > 1 ? $funnel['from']->locale('tr')->isoFormat('MMM').'–'.$monthDate->locale('tr')->isoFormat('MMM YYYY') : $monthLabel }}</span>
            <button type="button" wire:click="shift(1)" class="btn-quiet" aria-label="Sonraki ay" @disabled($isThisMonth)>›</button>
        </div>
    </header>

    <div class="mt-7 flex flex-wrap items-center gap-1.5" role="tablist" aria-label="Pazarlama sekmeleri">
        @foreach (MarketingPage::TABS as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['seg', 'seg-active' => $tab === $key]) role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" data-marketing-tab="{{ $key }}">{{ $label }}</button>
        @endforeach
        <a href="{{ route('operator.social') }}" wire:navigate class="seg" data-marketing-social-link>Gönderi planı →</a>
    </div>

    @if ($message !== '')
        <p class="mt-4 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300" data-marketing-message>{{ $message }}</p>
    @endif

    @if ($tab === 'huni')
        <div class="mt-5 flex flex-wrap items-center gap-1.5" data-marketing-filters>
            @foreach ($filters as $f)
                <button type="button" wire:click="$set('filter', '{{ $f['key'] }}')" @class(['seg h-8 text-[13px]', 'seg-active' => $filter === $f['key']]) data-marketing-filter="{{ $f['key'] }}">{{ $f['label'] }}</button>
            @endforeach
            <span class="mx-1 h-5 w-px bg-gray-200 dark:bg-gray-800"></span>
            @foreach (MarketingFunnel::SPANS as $key => $label)
                <button type="button" wire:click="$set('span', {{ $key }})" @class(['seg h-8 text-[13px]', 'seg-active' => $span === $key]) data-marketing-span="{{ $key }}">{{ $label }}</button>
            @endforeach
        </div>

        <div class="hero-panel mt-4 grid-cols-2 lg:grid-cols-4" data-marketing-strip>
            <div class="hero-tile hero-tile-accent"><span class="hero-label">Başvuru</span><span class="hero-value">{{ $n($t['counted']) }}{{ $t['target'] > 0 ? ' / '.$n($t['target']) : '' }}</span><span class="mt-2 text-xs opacity-75">{{ $t['target'] > 0 ? 'hedefin %'.(int) round($t['counted'] * 100 / $t['target']).'ı' : 'hedef girilmedi' }}{{ $funnel['excluded']['applications'] > 0 && $funnel['excluded']['label'] !== '' ? ' · '.$n($funnel['excluded']['applications']).' sayılmayan' : '' }}</span></div>
            <div class="hero-tile"><span class="hero-label">Konuşma</span><span class="hero-value">{{ $n($t['talked']) }}</span><span class="mt-2 text-xs text-white/60">{{ $t['applications'] > 0 ? 'başvuruların %'.(int) round($t['talked'] * 100 / $t['applications']).'ı' : '—' }}</span></div>
            <div class="hero-tile"><span class="hero-label">Teklif → müşteri</span><span class="hero-value">{{ $n($t['offered']) }} → {{ $n($t['won']) }}</span><span class="mt-2 text-xs text-white/60">{{ $t['offered'] > 0 ? '%'.(int) round($t['won'] * 100 / $t['offered']).' kapanış' : 'teklif yok' }}</span></div>
            <div class="hero-tile"><span class="hero-label">Kazanılan</span><span class="hero-value">{{ $n($t['revenue']) }} ₺</span><span class="mt-2 text-xs text-white/60">aylık, bu dönemde açılan kayıtlardan</span></div>
        </div>

        <div class="card mt-3 overflow-visible" data-marketing-funnel>
            <div class="section-head px-4 pt-4">
                <h2 class="section-title">Kaynak → başvuru → konuşma → teklif → müşteri → ₺</h2>
                @if ($isAdmin && ! $editingTargets)
                    <button type="button" wire:click="editTargets" class="link-neutral text-xs" data-marketing-edit-targets>Hedefi düzenle</button>
                @endif
            </div>
            @if ($editingTargets)
                <form wire:submit="saveTargets" class="mx-4 mt-3 grid grid-cols-2 gap-3 rounded-lg border border-gray-200 p-3 sm:grid-cols-4 dark:border-gray-800" data-marketing-targets-form>
                    @foreach (MarketingFunnel::CHANNELS as $key => $label)
                        @if (! in_array($key, ['unknown', 'outbound'], true))
                            <label class="block text-xs text-gray-500">{{ $label }} / ay<input wire:model="targets.{{ $key }}" type="number" min="0" max="999" class="mt-1 h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm dark:border-gray-700 dark:bg-transparent dark:text-white" /></label>
                        @endif
                    @endforeach
                    <div class="col-span-2 flex items-center gap-2 sm:col-span-4">
                        <button type="submit" class="btn-dark">Kaydet</button>
                        <button type="button" wire:click="$set('editingTargets', false)" class="btn-quiet">Vazgeç</button>
                        <span class="text-xs text-gray-500">Hedef sağlık başvurusu içindir; sayılmayan kanalların hedefi olmaz.</span>
                    </div>
                </form>
            @endif
            @php $cols = 'lg:grid-cols-[minmax(0,2fr)_minmax(0,2.2fr)_5rem_5rem_5rem_7rem]'; @endphp
            <div class="mt-2 hidden border-b border-gray-200 px-4 py-2 text-[11px] uppercase tracking-wide text-gray-500 lg:grid {{ $cols }} dark:border-gray-800">
                <span>Kanal</span><span>Başvuru / hedef</span><span class="text-right">Konuşma</span><span class="text-right">Teklif</span><span class="text-right">Müşteri</span><span class="text-right">₺ / ay</span>
            </div>
            @foreach ($funnel['rows'] as $row)
                @if ($row['target'] > 0 || $row['applications'] > 0)
                    <div class="card-row grid grid-cols-2 items-center gap-x-3 gap-y-1 lg:grid {{ $cols }}" data-marketing-row="{{ $row['key'] }}">
                        <span class="col-span-2 text-sm font-medium text-gray-900 lg:col-span-1 dark:text-white">{{ $row['label'] }}@if (! $row['counts']) <span class="text-xs font-normal text-gray-500">· hedefe sayılmaz</span>@endif</span>
                        <span class="col-span-2 flex items-center gap-2 lg:col-span-1">
                            <span class="tabular-nums text-sm text-gray-900 dark:text-white">{{ $n($row['applications']) }}{{ $row['target'] > 0 ? ' / '.$n($row['target']) : '' }}</span>
                            @if ($row['target'] > 0)
                                @php $pct = min(100, (int) round($row['applications'] * 100 / $row['target'])); @endphp
                                <span class="h-1.5 w-24 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800"><span @class(['block h-full rounded-full', 'bg-success-500' => $pct >= 100, 'bg-accent' => $pct < 100 && $pct >= 50, 'bg-error-500' => $pct < 50]) style="width: {{ $pct }}%"></span></span>
                            @endif
                        </span>
                        <span class="text-xs text-gray-500 lg:hidden">Konuşma</span><span class="tabular-nums text-right text-sm">{{ $n($row['talked']) }}</span>
                        <span class="text-xs text-gray-500 lg:hidden">Teklif</span><span class="tabular-nums text-right text-sm">{{ $n($row['offered']) }}</span>
                        <span class="text-xs text-gray-500 lg:hidden">Müşteri</span><span @class(['tabular-nums text-right text-sm', 'font-semibold text-success-700 dark:text-success-400' => $row['won'] > 0])>{{ $n($row['won']) }}</span>
                        <span class="text-xs text-gray-500 lg:hidden">₺ / ay</span><span class="tabular-nums text-right text-sm">{{ $row['revenue'] > 0 ? $n($row['revenue']).' ₺' : '—' }}</span>
                    </div>
                @endif
            @endforeach
            @if ($t['applications'] === 0)
                <x-ta.empty-state title="Bu dönemde başvuru yok" message="Yeni bir kayıt açıldığında kaynağına göre burada sayılır; kaynağı kayıttaki 'Bizi nereden duydu' alanı belirler." />
            @endif
            @if ($funnel['excluded']['applications'] > 0 && $funnel['excluded']['label'] !== '')
                <p class="border-t border-gray-200 px-4 py-2.5 text-xs text-gray-500 dark:border-gray-800" data-marketing-excluded>{{ $funnel['excluded']['label'] }}: {{ $n($funnel['excluded']['applications']) }} başvuru, hedefe sayılmaz{{ $funnel['excluded']['won'] > 0 ? '; '.$n($funnel['excluded']['won']).' müşteri oldu'.($funnel['excluded']['revenue'] > 0 ? ' ('.$n($funnel['excluded']['revenue']).' ₺/ay)' : '') : '' }}.</p>
            @endif
        </div>

        <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
            <div class="card p-4" data-marketing-summary>
                <h2 class="section-title">{{ $isThisMonth ? 'Bu ay' : $monthDate->locale('tr')->isoFormat('MMMM') }} ne çalıştı</h2>
                <ul class="mt-2 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                    @foreach ($summary as $line)<li>{{ $line }}</li>@endforeach
                </ul>
                <p class="mt-3 text-xs text-gray-500">Her ayın 1'inde geçen ayın özeti telefona düşer. Kazanma-kaybetme desenleri: <a href="{{ route('operator.sales', ['sekme' => 'sonuclar']) }}" wire:navigate class="link-neutral">Satış › Sonuçlar</a>.</p>
            </div>
            <div class="card p-4" data-marketing-won>
                <h2 class="section-title">Müşteri olanlar</h2>
                @forelse ($funnel['won_names'] as $w)
                    <p class="mt-2 text-sm"><a href="{{ route('operator.sales.lead', $w['id']) }}" wire:navigate class="link-neutral">{{ $w['title'] }}</a><span class="text-gray-500"> · {{ $w['channel'] }}{{ $w['fee'] > 0 ? ' · '.$n($w['fee']).' ₺/ay' : '' }}</span></p>
                @empty
                    <p class="mt-2 text-sm text-gray-500">Bu dönemde açılan kayıtlardan henüz müşteri çıkmadı.</p>
                @endforelse
            </div>
        </div>

    @elseif ($tab === 'sosyal' && $social !== null)
        <div class="hero-panel mt-5 grid-cols-2 lg:grid-cols-4" data-marketing-social>
            <div class="hero-tile hero-tile-accent"><span class="hero-label">Sosyalden başvuru</span><span class="hero-value">{{ $n($social['applications']) }}</span><span class="mt-2 text-xs opacity-75">{{ $delta($social['applications'], $social['applications_prev']) }}</span></div>
            <div class="hero-tile"><span class="hero-label">"Instagram'da gördüm" diyen</span><span class="hero-value">{{ $n($social['seen']) }}</span><span class="mt-2 text-xs text-white/60">{{ $delta($social['seen'], $social['seen_prev']) }}</span></div>
            <div class="hero-tile"><span class="hero-label">Yayınlanan gönderi</span><span class="hero-value">{{ $n($social['published']) }}</span><span class="mt-2 text-xs text-white/60">{{ $delta($social['published'], $social['published_prev']) }}</span></div>
            <div class="hero-tile"><span class="hero-label">Onay bekleyen</span><span class="hero-value">{{ $n($social['drafts']) }}</span><span class="mt-2 text-xs text-white/60"><a href="{{ route('operator.social') }}" wire:navigate class="underline">Gönderi planı →</a></span></div>
        </div>
        <div class="card mt-3 p-4">
            <h2 class="section-title">Ölçü</h2>
            <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">Sosyal medyanın ölçüsü takipçi değil: sosyalden gelen başvuru ve görüşmede "Instagram'da gördüm" diyen kayıt sayısı. Sosyalin rolü güven ve hatırlatma; onaylı plan değişmez.</p>
            @if ($social['by_pillar'] !== [])
                <p class="mt-3 flex flex-wrap gap-1.5">@foreach ($social['by_pillar'] as $pillar => $count)<span class="chip chip-flat">{{ ucfirst($pillar) }} {{ $count }}</span>@endforeach</p>
            @endif
        </div>

    @elseif ($tab === 'reklam' && $ads !== null)
        <div class="mt-5 grid grid-cols-1 gap-3 lg:grid-cols-2" data-marketing-ads>
            <div class="card p-4" data-marketing-meta>
                <div class="section-head"><h2 class="section-title">Meta reklamı</h2>@if ($ads['meta']['connected'])<span class="text-xs text-gray-500">son gün {{ $ads['meta']['last_day'] }}</span>@endif</div>
                @if ($ads['meta']['connected'])
                    <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                        <div><dt class="text-xs text-gray-500">Harcama</dt><dd class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $money($ads['meta']['spend']) }}</dd><dd class="text-xs text-gray-500">{{ $delta((int) round($ads['meta']['spend']), (int) round($ads['meta']['spend_prev'])) }}</dd></div>
                        <div><dt class="text-xs text-gray-500">Mesaj + form</dt><dd class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $n($ads['meta']['messages']) }} + {{ $n($ads['meta']['leads']) }}</dd><dd class="text-xs text-gray-500">{{ $ads['meta']['cost'] !== null ? 'sonuç başına '.$money($ads['meta']['cost']) : 'sonuç yok' }}</dd></div>
                        <div><dt class="text-xs text-gray-500">Kayda dönen başvuru</dt><dd class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $n($ads['applications']['meta']) }}</dd><dd class="text-xs text-gray-500">{{ $delta($ads['applications']['meta'], $ads['applications']['meta_prev']) }}</dd></div>
                    </dl>
                @else
                    <p class="mt-2 text-sm text-gray-500">Moximu'nun Meta reklam hesabı bağlı değil ya da henüz veri gelmedi. Kayda dönen başvuru: {{ $n($ads['applications']['meta']) }} ({{ $delta($ads['applications']['meta'], $ads['applications']['meta_prev']) }}).</p>
                @endif
            </div>
            <div class="card p-4" data-marketing-google>
                <div class="section-head"><h2 class="section-title">Google Ads</h2>@if ($ads['google']['connected'])<span class="text-xs text-gray-500">son gün {{ $ads['google']['last_day'] }}</span>@endif</div>
                @if ($ads['google']['connected'])
                    <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                        <div><dt class="text-xs text-gray-500">Harcama</dt><dd class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $money($ads['google']['cost']) }}</dd><dd class="text-xs text-gray-500">{{ $delta((int) round($ads['google']['cost']), (int) round($ads['google']['cost_prev'])) }}</dd></div>
                        <div><dt class="text-xs text-gray-500">Tıklama / dönüşüm</dt><dd class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $n($ads['google']['clicks']) }} / {{ $n($ads['google']['conversions']) }}</dd></div>
                        <div><dt class="text-xs text-gray-500">Kayda dönen başvuru</dt><dd class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $n($ads['applications']['google_ads']) }}</dd><dd class="text-xs text-gray-500">{{ $delta($ads['applications']['google_ads'], $ads['applications']['google_ads_prev']) }}</dd></div>
                    </dl>
                @else
                    <p class="mt-2 text-sm text-gray-500">Google Ads hesabı bağlı değil ya da harcama yok. Kayda dönen başvuru: {{ $n($ads['applications']['google_ads']) }}.</p>
                @endif
            </div>
        </div>
        <p class="mt-3 text-xs text-gray-500">Reklam hesaplarına buradan yazılmaz; bütçe ve hedefleme önerileri Meta ve Google Ads masalarında.</p>

    @elseif ($tab === 'site' && $site !== null)
        <div class="hero-panel mt-5 grid-cols-2 lg:grid-cols-4" data-marketing-site>
            <div class="hero-tile hero-tile-accent"><span class="hero-label">Siteden başvuru</span><span class="hero-value">{{ $n($site['applications']) }}</span><span class="mt-2 text-xs opacity-75">{{ $delta($site['applications'], $site['applications_prev']) }}</span></div>
            <div class="hero-tile"><span class="hero-label">Google tıklaması</span><span class="hero-value">{{ $site['connected'] ? $n($site['clicks']) : '—' }}</span><span class="mt-2 text-xs text-white/60">{{ $site['connected'] ? $delta($site['clicks'], $site['clicks_prev']) : 'Search Console bağlı değil' }}</span></div>
            <div class="hero-tile"><span class="hero-label">Gösterim</span><span class="hero-value">{{ $site['connected'] ? $n($site['impressions']) : '—' }}</span><span class="mt-2 text-xs text-white/60">{{ $site['connected'] ? $delta($site['impressions'], $site['impressions_prev']) : '' }}</span></div>
            <div class="hero-tile"><span class="hero-label">Tıklama → başvuru</span><span class="hero-value">{{ $site['connected'] && $site['clicks'] > 0 ? '%'.number_format($site['applications'] * 100 / $site['clicks'], 1, ',', '.') : '—' }}</span><span class="mt-2 text-xs text-white/60">{{ $site['last_day'] !== null ? 'son gün '.$site['last_day'] : '' }}</span></div>
        </div>
        <div class="card mt-3 p-4" data-marketing-queries>
            <h2 class="section-title">En çok tıklanan aramalar</h2>
            @forelse ($site['queries'] as $q)
                <p class="mt-1.5 flex items-center justify-between gap-3 text-sm"><span class="truncate text-gray-900 dark:text-white">{{ $q['query'] }}</span><span class="shrink-0 tabular-nums text-gray-500">{{ $n($q['clicks']) }} tık · {{ $n($q['impressions']) }} gösterim</span></p>
            @empty
                <p class="mt-2 text-sm text-gray-500">{{ $site['connected'] ? 'Bu ay sorgu verisi yok.' : 'Moximu sitesinin Search Console bağlantısı yok; bağlanınca aramalar burada görünür.' }}</p>
            @endforelse
            @if ($site['brand'] !== null)
                <p class="mt-3 text-xs text-gray-500">Sitenin içerik ve teknik durumu: menüde <strong>Web sitemiz</strong>.</p>
            @endif
        </div>
    @endif
</div>
