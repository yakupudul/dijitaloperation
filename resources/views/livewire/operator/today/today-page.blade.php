{{--
    Bugün (arayüz kararı 2026-11-17): which brand needs the operator today. Denetçi's remarks (at most three), then the
    brands, most urgent first: two numbers per row (system gaps, the operator's own to-dos), one sentence, the doors.
--}}
@php
    $dot = fn (string $tone): string => match ($tone) { 'bad' => 'bg-error-500', 'warn' => 'bg-warning-500', default => 'bg-success-500' };
    $quiet = 'text-[13px] font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white';
@endphp
<div class="space-y-10 text-sm dark:text-gray-200" data-today>
    <header class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
        <div class="min-w-0">
            <h1 class="page-title">{{ $greeting }}{{ $firstName !== '' ? ' '.$firstName : '' }}</h1>
            <p class="page-sub">
                @if ($total === 0)
                    Hizmet verilen marka yok.
                @elseif ($total - $calm === 0)
                    {{ $total }} markanın hepsi yolunda, bugün senden bir şey beklenmiyor.
                @else
                    {{ $total }} markadan {{ $total - $calm }} tanesi bugün sana bakıyor, en acili en üstte.
                @endif
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('operator.komuta') }}" wire:navigate class="btn" title="Silme, giden mesaj, yorum yanıtı gibi onayını bekleyen işler">Onay kutusu</a>
            <a href="{{ route('operator.calendar') }}" wire:navigate class="btn">Yapılacaklar</a>
        </div>
    </header>

    {{-- Moximu's own line (Aşama 3): the agency's inbox and today's sales steps in one sentence; loads after the page. --}}
    <div data-sales-today><livewire:operator.sales.today-card lazy /></div>

    <section data-today-brands>
        <div class="section-head">
            <h2 class="section-title">Markalar <span class="section-count">{{ count($rows) }}</span></h2>
            @if ($calm > 0)
                <button type="button" wire:click="$toggle('all')" class="{{ $quiet }} ml-auto" data-today-all>{{ $all ? 'Yalnız bana bakanlar' : 'Yolunda olan '.$calm.' markayı da göster' }}</button>
            @endif
        </div>
        <div class="card">
            @forelse ($rows as $row)
                <div class="card-row gap-3 max-sm:flex-wrap" wire:key="today-brand-{{ $row['id'] }}" data-today-row="{{ $row['id'] }}">
                    <a href="{{ route('operator.brand', ['brand' => $row['id']]) }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-3 max-sm:basis-full">
                        <x-ta.brand-avatar :name="$row['name']" :logo="$row['logo']" />
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2"><span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dot($row['tone']) }}" aria-hidden="true"></span><span class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</span></span>
                            <span class="mt-0.5 block text-[13px] text-gray-600 dark:text-gray-300">{{ $row['line'] }}</span>
                            @if ($row['next'])<span class="mt-0.5 block truncate text-xs text-gray-500">Sıradaki işin: {{ $row['next'] }}</span>@endif
                        </span>
                    </a>
                    <dl class="flex shrink-0 items-center gap-5 text-right max-sm:pl-12 max-sm:text-left">
                        <div title="Sistemin bulduğu eksik ve kayıplar"><dt class="text-xs text-gray-500">Sistem</dt><dd class="text-sm font-semibold tabular-nums {{ $row['gaps'] > 0 ? 'text-gray-900 dark:text-white' : 'text-gray-400' }}">{{ $row['gaps'] }}</dd></div>
                        <div title="Senin yazdığın açık işler{{ $row['late'] > 0 ? ', '.$row['late'].' tanesi gecikti' : '' }}"><dt class="text-xs text-gray-500">Senin işin</dt><dd class="text-sm font-semibold tabular-nums {{ $row['late'] > 0 ? 'text-error-600 dark:text-error-400' : ($row['todos'] > 0 ? 'text-gray-900 dark:text-white' : 'text-gray-400') }}">{{ $row['todos'] }}</dd></div>
                    </dl>
                    <div class="flex shrink-0 items-center gap-x-3 max-sm:pl-12 lg:w-64 lg:justify-end">
                        @foreach (array_slice($row['doors'], 0, 2) as $door)
                            <x-operator.open-link :link="$door['link']" :label="$door['group']" />
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="card-row"><span class="row-icon text-success-600 dark:text-success-400" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg></span><p class="text-sm text-gray-700 dark:text-gray-300">Bugün hiçbir marka senden bir şey beklemiyor.</p></div>
            @endforelse
            @if ($loose > 0)
                <a href="{{ route('operator.calendar') }}" wire:navigate class="card-row text-[13px] text-gray-500 hover:bg-gray-50 dark:hover:bg-white/[0.02]" data-today-loose>Bir markaya bağlı olmayan {{ $loose }} işin daha var</a>
            @endif
        </div>
    </section>
</div>
