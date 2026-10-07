@php
    $panel = 'rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
    $select = 'mt-1 block rounded-lg border-white/20 bg-white/10 py-1.5 text-sm text-white [&>option]:text-gray-900';
    $channelDot = ['web' => 'bg-sky-400', 'google_ads' => 'bg-amber-400', 'meta' => 'bg-indigo-400', 'gbp' => 'bg-emerald-400'];
@endphp

<div class="space-y-5">
    @include('livewire.operator.winners.partials.nav', ['current' => 'operator.winners'])

    <section class="relative overflow-hidden rounded-2xl bg-[#14171f] px-6 py-7 text-white" data-testid="winners-hero">
        <div class="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full bg-[#d8b45e]/15 blur-2xl"></div>
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-[#d8b45e]">Markalar arası · hizmet bazında yarış</p>
        <h1 class="mt-2 font-serif text-4xl tracking-tight">Kazananlar</h1>
        <p class="mt-2 max-w-2xl text-sm text-white/70">Her hizmette hangi markanın sayfası, Google Ads kampanyası, Meta kampanyası ve İşletme Profili önde. Bir hizmete gir, kazananın neyi farklı yaptığını gör.</p>
        @if ($ready && $view)
            <div class="mt-5 flex flex-wrap items-end gap-4">
                <label class="text-xs text-white/60">Şehir
                    <select wire:model.live="city" class="{{ $select }}"><option value="">Türkiye geneli</option>@foreach ($view['cities'] as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select>
                </label>
                <p class="text-xs text-white/50">Son 30 gün · eşik altı markalar sıralanmaz · sayılar her sabah yenilenir</p>
            </div>
        @endif
    </section>

    @if (! $ready || ! $view)
        <p class="{{ $panel }} p-4 text-sm text-gray-500">Kazananlar tabloları henüz hazır değil.</p>
    @elseif ($view['sectors'] === [])
        <p class="{{ $panel }} p-4 text-sm text-gray-500" data-testid="winners-empty">Henüz yarışa giren hizmet yok. Aynı hizmette en az iki markanın verisi olunca burada görünür; sayılar her sabah hesaplanır.</p>
    @else
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" data-testid="winners-sectors">
            @foreach ($view['sectors'] as $s)
                <button type="button" wire:click="$set('sector', '{{ $s['id'] }}')" wire:key="sector-{{ $s['id'] }}" @class([$panel, 'p-4 text-left transition hover:ring-[#d8b45e]',
                    'ring-2 ring-[#d8b45e] dark:ring-[#d8b45e]' => $view['sector'] === $s['id']])>
                    <p class="font-serif text-xl text-gray-900 dark:text-white">{{ $s['name'] }}</p>
                    <p class="mt-1 text-xs text-gray-500">{{ $s['brands'] }} marka · {{ $s['services'] }} hizmet yarışta</p>
                </button>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500">
            @foreach ($channels as $key => $label)<span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full {{ $channelDot[$key] }}"></span>{{ $label }}</span>@endforeach
            <span class="flex-1"></span>
            <span>Hizmet kartında her kanalın lideri</span>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" data-testid="winners-services">
            @foreach ($view['services'] as $svc)
                <a href="{{ route('operator.winner-service', ['serviceId' => $svc['id'], 'sehir' => $this->city !== '' ? $this->city : null]) }}" wire:navigate wire:key="svc-{{ $svc['id'] }}"
                    class="{{ $panel }} group block p-5 transition hover:-translate-y-0.5 hover:shadow-lg">
                    <div class="flex items-start gap-2">
                        <h2 class="min-w-0 flex-1 font-serif text-xl text-gray-900 group-hover:text-[#a8842f] dark:text-white">{{ $svc['name'] }}</h2>
                        @if ($svc['changed'])<span class="rounded-full bg-[#fbf3dd] px-2 py-0.5 text-[11px] font-semibold text-[#8a6a1f]">Lider değişti</span>@endif
                    </div>
                    <p class="text-xs text-gray-500">{{ $svc['competing'] }} marka yarışıyor · eşiği geçen {{ $svc['eligible'] }}</p>
                    <dl class="mt-4 space-y-2">
                        @foreach ($channels as $key => $label)
                            @php $l = $svc['leaders'][$key] ?? null; @endphp
                            <div class="flex items-center gap-2 text-sm">
                                <span class="h-2 w-2 shrink-0 rounded-full {{ $channelDot[$key] }}"></span>
                                <dt class="w-28 shrink-0 text-xs text-gray-500">{{ $label }}</dt>
                                @if ($l)
                                    <dd class="min-w-0 flex-1 truncate"><span class="font-semibold text-gray-900 dark:text-white">{{ $l['brand'] }}</span> <span class="text-xs text-gray-500">{{ $l['value'] }}</span></dd>
                                @else
                                    <dd class="text-xs text-gray-400">eşiği geçen yok</dd>
                                @endif
                            </div>
                        @endforeach
                    </dl>
                </a>
            @endforeach
        </div>
    @endif
</div>
