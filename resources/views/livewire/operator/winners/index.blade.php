@php
    $channelDot = ['web' => 'bg-gray-500', 'google_ads' => 'bg-warning-400', 'meta' => 'bg-live-400', 'gbp' => 'bg-success-400'];
    $all = \App\Services\Ads\Winners::ALL_CITIES;
    $segment = 'flex h-8 items-center rounded-md px-3 whitespace-nowrap';
    $segmentOn = 'bg-white font-semibold text-gray-900 shadow-theme-xs ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:text-white dark:ring-gray-700';
    $segmentOff = 'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white';
    /** Filter links: the chosen one is dark text with an underline, the rest quiet gray (no filled chips). */
    $chipOn = 'font-medium text-gray-900 underline decoration-gray-900 underline-offset-4 dark:text-white dark:decoration-white';
    $chipOff = 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white';
@endphp

<div class="space-y-8">
    {{-- Normal page head (2026-10-19): the dark / gold hero was a different visual language. --}}
    <div class="min-w-0" data-testid="winners-hero">
        <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white" title="Aynı şehirde aynı hizmeti veren markalar arasında web sitesi, Google Ads, Meta ve İşletme Profili’nde kim önde. Yalnız aynı şehirdeki markalar sıralanır, eşik altı markalar sıralanmaz, sayılar her sabah yenilenir.">Kazananlar</h1>
        <p class="mt-1 text-sm text-gray-500">Son 30 gün</p>
    </div>
    @include('livewire.operator.winners.partials.nav', ['current' => 'operator.winners'])

    <div class="space-y-3">
        <div class="inline-flex max-w-full flex-wrap gap-0.5 rounded-lg bg-gray-100 p-1 text-sm dark:bg-white/5" role="tablist">
            @foreach ($tabs as $key => $label)
                <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')" class="{{ $segment }} {{ $tab === $key ? $segmentOn : $segmentOff }}">{{ $label }}</button>
            @endforeach
        </div>
        @if ($view && $view['cities'] !== [])
            <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm" data-testid="winners-cities">
                @foreach ($view['cities'] as $c)
                    <button type="button" wire:click="$set('city', @js($c['name']))" wire:key="city-{{ $loop->index }}" class="{{ $view['city'] === $c['name'] ? $chipOn : $chipOff }}" title="{{ $c['brands'] }} marka, {{ $c['markets'] }} pazar">
                        {{ $c['name'] }} <span class="text-xs font-normal text-gray-500">{{ $c['brands'] }} marka</span>
                    </button>
                @endforeach
                <button type="button" wire:click="$set('city', '{{ $all }}')" class="{{ $view['city'] === $all ? $chipOn : $chipOff }}">Türkiye geneli</button>
            </div>
        @endif
    </div>

    @if (! $ready)
        <x-ta.empty-state title="Kazananlar tabloları henüz hazır değil" message="Sayılar her sabah hesaplanır."><a href="{{ route('operator.meta-strategy') }}" wire:navigate class="link-neutral text-sm" data-empty-action>Strateji öner’e git</a></x-ta.empty-state>
    @elseif ($tab === 'marka' && $brandView)
        <section data-testid="winners-brand">
            <div class="flex flex-wrap items-end gap-3">
                <label class="w-full text-xs text-gray-500 sm:w-auto">Marka
                    <select wire:model.live="brand" class="mt-1 block w-full rounded-lg border-gray-200 py-1.5 text-sm sm:w-auto dark:border-gray-700 dark:bg-gray-900">
                        @foreach ($brandView['brands'] as $id => $name)<option value="{{ $id }}" @selected($brandView['brand'] === $id)>{{ $name }}</option>@endforeach
                    </select>
                </label>
                @if ($brandView['brand'] !== null)<a href="{{ route('operator.brand', ['brand' => $brandView['brand']]) }}" wire:navigate class="link-neutral pb-1.5 text-sm" data-brand-link>Marka sayfası</a>@endif
                <p class="pb-1.5 text-xs text-gray-500" title="En kalabalık pazar önce.">Markanın yarıştığı pazarlarda geride olduğu kanallar</p>
            </div>
            @if ($brandView['behind'] === [])
                <p class="mt-4 text-sm text-gray-500">{{ $brandView['brand'] === null ? 'Henüz yarışa giren marka yok.' : 'Bu marka yarıştığı hiçbir pazarda geride değil.' }}
                    <button type="button" wire:click="$set('tab', 'pazarlar')" class="link-neutral ml-1" data-empty-action>Pazarlara bak</button></p>
            @else
                <ul class="mt-4 divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                    @foreach ($brandView['behind'] as $r)
                        <li wire:key="behind-{{ $loop->index }}" class="flex flex-wrap items-start gap-x-4 gap-y-1.5 py-3 text-sm text-gray-700 dark:text-gray-300">
                            <div class="min-w-0 flex-1 basis-60">
                                <p class="break-words"><span class="font-medium text-gray-900 dark:text-white">{{ $r['name'] }}</span> <span class="text-xs text-gray-500">{{ $r['city'] }}, {{ $r['competing'] }} marka</span></p>
                                <p class="mt-0.5 text-xs"><span class="mr-1 inline-block h-1.5 w-1.5 rounded-full {{ $channelDot[$r['channel']] }}"></span>{{ $r['label'] }}:
                                    <span class="tabular-nums">bu marka @if ($r['mine']){{ $r['mine']['rank'] }}. / {{ $r['mine']['of'] }}, {{ $r['mine']['value'] }}@else<span class="text-gray-500">eşiği geçmedi</span>@endif</span></p>
                            </div>
                            <div class="min-w-0 flex-1 basis-60 text-xs">
                                <p>Lider <span class="font-medium text-gray-900 dark:text-white">{{ $r['leader'] }}</span> <span class="ml-1 tabular-nums text-gray-500">{{ $r['leader_value'] }}</span></p>
                                @if (filled($r['leader_what']))<p class="mt-0.5 break-words text-gray-500 dark:text-gray-400">{{ $r['leader_what'] }}</p>@endif
                            </div>
                            <div class="flex shrink-0 flex-wrap gap-3 text-xs">
                                @if ($r['channel'] === 'meta' && in_array($r['type'], ['leads', 'messages', 'purchases'], true))
                                    <a href="{{ route('operator.meta-strategy', ['hizmet' => $r['service_id'], 'tur' => $r['type'], 'sehir' => $r['city']]) }}" wire:navigate class="link-neutral">Strateji öner</a>
                                @endif
                                <a href="{{ route('operator.winner-service', ['serviceId' => $r['service_id'], 'sehir' => $r['city']]) }}" wire:navigate class="link-neutral">Pazarı aç</a>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($brandView['leading'] !== [])
                <p class="mt-4 text-xs text-gray-500">Önde olduğu: @foreach ($brandView['leading'] as $l){{ $l['name'] }} ({{ $l['city'] }}, {{ $l['label'] }})@if (! $loop->last), @endif @endforeach</p>
            @endif
        </section>
    @elseif ($view && $view['sectors'] === [])
        <x-ta.empty-state title="Henüz yarışa giren pazar yok" message="Aynı şehirde aynı hizmette en az iki markanın verisi olunca burada görünür; sayılar her sabah hesaplanır." data-testid="winners-empty"><a href="{{ route('operator.meta-strategy') }}" wire:navigate class="link-neutral text-sm" data-empty-action>Strateji öner’e git</a></x-ta.empty-state>
    @elseif ($view)
        @if (count($view['sectors']) > 1)
            <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm" data-testid="winners-sectors">
                @foreach ($view['sectors'] as $s)
                    <button type="button" wire:click="$set('sector', '{{ $s['id'] }}')" wire:key="sector-{{ $s['id'] }}" class="{{ $view['sector'] === $s['id'] ? $chipOn : $chipOff }}">
                        {{ $s['name'] }} <span class="text-xs font-normal text-gray-500">{{ $s['markets'] }} pazar</span>
                    </button>
                @endforeach
            </div>
        @else
            <p class="text-sm font-medium text-gray-700 dark:text-gray-300" data-testid="winners-sectors">{{ $view['sectors'][0]['name'] }}</p>
        @endif

        <section data-testid="winners-markets">
            <h2 class="section-title" title="En az iki markanın yarıştığı ve en az bir kanalda eşiği geçenin olduğu şehir ve hizmet; her kanalın lideri.">Pazarlar <span class="text-sm font-normal text-gray-500">{{ count($view['markets']) }}</span></h2>
            @if ($view['markets'] === [])
                <x-ta.empty-state size="sm" title="Bu şehirde henüz gerçek bir yarış yok.">
                    @if ($view['city'] !== $all)<button type="button" wire:click="$set('city', '{{ $all }}')" class="link-neutral text-sm" data-empty-action>Türkiye geneline bak</button>@endif</x-ta.empty-state>
            @else
                {{-- Compact list (2026-10-19): one row per market, only the channels that have a leader. --}}
                <ul class="mt-2 divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                    @foreach ($view['markets'] as $m)
                        <li wire:key="market-{{ $m['service_id'] }}-{{ $loop->index }}" class="relative flex flex-wrap items-start gap-x-4 gap-y-2 py-3 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                            <div class="min-w-0 flex-1 basis-56">
                                <a href="{{ route('operator.winner-service', ['serviceId' => $m['service_id'], 'sehir' => $m['city']]) }}" wire:navigate class="break-words font-medium text-gray-900 after:absolute after:inset-0 dark:text-white">{{ $m['name'] }}</a>
                                <p class="mt-0.5 text-xs tabular-nums text-gray-500">@if ($view['city'] === $all){{ $m['city'] }}, @endif{{ $m['competing'] }} yarışıyor, {{ $m['eligible'] }} eşikte</p>
                                @if ($m['changed'])<x-ta.pill tone="info" class="mt-1">Lider değişti</x-ta.pill>@endif
                            </div>
                            <dl class="grid min-w-0 flex-[2] basis-72 grid-cols-1 gap-x-4 gap-y-1 text-xs sm:grid-cols-2">
                                @foreach ($view['channels'] as $key)
                                    @php $l = $m['leaders'][$key] ?? null; @endphp
                                    @if ($l)
                                        <div class="min-w-0">
                                            <dt class="inline text-gray-500"><span class="mr-1 inline-block h-1.5 w-1.5 rounded-full {{ $channelDot[$key] }}"></span>{{ $channels[$key] }}:</dt>
                                            <dd class="inline"><span class="font-medium text-gray-900 dark:text-white">{{ $l['brand'] }}</span> <span class="ml-1 tabular-nums text-gray-500">{{ $l['value'] }}</span>@if ($l['gap'])<span class="text-gray-500">, {{ $l['gap'] }}</span>@endif</dd>
                                        </div>
                                    @endif
                                @endforeach
                            </dl>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($view['alone'] !== [])
            <section data-testid="winners-alone">
                <h2 class="section-title" title="Bu şehirde bu hizmette tek marka var; sıra yerine diğer şehirlerdeki aynı hizmete göre durumu.">Rakipsiz pazarlar</h2>
                <ul class="mt-2 divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                    @foreach ($view['alone'] as $a)
                        <li wire:key="alone-{{ $loop->index }}" class="break-words py-2.5 text-sm">
                            <span class="font-medium text-gray-900 dark:text-white">{{ $a['name'] }}</span> <span class="text-xs text-gray-500">{{ $a['city'] }}, {{ $a['brand'] }}</span>
                            @foreach ($a['references'] as $line)<span class="block text-xs text-gray-500 dark:text-gray-400">{{ $line }}</span>@endforeach
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($view['waiting'] > 0 || $view['duplicates'] !== [])
            <div class="space-y-1 text-xs text-gray-500" data-testid="winners-notes">
                @if ($view['waiting'] > 0)<p>{{ $view['waiting'] }} pazarda veri birikiyor: ya tek marka var ve eşiği geçmiyor ya da hiçbir kanalda eşiği geçen yok.</p>@endif
                @foreach ($view['duplicates'] as [$a, $b])<p>Katalogda aynı hizmet iki adla görünüyor: “{{ $a }}” ve “{{ $b }}”. Birleştirilince tek pazarda yarışırlar.</p>@endforeach
            </div>
        @endif
    @endif
</div>
