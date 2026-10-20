@php
    $a = $analysis;
    $types = \App\Services\Meta\MetaCampaignBoard::TYPES;
    $count = fn ($value) => $value === null ? '—' : ((float) $value >= 10000 ? $num((float) $value / 1000, 1).' bin' : $num($value, (float) $value == floor((float) $value) ? 0 : 1));
    $shade = fn (float $alpha): string => 'background-color: rgba(17, 24, 39, '.number_format(max(0.06, min(1, $alpha)), 2, '.', '').');'.($alpha > 0.55 ? ' color: #fff;' : '');
    $date = fn (string $d): string => \Carbon\CarbonImmutable::parse($d)->locale('tr')->translatedFormat('d M');
    $geoRunning = ($geoState['state'] ?? null) === 'running';
@endphp

@if ($a === null)
    <x-ta.empty-state size="sm" title="Veri yok." />
@else
    <div class="flex flex-wrap items-end gap-3" data-testid="meta-analysis-filters">
        <label class="w-full text-xs text-gray-500 sm:w-auto sm:min-w-[14rem]">Neye göre
            <select wire:model.live="focus" class="mt-1 block w-full rounded-lg border-gray-200 py-1.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                <option value="">Tüm hesap</option>
                @if ($a['options']['services'] !== [])
                    <optgroup label="Hizmet">
                        @foreach ($a['options']['services'] as $o)<option value="service:{{ $o['id'] }}">{{ $o['name'] }}</option>@endforeach
                    </optgroup>
                @endif
                <optgroup label="Kampanya">
                    @foreach ($a['options']['campaigns'] as $o)<option value="campaign:{{ $o['id'] }}">{{ $o['name'] }}</option>@endforeach
                </optgroup>
            </select>
        </label>
        <span class="flex-1"></span>
        <div class="text-right text-xs text-gray-500">
            <p>{{ $a['focus_label'] }} · {{ $date($a['window']['from']) }} – {{ $date($a['window']['to']) }}</p>
            <p>karşılaştırma: {{ $date($a['window']['cmp_from']) }} – {{ $date($a['window']['cmp_to']) }}</p>
        </div>
    </div>

    @php
        $k = $a['kpis'];
        $tiles = [
            ['Harcama', $money($k['spend']['value']), null, $k['spend']['change'], false],
            ['Form', $count($k['leads']['value']), 'form başı '.$money($k['leads']['cost']), $k['leads']['change'], true],
            ['Mesaj', $count($k['messages']['value']), 'mesaj başı '.$money($k['messages']['cost']), $k['messages']['change'], true],
            ['Satış', $count($k['purchases']['value']), 'satış başı '.$money($k['purchases']['cost']), $k['purchases']['change'], true],
        ];
    @endphp
    <div class="grid grid-cols-2 gap-x-8 gap-y-6 lg:grid-cols-4" data-testid="meta-analysis-kpis">
        @foreach ($tiles as [$label, $value, $sub, $change, $moreIsGood])
            <x-ta.metric-card :label="$label" :value="$value" :hint="$sub"
                :delta="$change === null ? 'karşılaştırma yok' : $pct($change)"
                :tone="$change === null || ! $moreIsGood ? 'neutral' : ($change > 0 ? 'positive' : ($change < 0 ? 'negative' : 'neutral'))" />
        @endforeach
    </div>

    <div class="grid gap-10 xl:grid-cols-3">
        <section class="{{ $card }} xl:col-span-2" data-testid="meta-analysis-weekly">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 class="section-title">Harcama ve sonuç · haftalık</h2>
                <p class="text-xs text-gray-500">Çubuk harcama, çizgi sonuç (form + mesaj + satış)</p>
            </div>
            @php
                $weeks = $a['weekly'];
                $wMax = max(1, max(array_column($weeks, 'spend') ?: [0]));
                $rMax = max(1, max(array_column($weeks, 'results') ?: [0]));
                $n = max(1, count($weeks));
                $W = 640; $H = 180; $step = $W / $n; $bar = max(4, $step * 0.55);
                $points = [];
                foreach ($weeks as $i => $wk) {
                    $points[] = round($i * $step + $step / 2, 1).','.round($H - 20 - ($wk['results'] / $rMax) * ($H - 40), 1);
                }
            @endphp
            @if ($weeks === [] || array_sum(array_column($weeks, 'spend')) <= 0)
                <p class="mt-4 text-sm text-gray-500">Bu dönemde harcama yok.</p>
            @else
                <svg viewBox="0 0 {{ $W }} {{ $H }}" class="mt-3 h-48 w-full" role="img" aria-label="Haftalık harcama ve sonuç">
                    @foreach ($weeks as $i => $wk)
                        @php $bh = ($wk['spend'] / $wMax) * ($H - 40); @endphp
                        <rect x="{{ round($i * $step + ($step - $bar) / 2, 1) }}" y="{{ round($H - 20 - $bh, 1) }}" width="{{ round($bar, 1) }}" height="{{ round($bh, 1) }}" rx="3" class="fill-brand-100 dark:fill-brand-500/30"><title>{{ $date($wk['week']) }} haftası, {{ $money($wk['spend']) }}, {{ $count($wk['results']) }} sonuç</title></rect>
                        @if ($n <= 14 || $i % 2 === 0)
                            <text x="{{ round($i * $step + $step / 2, 1) }}" y="{{ $H - 4 }}" text-anchor="middle" class="fill-gray-500 text-xs">{{ $date($wk['week']) }}</text>
                        @endif
                    @endforeach
                    <polyline points="{{ implode(' ', $points) }}" fill="none" stroke-width="2.5" stroke-linejoin="round" class="stroke-brand-600 dark:stroke-brand-400" />
                    @foreach ($points as $p)
                        @php [$px, $py] = explode(',', $p); @endphp
                        <circle cx="{{ $px }}" cy="{{ $py }}" r="3" class="fill-brand-600 dark:fill-brand-400" />
                    @endforeach
                </svg>
            @endif
        </section>

        <section class="{{ $card }}" data-testid="meta-analysis-types">
            <h2 class="section-title" title="Her kampanya kendi sonuç türüyle sayılır; türler toplanmaz.">Sonuç türüne göre maliyet</h2>
            <ul class="mt-2 divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                @forelse ($a['types'] as $t)
                    <li class="flex items-baseline justify-between gap-3 py-2.5">
                        <span class="text-sm text-gray-700 dark:text-gray-300">{{ ucfirst($types[$t['key']][1]) }} <span class="ml-1 text-xs text-gray-500">{{ $count($t['count']) }} {{ $t['label'] }}</span></span>
                        <span class="text-sm font-semibold tabular-nums text-gray-900 dark:text-white">{{ $money($t['cost']) }}</span>
                    </li>
                @empty
                    <li class="py-2.5 text-sm text-gray-500">Bu dönemde sonuç yok.</li>
                @endforelse
            </ul>
        </section>
    </div>

    @if (! $a['has_breakdowns'] || $a['regions'] === [])
        <div class="flex flex-wrap items-center gap-3 text-sm text-gray-500 dark:text-gray-400">
            <span class="flex-1" title="Bölge, yaş / cinsiyet, saat, yerleşim ve cihaz kırılımları her gün çekilir.">Bu dönem için kırılım verisi eksik; şimdi çekebilirsin (son 90 gün).</span>
            <button type="button" wire:click="collectGeoResults" @disabled(! $bound || $geoRunning) class="{{ $btn }}" @if ($geoRunning) wire:poll.15s @endif>{{ $geoRunning ? 'Çekiliyor…' : 'Kırılım verisini çek' }}</button>
        </div>
    @endif

    <div class="grid gap-10 xl:grid-cols-2">
        <section class="{{ $panel }} overflow-x-auto" data-testid="meta-analysis-regions">
            <h2 class="section-title" title="Kalın satır ülke, altındakiler o ülkenin eyalet / il bölgeleri. Meta şehir değil bölge verir; yer reklamın gösterildiği yerdir.">Ülke ve bölge</h2>
            @if ($a['regions'] === [])
                <p class="py-4 text-sm text-gray-500">Veri yok.</p>
            @else
                <div class="mt-2 overflow-x-auto"><table class="w-full min-w-[30rem] text-sm">
                    <thead><tr class="text-left text-xs text-gray-500"><th class="py-2 pr-4 font-normal">Ülke / bölge</th><th class="{{ $th }}">Harcama</th><th class="{{ $th }}">Sonuç</th><th class="{{ $th }}">Sonuç başı</th></tr></thead>
                    @foreach ($a['regions'] as $country)
                        <tbody class="border-t border-gray-200 tabular-nums dark:border-gray-800" data-testid="meta-analysis-country">
                            <tr class="font-medium text-gray-900 dark:text-white"><td class="py-2 pr-4">{{ $country['name'] }} <span class="text-xs font-normal text-gray-500">{{ $country['country'] }}</span></td><td class="{{ $td }}">{{ $money($country['spend']) }}</td><td class="{{ $td }}">{{ $count($country['results']) }}</td><td class="{{ $td }}">{{ $money($country['cpr']) }}</td></tr>
                            @foreach ($country['regions'] as $row)
                                <tr class="text-gray-500 dark:text-gray-400"><td class="py-1 pl-6 pr-4">{{ $row['region'] }}</td><td class="{{ $td }}">{{ $money($row['spend']) }}</td><td class="{{ $td }}">{{ $count($row['results']) }}</td><td class="{{ $td }}">{{ $money($row['cpr']) }}</td></tr>
                            @endforeach
                        </tbody>
                    @endforeach
                </table></div>
            @endif
        </section>

        @php $ag = $a['age_gender']; @endphp
        <section class="{{ $card }}" data-testid="meta-analysis-age-gender">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="section-title">Yaş × cinsiyet · {{ $types[$a['type']][1] }}</h2>
                <div class="flex gap-1">
                    @foreach (['leads', 'messages', 'purchases'] as $key)
                        <button type="button" wire:click="$set('analysisType', '{{ $key }}')" @class(['rounded-md px-2 py-0.5 text-xs font-medium', 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $a['type'] === $key, 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5' => $a['type'] !== $key])>{{ ucfirst($types[$key][0]) }}</button>
                    @endforeach
                </div>
            </div>
            <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                <span class="rounded px-1.5 py-0.5 tone-ok">ucuz</span><span class="rounded px-1.5 py-0.5 tone-look">orta</span><span class="rounded px-1.5 py-0.5 tone-critical">pahalı</span>
                <span>{{ \App\Services\Meta\MetaAnalysis::MIN_SLICE_RESULTS }} sonuçtan azı gri: karar için az veri</span>
            </p>
            @if ($ag['min'] === null)
                <p class="mt-4 text-sm text-gray-500">Bu türde yaş / cinsiyet verisi yok.</p>
            @else
                <table class="mt-3 w-full table-fixed text-xs">
                    <thead><tr class="text-gray-500"><th class="w-16"></th>@foreach (\App\Services\Meta\MetaAnalysis::AGES as $age)<th class="pb-1 font-medium">{{ str_replace('-', '–', $age) }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach (\App\Services\Meta\MetaAnalysis::GENDERS as $gender => $label)
                            <tr>
                                <th class="pr-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ $label }}</th>
                                @foreach (\App\Services\Meta\MetaAnalysis::AGES as $age)
                                    @php $cell = $ag['cells'][$age][$gender]; @endphp
                                    <td class="p-0.5">
                                        @if ($cell['cost'] === null)
                                            <div class="rounded-md bg-gray-50 py-2.5 text-center tabular-nums text-gray-500 dark:bg-white/[0.03]" title="{{ $money($cell['spend']) }} harcama, sonuç yok">—</div>
                                        @else
                                            <div @class(['rounded-md py-1.5 text-center tabular-nums', $cell['tone'] === null ? 'bg-gray-50 text-gray-500 dark:bg-white/[0.03] dark:text-gray-400' : 'font-medium tone-'.$cell['tone']]) data-tone="{{ $cell['tone'] ?? 'few' }}" title="{{ $money($cell['spend']) }} harcama, {{ $count($cell['count']) }} {{ $types[$a['type']][0] }}">
                                                {{ $num($cell['cost'], 0) }}
                                                <span class="block text-xs font-normal opacity-75">{{ $count($cell['count']) }} {{ $types[$a['type']][0] }}</span>
                                            </div>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($ag['best'])
                    <p class="mt-3 text-sm text-gray-700 dark:text-gray-300">En iyi dilim: <span class="font-medium">{{ str_replace('-', '–', $ag['best']['age']) }} {{ mb_strtolower($ag['best']['gender']) }}</span>, {{ $money($ag['best']['cost']) }}.@if ($ag['worst']) En pahalı: <span class="font-medium">{{ str_replace('-', '–', $ag['worst']['age']) }} {{ mb_strtolower($ag['worst']['gender']) }}</span>, {{ $money($ag['worst']['cost']) }}.@endif</p>
                @else
                    <p class="mt-3 text-xs text-gray-500">En iyi / en pahalı dilim için her dilimde en az {{ \App\Services\Meta\MetaAnalysis::MIN_SLICE_RESULTS }} sonuç gerekir.</p>
                @endif
            @endif
        </section>
    </div>

    <div class="grid gap-10 xl:grid-cols-2">
        @php $hours = $a['hours']; @endphp
        <section class="{{ $card }}" data-testid="meta-analysis-hours">
            <h2 class="section-title">Gün × saat · sonuç sayısı</h2>
            <p class="mt-0.5 text-xs text-gray-500">Koyu daha çok sonuç, hesap saat diliminde</p>
            @if ($hours['max'] <= 0)
                <p class="mt-4 text-sm text-gray-500">Saat verisi yok.</p>
            @else
                <table class="mt-3 w-full table-fixed text-xs">
                    <thead><tr class="text-gray-500"><th class="w-12"></th>@foreach (\App\Services\Meta\MetaAnalysis::HOUR_BANDS as $band)<th class="pb-1 font-medium">{{ $band }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach (\App\Services\Meta\MetaAnalysis::WEEKDAYS as $weekday => $label)
                            <tr>
                                <th class="pr-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ $label }}</th>
                                @foreach ($hours['grid'][$weekday] as $value)
                                    <td class="p-0.5"><div class="rounded-md py-2 text-center tabular-nums text-gray-900" style="{{ $value > 0 ? $shade($value / $hours['max']) : '' }}">{{ $value > 0 ? $num($value, 0) : '' }}</div></td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section class="{{ $card }}" data-testid="meta-analysis-placements">
            <h2 class="section-title">Yerleşim ve cihaz</h2>
            <div class="mt-3 grid gap-5 sm:grid-cols-2">
                @foreach (['Yerleşim · sonuç payı' => $a['placements'], 'Cihaz · sonuç payı' => $a['devices']] as $title => $rows)
                    <div>
                        <p class="mb-2 text-xs text-gray-500">{{ $title }}</p>
                        @forelse ($rows as $row)
                            <div class="mb-2">
                                <div class="flex justify-between gap-2 text-xs text-gray-700 dark:text-gray-300"><span class="truncate">{{ $row['label'] }}</span><span class="tabular-nums">{{ $num($row['share'], 0) }}%</span></div>
                                <div class="mt-1 h-1.5 rounded-full bg-gray-100 dark:bg-white/[0.06]"><div class="h-1.5 rounded-full bg-brand-500" style="width: {{ $row['share'] }}%"></div></div>
                                <p class="mt-0.5 text-xs text-gray-500">harcamanın %{{ $num($row['spend_share'], 0) }}'i</p>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">Veri yok.</p>
                        @endforelse
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    <section class="{{ $card }}" data-testid="meta-analysis-interests">
        <h2 class="section-title" title="Burada her reklam setinin hedeflediği ilgi alanları, o setin sonucuyla yan yana durur.">İlgi alanları</h2>
        <p class="mt-0.5 text-xs text-gray-500">Meta ilgi alanı başına sonuç vermez.</p>
        <div class="mt-2 divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
            @forelse ($a['interests'] as $set)
                <div class="flex flex-wrap items-start gap-3 py-2.5">
                    <div class="w-full min-w-0 sm:w-64 sm:shrink-0">
                        <p class="truncate text-sm font-medium text-gray-900 dark:text-white" title="{{ $set['set'] }}">{{ $set['set'] }}</p>
                        <p class="text-xs text-gray-500">{{ $count($set['results']) }} {{ $set['label'] }}, {{ $types[$set['type']][1] }} {{ $money($set['cost']) }}</p>
                    </div>
                    <p class="min-w-0 flex-1 text-sm text-gray-700 dark:text-gray-300">
                        @if (array_merge($set['interests'], $set['audiences']) !== []){{ implode(', ', array_merge($set['interests'], $set['audiences'])) }}@else<span class="text-xs text-gray-500">İlgi alanı yok (geniş kitle)</span>@endif
                    </p>
                </div>
            @empty
                <p class="py-2 text-sm text-gray-500">Bu dönemde harcaması olan reklam seti yok.</p>
            @endforelse
        </div>
    </section>
@endif
