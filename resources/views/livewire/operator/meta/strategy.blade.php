@php
    /** Raw Meta enums never reach the screen: unknown ones are humanised. */
    $enumText = fn (string $v): string => \Illuminate\Support\Str::of($v)->lower()->replace('_', ' ')->ucfirst()->toString();
    $objective = fn (string $v): string => ($label = \App\Services\Meta\MetaScreen::objectiveLabel($v)) === $v ? $enumText($v) : $label;
    $select = 'h-9 w-full min-w-0 rounded-lg border border-gray-200 bg-white pl-3 pr-8 text-sm text-gray-900 sm:w-auto sm:max-w-[16rem] dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $num = fn ($v, int $d = 0) => $v === null ? '—' : number_format((float) $v, $d, ',', '.');
    $money = fn ($v, string $cur = 'TRY') => $v === null ? '—' : number_format((float) $v, 0, ',', '.').' '.($cur !== '' ? $cur : 'TRY');
    $pct = fn ($v) => $v === null ? '' : (($v > 0 ? '+' : '').number_format((float) $v, 0, ',', '.').'%');
    $typeLabel = mb_strtolower($types[$view['type'] ?? 'leads'] ?? 'Form');
    $plan = $view['plan'] ?? null;
    // The answer first: what a result should cost in this service, when a recipe exists.
    $recipe = ($view['ready'] ?? false) && ($view['service_id'] ?? null) !== null ? ($view['recipe'] ?? null) : null;
    $sub = $recipe !== null
        ? ($view['service_name'] ?? '').' için beklenen '.$typeLabel.' başı '.$money($recipe['cpr']).'; en iyi '.$recipe['count'].' kampanyanın ortancası.'
        : 'Kazanan: son 30 günde en az '.$num($minSpend).' TRY harcama ve '.$minResults.' sonuç.';
@endphp

<div class="min-w-0">
    @include('livewire.operator.winners.partials.nav', ['current' => 'operator.meta-strategy'])

    <div class="space-y-10">
    <header class="min-w-0">
        <h1 class="page-title" title="Aynı hizmette diğer markaların kazanan kampanyaları, en ucuzdan pahalıya. Sonuç türleri karıştırılmaz.">Strateji öner</h1>
        <p class="page-sub">{{ $sub }}</p>
    </header>

    @if ($flash !== '')
        <p class="tone-info rounded-lg px-3 py-2 text-sm" data-testid="meta-strategy-flash">{{ $flash }}</p>
    @endif

    @if (! ($view['ready'] ?? false))
        <div class="card"><x-ta.empty-state icon="none" title="Henüz hesaplanmış kampanya yok." message="Sayılar her sabah Meta verisi çekildikten sonra hesaplanır." /></div>
    @else
        <div class="flex flex-wrap items-center gap-2" data-testid="meta-strategy-filters">
            <label class="contents"><span class="sr-only">Marka</span>
                <select wire:model.live="brand" class="{{ $select }}"><option value="">Marka seçin</option>@foreach ($view['options']['brands'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
            </label>
            <label class="contents"><span class="sr-only">Hizmet</span>
                <select wire:model.live="service" class="{{ $select }}"><option value="">Hizmet seçin</option>@foreach ($view['options']['services'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
            </label>
            <label class="contents"><span class="sr-only">Şehir</span>
                <select wire:model.live="city" class="{{ $select }}"><option value="">Tüm şehirler</option>@foreach ($view['options']['cities'] as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select>
            </label>
            <label class="contents"><span class="sr-only">Sonuç türü</span>
                <select wire:model.live="type" class="{{ $select }}">@foreach ($types as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
            </label>
            @if ($view['period_end'])<span class="text-[13px] text-gray-500 sm:ml-auto">{{ \Carbon\CarbonImmutable::parse($view['period_end'])->format('d.m.Y') }}’e kadar 30 gün</span>@endif
        </div>

        @if ($view['service_id'] === null)
            <div class="card"><x-ta.empty-state icon="none" size="sm" title="Kazananları görmek için bir hizmet seçin." /></div>
        @else
            <div class="grid gap-10 xl:grid-cols-3">
                <section class="min-w-0 xl:col-span-2" data-testid="meta-strategy-recipe">
                    <div class="section-head">
                        <h2 class="section-title">Kazanan tarifi</h2>
                        <span class="min-w-0 break-words text-[13px] text-gray-500">{{ $view['service_name'] }}, {{ $typeLabel }}, {{ $view['scope'] }}</span>
                    </div>
                    @if ($view['recipe'] === null)
                        <div class="card"><x-ta.empty-state icon="none" size="sm" title="Bu hizmet ve sonuç türünde eşiği geçen kampanya yok." message="Başka bir sonuç türüne ya da tüm şehirlere bakın." /></div>
                    @else
                        @php $r = $view['recipe']; @endphp
                        <div class="card">
                            <div class="grid gap-x-6 gap-y-4 p-5 md:grid-cols-3">
                                <div class="min-w-0">
                                    <p class="text-[13px] text-gray-500">Beklenen {{ $typeLabel }} başı</p>
                                    <p class="mt-1 text-2xl leading-8 font-semibold tabular-nums tracking-[-0.02em] text-gray-900 dark:text-white">{{ $money($r['cpr']) }}</p>
                                    <p class="mt-1 text-xs text-gray-500">En iyi {{ $r['count'] }} kampanyanın ortancası, {{ $r['brands'] }} marka</p>
                                </div>
                                <dl class="grid min-w-0 gap-x-6 gap-y-3 text-sm sm:grid-cols-2 md:col-span-2">
                                    @foreach ($r['lines'] as $line)
                                        <div class="min-w-0"><dt class="text-xs text-gray-500">{{ $line['label'] }}</dt><dd class="mt-0.5 break-words text-gray-900 dark:text-white">{{ $line['value'] }}</dd></div>
                                    @endforeach
                                </dl>
                            </div>
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-gray-200 px-5 py-3.5 dark:border-gray-800">
                                @if ($view['brand'])
                                    <button type="button" wire:click="requestPlan" wire:loading.attr="disabled" @disabled(($plan['status'] ?? '') === 'running' || ! $view['asset_id'])
                                        class="btn-primary h-9! w-full whitespace-normal! sm:w-auto">{{ $view['brand']->name }} için plan taslağı iste</button>
                                    @if (! $view['asset_id'])<span class="text-xs text-gray-500">Markanın bağlı Meta reklam hesabı yok.</span>@endif
                                    @if ($plan)
                                        <span @class(['flex items-center gap-1.5 text-[13px]', 'text-gray-600 dark:text-gray-300' => $plan['status'] === 'running', 'text-success-700 dark:text-success-300' => $plan['status'] === 'ready', 'text-error-700 dark:text-error-400' => $plan['status'] === 'failed'])>@if ($plan['status'] === 'running')<span class="live-dot"></span>@endif{{ $plan['message'] }}</span>
                                        @if ($plan['status'] === 'ready' && $view['asset_id'])<a href="{{ route('operator.meta.overview', ['assetId' => $view['asset_id'], 'tab' => 'todo']) }}" wire:navigate class="link-neutral text-[13px]">Yapılacaklar’a git</a>@endif
                                    @endif
                                @else
                                    <span class="text-[13px] text-gray-500">Plan taslağı için marka seçin.</span>
                                @endif
                                <span class="text-xs text-gray-500 sm:ml-auto" title="Tarif kuralla çıkar; planı Claude yazar, bütçe ve maliyet tariften gelir.">Meta’ya bir şey gönderilmez.</span>
                            </div>
                        </div>
                    @endif
                </section>

                <section class="min-w-0" data-testid="meta-strategy-own">
                    <div class="section-head">
                        <h2 class="section-title min-w-0 break-words">{{ $view['brand'] ? $view['brand']->name.', bu hizmette' : 'Markanın kampanyaları' }}@if ($view['brand']) <span class="section-count">{{ count($view['own']) }}</span>@endif</h2>
                    </div>
                    @if (! $view['brand'])
                        <div class="card"><x-ta.empty-state icon="none" size="sm" title="Marka seçilmedi." message="Kendi kampanyalarını yanında görmek için marka seçin." /></div>
                    @elseif ($view['own'] === [])
                        <div class="card"><x-ta.empty-state icon="none" size="sm" :title="'Son 30 günde bu hizmette '.$typeLabel.' kampanyası yok.'" /></div>
                    @else
                        <ul class="card">
                            @foreach ($view['own'] as $o)
                                <li class="card-row block" wire:key="own-{{ $o['id'] }}">
                                    <a href="{{ route('operator.meta.campaign', ['assetId' => $o['asset_id'], 'campaignId' => $o['campaign_id']]) }}" wire:navigate class="line-clamp-2 break-words text-sm font-medium text-gray-900 hover:underline dark:text-white" title="{{ $o['name'] }}">{{ $o['name'] }}</a>
                                    <p class="mt-0.5 text-[13px] tabular-nums text-gray-500">{{ $money($o['spend'], $o['currency']) }}, {{ $num($o['results']) }} {{ $typeLabel }}, {{ $typeLabel }} başı {{ $money($o['cpr'], $o['currency']) }}</p>
                                    @if ($o['diff'] !== null)<p class="mt-1.5 flex items-center gap-1.5 text-xs text-gray-500"><span @class(['chip', 'chip-down' => $o['diff'] >= 15, 'chip-up' => $o['diff'] <= -15, 'chip-flat' => abs($o['diff']) < 15])>{{ $o['diff'] > 0 ? '▲ ' : ($o['diff'] < 0 ? '▼ ' : '') }}{{ number_format(abs((float) $o['diff']), 0, ',', '.') }}%</span> tarife göre</p>@endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>

            <section data-testid="meta-strategy-winners">
                <div class="section-head">
                    <h2 class="section-title">Kazananlar <span class="section-count">{{ count($view['winners']) }}</span></h2>
                    <span class="text-[13px] text-gray-500">{{ $view['pool'] }} kampanya, {{ $view['scope'] }}@if ($view['other_currency'] > 0), TRY dışı {{ $view['other_currency'] }} kampanya hariç @endif</span>
                </div>
                @if ($view['winners'] === [])
                    <div class="card"><x-ta.empty-state icon="none" size="sm" title="Eşiği geçen kampanya yok." /></div>
                @else
                <ol class="card">
                @foreach ($view['winners'] as $i => $w)
                    @php $p = $w['profile']; $t = $p['targeting'] ?? null; $ad = $p['best_ad'] ?? null; @endphp
                    <li class="border-t border-gray-200 px-4 py-4 first:border-t-0 sm:px-5 dark:border-gray-800" wire:key="winner-{{ $w['id'] }}">
                        <div class="flex flex-wrap items-start gap-3 sm:flex-nowrap">
                            <span class="w-5 shrink-0 pt-1 text-[13px] tabular-nums text-gray-500">{{ $i + 1 }}</span>
                            <x-ta.brand-avatar :name="$w['brand']" />
                            <div class="min-w-0 flex-1 basis-40">
                                <p class="flex min-w-0 flex-wrap items-center gap-x-2 text-sm font-medium text-gray-900 dark:text-white"><span class="break-words">{{ $w['brand'] }}</span>@if ($w['top'])<x-ta.pill tone="ok" class="font-normal">En iyi</x-ta.pill>@endif</p>
                                <p class="mt-0.5 line-clamp-2 break-words text-[13px] text-gray-700 dark:text-gray-300" title="{{ $w['name'] }}">{{ $w['name'] }}</p>
                                <p class="text-xs text-gray-500">{{ $w['city'] !== '' ? $w['city'] : 'şehir yok' }}, {{ $w['status'] === 'live' ? 'yayında' : 'durdu' }}</p>
                            </div>
                            <dl class="grid w-full shrink-0 grid-cols-3 gap-4 tabular-nums sm:w-auto sm:text-right">
                                <div><dt class="text-xs text-gray-500">Harcama</dt><dd class="whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">{{ $money($w['spend'], $w['currency']) }}</dd></div>
                                <div><dt class="text-xs text-gray-500">{{ ucfirst($typeLabel) }}</dt><dd class="text-sm text-gray-700 dark:text-gray-300">{{ $num($w['results']) }}</dd></div>
                                <div><dt class="text-xs text-gray-500">{{ ucfirst($typeLabel) }} başı</dt><dd class="whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-white">{{ $money($w['cpr'], $w['currency']) }}</dd></div>
                            </dl>
                        </div>
                        <div class="mt-3 grid gap-4 sm:pl-8 lg:grid-cols-2">
                            <div class="min-w-0 space-y-2 text-sm">
                                @php
                                    $facts = array_filter([
                                        ($p['objective'] ?? '') !== '' ? $objective((string) $p['objective']) : null,
                                        ...array_map(fn ($o) => $optimizations[$o] ?? $enumText((string) $o), $p['optimization'] ?? []),
                                        ($p['destination'] ?? '') !== '' ? ($destinations[$p['destination']] ?? $p['destination']) : null,
                                        ($p['budget'] ?? null) !== null ? 'günlük '.$money($p['budget'], $w['currency']) : null,
                                        isset($p['adsets']) ? $p['adsets'].' set, '.($p['ads'] ?? 0).' reklam' : null,
                                    ]);
                                @endphp
                                @if ($facts !== [])<p class="text-[13px] text-gray-700 dark:text-gray-300">{{ implode(', ', $facts) }}</p>@endif
                                @if ($t)
                                    <dl class="grid grid-cols-[6rem_minmax(0,1fr)] gap-x-3 gap-y-1 break-words text-xs sm:grid-cols-[7rem_minmax(0,1fr)]">
                                        <dt class="text-gray-500">Konum</dt><dd class="text-gray-800 dark:text-gray-200">{{ implode(', ', $t['locations'] ?? []) ?: '—' }}</dd>
                                        <dt class="text-gray-500">Yaş, cinsiyet</dt><dd class="text-gray-800 dark:text-gray-200">{{ $t['age'] ?? '—' }}, {{ $t['genders'] ?? '—' }}</dd>
                                        <dt class="text-gray-500">Kitle</dt><dd class="text-gray-800 dark:text-gray-200">{{ ($t['advantage'] ?? false) ? 'Advantage+ kitle' : 'Elle' }}@if (($t['interests'] ?? []) !== []), {{ implode(', ', $t['interests']) }}@endif @if (($t['audiences'] ?? 0) > 0), {{ $t['audiences'] }} özel kitle @endif</dd>
                                        <dt class="text-gray-500">Yerleşim</dt><dd class="text-gray-800 dark:text-gray-200">{{ $t['placements'] ?? '—' }}</dd>
                                    </dl>
                                @else
                                    <p class="text-xs text-gray-500">Hedefleme verisi yok.</p>
                                @endif
                            </div>
                            <div class="min-w-0 rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-white/[0.02]">
                                @if ($ad)
                                    <p class="text-xs text-gray-500">En iyi reklam, {{ ($ad['video'] ?? false) ? 'video' : 'görsel' }}@if (($ad['cpr'] ?? null) !== null), {{ $typeLabel }} başı {{ $money($ad['cpr'], $w['currency']) }}@endif</p>
                                    @if (($ad['title'] ?? '') !== '')<p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $ad['title'] }}</p>@endif
                                    <p class="mt-1 line-clamp-6 whitespace-pre-line text-[13px] text-gray-700 dark:text-gray-300">{{ $ad['body'] ?? '' }}</p>
                                @else
                                    <p class="text-xs text-gray-500">Reklam metni yok.</p>
                                @endif
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-1.5 sm:pl-8">
                            <button type="button" wire:click="save({{ $w['id'] }}, 'text')" @disabled(! $ad || $w['saved']['text']) class="btn h-7! px-2.5! text-xs!">{{ $w['saved']['text'] ? 'Metin kaydedildi' : 'Metni kaydet' }}</button>
                            <button type="button" wire:click="save({{ $w['id'] }}, 'targeting')" @disabled(! $t || $w['saved']['targeting']) class="btn h-7! px-2.5! text-xs!">{{ $w['saved']['targeting'] ? 'Hedefleme kaydedildi' : 'Hedeflemeyi kaydet' }}</button>
                            <a href="{{ route('operator.meta.campaign', ['assetId' => $w['asset_id'], 'campaignId' => $w['campaign_id']]) }}" wire:navigate class="btn-quiet h-7! px-2! text-xs! sm:ml-auto">Kampanyayı aç</a>
                        </div>
                    </li>
                @endforeach
                </ol>
                @endif
            </section>
        @endif
    @endif
    </div>
</div>
