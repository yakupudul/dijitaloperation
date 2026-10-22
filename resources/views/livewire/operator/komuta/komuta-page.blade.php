@php
    use App\Services\Goals\TargetImpact;

    $n = fn (int $v): string => number_format($v, 0, ',', '.');
    $now = now('Europe/Istanbul');
    $greeting = $now->hour < 12 ? 'Günaydın' : ($now->hour < 18 ? 'İyi günler' : 'İyi akşamlar');
    $firstName = \Illuminate\Support\Str::before(trim((string) (auth()->user()?->name ?? '')).' ', ' ');
    $decisions = $box['decisions'];
    $withGoal = $portfolio['ahead'] + $portfolio['on_track'] + $portfolio['behind'];
@endphp
{{-- Komuta (Aşama 5, prototype approved 2026-10-22): one decision box for every brand; keys J/K, Enter, A, X, G. --}}
<div class="pb-24" data-komuta
    x-data="{
        at: -1,
        items() { return [...this.$root.querySelectorAll('[data-komuta-item]')] },
        go(step) { const list = this.items(); if (!list.length) return; this.at = Math.max(0, Math.min(list.length - 1, this.at + step)); list[this.at].focus(); list[this.at].scrollIntoView({ block: 'nearest' }) },
        act(name) { const el = this.items()[this.at] ?? document.activeElement?.closest('[data-komuta-item]'); el?.querySelector('[data-act=' + name + ']')?.click() },
        key(e) {
            if (e.metaKey || e.ctrlKey || e.altKey || ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName) || e.target.isContentEditable) return;
            const k = e.key.toLowerCase();
            if (k === 'j') { e.preventDefault(); this.go(1) } else if (k === 'k') { e.preventDefault(); this.go(-1) }
            else if (k === 'enter' && this.at >= 0) { e.preventDefault(); this.act('open') }
            else if (k === 'a' && this.at >= 0) { e.preventDefault(); this.act('approve') }
            else if (k === 'x' && this.at >= 0) { e.preventDefault(); this.act('dismiss') }
            else if (k === 'g') { document.querySelector('[data-daily-send-button]')?.click() }
            else if (k === '2') { window.Livewire.navigate('{{ route('operator.flow') }}') }
        },
    }" @keydown.window="key($event)">

    <header>
        <h1 class="page-title text-balance" data-komuta-heading>
            {{ $greeting }}{{ $firstName !== '' ? ' '.$firstName : '' }}@if ($decisions > 0), <span class="highlight">{{ $n($decisions) }} karar</span> seni bekliyor @else, bugün senden beklenen karar yok @endif
        </h1>
        <p class="page-sub mt-1.5! text-[15px]!">
            @if ($withGoal > 0)
                Bu ay {{ $n($withGoal) }} markanın {{ $n($portfolio['ahead'] + $portfolio['on_track']) }} tanesi amacında{{ $portfolio['behind'] > 0 ? ', '.$n($portfolio['behind']).' tanesi geride' : '' }}.
                <a href="{{ route('operator.portfolio') }}" wire:navigate class="link-neutral">Portföy</a>
            @else
                Benzer işler tek grupta, en çok talep getirecek olan en üstte. Her iş geri alınabilir.
            @endif
        </p>
    </header>

    @if ($loss->isNotEmpty())
        <a href="{{ route('operator.today') }}#alarm-verenler" wire:navigate class="mt-6 flex items-center gap-3 rounded-2xl border border-error-100 bg-error-50 px-4 py-3 text-sm text-error-800 dark:border-error-500/20 dark:bg-error-500/[0.07] dark:text-error-200" data-komuta-loss>
            <span class="h-2 w-2 shrink-0 rounded-full bg-error-500"></span>
            <span class="min-w-0 flex-1"><b class="font-semibold">{{ $loss->count() }} hesap para ya da trafik kaybediyor:</b> {{ $loss->pluck('brand')->filter()->unique()->take(3)->implode(', ') }}</span>
            <span class="shrink-0 font-medium">Bak →</span>
        </a>
    @endif

    <div class="mt-7">
        <livewire:operator.repair.daily-send-strip lazy />
    </div>

    @if ($message !== '')
        <p class="mt-5 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300" data-komuta-message>{{ $message }} <a href="{{ route('operator.flow') }}" wire:navigate class="link-neutral">Akışta izle</a></p>
    @endif

    <section class="mt-10" data-komuta-box>
        <div class="section-head">
            <h2 class="section-title">Karar kutusu <span class="section-count">{{ $n($decisions) }}</span></h2>
            <p class="ml-auto hidden text-xs text-gray-400 sm:block">J / K gez · Enter aç · A onayla · X gerek yok · G günü gönder</p>
        </div>
        @if (($box['failed'] ?? false) === true)
            <div class="card border-warning-200 bg-warning-50 px-4 py-3 text-[13px] text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-200" data-komuta-unread>Onarım masası şu an okunamadı; bu kutudaki sayılar bilinmiyor, sıfır değil.</div>
        @endif
        <div class="space-y-3">
            @forelse ($box['groups'] as $group)
                <div class="card lift" wire:key="group-{{ $group['key'] }}" data-komuta-group="{{ $group['key'] }}">
                    <div class="flex flex-wrap items-center gap-3 px-4 py-3.5">
                        @if ($group['kind'] === 'marka')
                            <span class="grid h-[34px] w-[34px] shrink-0 place-items-center rounded-[10px] bg-accent-soft text-gray-900 dark:text-white" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/></svg></span>
                        @else
                            @include('livewire.operator.flow.partials.kind-icon', ['kind' => $group['kind']])
                        @endif
                        <div class="min-w-0 flex-1">
                            <p class="text-[15px] font-semibold tracking-[-0.01em] text-gray-900 dark:text-white">{{ $group['title'] }}</p>
                            <p class="text-[13px] text-gray-500 dark:text-gray-400">{{ $group['line'] }}@if ($group['impact'] !== null) · <span class="font-medium text-success-700 dark:text-success-400">tahminen {{ TargetImpact::label($group['impact']) }}</span>@endif</p>
                        </div>
                        @if ($isAdmin && $group['bulk'] && count($group['items']) > 1)
                            <button type="button" wire:click="approveGroup(@js($group['key']))" wire:loading.attr="disabled" wire:confirm="Bu gruptaki tek tıkla onaylanabilir işlerin hepsi gönderilsin mi? Her biri geri alınabilir." class="btn-dark w-full sm:w-auto" data-komuta-group-approve>Hepsini onayla</button>
                        @endif
                    </div>
                    <ul class="border-t border-gray-200 dark:border-gray-800">
                        @foreach ($group['items'] as $item)
                            <li tabindex="0" wire:key="item-{{ md5($item['key']) }}" data-komuta-item="{{ $item['key'] }}"
                                class="grid grid-cols-1 items-center gap-2 border-t border-gray-100 px-4 py-2.5 outline-none first:border-t-0 focus:bg-accent-soft/60 sm:grid-cols-[minmax(0,1fr)_auto] sm:pl-[62px] dark:border-gray-800">
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <x-ta.brand-avatar :name="$item['brand']" :logo="$item['logo']" size="sm" />
                                    <p class="min-w-0 truncate text-sm"><b class="font-medium text-gray-900 dark:text-white">{{ $item['brand'] }}</b> <span class="text-gray-500 dark:text-gray-400">· {{ $item['line'] }}</span>
                                        @if (($label = TargetImpact::label($item['impact'])) !== '')<span class="ml-1 chip chip-up" title="Tahmin: sayfaların arama tıklamaları × bu tür düzeltmelerin etkisi × markanın tık başına talebi">{{ $label }}</span>@endif
                                    </p>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    @if (str_starts_with($item['key'], 'target:') && $isAdmin)
                                        @php $targetId = (int) substr($item['key'], 7); @endphp
                                        <label class="sr-only" for="goal-{{ $targetId }}">Aylık talep amacı</label>
                                        <input id="goal-{{ $targetId }}" type="number" min="1" inputmode="numeric" wire:model="goals.{{ $targetId }}" placeholder="{{ preg_replace('/\D.*$/', '', substr($item['line'], 5)) }}" class="h-8 w-20 rounded-lg border border-gray-200 bg-white px-2 text-sm tabular-nums dark:border-gray-700 dark:bg-gray-900" />
                                    @endif
                                    <a href="{{ $item['url'] }}" wire:navigate class="btn-quiet" data-act="open">Aç</a>
                                    @if ($isAdmin && (str_starts_with($item['key'], 'pkg:') || str_starts_with($item['key'], 'gbpname:')))
                                        <button type="button" wire:click="dismissItem(@js($item['key']))" wire:loading.attr="disabled" class="btn-quiet" data-act="dismiss">Gerek yok</button>
                                    @endif
                                    @if ($isAdmin && $item['approve'])
                                        <button type="button" wire:click="approveItem(@js($item['key']))" wire:loading.attr="disabled" class="btn-dark" data-act="approve">Onayla</button>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <div class="card"><x-ta.empty-state title="Kutu boş" message="Bugün senin vereceğin karar kalmadı. Claude çalışmaya devam ediyor; Akış'tan izleyebilirsin." /></div>
            @endforelse
        </div>
    </section>

    {{-- Smaller things waiting elsewhere, one line each. --}}
    @php
        $more = array_values(array_filter([
            $box['manual'] > 0 ? ['Senin elinle yapılacak '.$n($box['manual']).' iş', 'Google Ads gibi yazması kapalı işler; nasıl yapılacağı yazıyor.', route('operator.repair', ['serit' => 'manual']), 'manual'] : null,
            $elsewhere['reviews'] > 0 ? [$n($elsewhere['reviews']).' yorum yanıt bekliyor', 'Son 30 günün yanıtsız yorumları; taslaklar hazır.', route('operator.gbp-reviews'), 'reviews'] : null,
            $elsewhere['accounts'] > 0 ? [$n($elsewhere['accounts']).' hesap bir markaya ait olabilir', 'Bağla, "Bu markanın değil" ya da "Kullanılmıyor" de.', route('operator.brand', ['brand' => $elsewhere['account_brand']]).'#hesap-ekle', 'accounts'] : null,
        ]));
    @endphp
    @if ($more !== [])
        <section class="mt-8" data-komuta-elsewhere>
            <ul class="card">
                @foreach ($more as [$title, $line, $url, $key])
                    <li class="card-row" data-komuta-more="{{ $key }}">
                        <div class="min-w-0 flex-1"><p class="text-sm font-medium text-gray-900 dark:text-white">{{ $title }}</p><p class="text-[13px] text-gray-500">{{ $line }}</p></div>
                        <a href="{{ $url }}" wire:navigate class="btn">Aç</a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="mt-10">
        <livewire:operator.portfolio.epicrisis-questions lazy />
    </div>

    <p class="mt-10 text-[13px] text-gray-500">Haftalık sayılar, alarmlar ve dün gecenin işleri <a href="{{ route('operator.today') }}" wire:navigate class="link-neutral">Bugün özetinde</a>.</p>
</div>
