@php
    /** Raw Meta enums never reach the screen: unknown ones are humanised. */
    $enumText = fn (string $v): string => \Illuminate\Support\Str::of($v)->lower()->replace('_', ' ')->ucfirst()->toString();
    $objective = fn (string $v): string => ($label = \App\Services\Meta\MetaScreen::objectiveLabel($v)) === $v ? $enumText($v) : $label;
    $select = 'mt-1 block w-full max-w-full rounded-lg border-gray-200 py-1.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $num = fn ($v, int $d = 0) => $v === null ? '—' : number_format((float) $v, $d, ',', '.');
    $money = fn ($v, string $cur = 'TRY') => $v === null ? '—' : number_format((float) $v, 0, ',', '.').' '.($cur !== '' ? $cur : 'TRY');
    $pct = fn ($v) => $v === null ? '' : (($v > 0 ? '+' : '').number_format((float) $v, 0, ',', '.').'%');
    $typeLabel = mb_strtolower($types[$view['type'] ?? 'leads'] ?? 'Form');
    $plan = $view['plan'] ?? null;
@endphp

<div class="space-y-10">
    <div class="flex flex-wrap items-start gap-3">
        <div class="min-w-0 flex-1 space-y-1">
            <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white" title="Aynı hizmette diğer markaların kazanan kampanyaları, en ucuzdan pahalıya. Sonuç türleri karıştırılmaz.">Strateji öner</h1>
            <p class="text-sm text-gray-500">Kazanan: son 30 günde en az {{ $num($minSpend) }} TRY harcama ve {{ $minResults }} sonuç</p>
        </div>
    </div>
    @include('livewire.operator.winners.partials.nav', ['current' => 'operator.meta-strategy'])

    @if ($flash !== '')
        <p class="tone-info rounded-lg px-3 py-2 text-sm" data-testid="meta-strategy-flash">{{ $flash }}</p>
    @endif

    @if (! ($view['ready'] ?? false))
        <x-ta.empty-state title="Henüz hesaplanmış kampanya yok." message="Sayılar her sabah Meta verisi çekildikten sonra hesaplanır." />
    @else
        <div class="flex flex-wrap items-end gap-3" data-testid="meta-strategy-filters">
            <label class="min-w-0 max-w-full text-xs text-gray-500">Marka
                <select wire:model.live="brand" class="{{ $select }}"><option value="">Marka seçin</option>@foreach ($view['options']['brands'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
            </label>
            <label class="text-xs text-gray-500">Hizmet
                <select wire:model.live="service" class="{{ $select }}"><option value="">Hizmet seçin</option>@foreach ($view['options']['services'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
            </label>
            <label class="text-xs text-gray-500">Şehir
                <select wire:model.live="city" class="{{ $select }}"><option value="">Tüm şehirler</option>@foreach ($view['options']['cities'] as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select>
            </label>
            <label class="text-xs text-gray-500">Sonuç türü
                <select wire:model.live="type" class="{{ $select }}">@foreach ($types as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
            </label>
            <span class="hidden flex-1 sm:block"></span>
            @if ($view['period_end'])<span class="text-xs text-gray-500">{{ \Carbon\CarbonImmutable::parse($view['period_end'])->format('d.m.Y') }}’e kadar 30 gün</span>@endif
        </div>

        @if ($view['service_id'] === null)
            <x-ta.empty-state size="sm" title="Kazananları görmek için bir hizmet seçin." />
        @else
            <div class="grid gap-10 xl:grid-cols-3">
                <section class="min-w-0 xl:col-span-2" data-testid="meta-strategy-recipe">
                    <div class="flex flex-wrap items-baseline gap-2">
                        <h2 class="section-title">Kazanan tarifi · {{ $view['service_name'] }}</h2>
                        <span class="text-sm text-gray-500">{{ $typeLabel }}, {{ $view['scope'] }}</span>
                    </div>
                    @if ($view['recipe'] === null)
                        <p class="py-4 text-sm text-gray-500">Bu hizmet ve sonuç türünde eşiği geçen kampanya yok. Başka bir sonuç türüne ya da tüm şehirlere bakın.</p>
                    @else
                        @php $r = $view['recipe']; @endphp
                        <div class="grid gap-6 py-4 md:grid-cols-3">
                            <x-ta.metric-card :label="'Beklenen '.$typeLabel.' başı'" :value="$money($r['cpr'])" :hint="'En iyi '.$r['count'].' kampanyanın ortancası, '.$r['brands'].' marka'" />
                            <dl class="grid gap-x-4 gap-y-2 text-sm sm:grid-cols-2 md:col-span-2">
                                @foreach ($r['lines'] as $line)
                                    <div><dt class="text-xs text-gray-500">{{ $line['label'] }}</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $line['value'] }}</dd></div>
                                @endforeach
                            </dl>
                        </div>
                        <div class="flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4 dark:border-gray-800">
                            @if ($view['brand'])
                                <button type="button" wire:click="requestPlan" wire:loading.attr="disabled" @disabled(($plan['status'] ?? '') === 'running' || ! $view['asset_id'])
                                    class="w-full rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-500 disabled:opacity-50 sm:w-auto">{{ $view['brand']->name }} için plan taslağı iste</button>
                                @if (! $view['asset_id'])<span class="text-xs text-gray-500">Markanın bağlı Meta reklam hesabı yok.</span>@endif
                                @if ($plan)
                                    <span @class(['text-sm', 'text-gray-600 dark:text-gray-300' => $plan['status'] === 'running', 'text-success-700 dark:text-success-300' => $plan['status'] === 'ready', 'text-error-700' => $plan['status'] === 'failed'])>{{ $plan['message'] }}</span>
                                    @if ($plan['status'] === 'ready' && $view['asset_id'])<a href="{{ route('operator.meta.overview', ['assetId' => $view['asset_id'], 'tab' => 'todo']) }}" wire:navigate class="link-neutral text-sm">Yapılacaklar’a git</a>@endif
                                @endif
                            @else
                                <span class="text-sm text-gray-500">Plan taslağı için marka seçin.</span>
                            @endif
                            <span class="hidden flex-1 sm:block"></span>
                            <span class="text-xs text-gray-500" title="Tarif kuralla çıkar; planı Claude yazar, bütçe ve maliyet tariften gelir.">Meta’ya bir şey gönderilmez.</span>
                        </div>
                    @endif
                </section>

                <section class="min-w-0" data-testid="meta-strategy-own">
                    <h2 class="section-title">{{ $view['brand'] ? $view['brand']->name.', bu hizmette' : 'Markanın kampanyaları' }}</h2>
                    @if (! $view['brand'])
                        <p class="py-4 text-sm text-gray-500">Kendi kampanyalarını yanında görmek için marka seçin.</p>
                    @else
                        @forelse ($view['own'] as $o)
                            <div class="border-b border-gray-200 py-2.5 dark:border-gray-800" wire:key="own-{{ $o['id'] }}">
                                <a href="{{ route('operator.meta.campaign', ['assetId' => $o['asset_id'], 'campaignId' => $o['campaign_id']]) }}" wire:navigate class="line-clamp-2 break-words text-sm font-medium text-gray-900 hover:underline dark:text-white" title="{{ $o['name'] }}">{{ $o['name'] }}</a>
                                <p class="text-xs tabular-nums text-gray-500">{{ $money($o['spend'], $o['currency']) }}, {{ $num($o['results']) }} {{ $typeLabel }}, {{ $typeLabel }} başı {{ $money($o['cpr'], $o['currency']) }}
                                    @if ($o['diff'] !== null)<span @class(['ml-1', 'text-error-700' => $o['diff'] >= 15, 'text-success-700' => $o['diff'] <= -15])>tarife göre {{ $pct($o['diff']) }}</span>@endif</p>
                            </div>
                        @empty
                            <p class="py-4 text-sm text-gray-500">Son 30 günde bu hizmette {{ $typeLabel }} kampanyası yok.</p>
                        @endforelse
                    @endif
                </section>
            </div>

            <section data-testid="meta-strategy-winners">
                <div class="flex flex-wrap items-baseline gap-2">
                    <h2 class="section-title">Kazananlar</h2>
                    <span class="text-sm text-gray-500">{{ $view['pool'] }} kampanya, {{ $view['scope'] }}@if ($view['other_currency'] > 0), TRY dışı {{ $view['other_currency'] }} kampanya hariç @endif</span>
                </div>
                <div class="mt-2 divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                @forelse ($view['winners'] as $i => $w)
                    @php $p = $w['profile']; $t = $p['targeting'] ?? null; $ad = $p['best_ad'] ?? null; @endphp
                    <article class="py-5" wire:key="winner-{{ $w['id'] }}">
                        <div class="flex flex-wrap items-start gap-3">
                            <span class="w-6 shrink-0 pt-0.5 text-sm tabular-nums text-gray-500">{{ $i + 1 }}.</span>
                            <div class="min-w-0 flex-1">
                                <p class="flex min-w-0 items-center gap-2 font-medium text-gray-900 dark:text-white"><x-ta.brand-avatar :name="$w['brand']" size="xs" /><span class="break-words">{{ $w['brand'] }}</span>@if ($w['top'])<x-ta.pill tone="ok" class="font-normal">En iyi</x-ta.pill>@endif</p>
                                <p class="mt-0.5 line-clamp-2 break-words text-sm text-gray-700 dark:text-gray-300" title="{{ $w['name'] }}">{{ $w['name'] }}</p>
                                <p class="text-xs text-gray-500">{{ $w['city'] !== '' ? $w['city'] : 'şehir yok' }}, {{ $w['status'] === 'live' ? 'yayında' : 'durdu' }}</p>
                            </div>
                            <div class="grid w-full grid-cols-3 gap-4 tabular-nums sm:w-auto sm:text-right">
                                <div><p class="text-xs text-gray-500">Harcama</p><p class="whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">{{ $money($w['spend'], $w['currency']) }}</p></div>
                                <div><p class="text-xs text-gray-500">{{ ucfirst($typeLabel) }}</p><p class="text-sm font-medium text-gray-900 dark:text-white">{{ $num($w['results']) }}</p></div>
                                <div><p class="text-xs text-gray-500">{{ ucfirst($typeLabel) }} başı</p><p class="whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-white">{{ $money($w['cpr'], $w['currency']) }}</p></div>
                            </div>
                        </div>
                        <div class="mt-3 grid gap-4 lg:grid-cols-2">
                            <div class="space-y-2 text-sm">
                                @php
                                    $facts = array_filter([
                                        ($p['objective'] ?? '') !== '' ? $objective((string) $p['objective']) : null,
                                        ...array_map(fn ($o) => $optimizations[$o] ?? $enumText((string) $o), $p['optimization'] ?? []),
                                        ($p['destination'] ?? '') !== '' ? ($destinations[$p['destination']] ?? $p['destination']) : null,
                                        ($p['budget'] ?? null) !== null ? 'günlük '.$money($p['budget'], $w['currency']) : null,
                                        isset($p['adsets']) ? $p['adsets'].' set, '.($p['ads'] ?? 0).' reklam' : null,
                                    ]);
                                @endphp
                                @if ($facts !== [])<p class="text-xs text-gray-700 dark:text-gray-300">{{ implode(', ', $facts) }}</p>@endif
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
                            <div class="min-w-0">
                                @if ($ad)
                                    <p class="text-xs text-gray-500">En iyi reklam, {{ ($ad['video'] ?? false) ? 'video' : 'görsel' }}@if (($ad['cpr'] ?? null) !== null), {{ $typeLabel }} başı {{ $money($ad['cpr'], $w['currency']) }}@endif</p>
                                    @if (($ad['title'] ?? '') !== '')<p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $ad['title'] }}</p>@endif
                                    <p class="mt-1 line-clamp-6 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $ad['body'] ?? '' }}</p>
                                @else
                                    <p class="text-xs text-gray-500">Reklam metni yok.</p>
                                @endif
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <button type="button" wire:click="save({{ $w['id'] }}, 'text')" @disabled(! $ad || $w['saved']['text']) class="rounded-lg px-2.5 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 disabled:opacity-50 dark:text-gray-300 dark:ring-gray-700">{{ $w['saved']['text'] ? 'Metin kütüphanede' : 'Metni kütüphaneye kaydet' }}</button>
                            <button type="button" wire:click="save({{ $w['id'] }}, 'targeting')" @disabled(! $t || $w['saved']['targeting']) class="rounded-lg px-2.5 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 disabled:opacity-50 dark:text-gray-300 dark:ring-gray-700">{{ $w['saved']['targeting'] ? 'Hedefleme kütüphanede' : 'Hedeflemeyi kaydet' }}</button>
                            <span class="flex-1"></span>
                            <a href="{{ route('operator.meta.campaign', ['assetId' => $w['asset_id'], 'campaignId' => $w['campaign_id']]) }}" wire:navigate class="link-neutral text-xs">Kampanyayı aç</a>
                        </div>
                    </article>
                @empty
                    <x-ta.empty-state size="sm" title="Eşiği geçen kampanya yok." />
                @endforelse
                </div>
            </section>
        @endif
    @endif
</div>
