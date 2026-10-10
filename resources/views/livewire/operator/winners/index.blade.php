@php
    $panel = 'rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
    $select = 'mt-1 block rounded-lg border-white/20 bg-white/10 py-1.5 text-sm text-white [&>option]:text-gray-900';
    $channelDot = ['web' => 'bg-sky-400', 'google_ads' => 'bg-amber-400', 'meta' => 'bg-indigo-400', 'gbp' => 'bg-emerald-400'];
    $all = \App\Services\Ads\Winners::ALL_CITIES;
@endphp

<div class="space-y-5">
    @include('livewire.operator.winners.partials.nav', ['current' => 'operator.winners'])

    <section class="relative overflow-hidden rounded-2xl bg-[#14171f] px-6 py-7 text-white" data-testid="winners-hero">
        <div class="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full bg-[#d8b45e]/15 blur-2xl"></div>
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-[#d8b45e]">Markalar arası · şehir ve hizmet bazında yarış</p>
        <h1 class="mt-2 font-serif text-4xl tracking-tight">Kazananlar</h1>
        <p class="mt-2 max-w-2xl text-sm text-white/70">Aynı şehirde aynı hizmeti veren markalar arasında web sitesi, Google Ads, Meta ve İşletme Profili’nde kim önde. Bir pazara gir, kazananın neyi farklı yaptığını gör.</p>
        <div class="mt-5 flex flex-wrap gap-1 rounded-lg bg-white/10 p-0.5 text-sm sm:inline-flex" role="tablist">
            @foreach ($tabs as $key => $label)
                <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['rounded-md px-3 py-1 font-medium', 'bg-[#d8b45e] text-[#14171f]' => $tab === $key, 'text-white/70' => $tab !== $key])>{{ $label }}</button>
            @endforeach
        </div>
        @if ($view && $view['cities'] !== [])
            <div class="mt-4 flex flex-wrap gap-2" data-testid="winners-cities">
                @foreach ($view['cities'] as $c)
                    <button type="button" wire:click="$set('city', @js($c['name']))" wire:key="city-{{ $loop->index }}" @class(['rounded-full px-3 py-1 text-xs ring-1 ring-inset',
                        'bg-[#d8b45e] text-[#14171f] ring-[#d8b45e]' => $view['city'] === $c['name'], 'text-white/80 ring-white/20 hover:ring-[#d8b45e]' => $view['city'] !== $c['name']])>
                        <span class="font-semibold">{{ $c['name'] }}</span> · {{ $c['brands'] }} marka · {{ $c['markets'] }} pazar
                    </button>
                @endforeach
                <button type="button" wire:click="$set('city', '{{ $all }}')" @class(['rounded-full px-3 py-1 text-xs ring-1 ring-inset',
                    'bg-[#d8b45e] text-[#14171f] ring-[#d8b45e]' => $view['city'] === $all, 'text-white/80 ring-white/20 hover:ring-[#d8b45e]' => $view['city'] !== $all])>Türkiye geneli</button>
            </div>
        @endif
        <p class="mt-3 text-xs text-white/50">Son 30 gün · yalnız aynı şehirdeki markalar sıralanır · eşik altı markalar sıralanmaz · sayılar her sabah yenilenir</p>
    </section>

    @if (! $ready)
        <p class="{{ $panel }} p-4 text-sm text-gray-500">Kazananlar tabloları henüz hazır değil.</p>
    @elseif ($tab === 'marka' && $brandView)
        <section class="{{ $panel }} p-5" data-testid="winners-brand">
            <div class="flex flex-wrap items-end gap-3">
                <label class="text-xs text-gray-500">Marka
                    <select wire:model.live="brand" class="mt-1 block rounded-lg border-gray-300 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900">
                        @foreach ($brandView['brands'] as $id => $name)<option value="{{ $id }}" @selected($brandView['brand'] === $id)>{{ $name }}</option>@endforeach
                    </select>
                </label>
                @if ($brandView['brand'] !== null)<a href="{{ route('operator.brand', ['brand' => $brandView['brand']]) }}" wire:navigate class="pb-1.5 text-sm font-semibold text-brand-600 hover:underline" data-brand-link>Marka sayfası →</a>@endif
                <p class="text-xs text-gray-500">Markanın yarıştığı pazarlarda geride olduğu kanallar; en kalabalık pazar önce.</p>
            </div>
            @if ($brandView['behind'] === [])
                <p class="mt-4 text-sm text-gray-500">{{ $brandView['brand'] === null ? 'Henüz yarışa giren marka yok.' : 'Bu marka yarıştığı hiçbir pazarda geride değil.' }}</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full min-w-[56rem] text-sm">
                        <thead class="bg-gray-50 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/[0.02]">
                            <tr><th class="px-3 py-2.5">Pazar</th><th class="px-3 py-2.5">Kanal</th><th class="px-3 py-2.5">Bu marka</th><th class="px-3 py-2.5">Lider</th><th class="px-3 py-2.5">Liderin tarifi</th><th class="px-3 py-2.5"></th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($brandView['behind'] as $r)
                                <tr wire:key="behind-{{ $loop->index }}" class="align-top text-gray-700 dark:text-gray-300">
                                    <td class="px-3 py-3"><span class="font-semibold text-gray-900 dark:text-white">{{ $r['name'] }}</span><span class="block text-xs text-gray-500">{{ $r['city'] }} · {{ $r['competing'] }} marka</span></td>
                                    <td class="px-3 py-3 text-xs"><span class="mr-1 inline-block h-2 w-2 rounded-full {{ $channelDot[$r['channel']] }}"></span>{{ $r['label'] }}</td>
                                    <td class="px-3 py-3 text-xs tabular-nums">@if ($r['mine']){{ $r['mine']['rank'] }}. / {{ $r['mine']['of'] }} · {{ $r['mine']['value'] }}@else<span class="text-gray-400">eşiği geçmedi</span>@endif</td>
                                    <td class="px-3 py-3 text-xs"><span class="font-semibold text-gray-900 dark:text-white">{{ $r['leader'] }}</span><span class="block tabular-nums text-gray-500">{{ $r['leader_value'] }}</span></td>
                                    <td class="max-w-xs px-3 py-3 text-xs text-gray-600 dark:text-gray-400">{{ $r['leader_what'] }}</td>
                                    <td class="px-3 py-3 text-right text-xs">
                                        @if ($r['channel'] === 'meta' && in_array($r['type'], ['leads', 'messages', 'purchases'], true))
                                            <a href="{{ route('operator.meta-strategy', ['hizmet' => $r['service_id'], 'tur' => $r['type'], 'sehir' => $r['city']]) }}" wire:navigate class="font-semibold text-brand-600 hover:underline">Strateji öner →</a>
                                        @endif
                                        <a href="{{ route('operator.winner-service', ['serviceId' => $r['service_id'], 'sehir' => $r['city']]) }}" wire:navigate class="ml-2 font-semibold text-brand-600 hover:underline">Pazarı aç →</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if ($brandView['leading'] !== [])
                <p class="mt-4 text-xs text-gray-500">Önde olduğu: @foreach ($brandView['leading'] as $l){{ $l['name'] }} ({{ $l['city'] }}, {{ $l['label'] }})@if (! $loop->last) · @endif @endforeach</p>
            @endif
        </section>
    @elseif ($view && $view['sectors'] === [])
        <p class="{{ $panel }} p-4 text-sm text-gray-500" data-testid="winners-empty">Henüz yarışa giren pazar yok. Aynı şehirde aynı hizmette en az iki markanın verisi olunca burada görünür; sayılar her sabah hesaplanır.</p>
    @elseif ($view)
        @if (count($view['sectors']) > 1)
            <div class="flex flex-wrap gap-2" data-testid="winners-sectors">
                @foreach ($view['sectors'] as $s)
                    <button type="button" wire:click="$set('sector', '{{ $s['id'] }}')" wire:key="sector-{{ $s['id'] }}" @class(['rounded-full px-3 py-1 text-sm ring-1 ring-inset',
                        'bg-[#14171f] text-[#e9cf8a] ring-[#14171f]' => $view['sector'] === $s['id'], 'text-gray-700 ring-gray-200 hover:ring-[#d8b45e] dark:text-gray-300 dark:ring-gray-700' => $view['sector'] !== $s['id']])>
                        {{ $s['name'] }} · {{ $s['markets'] }} pazar
                    </button>
                @endforeach
            </div>
        @else
            <p class="text-sm font-semibold text-gray-700 dark:text-gray-300" data-testid="winners-sectors">{{ $view['sectors'][0]['name'] }}</p>
        @endif

        <section class="{{ $panel }} overflow-hidden" data-testid="winners-markets">
            <div class="flex flex-wrap items-baseline gap-2 border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                <h2 class="font-serif text-xl text-gray-900 dark:text-white">Pazarlar</h2>
                <p class="text-xs text-gray-500">En az iki markanın yarıştığı ve en az bir kanalda eşiği geçenin olduğu şehir · hizmet; her kanalın lideri.</p>
            </div>
            @if ($view['markets'] === [])
                <p class="px-5 py-6 text-sm text-gray-500">Bu şehirde henüz gerçek bir yarış yok.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[48rem] text-sm">
                        <thead class="bg-gray-50 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/[0.02]">
                            <tr><th class="px-5 py-2.5">Pazar</th><th class="px-3 py-2.5">Marka</th>@foreach ($view['channels'] as $key)<th class="px-3 py-2.5"><span class="mr-1 inline-block h-2 w-2 rounded-full {{ $channelDot[$key] }}"></span>{{ $channels[$key] }}</th>@endforeach</tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($view['markets'] as $m)
                                <tr wire:key="market-{{ $m['service_id'] }}-{{ $loop->index }}" class="align-top text-gray-700 dark:text-gray-300">
                                    <td class="px-5 py-3">
                                        <a href="{{ route('operator.winner-service', ['serviceId' => $m['service_id'], 'sehir' => $m['city']]) }}" wire:navigate class="font-semibold text-gray-900 hover:text-[#a8842f] dark:text-white">{{ $m['name'] }}</a>
                                        @if ($view['city'] === $all)<span class="block text-xs text-gray-500">{{ $m['city'] }}</span>@endif
                                        @if ($m['changed'])<span class="mt-1 inline-block rounded-full bg-[#fbf3dd] px-2 py-0.5 text-[11px] font-semibold text-[#8a6a1f]">Lider değişti</span>@endif
                                    </td>
                                    <td class="px-3 py-3 text-xs tabular-nums text-gray-500">{{ $m['competing'] }} yarışıyor · {{ $m['eligible'] }} eşikte</td>
                                    @foreach ($view['channels'] as $key)
                                        @php $l = $m['leaders'][$key] ?? null; @endphp
                                        <td class="px-3 py-3 text-xs">
                                            @if ($l)
                                                <span class="font-semibold text-gray-900 dark:text-white">{{ $l['brand'] }}</span>
                                                <span class="block tabular-nums text-gray-500">{{ $l['value'] }}</span>
                                                @if ($l['gap'])<span class="block text-gray-400">{{ $l['gap'] }}</span>@endif
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
            <section class="{{ $panel }} p-5" data-testid="winners-alone">
                <h2 class="font-semibold text-gray-900 dark:text-white">Rakipsiz pazarlar</h2>
                <p class="text-xs text-gray-500">Bu şehirde bu hizmette tek marka var; sıra yerine diğer şehirlerdeki aynı hizmete göre durumu.</p>
                <ul class="mt-3 space-y-3">
                    @foreach ($view['alone'] as $a)
                        <li wire:key="alone-{{ $loop->index }}" class="text-sm">
                            <span class="font-semibold text-gray-900 dark:text-white">{{ $a['name'] }}</span> <span class="text-xs text-gray-500">{{ $a['city'] }} · {{ $a['brand'] }}</span>
                            @foreach ($a['references'] as $line)<span class="block text-xs text-gray-600 dark:text-gray-400">{{ $line }}</span>@endforeach
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
