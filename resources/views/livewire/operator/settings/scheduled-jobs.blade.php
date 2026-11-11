@php
    $when = fn (?\Carbon\CarbonImmutable $at): string => $at === null ? '' : ($at->isToday() ? 'bugün '.$at->format('H:i') : ($at->isTomorrow() ? 'yarın '.$at->format('H:i') : ($at->isYesterday() ? 'dün '.$at->format('H:i') : $at->locale('tr')->translatedFormat('j M H:i'))));
    $num = fn (int|float $n): string => number_format($n, is_float($n) && $n < 10 && $n != floor($n) ? 1 : 0, ',', '.');
@endphp
<div class="space-y-10 dark:text-gray-200" data-scheduled-jobs>
    <header>
        <h1 class="page-title">Zamanlanmış işler</h1>
        <p class="page-sub">Sistem {{ $total }} işi kendiliğinden başlatıyor; son 30 günde toplam {{ $num($minutes) }} dakika çalıştılar.@if ($offCount > 0) {{ $offCount }} iş kapalı.@endif @if ($failing > 0) {{ $failing }} işte hata var.@endif</p>
    </header>

    <div class="-mt-4 flex flex-wrap items-center gap-2">
        <button type="button" wire:click="$set('show', 'hepsi')" @class(['seg', 'seg-active' => ! in_array($show, ['kapali', 'hatali'], true)])>Hepsi <span class="tabular-nums {{ ! in_array($show, ['kapali', 'hatali'], true) ? 'text-accent' : 'text-gray-400' }}">{{ $total }}</span></button>
        <button type="button" wire:click="$set('show', 'hatali')" @class(['seg', 'seg-active' => $show === 'hatali'])>Hata veren <span class="tabular-nums {{ $show === 'hatali' ? 'text-accent' : 'text-gray-400' }}">{{ $failing }}</span></button>
        <button type="button" wire:click="$set('show', 'kapali')" @class(['seg', 'seg-active' => $show === 'kapali'])>Kapalı <span class="tabular-nums {{ $show === 'kapali' ? 'text-accent' : 'text-gray-400' }}">{{ $offCount }}</span></button>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="İş ara" aria-label="İş ara"
            class="h-9 w-full rounded-full border border-gray-200 bg-white px-3.5 text-[13px] text-gray-800 placeholder:text-gray-400 focus:border-gray-900 focus:ring-0 dark:border-gray-700 dark:bg-white/[0.04] dark:text-gray-200 sm:ml-auto sm:w-56">
    </div>

    @if ($message !== '')
        <p class="card -mt-4 px-4 py-3 text-sm text-gray-700 dark:text-gray-300" role="status">{{ $message }}</p>
    @endif

    <div class="card -mt-4">
        <div class="hidden grid-cols-[minmax(0,2.4fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.2fr)_auto] gap-4 border-b border-gray-100 px-4 py-2.5 text-[12px] font-medium text-gray-500 dark:border-gray-800 dark:text-gray-400 lg:grid">
            <span>İş</span><span>Sıklık</span><span>Son çalışma</span><span>Son 30 gün</span><span class="w-[76px] text-right">Durum</span>
        </div>
        @forelse ($rows as $job)
            <div wire:key="job-{{ md5($job['key']) }}" @class(['grid gap-x-4 gap-y-1.5 border-b border-gray-100 px-4 py-3 last:border-b-0 dark:border-gray-800 lg:grid-cols-[minmax(0,2.4fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.2fr)_auto] lg:items-center', 'opacity-60' => $job['off']]) data-job-row data-off="{{ $job['off'] ? '1' : '0' }}">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-gray-900 dark:text-white" title="{{ $job['key'] }}">{{ $job['name'] }}</p>
                    @if ($job['what'] !== '')<p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $job['what'] }}</p>@endif
                </div>
                <p class="text-[13px] text-gray-700 dark:text-gray-300"><span class="text-gray-400 lg:hidden">Sıklık: </span>{{ $job['cadence'] }}</p>
                <p class="text-[13px] text-gray-700 dark:text-gray-300"><span class="text-gray-400 lg:hidden">Son: </span>{{ $job['last'] !== null ? $when($job['last']) : 'kayıt yok' }}@if ($job['next'] !== null)<span class="block text-gray-400">sıradaki {{ $when($job['next']) }}</span>@endif</p>
                <p class="text-[13px] tabular-nums text-gray-700 dark:text-gray-300">
                    @if ($job['runs'] > 0)
                        {{ $num($job['runs']) }} kez, {{ $num($job['minutes']).' dk' }}@if ($job['failures'] > 0)<span class="text-error-700 dark:text-error-300">, {{ $num($job['failures']) }} hata</span>@endif
                    @else
                        <span class="text-gray-400">çalışmadı</span>
                    @endif
                </p>
                <div class="flex items-center lg:w-[76px] lg:justify-end">
                    @if ($job['vital'])
                        <span class="text-[13px] text-gray-400" title="Sistemi ayakta tutan iş; kapatılamaz.">Hep açık</span>
                    @else
                        <button type="button" wire:click="setJob(@js($job['key']), {{ $job['off'] ? 'true' : 'false' }})" wire:loading.attr="disabled" class="{{ $job['off'] ? 'btn' : 'btn-quiet' }} h-8! px-3! text-[13px]" data-job-switch>{{ $job['off'] ? 'Aç' : 'Kapat' }}</button>
                    @endif
                </div>
            </div>
        @empty
            <x-ta.empty-state size="sm" title="İş yok" message="Bu süzgeçte gösterilecek iş yok." />
        @endforelse
    </div>
</div>
