@php
    $a = $analysis;
    $types = \App\Services\Meta\MetaCampaignBoard::TYPES;
    $count = fn ($value) => $value === null ? '—' : ((float) $value >= 10000 ? $num((float) $value / 1000, 1).' bin' : $num($value, (float) $value == floor((float) $value) ? 0 : 1));
    $shade = fn (float $alpha): string => 'background-color: rgba(17, 24, 39, '.number_format(max(0.06, min(1, $alpha)), 2, '.', '').');'.($alpha > 0.55 ? ' color: #fff;' : '');
    $date = fn (string $d): string => \Carbon\CarbonImmutable::parse($d)->locale('tr')->translatedFormat('d M');
    $geoRunning = ($geoState['state'] ?? null) === 'running';
    /** Panel concept: every block is a section head over a white card; callers' $card / $panel / $btn / $th / $td are no longer needed. */
    $cardBody = 'card p-4 sm:p-5';
@endphp

@if ($a === null)
    <div class="card"><x-ta.empty-state icon="none" size="sm" title="Veri yok." /></div>
@else
    <div class="space-y-10">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2" data-testid="meta-analysis-filters">
            <label class="w-full sm:w-auto"><span class="sr-only">Neye göre</span>
                <select wire:model.live="focus" class="h-9 w-full rounded-lg border border-gray-200 bg-white pl-3 pr-8 text-sm text-gray-900 sm:w-auto sm:min-w-[16rem] sm:max-w-[22rem] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
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
            <div class="min-w-0 text-[13px] text-gray-500 sm:ml-auto sm:text-right">
                <p class="break-words">{{ $a['focus_label'] }}, {{ $date($a['window']['from']) }} – {{ $date($a['window']['to']) }}</p>
                <p class="text-xs">karşılaştırma: {{ $date($a['window']['cmp_from']) }} – {{ $date($a['window']['cmp_to']) }}</p>
            </div>
        </div>

        @php
            $k = $a['kpis'];
            $tiles = [
                ['Harcama', $money($k['spend']['value']), null, $k['spend']['change'], false],
                ['Form', $count($k['leads']['value']), 'Form başı '.$money($k['leads']['cost']), $k['leads']['change'], true],
                ['Mesaj', $count($k['messages']['value']), 'Mesaj başı '.$money($k['messages']['cost']), $k['messages']['change'], true],
                ['Satış', $count($k['purchases']['value']), 'Satış başı '.$money($k['purchases']['cost']), $k['purchases']['change'], true],
            ];
        @endphp
        <div class="metric-strip grid-cols-2 lg:grid-cols-4" data-testid="meta-analysis-kpis">
            @foreach ($tiles as [$label, $value, $sub, $change, $moreIsGood])
                <x-ta.metric-card :label="$label" :value="$value" :hint="$sub"
                    :delta="$change === null ? 'karşılaştırma yok' : $pct($change)"
                    :tone="$change === null || ! $moreIsGood ? 'neutral' : ($change > 0 ? 'positive' : ($change < 0 ? 'negative' : 'neutral'))" />
            @endforeach
        </div>

        <div class="grid gap-10 xl:grid-cols-3">
            <section class="min-w-0 xl:col-span-2" data-testid="meta-analysis-weekly">
                <div class="section-head">
                    <h2 class="section-title">Harcama ve sonuç</h2>
                    <span class="text-[13px] text-gray-500">haftalık, çubuk harcama, çizgi sonuç (form + mesaj + satış)</span>
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
                <div class="{{ $cardBody }}">
                    @if ($weeks === [] || array_sum(array_column($weeks, 'spend')) <= 0)
                        <p class="text-sm text-gray-500">Bu dönemde harcama yok.</p>
                    @else
                        <svg viewBox="0 0 {{ $W }} {{ $H }}" class="h-48 w-full" role="img" aria-label="Haftalık harcama ve sonuç">
                            @foreach ($weeks as $i => $wk)
                                @php $bh = ($wk['spend'] / $wMax) * ($H - 40); @endphp
                                <rect x="{{ round($i * $step + ($step - $bar) / 2, 1) }}" y="{{ round($H - 20 - $bh, 1) }}" width="{{ round($bar, 1) }}" height="{{ round($bh, 1) }}" rx="3" class="fill-gray-200 dark:fill-white/10"><title>{{ $date($wk['week']) }} haftası, {{ $money($wk['spend']) }}, {{ $count($wk['results']) }} sonuç</title></rect>
                                @if ($n <= 14 || $i % 2 === 0)
                                    <text x="{{ round($i * $step + $step / 2, 1) }}" y="{{ $H - 4 }}" text-anchor="middle" class="fill-gray-500 text-xs">{{ $date($wk['week']) }}</text>
                                @endif
                            @endforeach
                            <polyline points="{{ implode(' ', $points) }}" fill="none" stroke-width="2" stroke-linejoin="round" class="stroke-gray-900 dark:stroke-white" />
                            @foreach ($points as $p)
                                @php [$px, $py] = explode(',', $p); @endphp
                                <circle cx="{{ $px }}" cy="{{ $py }}" r="3" class="fill-gray-900 dark:fill-white" />
                            @endforeach
                        </svg>
                    @endif
                </div>
            </section>

            <section class="min-w-0" data-testid="meta-analysis-types">
                <div class="section-head"><h2 class="section-title" title="Her kampanya kendi sonuç türüyle sayılır; türler toplanmaz.">Sonuç türüne göre maliyet</h2></div>
                <ul class="card">
                    @forelse ($a['types'] as $t)
                        <li class="card-row justify-between">
                            <span class="min-w-0 text-sm text-gray-900 dark:text-gray-200">{{ ucfirst($types[$t['key']][1]) }} <span class="ml-1 text-xs text-gray-500">{{ $count($t['count']) }} {{ $t['label'] }}</span></span>
                            <span class="text-sm font-semibold tabular-nums text-gray-900 dark:text-white">{{ $money($t['cost']) }}</span>
                        </li>
                    @empty
                        <li class="card-row text-sm text-gray-500">Bu dönemde sonuç yok.</li>
                    @endforelse
                </ul>
            </section>
        </div>

        @if (! $a['has_breakdowns'] || $a['regions'] === [])
            <div class="card">
                <div class="card-row flex-wrap sm:flex-nowrap">
                    <span class="min-w-0 flex-1 text-[13px] text-gray-500 dark:text-gray-400" title="Bölge, yaş / cinsiyet, saat, yerleşim ve cihaz kırılımları her gün çekilir.">Bu dönem için kırılım verisi eksik; şimdi çekebilirsin (son 90 gün).</span>
                    <button type="button" wire:click="collectGeoResults" @disabled(! $bound || $geoRunning) class="btn w-full sm:w-auto" @if ($geoRunning) wire:poll.15s @endif>{{ $geoRunning ? 'Çekiliyor…' : 'Kırılım verisini çek' }}</button>
                </div>
            </div>
        @endif

        <div class="grid gap-10 xl:grid-cols-2">
            <section class="min-w-0" data-testid="meta-analysis-regions">
                <div class="section-head"><h2 class="section-title" title="Kalın satır ülke, altındakiler o ülkenin eyalet / il bölgeleri. Meta şehir değil bölge verir; yer reklamın gösterildiği yerdir.">Ülke ve bölge</h2></div>
                @if ($a['regions'] === [])
                    <div class="card"><x-ta.empty-state icon="none" size="sm" title="Veri yok." /></div>
                @else
                    <div class="card overflow-x-auto">
                        <table class="data-table min-w-[30rem]">
                            <thead><tr><th>Ülke / bölge</th><th class="text-right!">Harcama</th><th class="text-right!">Sonuç</th><th class="text-right!">Sonuç başı</th></tr></thead>
                            @foreach ($a['regions'] as $country)
                                <tbody class="tabular-nums" data-testid="meta-analysis-country">
                                    <tr class="font-medium text-gray-900 dark:text-white"><td>{{ $country['name'] }} <span class="text-xs font-normal text-gray-500">{{ $country['country'] }}</span></td><td class="whitespace-nowrap text-right">{{ $money($country['spend']) }}</td><td class="text-right">{{ $count($country['results']) }}</td><td class="whitespace-nowrap text-right">{{ $money($country['cpr']) }}</td></tr>
                                    @foreach ($country['regions'] as $row)
                                        <tr class="text-[13px] text-gray-500 dark:text-gray-400"><td class="py-2! pl-8!">{{ $row['region'] }}</td><td class="whitespace-nowrap py-2! text-right">{{ $money($row['spend']) }}</td><td class="py-2! text-right">{{ $count($row['results']) }}</td><td class="whitespace-nowrap py-2! text-right">{{ $money($row['cpr']) }}</td></tr>
                                    @endforeach
                                </tbody>
                            @endforeach
                        </table>
                    </div>
                @endif
            </section>

            @php $ag = $a['age_gender']; @endphp
            <section class="min-w-0" data-testid="meta-analysis-age-gender">
                <div class="section-head">
                    <h2 class="section-title">Yaş × cinsiyet</h2>
                    <span class="text-[13px] text-gray-500">{{ $types[$a['type']][1] }}</span>
                    <div class="inline-flex gap-0.5 rounded-lg border border-gray-200 bg-gray-50 p-0.5 sm:ml-auto dark:border-gray-800 dark:bg-white/[0.03]" role="group" aria-label="Sonuç türü">
                        @foreach (['leads', 'messages', 'purchases'] as $key)
                            <button type="button" wire:click="$set('analysisType', '{{ $key }}')" @if ($a['type'] === $key) aria-pressed="true" @endif @class(['rounded-md px-2.5 py-0.5 text-xs font-medium', 'bg-white text-gray-900 shadow-card dark:bg-gray-800 dark:text-white' => $a['type'] === $key, 'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white' => $a['type'] !== $key])>{{ ucfirst($types[$key][0]) }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="{{ $cardBody }}">
                    <p class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                        <span class="rounded px-1.5 py-0.5 tone-ok">ucuz</span><span class="rounded px-1.5 py-0.5 tone-look">orta</span><span class="rounded px-1.5 py-0.5 tone-critical">pahalı</span>
                        <span>{{ \App\Services\Meta\MetaAnalysis::MIN_SLICE_RESULTS }} sonuçtan azı gri: karar için az veri</span>
                    </p>
                    @if ($ag['min'] === null)
                        <p class="mt-4 text-sm text-gray-500">Bu türde yaş / cinsiyet verisi yok.</p>
                    @else
                        <table class="mt-3 w-full table-fixed text-xs">
                            <thead><tr class="text-gray-500"><th class="w-14"></th>@foreach (\App\Services\Meta\MetaAnalysis::AGES as $age)<th class="pb-1 font-medium">{{ str_replace('-', '–', $age) }}</th>@endforeach</tr></thead>
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
                            <p class="mt-3 text-[13px] text-gray-700 dark:text-gray-300">En iyi dilim: <span class="font-medium">{{ str_replace('-', '–', $ag['best']['age']) }} {{ mb_strtolower($ag['best']['gender']) }}</span>, {{ $money($ag['best']['cost']) }}.@if ($ag['worst']) En pahalı: <span class="font-medium">{{ str_replace('-', '–', $ag['worst']['age']) }} {{ mb_strtolower($ag['worst']['gender']) }}</span>, {{ $money($ag['worst']['cost']) }}.@endif</p>
                        @else
                            <p class="mt-3 text-xs text-gray-500">En iyi / en pahalı dilim için her dilimde en az {{ \App\Services\Meta\MetaAnalysis::MIN_SLICE_RESULTS }} sonuç gerekir.</p>
                        @endif
                    @endif
                </div>
            </section>
        </div>

        <div class="grid gap-10 xl:grid-cols-2">
            @php $hours = $a['hours']; @endphp
            <section class="min-w-0" data-testid="meta-analysis-hours">
                <div class="section-head">
                    <h2 class="section-title">Gün × saat</h2>
                    <span class="text-[13px] text-gray-500">sonuç sayısı, koyu daha çok, hesap saat diliminde</span>
                </div>
                <div class="{{ $cardBody }}">
                    @if ($hours['max'] <= 0)
                        <p class="text-sm text-gray-500">Saat verisi yok.</p>
                    @else
                        <table class="w-full table-fixed text-xs">
                            <thead><tr class="text-gray-500"><th class="w-10"></th>@foreach (\App\Services\Meta\MetaAnalysis::HOUR_BANDS as $band)<th class="pb-1 font-medium">{{ $band }}</th>@endforeach</tr></thead>
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
                </div>
            </section>

            <section class="min-w-0" data-testid="meta-analysis-placements">
                <div class="section-head"><h2 class="section-title">Yerleşim ve cihaz</h2><span class="text-[13px] text-gray-500">sonuç payı</span></div>
                <div class="{{ $cardBody }} grid gap-6 sm:grid-cols-2">
                    @foreach (['Yerleşim' => $a['placements'], 'Cihaz' => $a['devices']] as $title => $rows)
                        <div class="min-w-0">
                            <p class="mb-2 text-xs text-gray-500">{{ $title }}</p>
                            @forelse ($rows as $row)
                                <div class="mb-2.5">
                                    <div class="flex justify-between gap-2 text-[13px] text-gray-900 dark:text-gray-200"><span class="truncate">{{ $row['label'] }}</span><span class="tabular-nums">{{ $num($row['share'], 0) }}%</span></div>
                                    <div class="mt-1 h-1.5 rounded-full bg-gray-100 dark:bg-white/[0.06]"><div class="h-1.5 rounded-full bg-gray-900 dark:bg-white" style="width: {{ $row['share'] }}%"></div></div>
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

        <section data-testid="meta-analysis-interests">
            <div class="section-head">
                <h2 class="section-title" title="Burada her reklam setinin hedeflediği ilgi alanları, o setin sonucuyla yan yana durur.">İlgi alanları</h2>
                <span class="text-[13px] text-gray-500">Meta ilgi alanı başına sonuç vermez.</span>
            </div>
            <ul class="card">
                @forelse ($a['interests'] as $set)
                    <li class="card-row flex-wrap items-start sm:flex-nowrap">
                        <div class="w-full min-w-0 sm:w-64 sm:shrink-0">
                            <p class="truncate text-sm font-medium text-gray-900 dark:text-white" title="{{ $set['set'] }}">{{ $set['set'] }}</p>
                            <p class="mt-0.5 text-xs text-gray-500">{{ $count($set['results']) }} {{ $set['label'] }}, {{ $types[$set['type']][1] }} {{ $money($set['cost']) }}</p>
                        </div>
                        <p class="min-w-0 flex-1 text-[13px] text-gray-700 dark:text-gray-300">
                            @if (array_merge($set['interests'], $set['audiences']) !== []){{ implode(', ', array_merge($set['interests'], $set['audiences'])) }}@else<span class="text-xs text-gray-500">İlgi alanı yok (geniş kitle)</span>@endif
                        </p>
                    </li>
                @empty
                    <li class="card-row text-sm text-gray-500">Bu dönemde harcaması olan reklam seti yok.</li>
                @endforelse
            </ul>
        </section>
    </div>
@endif
