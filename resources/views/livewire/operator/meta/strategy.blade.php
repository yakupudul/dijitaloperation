@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
    $panel = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
    $select = 'mt-1 block rounded-lg border-gray-300 py-1.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $chip = 'rounded-md bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-white/[0.06] dark:text-gray-300';
    $num = fn ($v, int $d = 0) => $v === null ? '—' : number_format((float) $v, $d, ',', '.');
    $money = fn ($v, string $cur = 'TRY') => $v === null ? '—' : number_format((float) $v, 0, ',', '.').' '.($cur !== '' ? $cur : 'TRY');
    $pct = fn ($v) => $v === null ? '' : (($v > 0 ? '+' : '').number_format((float) $v, 0, ',', '.').'%');
    $typeLabel = mb_strtolower($types[$view['type'] ?? 'leads'] ?? 'Form');
    $plan = $view['plan'] ?? null;
@endphp

<div class="space-y-5">
    @include('livewire.operator.winners.partials.nav', ['current' => 'operator.meta-strategy'])
    <div class="flex flex-wrap items-start gap-3">
        <div class="min-w-0 flex-1 space-y-1">
            <p class="text-xs font-medium text-gray-500"><a href="{{ route('operator.meta-desk') }}" wire:navigate class="hover:text-brand-600">Meta masası</a> › Strateji öner</p>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Strateji öner</h1>
            <p class="text-sm text-gray-500">Aynı hizmette diğer markaların kazanan kampanyaları, en ucuzdan pahalıya. Kazanan: son 30 günde en az {{ $num($minSpend) }} TRY harcama ve {{ $minResults }} sonuç. Sonuç türleri karıştırılmaz.</p>
        </div>
    </div>

    @if ($flash !== '')
        <p class="rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-800 dark:bg-brand-500/10 dark:text-brand-200" data-testid="meta-strategy-flash">{{ $flash }}</p>
    @endif

    @if (! ($view['ready'] ?? false))
        <p class="{{ $card }} text-sm text-gray-500">Henüz hesaplanmış kampanya yok. Sayılar her sabah Meta verisi çekildikten sonra hesaplanır.</p>
    @else
        <div class="flex flex-wrap items-end gap-3" data-testid="meta-strategy-filters">
            <label class="text-xs text-gray-500">Marka
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
            <span class="flex-1"></span>
            @if ($view['period_end'])<span class="text-xs text-gray-500">{{ \Carbon\CarbonImmutable::parse($view['period_end'])->format('d.m.Y') }}’e kadar 30 gün</span>@endif
        </div>

        @if ($view['service_id'] === null)
            <p class="{{ $card }} text-sm text-gray-500">Kazananları görmek için bir hizmet seçin.</p>
        @else
            <div class="grid gap-4 xl:grid-cols-3">
                <section class="{{ $panel }} xl:col-span-2" data-testid="meta-strategy-recipe">
                    <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                        <h2 class="font-semibold text-gray-900 dark:text-white">Kazanan tarifi · {{ $view['service_name'] }}</h2>
                        <span class="text-xs text-gray-500">{{ $typeLabel }} · {{ $view['scope'] }}</span>
                    </div>
                    @if ($view['recipe'] === null)
                        <p class="px-4 py-4 text-sm text-gray-500">Bu hizmet ve sonuç türünde eşiği geçen kampanya yok. Başka bir sonuç türüne ya da tüm şehirlere bakın.</p>
                    @else
                        @php $r = $view['recipe']; @endphp
                        <div class="grid gap-4 p-4 md:grid-cols-3">
                            <div class="rounded-xl bg-emerald-50 p-4 dark:bg-emerald-500/10">
                                <p class="text-xs font-medium text-emerald-800 dark:text-emerald-300">Beklenen {{ $typeLabel }} başı</p>
                                <p class="mt-1 text-3xl font-semibold tabular-nums text-emerald-900 dark:text-emerald-200">{{ $money($r['cpr']) }}</p>
                                <p class="mt-1 text-xs text-emerald-800/80 dark:text-emerald-300/80">En iyi {{ $r['count'] }} kampanyanın ortancası · {{ $r['brands'] }} marka</p>
                            </div>
                            <dl class="grid gap-x-4 gap-y-2 text-sm sm:grid-cols-2 md:col-span-2">
                                @foreach ($r['lines'] as $line)
                                    <div><dt class="text-xs text-gray-500">{{ $line['label'] }}</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $line['value'] }}</dd></div>
                                @endforeach
                            </dl>
                        </div>
                        <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 px-4 py-3 dark:border-gray-700">
                            @if ($view['brand'])
                                <button type="button" wire:click="requestPlan" wire:loading.attr="disabled" @disabled(($plan['status'] ?? '') === 'running' || ! $view['asset_id'])
                                    class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">{{ $view['brand']->name }} için plan taslağı iste</button>
                                @if (! $view['asset_id'])<span class="text-xs text-gray-500">Markanın bağlı Meta reklam hesabı yok.</span>@endif
                                @if ($plan)
                                    <span @class(['text-sm', 'text-gray-600 dark:text-gray-300' => $plan['status'] === 'running', 'text-emerald-700 dark:text-emerald-300' => $plan['status'] === 'ready', 'text-rose-600' => $plan['status'] === 'failed'])>{{ $plan['message'] }}</span>
                                    @if ($plan['status'] === 'ready' && $view['asset_id'])<a href="{{ route('operator.meta.overview', ['assetId' => $view['asset_id'], 'tab' => 'todo']) }}" wire:navigate class="text-sm font-semibold text-brand-600 hover:underline">Yapılacaklar’a git →</a>@endif
                                @endif
                            @else
                                <span class="text-sm text-gray-500">Plan taslağı için marka seçin.</span>
                            @endif
                            <span class="flex-1"></span>
                            <span class="text-xs text-gray-400">Tarif kuralla çıkar; planı Claude yazar, bütçe ve maliyet tariften gelir. Meta’ya bir şey gönderilmez.</span>
                        </div>
                    @endif
                </section>

                <section class="{{ $panel }}" data-testid="meta-strategy-own">
                    <h2 class="border-b border-gray-100 px-4 py-3 font-semibold text-gray-900 dark:border-gray-700 dark:text-white">{{ $view['brand'] ? $view['brand']->name.' · bu hizmette' : 'Markanın kampanyaları' }}</h2>
                    @if (! $view['brand'])
                        <p class="px-4 py-4 text-sm text-gray-500">Kendi kampanyalarını yanında görmek için marka seçin.</p>
                    @else
                        @forelse ($view['own'] as $o)
                            <div class="border-b border-gray-100 px-4 py-3 last:border-0 dark:border-gray-700" wire:key="own-{{ $o['id'] }}">
                                <a href="{{ route('operator.meta.campaign', ['assetId' => $o['asset_id'], 'campaignId' => $o['campaign_id']]) }}" wire:navigate class="text-sm font-medium text-gray-900 hover:text-brand-600 dark:text-white">{{ $o['name'] }}</a>
                                <p class="text-xs tabular-nums text-gray-500">{{ $money($o['spend'], $o['currency']) }} · {{ $num($o['results']) }} {{ $typeLabel }} · {{ $typeLabel }} başı {{ $money($o['cpr'], $o['currency']) }}
                                    @if ($o['diff'] !== null)<span @class(['font-semibold', 'text-rose-600' => $o['diff'] >= 15, 'text-emerald-600' => $o['diff'] <= -15])>· tarife göre {{ $pct($o['diff']) }}</span>@endif</p>
                            </div>
                        @empty
                            <p class="px-4 py-4 text-sm text-gray-500">Son 30 günde bu hizmette {{ $typeLabel }} kampanyası yok.</p>
                        @endforelse
                    @endif
                </section>
            </div>

            <section class="space-y-3" data-testid="meta-strategy-winners">
                <div class="flex flex-wrap items-baseline gap-2">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Kazananlar</h2>
                    <span class="text-sm text-gray-500">{{ $view['pool'] }} kampanya · {{ $view['scope'] }}@if ($view['other_currency'] > 0) · TRY dışı {{ $view['other_currency'] }} kampanya hariç @endif</span>
                </div>
                @forelse ($view['winners'] as $i => $w)
                    @php $p = $w['profile']; $t = $p['targeting'] ?? null; $ad = $p['best_ad'] ?? null; @endphp
                    <article @class([$panel, 'p-4', 'ring-emerald-300 dark:ring-emerald-500/40' => $w['top']]) wire:key="winner-{{ $w['id'] }}">
                        <div class="flex flex-wrap items-start gap-3">
                            <span @class(['flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200' => $w['top'], 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300' => ! $w['top']])>{{ $i + 1 }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-gray-900 dark:text-white">{{ $w['brand'] }} <span class="font-normal text-gray-400">·</span> <span class="font-normal text-gray-700 dark:text-gray-300">{{ $w['name'] }}</span></p>
                                <p class="text-xs text-gray-500">{{ $w['city'] !== '' ? $w['city'] : 'şehir yok' }} · {{ $w['status'] === 'live' ? 'yayında' : 'durdu' }}</p>
                            </div>
                            <div class="grid grid-cols-3 gap-4 text-right tabular-nums">
                                <div><p class="text-[11px] text-gray-500">Harcama</p><p class="text-sm font-medium text-gray-900 dark:text-white">{{ $money($w['spend'], $w['currency']) }}</p></div>
                                <div><p class="text-[11px] text-gray-500">{{ ucfirst($typeLabel) }}</p><p class="text-sm font-medium text-gray-900 dark:text-white">{{ $num($w['results']) }}</p></div>
                                <div><p class="text-[11px] text-gray-500">{{ ucfirst($typeLabel) }} başı</p><p class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">{{ $money($w['cpr'], $w['currency']) }}</p></div>
                            </div>
                        </div>
                        <div class="mt-3 grid gap-4 lg:grid-cols-2">
                            <div class="space-y-2 text-sm">
                                <div class="flex flex-wrap gap-1.5">
                                    @if (($p['objective'] ?? '') !== '')<span class="{{ $chip }}">{{ $p['objective'] }}</span>@endif
                                    @foreach ($p['optimization'] ?? [] as $o)<span class="{{ $chip }}">{{ $optimizations[$o] ?? $o }}</span>@endforeach
                                    @if (($p['destination'] ?? '') !== '')<span class="{{ $chip }}">{{ $destinations[$p['destination']] ?? $p['destination'] }}</span>@endif
                                    @if (($p['budget'] ?? null) !== null)<span class="{{ $chip }}">günlük {{ $money($p['budget'], $w['currency']) }}</span>@endif
                                    @if (isset($p['adsets']))<span class="{{ $chip }}">{{ $p['adsets'] }} set · {{ $p['ads'] ?? 0 }} reklam</span>@endif
                                </div>
                                @if ($t)
                                    <dl class="grid grid-cols-[7rem_1fr] gap-x-3 gap-y-1 text-xs">
                                        <dt class="text-gray-500">Konum</dt><dd class="text-gray-800 dark:text-gray-200">{{ implode(', ', $t['locations'] ?? []) ?: '—' }}</dd>
                                        <dt class="text-gray-500">Yaş · cinsiyet</dt><dd class="text-gray-800 dark:text-gray-200">{{ $t['age'] ?? '—' }} · {{ $t['genders'] ?? '—' }}</dd>
                                        <dt class="text-gray-500">Kitle</dt><dd class="text-gray-800 dark:text-gray-200">{{ ($t['advantage'] ?? false) ? 'Advantage+ kitle' : 'Elle' }}@if (($t['interests'] ?? []) !== []) · {{ implode(', ', $t['interests']) }}@endif @if (($t['audiences'] ?? 0) > 0) · {{ $t['audiences'] }} özel kitle @endif</dd>
                                        <dt class="text-gray-500">Yerleşim</dt><dd class="text-gray-800 dark:text-gray-200">{{ $t['placements'] ?? '—' }}</dd>
                                    </dl>
                                @else
                                    <p class="text-xs text-gray-500">Hedefleme verisi yok.</p>
                                @endif
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                                @if ($ad)
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">En iyi reklam · {{ ($ad['video'] ?? false) ? 'video' : 'görsel' }}@if (($ad['cpr'] ?? null) !== null) · {{ $typeLabel }} başı {{ $money($ad['cpr'], $w['currency']) }}@endif</p>
                                    @if (($ad['title'] ?? '') !== '')<p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $ad['title'] }}</p>@endif
                                    <p class="mt-1 line-clamp-6 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $ad['body'] ?? '' }}</p>
                                @else
                                    <p class="text-xs text-gray-500">Reklam metni yok.</p>
                                @endif
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3 dark:border-gray-700">
                            <button type="button" wire:click="save({{ $w['id'] }}, 'text')" @disabled(! $ad || $w['saved']['text']) class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:text-gray-300 dark:ring-gray-700">{{ $w['saved']['text'] ? 'Metin kütüphanede' : 'Metni kütüphaneye kaydet' }}</button>
                            <button type="button" wire:click="save({{ $w['id'] }}, 'targeting')" @disabled(! $t || $w['saved']['targeting']) class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:text-gray-300 dark:ring-gray-700">{{ $w['saved']['targeting'] ? 'Hedefleme kütüphanede' : 'Hedeflemeyi kaydet' }}</button>
                            <span class="flex-1"></span>
                            <a href="{{ route('operator.meta.campaign', ['assetId' => $w['asset_id'], 'campaignId' => $w['campaign_id']]) }}" wire:navigate class="text-xs font-semibold text-brand-600 hover:underline">Kampanyayı aç →</a>
                        </div>
                    </article>
                @empty
                    <p class="{{ $card }} text-sm text-gray-500">Eşiği geçen kampanya yok.</p>
                @endforelse
            </section>
        @endif
    @endif
</div>
