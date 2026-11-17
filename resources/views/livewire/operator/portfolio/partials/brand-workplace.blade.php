{{--
    Marka sayfası, tek çalışma yeri (arayüz kararı 2026-11-17). In the order of the spine Müşteri → Marka → Dijital
    varlıklar → ölçen araçlar: this month's customer contacts and what one costs, every asset with its measuring tools
    and doors, what is not connected (and who closes it), what Claude did, what is left to the operator (at most three).
    A contact kind nobody measures reads "ölçülmüyor", never 0.
--}}
@php
    $tr = fn (float|int $n, int $dec = 0): string => number_format((float) $n, $dec, ',', '.');
    $money = fn (float|int $n, string $currency): string => $tr($n).' '.($currency === 'TRY' ? 'TL' : $currency);
    $dot = fn (string $tone): string => match ($tone) {
        'ok', 'success' => 'bg-success-500',
        'warn', 'warning' => 'bg-warning-500',
        'bad', 'error', 'critical' => 'bg-error-500',
        default => 'bg-gray-300 dark:bg-gray-600',
    };
    $period = $outcome === null ? '' : \Carbon\Carbon::parse($outcome['from'])->translatedFormat('j').'–'.\Carbon\Carbon::parse($outcome['to'])->translatedFormat('j F');
    $change = $outcome['total']['change_pct'] ?? null;
    $costDelta = ($outcome['cost_per'] ?? null) !== null && ($outcome['cost_per_prev'] ?? null) ? (int) round(($outcome['cost_per'] - $outcome['cost_per_prev']) / $outcome['cost_per_prev'] * 100) : null;
    $claudeMissing = collect($missingLinks)->where('who', 'claude')->values();
    $yourMissing = collect($missingLinks)->where('who', 'you')->values();
@endphp

{{-- 1. Sonuç: how many customers reached the brand this month, through what, and what one cost. --}}
@if ($outcome !== null)
    <section data-brand-outcome>
        <div class="section-head">
            <h2 class="section-title">Bu ay müşteri teması</h2>
            <span class="text-sm text-gray-500">{{ $period }}, geçen ayın aynı günleriyle</span>
        </div>
        @if ($outcome['has_sources'])
            <div class="card">
                <div class="metric-strip grid-cols-2 lg:grid-cols-5">
                    <x-ta.metric-card label="Toplam" :value="$tr($outcome['total']['now'])"
                        :delta="$change === null ? null : ($change > 0 ? '+' : '').$change.'%'" :tone="$change === null || $change === 0 ? 'neutral' : ($change > 0 ? 'positive' : 'negative')"
                        :hint="'Geçen ay '.$tr($outcome['total']['prev'])" data-outcome="total" />
                    @foreach ($outcome['kinds'] as $kind)
                        @if ($kind['key'] !== 'other')
                            <x-ta.metric-card :label="$kind['label']" :value="$kind['measured'] ? $tr($kind['now']) : 'Ölçülmüyor'" :muted="! $kind['measured']"
                                :hint="$kind['measured'] ? 'Geçen ay '.$tr($kind['prev']) : 'Claude kuracak'" data-outcome="{{ $kind['key'] }}" />
                        @endif
                    @endforeach
                    @if ($outcome['cost_per'] !== null)
                        <x-ta.metric-card label="Bir temasın maliyeti" :value="$money($outcome['cost_per'], $outcome['spend']['currency'])"
                            :delta="$costDelta === null ? null : ($costDelta > 0 ? '+' : '').$costDelta.'%'" :tone="$costDelta === null || $costDelta === 0 ? 'neutral' : ($costDelta < 0 ? 'positive' : 'negative')"
                            :hint="'Reklam '.$money($outcome['spend']['now'], $outcome['spend']['currency'])" data-outcome="cost" />
                    @elseif ($outcome['spend'] !== null && $outcome['spend']['now'] > 0)
                        <x-ta.metric-card label="Reklam harcaması" :value="$money($outcome['spend']['now'], $outcome['spend']['currency'])" :hint="'Geçen ay '.$money($outcome['spend']['prev'], $outcome['spend']['currency'])" data-outcome="spend" />
                    @else
                        <x-ta.metric-card label="Reklam harcaması" value="Yok" :muted="true" hint="Bu ay reklam verilmedi" data-outcome="spend" />
                    @endif
                </div>
            </div>
        @else
            <div class="card"><div class="card-row">
                <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Bu markada müşteri teması henüz ölçülmüyor.</p>
                    <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">Arama, WhatsApp ve form sayılmadan işin sonucu görünmez. Ölçüm kurulumu Claude’un işi, kurulunca sayılar burada çıkar.</p>
                </div>
            </div></div>
        @endif
    </section>
@endif

{{-- 2. Dijital varlıklar: one row per asset, its measuring tools, one sentence, its doors. --}}
<section data-brand-assets>
    <div class="section-head">
        <h2 class="section-title">Dijital varlıklar <span class="section-count">{{ count($workAssets) }}</span></h2>
        <button type="button" wire:click="setTab('varliklar')" class="ml-auto text-[13px] font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">Tümünü yönet</button>
    </div>
    <div class="card">
        @forelse ($workAssets as $asset)
            <div class="card-row flex-wrap items-start! gap-x-3 gap-y-2 sm:flex-nowrap" wire:key="work-asset-{{ $asset['id'] }}" data-work-asset="{{ $asset['id'] }}" @if ($asset['intent']) data-asset-intent="{{ $asset['intent'] }}" @endif>
                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $dot($asset['tone']) }}" aria-hidden="true"></span>
                <div class="min-w-[75%] flex-1 sm:min-w-0">
                    <div class="flex flex-wrap items-baseline gap-x-2.5 gap-y-0.5">
                        <a href="{{ $asset['url'] }}" wire:navigate class="min-w-0 truncate text-sm font-medium text-gray-900 hover:underline dark:text-white">{{ $asset['name'] }}</a>
                        <span class="text-[13px] text-gray-500">{{ $asset['type_label'] }}</span>
                    </div>
                    @php $said = collect($asset['tools'])->contains(fn (array $t): bool => $asset['line'] === $t['label'].': '.$t['state']); @endphp
                    @if ($asset['line'] !== '' && ($asset['intent'] !== null || ! $said))
                        <p class="mt-0.5 text-[13px] text-gray-600 dark:text-gray-300">{{ $asset['line'] }}</p>
                    @endif
                    @if ($asset['tools'] !== [] && $asset['intent'] === null)
                        <ul class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400" aria-label="Ölçen araçlar">
                            @foreach ($asset['tools'] as $tool)
                                <li class="inline-flex items-center gap-1.5" title="{{ $tool['last'] ? 'Son veri '.$tool['last'] : '' }}"><span class="h-1.5 w-1.5 rounded-full {{ $dot($tool['tone']) }}" aria-hidden="true"></span>{{ $tool['label'] }}: {{ $tool['state'] }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                <div class="flex shrink-0 flex-wrap items-center justify-end gap-x-3 gap-y-1 max-sm:w-full max-sm:justify-start max-sm:pl-5">
                    @foreach (array_slice($asset['doors'], 0, 3) as $door)
                        <x-operator.open-link :link="$door" />
                    @endforeach
                    @if ($isAdmin)
                        <details class="relative" x-data @click.outside="$el.removeAttribute('open')" data-asset-intent-menu>
                            <summary class="link-neutral cursor-pointer list-none text-xs" title="Bu varlık hakkında bildiğini yaz: sistem bir daha uyarı üretmesin">{{ $asset['intent'] ? 'Notu değiştir' : 'Not düş' }}</summary>
                            <div class="absolute right-0 z-30 mt-1 w-64 max-w-[calc(100vw-2rem)] rounded-xl border border-gray-200 bg-white p-1.5 shadow-lg dark:border-gray-700 dark:bg-gray-900">
                                @foreach (\App\Models\DigitalAsset::INTENTS as $key => $label)
                                    <button type="button" wire:click="setAssetIntent({{ $asset['id'] }}, '{{ $key }}')" class="flex w-full rounded-lg px-2.5 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">{{ $label }}</button>
                                @endforeach
                                @if ($asset['intent'])
                                    <button type="button" wire:click="setAssetIntent({{ $asset['id'] }}, '')" class="flex w-full rounded-lg px-2.5 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]">Notu kaldır, yeniden izle</button>
                                @endif
                            </div>
                        </details>
                    @endif
                </div>
            </div>
        @empty
            <div class="card-row"><span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span><p class="min-w-0 flex-1 text-sm text-gray-700 dark:text-gray-300">Bu markaya henüz varlık bağlanmadı.</p><button type="button" wire:click="setTab('varliklar')" class="btn">Varlık bağla</button></div>
        @endforelse
    </div>
</section>

{{-- 3. Eksik bağlantılar: by name, and who closes each. --}}
@if ($missingLinks !== [])
    <section data-brand-missing-links>
        <div class="section-head">
            <h2 class="section-title">Eksik bağlantılar <span class="section-count">{{ count($missingLinks) }}</span></h2>
        </div>
        <div class="card">
            @foreach ($yourMissing as $row)
                <div class="card-row" data-missing-who="you">
                    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>
                    <p class="min-w-0 flex-1 text-sm text-gray-800 dark:text-gray-200">{{ $row['text'] }}</p>
                    @if ($row['url'])<a href="{{ $row['url'] }}" wire:navigate class="btn">{{ $row['action'] }}</a>@endif
                </div>
            @endforeach
            @foreach ($claudeMissing as $row)
                <div class="card-row" data-missing-who="claude">
                    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600" aria-hidden="true"></span>
                    <p class="min-w-0 flex-1 text-sm text-gray-800 dark:text-gray-200">{{ $row['text'] }}</p>
                    <span class="text-[13px] text-gray-500">Claude kuracak</span>
                </div>
            @endforeach
        </div>
    </section>
@endif

{{-- 4. Claude yaptı · Sana kalan --}}
<div class="grid gap-x-6 gap-y-10 lg:grid-cols-2">
    <section data-brand-done>
        <div class="section-head">
            <h2 class="section-title">Claude yaptı</h2>
            <span class="text-sm text-gray-500">son {{ \App\Services\Operator\BrandWorkplace::DONE_DAYS }} gün</span>
            <a href="{{ $claudeDone['flow_url'] }}" wire:navigate class="ml-auto text-[13px] font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">Tümü ve geri al</a>
        </div>
        <div class="card">
            @forelse ($claudeDone['items'] as $item)
                <div class="card-row" data-done-row="{{ $item['id'] }}">
                    <span class="w-[74px] shrink-0 text-xs tabular-nums text-gray-500">{{ $item['at'] }}</span>
                    <p class="min-w-0 flex-1 text-sm text-gray-800 dark:text-gray-200"><span class="font-medium">{{ $item['label'] }}</span>@if ($item['subject'] !== '')<span class="text-gray-500">, {{ $item['subject'] }}</span>@endif</p>
                    @if ($item['undoable'])<span class="shrink-0 text-xs text-gray-500">geri alınabilir</span>@endif
                </div>
            @empty
                <div class="card-row"><p class="text-sm text-gray-500">Son {{ \App\Services\Operator\BrandWorkplace::DONE_DAYS }} günde bu markada yazılan bir iş yok.</p></div>
            @endforelse
            @if ($claudeDone['more'] > 0)
                <a href="{{ $claudeDone['flow_url'] }}" wire:navigate class="card-row text-[13px] text-gray-500 hover:bg-gray-50 dark:hover:bg-white/[0.02]">{{ $claudeDone['more'] }} iş daha</a>
            @endif
        </div>
    </section>

    <section data-brand-yours>
        <div class="section-head">
            <h2 class="section-title">Sana kalan <span class="section-count">{{ count($yours['items']) + $yours['more'] }}</span></h2>
            <a href="{{ $yours['calendar_url'] }}" wire:navigate class="ml-auto text-[13px] font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">Yapılacaklar</a>
        </div>
        <div class="card">
            @forelse ($yours['items'] as $item)
                <div class="card-row" wire:key="yours-{{ $item['id'] }}" data-yours-row="{{ $item['id'] }}">
                    <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $item['late'] ? 'bg-error-500' : 'bg-gray-300 dark:bg-gray-600' }}" aria-hidden="true"></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-gray-900 dark:text-white">{{ $item['title'] }}</p>
                        @if ($item['when'] || $item['ask'])
                            <p class="mt-0.5 text-xs {{ $item['late'] ? 'text-error-600 dark:text-error-400' : 'text-gray-500' }}">{{ $item['ask'] ? 'Claude soruyor' : '' }}{{ $item['ask'] && $item['when'] ? ', ' : '' }}{{ $item['when'] ? ($item['late'] ? 'Gecikti, ' : '').$item['when'] : '' }}</p>
                        @endif
                    </div>
                    @if ($item['url'])<a href="{{ $item['url'] }}" @if (str_starts_with($item['url'], url('/'))) wire:navigate @else target="_blank" rel="noopener noreferrer" @endif class="link-neutral shrink-0 text-xs">Aç</a>@endif
                    @if ($item['ask'])
                        <a href="{{ $yours['calendar_url'] }}" wire:navigate class="btn shrink-0">Yanıtla</a>
                    @else
                        <button type="button" wire:click="finishTodo({{ $item['id'] }})" class="btn shrink-0">Bitti</button>
                    @endif
                </div>
            @empty
                <div class="card-row"><span class="row-icon text-success-600 dark:text-success-400" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg></span><p class="text-sm text-gray-700 dark:text-gray-300">Bu markada senden beklenen bir şey yok.</p></div>
            @endforelse
            @if ($yours['more'] > 0)
                <a href="{{ $yours['calendar_url'] }}" wire:navigate class="card-row text-[13px] text-gray-500 hover:bg-gray-50 dark:hover:bg-white/[0.02]">{{ $yours['more'] }} iş daha</a>
            @endif
        </div>
    </section>
</div>
