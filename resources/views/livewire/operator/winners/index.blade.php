@php
    $all = \App\Services\Ads\Winners::ALL_CITIES;
    $chevron = '<svg class="h-3.5 w-3.5 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
    $menu = 'absolute left-0 z-20 mt-1 w-64 max-w-[calc(100vw-2rem)] overflow-hidden rounded-lg border border-gray-200 bg-white py-1 shadow-theme-lg dark:border-gray-800 dark:bg-gray-900';
    $option = 'flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5';
    $logo = fn (?string $name): ?string => $name !== null ? ($logos[$name] ?? null) : null;
@endphp

<div>
    @include('livewire.operator.winners.partials.nav', ['current' => 'operator.winners'])

    <div class="space-y-10">
        <header data-testid="winners-hero">
            <h1 class="page-title" title="Aynı şehirde aynı hizmeti veren markalar arasında web sitesi, Google Ads, Meta ve İşletme Profili’nde kim önde. Yalnız aynı şehirdeki markalar sıralanır, eşik altı markalar sıralanmaz, sayılar her sabah yenilenir.">Kazananlar</h1>
            <p class="page-sub">Son 30 gün. Aynı şehirde aynı hizmeti veren markalar arasında kim önde.</p>
        </header>

        <div class="-mt-4 space-y-4">
            <div class="no-scrollbar flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" role="tablist">
                @foreach ($tabs as $key => $label)
                    <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')" @class(['tab', 'tab-active' => $tab === $key])>
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            @if ($view && ($view['cities'] !== [] || count($view['sectors']) > 0))
                <div class="flex flex-wrap items-center gap-2">
                    @if ($view['cities'] !== [])
                        <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false" data-testid="winners-cities">
                            <button type="button" x-on:click="open = ! open" class="btn" :aria-expanded="open.toString()" aria-haspopup="true">
                                {{ $view['city'] === $all ? 'Türkiye geneli' : $view['city'] }} {!! $chevron !!}
                            </button>
                            <div x-cloak x-show="open" x-transition.opacity class="{{ $menu }}">
                                @foreach ($view['cities'] as $c)
                                    <button type="button" wire:click="$set('city', @js($c['name']))" x-on:click="open = false" wire:key="city-{{ $loop->index }}" title="{{ $c['brands'] }} marka, {{ $c['markets'] }} pazar"
                                        @class([$option, 'font-medium text-gray-900 dark:text-white' => $view['city'] === $c['name'], 'text-gray-700 dark:text-gray-300' => $view['city'] !== $c['name']])>
                                        {{ $c['name'] }} <span class="text-xs font-normal tabular-nums text-gray-500">{{ $c['brands'] }} marka</span>
                                    </button>
                                @endforeach
                                <button type="button" wire:click="$set('city', '{{ $all }}')" x-on:click="open = false"
                                    @class([$option, 'border-t border-gray-200 dark:border-gray-800', 'font-medium text-gray-900 dark:text-white' => $view['city'] === $all, 'text-gray-700 dark:text-gray-300' => $view['city'] !== $all])>Türkiye geneli</button>
                            </div>
                        </div>
                    @endif
                    @if (count($view['sectors']) > 1)
                        @php $currentSector = collect($view['sectors'])->firstWhere('id', $view['sector']); @endphp
                        <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false" data-testid="winners-sectors">
                            <button type="button" x-on:click="open = ! open" class="btn" :aria-expanded="open.toString()" aria-haspopup="true">
                                {{ $currentSector['name'] ?? 'Sektör' }} {!! $chevron !!}
                            </button>
                            <div x-cloak x-show="open" x-transition.opacity class="{{ $menu }}">
                                @foreach ($view['sectors'] as $s)
                                    <button type="button" wire:click="$set('sector', '{{ $s['id'] }}')" x-on:click="open = false" wire:key="sector-{{ $s['id'] }}"
                                        @class([$option, 'font-medium text-gray-900 dark:text-white' => $view['sector'] === $s['id'], 'text-gray-700 dark:text-gray-300' => $view['sector'] !== $s['id']])>
                                        {{ $s['name'] }} <span class="text-xs font-normal tabular-nums text-gray-500">{{ $s['markets'] }} pazar</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @elseif (count($view['sectors']) === 1)
                        <span class="px-1 text-[13px] text-gray-500" data-testid="winners-sectors">{{ $view['sectors'][0]['name'] }}</span>
                    @endif
                </div>
            @endif
        </div>

        @if (! $ready)
            <div class="card">
                <x-ta.empty-state title="Kazananlar tabloları henüz hazır değil" message="Sayılar her sabah hesaplanır."><a href="{{ route('operator.meta-strategy') }}" wire:navigate class="btn" data-empty-action>Strateji öner’e git</a></x-ta.empty-state>
            </div>
        @elseif ($tab === 'marka' && $brandView)
            <section class="space-y-10" data-testid="winners-brand">
                <div class="-mt-6 flex flex-wrap items-center gap-x-3 gap-y-2">
                    <select wire:model.live="brand" aria-label="Marka" class="h-9 w-full rounded-lg border border-gray-200 bg-white py-0 pl-3 text-sm sm:w-72 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        @foreach ($brandView['brands'] as $id => $name)<option value="{{ $id }}" @selected($brandView['brand'] === $id)>{{ $name }}</option>@endforeach
                    </select>
                    @if ($brandView['brand'] !== null)<a href="{{ route('operator.brand', ['brand' => $brandView['brand']]) }}" wire:navigate class="link-neutral text-[13px]" data-brand-link>Marka sayfası</a>@endif
                </div>

                <div>
                    <div class="section-head">
                        <h2 class="section-title" title="Markanın yarıştığı pazarlarda başka bir markanın önde olduğu kanallar; en kalabalık pazar önce.">Geride olduğu kanallar <span class="section-count">{{ count($brandView['behind']) }}</span></h2>
                    </div>
                    <ul class="card">
                        @forelse ($brandView['behind'] as $r)
                            <li wire:key="behind-{{ $loop->index }}" class="card-row flex-wrap items-start sm:flex-nowrap">
                                <div class="grid min-w-0 flex-1 gap-x-6 gap-y-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.2fr)]">
                                    <div class="min-w-0">
                                        <p class="break-words text-sm font-medium text-gray-900 dark:text-white">{{ $r['name'] }}</p>
                                        <p class="mt-0.5 text-[13px] text-gray-500">{{ $r['city'] }}, {{ $r['competing'] }} marka</p>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-xs text-gray-500">{{ $r['label'] }}, bu marka</p>
                                        <p class="mt-0.5 text-[13px] tabular-nums text-gray-900 dark:text-white">@if ($r['mine']){{ $r['mine']['rank'] }}. / {{ $r['mine']['of'] }}, {{ $r['mine']['value'] }}@else<span class="text-warning-700 dark:text-warning-400">eşiği geçmedi</span>@endif</p>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="flex min-w-0 items-center gap-1.5 text-xs text-gray-500">Lider <x-ta.brand-avatar :name="$r['leader']" :logo="$logo($r['leader'])" size="xs" /><span class="truncate font-medium text-gray-900 dark:text-white">{{ $r['leader'] }}</span></p>
                                        <p class="mt-0.5 text-[13px] tabular-nums text-gray-900 dark:text-white">{{ $r['leader_value'] }}</p>
                                        @if (filled($r['leader_what']))<p class="mt-0.5 break-words text-xs text-gray-500 dark:text-gray-400">{{ $r['leader_what'] }}</p>@endif
                                    </div>
                                </div>
                                <div class="flex w-full shrink-0 items-center gap-2 sm:w-52 sm:justify-end">
                                    @if ($r['channel'] === 'meta' && in_array($r['type'], ['leads', 'messages', 'purchases'], true))
                                        <a href="{{ route('operator.meta-strategy', ['hizmet' => $r['service_id'], 'tur' => $r['type'], 'sehir' => $r['city']]) }}" wire:navigate class="btn">Strateji öner</a>
                                    @endif
                                    <a href="{{ route('operator.winner-service', ['serviceId' => $r['service_id'], 'sehir' => $r['city']]) }}" wire:navigate class="btn-quiet first:-ml-2.5 sm:first:ml-0">Pazarı aç</a>
                                </div>
                            </li>
                        @empty
                            <li>
                                <x-ta.empty-state :title="$brandView['brand'] === null ? 'Henüz yarışa giren marka yok.' : 'Bu marka yarıştığı hiçbir pazarda geride değil.'">
                                    <button type="button" wire:click="$set('tab', 'pazarlar')" class="btn" data-empty-action>Pazarlara bak</button>
                                </x-ta.empty-state>
                            </li>
                        @endforelse
                    </ul>
                </div>

                @if ($brandView['leading'] !== [])
                    <div>
                        <div class="section-head"><h2 class="section-title">Önde olduğu kanallar <span class="section-count">{{ count($brandView['leading']) }}</span></h2></div>
                        <ul class="card">
                            @foreach ($brandView['leading'] as $l)
                                <li wire:key="leading-{{ $loop->index }}" class="card-row flex-wrap sm:flex-nowrap">
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $l['name'] }}</p>
                                        <p class="mt-0.5 text-[13px] text-gray-500">{{ $l['city'] }}, {{ $l['label'] }}</p>
                                    </div>
                                    <span class="text-[13px] tabular-nums text-gray-600 dark:text-gray-300">{{ $l['value'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>
        @elseif ($view && $view['sectors'] === [])
            <div class="card" data-testid="winners-empty">
                <x-ta.empty-state title="Henüz yarışa giren pazar yok" message="Aynı şehirde aynı hizmette en az iki markanın verisi olunca burada görünür; sayılar her sabah hesaplanır."><a href="{{ route('operator.meta-strategy') }}" wire:navigate class="btn" data-empty-action>Strateji öner’e git</a></x-ta.empty-state>
            </div>
        @elseif ($view)
            <section data-testid="winners-markets">
                <div class="section-head">
                    <h2 class="section-title" title="En az iki markanın yarıştığı ve en az bir kanalda eşiği geçenin olduğu şehir ve hizmet; her kanalın lideri.">Pazarlar <span class="section-count">{{ count($view['markets']) }}</span></h2>
                    <span class="ml-auto text-[13px] text-gray-500">Her kanalın lideri</span>
                </div>
                @if ($view['markets'] === [])
                    <div class="card">
                        <x-ta.empty-state size="sm" title="Bu şehirde henüz gerçek bir yarış yok.">
                            @if ($view['city'] !== $all)<button type="button" wire:click="$set('city', '{{ $all }}')" class="btn" data-empty-action>Türkiye geneline bak</button>@endif</x-ta.empty-state>
                    </div>
                @else
                    <div class="card overflow-x-auto">
                        <table class="data-table md:table-fixed">
                            <thead>
                                <tr>
                                    <th class="md:w-[24%]">Pazar</th>
                                    @foreach ($view['channels'] as $key)<th class="hidden md:table-cell">{{ $channels[$key] }}</th>@endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($view['markets'] as $m)
                                    <tr wire:key="market-{{ $m['service_id'] }}-{{ $loop->index }}">
                                        <td class="align-top">
                                            <a href="{{ route('operator.winner-service', ['serviceId' => $m['service_id'], 'sehir' => $m['city']]) }}" wire:navigate class="break-words font-medium text-gray-900 hover:underline dark:text-white">{{ $m['name'] }}</a>
                                            <p class="mt-0.5 text-[13px] tabular-nums text-gray-500">@if ($view['city'] === $all){{ $m['city'] }}, @endif{{ $m['competing'] }} yarışıyor, {{ $m['eligible'] }} eşikte</p>
                                            @if ($m['changed'])<x-ta.pill tone="look" class="mt-1.5">Lider değişti</x-ta.pill>@endif
                                            {{-- Phone: the leaders under the market, one line each. --}}
                                            <dl class="mt-2.5 space-y-2 md:hidden">
                                                @foreach ($view['channels'] as $key)
                                                    @php $l = $m['leaders'][$key] ?? null; @endphp
                                                    @if ($l)
                                                        <div class="min-w-0">
                                                            <dt class="text-xs text-gray-500">{{ $channels[$key] }}</dt>
                                                            <dd class="flex min-w-0 items-center gap-1.5 text-[13px]"><x-ta.brand-avatar :name="$l['brand']" :logo="$logo($l['brand'])" size="xs" /><span class="truncate font-medium text-gray-900 dark:text-white">{{ $l['brand'] }}</span><span class="shrink-0 tabular-nums text-gray-500">{{ $l['value'] }}</span></dd>
                                                        </div>
                                                    @endif
                                                @endforeach
                                            </dl>
                                        </td>
                                        @foreach ($view['channels'] as $key)
                                            @php $l = $m['leaders'][$key] ?? null; @endphp
                                            <td class="hidden align-top md:table-cell">
                                                @if ($l)
                                                    <span class="flex min-w-0 items-center gap-2" title="{{ $l['brand'] }}">
                                                        <x-ta.brand-avatar :name="$l['brand']" :logo="$logo($l['brand'])" size="sm" />
                                                        <span class="truncate font-medium text-gray-900 dark:text-white">{{ $l['brand'] }}</span>
                                                    </span>
                                                    <p class="mt-1 text-[13px] tabular-nums text-gray-700 dark:text-gray-300">{{ $l['value'] }}</p>
                                                    @if ($l['gap'])<p class="mt-0.5 text-xs text-gray-500">{{ $l['gap'] }}</p>@endif
                                                @else
                                                    <span class="text-gray-400">—</span>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            @if ($view['alone'] !== [])
                <section data-testid="winners-alone">
                    <div class="section-head">
                        <h2 class="section-title" title="Bu şehirde bu hizmette tek marka var; sıra yerine diğer şehirlerdeki aynı hizmete göre durumu.">Rakipsiz pazarlar <span class="section-count">{{ count($view['alone']) }}</span></h2>
                    </div>
                    <ul class="card">
                        @foreach ($view['alone'] as $a)
                            <li wire:key="alone-{{ $loop->index }}" class="card-row items-start">
                                <x-ta.brand-avatar :name="$a['brand']" :logo="$logo($a['brand'])" />
                                <div class="min-w-0 flex-1">
                                    <p class="break-words text-sm font-medium text-gray-900 dark:text-white">{{ $a['name'] }} <span class="font-normal text-gray-500">{{ $a['city'] }}, {{ $a['brand'] }}</span></p>
                                    @foreach ($a['references'] as $line)<p class="mt-0.5 break-words text-[13px] text-gray-500 dark:text-gray-400">{{ $line }}</p>@endforeach
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($view['waiting'] > 0 || $view['duplicates'] !== [])
                <div class="space-y-1 text-[13px] text-gray-500" data-testid="winners-notes">
                    @if ($view['waiting'] > 0)<p>{{ $view['waiting'] }} pazarda veri birikiyor: ya tek marka var ve eşiği geçmiyor ya da hiçbir kanalda eşiği geçen yok.</p>@endif
                    @foreach ($view['duplicates'] as [$a, $b])<p>Katalogda aynı hizmet iki adla görünüyor: “{{ $a }}” ve “{{ $b }}”. Birleştirilince tek pazarda yarışırlar.</p>@endforeach
                </div>
            @endif
        @endif
    </div>
</div>
