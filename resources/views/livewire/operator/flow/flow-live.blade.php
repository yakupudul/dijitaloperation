@php
    $n = fn (int $v): string => number_format($v, 0, ',', '.');
    $stats = [
        'queue' => ['Sırada', 'text-gray-900 dark:text-white'],
        'run' => ['Çalışıyor', 'text-live-600 dark:text-live-300'],
        'done' => ['Bugün bitti', 'text-gray-900 dark:text-white'],
        'err' => ['Hata', 'text-error-600 dark:text-error-400'],
    ];
    $errors = $rows->sum('retry');
@endphp
{{-- Akış, live part (prototype approved 2026-10-22): polls only while the tab is visible; fast while work runs. --}}
<div wire:poll.{{ $busy ? '3s' : '15s' }}.visible data-flow-live>
    <span class="eyebrow"><span class="live-dot"></span>Canlı · sayfa kendiliğinden yenilenir</span>
    <h1 class="page-title mt-3" data-flow-heading>
        @if ($counts['run'] > 0)
            Sistemde <span class="highlight">{{ $n($counts['run']) }} iş</span> şu an çalışıyor
        @else
            Bugün <span class="highlight">{{ $n($counts['done']) }} iş</span> bitti
        @endif
    </h1>
    <p class="page-sub">Onayladığın her iş burada: sırada, çalışıyor, bitti ya da hata. Bin satırlık bir onay tek satırdır; açınca her işi görürsün.</p>

    <div class="mt-7 grid grid-cols-2 gap-2.5 sm:grid-cols-4" data-flow-stats>
        @foreach ($stats as $key => [$label, $tone])
            <button type="button" wire:click="filter('{{ $key }}')" aria-pressed="{{ $state === $key ? 'true' : 'false' }}"
                @class(['flex flex-col gap-1.5 rounded-3xl border px-4 py-3.5 text-left transition', 'border-gray-900 bg-white shadow-lift dark:border-white dark:bg-white/[0.06]' => $state === $key, 'border-transparent bg-gray-100/80 hover:bg-gray-100 dark:bg-white/[0.04]' => $state !== $key])
                data-flow-stat="{{ $key }}">
                <span class="flex items-center gap-1.5 text-[13px] text-gray-500 dark:text-gray-400">@if ($key === 'run')<span class="live-dot h-1.5 w-1.5"></span>@endif{{ $label }}</span>
                <span class="text-[28px] leading-none font-semibold tracking-[-0.03em] tabular-nums {{ $tone }}">{{ $n($counts[$key]) }}</span>
            </button>
        @endforeach
    </div>

    <div class="mt-8">
        <div class="section-head">
            <div class="min-w-0 flex-1">
                <h2 class="section-title">{{ $state !== '' ? (\App\Livewire\Operator\Flow\FlowLive::STATES[$state] ?? '').' işler' : 'Bugünün işleri' }}</h2>
                <p class="text-[13px] text-gray-500 dark:text-gray-400">En yeni en üstte. Hatalılar tek tıkla yeniden gönderilir.</p>
            </div>
            @if ($isAdmin && $errors > 0)
                <button type="button" wire:click="retryAll" wire:loading.attr="disabled" class="btn" data-flow-retry-all>Hatalıları yeniden dene</button>
            @endif
        </div>
        @if ($message !== '')
            <p class="mb-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300" data-flow-message>{{ $message }}</p>
        @endif

        <div class="card" data-flow-log>
            @forelse ($rows as $row)
                @php
                    $okPct = $row['total'] > 0 ? min(100, $row['ok'] / $row['total'] * 100) : 0;
                    $errPct = $row['total'] > 0 ? min(100 - $okPct, $row['err'] / $row['total'] * 100) : 0;
                    $runPct = $row['state'] === 'run' && $row['total'] === 1 ? 45 : 0;
                    $label = $row['total'] > 1
                        ? $n($row['ok']).' / '.$n($row['total']).($row['err'] > 0 ? ' · '.$n($row['err']).' hata' : '')
                        : match ($row['state']) { 'done' => 'Bitti', 'run' => 'Çalışıyor…', 'err' => 'Hata', default => 'Bekliyor' };
                    $isOpen = $opened === $row['key'];
                @endphp
                <div wire:key="flow-{{ md5($row['key']) }}" class="border-t border-gray-200 first:border-t-0 dark:border-gray-800" data-flow-row="{{ $row['key'] }}">
                    <div @if ($row['open']) wire:click="toggle(@js($row['key']))" role="button" tabindex="0" aria-expanded="{{ $isOpen ? 'true' : 'false' }}" @endif
                        @class(['grid grid-cols-[34px_minmax(0,1fr)_auto] items-center gap-3 px-4 py-3 sm:grid-cols-[34px_minmax(0,1fr)_170px_96px_56px]', 'cursor-pointer hover:bg-gray-50 dark:hover:bg-white/[0.02]' => $row['open']])>
                        @include('livewire.operator.flow.partials.kind-icon', ['kind' => $row['kind']])
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $row['title'] }}</p>
                            <p class="truncate text-[13px] text-gray-500 dark:text-gray-400">{{ $row['who'] }}@if ($row['note']) · <span class="text-error-600 dark:text-error-400">{{ $row['note'] }}</span>@endif</p>
                        </div>
                        <div class="col-span-3 col-start-2 row-start-2 flex min-w-0 flex-col gap-1 sm:col-span-1 sm:col-start-auto sm:row-start-auto">
                            <div class="flex h-1.5 overflow-hidden rounded-full bg-gray-900/[0.08] dark:bg-white/10">
                                <i class="block h-full bg-success-500 transition-all duration-500" style="width: {{ $okPct }}%"></i>
                                <i class="block h-full bg-error-500 transition-all duration-500" style="width: {{ $errPct }}%"></i>
                                <i class="block h-full bg-live-500 transition-all duration-500" style="width: {{ $runPct }}%"></i>
                            </div>
                            <small class="text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $label }}</small>
                        </div>
                        <div class="col-start-3 row-start-1 sm:col-start-auto">@include('livewire.operator.flow.partials.state', ['state' => $row['state']])</div>
                        <span class="hidden text-right text-[13px] tabular-nums text-gray-500 sm:block">{{ $row['time'] }}</span>
                    </div>
                    @if ($isOpen && $detail !== null)
                        <div class="border-t border-gray-200 bg-gray-50/70 dark:border-gray-800 dark:bg-white/[0.02]" data-flow-detail>
                            @forelse ($detail['items'] as $item)
                                <div wire:key="flow-item-{{ $item['id'] }}" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-0.5 border-t border-gray-200 px-4 py-2 text-[13px] first:border-t-0 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_90px_auto] sm:pl-[62px] dark:border-gray-800" data-flow-item="{{ $item['id'] }}">
                                    <p class="truncate text-gray-700 dark:text-gray-300">@if ($item['brand'] !== '')<b class="font-medium text-gray-900 dark:text-white">{{ $item['brand'] }}</b> · @endif{{ $item['subject'] }}</p>
                                    <p @class(['col-span-2 row-start-2 truncate sm:col-span-1 sm:row-start-auto', 'text-error-600 dark:text-error-400' => $item['state'] === 'err', 'text-gray-500 dark:text-gray-400' => $item['state'] !== 'err'])>{{ $item['line'] }}</p>
                                    <span class="hidden text-xs text-gray-400 sm:block" title="Tespit → Çözüm → İş → Doğrulama → Sonuç">{{ $item['stage'] }}</span>
                                    @if ($item['retry'] && $isAdmin)
                                        <button type="button" wire:click="retryItem(@js($item['id']))" wire:loading.attr="disabled" class="btn h-7" data-flow-retry-item>Yeniden dene</button>
                                    @else
                                        @include('livewire.operator.flow.partials.state', ['state' => $item['state']])
                                    @endif
                                </div>
                            @empty
                                <p class="px-4 py-3 text-[13px] text-gray-500 sm:pl-[62px]">Bu işin alt satırı yok.</p>
                            @endforelse
                            <div class="flex flex-wrap items-center gap-2.5 border-t border-gray-200 px-4 py-2.5 text-[13px] text-gray-500 sm:pl-[62px] dark:border-gray-800">
                                <span class="tabular-nums">{{ $n($row['ok']) }} bitti · {{ $n(max(0, $row['total'] - $row['ok'] - $row['err'])) }} sırada · {{ $n($row['err']) }} hata{{ $detail['more'] > 0 ? ' · '.$n($detail['more']).' iş daha' : '' }}</span>
                                @if ($isAdmin && $row['retry'] > 0)
                                    <button type="button" wire:click="retry(@js($row['key']))" wire:loading.attr="disabled" class="btn ml-auto h-7" data-flow-retry>Hatalıları yeniden dene</button>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <div class="px-4 py-10 text-center text-sm text-gray-500" data-flow-empty>{{ $state !== '' ? 'Bu durumda iş yok.' : 'Bugün henüz iş yok. Onayladığın ya da sistemin başlattığı her iş burada görünür.' }}</div>
            @endforelse
        </div>
    </div>
</div>
