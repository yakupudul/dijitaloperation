@php
    use App\Services\Goals\BrandTargets;
    use App\Services\Goals\TargetImpact;

    $n = fn (int|float $v): string => BrandTargets::number((float) $v);
    $stateChip = ['ok' => 'chip-up', 'look' => 'chip-flat', 'bad' => 'chip-down', 'none' => 'chip-flat'];
    $goalState = ['ahead' => 'Önde', 'on_track' => 'Yolunda', 'behind' => 'Geride', 'no_goal' => 'Amaç yok'];
    $p = $goal['goal'] ?? null;
    $monthName = \Illuminate\Support\Str::ucfirst(now('Europe/Istanbul')->locale('tr')->translatedFormat('F'));
@endphp
{{-- Marka sayfası (Aşama 6, prototype approved 2026-10-22): the goal on top, one card per channel under it. --}}
<div class="space-y-6" data-brand-page>
    @if ($goal !== null)
        <section class="hero-panel" data-brand-goal>
            @if ($p['state'] === 'no_goal')
                <p class="hero-label">{{ $monthName }} amacı</p>
                <p class="mt-2 text-lg font-semibold text-white">{{ $goal['target']?->status === \App\Models\BrandTarget::UNMEASURED ? 'Bu markanın talebi henüz ölçülmüyor.' : 'Bu ayın amacı henüz onaylanmadı.' }}</p>
                <p class="mt-1 text-sm text-white/60">Bu ay şimdiye kadar {{ $n($p['mtd']) }} talep. Amaç Komuta'da önerilir, tek tıkla onaylanır.</p>
            @else
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p class="hero-label">{{ $monthName }} amacı · ayda {{ $n($p['goal']) }} talep (form, arama, WhatsApp)</p>
                        <p class="mt-2 flex items-baseline gap-2 text-white"><span class="text-4xl font-semibold tabular-nums" data-goal-mtd>{{ $n($p['mtd']) }}</span><span class="text-white/60">/ {{ $n($p['goal']) }} talep</span></p>
                        <p class="mt-1 text-sm text-white/60">
                            Ay sonu tahmini {{ $n($p['projected']) }}
                            <span class="chip {{ $p['state'] === 'behind' ? 'chip-down' : ($p['state'] === 'ahead' ? 'chip-up' : 'chip-flat') }} ml-1">{{ $goalState[$p['state']] }}{{ $p['gap_pct'] !== null && $p['state'] !== 'on_track' ? ' '.($p['gap_pct'] > 0 ? '+' : '').$p['gap_pct'].'%' : '' }}</span>
                        </p>
                    </div>
                    @if (TargetImpact::label($goal['gain']) !== '')
                        <p class="text-sm text-white/70" data-goal-gain>Açık işler biterse tahminen <b class="text-white">{{ TargetImpact::label($goal['gain']) }}</b></p>
                    @endif
                </div>
                <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4" data-goal-channels>
                    @foreach ($goal['channels'] as $channel => $c)
                        @php $pct = $c['goal'] ? min(100, (int) round($c['mtd'] / max(1, $c['goal']) * 100)) : 0; @endphp
                        <div class="min-w-0" data-goal-channel="{{ $channel }}">
                            <p class="truncate text-xs text-white/60">{{ $c['label'] }}</p>
                            <p class="mt-0.5 text-white tabular-nums"><b class="font-semibold">{{ $n($c['mtd']) }}</b> <span class="text-white/50">/ {{ $n((int) $c['goal']) }}</span></p>
                            <span class="mt-1.5 block h-1.5 overflow-hidden rounded-full bg-white/10"><i class="block h-full rounded-full bg-accent" style="width: {{ $pct }}%"></i></span>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    @endif

    @if ($message !== '')
        <p class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300" data-brand-page-message>{{ $message }} <a href="{{ route('operator.flow') }}" wire:navigate class="link-neutral">Akışta izle</a></p>
    @endif

    <div class="grid gap-4 md:grid-cols-2" data-brand-cards>
        @forelse ($cards as $card)
            <section class="card flex flex-col p-5" wire:key="brand-card-{{ $card['key'] }}" data-brand-card="{{ $card['key'] }}" data-card-state="{{ $card['state'] }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ $card['title'] }}</h2>
                        <p class="mt-0.5 truncate text-[13px] text-gray-500">{{ $card['line'] }}</p>
                    </div>
                    <span class="chip {{ $stateChip[$card['state']] }} shrink-0">{{ $card['state_label'] }}</span>
                </div>

                @if ($card['metrics'] !== [])
                    <dl class="mt-4 grid grid-cols-3 gap-3">
                        @foreach ($card['metrics'] as $metric)
                            <div class="min-w-0">
                                <dt class="truncate text-xs text-gray-500">{{ $metric['label'] }}</dt>
                                <dd class="mt-0.5 flex flex-wrap items-baseline gap-1.5 text-gray-900 dark:text-white"><b class="text-lg font-semibold tabular-nums">{{ $metric['value'] }}</b>
                                    @if ($metric['delta'] !== null)<span class="chip {{ $metric['delta'] >= 0 ? 'chip-up' : 'chip-down' }}">{{ $metric['delta'] > 0 ? '+' : '' }}{{ $metric['delta'] }}%</span>@endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                @endif

                <ul class="mt-4 flex-1 divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-800 dark:border-gray-800">
                    @forelse ($card['findings'] as $finding)
                        <li class="flex items-start justify-between gap-3 py-2.5" data-brand-finding>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">@if ($finding['url'])<a href="{{ $finding['url'] }}" wire:navigate class="hover:underline">{{ $finding['title'] }}</a>@else{{ $finding['title'] }}@endif</p>
                                <p class="mt-0.5 line-clamp-2 text-[13px] text-gray-500">{{ $finding['line'] }}</p>
                            </div>
                            @if ($finding['count'] > 1)<span class="shrink-0 text-[13px] tabular-nums text-gray-500">{{ number_format($finding['count'], 0, ',', '.') }} iş</span>@endif
                        </li>
                    @empty
                        <li class="py-2.5 text-[13px] text-gray-500">{{ $card['state'] === 'none' ? 'Hesap bağlanınca tespitler burada görünür.' : 'Bu kanalda bekleyen iş yok.' }}</li>
                    @endforelse
                </ul>

                <div class="mt-4 flex flex-wrap items-center gap-2">
                    @if ($isAdmin && $card['ready'] > 0)
                        <button type="button" wire:click="solve('{{ $card['key'] }}')" wire:loading.attr="disabled" wire:confirm="{{ $card['title'] }} kanalındaki hazır {{ $card['ready'] }} iş gönderilsin mi? Her biri geri alınabilir." class="btn-dark" data-brand-solve="{{ $card['key'] }}">Hepsini çöz ({{ number_format($card['ready'], 0, ',', '.') }})</button>
                    @endif
                    @if ($card['manual'] > 0)
                        <a href="{{ $card['desk_url'] }}" wire:navigate class="btn">Nasıl yapılır ({{ number_format($card['manual'], 0, ',', '.') }})</a>
                    @endif
                    <a href="{{ $card['desk_url'] }}" wire:navigate class="btn-quiet">{{ $card['key'] === 'web' ? 'Web masası' : ($card['key'] === 'google_ads' ? 'Ads masası' : ($card['key'] === 'gbp' ? 'Profil masası' : 'Meta masası')) }}</a>
                </div>
            </section>
        @empty
            <p class="text-sm text-gray-500">Kanal bilgisi okunamadı; sayfayı yenile.</p>
        @endforelse
    </div>
</div>
