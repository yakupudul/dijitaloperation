@php
    $viewTab = fn (bool $active): string => $active ? 'seg seg-active h-8! px-3! text-[13px]!' : 'seg h-8! px-3! text-[13px]!';
@endphp
<div class="space-y-8 dark:text-gray-200" data-work-desk>
    @unless($embedded)
    <header class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="min-w-0">
            @if($contentOnly)
                <h1 class="page-title"><span class="highlight">İçerik</span></h1>
                <p class="page-sub">{{ $brandName !== null ? $brandName.' markasının yazıları' : 'Bütün markaların yazıları' }}: yazılacak, gönderime hazır, taslakta, yayında.</p>
            @else
                <h1 class="page-title">Genel işler</h1>
                <p class="page-sub" title="Aynı türden işler bir arada, en acil olan üstte.">{{ $brandName !== null ? $brandName.' markasının açık işleri' : 'Bütün markaların açık işleri' }}</p>
            @endif
        </div>
        <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
            <select wire:model.live="brand" aria-label="Marka" autocomplete="off" class="h-8 w-full min-w-0 rounded-lg border border-gray-200 bg-white pl-3 text-[13px] text-gray-900 sm:w-auto sm:max-w-64 dark:border-gray-700 dark:bg-gray-900 dark:text-white" data-brand-filter>
                <option value="" @selected($brand === null)>Tüm markalar</option>
                @foreach($brands as $b)<option value="{{ $b->id }}" @selected($brand === (int) $b->id)>{{ $b->name }}{{ ($brandCounts[$b->id] ?? 0) > 0 ? ' ('.$brandCounts[$b->id].')' : '' }}</option>@endforeach
            </select>
            @include('livewire.operator.work.partials.push-toggle', ['devices' => $pushDevices])
        </div>
    </header>

    @if($brandName !== null)
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-600 dark:text-gray-300" data-brand-filter-on="{{ $brand }}">
            <span>Yalnız <strong>{{ $brandName }}</strong> gösteriliyor.</span>
            <button type="button" wire:click="clearBrand" class="link-neutral" data-clear-brand>Tüm markaları göster</button>
        </div>
    @endif

    @endunless

    @if($message !== '')<p role="status" class="tone-info rounded-lg px-3 py-2 text-sm">{{ $message }}</p>@endif

    <div class="space-y-4">
        <nav class="no-scrollbar flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Sekmeler" role="tablist">
            @foreach($tabs as $code => $label)
                @continue(($code === 'kurulum' || in_array($code, \App\Services\Work\WorkDesk::CHANNEL_TABS, true)) && $tab !== $code)
                @continue($contentOnly && $code !== 'icerik')
                <button type="button" role="tab" wire:click="setTab('{{ $code }}')" @class(['tab', 'tab-active' => $tab === $code]) aria-selected="{{ $tab === $code ? 'true' : 'false' }}">
                    {{ $label }}
                    @if($counts[$code] > 0)<span class="font-normal tabular-nums text-gray-500" title="Açık iş">{{ $counts[$code] }}</span>@endif
                    @if($urgent[$code] > 0)<span class="font-normal tabular-nums text-warning-700 dark:text-warning-400" title="Bunların acil olanları">{{ $urgent[$code] }} acil</span>@endif
                </button>
            @endforeach
        </nav>

        <div class="flex flex-wrap items-center gap-x-2 gap-y-2">
            <span class="inline-flex items-center gap-1">
                <button type="button" wire:click="setView('acik')" class="{{ $viewTab($view === 'acik') }}">Açık</button>
                <button type="button" wire:click="setView('yapildi')" class="{{ $viewTab($view === 'yapildi') }}">Yapıldı (30 gün)</button>
            </span>
            <span class="text-[13px] text-gray-500">{{ $total }} iş{{ $brandName === null && count($sections) > 1 ? ', '.(count($sections) + $hiddenSections).' marka' : '' }}</span>
            @if($embedded)
                <span class="sm:ml-auto">@include('livewire.operator.work.partials.push-toggle', ['devices' => $pushDevices])</span>
            @endif
        </div>
    </div>

    @if(! $embedded && ! $contentOnly && $view === 'acik' && ($onDesk['brand'] > 0 || ($onDesk['preparing'] ?? 0) > 0))
        <a href="{{ route('operator.repair', array_filter(['marka' => $brand])) }}" class="card flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3.5 text-sm text-gray-700 transition hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]" data-on-desk>
            @php
                $deskLine = 'Hazırlanmış '.$onDesk['brand'].' düzeltme Onarım masasında onayını bekliyor';
                if (($onDesk['preparing'] ?? 0) > 0) {
                    $deskLine .= '; '.number_format($onDesk['preparing'], 0, ',', '.').' sayfa düzeltmesini sistem hazırlıyor (saatte '.\App\Services\Repair\RepairPreparer::BATCH_PAGES_PER_RUN.' sayfa), hazır olan masaya gelir';
                }
            @endphp
            <span title="Sayfa düzeltmeleri burada tekrar gösterilmez.">{{ $deskLine }}.</span>
            <span class="btn">Onarım masasını aç</span>
        </a>
    @endif

    @if($setup !== [] && array_sum($setup) > 0 && ($brand === null || ($setup[$brand] ?? 0) > 0))
        <div class="card flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3 text-[13px] text-gray-500 dark:text-gray-400" data-setup-strip>
            <span class="font-medium text-gray-900 dark:text-white">Marka kurulumu</span>
            @foreach($brands as $b)
                @if(($setup[$b->id] ?? 0) > 0 && ($brand === null || $brand === (int) $b->id))
                    <a href="{{ route('operator.brand', $b->id) }}" class="inline-flex min-w-0 max-w-full items-center gap-1 text-gray-700 hover:underline dark:text-gray-300"><span class="truncate">{{ $b->name }}</span> <span class="tabular-nums text-gray-500">{{ $setup[$b->id] }}</span></a>
                @endif
            @endforeach
            <button type="button" wire:click="setTab('kurulum')" class="btn h-7! px-2.5! text-xs! sm:ml-auto">Kurulum işlerini listele</button>
        </div>
    @endif

    @if($queue !== null)
        @include('livewire.operator.work.partials.content-queue')
        @if($sections !== [])<h2 class="section-title">Diğer SEO işleri</h2>@endif
    @endif

    @include('livewire.operator.work.partials.work-groups')
</div>
